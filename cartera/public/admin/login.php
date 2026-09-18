<?php
require_once __DIR__ . '/../../includes/admin_auth.php';

if (admin_logueado()) {
    header('Location: cartera.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validar_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión inválida, recargá la página e intentá de nuevo.';
    } elseif (intentar_login_admin($_POST['email'] ?? '', $_POST['password'] ?? '', $_POST['codigo2fa'] ?? '')) {
        header('Location: cartera.php');
        exit;
    } else {
        $error = 'Email, contraseña o código incorrecto.';
    }
}

$csrf = generar_csrf_token();
?>
<!doctype html>
<html lang="es-AR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin — Panel de Marketing</title>
<link rel="stylesheet" href="../assets/style.css?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?>">
</head>
<body class="login-page">
  <main class="login-box">
    <h1>Panel de administración</h1>
    <?php if ($error): ?>
      <p class="alerta-error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <form method="post" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" required autofocus autocomplete="username">
      <label for="password">Contraseña</label>
      <input type="password" id="password" name="password" required autocomplete="current-password">
      <label for="codigo2fa">Código 2FA (si lo tenés activado)</label>
      <input type="text" id="codigo2fa" name="codigo2fa" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" placeholder="123456">
      <button type="submit">Ingresar</button>
    </form>
  </main>
</body>
</html>
