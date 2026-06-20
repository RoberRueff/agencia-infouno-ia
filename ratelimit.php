<?php
/* =====================================================================
   Infouno — Rate limiting basado en archivos (hallazgo H1).
   Protege el endpoint pago (chat.php → LLM) del abuso económico.
   Sin dependencias (sirve en hosting compartido). flock para concurrencia.
   Fail-open: si el temp dir no es escribible, NO rompe el bot (deja pasar).
   El tope global diario + el límite de gasto del proveedor son el salvavidas.
   ===================================================================== */

/** IP real del cliente (detrás del proxy openresty de DonWeb). */
function infouno_client_ip() {
  $cands = [];
  if (!empty($_SERVER['HTTP_X_REAL_IP'])) $cands[] = $_SERVER['HTTP_X_REAL_IP'];
  if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    $cands[] = $parts[0];
  }
  if (!empty($_SERVER['REMOTE_ADDR'])) $cands[] = $_SERVER['REMOTE_ADDR'];
  foreach ($cands as $ip) {
    $ip = trim($ip);
    if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
  }
  return '0.0.0.0';
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
function infouno_rate_check($cfg) {
  $perMin  = (int) ($cfg['rate_per_min']      ?? 15);
  $perHour = (int) ($cfg['rate_per_hour']     ?? 60);
  $perDayG = (int) ($cfg['rate_daily_global'] ?? 1500);
  $now = time();
  $dir = infouno_rate_dir();

  // --- Capa 1: por IP (ventana deslizante de timestamps) ---
  $ip = infouno_client_ip();
  $ipFile = $dir . '/ip_' . md5($ip) . '.json';
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
  $gFile = $dir . '/global_' . gmdate('Y-m-d', $now) . '.txt';
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
