<?php
/**
 * Cron mensual (día 1 de cada mes, ej. 00:05) — creación de objetivos del mes.
 * Crea el registro de objetivos_comerciales del mes para cada cliente activo,
 * copiando el presupuesto/metas del mes anterior si existen, o dejándolo en
 * cero y avisando por WhatsApp al equipo interno para que lo cargue.
 *
 * cPanel Cron: 5 0 1 * * /usr/local/bin/php /home/USUARIO/donweb-mysql/cron/04_create_monthly_objectives.php
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/cola_whatsapp.php'; // + whatsapp_client + db transitivos
require_once __DIR__ . '/../includes/crons_heartbeat.php';

$pdo = db();
registrar_latido($pdo, '04_create_monthly_objectives');

$mesActual = primer_dia_mes();
$mesAnterior = primer_dia_mes_anterior();

$clientes = $pdo->query("SELECT id, nombre_empresa FROM clientes WHERE estado_cuenta = 'activo'")->fetchAll();

foreach ($clientes as $cliente) {
    $anteriorStmt = $pdo->prepare('SELECT presupuesto_asignado, meta_leads, meta_ventas FROM objetivos_comerciales WHERE cliente_id = ? AND mes_anio = ?');
    $anteriorStmt->execute([$cliente['id'], $mesAnterior]);
    $anterior = $anteriorStmt->fetch();

    $presupuesto = $anterior['presupuesto_asignado'] ?? 0;
    $metaLeads = $anterior['meta_leads'] ?? 0;
    $metaVentas = $anterior['meta_ventas'] ?? 0;

    // INSERT IGNORE contra uq_cliente_mes: crea el objetivo del mes si no existe.
    // rowCount()===1 => lo creó ESTA corrida; 0 => ya existía (o una corrida
    // solapada lo creó) → no re-loguear ni re-avisar. Reemplaza el check-then-insert
    // (que ante solapamiento lanzaba duplicado y abortaba el cron a mitad del loop).
    $ins = $pdo->prepare('INSERT IGNORE INTO objetivos_comerciales (cliente_id, mes_anio, presupuesto_asignado, meta_leads, meta_ventas) VALUES (?, ?, ?, ?, ?)');
    $ins->execute([$cliente['id'], $mesActual, $presupuesto, $metaLeads, $metaVentas]);

    if ($ins->rowCount() !== 1) {
        log_line("Objetivo de {$cliente['nombre_empresa']} para $mesActual ya existe, se omite.");
        continue;
    }

    log_line("Objetivo creado para {$cliente['nombre_empresa']} — $mesActual — presupuesto=$presupuesto (copiado_de_mes_anterior=" . ($anterior ? 'si' : 'no') . ")");

    if (!$anterior) {
        $urlObjetivo = str_replace('dashboard.php', 'admin/objetivo_form.php', app_config()['app']['dashboard_base_url']) . '?cliente_id=' . $cliente['id'];
        // Aviso DURABLE: se encola (no envío directo) para que un WhatsApp caído a
        // las 00:05 del día 1 no pierda el aviso — lo drena cron/02 con reintentos.
        // Clave de idempotencia por cliente y mes.
        encolar_whatsapp_texto(
            $pdo,
            "objetivo_cero:{$cliente['id']}:{$mesActual}",
            app_config()['whatsapp']['admin_interno_whatsapp'],
            "⚠️ Se creó el Objetivo Comercial de {$cliente['nombre_empresa']} para $mesActual con valores en CERO (no había mes anterior para copiar). Cargalo desde el panel admin: $urlObjetivo"
        );
    }
}

log_line('Creación de objetivos mensuales finalizada.');
