<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/totp.php';

/**
 * Sesión del panel admin con IDENTIDAD por usuario (tabla usuarios_admin) y
 * roles. Separada de la sesión de cliente (misma cookie PHP, distintas claves).
 *
 * La credencial de config (app.admin_password_hash / admin_totp_secret) sigue
 * funcionando como ACCESO DE EMERGENCIA (break-glass) y como bootstrap inicial:
 * con ella entrás en modo dueño y creás tu usuario nombrado, así nunca te
 * quedás afuera si la tabla usuarios_admin queda inconsistente.
 *
 * Reloj de PHP de punta a punta para el bloqueo por fuerza bruta (heredado de
 * auth.php), nunca NOW() de MySQL.
 */

const ROLES_ADMIN = ['dueno' => 'Dueño', 'operador' => 'Operador'];

function etiqueta_rol(string $rol): string
{
    return ROLES_ADMIN[$rol] ?? $rol;
}

function admin_logueado(): bool
{
    iniciar_sesion_segura();
    return !empty($_SESSION['admin']);
}

function exigir_admin(): void
{
    if (!admin_logueado()) {
        header('Location: login.php');
        exit;
    }
    // Revalidación por request de los usuarios nombrados: un usuario desactivado
    // o borrado pierde el acceso de inmediato; un cambio de rol se refleja al
    // toque. El break-glass (admin_id null) no tiene fila y no se revalida.
    $id = $_SESSION['admin_id'] ?? null;
    if ($id !== null) {
        $estado = revalidar_admin_sesion(db(), (int) $id);
        if (!$estado['vigente']) {
            cerrar_sesion_admin();
            header('Location: login.php');
            exit;
        }
        $_SESSION['admin_rol'] = $estado['rol'];
    }
}

/** Datos del admin logueado o null. break_glass=true => sesión sin usuario. */
function admin_actual(): ?array
{
    if (!admin_logueado()) {
        return null;
    }
    return [
        'id'          => $_SESSION['admin_id'] ?? null,
        'nombre'      => $_SESSION['admin_nombre'] ?? '',
        'rol'         => $_SESSION['admin_rol'] ?? 'operador',
        'break_glass' => !empty($_SESSION['admin_break_glass']),
    ];
}

function admin_rol(): ?string
{
    return admin_logueado() ? ($_SESSION['admin_rol'] ?? null) : null;
}

/** Gatea por rol: exige admin y el rol pedido; si no, vuelve a la cartera. */
function exigir_admin_rol(string $rol): void
{
    exigir_admin();
    if (admin_rol() !== $rol) {
        $_SESSION['admin_aviso'] = 'No tenés permiso para acceder a esa sección.';
        header('Location: cartera.php');
        exit;
    }
}

/** El 2FA global del config aplica solo al camino break-glass. */
function admin_2fa_habilitado(): bool
{
    return trim(app_config()['app']['admin_totp_secret'] ?? '') !== '';
}

/** Cantidad de dueños activos (para las guardas de usuarios.php). */
function contar_duenos_activos(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM usuarios_admin WHERE rol = 'dueno' AND activo = 1")->fetchColumn();
}

/**
 * Revalida la sesión de un usuario nombrado contra la DB (se llama por request
 * desde exigir_admin). Un usuario borrado o desactivado deja de estar vigente
 * (corte de acceso inmediato, sin esperar a que expire la sesión); el rol
 * devuelto es el ACTUAL de la DB (un cambio de rol se refleja al toque).
 *
 * @return array{vigente: bool, rol: ?string}
 */
function revalidar_admin_sesion(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT rol, activo FROM usuarios_admin WHERE id = ?');
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    if (!$u || (int) $u['activo'] !== 1) {
        return ['vigente' => false, 'rol' => null];
    }
    return ['vigente' => true, 'rol' => $u['rol']];
}

/**
 * Lógica pura del break-glass (aislada para testearla sin depender del secreto
 * real de config): la password verifica contra el hash de emergencia y, si hay
 * 2FA de emergencia, el código pasa. $totpSecret vacío => sin 2FA de emergencia.
 */
function _admin_break_glass_ok(string $password, string $codigo2fa, string $hash, string $totpSecret): bool
{
    if ($hash === '' || !password_verify($password, $hash)) {
        return false;
    }
    return $totpSecret === '' || totp_verificar($totpSecret, $codigo2fa);
}

