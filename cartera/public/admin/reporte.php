<?php
/**
 * Reporte mensual imprimible de un cliente (vista de agencia).
 * Página admin de solo lectura, una sola hoja, pensada para imprimir / guardar
 * como PDF y enviar al cliente como entregable. Re-consulta las mismas vistas
 * que el dashboard (vista_objetivos_progreso + atribución por canal) — no
 * recalcula ROAS/CPL en PHP ni toca el dashboard del cliente.
 */
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/atribucion_canal.php';
require_once __DIR__ . '/../../includes/reporte_comparacion.php';
exigir_admin();

$pdo = db();

// Cliente: valida existencia y trae el encabezado (nombre + rubro). Si el id no
// corresponde a ningún cliente, se vuelve a la cartera sin filtrar si existía o
// no. Va antes de cualquier salida (redirect por header()).
$clienteId = (int) ($_GET['cliente_id'] ?? 0);
$clienteStmt = $pdo->prepare('SELECT id, nombre_empresa, rubro_industrial FROM clientes WHERE id = ?');
$clienteStmt->execute([$clienteId]);
$cliente = $clienteStmt->fetch();
if (!$cliente) {
    header('Location: cartera.php');
    exit;
}

// Mes del reporte: $_GET['mes'] validado (YYYY-MM-01, fecha real, no futuro);
// default = mes en curso. Reloj de PHP de punta a punta (nunca CURDATE()).
$mesActualReal = primer_dia_mes();
$mes = $mesActualReal;
$mesParam = is_string($_GET['mes'] ?? null) ? $_GET['mes'] : '';
if (preg_match('/^\d{4}-(0[1-9]|1[0-2])-01$/', $mesParam) && strtotime($mesParam) !== false && $mesParam <= $mesActualReal) {
    $mes = $mesParam;
}
$mesAnterior  = primer_dia_mes_anterior($mes);
$mesSiguiente = date('Y-m-01', strtotime($mes . ' +1 month'));
$hayMesSiguiente = ($mesSiguiente <= $mesActualReal);

// Agregados del mes elegido y del mes anterior (comparativa). Ambos de
// vista_objetivos_progreso, que ya calcula ROAS/CPL/cumplimiento en SQL.
$objetivoStmt = $pdo->prepare('SELECT * FROM vista_objetivos_progreso WHERE cliente_id = ? AND mes_anio = ?');
$objetivoStmt->execute([$clienteId, $mes]);
$objetivo = $objetivoStmt->fetch();

$objetivoAnteriorStmt = $pdo->prepare('SELECT * FROM vista_objetivos_progreso WHERE cliente_id = ? AND mes_anio = ?');
$objetivoAnteriorStmt->execute([$clienteId, $mesAnterior]);
$objetivoAnterior = $objetivoAnteriorStmt->fetch();

// Atribución por canal del mes (Google vs Meta) — include compartido.
$atribucion = atribucion_por_canal($pdo, $clienteId, $mes);

// Comparativa ROAS/CPL vs mes anterior (solo si hay objetivo en ambos meses).
$cmpRoas = null;
$cmpCpl  = null;
if ($objetivo !== false && $objetivoAnterior !== false) {
    $cmpRoas = comparar_metrica(
        (float) $objetivo['roas_mes'], (float) $objetivoAnterior['roas_mes'],
        (float) $objetivo['inversion_real_mes'] > 0, (float) $objetivoAnterior['inversion_real_mes'] > 0,
        false
    );
    $cmpCpl = comparar_metrica(
        (float) $objetivo['cpl_mes'], (float) $objetivoAnterior['cpl_mes'],
        (float) $objetivo['leads_reales_mes'] > 0, (float) $objetivoAnterior['leads_reales_mes'] > 0,
        true
    );
}

/** Nombre del mes en español sin depender del locale del server (strftime está
 *  deprecado en PHP 8.1+). mes_anio siempre es día 1, basta el número. */
