<?php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
exigir_admin();

$pdo = db();
$clientes = $pdo->query('SELECT id, nombre_empresa, rubro_industrial, estado_cuenta, email_dueno, fecha_alta FROM clientes ORDER BY nombre_empresa')->fetchAll();
$solicitudesPendientes = contar_solicitudes_pendientes();

function estado_badge(string $estado): string
{
    $map = [
        'activo' => 'semaforo-verde',
        'onboarding' => 'semaforo-amarillo',
        'pausado' => 'semaforo-amarillo',
        'cancelado' => 'semaforo-rojo',
    ];
    return $map[$estado] ?? '';
}

$csrf = generar_csrf_token();
$tema = admin_tema_actual();
?>
<!doctype html>
<html lang="es-AR"<?= $tema !== 'auto' ? ' data-theme="' . htmlspecialchars($tema) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin — Clientes</title>
<link rel="stylesheet" href="../assets/style.css?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?>">
</head>
<body>
  <header class="topbar">
    <h1>Clientes</h1>
    <nav>
      <?= render_btn_tema($tema, $csrf) ?>
      &nbsp;|&nbsp;
      <a href="cartera.php" class="logout-link">Cartera</a>
      &nbsp;|&nbsp;
      <a href="solicitudes.php" class="logout-link">Solicitudes<?= $solicitudesPendientes > 0 ? ' (' . $solicitudesPendientes . ')' : '' ?></a>
      &nbsp;|&nbsp;
      <a href="cliente_form.php" class="logout-link">+ Nuevo cliente</a>
      &nbsp;|&nbsp;
      <?= boton_logout() ?>
    </nav>
  </header>

  <main class="dashboard">
    <div class="tabla-scroll">
      <table>
        <thead>
          <tr>
            <th>Empresa</th>
            <th>Rubro</th>
            <th>Estado</th>
            <th>Email</th>
            <th>Alta</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($clientes as $c): ?>
          <tr>
            <td><?= htmlspecialchars($c['nombre_empresa']) ?></td>
            <td><?= htmlspecialchars($c['rubro_industrial']) ?></td>
            <td><span class="badge <?= estado_badge($c['estado_cuenta']) ?>"><?= htmlspecialchars($c['estado_cuenta']) ?></span></td>
            <td><?= htmlspecialchars($c['email_dueno']) ?></td>
            <td><?= htmlspecialchars(date('d/m/Y', strtotime($c['fecha_alta']))) ?></td>
            <td><a href="cliente_form.php?id=<?= (int) $c['id'] ?>">Editar</a></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$clientes): ?>
          <tr><td colspan="6">Todavía no hay clientes cargados.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>
  <script src="../assets/tema-toggle.js?v=<?= @filemtime(__DIR__ . '/../assets/tema-toggle.js') ?>"></script>
</body>
</html>