/** Setea la sesión admin (usuario nombrado con $id int, o break-glass con null). */
function _admin_iniciar_sesion(?int $id, string $nombre, string $rol, bool $breakGlass): void
{
    iniciar_sesion_segura();
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['admin_id'] = $id;
    $_SESSION['admin_rol'] = $rol;
    $_SESSION['admin_nombre'] = $nombre;
    $_SESSION['admin_break_glass'] = $breakGlass;
}

/**
 * Login del panel admin. Primero el camino de usuario nombrado (usuarios_admin
 * por email); si no autentica, cae al break-glass del config. Cada intento corre
 * exactamente un password_verify del camino usuario (real o dummy) para que el
 * tiempo no delate si el email existe. Bloqueo por email.
 */
function intentar_login_admin(string $email, string $password, string $codigo2fa = ''): bool
{
    $emailNorm = mb_strtolower(trim($email));
    $identificador = 'admin:' . $emailNorm;
    if (login_bloqueado($identificador)) {
        return false;
    }

    // --- Camino usuario nombrado ---
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, nombre, email, password_hash, rol, activo, totp_secret FROM usuarios_admin WHERE email = ?');
    $stmt->execute([$emailNorm]);
    $u = $stmt->fetch();
    $usuarioActivo = $u && (int) $u['activo'] === 1;
    // password_verify siempre (real o dummy) para timing constante (anti-enumeración).
    $passUsuarioOk = password_verify($password, $usuarioActivo ? $u['password_hash'] : HASH_DUMMY_TIMING);
    if ($usuarioActivo && $passUsuarioOk && (empty($u['totp_secret']) || totp_verificar($u['totp_secret'], $codigo2fa))) {
        limpiar_intentos($identificador);
        _admin_iniciar_sesion((int) $u['id'], $u['nombre'], $u['rol'], false);
        return true;
    }

    // Guard de fuerza bruta del break-glass SCOPEADO POR IP (no global): un
    // atacante solo puede bloquear su propia IP del acceso de emergencia, no el
    // de todos. El camino usuario sigue con su bloqueo por email. Mismo criterio
    // que tenía el login admin anterior (evita que alguien ajeno bloquee el
    // acceso real fallando adrede).
    $bgId = 'admin:breakglass:' . ($_SERVER['REMOTE_ADDR'] ?? 'desconocida');

    // --- Camino break-glass (config), con su propio guard de fuerza bruta ---
    if (!login_bloqueado($bgId)) {
        $cfg = app_config()['app'];
        if (_admin_break_glass_ok($password, $codigo2fa, $cfg['admin_password_hash'] ?? '', trim($cfg['admin_totp_secret'] ?? ''))) {
            limpiar_intentos($identificador);
            limpiar_intentos($bgId);
            _admin_iniciar_sesion(null, '(break-glass)', 'dueno', true);
            return true;
        }
        registrar_intento_fallido($bgId);
    }

    registrar_intento_fallido($identificador);
    return false;
}

/**
 * Cierra la sesión admin (llamada por logout.php y por mi_cuenta.php cuando el
 * usuario logueado ya no existe). Limpia las 5 claves de sesión admin — antes
 * de esta reescritura solo existía $_SESSION['admin']; ahora hay identidad
 * (admin_id/admin_rol/admin_nombre) y el flag de break-glass que también deben
 * quedar limpios para que admin_actual()/admin_rol() no arrastren datos stale.
 */
function cerrar_sesion_admin(): void
{
    iniciar_sesion_segura();
    unset(
        $_SESSION['admin'],
        $_SESSION['admin_id'],
        $_SESSION['admin_rol'],
        $_SESSION['admin_nombre'],
        $_SESSION['admin_break_glass']
    );
}

/**
 * Tema efectivo del admin logueado. Break-glass (sin fila en
 * usuarios_admin) o sin sesión: 'auto' siempre, no hay dónde
 * persistirlo — el toggle funciona visualmente vía JS pero no guarda.
 */
function admin_tema_actual(): string
{
    $admin = admin_actual();
    if ($admin === null || $admin['id'] === null) {
        return 'auto';
    }
    $stmt = db()->prepare('SELECT tema FROM usuarios_admin WHERE id = ?');
    $stmt->execute([$admin['id']]);
    return (string) ($stmt->fetchColumn() ?: 'auto');
}
