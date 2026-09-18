<?php
require_once __DIR__ . '/../../includes/admin_auth.php';
exigir_admin();
require_once __DIR__ . '/../../includes/auditoria.php';
require_once __DIR__ . '/../../includes/helpers.php';

$yo = admin_actual();
$pdo = db();
$aviso = null;
$csrf = generar_csrf_token();
$tema = admin_tema_actual();

// El break-glass no tiene usuario en la tabla: no puede gestionar su cuenta.
if ($yo['break_glass'] || $yo['id'] === null) {
    ?>
    <!doctype html>
    <html lang="es-AR"<?= $tema !== 'auto' ? ' data-theme="' . htmlspecialchars($tema) . '"' : '' ?>><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin — Mi cuenta</title><link rel="stylesheet" href="../assets/style.css?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?>"></head>
    <body><header class="topbar"><h1>Mi cuenta</h1>
    <nav><?= render_btn_tema($tema, $csrf) ?> &nbsp;|&nbsp; <a href="cartera.php" class="logout-link">← Cartera</a></nav></header>
    <main class="dashboard">
      <p class="alerta-error">Estás en modo emergencia (break-glass). Creá o entrá con tu usuario nombrado desde <a href="usuarios.php">Usuarios</a> para gestionar tu cuenta.</p>
    </main>
    <script src="../assets/tema-toggle.js?v=<?= @filemtime(__DIR__ . '/../assets/tema-toggle.js') ?>"></script>
    </body></html>
    <?php
    exit;
}

$uid = (int) $yo['id'];
$stmt = $pdo->prepare('SELECT nombre, email, password_hash, totp_secret FROM usuarios_admin WHERE id = ?');
$stmt->execute([$uid]);
$usuario = $stmt->fetch();
if (!$usuario) { // el usuario fue borrado/desactivado en otra sesión
    cerrar_sesion_admin();
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validar_csrf_token($_POST['csrf_token'] ?? null)) {
        $aviso = ['tipo' => 'error', 'texto' => 'Sesión inválida, recargá la página.'];
    } else {
        $accion = $_POST['accion'] ?? '';
        if ($accion === 'cambiar_password') {
            $actual = $_POST['password_actual'] ?? '';
            $nueva = $_POST['password_nueva'] ?? '';
            if (!password_verify($actual, $usuario['password_hash'])) {
                $aviso = ['tipo' => 'error', 'texto' => 'La contraseña actual no es correcta.'];
            } elseif (strlen($nueva) < 8) {
                $aviso = ['tipo' => 'error', 'texto' => 'La nueva contraseña debe tener al menos 8 caracteres.'];
            } else {
                $pdo->prepare('UPDATE usuarios_admin SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($nueva, PASSWORD_DEFAULT), $uid]);
                registrar_auditoria($pdo, 'cuenta.cambiar_password', 'cuenta', $uid, 'cambió su contraseña');
                $aviso = ['tipo' => 'ok', 'texto' => 'Contraseña actualizada.'];
            }
        } elseif ($accion === 'activar_2fa') {
            // El secreto pendiente se guardó en sesión al mostrarlo; se persiste
            // recién cuando el usuario confirma con un código válido.
            $secreto = $_SESSION['mi_cuenta_2fa_pendiente'] ?? '';
            $codigo = $_POST['codigo2fa'] ?? '';
            if ($secreto === '') {
                $aviso = ['tipo' => 'error', 'texto' => 'Generá primero el código y volvé a intentar.'];
            } elseif (!totp_verificar($secreto, $codigo)) {
                $aviso = ['tipo' => 'error', 'texto' => 'El código no es válido, probá de nuevo.'];
            } else {
                $pdo->prepare('UPDATE usuarios_admin SET totp_secret = ? WHERE id = ?')->execute([$secreto, $uid]);
                unset($_SESSION['mi_cuenta_2fa_pendiente']);
                registrar_auditoria($pdo, 'cuenta.activar_2fa', 'cuenta', $uid, 'activó 2FA');
                $aviso = ['tipo' => 'ok', 'texto' => '2FA activado.'];
            }
        } elseif ($accion === 'desactivar_2fa') {
            $codigo = $_POST['codigo2fa'] ?? '';
            if (empty($usuario['totp_secret']) || !totp_verificar($usuario['totp_secret'], $codigo)) {
                $aviso = ['tipo' => 'error', 'texto' => 'Ingresá un código válido para desactivar el 2FA.'];
            } else {
                $pdo->prepare('UPDATE usuarios_admin SET totp_secret = NULL WHERE id = ?')->execute([$uid]);
                registrar_auditoria($pdo, 'cuenta.desactivar_2fa', 'cuenta', $uid, 'desactivó 2FA');
                $aviso = ['tipo' => 'ok', 'texto' => '2FA desactivado.'];
            }
        }
    }
    $_SESSION['mi_cuenta_aviso'] = $aviso;
    header('Location: mi_cuenta.php');
    exit;
}

