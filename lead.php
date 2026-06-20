<?php
/* =====================================================================
   Infouno — Receptor de leads (formulario + bot scripteado)
   Delega la persistencia en db_lead.php (compartido con chat.php).
   ===================================================================== */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'method']);
  exit;
}

$cfg = require __DIR__ . '/config.php';

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'json']);
  exit;
}

// Honeypot (M2): si el campo trampa viene completo, es un bot. Devolvemos OK falso
// (no revelamos la trampa) y NO guardamos ni notificamos.
if (!empty($in['hp'])) { echo json_encode(['ok' => true]); exit; }

// Rate limiting (M2): frena flooding directo / email-bombing. Umbrales generosos:
// el bot scripteado postea ~6 pasos por lead, así que damos margen.
require_once __DIR__ . '/ratelimit.php';
$rl = infouno_rate_check($cfg, ['bucket' => 'lead', 'per_min' => 30, 'per_hour' => 200, 'per_day_global' => 5000]);
if (!$rl['ok']) { http_response_code(429); echo json_encode(['ok' => false, 'error' => 'rate']); exit; }

require_once __DIR__ . '/db_lead.php';
$r = infouno_save_lead($cfg, $in);

if (!($r['ok'] ?? false)) {
  $err = $r['error'] ?? 'save';
  http_response_code($err === 'session' ? 400 : 500);
}
echo json_encode($r);
