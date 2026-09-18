<?php
require_once __DIR__ . '/../includes/auth.php';

if (cliente_logueado()) {
    header('Location: dashboard.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validar_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión inválida, recargá la página e intentá de nuevo.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        if (intentar_login($email, $password)) {
            header('Location: dashboard.php');
            exit;
        }
        $error = 'Email o contraseña incorrectos.';
    }
}

$csrf = generar_csrf_token();
?>
<!doctype html>
<html lang="es-AR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Ingresar — Panel de Marketing</title>
<link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body class="login-page">
  <main class="login-box">
    <h1>Panel de Marketing</h1>
    <?php if ($error): ?>
      <p class="alerta-error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <form method="post" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" required autofocus>
      <label for="password">Contraseña</label>
      <input type="password" id="password" name="password" required>
      <button type="submit">Ingresar</button>
    </form>
    <p class="login-ayuda"><a href="recuperar.php">¿Olvidaste tu contraseña?</a></p>
  </main>
</body>
</html>
