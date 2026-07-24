<?php
// Cron diario (ej. 8:15 AM, después de cron/01 y cron/03) — empuja las señales
// de salud de cartera al admin interno por WhatsApp. Cierra el loop de
// includes/alertas_agencia.php, que hasta ahora solo se veía al abrir la cartera.
//
// cPanel Cron: 15 8 * * * /usr/local/bin/php /home/USUARIO/donweb-mysql/cron/05_avisos_cartera.php

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/whatsapp_client.php';
require_once __DIR__ . '/../includes/alertas_agencia.php';
require_once __DIR__ . '/../includes/crons_heartbeat.php';

$pdo = db();
registrar_latido($pdo, '05_avisos_cartera');
$whatsapp = new WhatsAppClient();

$avisos = alertas_agencia($pdo);
log_line('Avisos de cartera detectados: ' . count($avisos));
if (!$avisos) {
    log_line('Sin avisos hoy — no se envía nada.');
    return;
}

$hoy = date('Y-m-d'); // reloj de PHP (mismo criterio que cron/03)

// Dedup: un digest por día.
$check = $pdo->prepare('SELECT fecha FROM avisos_agencia_enviados WHERE fecha = ?');
$check->execute([$hoy]);
if ($check->fetch()) {
    log_line('El digest de hoy ya fue enviado — se omite.');
    return;
}

$urlCartera = str_replace('dashboard.php', 'admin/cartera.php', app_config()['app']['dashboard_base_url']);
$digest = armar_digest_cartera($avisos, $urlCartera);

$enviado = $whatsapp->enviarTexto(app_config()['whatsapp']['admin_interno_whatsapp'], $digest);

if ($enviado) {
    $ins = $pdo->prepare('INSERT INTO avisos_agencia_enviados (fecha, cantidad_avisos) VALUES (?, ?)');
    $ins->execute([$hoy, count($avisos)]);
    log_line('Digest de cartera enviado al admin interno (' . count($avisos) . ' avisos).');
} else {
    log_line('FALLÓ el envío del digest — se reintentará en la próxima corrida.');
}