function reporte_nombre_mes(string $mesDia1): string
{
    $meses = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
              5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
              9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
    $ts = strtotime($mesDia1);
    return $meses[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}
function reporte_pesos(float $n): string { return '$' . formatear_ars($n); }
function reporte_roas(float $n): string { return number_format($n, 2, ',', '.') . '×'; }
function reporte_pct(float $frac): string { return number_format($frac * 100, 0) . '%'; }

$nombreMes = reporte_nombre_mes($mes);
$csrf = generar_csrf_token();
$tema = admin_tema_actual();
?>
<!doctype html>
<html lang="es-AR"<?= $tema !== 'auto' ? ' data-theme="' . htmlspecialchars($tema) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Reporte — <?= htmlspecialchars($cliente['nombre_empresa']) ?> — <?= htmlspecialchars($nombreMes) ?></title>
<link rel="stylesheet" href="../assets/style.css?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?>">
</head>
<body>
  <header class="topbar no-print">
    <h1>Reporte mensual</h1>
    <nav>
      <?= render_btn_tema($tema, $csrf) ?>
      &nbsp;|&nbsp;
      <a href="cartera.php" class="logout-link">← Volver a la cartera</a>
      &nbsp;|&nbsp;
      <button type="button" class="boton-imprimir" onclick="window.print()">Imprimir / Guardar PDF</button>
    </nav>
  </header>

  <main class="dashboard">
    <nav class="reporte-mesnav no-print">
      <a class="mes-nav-link" href="?cliente_id=<?= (int) $clienteId ?>&amp;mes=<?= htmlspecialchars($mesAnterior) ?>">← <?= htmlspecialchars(reporte_nombre_mes($mesAnterior)) ?></a>
      <span><?= htmlspecialchars($nombreMes) ?></span>
      <?php if ($hayMesSiguiente): ?>
      <a class="mes-nav-link" href="?cliente_id=<?= (int) $clienteId ?>&amp;mes=<?= htmlspecialchars($mesSiguiente) ?>"><?= htmlspecialchars(reporte_nombre_mes($mesSiguiente)) ?> →</a>
      <?php else: ?>
      <span></span>
      <?php endif; ?>
    </nav>

    <section class="reporte-header">
      <h2><?= htmlspecialchars($cliente['nombre_empresa']) ?></h2>
      <p class="tarjeta-sub"><?= htmlspecialchars($cliente['rubro_industrial']) ?> · <?= htmlspecialchars($nombreMes) ?></p>
    </section>

    <?php if ($objetivo === false): ?>
    <section class="reporte-seccion">
      <p class="tarjeta-sub">Sin datos para este mes.</p>
    </section>
    <?php else: ?>

    <section class="reporte-seccion">
      <h3>Objetivo del mes</h3>
      <table class="reporte-tabla">
        <tbody>
          <tr>
            <th>Presupuesto</th>
            <td><?= htmlspecialchars(reporte_pesos((float) $objetivo['inversion_real_mes'])) ?> / <?= htmlspecialchars(reporte_pesos((float) $objetivo['presupuesto_asignado'])) ?></td>
            <td><?= htmlspecialchars(reporte_pct((float) $objetivo['pct_cumplimiento_presupuesto'])) ?></td>
          </tr>
          <tr>
            <th>Leads</th>
            <td><?= (int) $objetivo['leads_reales_mes'] ?> / <?= (int) $objetivo['meta_leads'] ?></td>
            <td></td>
          </tr>
          <tr>
            <th>Ventas</th>
            <td><?= htmlspecialchars(reporte_pesos((float) $objetivo['ventas_reales_mes'])) ?> / <?= htmlspecialchars(reporte_pesos((float) $objetivo['meta_ventas'])) ?></td>
            <td><?= htmlspecialchars(reporte_pct((float) $objetivo['pct_cumplimiento_ventas'])) ?></td>
          </tr>
        </tbody>
      </table>
    </section>

    <section class="reporte-seccion">
      <h3>Totales del mes</h3>
      <table class="reporte-tabla">
        <tbody>
          <tr><th>Inversión</th><td><?= htmlspecialchars(reporte_pesos((float) $objetivo['inversion_real_mes'])) ?></td></tr>
          <tr><th>Leads</th><td><?= (int) $objetivo['leads_reales_mes'] ?></td></tr>
          <tr><th>Ventas</th><td><?= htmlspecialchars(reporte_pesos((float) $objetivo['ventas_reales_mes'])) ?></td></tr>
          <tr><th>ROAS</th><td><?= htmlspecialchars(reporte_roas((float) $objetivo['roas_mes'])) ?></td></tr>
          <tr><th>CPL</th><td><?= htmlspecialchars(reporte_pesos((float) $objetivo['cpl_mes'])) ?></td></tr>
        </tbody>
      </table>
    </section>

    <?php if ($objetivoAnterior !== false): ?>
    <section class="reporte-seccion">
      <h3>Comparativa vs mes anterior</h3>
      <table class="reporte-tabla">
        <tbody>
          <tr>
            <th>ROAS</th>
            <td><?= htmlspecialchars(reporte_roas((float) $objetivo['roas_mes'])) ?> vs <?= htmlspecialchars(reporte_roas((float) $objetivoAnterior['roas_mes'])) ?></td>
            <td>
              <?php if ($cmpRoas['comparable']): ?>
              <span class="<?= htmlspecialchars($cmpRoas['clase']) ?>"><?= htmlspecialchars($cmpRoas['flecha']) ?></span>
              <?php else: ?>
              <span class="tarjeta-sub">sin dato comparable</span>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <th>CPL</th>
            <td><?= htmlspecialchars(reporte_pesos((float) $objetivo['cpl_mes'])) ?> vs <?= htmlspecialchars(reporte_pesos((float) $objetivoAnterior['cpl_mes'])) ?></td>
            <td>
              <?php if ($cmpCpl['comparable']): ?>
              <span class="<?= htmlspecialchars($cmpCpl['clase']) ?>"><?= htmlspecialchars($cmpCpl['flecha']) ?></span>
              <?php else: ?>
              <span class="tarjeta-sub">sin dato comparable</span>
              <?php endif; ?>
            </td>
          </tr>
        </tbody>
      </table>
    </section>
    <?php endif; ?>

    <?php endif; // fin del bloque "hay objetivo del mes" ?>

    <section class="reporte-seccion">
      <h3>Atribución por canal</h3>
      <?php if (!$atribucion): ?>
      <p class="tarjeta-sub">Sin datos de canal para este mes.</p>
      <?php else: ?>
      <div class="tabla-scroll">
        <table>
          <thead>
            <tr>
              <th>Canal</th><th>Inversión</th><th>Leads</th><th>Ventas</th><th>Monto</th><th>ROAS</th><th>CPL</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($atribucion as $a): ?>
            <tr>
              <td><?= htmlspecialchars(ucfirst($a['canal'])) ?></td>
              <td><?= htmlspecialchars(reporte_pesos((float) $a['inversion'])) ?></td>
              <td><?= (int) $a['leads'] ?></td>
              <td><?= (int) $a['ventas_cant'] ?></td>
              <td><?= htmlspecialchars(reporte_pesos((float) $a['ventas_monto'])) ?></td>
              <td><?= htmlspecialchars(reporte_roas((float) $a['roas'])) ?></td>
              <td><?= htmlspecialchars(reporte_pesos((float) $a['cpl'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>
  </main>
  <script src="../assets/tema-toggle.js?v=<?= @filemtime(__DIR__ . '/../assets/tema-toggle.js') ?>"></script>
</body>
</html>
