<?php
/**
 * Endpoint público que recibe nuevos leads (ej. desde el formulario de una
 * landing page o un Webhook de Google/Meta Lead Ads) y los guarda en `leads`.
 *
 * POST JSON: { "cliente_id": 1, "nombre_lead": "...", "telefono_lead": "...", "fuente": "google|meta" }
 * Header requerido: X-Api-Key: <app.lead_capture_api_key de config.php>
 *
 * cliente_id es un entero secuencial adivinable — sin este chequeo, cualquiera
 * podría inyectar leads falsos y disparar mensajes reales de WhatsApp 48hs
 * después. La landing page / integración que llame a este endpoint debe
 * mandar la API key en el header (no en el body, para no loguearla por error).
 */

require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$apiKeyRecibida = $_SERVER['HTTP_X_API_KEY'] ?? '';
$apiKeyEsperada = app_config()['app']['lead_capture_api_key'] ?? '';
if ($apiKeyEsperada === '' || !hash_equals($apiKeyEsperada, $apiKeyRecibida)) {
    http_response_code(401);
    echo json_encode(['error' => 'API key inválida']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'JSON inválido']);
    exit;
}

$clienteId = (int) ($body['cliente_id'] ?? 0);
$nombreLead = trim($body['nombre_lead'] ?? '');
$telefonoLead = trim($body['telefono_lead'] ?? '');
$fuente = in_array($body['fuente'] ?? '', ['google', 'meta'], true) ? $body['fuente'] : null;

if (!$clienteId || $nombreLead === '' || $telefonoLead === '' || !$fuente) {
    http_response_code(422);
    echo json_encode(['error' => 'Faltan campos: cliente_id, nombre_lead, telefono_lead, fuente(google|meta)']);
    exit;
}

$leadId = registrar_lead($clienteId, $nombreLead, $telefonoLead, $fuente);

if ($leadId === null) {
    http_response_code(404);
    echo json_encode(['error' => 'Cliente no encontrado o inactivo']);
    exit;
}

echo json_encode(['ok' => true, 'lead_id' => $leadId]);
