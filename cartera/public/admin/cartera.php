<?php
/**
 * Panel de admin — Vista de agencia (cartera).
 * Muestra todas las PyMEs activas de un vistazo: semáforo y ROAS del último día
 * con datos de cada cliente, más el avance del mes contra el objetivo.
 * Solo lectura. Los cálculos (ROAS/CPL/semáforo/% objetivo) salen de las vistas;
 * PHP solo asocia por cliente y ordena para presentar.
 */
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/alertas_agencia.php';
require_once __DIR__ . '/../../includes/crons_heartbeat.php';
require_once __DIR__ . '/../../includes/tendencia_cartera.php';
exigir_admin();

$pdo = db();

$solicitudesPendientes = contar_solicitudes_pendientes();
$colaFallidos = (int) $pdo->query("SELECT COUNT(*) FROM cola_whatsapp WHERE estado = 'fallido'")->fetchColumn();
$avisos = alertas_agencia($pdo);
$estadoCrons = estado_crons($pdo);
$tendencia = tendencia_cartera($pdo);

// 1) Última fila (por fecha) de cada cliente activo. El MAX(fecha) es interno de
//    MySQL (compara datos almacenados entre sí), así que no cruza los relojes.
$filas = $pdo->query("
    SELECT c.id AS cliente_id, c.nombre_empresa,
           vms.fecha, vms.inversion_total, vms.leads_totales,
           vms.cpl_dia, vms.roas_dia, vms.estado_semaforo
    FROM clientes c
    LEFT JOIN (SELECT cliente_id, MAX(fecha) AS max_fecha
               FROM metricas_diarias GROUP BY cliente_id) ult ON ult.cliente_id = c.id
    LEFT JOIN vista_metricas_semaforo vms
           ON vms.cliente_id = c.id AND vms.fecha = ult.max_fecha
    WHERE c.estado_cuenta = 'activo'
")->fetchAll();

// 2) Objetivo del mes actual. El mes se determina con el reloj de PHP
//    (primer_dia_mes()), nunca con CURDATE() de MySQL.
$stmt = $pdo->prepare("
    SELECT cliente_id, meta_leads, leads_reales_mes,
           pct_cumplimiento_presupuesto, pct_cumplimiento_ventas
    FROM vista_objetivos_progreso
    WHERE mes_anio = ?
");
$stmt->execute([primer_dia_mes()]);
$objetivos = [];
foreach ($stmt->fetchAll() as $o) {
    $objetivos[$o['cliente_id']] = $o;
}

// 3) Rank de salud a partir del estado_semaforo que ya trae la vista.
function cartera_rank(?string $estado): int
{
    if ($estado === null) return 3;                 // sin métricas todavía
    if (strpos($estado, '🔴') !== false) return 0;
    if (strpos($estado, '🟡') !== false) return 1;
    if (strpos($estado, '🟢') !== false) return 2;
    return 3;                                        // ⚪ sin datos / sin leads
}

// 4) Ordenar: peor salud arriba; dentro del grupo, peor ROAS primero.
usort($filas, function ($a, $b) {
    $ra = cartera_rank($a['estado_semaforo']);
    $rb = cartera_rank($b['estado_semaforo']);
    if ($ra !== $rb) return $ra <=> $rb;
    return (float) ($a['roas_dia'] ?? 0) <=> (float) ($b['roas_dia'] ?? 0);
});

// 5) Conteo para la tira de resumen.
$resumen = ['total' => count($filas), 0 => 0, 1 => 0, 2 => 0, 3 => 0];
foreach ($filas as $f) {
    $resumen[cartera_rank($f['estado_semaforo'])]++;
}

// Días de atraso de la última carga (reloj de PHP). >= 2 => anterior a ayer.
function dias_atraso(?string $fecha): int
{
    if ($fecha === null) return 0;
    $diff = (strtotime(date('Y-m-d')) - strtotime($fecha)) / 86400;
    return (int) round($diff);
}

// Etiqueta de mes corta en español para la tendencia (ej "Feb 26"). No usa
// strftime (deprecado en PHP 8.1+) ni depende del locale del server.
function tendencia_mes_corto(string $mesDia1): string
{
    $abbr = [1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun',
             7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'];
    $ts = strtotime($mesDia1);
    return $abbr[(int) date('n', $ts)] . ' ' . date('y', $ts);
}

$csrf = generar_csrf_token();
$tema = admin_tema_actual();
?>
<!doctype html>
<html lang="es-AR"<?= $tema !== 'auto' ? ' data-theme="' . htmlspecialchars($tema) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin — Cartera</title>
<link rel="stylesheet" href="../assets/style.css?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?>">
</head>
<body>
  <header class="topbar">
    <h1>Cartera</h1>
    <nav>
      <?= render_btn_tema($tema, $csrf) ?>
      &nbsp;|&nbsp;
      <?php $yo = admin_actual(); ?>
      <span class="logout-link"><?= $yo['break_glass'] ? 'Modo emergencia' : 'Conectado como ' . htmlspecialchars($yo['nombre']) . ' (' . htmlspecialchars(etiqueta_rol($yo['rol'])) . ')' ?></span>
      &nbsp;|&nbsp;
      <a href="clientes.php" class="logout-link">Clientes</a>
      &nbsp;|&nbsp;
      <a href="solicitudes.php" class="logout-link">Solicitudes<?= $solicitudesPendientes > 0 ? ' (' . $solicitudesPendientes . ')' : '' ?></a>
      &nbsp;|&nbsp;
      <a href="cola_whatsapp.php" class="logout-link">Cola WhatsApp<?= $colaFallidos > 0 ? ' (' . $colaFallidos . ')' : '' ?></a>
      <?php if (admin_rol() === 'dueno'): ?>
      &nbsp;|&nbsp;
      <a href="usuarios.php" class="logout-link">Usuarios</a>
      &nbsp;|&nbsp;
      <a href="auditoria.php" class="logout-link">Auditoría</a>
      <?php endif; ?>
      &nbsp;|&nbsp;
      <a href="mi_cuenta.php" class="logout-link">Mi cuenta</a>
      &nbsp;|&nbsp;
      <?= boton_logout() ?>
    </nav>
  </header>

  <main class="dashboard">
    <?php $adminAviso = $_SESSION['admin_aviso'] ?? null; unset($_SESSION['admin_aviso']); ?>
    <?php if ($adminAviso): ?>
    <p class="alerta-ok"><?= htmlspecialchars($adminAviso) ?></p>
    <?php endif; ?>
    <?php if ($avisos): ?>
    <div class="avisos-agencia">
      <strong>⚠ Avisos de la agencia (<?= count($avisos) ?>)</strong>
      <ul>
        <?php foreach ($avisos as $a): ?>
        <li>
          <?= $a['icono'] ?>
          <?php if (!empty($a['cliente_id'])): ?>
            <a href="cliente_form.php?id=<?= (int) $a['cliente_id'] ?>"><?= htmlspecialchars($a['texto']) ?></a>
          <?php else: ?>
            <?= htmlspecialchars($a['texto']) ?>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <div class="salud-crons">
      <strong>Salud de crons</strong>
      <ul>
        <?php foreach ($estadoCrons as $c): ?>
        <?php
          $icono = $c['estado'] === 'al_dia' ? '🟢' : ($c['estado'] === 'caido' ? '🔴' : '⚪');
          if ($c['ultimo_latido'] === null) {
              $detalle = 'sin datos aún';
          } else {
              $detalle = 'última: ' . date('d/m H:i', strtotime($c['ultimo_latido']));
              if ($c['estado'] === 'caido') {
                  $detalle .= ' · hace ' . (int) $c['horas_sin_latido'] . 'h';
              }
          }
        ?>
        <li><?= $icono ?> <?= htmlspecialchars($c['etiqueta']) ?> — <span class="tarjeta-sub"><?= htmlspecialchars($detalle) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>

    <p class="resumen-cartera">
      Activos: <strong><?= (int) $resumen['total'] ?></strong>
      &nbsp;·&nbsp; 🔴 <?= (int) $resumen[0] ?>
      &nbsp;·&nbsp; 🟡 <?= (int) $resumen[1] ?>
      &nbsp;·&nbsp; 🟢 <?= (int) $resumen[2] ?>
      &nbsp;·&nbsp; ⚪ <?= (int) $resumen[3] ?>
    </p>

    <section class="tendencia-cartera">
      <strong>Salud de la cartera — últimos 6 meses</strong>
      <div class="tendencia-lista">
        <?php foreach ($tendencia as $t): ?>
        <div class="tendencia-fila">
          <span class="tendencia-mes"><?= htmlspecialchars(tendencia_mes_corto($t['mes'])) ?></span>
          <div class="tendencia-barra">
            <?php if ((int) $t['total'] === 0): ?>
            <span class="tendencia-vacio">sin datos</span>
            <?php else: ?>
            <?php foreach (['verde', 'amarillo', 'rojo', 'gris'] as $color): ?>
            <?php $n = (int) $t[$color]; if ($n > 0): ?>
            <span class="tendencia-seg tendencia-<?= htmlspecialchars($color) ?>" style="width: <?= htmlspecialchars((string) round($n / $t['total'] * 100, 2)) ?>%"></span>
            <?php endif; ?>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <span class="tendencia-conteos">
            <?php if ((int) $t['verde'] > 0): ?>🟢<?= (int) $t['verde'] ?> <?php endif; ?>
            <?php if ((int) $t['amarillo'] > 0): ?>🟡<?= (int) $t['amarillo'] ?> <?php endif; ?>
            <?php if ((int) $t['rojo'] > 0): ?>🔴<?= (int) $t['rojo'] ?> <?php endif; ?>
            <?php if ((int) $t['gris'] > 0): ?>⚪<?= (int) $t['gris'] ?><?php endif; ?>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
    </section>

    <div class="tabla-scroll">
      <table>
        <thead>
          <tr>
            <th>Empresa</th>
            <th>Estado</th>
            <th>Datos</th>
            <th>ROAS</th>
            <th>CPL</th>
            <th>Inv. día</th>
            <th>Leads día</th>
            <th>Ppto mes</th>
            <th>Leads mes</th>
            <th>Ventas mes</th>
            <th>Objetivo</th>
            <th>Reporte</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($filas as $f): ?>
          <?php
            $rank = cartera_rank($f['estado_semaforo']);
            $badgeClase = $rank === 0 ? 'semaforo-rojo' : ($rank === 1 ? 'semaforo-amarillo' : ($rank === 2 ? 'semaforo-verde' : ''));
            $filaClase = $rank === 0 ? 'fila-alerta' : '';
            $obj = $objetivos[$f['cliente_id']] ?? null;
            $atraso = dias_atraso($f['fecha']);
          ?>
          <tr class="<?= $filaClase ?>">
            <td><a href="cliente_form.php?id=<?= (int) $f['cliente_id'] ?>"><?= htmlspecialchars($f['nombre_empresa']) ?></a></td>
            <td><span class="badge <?= $badgeClase ?>"><?= htmlspecialchars($f['estado_semaforo'] ?? '⚪ Sin datos') ?></span></td>
            <td>
              <?php if ($f['fecha'] === null): ?>—
              <?php else: ?>
                <?= htmlspecialchars(date('d/m', strtotime($f['fecha']))) ?>
                <?php if ($atraso >= 2): ?><br><span class="tarjeta-sub">hace <?= (int) $atraso ?> días</span><?php endif; ?>
              <?php endif; ?>
            </td>
            <td><?= $f['roas_dia'] !== null ? htmlspecialchars(number_format((float) $f['roas_dia'], 1)) . 'x' : '—' ?></td>
            <td><?= $f['cpl_dia'] !== null ? htmlspecialchars(formatear_ars((float) $f['cpl_dia'])) : '—' ?></td>
            <td><?= $f['inversion_total'] !== null ? htmlspecialchars(formatear_ars((float) $f['inversion_total'])) : '—' ?></td>
            <td><?= $f['leads_totales'] !== null ? (int) $f['leads_totales'] : '—' ?></td>
            <?php if ($obj === null): ?>
            <td colspan="3" class="tarjeta-sub">— sin objetivo del mes</td>
            <?php else: ?>
            <td><?= htmlspecialchars(number_format((float) $obj['pct_cumplimiento_presupuesto'] * 100, 0)) ?>%</td>
            <td><?= (int) $obj['leads_reales_mes'] ?> / <?= (int) $obj['meta_leads'] ?></td>
            <td><?= htmlspecialchars(number_format((float) $obj['pct_cumplimiento_ventas'] * 100, 0)) ?>%</td>
            <?php endif; ?>
            <td><a href="objetivo_form.php?cliente_id=<?= (int) $f['cliente_id'] ?>"><?= $obj === null ? 'Cargar' : 'Editar' ?></a></td>
            <td><a href="reporte.php?cliente_id=<?= (int) $f['cliente_id'] ?>">Reporte</a></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$filas): ?>
          <tr><td colspan="12">No hay clientes activos.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>
  <script src="../assets/tema-toggle.js?v=<?= @filemtime(__DIR__ . '/../assets/tema-toggle.js') ?>"></script>
</body>
</html>
