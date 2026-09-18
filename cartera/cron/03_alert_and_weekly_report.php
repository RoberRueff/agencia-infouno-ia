<?php
/**
 * Cron diario (ej. 08:00 AM) — alertas de salud comercial y reporte semanal.
 * 1) Detecta clientes con 2 días consecutivos en 🔴 y avisa por WhatsApp (una sola vez).
 * 2) Si hoy es lunes, además envía el reporte semanal de ROAS a cada cliente activo.
 *
 * cPanel Cron: 0 8 * * * /usr/local/bin/php /home/USUARIO/donweb-mysql/cron/03_alert_and_weekly_report.php
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/whatsapp_client.php';
require_once __DIR__ . '/../includes/crons_heartbeat.php';
require_once __DIR__ . '/../includes/cola_whatsapp.php';

$pdo = db();
registrar_latido($pdo, '03_alert_and_weekly_report');

// ---------- 1) Alerta preventiva: 2 días consecutivos en rojo ----------
// Se comparan los dos últimos días CON datos de campañas ya ingestados: ayer y
// anteayer. (cron/01 corre a la mañana e ingesta los datos de *ayer*, así que
// la fila de "hoy" todavía no tiene inversión de ads — por eso la ventana es
// ayer/anteayer y no hoy/ayer.)
$hoy = date('Y-m-d');                              // fecha de esta corrida (dedup de alertas_enviadas)
$ayer = date('Y-m-d', strtotime('-1 day'));
$anteayer = date('Y-m-d', strtotime('-2 days'));

$stmt = $pdo->prepare("
    SELECT cliente_id, nombre_empresa, fecha, cpl_dia, estado_semaforo, cpl_maximo_permitido
    FROM vista_metricas_semaforo
    WHERE fecha IN (:ayer, :anteayer)
    ORDER BY cliente_id, fecha
");
$stmt->execute(['ayer' => $ayer, 'anteayer' => $anteayer]);
$filas = $stmt->fetchAll();

$porCliente = [];
foreach ($filas as $f) {
    $porCliente[$f['cliente_id']][$f['fecha']] = $f;
}

foreach ($porCliente as $clienteId => $dias) {
    if (!isset($dias[$ayer], $dias[$anteayer])) {
        continue; // falta un día, no podemos confirmar 2 consecutivos
    }
    $ambosRojo = str_contains($dias[$ayer]['estado_semaforo'], '🔴') && str_contains($dias[$anteayer]['estado_semaforo'], '🔴');
    if (!$ambosRojo) {
        continue;
    }

    // Solo clientes activos (un cliente pausado/cancelado no debe recibir
    // alertas por datos históricos). Mismo patrón que el resto del proyecto.
    $clienteStmt = $pdo->prepare("SELECT contacto_whatsapp_vendedor, id AS cliente_id FROM clientes WHERE id = ? AND estado_cuenta = 'activo'");
    $clienteStmt->execute([$clienteId]);
    $cliente = $clienteStmt->fetch();
    if (!$cliente) {
        continue;
    }

    // Evitar reenviar la misma alerta el mismo día
    $check = $pdo->prepare('SELECT id FROM alertas_enviadas WHERE cliente_id = ? AND fecha = ? AND tipo = ?');
    $check->execute([$clienteId, $hoy, 'cpl_alto_2dias']);
    if ($check->fetch()) {
        continue;
    }

    encolar_whatsapp_plantilla(
        $pdo,
        "preventiva:{$clienteId}:{$hoy}",
        $cliente['contacto_whatsapp_vendedor'],
        'alerta_preventiva',
        [
            $dias[$ayer]['nombre_empresa'],
            formatear_ars((float) $dias[$ayer]['cpl_maximo_permitido']),
            date('d/m/Y', strtotime($anteayer)),
            formatear_ars((float) $dias[$anteayer]['cpl_dia']),
            date('d/m/Y', strtotime($ayer)),
            formatear_ars((float) $dias[$ayer]['cpl_dia']),
            app_config()['app']['dashboard_base_url'] . '?cliente=' . $clienteId,
        ]
    );
    // Marca de dedup DESPUÉS de encolar. INSERT IGNORE: si otra corrida solapada
    // ya insertó la marca (uq_alerta), no lanza duplicado ni aborta el loop.
    $ins = $pdo->prepare('INSERT IGNORE INTO alertas_enviadas (cliente_id, fecha, tipo) VALUES (?, ?, ?)');
    $ins->execute([$clienteId, $hoy, 'cpl_alto_2dias']);
    log_line("Alerta preventiva encolada a cliente #$clienteId");
}

// ---------- 2) Reporte semanal de ROAS (solo lunes) ----------
if (date('N') == 1) { // 1 = lunes
    log_line('Hoy es lunes — generando reportes semanales...');

    $clientes = $pdo->query("SELECT id, nombre_empresa, contacto_whatsapp_vendedor FROM clientes WHERE estado_cuenta = 'activo'")->fetchAll();
    $desde = date('Y-m-d', strtotime('-7 days'));
    $hastaAyer = date('Y-m-d', strtotime('-1 day'));

    foreach ($clientes as $cliente) {
        // ROAS y CPL del período se calculan en SQL (misma fórmula que las
        // vistas: monto/inversión, inversión/leads), no dividiendo en PHP —
        // regla del proyecto (ai/rules.md): no reimplementar ROAS/CPL en PHP.
        // Es una agregación de ventana móvil (últimos 7 días), que no puede ser
        // una vista fija, por eso va como cálculo SQL en esta consulta.
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(inversion_total), 0) AS inversion,
                COALESCE(SUM(leads_totales), 0) AS leads,
                COALESCE(SUM(ventas_cant), 0) AS ventas_cant,
                COALESCE(SUM(monto_venta), 0) AS monto_ventas,
                CASE WHEN COALESCE(SUM(inversion_total), 0) = 0 THEN 0
                     ELSE ROUND(SUM(monto_venta) / SUM(inversion_total), 2) END AS roas,
                CASE WHEN COALESCE(SUM(leads_totales), 0) = 0 THEN 0
                     ELSE ROUND(SUM(inversion_total) / SUM(leads_totales)) END AS cpl_promedio
            FROM metricas_diarias
            WHERE cliente_id = ? AND fecha BETWEEN ? AND ?
        ");
        $stmt->execute([$cliente['id'], $desde, $hastaAyer]);
        $agg = $stmt->fetch();

        $inversion = (float) $agg['inversion'];
        $leads = (int) $agg['leads'];
        $roas = (float) $agg['roas'];
        $cplProm = (float) $agg['cpl_promedio'];

        $ultimoEstadoStmt = $pdo->prepare('SELECT estado_semaforo FROM vista_metricas_semaforo WHERE cliente_id = ? ORDER BY fecha DESC LIMIT 1');
        $ultimoEstadoStmt->execute([$cliente['id']]);
        $ultimoEstado = $ultimoEstadoStmt->fetchColumn() ?: '⚪ Sin Datos';

        encolar_whatsapp_plantilla(
            $pdo,
            "reporte_semanal:{$cliente['id']}:{$hoy}",
            $cliente['contacto_whatsapp_vendedor'],
            'reporte_semanal_roas',
            [
                $cliente['nombre_empresa'],
                date('d/m/Y', strtotime($desde)),
                date('d/m/Y', strtotime($hastaAyer)),
                formatear_ars($inversion),
                (string) $leads,
                (string) $agg['ventas_cant'],
                formatear_ars((float) $agg['monto_ventas']),
                (string) $roas,
                formatear_ars($cplProm),
                $ultimoEstado,
                app_config()['app']['dashboard_base_url'] . '?cliente=' . $cliente['id'],
            ]
        );
        log_line("Reporte semanal encolado a cliente #{$cliente['id']} {$cliente['nombre_empresa']}");
    }
}
