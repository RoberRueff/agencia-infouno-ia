<?php
require_once __DIR__ . '/db.php';

/**
 * Cliente mínimo de Meta Graph API (Marketing API) vía cURL.
 */
class MetaAdsClient
{
    private array $cfg;

    public function __construct()
    {
        $this->cfg = app_config()['meta'];
    }

    /**
     * Devuelve ['inversion' => float, 'leads' => int] para una ad account y fecha (Y-m-d).
     */
    public function metricasDelDia(string $adAccountId, string $fecha): array
    {
        $version = $this->cfg['graph_api_version'];
        // Defensa en profundidad: $accountId se interpola en el path de la URL, así
        // que se deja solo dígitos (quita el prefijo act_ y cualquier otro carácter);
        // y se valida el formato de $fecha (siempre viene de date('Y-m-d') de PHP).
        $accountId = preg_replace('/\D/', '', $adAccountId);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            throw new RuntimeException("Meta: fecha con formato inesperado: $fecha");
        }
        $timeRange = json_encode(['since' => $fecha, 'until' => $fecha]);

        $url = "https://graph.facebook.com/{$version}/act_{$accountId}/insights?" . http_build_query([
            'fields' => 'spend,actions',
            'time_range' => $timeRange,
            'access_token' => $this->cfg['access_token'],
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,   // un cuelgue de red no puede colgar el cron indefinidamente
            CURLOPT_TIMEOUT => 15,
        ]);
        $res = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Ante un error de la API se LANZA (no se devuelve 0): un 0 falso se
        // persistiría en metricas_diarias y colapsaría el CPL/semáforo del día.
        // cron/01 atrapa el throw y preserva el dato previo del día.
        if ($status !== 200) {
            throw new RuntimeException("Meta Graph API account=$adAccountId status=$status body=$res");
        }

        $data = json_decode($res, true);
        if (!is_array($data)) {
            throw new RuntimeException("Meta Graph API account=$adAccountId: respuesta no-JSON con status 200: $res");
        }

        // data vacío con status 200 = día legítimo sin actividad (campaña pausada,
        // sin gasto). Eso SÍ es un 0 real, no un error → se devuelve 0.
        $row = $data['data'][0] ?? null;
        if (!$row) {
            return ['inversion' => 0.0, 'leads' => 0];
        }

        $inversion = (float) ($row['spend'] ?? 0);
        $leads = 0;
        foreach ($row['actions'] ?? [] as $action) {
            if ($action['action_type'] === 'lead') {
                $leads = (int) $action['value'];
                break;
            }
        }
        return ['inversion' => $inversion, 'leads' => $leads];
    }
}
