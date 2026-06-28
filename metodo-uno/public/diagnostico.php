<?php
/* =====================================================================
   Infouno — Método UNO® Nivel 1: proxy del diagnóstico (PHP)
   Reemplaza al server Node (server.js). Integrado al stack del sitio:
     - LLM vía API compatible con OpenAI (mismo api_base/key que chat.php).
     - Persistencia del lead en wp_infouno_leads vía db_lead.php (R4).
     - Rate-limit + honeypot vía ratelimit.php (bucket propio).
   La API key vive solo en config.php (raíz). Sirve en DonWeb/cPanel sin Node.
   ===================================================================== */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'method']);
  exit;
}

try {

$cfg = require __DIR__ . '/../../config.php';

// Rate-limit (anti-abuso del endpoint pago). Bucket separado del chat/lead.
require_once __DIR__ . '/../../ratelimit.php';
$rl = infouno_rate_check($cfg, ['bucket' => 'diagnostico']);
if (!$rl['ok']) {
  http_response_code(429);
  echo json_encode(['error' => 'Estamos recibiendo muchas solicitudes en este momento. Probá de nuevo en un minuto.']);
  exit;
}

$d = json_decode(file_get_contents('php://input'), true);
if (!is_array($d)) {
  http_response_code(400);
  echo json_encode(['error' => 'json']);
  exit;
}

// Honeypot: si viene relleno, es un bot. Respondemos OK genérico sin guardar ni llamar al LLM.
if (!empty($d['hp'])) {
  echo json_encode(['diagnostico' => 'Gracias por completar el diagnóstico. Nuestro equipo se pondrá en contacto.']);
  exit;
}

// Validación mínima de campos obligatorios (espejo del form).
$required = ['empresa', 'contacto', 'email', 'rubro', 'productos'];
foreach ($required as $f) {
  if (empty($d[$f]) || !is_string($d[$f]) || trim($d[$f]) === '') {
    http_response_code(400);
    echo json_encode(['error' => "Campo requerido faltante: $f"]);
    exit;
  }
}

/* -------- 1) Persistir el lead PRIMERO (no se pierde aunque el LLM falle) ---- */
require_once __DIR__ . '/../../db_lead.php';

$session = s($d['session_id'] ?? '', 64);
if ($session === '') $session = 'metodo-' . bin2hex(random_bytes(8));

// Resumen cualitativo (lo más valioso del Método UNO) → campo mensaje (db lo trunca a 1000).
$arr = function ($v) { return (is_array($v) && $v) ? implode(', ', array_map('strval', $v)) : ''; };
$resumen = implode(' · ', array_filter([
  ($d['cargo']        ?? '') ? 'Cargo: '         . $d['cargo']               : '',
  ($d['antiguedad']   ?? '') ? 'Antigüedad: '    . $d['antiguedad']          : '',
  ($d['productos']    ?? '') ? 'Productos: '     . $d['productos']           : '',
  $arr($d['objetivos'] ?? null) ? 'Objetivos: '  . $arr($d['objetivos'])     : '',
  ($d['masRentable']  ?? '') ? 'Más rentable: '  . $d['masRentable']         : '',
  ($d['venderMas']    ?? '') ? 'Quiere impulsar: ' . $d['venderMas']         : '',
  $arr($d['recursos'] ?? null) ? 'Recursos: '    . $arr($d['recursos'])      : '',
  ($d['competencia']  ?? '') ? 'Competencia: '   . $d['competencia']         : '',
  ($d['exito']        ?? '') ? 'Éxito 12m: '     . $d['exito']               : '',
  ($d['preguntaClientes'] ?? '') ? 'Pregunta clientes: ' . $d['preguntaClientes'] : '',
]));

$leadPayload = [
  'session_id' => $session,
  'source'     => 'metodo-uno',
  'name'       => $d['contacto'] ?? '',
  'empresa'    => $d['empresa']  ?? '',
  'rubro'      => $d['rubro']    ?? '',
  'email'      => $d['email']    ?? '',
  'whatsapp'   => $d['telefono'] ?? '',
  // Si declaró un sitio, lo tomamos como "ya tiene web" (alimenta el scoring/infra).
  'web'        => (!empty($d['sitio'])) ? 'ya tengo web' : 'arranco de cero',
  'mensaje'    => $resumen,
  'page'       => 'metodo-uno/public/metodo-uno-nivel1.html',
];
@infouno_save_lead($cfg, $leadPayload);  // best-effort: nunca rompe la respuesta al usuario

/* -------- 2) Generar el diagnóstico con el LLM (mismo proveedor que el bot) -- */
$systemPrompt = "Sos un consultor digital senior de Infouno, agencia argentina especializada en estrategia digital, diseño web y automatización para PyMEs.\n\n"
  . "Acabás de recibir el formulario de diagnóstico completado por un cliente potencial. Tu tarea es analizar sus respuestas y generar un Resumen Ejecutivo de Diagnóstico — Método UNO® Nivel 1 — que le sirva al equipo de Infouno para preparar una propuesta personalizada y que también motive al cliente a avanzar.\n\n"
  . "El resumen debe cubrir estos puntos (integrándolos en texto corrido, sin usar estos títulos literalmente):\n"
  . "1. Perfil del cliente — quiénes son, rubro, tiempo en el mercado.\n"
  . "2. Oportunidad principal — el mayor potencial de crecimiento digital basado en sus objetivos declarados.\n"
  . "3. Producto/servicio clave — cuál tiene mayor rentabilidad y cuál quieren impulsar este año.\n"
  . "4. Estado de recursos — qué tienen listo versus qué falta conseguir.\n"
  . "5. Insight de compra — la objeción o pregunta principal de sus clientes (señal directa para la propuesta).\n"
  . "6. Visión de éxito — qué tiene que pasar en 12 meses para que valga la pena la inversión.\n"
  . "7. Tres próximos pasos concretos que Infouno debería proponer.\n\n"
  . "Reglas de estilo:\n- Tono: directo, profesional, cálido. Voseo argentino.\n- Sin tecnicismos innecesarios.\n- Máximo 400 palabras.\n- No inventar información que no esté en el formulario.";

$userContent = infouno_diag_user_message($d);

$resp = infouno_diag_llm($cfg, $systemPrompt, $userContent);
if ($resp === null || $resp === '') {
  // El lead ya quedó guardado; avisamos del fallo del análisis.
  http_response_code(502);
  echo json_encode(['error' => 'No pudimos generar el diagnóstico ahora mismo, pero recibimos tus datos y el equipo de Infouno se va a comunicar con vos.']);
  exit;
}

echo json_encode(['diagnostico' => $resp]);

} catch (\Throwable $e) {
  // Red de seguridad: cualquier fatal/excepción se convierte en JSON (nunca 500 en blanco).
  // El detalle NO se expone al cliente (evita filtrar DB/rutas); queda en el log del server.
  error_log('infouno diagnostico: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode([
    'error' => 'Hubo un problema al procesar el diagnóstico. Recibimos tus datos igual.',
  ]);
}

