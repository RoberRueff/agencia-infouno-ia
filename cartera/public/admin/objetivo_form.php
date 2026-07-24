<?php
require_once __DIR__ . '/../../includes/admin_auth.php';
exigir_admin();
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auditoria.php';

$pdo = db();
$mes = primer_dia_mes();

$clienteId = (int) ($_GET['cliente_id'] ?? $_POST['cliente_id'] ?? 0);

$cliStmt = $pdo->prepare('SELECT id, nombre_empresa FROM clientes WHERE id = ?');
$cliStmt->execute([$clienteId]);
$cliente = $cliStmt->fetch();
if (!$cliente) {
    $_SESSION['admin_aviso'] = 'Cliente inexistente.';
    header('Location: cartera.php');
    exit;
}

$error = null;
$objetivo = ['presupuesto_asignado' => '0', 'meta_leads' => '0', 'meta_ventas' => '0'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Conservar lo cargado para el re-render ante error.
    $objetivo['presupuesto_asignado'] = $_POST['presupuesto_asignado'] ?? '0';
    $objetivo['meta_leads'] = $_POST['meta_leads'] ?? '0';
    $objetivo['meta_ventas'] = $_POST['meta_ventas'] ?? '0';

    if (!validar_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión inválida, recargá la página e intentá de nuevo.';
    } else {
        $presupuestoRaw = trim($_POST['presupuesto_asignado'] ?? '');
        $metaLeadsRaw = trim($_POST['meta_leads'] ?? '');
        $metaVentasRaw = trim($_POST['meta_ventas'] ?? '');

        if (!is_numeric($presupuestoRaw) || !is_numeric($metaLeadsRaw) || !is_numeric($metaVentasRaw)
            || (float) $presupuestoRaw < 0 || (int) $metaLeadsRaw < 0 || (float) $metaVentasRaw < 0) {
            $error = 'Los valores deben ser números mayores o iguales a cero.';
        } else {
            $objStmtViejo = $pdo->prepare('SELECT presupuesto_asignado, meta_leads, meta_ventas FROM objetivos_comerciales WHERE cliente_id = ? AND mes_anio = ?');
            $objStmtViejo->execute([$clienteId, $mes]);
            $objetivoViejo = $objStmtViejo->fetch();

            $stmt = $pdo->prepare('
                INSERT INTO objetivos_comerciales (cliente_id, mes_anio, presupuesto_asignado, meta_leads, meta_ventas)
                VALUES (:cliente_id, :mes, :presupuesto, :meta_leads, :meta_ventas)
                ON DUPLICATE KEY UPDATE
                    presupuesto_asignado = VALUES(presupuesto_asignado),
                    meta_leads = VALUES(meta_leads),
                    meta_ventas = VALUES(meta_ventas)
            ');
            $stmt->execute([
                ':cliente_id' => $clienteId,
                ':mes' => $mes,
                ':presupuesto' => (float) $presupuestoRaw,
                ':meta_leads' => (int) $metaLeadsRaw,
                ':meta_ventas' => (float) $metaVentasRaw,
            ]);
            $nuevoObjetivo = [
                'presupuesto_asignado' => (float) $presupuestoRaw,
                'meta_leads' => (int) $metaLeadsRaw,
                'meta_ventas' => (float) $metaVentasRaw,
            ];
            $ETIQUETAS_OBJ = ['presupuesto_asignado' => 'presupuesto', 'meta_leads' => 'meta leads', 'meta_ventas' => 'meta ventas'];
            $mesLegible = date('m/Y', strtotime($mes));
            if ($objetivoViejo) {
                $d = diff_campos($objetivoViejo, $nuevoObjetivo, $ETIQUETAS_OBJ);
                $detalle = 'mes ' . $mesLegible . ' — ' . ($d === '' ? 'sin cambios' : $d);
            } else {
                $detalle = 'alta de objetivo mes ' . $mesLegible;
            }
            registrar_auditoria($pdo, 'objetivo.guardar', 'objetivo', (int) $clienteId, $detalle);
            $_SESSION['admin_aviso'] = 'Objetivo del mes actualizado para ' . $cliente['nombre_empresa'] . '.';
            header('Location: cartera.php');
            exit;
        }
    }
} else {
    // GET: precargar el objetivo existente del mes, si lo hay.
    $objStmt = $pdo->prepare('SELECT presupuesto_asignado, meta_leads, meta_ventas FROM objetivos_comerciales WHERE cliente_id = ? AND mes_anio = ?');
    $objStmt->execute([$clienteId, $mes]);
    $existente = $objStmt->fetch();
    if ($existente) {
        $objetivo = $existente;
    }
}

$csrf = generar_csrf_token();
$tema = admin_tema_actual();
?>
<!doctype html>
<html lang="es-AR"<?= $tema !== 'auto' ? ' data-theme="' . htmlspecialchars($tema) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin — Objetivo de <?= htmlspecialchars($cliente['nombre_empresa']) ?></title>
<link rel="stylesheet" href="../assets/style.css?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?>">
</head>
<body>
  <header class="topbar">
    <h1>Objetivo del mes — <?= htmlspecialchars($cliente['nombre_empresa']) ?></h1>
    <nav>
      <?= render_btn_tema($tema, $csrf) ?>
      &nbsp;|&nbsp;
      <a href="cartera.php" class="logout-link">← Volver a la cartera</a>
    </nav>
  </header>

  <main class="dashboard">
    <?php if ($error): ?>
      <p class="alerta-error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <p class="resumen-cartera">Mes: <strong><?= htmlspecialchars(date('m/Y', strtotime($mes))) ?></strong></p>

    <form method="post" class="form-card" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="cliente_id" value="<?= (int) $clienteId ?>">

      <label for="presupuesto_asignado">Presupuesto asignado ($)</label>
      <input type="number" step="0.01" min="0" id="presupuesto_asignado" name="presupuesto_asignado" value="<?= htmlspecialchars((string) $objetivo['presupuesto_asignado']) ?>">

      <label for="meta_leads">Meta de leads</label>
      <input type="number" step="1" min="0" id="meta_leads" name="meta_leads" value="<?= htmlspecialchars((string) $objetivo['meta_leads']) ?>">

      <label for="meta_ventas">Meta de ventas ($)</label>
      <input type="number" step="0.01" min="0" id="meta_ventas" name="meta_ventas" value="<?= htmlspecialchars((string) $objetivo['meta_ventas']) ?>">

      <button type="submit">Guardar</button>
    </form>
  </main>
  <script src="../assets/tema-toggle.js?v=<?= @filemtime(__DIR__ . '/../assets/tema-toggle.js') ?>"></script>
</body>
</html>
