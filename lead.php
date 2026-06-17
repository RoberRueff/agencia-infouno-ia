<?php
/* =====================================================================
   Infouno — Receptor de leads (guardado paso a paso)
   ---------------------------------------------------------------------
   site.js hace fetch('/lead.php') en cada paso del bot y del formulario.
   Guarda/actualiza el lead por session_id (no se pierde si abandonan) y
   avisa por email cuando el lead ya tiene un contacto accionable.
   Seguridad: prepared statements (anti SQL injection) + sanitización.
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

/* ---- Sanitización básica ---- */
function s($v, $max = 190) {
  $v = is_string($v) ? trim($v) : '';
  $v = strip_tags($v);
  if (mb_strlen($v) > $max) $v = mb_substr($v, 0, $max);
  return $v;
}
function nn($v) { return ($v === '' || $v === null) ? null : $v; } // '' -> NULL

$session = s($in['session_id'] ?? '', 64);
if ($session === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'session']);
  exit;
}

$source  = s($in['source']  ?? 'bot', 20);
$name    = s($in['name']    ?? '', 120);
$rubro   = s($in['rubro']   ?? ($in['interes'] ?? ''), 150);
$company = s($in['empresa'] ?? '', 150);
$message = s($in['mensaje'] ?? '', 1000);
$webTxt  = mb_strtolower(s($in['web']    ?? '', 60));
$eqTxt   = mb_strtolower(s($in['equipo'] ?? '', 60));
$page    = s($in['page'] ?? '', 190);
$utm_s   = s($in['utm_source']   ?? '', 120);
$utm_m   = s($in['utm_medium']   ?? '', 120);
$utm_c   = s($in['utm_campaign'] ?? '', 150);

/* ---- Check de sintaxis telefónica (Argentina) ---- */
$phone = preg_replace('/\D+/', '', s($in['whatsapp'] ?? ($in['phone'] ?? ''), 30));
$phone = preg_replace('/^0/', '', $phone);   // quitar 0 inicial de larga distancia
$phoneValid = strlen($phone) >= 8;

/* ---- Check de dominio de email (formato + dominios desechables) ---- */
$email = s($in['email'] ?? '', 150);
$disposable = ['mailinator.com','trashmail.com','10minutemail.com','guerrillamail.com','tempmail.com','yopmail.com','trash-mail.com'];
$emailValid = false;
if ($email !== '') {
  $emailValid = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
  $dom = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
  if (in_array($dom, $disposable, true)) $emailValid = false;
}
if ($email !== '' && !$emailValid) $email = ''; // no guardamos email inválido

/* ---- Mapeo a la taxonomía (ai/taxonomy.md) ---- */
$infra = null;
if ($webTxt !== '') $infra = (strpos($webTxt, 'tengo') !== false || strpos($webTxt, 'rehacer') !== false) ? 'has_web' : 'no_web';
$size = null;
if ($eqTxt !== '') {
  if (strpos($eqTxt, 'grande') !== false || strpos($eqTxt, '+5') !== false) $size = 'team_large';
  elseif (strpos($eqTxt, 'chico') !== false || strpos($eqTxt, 'equipo') !== false) $size = 'team_small';
  else $size = 'solo';
}

/* ---- Lead scoring (regla R3, ai/rules.md) ---- */
$score = 0;
if ($infra === 'has_web')      $score += 40;
if ($size === 'team_large')    $score += 50;
elseif ($size === 'team_small') $score += 30;
if ($phoneValid)               $score += 20;
// VIP (R3 estricto): equipo grande (+5 personas) Y con web previa
$vip = ($infra === 'has_web' && $size === 'team_large') ? 1 : 0;

/* ---- Conexión ---- */
$db = @new mysqli($cfg['db_host'], $cfg['db_user'], $cfg['db_pass'], $cfg['db_name']);
if ($db->connect_errno) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'db']);
  exit;
}
$db->set_charset('utf8mb4');

