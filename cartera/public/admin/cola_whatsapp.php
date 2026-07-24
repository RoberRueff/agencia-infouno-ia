<?php
require_once __DIR__ . '/../../includes/admin_auth.php';
exigir_admin();
require_once __DIR__ . '/../../includes/auditoria.php';
require_once __DIR__ . '/../../includes/cola_whatsapp.php';
require_once __DIR__ . '/../../includes/helpers.php';

$pdo = db();
$aviso = null;
$ESTADOS = ['pendiente', 'enviado', 'fallido'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validar_csrf_token($_POST['csrf_token'] ?? null)) {
        $aviso = ['tipo' => 'error', 'texto' => 'Sesión inválida, recargá la página.'];
    } else {
        $accion = $_POST['accion'] ?? '';
        $id = (int) ($_POST['id'] ?? 0);
        if ($accion === 'reintentar' && $id > 0) {
            $r = reintentar_fallido($pdo, $id);
            if ($r) {
                registrar_auditoria($pdo, 'cola.reintentar', 'cola_whatsapp', $id, 'reintento manual de ' . $r['plantilla'] . ' a ' . enmascarar_destinatario($r['destinatario']));
                $aviso = ['tipo' => 'ok', 'texto' => 'Mensaje reencolado para el próximo envío.'];
            } else {
                $aviso = ['tipo' => 'error', 'texto' => 'El mensaje ya no está en estado fallido.'];
            }
        } elseif ($accion === 'descartar' && $id > 0) {
            $r = descartar_fallido($pdo, $id);
            if ($r) {
                registrar_auditoria($pdo, 'cola.descartar', 'cola_whatsapp', $id, 'descartó ' . $r['plantilla'] . ' a ' . enmascarar_destinatario($r['destinatario']) . ' (error: ' . (string) $r['ultimo_error'] . ')');
                $aviso = ['tipo' => 'ok', 'texto' => 'Mensaje descartado.'];
            } else {
                $aviso = ['tipo' => 'error', 'texto' => 'El mensaje ya no está en estado fallido.'];
            }
        } elseif ($accion === 'reintentar_todos') {
            $n = reintentar_todos_fallidos($pdo);
            registrar_auditoria($pdo, 'cola.reintentar_masivo', 'cola_whatsapp', null, 'reintentó ' . $n . ' mensajes fallidos');
            $aviso = ['tipo' => 'ok', 'texto' => "Se reencolaron $n mensajes fallidos."];
        }
    }
    $_SESSION['cola_whatsapp_aviso'] = $aviso;
    header('Location: cola_whatsapp.php');
    exit;
}

$aviso = $_SESSION['cola_whatsapp_aviso'] ?? null;
unset($_SESSION['cola_whatsapp_aviso']);

$filtro = $_GET['estado'] ?? '';
$filtroValido = in_array($filtro, $ESTADOS, true);
$conteos = contar_cola_por_estado($pdo);
$filas = listar_cola($pdo, $filtroValido ? $filtro : null);
$csrf = generar_csrf_token();
$tema = admin_tema_actual();

// estado → clase de color del badge (reusa las clases del semáforo).
$claseEstado = ['pendiente' => 'semaforo-amarillo', 'enviado' => 'semaforo-verde', 'fallido' => 'semaforo-rojo'];
?>
<!doctype html>
<html lang="es-AR"<?= $tema !== 'auto' ? ' data-theme="' . htmlspecialchars($tema) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin — Cola de WhatsApp</title>
<link rel="stylesheet" href="../assets/style.css?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?>">
</head>
<body>
  <header class="topbar">
    <h1>Cola de WhatsApp</h1>
    <nav><?= render_btn_tema($tema, $csrf) ?> &nbsp;|&nbsp; <a href="cartera.php" class="logout-link">← Cartera</a></nav>
  </header>

  <main class="dashboard">
    <?php if ($aviso): ?>
    <p class="<?= $aviso['tipo'] === 'ok' ? 'alerta-ok' : 'alerta-error' ?>"><?= htmlspecialchars($aviso['texto']) ?></p>
    <?php endif; ?>

    <p class="tarjeta-sub">
      Pendientes: <strong><?= (int) $conteos['pendiente'] ?></strong> &middot;
      Enviados: <strong><?= (int) $conteos['enviado'] ?></strong> &middot;
      Fallidos: <strong><?= (int) $conteos['fallido'] ?></strong>
    </p>

    <?php if ($conteos['fallido'] > 0): ?>
    <form method="post" class="form-inline" onsubmit="return confirm('¿Reencolar todos los mensajes fallidos?');">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="accion" value="reintentar_todos">
      <button type="submit">Reintentar todos los fallidos (<?= (int) $conteos['fallido'] ?>)</button>
    </form>
    <?php endif; ?>

    <form method="get" class="form-inline">
      <label>Estado
        <select name="estado" onchange="this.form.submit()">
          <option value="">Todos</option>
          <?php foreach ($ESTADOS as $e): ?>
          <option value="<?= htmlspecialchars($e) ?>" <?= ($filtroValido && $filtro === $e) ? 'selected' : '' ?>><?= htmlspecialchars($e) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <noscript><button type="submit">Filtrar</button></noscript>
    </form>

    <?php if (!$filas): ?>
    <p class="tarjeta-sub">No hay mensajes en la cola<?= $filtroValido ? ' con ese estado' : '' ?>.</p>
    <?php else: ?>
    <div class="tabla-scroll">
      <table>
        <thead>
          <tr><th>Estado</th><th>Destinatario</th><th>Plantilla</th><th>Intentos</th><th>Próximo intento</th><th>Último error</th><th>Actualizado</th><th>Parámetros</th><th>Acciones</th></tr>
        </thead>
        <tbody>
          <?php foreach ($filas as $f): ?>
          <tr>
            <td><span class="badge <?= htmlspecialchars($claseEstado[$f['estado']] ?? '') ?>"><?= htmlspecialchars($f['estado']) ?></span></td>
            <td><?= htmlspecialchars($f['destinatario']) ?></td>
            <td><?= htmlspecialchars($f['plantilla']) ?></td>
            <td><?= (int) $f['intentos'] ?>/<?= COLA_WHATSAPP_MAX_INTENTOS ?></td>
            <td><?= htmlspecialchars((string) $f['proximo_intento']) ?></td>
            <td><?= htmlspecialchars((string) ($f['ultimo_error'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string) $f['actualizado_en']) ?></td>
            <td>
              <details>
                <summary>ver</summary>
                <pre><?= htmlspecialchars(json_encode(json_decode((string) $f['parametros'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
              </details>
            </td>
            <td>
              <?php if ($f['estado'] === 'fallido'): ?>
              <form method="post" class="form-inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="accion" value="reintentar">
                <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                <button type="submit">Reintentar</button>
              </form>
              <form method="post" class="form-inline" onsubmit="return confirm('¿Descartar este mensaje?');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="accion" value="descartar">
                <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                <button type="submit">Descartar</button>
              </form>
              <?php endif; ?>
            </td>
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
