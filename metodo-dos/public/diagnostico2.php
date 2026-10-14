<?php
/* =====================================================================
   Infouno — Método DOS® Nivel 2: diagnóstico IOI® (PHP)
   IOI® determinístico (IOIEngine) + persistencia (db_lead.php) + narrativo LLM.
   Misma infraestructura que el Método UNO®. Sirve en DonWeb/cPanel sin Node.
   ===================================================================== */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'method']);
  exit;
}

try {
  $cfg = require __DIR__ . '/../../config.php';

  require_once __DIR__ . '/../../ratelimit.php';
  $rl = infouno_rate_check($cfg, ['bucket' => 'diagnostico2']);
  if (!$rl['ok']) {
    http_response_code(429);
    echo json_encode(['error' => 'Estamos recibiendo muchas solicitudes. Probá de nuevo en un minuto.']);
    exit;
  }

  $d = infouno_read_json(40000);
  if ($d === null) {
    http_response_code(400);
    echo json_encode(['error' => 'json']);
    exit;
  }

  $d = infouno_clamp($d, 1500, 30);

  // Honeypot: bot → OK genérico sin guardar ni llamar al LLM.
  if (!empty($d['hp'])) {
    echo json_encode(['ok' => true]);
    exit;
  }

  // Validación mínima (espejo del wizard).
  foreach (['empresa', 'contacto', 'email'] as $f) {
    if (empty($d[$f]) || !is_string($d[$f]) || trim($d[$f]) === '') {
      http_response_code(400);
      echo json_encode(['error' => "Campo requerido faltante: $f"]);
      exit;
    }
  }

  // 1) IOI® determinístico (no depende del LLM).
  require_once __DIR__ . '/../src/Scoring/IOIEngine.php';
  $result = IOIEngine::diagnose(infouno_dos_clean($d));

  // 2) Persistir el lead ANTES del LLM (no se pierde aunque el LLM falle).
  require_once __DIR__ . '/../../db_lead.php';
  $session = s($d['session_id'] ?? '', 64);
  if ($session === '') $session = 'metodo2-' . bin2hex(random_bytes(8));

  $resumen = 'IOI® ' . $result['ioi_final'] . '/100 · ' . $result['veredicto']['titulo']
    . ' · Costo inacción anual: $' . number_format((float) $result['costo_inaccion']['anual_ars'], 0, ',', '.') . ' ARS'
    . ' · Fases A/B/C/D: ' . implode('/', [$result['fases']['A'], $result['fases']['B'], $result['fases']['C'], $result['fases']['D']])
    . ' · Puntos críticos: ' . implode(', ', $result['puntos_criticos']);

  @infouno_save_lead($cfg, [
    'session_id' => $session,
    'source'     => 'metodo-dos',
    'name'       => $d['contacto'] ?? '',
    'empresa'    => $d['empresa']  ?? '',
    'rubro'      => $d['rubro']    ?? '',
    'email'      => $d['email']    ?? '',
    'whatsapp'   => $d['telefono'] ?? '',
    'mensaje'    => $resumen,
    'page'       => 'metodo-dos/public/metodo-dos-nivel2.html',
    'utm_source'   => $d['utm_source']   ?? '',
    'utm_medium'   => $d['utm_medium']   ?? '',
    'utm_campaign' => $d['utm_campaign'] ?? '',
  ]);

  // 3) Narrativo con el LLM (redacta sobre números YA calculados; no puntúa).
  $narrativo = infouno_dos_llm($cfg, $result, $d);

  // 4) Responder (el motor manda; el LLM solo enriquece).
  echo json_encode([
    'ioi_final'       => $result['ioi_final'],
    'veredicto'       => $result['veredicto'],
    'costo_inaccion'  => $result['costo_inaccion'],
    'fases'           => $result['fases'],
    'puntos_criticos' => $result['puntos_criticos'],
    'narrativo'       => $narrativo, // puede ser null si el LLM falló; el front usa el veredicto igual
  ]);

} catch (\Throwable $e) {
  error_log('infouno metodo-dos: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['error' => 'Hubo un problema al procesar el diagnóstico. Recibimos tus datos igual.']);
}

/* ===================================================================== */

/**
 * Normaliza el input del motor IOI®: llega del navegador, así que se fuerza la forma
 * esperada y se acotan los rangos (el wizard usa puntos 0–4 y horas 0–80). Evita IOI fuera
 * de 0–100, costo de inacción inflado y excepciones que dejarían el lead sin guardar.
 * ponytail: el puntaje sigue viniendo del cliente; para blindarlo del todo, mandar las
 * respuestas (a2…d3) y calcular los puntos en el server.
 */
function infouno_dos_clean(array $d): array {
  $num = static function ($v, float $lo, float $hi): float {
    return max($lo, min($hi, is_numeric($v) ? (float) $v : 0.0));
  };
  $entry = static function ($r) use ($num): array {
    $r = is_array($r) ? $r : [];
    $max = $num($r['max'] ?? 0, 0, 10);
    return ['points' => $num($r['points'] ?? 0, 0, $max), 'max' => $max];
  };
  $phases = [];
  foreach (['A', 'B', 'C', 'D'] as $k) {
    $rows = is_array($d['phases'][$k] ?? null) ? $d['phases'][$k] : [];
    $phases[$k] = array_map($entry, array_slice(array_values($rows), 0, 10));
  }
  $items = [];
  foreach (array_slice(array_values(is_array($d['critical_items'] ?? null) ? $d['critical_items'] : []), 0, 20) as $i) {
    if (!is_array($i)) continue;
    $e = $entry(['points' => $i['score'] ?? 0, 'max' => $i['max'] ?? 0]);
    $items[] = ['label' => is_string($i['label'] ?? null) ? mb_substr($i['label'], 0, 120, 'UTF-8') : '', 'score' => $e['points'], 'max' => $e['max']];
  }
  return ['phases' => $phases, 'hours_per_week' => $num($d['hours_per_week'] ?? 0, 0, 168), 'critical_items' => $items];
}

/** Pide al LLM el narrativo del veredicto sobre los números ya calculados. */
function infouno_dos_llm(array $cfg, array $result, array $d): ?string {
  if (empty($cfg['openai_key'])) return null;
  $endpoint = !empty($cfg['api_base']) ? $cfg['api_base'] : 'https://api.openai.com/v1/chat/completions';

  $system = "Sos un consultor senior de Infouno, agencia argentina de estrategia digital y automatización para PyMEs.\n"
    . "Ya calculamos el diagnóstico Método DOS® (IOI®). NO recalcules ni cambies los números: redactás el análisis sobre ellos.\n"
    . "Devolvé un texto de máximo 250 palabras, tono directo y profesional, voseo argentino, sin tecnicismos, sin dar precios.\n"
    . "Integrá: qué significa el IOI® y su veredicto, el costo de la inacción, y desarrollá los 3 puntos críticos como próximos pasos accionables.";

  $user = "IOI® final: {$result['ioi_final']}/100\n"
    . "Veredicto: {$result['veredicto']['titulo']}\n"
    . "Costo de inacción anual: {$result['costo_inaccion']['anual_ars']} ARS ({$result['costo_inaccion']['horas_semanales']} h/semana perdidas)\n"
    . "Scores por fase (A Dolor / B Volumen / C Madurez inversa / D Intención): "
    . "{$result['fases']['A']}/{$result['fases']['B']}/{$result['fases']['C']}/{$result['fases']['D']}\n"
    . "3 puntos críticos (mayor brecha): " . implode(', ', $result['puntos_criticos']) . "\n"
    . "Empresa: " . ($d['empresa'] ?? '') . " · Rubro: " . ($d['rubro'] ?? '');

  $payload = json_encode([
    'model'       => $cfg['openai_model'] ?? 'gpt-4o-mini',
    'temperature' => 0.3,
    'max_tokens'  => 4096,
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
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 30,
  ]);
  $raw  = curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($raw === false || $code !== 200) {
    error_log('infouno metodo-dos LLM: code=' . $code);
    return null;
  }
  $data = json_decode($raw, true);
  $text = $data['choices'][0]['message']['content'] ?? '';
  return (is_string($text) && trim($text) !== '') ? trim($text) : null;
}
