<?php
require_once __DIR__ . '/db.php';

/**
 * Loguea tanto en CLI (cron) como en contexto web (endpoints públicos).
 * STDOUT solo existe como constante en el SAPI de CLI — usarlo en un
 * request web tira "Undefined constant STDOUT" (fatal en PHP 8). error_log()
 * funciona en ambos: en cron va a la salida que cPanel te manda por mail/log,
 * en web va al error log de PHP configurado en el hosting.
 */
function log_line(string $mensaje): void
{
    $fecha = date('Y-m-d H:i:s');
    $linea = "[$fecha] $mensaje";
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, $linea . "\n");
    } else {
        error_log($linea);
    }
}

/**
 * Rate-limit generoso por IP para la landing pública (anti-flood de bots), sin
 * bloquear a un visitante legítimo. Reutiliza la tabla intentos_login como
 * contador (identificador "landing:<ip>", separado del login), con reloj de PHP
 * de punta a punta (nunca NOW() de MySQL). Devuelve true si la IP ya superó el
 * límite en la ventana; cada llamada además suma 1 al contador.
 */
const LANDING_MAX_POR_VENTANA = 20;
const LANDING_VENTANA_MINUTOS = 60;

function landing_excede_limite(string $ip): bool
{
    $pdo = db();
    $id = 'landing:' . $ip;
    $stmt = $pdo->prepare('SELECT intentos, ultimo_intento FROM intentos_login WHERE identificador = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    $ahora = time();
    $dentroVentana = $row && strtotime($row['ultimo_intento']) > $ahora - (LANDING_VENTANA_MINUTOS * 60);
    $n = $dentroVentana ? ((int) $row['intentos'] + 1) : 1;

    $upd = $pdo->prepare('
        INSERT INTO intentos_login (identificador, intentos, ultimo_intento)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE intentos = VALUES(intentos), ultimo_intento = VALUES(ultimo_intento)
    ');
    $upd->execute([$id, $n, date('Y-m-d H:i:s', $ahora)]);

    return $n > LANDING_MAX_POR_VENTANA;
}

/**
 * Cuenta las solicitudes de "olvidé mi contraseña" pendientes de atender.
 * Usada tanto por clientes.php como por cartera.php para mostrar el badge
 * de "Solicitudes" en el nav de admin, así el conteo no se duplica ni se
 * desalinea entre las dos pantallas.
 */
function contar_solicitudes_pendientes(): int
{
    $pdo = db();
    return (int) $pdo->query("SELECT COUNT(*) FROM solicitudes_reset WHERE estado = 'pendiente'")->fetchColumn();
}

function primer_dia_mes(?string $fecha = null): string
{
    $ts = $fecha ? strtotime($fecha) : time();
    return date('Y-m-01', $ts);
}

function primer_dia_mes_anterior(?string $fecha = null): string
{
    $ts = $fecha ? strtotime($fecha) : time();
    return date('Y-m-01', strtotime('-1 month', $ts));
}

/**
 * Formatea un monto en pesos argentinos para mensajes de WhatsApp.
 */
function formatear_ars(float $monto): string
{
    return number_format($monto, 0, ',', '.');
}

/**
 * Busca o crea la fila de metricas_diarias para un cliente+fecha, devolviendo
 * su id. Usado por el cron de ingesta y por los endpoints públicos de leads/
 * ventas, que pueden llegar en paralelo (dos leads casi simultáneos del mismo
 * cliente). Un SELECT-then-INSERT tiene una condición de carrera contra la
 * unique key (cliente_id, fecha): el INSERT atómico con
 * ON DUPLICATE KEY UPDATE evita el 500 que eso provocaría.
 */
function obtener_o_crear_metrica_dia(int $clienteId, string $fecha): int
{
    $pdo = db();
    $stmt = $pdo->prepare('
        INSERT INTO metricas_diarias (cliente_id, fecha) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
    ');
    $stmt->execute([$clienteId, $fecha]);
    return (int) $pdo->lastInsertId();
}

/**
 * Da de alta un lead y suma +1 a los leads del día en metricas_diarias,
 * en una transacción. Usada tanto por public/lead_capture.php (integraciones
 * server-to-server con API key) como por public/landing.php (formulario
 * público sin API key) — la validación de quién puede llamarla es
 * responsabilidad de cada endpoint, esta función asume que ya se autorizó.
 * Devuelve el id del lead creado; el id de un lead ABIERTO preexistente si el
 * teléfono deduplica (mismo cliente, mismos últimos 8 dígitos, estado
 * pendiente/contactado/en_negociacion) — en ese caso NO inserta ni suma a la
 * métrica del día, para no inflar el embudo ni subestimar el CPL; o null si el
 * cliente no existe o no está activo.
 *
 * $consentimiento registra si el titular aceptó la política de privacidad
 * (Ley 25.326) al dejar sus datos. Default true porque lead_capture.php lo
 * usan integraciones server-to-server (Meta/Google Lead Ads, CRMs) que ya
 * gestionan su propio consentimiento en origen; landing.php, en cambio, pasa
 * explícitamente el valor del checkbox porque ahí el consentimiento se pide
 * en esta misma página.
 */
function registrar_lead(int $clienteId, string $nombreLead, string $telefonoLead, string $fuente, bool $consentimiento = true): ?int
{
    $pdo = db();

    $clienteStmt = $pdo->prepare("SELECT id FROM clientes WHERE id = ? AND estado_cuenta = 'activo'");
    $clienteStmt->execute([$clienteId]);
    if (!$clienteStmt->fetch()) {
        return null;
    }

    // Dedup: si ya hay un lead ABIERTO para este cliente con el mismo teléfono
    // (comparando los últimos 8 dígitos, normalizados en SQL igual que la
    // columna telefono_normalizado), no damos de alta uno nuevo ni sumamos a la
    // métrica — devolvemos el id del lead abierto existente (idempotente). Un
    // teléfono que reaparece con el lead ya cerrado (vendido/no_vendido) SÍ
    // cuenta como oportunidad nueva. El chequeo es por estado, no por tiempo.
    $dup = $pdo->prepare("
        SELECT id FROM leads
        WHERE cliente_id = ?
          AND estado IN ('pendiente', 'contactado', 'en_negociacion')
          AND telefono_normalizado = RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(?, ' ', ''), '+', ''), '-', ''), '(', ''), ')', ''), '.', ''), '/', ''), 8)
          AND telefono_normalizado <> ''
        ORDER BY fecha_ingreso DESC
        LIMIT 1
    ");
    $dup->execute([$clienteId, $telefonoLead]);
    $existente = $dup->fetchColumn();
    if ($existente !== false) {
        return (int) $existente;
    }

    $pdo->beginTransaction();

    $ins = $pdo->prepare('INSERT INTO leads (cliente_id, nombre_lead, telefono_lead, fuente, fecha_ingreso, consentimiento_datos) VALUES (?, ?, ?, ?, NOW(), ?)');
    $ins->execute([$clienteId, $nombreLead, $telefonoLead, $fuente, $consentimiento ? 1 : 0]);
    $leadId = (int) $pdo->lastInsertId();

    $metricaId = obtener_o_crear_metrica_dia($clienteId, date('Y-m-d'));
    $campoLeads = $fuente === 'google' ? 'leads_google' : 'leads_meta';
    $upd = $pdo->prepare("UPDATE metricas_diarias SET $campoLeads = $campoLeads + 1 WHERE id = ?");
    $upd->execute([$metricaId]);

    $pdo->commit();

    return $leadId;
}

