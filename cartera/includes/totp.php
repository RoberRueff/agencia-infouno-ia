<?php
/**
 * TOTP (RFC 6238) en PHP puro, sin dependencias externas — para el 2FA del
 * panel de administración. Compatible con Google Authenticator / Authy /
 * 1Password / Microsoft Authenticator (HMAC-SHA1, 6 dígitos, período de 30s).
 *
 * Se usa desde includes/admin_auth.php. El secreto (en base32) se guarda en
 * config/config.php bajo app.admin_totp_secret; si está vacío, el 2FA queda
 * desactivado.
 */

/**
 * Decodifica una cadena base32 (RFC 4648, alfabeto A-Z2-7) a binario.
 * Ignora espacios y caracteres fuera del alfabeto (tolerante al copy/paste).
 */
function totp_base32_decode(string $b32): string
{
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(rtrim(trim($b32), '='));
    $bits = '';
    foreach (str_split($b32) as $c) {
        $pos = strpos($alfabeto, $c);
        if ($pos !== false) {
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $bytes .= chr(bindec($chunk));
        }
    }
    return $bytes;
}

/** Codifica binario a base32 (para generar un secreto nuevo legible). */
function totp_base32_encode(string $data): string
{
    $alfabeto = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($data) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $b32 = '';
    foreach (str_split($bits, 5) as $chunk) {
        $b32 .= $alfabeto[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $b32;
}

/** Genera un secreto aleatorio en base32 (20 bytes = 160 bits, recomendado). */
function totp_generar_secreto(int $bytes = 20): string
{
    return totp_base32_encode(random_bytes($bytes));
}

/** Calcula el código TOTP para un secreto y un instante dados. */
function totp_codigo(string $secretoBase32, ?int $timestamp = null, int $paso = 30, int $digitos = 6): string
{
    $timestamp = $timestamp ?? time();
    $contador = intdiv($timestamp, $paso);
    // Contador de 8 bytes big-endian (los 32 bits altos son 0 hasta el año ~2106).
    $binContador = pack('N', 0) . pack('N', $contador);
    $hash = hash_hmac('sha1', $binContador, totp_base32_decode($secretoBase32), true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $trunc = (
        ((ord($hash[$offset]) & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8) |
        (ord($hash[$offset + 3]) & 0xFF)
    );
    return str_pad((string) ($trunc % (10 ** $digitos)), $digitos, '0', STR_PAD_LEFT);
}

/**
 * Verifica un código ingresado contra el secreto, tolerando ±$ventana pasos de
 * 30s para absorber diferencias de reloj entre el server y el teléfono.
 * Comparación en tiempo constante (hash_equals).
 */
function totp_verificar(string $secretoBase32, string $codigoIngresado, int $ventana = 1): bool
{
    $codigoIngresado = preg_replace('/\D/', '', $codigoIngresado);
    if ($codigoIngresado === '' || trim($secretoBase32) === '') {
        return false;
    }
    $ahora = time();
    for ($i = -$ventana; $i <= $ventana; $i++) {
        if (hash_equals(totp_codigo($secretoBase32, $ahora + ($i * 30)), $codigoIngresado)) {
            return true;
        }
    }
    return false;
}

/** Arma el URI otpauth:// para cargar el secreto en la app autenticadora. */
function totp_otpauth_uri(string $secretoBase32, string $cuenta, string $emisor): string
{
    return sprintf(
        'otpauth://totp/%s:%s?secret=%s&issuer=%s&digits=6&period=30&algorithm=SHA1',
        rawurlencode($emisor),
        rawurlencode($cuenta),
        $secretoBase32,
        rawurlencode($emisor)
    );
}