/* ---- ¿Ya existe esta sesión? (para no notificar dos veces) ---- */
$notified = 0;
$q = $db->prepare('SELECT lead_notified FROM wp_infouno_leads WHERE session_id = ? LIMIT 1');
$q->bind_param('s', $session);
$q->execute();
if ($r = $q->get_result()->fetch_assoc()) $notified = (int) $r['lead_notified'];
$q->close();

/* ---- Upsert por session_id (guardado paso a paso, regla R4) ---- */
$vName = nn($name); $vRubro = nn($rubro); $vCompany = nn($company); $vMessage = nn($message);
$vPhone = nn($phone); $vEmail = nn($email); $vSource = nn($source); $vPage = nn($page);
$vUs = nn($utm_s); $vUm = nn($utm_m); $vUc = nn($utm_c);

$sql = 'INSERT INTO wp_infouno_leads
  (session_id, lead_name, lead_rubro, lead_company, lead_message, lead_infrastructure,
   lead_size, lead_phone, lead_email, lead_source, page, utm_source, utm_medium, utm_campaign,
   lead_scoring, lead_vip)
  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
  ON DUPLICATE KEY UPDATE
   lead_name           = COALESCE(VALUES(lead_name), lead_name),
   lead_rubro          = COALESCE(VALUES(lead_rubro), lead_rubro),
   lead_company        = COALESCE(VALUES(lead_company), lead_company),
   lead_message        = COALESCE(VALUES(lead_message), lead_message),
   lead_infrastructure = COALESCE(VALUES(lead_infrastructure), lead_infrastructure),
   lead_size           = COALESCE(VALUES(lead_size), lead_size),
   lead_phone          = COALESCE(VALUES(lead_phone), lead_phone),
   lead_email          = COALESCE(VALUES(lead_email), lead_email),
   lead_source         = COALESCE(VALUES(lead_source), lead_source),
   page                = COALESCE(VALUES(page), page),
   utm_source          = COALESCE(VALUES(utm_source), utm_source),
   utm_medium          = COALESCE(VALUES(utm_medium), utm_medium),
   utm_campaign        = COALESCE(VALUES(utm_campaign), utm_campaign),
   lead_scoring        = GREATEST(lead_scoring, VALUES(lead_scoring)),
   lead_vip            = GREATEST(lead_vip, VALUES(lead_vip))';

$stmt = $db->prepare($sql);
$stmt->bind_param(
  'ssssssssssssssii',
  $session, $vName, $vRubro, $vCompany, $vMessage, $infra, $size, $vPhone, $vEmail,
  $vSource, $vPage, $vUs, $vUm, $vUc, $score, $vip
);
$ok = $stmt->execute();
$stmt->close();

/* ---- Notificación por email (una sola vez por lead) ---- */
$actionable = ($phoneValid || $emailValid || $source === 'form');
if ($ok && $actionable && !$notified) {
  $subject = ($vip ? '[LEAD VIP] ' : '[Lead] ') . ($name !== '' ? $name : 'Nuevo contacto') . ($rubro !== '' ? ' — ' . $rubro : '');
  $body = implode("\n", [
    'Nombre:        ' . $name,
    'Rubro/Interés: ' . $rubro,
    'Empresa:       ' . $company,
    'WhatsApp:      ' . $phone,
    'Email:         ' . $email,
    'Web:           ' . $webTxt,
    'Equipo:        ' . $eqTxt,
    'Scoring:       ' . $score . ($vip ? '  (VIP)' : ''),
    'Origen:        ' . $source . ' · ' . $page,
    'UTM:           ' . trim($utm_s . ' / ' . $utm_m . ' / ' . $utm_c, ' /'),
    'Mensaje:       ' . $message,
  ]);
  $headers = 'From: ' . $cfg['from_email'] . "\r\n" . 'Content-Type: text/plain; charset=utf-8';
  @mail($cfg['notify_email'], $subject, $body, $headers);

  $u = $db->prepare('UPDATE wp_infouno_leads SET lead_notified = 1 WHERE session_id = ?');
  $u->bind_param('s', $session);
  $u->execute();
  $u->close();
}

$db->close();
echo json_encode(['ok' => (bool) $ok, 'vip' => $vip, 'scoring' => $score]);
