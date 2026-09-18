<?php
/**
 * Webhook público de WhatsApp Cloud API — recepción de respuestas y cierre de ventas.
 * GET: verificación del webhook (Meta la pide una vez al configurar).
 * POST: respuestas del vendedor al seguimiento de 48hs. Cierra el lead abierto
 * más reciente del cliente ruteando por el ciclo de vida: si el texto es un
 * monto puro lo marca vendido (lead_a_ganado, registra la venta una sola vez y
 * fija fecha_primer_contacto); si no, lo marca no_vendido con el texto como
 * motivo (lead_a_perdido). Best-effort: el mensaje no trae id de lead.
 *
 * URL pública a configurar en Meta App Dashboard → WhatsApp → Webhook:
 * https://tudominio.com.ar/donweb-mysql/public/webhook_whatsapp.php
 */

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/whatsapp_client.php';
require_once __DIR__ . '/../includes/leads_lifecycle.php';

$cfg = app_config()['whatsapp'];

// ---------- Verificación del webhook (GET) ----------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    if ($mode === 'subscribe' && hash_equals($cfg['webhook_verify_token'], $token)) {
        echo $challenge;
        exit;
    }
    http_response_code(403);
    exit('Verificación fallida');
}

// ---------- Mensajes entrantes (POST) ----------
$rawBody = file_get_contents('php://input');

// Meta firma cada POST con X-Hub-Signature-256 (HMAC-SHA256 del body crudo,
// usando el App Secret). Sin esto, cualquiera que conozca la URL podría
// mandar un POST falso simulando una venta y adulterar las métricas.
$firmaRecibida = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$firmaEsperada = 'sha256=' . hash_hmac('sha256', $rawBody, $cfg['app_secret']);
if (!hash_equals($firmaEsperada, $firmaRecibida)) {
    log_line('WARN: webhook de WhatsApp con firma inválida, se descarta.');
    exit;
}

$body = json_decode($rawBody, true);

http_response_code(200); // confirmar recepción a Meta siempre, aunque no haya nada procesable

// Un POST puede traer varios mensajes: varias entries, varios changes por entry y
// varios messages por change. Se juntan todos y se procesa cada uno. Antes solo se
// miraba entry[0].changes[0].value.messages[0] y se descartaba el resto en silencio.
$mensajes = [];
foreach ($body['entry'] ?? [] as $entry) {
    foreach ($entry['changes'] ?? [] as $change) {
        foreach ($change['value']['messages'] ?? [] as $m) {
            $mensajes[] = $m;
        }
    }
}
if (!$mensajes) {
    exit;
}

$pdo = db();
$whatsapp = new WhatsAppClient();
foreach ($mensajes as $message) {
    procesar_mensaje_whatsapp($pdo, $whatsapp, $message);
}

/**
 * Procesa un mensaje entrante del webhook: dedup anti-replay por message_id,
 * resolución del cliente por el número del vendedor, y ruteo del ciclo de vida del
 * lead (monto → venta con lead_a_ganado; texto → pérdida con lead_a_perdido).
 * Best-effort: el mensaje no trae id de lead, se usa el lead abierto más reciente.
 */
function procesar_mensaje_whatsapp(PDO $pdo, WhatsAppClient $whatsapp, array $message): void
{
    // Anti-replay: la firma HMAC prueba que el POST vino de Meta, pero no impide
    // reenviar el mismo POST firmado dos veces (duplicaría la venta). Guardamos el
    // id de cada mensaje ya procesado y descartamos los repetidos. El INSERT IGNORE
    // contra la PK es atómico: si afecta 0 filas, ya lo habíamos procesado.
    $messageId = $message['id'] ?? null;
    if ($messageId !== null) {
        $dedup = $pdo->prepare('INSERT IGNORE INTO mensajes_procesados (message_id) VALUES (?)');
        $dedup->execute([$messageId]);
        if ($dedup->rowCount() === 0) {
            log_line("Webhook: mensaje repetido descartado (id=$messageId)");
            return;
        }
    }

    $telefonoRemitente = $message['from'] ?? '';
    $textoOriginal = $message['text']['body'] ?? '';

    // Resolver el cliente activo por el número del vendedor (ambas ramas lo necesitan).
    $clienteStmt = $pdo->prepare("SELECT id FROM clientes WHERE contacto_whatsapp_vendedor = ? AND estado_cuenta = 'activo'");
    $clienteStmt->execute([$telefonoRemitente]);
    $cliente = $clienteStmt->fetch();
    if (!$cliente) {
        log_line("WARN: mensaje de WhatsApp de un número sin cliente activo asociado: $telefonoRemitente");
        return;
    }
    $clienteId = (int) $cliente['id'];

    $leadId = lead_abierto_mas_reciente($pdo, $clienteId);
    $monto = parsear_monto_whatsapp($textoOriginal);

    if ($monto !== null) {
        // Venta cerrada: cerrar el lead abierto más reciente (registra la métrica una sola vez).
        if ($leadId !== null && lead_a_ganado($pdo, $leadId, $clienteId, $monto)) {
            $whatsapp->enviarTexto($telefonoRemitente, '✅ Venta de $' . formatear_ars($monto) . ' registrada, ¡gracias!');
            log_line("Venta por WhatsApp: cliente #$clienteId lead #$leadId monto=$monto");
        } else {
            $whatsapp->enviarTexto($telefonoRemitente, 'Recibimos el monto pero no encontramos un lead abierto para asociarlo (¿ya lo habías cargado?). No registramos nada para evitar duplicar.');
            log_line("WARN: monto por WhatsApp sin lead abierto (cliente #$clienteId), no se registró.");
        }
    } else {
        // No cerró: marcar perdido con el texto como motivo.
        $motivo = trim(mb_substr($textoOriginal, 0, 255));
        if ($leadId !== null && $motivo !== '' && lead_a_perdido($pdo, $leadId, $clienteId, $motivo)) {
            $whatsapp->enviarTexto($telefonoRemitente, 'Anotado, marcamos el lead como no concretado. ¡Gracias por el dato!');
            log_line("Pérdida por WhatsApp: cliente #$clienteId lead #$leadId motivo=\"$motivo\"");
        } else {
            $whatsapp->enviarTexto($telefonoRemitente, 'No encontramos un lead abierto para asociar tu respuesta. Si cerraste una venta, respondé solo con el monto (ej: 150000).');
            log_line("WARN: respuesta no-monto por WhatsApp sin lead abierto o vacía (cliente #$clienteId).");
        }
    }
}
