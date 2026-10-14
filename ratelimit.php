<?php
/* =====================================================================
   Infouno — Rate limiting basado en archivos (hallazgo H1).
   Protege el endpoint pago (chat.php → LLM) del abuso económico.
   Sin dependencias (sirve en hosting compartido). flock para concurrencia.
   Fail-open: si el temp dir no es escribible, NO rompe el bot (deja pasar).
   El tope global diario + el límite de gasto del proveedor son el salvavidas.
   ===================================================================== */

/**
 * IP real del cliente. Por defecto usa REMOTE_ADDR (no spoofeable a nivel HTTP).
 * SOLO confía en X-Real-IP / X-Forwarded-For si $trustForwarded = true — porque headers
 * que el proxy no setea/sobrescribe son falsificables por el cliente y permitirían evadir
 * el límite por IP. Activar trust_forwarded solo detrás de un proxy de confianza (Cloudflare).
 */
function infouno_client_ip($trustForwarded = false) {
  if ($trustForwarded) {
    if (!empty($_SERVER['HTTP_X_REAL_IP']) && filter_var(trim($_SERVER['HTTP_X_REAL_IP']), FILTER_VALIDATE_IP))
      return trim($_SERVER['HTTP_X_REAL_IP']);
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
      $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
      if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
    }
  }
  $ra = $_SERVER['REMOTE_ADDR'] ?? '';
  return filter_var($ra, FILTER_VALIDATE_IP) ? $ra : '0.0.0.0';
}

function infouno_rate_dir() {
  $dir = sys_get_temp_dir() . '/infouno_rate';
  if (!is_dir($dir)) @mkdir($dir, 0700, true);
  return $dir;
}

/**
 * Chequea y registra un hit. Devuelve ['ok'=>bool, 'reason'=>'ip'|'global'|''].
 * Capas: por IP (req/min y req/hora, ventana deslizante) + tope global diario.
 * Umbrales configurables en config.php (defaults: 15/min, 60/hora, 1500/día).
 */
function infouno_rate_check($cfg, $opts = []) {
  $bucket  = $opts['bucket'] ?? 'chat';   // separa contadores por endpoint (chat / lead)
  $perMin  = (int) ($opts['per_min']        ?? $cfg['rate_per_min']      ?? 15);
  $perHour = (int) ($opts['per_hour']       ?? $cfg['rate_per_hour']     ?? 60);
  $perDayG = (int) ($opts['per_day_global'] ?? $cfg['rate_daily_global'] ?? 1500);
  $now = time();
  $dir = infouno_rate_dir();

  // --- Capa 1: por IP (ventana deslizante de timestamps) ---
  $ip = infouno_client_ip(!empty($cfg['trust_forwarded']));
  $ipFile = $dir . '/' . $bucket . '_ip_' . md5($ip) . '.json';
  $fh = @fopen($ipFile, 'c+');
  if ($fh) {
    @flock($fh, LOCK_EX);
    $ts = json_decode(stream_get_contents($fh) ?: '[]', true);
    if (!is_array($ts)) $ts = [];
    $ts = array_values(array_filter($ts, function ($t) use ($now) { return $t > $now - 3600; }));
    $inMin  = count(array_filter($ts, function ($t) use ($now) { return $t > $now - 60; }));
    $inHour = count($ts);
    if ($inMin >= $perMin || $inHour >= $perHour) {
      @flock($fh, LOCK_UN); @fclose($fh);
      return ['ok' => false, 'reason' => 'ip'];
    }
    $ts[] = $now;
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($ts));
    @flock($fh, LOCK_UN); @fclose($fh);
  }

  // --- Capa 2: tope global diario (salvavidas del presupuesto ante rotación de IP) ---
  $gFile = $dir . '/' . $bucket . '_global_' . gmdate('Y-m-d', $now) . '.txt';
  $gh = @fopen($gFile, 'c+');
  if ($gh) {
    @flock($gh, LOCK_EX);
    $cnt = (int) trim(stream_get_contents($gh));
    if ($cnt >= $perDayG) {
      @flock($gh, LOCK_UN); @fclose($gh);
      return ['ok' => false, 'reason' => 'global'];
    }
    ftruncate($gh, 0); rewind($gh); fwrite($gh, (string) ($cnt + 1));
    @flock($gh, LOCK_UN); @fclose($gh);
  }

  return ['ok' => true, 'reason' => ''];
}

/**
 * Lee el body JSON con tope de bytes (anti-abuso: el body va al LLM, que cobra por token).
 * Si excede el tope responde 413 y corta. Devuelve el array o null si no es JSON válido.
 */
function infouno_read_json($maxBytes = 20000) {
  $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
  if ($raw !== false && strlen($raw) > $maxBytes) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'too_large']);
    exit;
  }
  $d = json_decode((string) $raw, true);
  return is_array($d) ? $d : null;
}

/** Recorta strings (largo), arrays (cantidad de ítems) y profundidad de un input no confiable. */
function infouno_clamp($v, $maxStr = 2000, $maxItems = 40, $depth = 4) {
  if (is_string($v)) return mb_strlen($v) > $maxStr ? mb_substr($v, 0, $maxStr, 'UTF-8') : $v;
  if (is_array($v)) {
    if ($depth <= 0) return [];
    $out = [];
    foreach (array_slice($v, 0, $maxItems, true) as $k => $x) $out[$k] = infouno_clamp($x, $maxStr, $maxItems, $depth - 1);
    return $out;
  }
  return is_scalar($v) ? $v : null;
}
