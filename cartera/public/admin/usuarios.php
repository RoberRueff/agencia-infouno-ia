<?php
require_once __DIR__ . '/../../includes/admin_auth.php';
exigir_admin_rol('dueno');
require_once __DIR__ . '/../../includes/auditoria.php';
require_once __DIR__ . '/../../includes/helpers.php';

$pdo = db();
$aviso = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validar_csrf_token($_POST['csrf_token'] ?? null)) {
        $aviso = ['tipo' => 'error', 'texto' => 'Sesión inválida, recargá la página.'];
    } else {
        $accion = $_POST['accion'] ?? '';
        $targetId = (int) ($_POST['usuario_id'] ?? 0);

        if ($accion === 'crear') {
            $nombre = trim($_POST['nombre'] ?? '');
            $email = mb_strtolower(trim($_POST['email'] ?? ''));
            $rol = in_array($_POST['rol'] ?? '', ['dueno', 'operador'], true) ? $_POST['rol'] : 'operador';
            $pass = $_POST['password'] ?? '';
            if ($nombre === '' || $email === '' || $pass === '') {
                $aviso = ['tipo' => 'error', 'texto' => 'Nombre, email y contraseña son obligatorios.'];
            } elseif (strlen($pass) < 8) {
                $aviso = ['tipo' => 'error', 'texto' => 'La contraseña debe tener al menos 8 caracteres.'];
            } else {
                $existe = $pdo->prepare('SELECT id FROM usuarios_admin WHERE email = ?');
                $existe->execute([$email]);
                if ($existe->fetch()) {
                    $aviso = ['tipo' => 'error', 'texto' => 'Ya existe un usuario con ese email.'];
                } else {
                    $ins = $pdo->prepare('INSERT INTO usuarios_admin (nombre, email, password_hash, rol, activo) VALUES (?, ?, ?, ?, 1)');
                    $ins->execute([$nombre, $email, password_hash($pass, PASSWORD_DEFAULT), $rol]);
                    registrar_auditoria($pdo, 'usuario.crear', 'usuario_admin', (int) $pdo->lastInsertId(), 'alta de usuario ' . $nombre . ' (' . etiqueta_rol($rol) . ')');
                    $aviso = ['tipo' => 'ok', 'texto' => 'Usuario creado.'];
                }
            }
        } elseif ($accion === 'editar' && $targetId > 0) {
            $nombre = trim($_POST['nombre'] ?? '');
            $email = mb_strtolower(trim($_POST['email'] ?? ''));
            $rol = in_array($_POST['rol'] ?? '', ['dueno', 'operador'], true) ? $_POST['rol'] : 'operador';
            $activo = isset($_POST['activo']) ? 1 : 0;

            $stmt = $pdo->prepare('SELECT nombre, email, rol, activo FROM usuarios_admin WHERE id = ?');
            $stmt->execute([$targetId]);
            $actual = $stmt->fetch();
            $eraDuenoActivo = $actual && $actual['rol'] === 'dueno' && (int) $actual['activo'] === 1;
            $dejaDeSerDuenoActivo = $eraDuenoActivo && ($rol !== 'dueno' || $activo !== 1);

            if ($nombre === '' || $email === '') {
                $aviso = ['tipo' => 'error', 'texto' => 'Nombre y email son obligatorios.'];
            } elseif ($dejaDeSerDuenoActivo && contar_duenos_activos($pdo) <= 1) {
                $aviso = ['tipo' => 'error', 'texto' => 'No podés dejar el sistema sin ningún dueño activo.'];
            } else {
                $dup = $pdo->prepare('SELECT id FROM usuarios_admin WHERE email = ? AND id <> ?');
                $dup->execute([$email, $targetId]);
                if ($dup->fetch()) {
                    $aviso = ['tipo' => 'error', 'texto' => 'Ya existe otro usuario con ese email.'];
                } else {
                    $upd = $pdo->prepare('UPDATE usuarios_admin SET nombre = ?, email = ?, rol = ?, activo = ? WHERE id = ?');
                    $upd->execute([$nombre, $email, $rol, $activo, $targetId]);
                    if ($actual) {
                        $nuevoUsuario = ['nombre' => $nombre, 'email' => $email, 'rol' => $rol, 'activo' => $activo];
                        $ETIQUETAS_USR = ['nombre' => 'nombre', 'email' => 'email', 'rol' => 'rol', 'activo' => 'activo'];
                        $d = diff_campos($actual, $nuevoUsuario, $ETIQUETAS_USR);
                        registrar_auditoria($pdo, 'usuario.editar', 'usuario_admin', (int) $targetId, $d === '' ? 'sin cambios' : $d);
                    }
                    $aviso = ['tipo' => 'ok', 'texto' => 'Usuario actualizado.'];
                }
            }
        } elseif ($accion === 'reset_password' && $targetId > 0) {
            $pass = $_POST['password'] ?? '';
            if ($pass === '') {
                $aviso = ['tipo' => 'error', 'texto' => 'Ingresá la nueva contraseña.'];
            } elseif (strlen($pass) < 8) {
                $aviso = ['tipo' => 'error', 'texto' => 'La contraseña debe tener al menos 8 caracteres.'];
            } else {
                $upd = $pdo->prepare('UPDATE usuarios_admin SET password_hash = ? WHERE id = ?');
                $upd->execute([password_hash($pass, PASSWORD_DEFAULT), $targetId]);
                $nom = $pdo->prepare('SELECT nombre FROM usuarios_admin WHERE id = ?');
                $nom->execute([$targetId]);
                $nombreTarget = (string) $nom->fetchColumn();
                registrar_auditoria($pdo, 'usuario.reset_password', 'usuario_admin', (int) $targetId, 'reset de contraseña de ' . $nombreTarget);
                $aviso = ['tipo' => 'ok', 'texto' => 'Contraseña reseteada.'];
            }
        }
    }
    $_SESSION['usuarios_aviso'] = $aviso;
    header('Location: usuarios.php');
    exit;
}