/**
 * Parsea un texto libre de WhatsApp buscando un monto numérico.
 * Soporta "150000", "$150.000", "150.000", "150k".
 * Devuelve null si el texto no matchea un monto.
 */
function parsear_monto_whatsapp(string $texto): ?float
{
    $t = trim($texto);
    if (!preg_match('/^\$?\s?\d{3,}(\.\d{3})*(,\d+)?\s?k?$/i', $t)) {
        return null;
    }
    $t = str_replace('$', '', $t);
    $t = trim($t);
    if (preg_match('/k$/i', $t)) {
        $t = preg_replace('/k$/i', '', $t);
        $t = str_replace(',', '.', $t);
        return ((float) $t) * 1000;
    }
    $t = str_replace('.', '', $t);
    $t = str_replace(',', '.', $t);
    return (float) $t;
}

/**
 * Botón de toggle de tema (topbar). El ícono/aria-label son un
 * placeholder visual a propósito — tema-toggle.js los corrige al
 * cargar la página según el tema efectivo real (ver assets/tema-toggle.js).
 */
function render_btn_tema(string $tema, string $csrf): string
{
    return '<button type="button" id="btn-tema" class="btn-ayuda" data-csrf="'
        . htmlspecialchars($csrf) . '" aria-label="Cambiar a tema claro">'
        . '<span aria-hidden="true">🌙</span></button>';
}
