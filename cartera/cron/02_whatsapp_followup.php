<?php
// Cron frecuente (ej. cada 15-30 min) — seguimiento comercial + aviso de SLA.
// 1) Leads que pasaron 48hs sin recibir el mensaje de seguimiento: se lo envía al vendedor.
// 2) Clientes con leads sin atender (fecha_primer_contacto NULL) que superaron su SLA:
//    aviso por WhatsApp al vendedor, una sola vez por día (dedup vía alertas_enviadas).
//
// cPanel Cron (cada 30 min): */30 * * * * /usr/local/bin/php /home/USUARIO/donweb-mysql/cron/02_whatsapp_followup.php
//
// (Este comentario usa // en vez de /** */ a propósito: la expresión de cron
// "*/30" contiene la secuencia "*/", que en un comentario de bloque cerraría
// el comentario ahí mismo y dejaría el resto como código PHP inválido — pasó
// de verdad en este archivo, era un error fatal de sintaxis en producción.)

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/whatsapp_client.php';
require_once __DIR__ . '/../includes/crons_heartbeat.php';
require_once __DIR__ . '/../includes/cola_whatsapp.php';

$pdo = db();
registrar_latido($pdo, '02_whatsapp_followup');
$whatsapp = new WhatsAppClient();

$stmt = $pdo->query("
    SELECT l.id, l.nombre_lead, l.telefono_lead, l.fecha_ingreso,
           c.nombre_empresa, c.contacto_whatsapp_vendedor
    FROM leads l
    INNER JOIN clientes c ON c.id = l.cliente_id
    WHERE l.mensaje_seguimiento_enviado = 0
      AND l.fecha_ingreso <= (NOW() - INTERVAL 48 HOUR)
      AND c.estado_cuenta = 'activo'
");
$leads = $stmt->fetchAll();

log_line('Leads pendientes de seguimiento 48hs: ' . count($leads));

foreach ($leads as $lead) {
    encolar_whatsapp_plantilla(
        $pdo,
        "seguimiento:{$lead['id']}",
        $lead['contacto_whatsapp_vendedor'],
        'seguimiento_venta',
        [
            $lead['nombre_empresa'],           // {{1}}
            $lead['nombre_lead'],               // {{2}}
            $lead['telefono_lead'],             // {{3}}
            date('d/m/Y', strtotime($lead['fecha_ingreso'])), // {{4}}
            $lead['nombre_empresa'],            // {{5}}
        ]
    );
    // El flag NO se marca acá (sería optimista): un mensaje que después queda
    // 'fallido' dejaría el lead como contactado sin que el vendedor se enterara. Se
    // reconcilia al final contra el estado real de la cola (cuarta pasada). El
    // re-encolado de esta fila en corridas siguientes es idempotente (clave única).
    log_line("  -> Seguimiento encolado, lead #{$lead['id']}");
}

// --- Segunda pasada: aviso de leads sin atender (SLA). Mismo reloj MySQL que arriba. ---
// Un lead está "sin atender" si el humano nunca lo tocó (fecha_primer_contacto IS NULL),
// sigue abierto, y ya pasó su SLA. El flip automático a 'contactado' de la primera pasada
// NO setea fecha_primer_contacto, así que esos leads siguen contando acá.
$slaStmt = $pdo->query("
    SELECT l.cliente_id, c.nombre_empresa, c.contacto_whatsapp_vendedor,
           c.sla_horas_objetivo, COUNT(*) AS sin_atender
    FROM leads l
    INNER JOIN clientes c ON c.id = l.cliente_id
    WHERE l.fecha_primer_contacto IS NULL
      AND l.estado IN ('pendiente','contactado')
      AND TIMESTAMPDIFF(HOUR, l.fecha_ingreso, NOW()) >= c.sla_horas_objetivo
      AND c.estado_cuenta = 'activo'
    GROUP BY l.cliente_id, c.nombre_empresa, c.contacto_whatsapp_vendedor, c.sla_horas_objetivo
");
$clientesSinAtender = $slaStmt->fetchAll();
log_line('Clientes con leads sin atender (SLA): ' . count($clientesSinAtender));

$hoy = date('Y-m-d'); // clave de dedup diaria de alertas_enviadas (mismo criterio que cron/03)
$dashboardUrl = app_config()['app']['dashboard_base_url'] ?? '';

foreach ($clientesSinAtender as $c) {
    $check = $pdo->prepare("SELECT id FROM alertas_enviadas WHERE cliente_id = ? AND fecha = ? AND tipo = 'lead_sin_atender'");
    $check->execute([$c['cliente_id'], $hoy]);
    if ($check->fetch()) {
        continue; // ya avisado hoy
    }

    encolar_whatsapp_plantilla(
        $pdo,
        "sla:{$c['cliente_id']}:{$hoy}",
        $c['contacto_whatsapp_vendedor'],
        'alerta_lead_sin_atender',
        [
            $c['nombre_empresa'],                    // {{1}}
            (string) (int) $c['sin_atender'],        // {{2}}
            (string) (int) $c['sla_horas_objetivo'], // {{3}}
            $dashboardUrl,                           // {{4}}
        ]
    );
    // Marca de dedup DESPUÉS de encolar. INSERT IGNORE: si otra corrida solapada
    // ya insertó la marca (uq_alerta), no lanza duplicado ni aborta el loop.
    $ins = $pdo->prepare("INSERT IGNORE INTO alertas_enviadas (cliente_id, fecha, tipo) VALUES (?, ?, 'lead_sin_atender')");
    $ins->execute([$c['cliente_id'], $hoy]);
    log_line("  -> Aviso SLA encolado, cliente #{$c['cliente_id']} ({$c['sin_atender']} sin atender)");
}

// --- Tercera pasada: drenar la cola de WhatsApp (envía con reintentos/backoff).
// Único drenador del sistema (cron/03 no drena) para no enviar dos veces la misma fila.
$resCola = procesar_cola_whatsapp($pdo, $whatsapp);
log_line("Cola WhatsApp: {$resCola['enviados']} enviados, {$resCola['reintentar']} a reintentar, {$resCola['fallidos']} fallidos");

// --- Cuarta pasada: reconciliar el flag de seguimiento contra el estado REAL de la
// cola (no el optimista al encolar). Solo cuando el mensaje quedó 'enviado' se marca
// el lead como seguido y se mueve a 'contactado'. Si quedó 'fallido' (agotó los 5
// reintentos) se marca el flag para no re-encolar en loop, pero el estado NO se toca
// (el vendedor no fue avisado → el lead sigue honestamente sin contactar). Reloj de
// PHP, no NOW(). Solo cambia estado si el lead sigue en 'pendiente' (respeta un avance
// manual del vendedor).
$ahoraRecon = date('Y-m-d H:i:s');
$recon = $pdo->prepare("
    UPDATE leads l
    JOIN cola_whatsapp q ON q.clave_idempotencia = CONCAT('seguimiento:', l.id)
    SET l.mensaje_seguimiento_enviado = 1,
        l.fecha_seguimiento_enviado = CASE WHEN q.estado = 'enviado' THEN ? ELSE l.fecha_seguimiento_enviado END,
        l.estado = CASE WHEN q.estado = 'enviado' AND l.estado = 'pendiente' THEN 'contactado' ELSE l.estado END
    WHERE q.estado IN ('enviado', 'fallido')
      AND l.mensaje_seguimiento_enviado = 0
");
$recon->execute([$ahoraRecon]);
log_line("Reconciliación de seguimiento: {$recon->rowCount()} leads actualizados según el estado de la cola.");
