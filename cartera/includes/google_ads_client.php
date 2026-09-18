<?php
require_once __DIR__ . '/db.php';

/**
 * Cliente mínimo de Google Ads API (REST) vía cURL, sin SDK.
 * Suficiente para traer costo y conversiones de un día puntual.
 */
class GoogleAdsClient
{
    private string $accessToken;
    private array $cfg;

    public function __construct()
    {
        $this->cfg = app_config()['google_ads'];
        $this->accessToken = $this->refreshAccessToken();
    }

    private function refreshAccessToken(): string
    {
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_CONNECTTIMEOUT => 5,   // un cuelgue de red no puede colgar el cron indefinidamente
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $this->cfg['client_id'],
                'client_secret' => $this->cfg['client_secret'],
                'refresh_token' => $this->cfg['refresh_token'],
                'grant_type' => 'refresh_token',
            ]),
        ]);
        $res = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($res, true);
        if ($status !== 200 || empty($json['access_token'])) {
            throw new RuntimeException('No se pudo refrescar el token de Google Ads: ' . $res);
        }
        return $json['access_token'];
    }

    /**
     * Devuelve ['inversion' => float, 'leads' => int] para un customerId y fecha (Y-m-d).
     */
    public function metricasDelDia(string $customerId, string $fecha): array
    {
        $customerId = preg_replace('/\D/', '', $customerId);
        // Defensa en profundidad: $fecha se interpola en la GAQL, así que se valida
        // el formato antes de armar la query (siempre viene de date('Y-m-d') de PHP).
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            throw new RuntimeException("Google Ads: fecha con formato inesperado: $fecha");
        }
        $query = "SELECT metrics.cost_micros, metrics.conversions FROM customer WHERE segments.date = '$fecha'";

        $url = "https://googleads.googleapis.com/v17/customers/{$customerId}/googleAds:searchStream";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_CONNECTTIMEOUT => 5,   // un cuelgue de red no puede colgar el cron indefinidamente
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'developer-token: ' . $this->cfg['developer_token'],
                'login-customer-id: ' . $this->cfg['login_customer_id'],
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode(['query' => $query]),
        ]);
        $res = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Ante un error de la API se LANZA (no se devuelve 0): un 0 falso se
        // persistiría en metricas_diarias y colapsaría el CPL/semáforo del día
        // (un cliente pasado de CPL dejaría de marcar rojo). cron/01 atrapa el
        // throw, omite la plataforma y preserva el dato previo del día. Mismo
        // criterio que refreshAccessToken().
        if ($status !== 200) {
            throw new RuntimeException("Google Ads API customer=$customerId status=$status body=$res");
        }

        $data = json_decode($res, true);
        if (!is_array($data)) {
            throw new RuntimeException("Google Ads API customer=$customerId: respuesta no-JSON con status 200: $res");
        }

        $inversion = 0.0;
        $leads = 0;
        foreach ($data as $chunk) {
            foreach ($chunk['results'] ?? [] as $r) {
                $inversion += ((float) ($r['metrics']['costMicros'] ?? 0)) / 1000000;
                $leads += (int) round((float) ($r['metrics']['conversions'] ?? 0));
            }
        }
        return ['inversion' => $inversion, 'leads' => $leads];
    }
}
