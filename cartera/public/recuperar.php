<?php
/**
 * "Olvidé mi contraseña" — reset mediado por administrador.
 *
 * No genera ni envía contraseñas (el hosting no tiene email configurado y
 * mostrar/enviar una credencial a quien escriba un email ajeno seria un riesgo
 * de robo de cuenta). Solo registra una solicitud en solicitudes_reset; el
 * admin la ve en public/admin/solicitudes.php y regenera la contraseña con el
 * formulario de cliente que ya existe.
 *
 * Controles: CSRF, honeypot (mismo patrón que landing.php), rate-limit por IP
 * reutilizando la lógica de bloqueo de auth.php (reloj de PHP de punta a punta),
 * y respuesta anti-enumeración (siempre el mismo mensaje, exista o no el email).
 */

require_once __DIR__ . '/../includes/auth.php';

if (cliente_logueado()) {
    header('Location: dashboard.php');
    exit;
}

$error = null;
$enviado = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validar_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'La página quedó desactualizada, recargá y volvé a intentar.';
    } elseif (trim($_POST['sitio_web'] ?? '') !== '') {
        // Honeypot completado -> es un bot. Fingimos éxito sin registrar nada.
        $enviado = true;
    } else {
        $identificador = 'reset:' . ($_SERVER['REMOTE_ADDR'] ?? 'desconocida');

        if (login_bloqueado($identificador)) {
            // Demasiados pedidos desde esta IP. Mensaje neutro igual (no delatamos
            // ni el bloqueo ni la existencia del email).
            $enviado = true;
        } else {
            $email = trim($_POST['email'] ?? '');

            if ($email === '') {
                $error = 'Ingresá tu email para continuar.';
            } else {
                // Cuenta este pedido contra el límite por IP (5 / 15 min).
                registrar_intento_fallido($identificador);

                $pdo = db();
                $stmt = $pdo->prepare("SELECT id FROM clientes WHERE email_dueno = ? AND estado_cuenta = 'activo'");
                $stmt->execute([$email]);
                $cliente = $stmt->fetch();

                // Solo registramos si el email corresponde a un cliente activo.
                // No hay solicitud pendiente duplicada: si ya hay una pendiente
                // para ese cliente, no creamos otra (evita inflar la lista del admin).
                if ($cliente) {
                    $yaPendiente = $pdo->prepare("SELECT id FROM solicitudes_reset WHERE cliente_id = ? AND estado = 'pendiente'");
                    $yaPendiente->execute([$cliente['id']]);
                    if (!$yaPendiente->fetch()) {
                        $ins = $pdo->prepare('INSERT INTO solicitudes_reset (cliente_id, email_solicitado, ip_solicitante) VALUES (?, ?, ?)');
                        $ins->execute([$cliente['id'], $email, $_SERVER['REMOTE_ADDR'] ?? null]);
                    }
                }

                // Respuesta idéntica exista o no el email (anti-enumeración).
                $enviado = true;
            }
        }
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
<title>Recuperar contraseña — Panel de Marketing</title>
<link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body class="login-page">
  <main class="login-box">
    <h1>Recuperar contraseña</h1>
    <?php if ($enviado): ?>
      <p class="alerta-ok">Si el email está registrado, un administrador se va a contactar para restablecer tu acceso.</p>
      <p class="login-ayuda"><a href="login.php">← Volver a ingresar</a></p>
    <?php else: ?>
      <?php if ($error): ?>
        <p class="alerta-error"><?= htmlspecialchars($error) ?></p>
      <?php endif; ?>
      <p class="login-intro">Ingresá el email con el que accedés al panel. Un administrador va a generar una contraseña nueva y contactarte.</p>
      <form method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required autofocus>
        <!-- Honeypot: invisible para humanos, los bots lo completan. -->
        <div class="hp-campo" aria-hidden="true">
          <label for="sitio_web">No completar</label>
          <input type="text" id="sitio_web" name="sitio_web" tabindex="-1" autocomplete="off">
        </div>
        <button type="submit">Enviar solicitud</button>
      </form>
      <p class="login-ayuda"><a href="login.php">← Volver a ingresar</a></p>
    <?php endif; ?>
  </main>
</body>
</html>