/* ===================================================================== */

/** Arma el mensaje de usuario para el LLM a partir del formulario. */
function infouno_diag_user_message($d) {
  $str = function ($v) { return (is_string($v) && trim($v) !== '') ? trim($v) : 'No especificado'; };
  $arr = function ($v) { return (is_array($v) && $v) ? implode(', ', array_map('strval', $v)) : 'No especificado'; };

  return "FORMULARIO DE DIAGNÓSTICO — MÉTODO UNO® NIVEL 1\n\n"
    . "DATOS DE CONTACTO\n"
    . "Empresa: "       . $str($d['empresa'] ?? '')   . "\n"
    . "Contacto: "      . $str($d['contacto'] ?? '')  . " (" . $str($d['cargo'] ?? '') . ")\n"
    . "Teléfono: "      . $str($d['telefono'] ?? '')  . "\n"
    . "Email: "         . $str($d['email'] ?? '')     . "\n"
    . "Sitio web: "     . $str($d['sitio'] ?? '')     . "\n"
    . "Redes sociales: ". $str($d['redes'] ?? '')     . "\n\n"
    . "INFORMACIÓN GENERAL\n"
    . "Rubro / actividad: "        . $str($d['rubro'] ?? '')      . "\n"
    . "Antigüedad en el mercado: " . $str($d['antiguedad'] ?? '') . "\n\n"
    . "SU NEGOCIO\n"
    . "Productos o servicios: "    . $str($d['productos'] ?? '')   . "\n"
    . "Principales clientes: "     . $arr($d['clientes'] ?? null)  . "\n"
    . "Objetivos del sitio: "      . $arr($d['objetivos'] ?? null) . "\n"
    . "Producto de mayor rentabilidad: " . $str($d['masRentable'] ?? '') . "\n"
    . "Producto que quiere vender más este año: " . $str($d['venderMas'] ?? '') . "\n\n"
    . "RECURSOS DISPONIBLES\n"
    . "Materiales que ya poseen: " . $arr($d['recursos'] ?? null)  . "\n"
    . "Sitios web de referencia (estilo): " . $str($d['sitiosRef'] ?? '') . "\n"
    . "Principal competencia: "    . $str($d['competencia'] ?? '') . "\n\n"
    . "VISIÓN Y CIERRE\n"
    . "Definición de éxito en 12 meses: " . $str($d['exito'] ?? '') . "\n"
    . "Pregunta frecuente de sus clientes antes de comprar: " . $str($d['preguntaClientes'] ?? '') . "\n"
    . "Información adicional: "     . $str($d['infoExtra'] ?? '');
}

/**
 * Llama al LLM por la API Chat Completions compatible con OpenAI (mismo
 * api_base/key/model que chat.php). Devuelve el texto del diagnóstico o null.
 */
function infouno_diag_llm($cfg, $system, $user) {
  if (empty($cfg['openai_key'])) return null;
  $endpoint = !empty($cfg['api_base']) ? $cfg['api_base'] : 'https://api.openai.com/v1/chat/completions';
  $payload = json_encode([
    'model'       => $cfg['openai_model'] ?? 'gpt-4o-mini',
    'temperature' => 0.3,
    'max_tokens'  => 1024,
    'messages'    => [
      ['role' => 'system', 'content' => $system],
      ['role' => 'user',   'content' => $user],
    ],
  ]);

  $ch = curl_init($endpoint);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['openai_key']],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 30,
  ]);
  $raw  = curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $cerr = curl_error($ch);
  curl_close($ch);
  if ($raw === false || $code !== 200) {
    error_log('infouno diag LLM: code=' . $code . ' curlerr=' . $cerr . ' body=' . substr((string) $raw, 0, 400));
    return null;
  }

  $data = json_decode($raw, true);
  $text = $data['choices'][0]['message']['content'] ?? '';
  if (trim((string) $text) === '') {
    error_log('infouno diag LLM: 200 pero content vacío. finish=' . ($data['choices'][0]['finish_reason'] ?? '?') . ' body=' . substr((string) $raw, 0, 400));
  }
  return is_string($text) ? trim($text) : null;
}