$aviso = $_SESSION['usuarios_aviso'] ?? null;
unset($_SESSION['usuarios_aviso']);
$csrf = generar_csrf_token();
$tema = admin_tema_actual();
$usuarios = $pdo->query('SELECT id, nombre, email, rol, activo, totp_secret FROM usuarios_admin ORDER BY activo DESC, nombre')->fetchAll();
?>
<!doctype html>
<html lang="es-AR"<?= $tema !== 'auto' ? ' data-theme="' . htmlspecialchars($tema) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin — Usuarios</title>
<link rel="stylesheet" href="../assets/style.css?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?>">
</head>
<body>
  <header class="topbar">
    <h1>Usuarios del panel</h1>
    <nav>
      <?= render_btn_tema($tema, $csrf) ?>
      &nbsp;|&nbsp;
      <a href="cartera.php" class="logout-link">← Cartera</a>
      &nbsp;|&nbsp;
      <a href="mi_cuenta.php" class="logout-link">Mi cuenta</a>
    </nav>
  </header>

  <main class="dashboard">
    <?php if ($aviso): ?>
    <p class="<?= $aviso['tipo'] === 'ok' ? 'alerta-ok' : 'alerta-error' ?>"><?= htmlspecialchars($aviso['texto']) ?></p>
    <?php endif; ?>

    <div class="tabla-scroll">
      <table>
        <thead>
          <tr><th>Nombre</th><th>Email</th><th>Rol</th><th>Estado</th><th>2FA</th><th>Acciones</th></tr>
        </thead>
        <tbody>
          <?php foreach ($usuarios as $u): ?>
          <tr class="<?= (int) $u['activo'] === 1 ? '' : 'tarjeta-sub' ?>">
            <td><?= htmlspecialchars($u['nombre']) ?></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><?= htmlspecialchars(etiqueta_rol($u['rol'])) ?></td>
            <td><?= (int) $u['activo'] === 1 ? 'Activo' : 'Inactivo' ?></td>
            <td><?= empty($u['totp_secret']) ? '—' : '🔒' ?></td>
            <td>
              <details>
                <summary>Editar</summary>
                <form method="post" class="form-inline">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="accion" value="editar">
                  <input type="hidden" name="usuario_id" value="<?= (int) $u['id'] ?>">
                  <label>Nombre <input type="text" name="nombre" value="<?= htmlspecialchars($u['nombre']) ?>" required></label>
                  <label>Email <input type="email" name="email" value="<?= htmlspecialchars($u['email']) ?>" required></label>
                  <label>Rol
                    <select name="rol">
                      <?php foreach (ROLES_ADMIN as $val => $lbl): ?>
                      <option value="<?= htmlspecialchars($val) ?>" <?= $u['rol'] === $val ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <label><input type="checkbox" name="activo" <?= (int) $u['activo'] === 1 ? 'checked' : '' ?>> Activo</label>
                  <button type="submit">Guardar</button>
                </form>
                <form method="post" class="form-inline">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                  <input type="hidden" name="accion" value="reset_password">
                  <input type="hidden" name="usuario_id" value="<?= (int) $u['id'] ?>">
                  <label>Nueva contraseña <input type="password" name="password" required></label>
                  <button type="submit">Resetear contraseña</button>
                </form>
              </details>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <section class="reporte-seccion">
      <h3>Crear usuario</h3>
      <form method="post" class="form-card">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="accion" value="crear">
        <label for="c_nombre">Nombre *</label>
        <input type="text" id="c_nombre" name="nombre" required>
        <label for="c_email">Email *</label>
        <input type="email" id="c_email" name="email" required>
        <label for="c_rol">Rol</label>
        <select id="c_rol" name="rol">
          <?php foreach (ROLES_ADMIN as $val => $lbl): ?>
          <option value="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($lbl) ?></option>
          <?php endforeach; ?>
        </select>
        <label for="c_password">Contraseña inicial *</label>
        <input type="password" id="c_password" name="password" required>
        <button type="submit">Crear usuario</button>
      </form>
      <p class="tarjeta-sub">El 2FA lo activa cada usuario desde "Mi cuenta".</p>
    </section>
  </main>
  <script src="../assets/tema-toggle.js?v=<?= @filemtime(__DIR__ . '/../assets/tema-toggle.js') ?>"></script>
</body>
</html>
