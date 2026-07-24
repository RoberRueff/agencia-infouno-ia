<?php
/**
 * Panel de admin — solicitudes de "olvidé mi contraseña".
 * Lista los pedidos pendientes. Desde acá el admin va a editar el cliente y
 * regenerar su contraseña (cliente_form.php), y marca la solicitud como resuelta.
 */
require_once __DIR__ . '/../../includes/admin_auth.php';
exigir_admin();
require_once __DIR__ . '/../../includes/helpers.php';

$pdo = db();
$aviso = null;

// Marcar una solicitud como resuelta (POST con CSRF).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validar_csrf_token($_POST['csrf_token'] ?? null)) {
        $aviso = ['tipo' => 'error', 'texto' => 'Sesión inválida, recargá la página e intentá de nuevo.'];
    } else {
        $solicitudId = (int) ($_POST['solicitud_id'] ?? 0);
        // resuelta_at con el reloj de PHP (no NOW() de MySQL), coherente con el
        // resto del proyecto respecto de husos horarios.
        $upd = $pdo->prepare("UPDATE solicitudes_reset SET estado = 'resuelta', resuelta_at = ? WHERE id = ? AND estado = 'pendiente'");
        $upd->execute([date('Y-m-d H:i:s'), $solicitudId]);
        $aviso = ['tipo' => 'ok', 'texto' => 'Solicitud marcada como resuelta.'];
    }
}

$solicitudes = $pdo->query("
    SELECT s.id, s.email_solicitado, s.ip_solicitante, s.created_at,
           s.cliente_id, c.nombre_empresa, c.email_dueno
    FROM solicitudes_reset s
    INNER JOIN clientes c ON c.id = s.cliente_id
    WHERE s.estado = 'pendiente'
    ORDER BY s.created_at ASC
")->fetchAll();

$csrf = generar_csrf_token();
$tema = admin_tema_actual();
?>
<!doctype html>
<html lang="es-AR"<?= $tema !== 'auto' ? ' data-theme="' . htmlspecialchars($tema) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin — Solicitudes de restablecimiento</title>
<link rel="stylesheet" href="../assets/style.css?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?>">
</head>
<body>
  <header class="topbar">
    <h1>Solicitudes de restablecimiento</h1>
    <nav>
      <?= render_btn_tema($tema, $csrf) ?>
      &nbsp;|&nbsp;
      <a href="cartera.php" class="logout-link">Cartera</a>
      &nbsp;|&nbsp;
      <a href="clientes.php" class="logout-link">Clientes</a>
      &nbsp;|&nbsp;
      <?= boton_logout() ?>
    </nav>
  </header>

  <main class="dashboard">
    <?php if ($aviso): ?>
      <p class="<?= $aviso['tipo'] === 'ok' ? 'alerta-ok' : 'alerta-error' ?>"><?= htmlspecialchars($aviso['texto']) ?></p>
    <?php endif; ?>

    <p class="tarjeta-sub" style="margin-bottom:1rem;">
      Pedidos pendientes de clientes que olvidaron su contraseña. Para resolver uno:
      entrá a <strong>Cambiar contraseña</strong>, generá una nueva, contactá al cliente y volvé acá a
      <strong>Marcar resuelta</strong>.
    </p>

    <div class="tabla-scroll">
      <table>
        <thead>
          <tr>
            <th>Empresa</th>
            <th>Email del pedido</th>
            <th>Fecha</th>
            <th>IP</th>
            <th></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($solicitudes as $s): ?>
          <tr>
            <td><?= htmlspecialchars($s['nombre_empresa']) ?></td>
            <td>
              <?= htmlspecialchars($s['email_solicitado']) ?>
              <?php if (strcasecmp($s['email_solicitado'], $s['email_dueno']) !== 0): ?>
                <br><span class="tarjeta-sub">(email del cliente: <?= htmlspecialchars($s['email_dueno']) ?>)</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($s['created_at']))) ?></td>
            <td><?= htmlspecialchars($s['ip_solicitante'] ?? '-') ?></td>
            <td><a href="cliente_form.php?id=<?= (int) $s['cliente_id'] ?>">Cambiar contraseña</a></td>
            <td>
              <form method="post" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="solicitud_id" value="<?= (int) $s['id'] ?>">
                <button type="submit" class="btn-inline">Marcar resuelta</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$solicitudes): ?>
          <tr><td colspan="6">No hay solicitudes pendientes.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>
  <script src="../assets/tema-toggle.js?v=<?= @filemtime(__DIR__ . '/../assets/tema-toggle.js') ?>"></script>
</body>
</html>
