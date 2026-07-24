<?php
require_once __DIR__ . '/../../includes/admin_auth.php';
exigir_admin_rol('dueno');
require_once __DIR__ . '/../../includes/helpers.php';

$pdo = db();

$ENTIDADES = ['cliente', 'objetivo', 'usuario_admin', 'cuenta'];
$filtro = $_GET['entidad'] ?? '';
$filtroValido = in_array($filtro, $ENTIDADES, true);

$campos = 'usuario_admin_id, actor_nombre, actor_rol, es_break_glass, accion, entidad, entidad_id, detalle, ip, creado_en';
if ($filtroValido) {
    $stmt = $pdo->prepare("SELECT $campos FROM auditoria WHERE entidad = ? ORDER BY creado_en DESC, id DESC LIMIT 200");
    $stmt->execute([$filtro]);
} else {
    $stmt = $pdo->query("SELECT $campos FROM auditoria ORDER BY creado_en DESC, id DESC LIMIT 200");
}
$filas = $stmt->fetchAll();
$csrf = generar_csrf_token();
$tema = admin_tema_actual();
?>
<!doctype html>
<html lang="es-AR"<?= $tema !== 'auto' ? ' data-theme="' . htmlspecialchars($tema) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin — Auditoría</title>
<link rel="stylesheet" href="../assets/style.css?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?>">
</head>
<body>
  <header class="topbar">
    <h1>Auditoría</h1>
    <nav>
      <?= render_btn_tema($tema, $csrf) ?>
      &nbsp;|&nbsp;
      <a href="cartera.php" class="logout-link">← Cartera</a>
      &nbsp;|&nbsp;
      <a href="usuarios.php" class="logout-link">Usuarios</a>
    </nav>
  </header>

  <main class="dashboard">
    <form method="get" class="form-inline">
      <label>Entidad
        <select name="entidad" onchange="this.form.submit()">
          <option value="">Todas</option>
          <?php foreach ($ENTIDADES as $e): ?>
          <option value="<?= htmlspecialchars($e) ?>" <?= ($filtroValido && $filtro === $e) ? 'selected' : '' ?>><?= htmlspecialchars($e) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <noscript><button type="submit">Filtrar</button></noscript>
    </form>

    <?php if (!$filas): ?>
    <p class="tarjeta-sub">Todavía no hay actividad registrada.</p>
    <?php else: ?>
    <div class="tabla-scroll">
      <table>
        <thead>
          <tr><th>Fecha</th><th>Actor</th><th>Acción</th><th>Entidad</th><th>Detalle</th><th>IP</th></tr>
        </thead>
        <tbody>
          <?php foreach ($filas as $f): ?>
          <tr>
            <td><?= htmlspecialchars((string) $f['creado_en']) ?></td>
            <td>
              <?= htmlspecialchars($f['actor_nombre']) ?>
              <?php if ((int) $f['es_break_glass'] === 1): ?>
              <span class="tarjeta-sub">(break-glass)</span>
              <?php else: ?>
              <span class="tarjeta-sub">(<?= htmlspecialchars(etiqueta_rol($f['actor_rol'])) ?>)</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($f['accion']) ?></td>
            <td><?= htmlspecialchars($f['entidad']) ?><?= $f['entidad_id'] !== null ? ' #' . (int) $f['entidad_id'] : '' ?></td>
            <td><?= htmlspecialchars($f['detalle']) ?></td>
            <td><?= htmlspecialchars((string) ($f['ip'] ?? '')) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </main>
  <script src="../assets/tema-toggle.js?v=<?= @filemtime(__DIR__ . '/../assets/tema-toggle.js') ?>"></script>
</body>
</html>
