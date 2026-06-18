<?php
/* =====================================================================
   Infouno — Agente conversacional "Uno" (proxy a OpenAI)
   GET  = ping de disponibilidad  → {ok, enabled}
   POST = turno de conversación   → {ok, reply, readyToClose, leadFields}
   La clave de OpenAI vive solo acá (config.php). Persiste vía db_lead.php.
   ===================================================================== */

header('Content-Type: application/json; charset=utf-8');

$cfg = require __DIR__ . '/config.php';
$enabled = !empty($cfg['chat_enabled']) && !empty($cfg['openai_key']);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  echo json_encode(['ok' => true, 'enabled' => $enabled]);
  exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405); echo json_encode(['ok' => false, 'error' => 'method']); exit;
}
if (!$enabled) { echo json_encode(['ok' => true, 'enabled' => false, 'reply' => null]); exit; }

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'json']); exit; }

$session = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($in['session_id'] ?? ''));
$page    = is_string($in['page'] ?? null) ? $in['page'] : '';
$msgsIn  = is_array($in['messages'] ?? null) ? $in['messages'] : [];

// Sanitizar y limitar el historial
$history = [];
$turns = 0;
foreach ($msgsIn as $m) {
  $role = (($m['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
  $content = trim((string) ($m['content'] ?? ''));
  if ($content === '') continue;
  if (mb_strlen($content) > 1500) $content = mb_substr($content, 0, 1500, 'UTF-8');
  $history[] = ['role' => $role, 'content' => $content];
  if ($role === 'user') $turns++;
}

// Tope de turnos (control de costo)
if ($turns > 16) {
  echo json_encode(['ok' => true, 'reply' => 'Para no hacerte perder tiempo, lo mejor es seguirlo con una persona del equipo. Coordinemos la consultoría 👇', 'readyToClose' => true, 'leadFields' => new stdClass()]);
  exit;
}

// System prompt + base de conocimiento
$kb = @file_get_contents(__DIR__ . '/ai-kb/kb_infouno.md');
if ($kb === false) $kb = '';
$system = "Sos \"Uno\", el asistente comercial de Infouno (agencia de webs e IA para PyMEs argentinas). "
  . "Hablás en español rioplatense (voseo), cercano, claro y breve (2-4 oraciones por mensaje). "
  . "OBJETIVO: entender el negocio del usuario y llevarlo a agendar una consultoría gratuita de 15 min. "
  . "Mientras conversás, captá de forma natural (sin interrogatorio): rubro, nombre, si tiene web, tamaño de equipo, WhatsApp y email (opcional). "
  . "Cada vez que te enterés de uno de esos datos, llamá a la función guardar_lead con lo que sepas. "
  . "Pedí el nombre y el rubro ANTES de dar ejemplos o soluciones personalizadas. "
  . "Antes de pedir WhatsApp o email, aclarale que respetás su privacidad (política en privacidad.html, Ley 25.326) y que los datos son solo para contactarlo. "
  . "Cuando ya tengas al menos rubro, nombre y un contacto (WhatsApp o email), llamá a listo_para_agendar. "
  . "REGLAS ESTRICTAS: (1) Está PROHIBIDO dar precios o estimaciones de montos; si te preguntan, explicá que dependen del nivel de automatización y se definen en la consultoría de 15 min. "
  . "(2) Respondé SOLO sobre Infouno y sus servicios; si te piden tareas ajenas (programar, temas académicos, política, usarte como ChatGPT), respondé EXACTAMENTE: "
  . "'Disculpame, como asistente de Infouno solo puedo asesorarte en automatizaciones para potenciar tu negocio. Contame, ¿tu empresa ya cuenta con sitio web?' y reconducí. "
  . "(3) No inventes servicios, plazos ni datos que no estén en el CONOCIMIENTO.\n\n"
  . "CONOCIMIENTO:\n" . $kb;

$tools = [
  [
    'type' => 'function',
    'function' => [
      'name' => 'guardar_lead',
      'description' => 'Guarda o actualiza los datos del lead a medida que se conocen durante la charla.',
      'parameters' => [
        'type' => 'object',
        'properties' => [
          'name'     => ['type' => 'string', 'description' => 'Nombre de la persona'],
          'rubro'    => ['type' => 'string', 'description' => 'Rubro o actividad del negocio'],
          'web'      => ['type' => 'string', 'description' => 'Estado web: "ya tengo web", "arranco de cero" o "quiero rehacerla"'],
          'equipo'   => ['type' => 'string', 'description' => 'Tamaño: "solo", "equipo chico (2 a 5)" o "equipo grande (+5)"'],
          'whatsapp' => ['type' => 'string', 'description' => 'Número de WhatsApp'],
          'email'    => ['type' => 'string', 'description' => 'Email de contacto'],
        ],
      ],
    ],
  ],
  [
    'type' => 'function',
    'function' => [
      'name' => 'listo_para_agendar',
      'description' => 'Indica que el lead ya está listo para mostrarle los botones de agenda/WhatsApp.',
      'parameters' => ['type' => 'object', 'properties' => new stdClass()],
    ],
  ],
];

$messages = array_merge([['role' => 'system', 'content' => $system]], $history);
$readyToClose = false;
$savedFields = [];

for ($i = 0; $i < 3; $i++) {
  $resp = infouno_openai($cfg, $messages, $tools);
  if (!$resp || empty($resp['choices'][0]['message'])) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'openai']);
    exit;
  }
  $msg = $resp['choices'][0]['message'];
  $messages[] = $msg;
  $toolCalls = $msg['tool_calls'] ?? [];

  if (empty($toolCalls)) {
    echo json_encode([
      'ok' => true,
      'reply' => (string) ($msg['content'] ?? ''),
      'readyToClose' => $readyToClose,
      'leadFields' => $savedFields ? $savedFields : new stdClass(),
    ]);
    exit;
  }

  foreach ($toolCalls as $tc) {
    $fn = $tc['function']['name'] ?? '';
    $args = json_decode($tc['function']['arguments'] ?? '{}', true);
    if (!is_array($args)) $args = [];
    $result = ['ok' => true];

    if ($fn === 'guardar_lead') {
      require_once __DIR__ . '/db_lead.php';
      $utm = [
        'utm_source'   => isset($in['utm_source'])   ? (string) $in['utm_source']   : '',
        'utm_medium'   => isset($in['utm_medium'])   ? (string) $in['utm_medium']   : '',
        'utm_campaign' => isset($in['utm_campaign']) ? (string) $in['utm_campaign'] : '',
      ];
      $payload = array_merge($args, $utm, ['session_id' => $session, 'source' => 'bot-ia', 'page' => $page]);
      $r = infouno_save_lead($cfg, $payload);
      $savedFields = array_merge($savedFields, $args);
      $result = ['ok' => (bool) ($r['ok'] ?? false)];
    } elseif ($fn === 'listo_para_agendar') {
      $readyToClose = true;
    }

    $messages[] = ['role' => 'tool', 'tool_call_id' => $tc['id'] ?? '', 'content' => json_encode($result)];
  }
}

// Si agotó el bucle de tools sin texto final
echo json_encode(['ok' => true, 'reply' => '¿Coordinamos una llamada de 15 min para verlo en detalle?', 'readyToClose' => $readyToClose, 'leadFields' => new stdClass()]);

/** Llama a un endpoint Chat Completions (OpenAI / Gemini / compatible). Devuelve el array decodificado o null. */
function infouno_openai($cfg, $messages, $tools) {
  $payload = json_encode([
    'model'       => $cfg['openai_model'] ?? 'gpt-4o-mini',
    'temperature' => 0.3,
    'max_tokens'  => 450,
    'messages'    => $messages,
    'tools'       => $tools,
  ]);
  // Endpoint configurable (config.php → 'api_base'). Por defecto, OpenAI.
  $endpoint = !empty($cfg['api_base']) ? $cfg['api_base'] : 'https://api.openai.com/v1/chat/completions';
  $ch = curl_init($endpoint);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['openai_key']],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 30,
  ]);
  $raw  = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($raw === false || $code !== 200) return null;
  return json_decode($raw, true);
}