$aviso = $_SESSION['mi_cuenta_aviso'] ?? null;
unset($_SESSION['mi_cuenta_aviso']);

// Re-leer por si cambió el 2FA en el POST anterior.
$stmt->execute([$uid]);
$usuario = $stmt->fetch();
$tiene2fa = !empty($usuario['totp_secret']);

// Si va a activar 2FA y no lo tiene, generar (o reusar) un secreto pendiente.
$secretoPendiente = null;
$otpauthUri = null;
if (!$tiene2fa) {
    if (empty($_SESSION['mi_cuenta_2fa_pendiente'])) {
        $_SESSION['mi_cuenta_2fa_pendiente'] = totp_generar_secreto();
    }
    $secretoPendiente = $_SESSION['mi_cuenta_2fa_pendiente'];
    $otpauthUri = totp_otpauth_uri($secretoPendiente, $usuario['email'], 'Panel Marketing');
}
?>
<!doctype html>
<html lang="es-AR"<?= $tema !== 'auto' ? ' data-theme="' . htmlspecialchars($tema) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin — Mi cuenta</title>
<link rel="stylesheet" href="../assets/style.css?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?>">
</head>
<body>
  <header class="topbar">
    <h1>Mi cuenta</h1>
    <nav>
      <?= render_btn_tema($tema, $csrf) ?>
      &nbsp;|&nbsp;
      <a href="cartera.php" class="logout-link">← Cartera</a>
      &nbsp;|&nbsp;
      <?= boton_logout() ?>
    </nav>
  </header>

  <main class="dashboard">
    <p class="tarjeta-sub"><?= htmlspecialchars($usuario['nombre']) ?> — <?= htmlspecialchars($usuario['email']) ?></p>
    <?php if ($aviso): ?>
    <p class="<?= $aviso['tipo'] === 'ok' ? 'alerta-ok' : 'alerta-error' ?>"><?= htmlspecialchars($aviso['texto']) ?></p>
    <?php endif; ?>

    <section class="reporte-seccion">
      <h3>Cambiar contraseña</h3>
      <form method="post" class="form-card">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="accion" value="cambiar_password">
        <label for="pa">Contraseña actual</label>
        <input type="password" id="pa" name="password_actual" required>
        <label for="pn">Contraseña nueva (mín. 8)</label>
        <input type="password" id="pn" name="password_nueva" required minlength="8">
        <button type="submit">Cambiar contraseña</button>
      </form>
    </section>

    <section class="reporte-seccion">
      <h3>Verificación en dos pasos (2FA)</h3>
      <?php if ($tiene2fa): ?>
      <p class="tarjeta-sub">El 2FA está <strong>activado</strong> en tu cuenta.</p>
      <form method="post" class="form-card">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="accion" value="desactivar_2fa">
        <label for="d2">Código actual para desactivar</label>
        <input type="text" id="d2" name="codigo2fa" inputmode="numeric" pattern="[0-9 ]*" required>
        <button type="submit">Desactivar 2FA</button>
      </form>
      <?php else: ?>
      <p class="tarjeta-sub">Cargá este secreto en tu app de autenticación (Google Authenticator, Authy, etc.) y confirmá con un código.</p>
      <p><strong>Secreto:</strong> <code><?= htmlspecialchars($secretoPendiente) ?></code></p>
      <p class="tarjeta-sub">URI: <code><?= htmlspecialchars($otpauthUri) ?></code></p>
      <form method="post" class="form-card">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="accion" value="activar_2fa">
        <label for="a2">Código de la app</label>
        <input type="text" id="a2" name="codigo2fa" inputmode="numeric" pattern="[0-9 ]*" required>
        <button type="submit">Activar 2FA</button>
      </form>
      <?php endif; ?>
    </section>
  </main>
  <script src="../assets/tema-toggle.js?v=<?= @filemtime(__DIR__ . '/../assets/tema-toggle.js') ?>"></script>
</body>
</html>
