<?php
/**
 * Cron diario (ej. 07:00 AM) — ingesta de métricas de campañas.
 * Trae inversión y leads de ayer desde Google Ads y Meta Ads para cada
 * cliente activo, y hace upsert en metricas_diarias.
 *
 * cPanel Cron: 0 7 * * * /usr/local/bin/php /home/USUARIO/donweb-mysql/cron/01_daily_ingest.php
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/google_ads_client.php';
require_once __DIR__ . '/../includes/meta_ads_client.php';
require_once __DIR__ . '/../includes/crons_heartbeat.php';

$pdo = db();
registrar_latido($pdo, '01_daily_ingest');
$fechaAyer = date('Y-m-d', strtotime('-1 day'));

$clientes = $pdo->query("SELECT id, nombre_empresa, google_ads_customer_id, meta_ad_account_id FROM clientes WHERE estado_cuenta = 'activo'")->fetchAll();

// GoogleAdsClient refresca el token OAuth ya en el constructor (llamada de
// red). Si esa credencial está mal configurada o Google Ads API está caída,
// no puede tirar abajo todo el cron — Meta Ads (y todos los clientes que
// solo usan Meta) tienen que poder seguir procesándose igual. Por eso cada
// cliente de API se instancia con su propio try/catch, quedando en null si
// falla, y el loop simplemente omite esa plataforma en ese caso.
try {
    $google = new GoogleAdsClient();
} catch (Throwable $e) {
    log_line('ERROR: no se pudo inicializar Google Ads Client, se omite Google Ads en toda esta corrida: ' . $e->getMessage());
    $google = null;
}

try {
    $meta = new MetaAdsClient();
} catch (Throwable $e) {
    log_line('ERROR: no se pudo inicializar Meta Ads Client, se omite Meta Ads en toda esta corrida: ' . $e->getMessage());
    $meta = null;
}

foreach ($clientes as $cliente) {
    log_line("Procesando cliente #{$cliente['id']} {$cliente['nombre_empresa']} — fecha $fechaAyer");

    // Los leads los cuenta registrar_lead() cuando entran por la landing propia
    // (public/landing.php) o por lead_capture.php — esa es la fuente de verdad
    // de leads en este proyecto. Por eso cron/01 trae SOLO la inversión de las
    // APIs y NO escribe leads_google/leads_meta: si lo hiciera, el upsert
    // pisaría los leads ya contados ese día. El conteo de conversiones que
    // devuelven las APIs se ignora a propósito.
    // null = NO se pudo obtener la inversión de esa plataforma (API caída, error
    // o timeout). Es distinto de 0.0 (día real sin gasto): un null NO se persiste
    // —el upsert de abajo preserva el valor previo en vez de pisarlo con un 0
    // falso que colapsaría el CPL/semáforo del día.
    $inversionGoogle = null;
    if ($google !== null && !empty($cliente['google_ads_customer_id'])) {
        try {
            $inversionGoogle = $google->metricasDelDia($cliente['google_ads_customer_id'], $fechaAyer)['inversion'];
        } catch (Throwable $e) {
            log_line('ERROR Google Ads (se preserva el dato previo del día): ' . $e->getMessage());
        }
    }

    $inversionMeta = null;
    if ($meta !== null && !empty($cliente['meta_ad_account_id'])) {
        try {
            $inversionMeta = $meta->metricasDelDia($cliente['meta_ad_account_id'], $fechaAyer)['inversion'];
        } catch (Throwable $e) {
            log_line('ERROR Meta Ads (se preserva el dato previo del día): ' . $e->getMessage());
        }
    }

    // Solo actualiza la inversión; NO toca leads_google/leads_meta (los maneja
    // registrar_lead). En un INSERT nuevo los leads quedan en su default 0 y se
    // van sumando a medida que entran; en un UPDATE se preservan intactos.
    // COALESCE: si una plataforma falló (:ig/:im = null), en un INSERT nuevo cae
    // a 0 (no había dato ese día) y en un UPDATE preserva el valor ya cargado —
    // nunca escribe un 0 falso sobre inversión real. Se usan placeholders
    // separados para VALUES y para UPDATE (PDO no reutiliza el mismo nombre).
    $stmt = $pdo->prepare('
        INSERT INTO metricas_diarias (cliente_id, fecha, inversion_google, inversion_meta)
        VALUES (:cliente_id, :fecha, COALESCE(:ig, 0), COALESCE(:im, 0))
        ON DUPLICATE KEY UPDATE
            inversion_google = COALESCE(:ig_upd, inversion_google),
            inversion_meta = COALESCE(:im_upd, inversion_meta)
    ');
    $stmt->execute([
        'cliente_id' => $cliente['id'],
        'fecha' => $fechaAyer,
        'ig' => $inversionGoogle,
        'im' => $inversionMeta,
        'ig_upd' => $inversionGoogle,
        'im_upd' => $inversionMeta,
    ]);

    log_line("  -> Inversión Google: " . ($inversionGoogle ?? 'sin dato') . " | Meta: " . ($inversionMeta ?? 'sin dato') . " (los leads los cuenta la landing)");
}

log_line('Ingesta diaria finalizada. Clientes procesados: ' . count($clientes));
