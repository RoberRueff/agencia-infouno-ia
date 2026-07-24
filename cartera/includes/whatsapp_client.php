<?php
require_once __DIR__ . '/db.php';

/**
 * Cliente mínimo de WhatsApp Business Cloud API vía cURL.
 */
class WhatsAppClient
{
    private array $cfg;

    public function __construct()
    {
        $this->cfg = app_config()['whatsapp'];
    }

    /**
     * POST a la Graph API de WhatsApp. Devuelve el resultado clasificado para el worker de la cola.
     * @return array{ok:bool, status:int, reintentable:bool, error:?string}
     */
    private function post(array $payload): array
    {
        // Modo prueba (dry-run): no se envía nada a la Graph API. Loguea el mensaje
        // que se habría mandado y simula éxito, para correr los crons en un entorno
        // de prueba sin disparar WhatsApp real. Enforcea el guardrail "nunca enviar
        // WhatsApp real en pruebas" a nivel código, no solo por convención de config.
        if (!empty($this->cfg['modo_prueba'])) {
            log_line('WhatsApp MODO_PRUEBA (no se envía) payload=' . json_encode($payload, JSON_UNESCAPED_UNICODE));
            return ['ok' => true, 'status' => 200, 'reintentable' => false, 'error' => null];
        }

        $version = $this->cfg['graph_api_version'];
        $phoneId = $this->cfg['phone_number_id'];
        $url = "https://graph.facebook.com/{$version}/{$phoneId}/messages";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_CONNECTTIMEOUT => 5,   // acota el peor caso: sin esto un cuelgue rompe el invariante de "un solo drenador"
            CURLOPT_TIMEOUT => 15,         // un timeout devuelve status 0 → ya clasificado como reintentable
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->cfg['access_token'],
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $res = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($status === 200) {
            return ['ok' => true, 'status' => 200, 'reintentable' => false, 'error' => null];
        }

        log_line("WARN WhatsApp send status=$status body=$res payload=" . json_encode($payload));
        $error = $curlError !== '' ? $curlError : ('HTTP ' . $status . ' ' . substr((string) $res, 0, 200));
        return ['ok' => false, 'status' => $status, 'reintentable' => _whatsapp_reintentable($status), 'error' => $error];
    }

    public function enviarTexto(string $telefono, string $texto): bool
    {
        return $this->enviarTextoDesdeCola($telefono, $texto)['ok'];
    }

    /** Envía un texto libre y devuelve el resultado rico (ok/status/reintentable/error) para el worker de la cola. */
    public function enviarTextoDesdeCola(string $telefono, string $texto): array
    {
        return $this->post([
            'messaging_product' => 'whatsapp',
            'to' => $telefono,
            'type' => 'text',
            'text' => ['body' => $texto],
        ]);
    }

    public function enviarPlantilla(string $telefono, string $nombrePlantilla, array $parametrosTexto): bool
    {
        return $this->enviarDesdeCola($telefono, $nombrePlantilla, $parametrosTexto)['ok'];
    }

    /** Envía una plantilla y devuelve el resultado rico (ok/status/reintentable/error) para el worker de la cola. */
    public function enviarDesdeCola(string $telefono, string $nombrePlantilla, array $parametrosTexto): array
    {
        return $this->post([
            'messaging_product' => 'whatsapp',
            'to' => $telefono,
            'type' => 'template',
            'template' => [
                'name' => $nombrePlantilla,
                'language' => ['code' => 'es_AR'],
                'components' => [[
                    'type' => 'body',
                    'parameters' => array_map(fn($t) => ['type' => 'text', 'text' => (string) $t], $parametrosTexto),
                ]],
            ],
        ]);
    }
}

/**
 * Clasifica un fallo de envío como transitorio (vale reintentar) o permanente.
 * Puro (sin cURL), para testearlo aparte. status 0 = sin conexión/timeout.
 */
function _whatsapp_reintentable(int $status): bool
{
    if ($status === 0 || $status === 429) {
        return true;               // sin conexión/timeout, o rate-limit
    }
    return $status >= 500;         // 5xx transitorio; 4xx (salvo 429) permanente
}
