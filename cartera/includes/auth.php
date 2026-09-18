<?php
require_once __DIR__ . '/db.php';

/**
 * ¿La conexión es HTTPS? Contempla el caso de un proxy/balanceador que termina
 * TLS y reenvía el request por HTTP interno informando X-Forwarded-Proto (común
 * en hosting compartido). Sin esto, detrás de ese proxy $_SERVER['HTTPS'] queda
 * vacío y la cookie de sesión saldría sin el flag `secure`.
 */
function conexion_es_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        return true;
    }
    return ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function iniciar_sesion_segura(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $cfg = app_config()['app'];
    session_name($cfg['session_name'] ?? 'mkt_saas_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => conexion_es_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function cliente_logueado(): ?array
{
    iniciar_sesion_segura();
    return $_SESSION['cliente'] ?? null;
}

function exigir_login(): array
{
    $cliente = cliente_logueado();
    if (!$cliente) {
        header('Location: login.php');
        exit;
    }
    return $cliente;
}

/**
 * Bloqueo temporal contra fuerza bruta (tabla intentos_login), compartido por
 * el login de clientes y el de admin. Cada uno arma su propio $identificador:
 * ver intentar_login() e intentar_login_admin().
 *
 * Todo el cálculo de tiempo se hace acá con time()/date() de PHP — nunca con
 * NOW() de MySQL. Si MySQL corre en otro huso horario que PHP (algo común:
 * el motor de base de datos en UTC y date_default_timezone_set() en
 * America/Argentina/Buenos_Aires), mezclar ambos relojes desalinea el
 * bloqueo en horas, no en minutos. Usar un solo reloj (el de PHP) de punta a
 * punta evita el problema de raíz.
 */
const LOGIN_MAX_INTENTOS = 5;
const LOGIN_VENTANA_MINUTOS = 15;

// Hash bcrypt fijo y válido usado solo para gastar el mismo tiempo de CPU que
// un password_verify real cuando el email no existe (anti-enumeración por
// timing). No corresponde a ninguna contraseña utilizable.
const HASH_DUMMY_TIMING = '$2y$10$.nz6NUTYKoS0XU6NZRiEDu/RrldHjyVHP1ugrFV3AINSrZTMQ3IWe';

function login_bloqueado(string $identificador): bool
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT bloqueado_hasta FROM intentos_login WHERE identificador = ?');
    $stmt->execute([$identificador]);
    $row = $stmt->fetch();
    return $row && $row['bloqueado_hasta'] !== null && strtotime($row['bloqueado_hasta']) > time();
}

function registrar_intento_fallido(string $identificador): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT intentos, ultimo_intento FROM intentos_login WHERE identificador = ?');
    $stmt->execute([$identificador]);
    $row = $stmt->fetch();

    $ahora = time();
    $ventanaVigente = $row && strtotime($row['ultimo_intento']) > $ahora - (LOGIN_VENTANA_MINUTOS * 60);
    $intentos = $ventanaVigente ? ((int) $row['intentos'] + 1) : 1;
    $bloqueadoHasta = $intentos >= LOGIN_MAX_INTENTOS ? date('Y-m-d H:i:s', $ahora + LOGIN_VENTANA_MINUTOS * 60) : null;

    $upd = $pdo->prepare('
        INSERT INTO intentos_login (identificador, intentos, ultimo_intento, bloqueado_hasta)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE intentos = VALUES(intentos), ultimo_intento = VALUES(ultimo_intento), bloqueado_hasta = VALUES(bloqueado_hasta)
    ');
    $upd->execute([$identificador, $intentos, date('Y-m-d H:i:s', $ahora), $bloqueadoHasta]);
}

function limpiar_intentos(string $identificador): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM intentos_login WHERE identificador = ?')->execute([$identificador]);
}

function intentar_login(string $email, string $password): bool
{
    $identificador = 'cliente:' . mb_strtolower($email);
    if (login_bloqueado($identificador)) {
        return false;
    }

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, nombre_empresa, email_dueno, password_hash, estado_cuenta FROM clientes WHERE email_dueno = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row) {
        // Email inexistente: corremos igual un password_verify contra un hash
        // dummy para que el tiempo de respuesta sea indistinguible del de un
        // email que sí existe (evita enumeración por timing).
        password_verify($password, HASH_DUMMY_TIMING);
        registrar_intento_fallido($identificador);
        return false;
    }

    if (!password_verify($password, $row['password_hash']) || $row['estado_cuenta'] === 'cancelado') {
        registrar_intento_fallido($identificador);
        return false;
    }

    limpiar_intentos($identificador);
    iniciar_sesion_segura();
    session_regenerate_id(true);
    $_SESSION['cliente'] = [
        'id' => (int) $row['id'],
        'nombre_empresa' => $row['nombre_empresa'],
        'email' => $row['email_dueno'],
    ];
    return true;
}

function cerrar_sesion(): void
{
    iniciar_sesion_segura();
    $_SESSION = [];
    session_destroy();
}

function generar_csrf_token(): string
{
    iniciar_sesion_segura();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validar_csrf_token(?string $token): bool
{
    iniciar_sesion_segura();
    return $token !== null && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Botón de logout como formulario POST con token CSRF. Evita que un tercero fuerce
 * el cierre de sesión con un GET (p. ej. <img src="logout.php">). Se usa en el header
 * del dashboard del cliente y del panel admin (ambos cargan auth.php). $accion por
 * defecto es relativo, así que sirve igual desde public/ y public/admin/.
 */
function boton_logout(string $accion = 'logout.php'): string
{
    $csrf = htmlspecialchars(generar_csrf_token(), ENT_QUOTES);
    $accion = htmlspecialchars($accion, ENT_QUOTES);
    return '<form method="post" action="' . $accion . '" class="logout-form">'
        . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
        . '<button type="submit" class="logout-link">Salir</button>'
        . '</form>';
}
