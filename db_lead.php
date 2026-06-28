<?php
/* =====================================================================
   Infouno — Persistencia compartida de leads (usada por lead.php y chat.php)
   Sanitización + mapeo a taxonomía + scoring/VIP (R3) + upsert (R4) + email.
   ===================================================================== */

// PHP 8.1+ pone mysqli en modo "throw" por defecto; este código está escrito para
// chequear errores con returns (connect_errno, prepare()===false). Lo volvemos al
// modo silencioso para que un problema de DB degrade con gracia y no tire un fatal.
mysqli_report(MYSQLI_REPORT_OFF);

if (!function_exists('s')) {
  function s($v, $max = 190) {
    $v = is_string($v) ? trim($v) : '';
    $v = strip_tags($v);
    $v = preg_replace('/[\r\n]+/', ' ', $v);   // anti header-injection (email) + normaliza saltos
    if (mb_strlen($v) > $max) $v = mb_substr($v, 0, $max);
    return $v;
  }
}
if (!function_exists('nn')) {
  function nn($v) { return ($v === '' || $v === null) ? null : $v; }
}

/**
 * Guarda/actualiza un lead por session_id. Devuelve ['ok'=>bool,'vip'=>int,'scoring'=>int].
 */
function infouno_save_lead($cfg, $in) {
  $session = s($in['session_id'] ?? '', 64);
  if ($session === '') return ['ok' => false, 'error' => 'session'];

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

  // Check de sintaxis telefónica (Argentina)
  $phone = preg_replace('/\D+/', '', s($in['whatsapp'] ?? ($in['phone'] ?? ''), 30));
  $phone = preg_replace('/^0/', '', $phone);
  $phoneValid = strlen($phone) >= 8;

  // Check de dominio de email
  $email = s($in['email'] ?? '', 150);
  $disposable = ['mailinator.com','trashmail.com','10minutemail.com','guerrillamail.com','tempmail.com','yopmail.com','trash-mail.com'];
  $emailValid = false;
  if ($email !== '') {
    $emailValid = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    $dom = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
    if (in_array($dom, $disposable, true)) $emailValid = false;
  }
  if ($email !== '' && !$emailValid) $email = '';

  // Mapeo a la taxonomía
  $infra = null;
  if ($webTxt !== '') $infra = (strpos($webTxt, 'tengo') !== false || strpos($webTxt, 'rehacer') !== false) ? 'has_web' : 'no_web';
  $size = null;
  if ($eqTxt !== '') {
    if (strpos($eqTxt, 'grande') !== false || strpos($eqTxt, '+5') !== false) $size = 'team_large';
    elseif (strpos($eqTxt, 'chico') !== false || strpos($eqTxt, 'equipo') !== false) $size = 'team_small';
    else $size = 'solo';
  }

  // Lead scoring (R3)
  $score = 0;
  if ($infra === 'has_web')       $score += 40;
  if ($size === 'team_large')     $score += 50;
  elseif ($size === 'team_small') $score += 30;
  if ($phoneValid)                $score += 20;
  $vip = ($infra === 'has_web' && $size === 'team_large') ? 1 : 0;

  // Conexión
  $db = @new mysqli($cfg['db_host'], $cfg['db_user'], $cfg['db_pass'], $cfg['db_name']);
  if ($db->connect_errno) {
    @error_log('infouno DB connect: (' . $db->connect_errno . ') ' . $db->connect_error);
    @file_put_contents(__DIR__ . '/_dberr_8f3k2.log', gmdate('c') . ' connect (' . $db->connect_errno . ') ' . $db->connect_error . "\n", FILE_APPEND);
    return ['ok' => false, 'error' => 'db'];
  }
  $db->set_charset('utf8mb4');

  // ¿Ya existe? (para no notificar dos veces: email y WhatsApp por separado)
  $notified = 0; $vipNotified = 0;
  $q = $db->prepare('SELECT lead_notified, lead_vip_notified FROM wp_infouno_leads WHERE session_id = ? LIMIT 1');
  if (!$q) { $db->close(); return ['ok' => false, 'error' => 'db']; }
  $q->bind_param('s', $session);
  $q->execute();
  if ($r = $q->get_result()->fetch_assoc()) { $notified = (int) $r['lead_notified']; $vipNotified = (int) $r['lead_vip_notified']; }
  $q->close();

  // Upsert por session_id (R4)
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
  if (!$stmt) { $db->close(); return ['ok' => false, 'error' => 'db']; }
  $stmt->bind_param(
    'ssssssssssssssii',
    $session, $vName, $vRubro, $vCompany, $vMessage, $infra, $size, $vPhone, $vEmail,
    $vSource, $vPage, $vUs, $vUm, $vUc, $score, $vip
  );
  $ok = $stmt->execute();
  if (!$ok) {
    @error_log('infouno DB insert: ' . $stmt->error);
    @file_put_contents(__DIR__ . '/_dberr_8f3k2.log', gmdate('c') . ' insert ' . $stmt->error . "\n", FILE_APPEND);
  }
  $stmt->close();

  // Notificación por email (una sola vez). Solo cuando el lead tiene sustancia:
  // contacto válido + identidad (nombre o rubro), para no avisar en pasos parciales
  // del bot. El form siempre notifica (coordina por WhatsApp, sin tel/email guardado).
  $hasIdentity = ($name !== '' || $rubro !== '');
  $actionable = ($source === 'form') || (($phoneValid || $emailValid) && $hasIdentity);
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

    if ($u = $db->prepare('UPDATE wp_infouno_leads SET lead_notified = 1 WHERE session_id = ?')) {
      $u->bind_param('s', $session);
      $u->execute();
      $u->close();
    }
  }

  // Alerta de lead VIP por WhatsApp (vía Make). Best-effort, NO bloqueante: nunca
  // demora ni rompe el guardado. Solo VIP + accionable, una sola vez por lead.
  if ($ok && $vip && $actionable && !$vipNotified && !empty($cfg['make_webhook_url'])) {
    $waLink = $phoneValid ? ('https://wa.me/' . $phone) : '';
    $payload = json_encode([
      'token'      => $cfg['make_token'] ?? '',
      'session_id' => $session,
      'name'       => $name,
      'rubro'      => $rubro,
      'company'    => $company,
      'phone'      => $phone,
      'email'      => $email,
      'web'        => $infra,
      'equipo'     => $size,
      'scoring'    => $score,
      'vip'        => $vip,
      'wa_link'    => $waLink,
      'page'       => $page,
      'utm'        => ['source' => $utm_s, 'medium' => $utm_m, 'campaign' => $utm_c],
    ]);
    $ch = curl_init($cfg['make_webhook_url']);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST           => true,
      CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
      CURLOPT_POSTFIELDS     => $payload,
      CURLOPT_TIMEOUT        => 3,
    ]);
    $resp = @curl_exec($ch);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($resp === false) @error_log('infouno: webhook Make fallo — ' . $cerr);

    // Marca best-effort (al intentar): evita re-disparar en cada paso del bot.
    if ($uv = $db->prepare('UPDATE wp_infouno_leads SET lead_vip_notified = 1 WHERE session_id = ?')) {
      $uv->bind_param('s', $session);
      $uv->execute();
      $uv->close();
    }
  }

  $db->close();
  return ['ok' => (bool) $ok, 'vip' => $vip, 'scoring' => $score];
}
