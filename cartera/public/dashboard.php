<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/leads_lifecycle.php';
require_once __DIR__ . '/../includes/atribucion_canal.php';
require_once __DIR__ . '/../includes/benchmark_rubro.php';

$cliente = exigir_login();
$pdo = db();
$clienteId = $cliente['id'];

// Gestión de leads (POST) — patrón PRG: procesa la acción y redirige.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aviso = null;
    if (!validar_csrf_token($_POST['csrf_token'] ?? null)) {
        $aviso = ['tipo' => 'error', 'texto' => 'Sesión inválida, recargá la página e intentá de nuevo.'];
    } else {
        $leadId = (int) ($_POST['lead_id'] ?? 0);
        $accion = $_POST['accion'] ?? '';
        if ($accion === 'negociacion') {
            $aviso = lead_a_negociacion($pdo, $leadId, $clienteId)
                ? ['tipo' => 'ok', 'texto' => 'Lead movido a En negociación.']
                : ['tipo' => 'error', 'texto' => 'No se pudo actualizar el lead.'];
        } elseif ($accion === 'ganado') {
            $monto = parsear_monto_whatsapp($_POST['monto'] ?? '');
            if ($monto === null || $monto <= 0) {
                $aviso = ['tipo' => 'error', 'texto' => 'Ingresá un monto válido para registrar la venta.'];
            } else {
                $aviso = lead_a_ganado($pdo, $leadId, $clienteId, $monto)
                    ? ['tipo' => 'ok', 'texto' => 'Venta de $' . formatear_ars($monto) . ' registrada.']
                    : ['tipo' => 'error', 'texto' => 'No se pudo registrar la venta (¿el lead ya estaba cerrado?).'];
            }
        } elseif ($accion === 'perdido') {
            $motivoSel = $_POST['motivo'] ?? '';
            if ($motivoSel === 'Otro') {
                // motivo_perdida es VARCHAR(255): recortar para no romper contra STRICT_TRANS_TABLES.
                $motivo = mb_substr(trim($_POST['motivo_otro'] ?? ''), 0, 255);
            } elseif (in_array($motivoSel, LEAD_MOTIVOS, true)) {
                $motivo = $motivoSel;
            } else {
                $motivo = '';
            }
            if ($motivo === '') {
                $aviso = ['tipo' => 'error', 'texto' => 'Elegí un motivo de pérdida (y escribí la nota si es "Otro").'];
            } else {
                $aviso = lead_a_perdido($pdo, $leadId, $clienteId, $motivo)
                    ? ['tipo' => 'ok', 'texto' => 'Lead marcado como Perdido.']
                    : ['tipo' => 'error', 'texto' => 'No se pudo actualizar el lead.'];
            }
        }
    }
    $_SESSION['dashboard_aviso'] = $aviso;
    header('Location: dashboard.php');
    exit;
}

$avisoLeads = $_SESSION['dashboard_aviso'] ?? null;
unset($_SESSION['dashboard_aviso']);
$csrf = generar_csrf_token();

// Selector de mes: $_GET['mes'] validado (YYYY-MM-01, fecha real, no futuro); default = mes en curso.
$mesActualReal = primer_dia_mes();
$mes = $mesActualReal;
$mesParam = is_string($_GET['mes'] ?? null) ? $_GET['mes'] : '';
if (preg_match('/^\d{4}-(0[1-9]|1[0-2])-01$/', $mesParam) && strtotime($mesParam) !== false && $mesParam <= $mesActualReal) {
    $mes = $mesParam;
}
$mesSiguiente = date('Y-m-01', strtotime($mes . ' +1 month'));
$mesAnterior = primer_dia_mes_anterior($mes);
$esMesEnCurso = ($mes === $mesActualReal);

// Umbral anti-ruido del pacing (mismo criterio que ALERTA_DIAS_MIN_MES de alertas_agencia.php,
// definido acá localmente para no acoplar el dashboard del cliente a ese módulo del admin).
const PACING_DIA_MIN = 5;

// Tarjetas Estado/ROAS/CPL: último día registrado DEL MES elegido.
$hoyStmt = $pdo->prepare('SELECT * FROM vista_metricas_semaforo WHERE cliente_id = ? AND fecha >= ? AND fecha < ? ORDER BY fecha DESC LIMIT 1');
$hoyStmt->execute([$clienteId, $mes, $mesSiguiente]);
$ultimaMetrica = $hoyStmt->fetch();

// Objetivo del mes elegido y del mes anterior (este último para la comparativa).
$objetivoStmt = $pdo->prepare('SELECT * FROM vista_objetivos_progreso WHERE cliente_id = ? AND mes_anio = ?');
$objetivoStmt->execute([$clienteId, $mes]);
$objetivo = $objetivoStmt->fetch();
$objetivoAnteriorStmt = $pdo->prepare('SELECT * FROM vista_objetivos_progreso WHERE cliente_id = ? AND mes_anio = ?');
$objetivoAnteriorStmt->execute([$clienteId, $mesAnterior]);
$objetivoAnterior = $objetivoAnteriorStmt->fetch();

// Días del mes elegido para la tabla/gráfico (acotado al mes, 14 más recientes).
$historicoStmt = $pdo->prepare('SELECT * FROM vista_metricas_semaforo WHERE cliente_id = ? AND fecha >= ? AND fecha < ? ORDER BY fecha DESC LIMIT 14');
$historicoStmt->execute([$clienteId, $mes, $mesSiguiente]);
$historico = $historicoStmt->fetchAll();

// SLA de respuesta del lead: el objetivo por cliente no viene en la sesión de login.
$clienteRowStmt = $pdo->prepare('SELECT sla_horas_objetivo, rubro_industrial, tema FROM clientes WHERE id = ?');
$clienteRowStmt->execute([$clienteId]);
$clienteRow = $clienteRowStmt->fetch();
$slaHoras = (int) ($clienteRow['sla_horas_objetivo'] ?? 0);
$rubro = (string) ($clienteRow['rubro_industrial'] ?? '');
$tema = (string) ($clienteRow['tema'] ?? 'auto');

// Benchmark del rubro para el mes seleccionado (promedio de pares, mín. 3).
$benchmark = benchmark_rubro($pdo, $clienteId, $rubro, $mes);

function fmt($n) { return number_format((float) $n, 0, ',', '.'); }

/**
 * Barra de progreso "real vs objetivo" (meter). El relleno se cubre en verde
 * cuando se alcanza o supera el 100% (severidad), azul mientras está en curso.
 * El ancho de la barra se cappea en 100% aunque el % real sea mayor, y ese
 * exceso se comunica en el número, no estirando la barra fuera del track.
 */
function render_meter(string $nombre, float $real, float $objetivo, string $formato = 'moneda', ?float $proyeccion = null): string
{
    $pct = $objetivo > 0 ? $real / $objetivo : 0;
    $pctMostrado = round($pct * 100);
    $anchoBarra = min($pct, 1) * 100;
    $completo = $pct >= 1;

    $valorReal = $formato === 'moneda' ? '$' . fmt($real) : number_format($real, 0, ',', '.');
    $valorObjetivo = $formato === 'moneda' ? '$' . fmt($objetivo) : number_format($objetivo, 0, ',', '.');

    $lineaProy = '';
    if ($proyeccion !== null) {
        $pctProy = $objetivo > 0 ? round($proyeccion / $objetivo * 100) : 0;
        $valorProy = $formato === 'moneda' ? '$' . fmt($proyeccion) : number_format($proyeccion, 0, ',', '.');
        $lineaProy = '<div class="meter-proyeccion">proyectado a fin de mes: ~' . $valorProy . ' (' . $pctProy . '% de la meta)</div>';
    }

    return '
      <div class="meter-row">
        <div class="meter-label-row">
          <span class="meter-nombre">' . htmlspecialchars($nombre) . '</span>
          <span class="meter-valores">' . $valorReal . ' / ' . $valorObjetivo . '<span class="meter-pct">' . $pctMostrado . '%</span></span>
        </div>
        <div class="meter-track' . ($completo ? ' meter-track-completo' : '') . '">
          <div class="meter-fill' . ($completo ? ' meter-fill-completo' : '') . '" style="width:' . $anchoBarra . '%"></div>
        </div>' . $lineaProy . '
      </div>';
}

/**
 * Fila de comparación mes vs mes: valor del mes elegido + flecha de dirección.
 * $masEsMejor: true si mayor es mejor (ROAS/leads/ventas/conversión), false si
 * mayor es peor (CPL). $formato: 'moneda' | 'numero' | 'roas' | 'pct'.
 */
function render_delta(string $etiqueta, float $actual, ?float $anterior, string $formato, bool $masEsMejor): string
{
    $fmtVal = function (float $v) use ($formato): string {
        if ($formato === 'moneda') return '$' . fmt($v);
        if ($formato === 'roas') return number_format($v, 2, ',', '.') . '×';
        if ($formato === 'pct') return number_format($v * 100, 0) . '%';
        return number_format($v, 0, ',', '.');
    };
    $flecha = '';
    if ($anterior !== null) {
        if ($actual > $anterior) {
            $flecha = '<span class="delta ' . ($masEsMejor ? 'delta-bien' : 'delta-mal') . '">▲</span>';
        } elseif ($actual < $anterior) {
            $flecha = '<span class="delta ' . ($masEsMejor ? 'delta-mal' : 'delta-bien') . '">▼</span>';
        } else {
            $flecha = '<span class="delta delta-igual">→</span>';
        }
    }
    return '<div class="comparativa-item">'
        . '<span class="comparativa-etiqueta">' . htmlspecialchars($etiqueta) . '</span>'
        . '<span class="comparativa-valor">' . $fmtVal($actual) . ' ' . $flecha . '</span>'
        . '</div>';
}

/**
 * Gráfico de línea (SVG) con la evolución del ROAS. $puntos debe venir
 * ordenado ascendente por fecha (más viejo primero) para leerse izq->der.
 * Incluye una línea de referencia en ROAS=1 (punto de equilibrio) y un
 * único label directo en el último valor, siguiendo la convención de
 * etiquetar solo el punto que importa en vez de cada dato.
 */
function render_roas_chart(array $puntos): string
{
    if (!$puntos) {
        return '<p class="tarjeta-sub">Todavía no hay datos para el gráfico de ROAS.</p>';
    }

    $valores = array_map(fn($p) => (float) $p['roas_dia'], $puntos);
    $n = count($puntos);

    $ancho = 640; $alto = 160;
    $padIzq = 10; $padDer = 46; $padSup = 16; $padInf = 24;
    $areaAncho = $ancho - $padIzq - $padDer;
    $areaAlto = $alto - $padSup - $padInf;
    $maxValor = max(max($valores), 1) * 1.15;

    $coordX = fn($i) => $padIzq + ($n > 1 ? $i * ($areaAncho / ($n - 1)) : $areaAncho / 2);
    $coordY = fn($v) => $padSup + $areaAlto - ($v / $maxValor) * $areaAlto;

    $puntosSvg = [];
    foreach ($valores as $i => $v) {
        $puntosSvg[] = round($coordX($i), 1) . ',' . round($coordY($v), 1);
    }
    $pathD = 'M' . implode(' L', $puntosSvg);

    $yBreakeven = round($coordY(1), 1);
    $lineaBreakeven = '
        <line class="chart-grid" x1="' . $padIzq . '" y1="' . $yBreakeven . '" x2="' . ($ancho - $padDer) . '" y2="' . $yBreakeven . '" />
        <text class="chart-texto" x="' . ($ancho - $padDer + 6) . '" y="' . $yBreakeven . '" dominant-baseline="middle">1x</text>';

    $ultimoIdx = $n - 1;
    $ultimoX = round($coordX($ultimoIdx), 1);
    $ultimoY = round($coordY($valores[$ultimoIdx]), 1);
    $ultimoLabel = '
        <circle class="chart-punto" cx="' . $ultimoX . '" cy="' . $ultimoY . '" r="4" />
        <text class="chart-texto-valor" x="' . round($ultimoX + 8, 1) . '" y="' . $ultimoY . '" dominant-baseline="middle">' . number_format($valores[$ultimoIdx], 2) . 'x</text>';

    $fechaInicio = htmlspecialchars(date('d/m', strtotime($puntos[0]['fecha'])));
    $fechaFin = htmlspecialchars(date('d/m', strtotime($puntos[$ultimoIdx]['fecha'])));

    return '
      <svg viewBox="0 0 ' . $ancho . ' ' . $alto . '" role="img" aria-label="Evolución de ROAS en los últimos ' . $n . ' días">' .
        $lineaBreakeven . '
        <path class="chart-linea" d="' . $pathD . '" />' .
        $ultimoLabel . '
        <text class="chart-texto" x="' . $padIzq . '" y="' . ($alto - 4) . '">' . $fechaInicio . '</text>
        <text class="chart-texto" x="' . ($ancho - $padDer) . '" y="' . ($alto - 4) . '" text-anchor="end">' . $fechaFin . '</text>
      </svg>';
}

$historicoAsc = array_reverse($historico);
?>
<!doctype html>
<html lang="es-AR"<?= $tema !== 'auto' ? ' data-theme="' . htmlspecialchars($tema) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Panel de Marketing — <?= htmlspecialchars($cliente['nombre_empresa']) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body>
  <header class="topbar">
    <h1><?= htmlspecialchars($cliente['nombre_empresa']) ?></h1>
    <nav>
      <?= render_btn_tema($tema, $csrf) ?>
      <button type="button" id="btn-ayuda" class="btn-ayuda" aria-haspopup="dialog" aria-controls="ayuda-dialog">
        <span class="btn-ayuda-icono" aria-hidden="true">?</span> Ayuda
      </button>
      <?= boton_logout() ?>
    </nav>
  </header>

  <main class="dashboard">
    <nav class="mes-nav">
      <a class="mes-nav-link" href="?mes=<?= htmlspecialchars($mesAnterior) ?>">← <?= htmlspecialchars(date('m/Y', strtotime($mesAnterior))) ?></a>
      <span class="mes-nav-actual"><?= htmlspecialchars(date('m/Y', strtotime($mes))) ?><?= $esMesEnCurso ? ' (mes en curso)' : '' ?></span>
      <?php if (!$esMesEnCurso): ?>
      <a class="mes-nav-link" href="?mes=<?= htmlspecialchars($mesSiguiente) ?>"><?= htmlspecialchars(date('m/Y', strtotime($mesSiguiente))) ?> →</a>
      <?php else: ?>
      <span class="mes-nav-link mes-nav-disabled"></span>
      <?php endif; ?>
    </nav>
    <section class="tarjetas">
      <?php
        $fechaUltimaMetrica = $ultimaMetrica ? date('d/m/Y', strtotime($ultimaMetrica['fecha'])) : null;
        $esDeHoy = $ultimaMetrica && $ultimaMetrica['fecha'] === date('Y-m-d');
        $sufijoFecha = $ultimaMetrica ? ($esDeHoy ? ' (hoy)' : " ($fechaUltimaMetrica)") : '';
      ?>
      <div class="tarjeta semaforo-<?= $ultimaMetrica ? (str_contains($ultimaMetrica['estado_semaforo'], '🔴') ? 'rojo' : (str_contains($ultimaMetrica['estado_semaforo'], '🟡') ? 'amarillo' : 'verde')) : 'gris' ?>">
        <span class="tarjeta-label">Estado<?= htmlspecialchars($sufijoFecha) ?></span>
        <span class="tarjeta-valor"><?= $ultimaMetrica ? htmlspecialchars($ultimaMetrica['estado_semaforo']) : '⚪ Sin datos aún' ?></span>
      </div>
      <div class="tarjeta">
        <span class="tarjeta-label">ROAS<?= htmlspecialchars($sufijoFecha) ?></span>
        <span class="tarjeta-valor"><?= $ultimaMetrica ? number_format((float) $ultimaMetrica['roas_dia'], 2) . 'x' : '-' ?></span>
      </div>
      <div class="tarjeta">
        <span class="tarjeta-label">CPL<?= htmlspecialchars($sufijoFecha) ?></span>
        <span class="tarjeta-valor">$<?= $ultimaMetrica ? fmt($ultimaMetrica['cpl_dia']) : '0' ?></span>
      </div>
    </section>

    <?php if ($objetivo): ?>
    <section class="objetivo-mes">
      <h2>Objetivo del mes (<?= date('m/Y', strtotime($mes)) ?>) — real vs. objetivo</h2>
      <?php
        $diaHoy = (int) date('j');
        $diasMes = (int) date('t');
        $pacing = $esMesEnCurso && $diaHoy >= PACING_DIA_MIN;
        $proyInv = $pacing ? (float) $objetivo['inversion_real_mes'] / $diaHoy * $diasMes : null;
        $proyLeads = $pacing ? (float) $objetivo['leads_reales_mes'] / $diaHoy * $diasMes : null;
        $proyVentas = $pacing ? (float) $objetivo['ventas_reales_mes'] / $diaHoy * $diasMes : null;
      ?>
      <div class="meters">
        <?= render_meter('Presupuesto invertido', (float) $objetivo['inversion_real_mes'], (float) $objetivo['presupuesto_asignado'], 'moneda', $proyInv) ?>
        <?= render_meter('Leads generados', (float) $objetivo['leads_reales_mes'], (float) $objetivo['meta_leads'], 'numero', $proyLeads) ?>
        <?= render_meter('Ventas cerradas', (float) $objetivo['ventas_reales_mes'], (float) $objetivo['meta_ventas'], 'moneda', $proyVentas) ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($objetivo): ?>
    <?php
      $convActual = embudo_leads($pdo, $clienteId, $mes)['conversion'];
      $convAnterior = $objetivoAnterior ? embudo_leads($pdo, $clienteId, $mesAnterior)['conversion'] : null;
    ?>
    <section class="comparativa">
      <h2>Comparativa con <?= htmlspecialchars(date('m/Y', strtotime($mesAnterior))) ?></h2>
      <?php if (!$objetivoAnterior): ?>
        <p>Sin mes anterior para comparar.</p>
      <?php else: ?>
      <?php if ($esMesEnCurso): ?><p class="comparativa-nota">El mes en curso va parcial (a hoy) contra un mes anterior completo — mirá también la proyección de cierre.</p><?php endif; ?>
      <div class="comparativa-grid">
        <?= render_delta('ROAS', (float) $objetivo['roas_mes'], (float) $objetivoAnterior['roas_mes'], 'roas', true) ?>
        <?= render_delta('CPL', (float) $objetivo['cpl_mes'], (float) $objetivoAnterior['cpl_mes'], 'moneda', false) ?>
        <?= render_delta('Leads', (float) $objetivo['leads_reales_mes'], (float) $objetivoAnterior['leads_reales_mes'], 'numero', true) ?>
        <?= render_delta('Ventas', (float) $objetivo['ventas_reales_mes'], (float) $objetivoAnterior['ventas_reales_mes'], 'moneda', true) ?>
        <?= render_delta('Conversión', $convActual, $convAnterior, 'pct', true) ?>
      </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="benchmark-rubro">
      <h2>Tu rubro vs vos</h2>
      <?php $tieneDatosPropios = $objetivo !== false && (float) $objetivo['inversion_real_mes'] > 0; ?>
      <?php if ($benchmark === null || !$tieneDatosPropios): ?>
      <p class="tarjeta-sub">Todavía no hay suficientes PyMEs de tu rubro con actividad el mes seleccionado para comparar.</p>
      <?php else: ?>
      <?php
        $miCpl  = (float) $objetivo['cpl_mes'];
        $miRoas = (float) $objetivo['roas_mes'];
        $cplComparable = (float) $objetivo['leads_reales_mes'] > 0;
        $cplMejor  = $miCpl < (float) $benchmark['cpl_prom'];    // CPL: más bajo es mejor
        $roasMejor = $miRoas > (float) $benchmark['roas_prom'];  // ROAS: más alto es mejor
      ?>
      <?php if ($cplComparable): ?>
      <p><strong>CPL:</strong> tu $<?= fmt($miCpl) ?> vs promedio $<?= fmt($benchmark['cpl_prom']) ?> — <?= $cplMejor ? '🟢 mejor que el promedio' : '🔴 peor que el promedio' ?></p>
      <?php else: ?>
      <p><strong>CPL:</strong> sin leads el mes seleccionado — no comparable.</p>
      <?php endif; ?>
      <p><strong>ROAS:</strong> tu <?= number_format($miRoas, 2, ',', '.') ?>× vs promedio <?= number_format((float) $benchmark['roas_prom'], 2, ',', '.') ?>× — <?= $roasMejor ? '🟢 mejor que el promedio' : '🔴 peor que el promedio' ?></p>
      <p class="tarjeta-sub">Promedio de <?= (int) $benchmark['pares'] ?> PyMEs de tu rubro (anónimo).</p>
      <?php endif; ?>
    </section>

    <?php
      $embudo = embudo_leads($pdo, $clienteId, $mes);
      $abiertos = leads_abiertos($pdo, $clienteId);
      $sla = sla_resumen($pdo, $clienteId, $mes, $slaHoras);
    ?>
    <section class="leads">
      <h2>Leads</h2>
      <?php if ($avisoLeads): ?>
        <p class="<?= $avisoLeads['tipo'] === 'ok' ? 'alerta-ok' : 'alerta-error' ?>"><?= htmlspecialchars($avisoLeads['texto']) ?></p>
      <?php endif; ?>

      <p class="embudo">
        Embudo del mes:
        Nuevos <strong><?= (int) $embudo['conteos']['pendiente'] ?></strong> ·
        Contactados <strong><?= (int) $embudo['conteos']['contactado'] ?></strong> ·
        Negociación <strong><?= (int) $embudo['conteos']['en_negociacion'] ?></strong> ·
        Ganados <strong><?= (int) $embudo['conteos']['vendido'] ?></strong> ·
        Perdidos <strong><?= (int) $embudo['conteos']['no_vendido'] ?></strong>
        &nbsp;|&nbsp; Conversión <strong><?= number_format($embudo['conversion'] * 100, 0) ?>%</strong>
      </p>

      <p class="sla-resumen">
        Velocidad de respuesta (objetivo <?= (int) $slaHoras ?>h):
        Promedio <strong><?= $sla['promedio_horas'] !== null ? htmlspecialchars(number_format($sla['promedio_horas'], 1, ',', '.')) . 'h' : '—' ?></strong> ·
        Dentro del objetivo <strong><?= number_format($sla['pct_dentro_sla'] * 100, 0) ?>%</strong> ·
        Sin atender ahora <strong class="<?= $sla['sin_atender_ahora'] > 0 ? 'sla-alerta' : '' ?>"><?= (int) $sla['sin_atender_ahora'] ?></strong>
      </p>

      <div class="tabla-scroll">
      <table>
        <thead>
          <tr><th>Lead</th><th>Teléfono</th><th>Ingreso</th><th>Estado</th><th>Acciones</th></tr>
        </thead>
        <tbody>
          <?php foreach ($abiertos as $lead): ?>
          <?php $leadSinAtender = ($lead['fecha_primer_contacto'] === null)
              && in_array($lead['estado'], ['pendiente', 'contactado'], true)
              && ((int) $lead['horas_sin_atender'] >= $slaHoras); ?>
          <tr class="<?= $leadSinAtender ? 'lead-sla-vencido' : '' ?>">
            <td><?= htmlspecialchars($lead['nombre_lead']) ?></td>
            <td><?= htmlspecialchars($lead['telefono_lead']) ?></td>
            <td>
              <?= date('d/m/Y', strtotime($lead['fecha_ingreso'])) ?>
              <?php if ($leadSinAtender): ?><span class="sla-badge">sin atender hace <?= (int) $lead['horas_sin_atender'] ?>h</span><?php endif; ?>
            </td>
            <td><span class="badge"><?= htmlspecialchars(LEAD_ESTADO_LABELS[$lead['estado']] ?? $lead['estado']) ?></span></td>
            <td class="lead-acciones">
              <?php if ($lead['estado'] !== 'en_negociacion'): ?>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="lead_id" value="<?= (int) $lead['id'] ?>">
                <input type="hidden" name="accion" value="negociacion">
                <button type="submit" class="btn-inline">Negociando</button>
              </form>
              <?php endif; ?>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="lead_id" value="<?= (int) $lead['id'] ?>">
                <input type="hidden" name="accion" value="ganado">
                <input type="text" name="monto" inputmode="numeric" placeholder="Monto $" required>
                <button type="submit" class="btn-inline">Ganó</button>
              </form>
              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="lead_id" value="<?= (int) $lead['id'] ?>">
                <input type="hidden" name="accion" value="perdido">
                <select name="motivo" required>
                  <option value="">Motivo…</option>
                  <?php foreach (LEAD_MOTIVOS as $m): ?>
                  <option value="<?= htmlspecialchars($m) ?>"><?= htmlspecialchars($m) ?></option>
                  <?php endforeach; ?>
                </select>
                <input type="text" name="motivo_otro" maxlength="255" placeholder="Nota (si es Otro)">
                <button type="submit" class="btn-inline">Perdió</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$abiertos): ?>
          <tr><td colspan="5">No tenés leads abiertos.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      </div>
    </section>

    <?php $motivos = motivos_perdida($pdo, $clienteId, $mes); ?>
    <section class="motivos-perdida">
      <h2>Motivos de pérdida (<?= date('m/Y', strtotime($mes)) ?>)</h2>
      <?php if ($motivos['total'] === 0): ?>
        <p>No registraste pérdidas este mes.</p>
      <?php else: ?>
        <?php $maxCant = max(array_column($motivos['ranking'], 'cantidad')); ?>
        <ul class="ranking-motivos">
          <?php foreach ($motivos['ranking'] as $m): ?>
          <li>
            <span class="rm-motivo"><?= htmlspecialchars($m['motivo']) ?></span>
            <span class="rm-barra"><span class="rm-relleno" style="width: <?= $maxCant > 0 ? (int) round($m['cantidad'] / $maxCant * 100) : 0 ?>%"></span></span>
            <span class="rm-cant"><?= (int) $m['cantidad'] ?> (<?= (int) round($m['cantidad'] / $motivos['total'] * 100) ?>%)</span>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($motivos['notas_otro']): ?>
        <p class="notas-otro">Otras razones: <?= htmlspecialchars(implode(' · ', $motivos['notas_otro'])) ?></p>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <?php
      $atribucion = atribucion_por_canal($pdo, $clienteId, $mes);
      $etiquetaCanal = ['google' => 'Google', 'meta' => 'Meta'];
      // Canal con mejor ROAS del mes (solo entre los que invirtieron) — para resaltar.
      $mejorCanal = null; $mejorRoas = -1.0;
      foreach ($atribucion as $fila) {
          if ((float) $fila['inversion'] > 0 && (float) $fila['roas'] > $mejorRoas) {
              $mejorRoas = (float) $fila['roas'];
              $mejorCanal = $fila['canal'];
          }
      }
    ?>
    <section class="atribucion">
      <h2>Atribución por canal (<?= date('m/Y', strtotime($mes)) ?>)</h2>
      <p class="atribucion-nota">Las ventas se atribuyen al mes en que <strong>entró</strong> el lead, así que el total puede diferir del recuadro de ROAS del mes (que cuenta la venta cuando se cierra).</p>
      <?php if (!$atribucion): ?>
        <p>Sin datos de canal este mes.</p>
      <?php else: ?>
      <div class="tabla-scroll">
      <table>
        <thead>
          <tr><th>Canal</th><th>Inversión</th><th>Leads</th><th>Ventas</th><th>ROAS</th><th>CPL</th><th>Conversión</th></tr>
        </thead>
        <tbody>
          <?php foreach ($atribucion as $fila): ?>
          <tr<?= $fila['canal'] === $mejorCanal ? ' class="mejor-roas"' : '' ?>>
            <td><?= htmlspecialchars($etiquetaCanal[$fila['canal']] ?? $fila['canal']) ?></td>
            <td>$<?= formatear_ars((float) $fila['inversion']) ?></td>
            <td><?= (int) $fila['leads'] ?></td>
            <td>$<?= formatear_ars((float) $fila['ventas_monto']) ?> (<?= (int) $fila['ventas_cant'] ?>)</td>
            <td><?= number_format((float) $fila['roas'], 2, ',', '.') ?>×</td>
            <td>$<?= formatear_ars((float) $fila['cpl']) ?></td>
            <td><?= number_format((float) $fila['conversion'] * 100, 0) ?>%</td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </section>

    <section class="tendencia-roas">
      <h2>Evolución de ROAS (últimos <?= count($historico) ?> días)</h2>
      <div class="chart-card">
        <?= render_roas_chart($historicoAsc) ?>
      </div>
    </section>

    <section class="historico">
      <h2>Detalle diario de <?= htmlspecialchars(date('m/Y', strtotime($mes))) ?></h2>
      <div class="tabla-scroll">
      <table>
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Inversión</th>
            <th>Leads</th>
            <th>CPL</th>
            <th>Ventas</th>
            <th>ROAS</th>
            <th>Estado</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($historico as $fila): ?>
          <tr>
            <td><?= date('d/m/Y', strtotime($fila['fecha'])) ?></td>
            <td>$<?= fmt($fila['inversion_total']) ?></td>
            <td><?= (int) $fila['leads_totales'] ?></td>
            <td>$<?= fmt($fila['cpl_dia']) ?></td>
            <td>$<?= fmt($fila['monto_venta']) ?></td>
            <td><?= number_format((float) $fila['roas_dia'], 2) ?>x</td>
            <td><?= htmlspecialchars($fila['estado_semaforo']) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$historico): ?>
          <tr><td colspan="7">Todavía no hay métricas cargadas.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      </div>
    </section>

    <section class="glosario">
      <details>
        <summary>¿Qué significan ROAS, CPL y Lead?</summary>
        <dl>
          <dt>Lead</dt>
          <dd>Una persona que dejó su nombre y WhatsApp a través de un anuncio o formulario porque le interesó lo que ofrecés — todavía no es un cliente, es un contacto para que un vendedor lo llame.</dd>

          <dt>CPL — Costo Por Lead</dt>
          <dd>Cuánto cuesta en promedio conseguir un lead. Se calcula dividiendo lo invertido en publicidad ese día entre la cantidad de leads que se generaron. Un CPL más bajo es mejor: significa que cada contacto sale más barato.</dd>

          <dt>ROAS — Retorno de la Inversión Publicitaria</dt>
          <dd>Por cada $1 que se invierte en publicidad (Google Ads + Meta Ads), cuánto se generó en ventas. Un ROAS de 2x quiere decir que cada $1 invertido generó $2 en ventas. Por debajo de 1x, todavía no se recuperó lo invertido.</dd>

          <dt>Semáforo</dt>
          <dd>
            Resume de un vistazo cómo viene el día, combinando CPL y ROAS:
            🟢 Saludable (todo dentro de lo esperado) ·
            🟡 Precaución (el CPL está bien, pero el ROAS es menor a 1x) ·
            🔴 Alerta (el CPL superó el máximo definido para la cuenta) ·
            ⚪ Sin datos (no hubo leads ese día).
          </dd>
        </dl>
      </details>
    </section>
  </main>

  <style>
    /* Modal de Ayuda: CSS crítico EMBEBIDO (la página es no-cache) para que ande
       siempre, sin depender de la caché de style.css ni de la API <dialog>. */
    #ayuda-overlay { position: fixed; top: 0; right: 0; bottom: 0; left: 0; z-index: 1000; display: flex; align-items: center; justify-content: center; background: rgba(15,17,21,0.55); padding: 16px; }
    #ayuda-overlay[hidden] { display: none; }
    /* La CAJA ENTERA scrollea (overflow-y sobre max-height) — el mecanismo de scroll
       más compatible que existe, sin depender de flexbox (flex+min-height:0 falla en
       navegadores viejos). El header queda pegado con sticky; en navegadores muy
       viejos simplemente scrollea con el resto, sin romper nada. */
    #ayuda-overlay .ayuda-box { width: 560px; max-width: 92vw; max-height: 85vh; overflow-y: auto; -webkit-overflow-scrolling: touch; background: var(--card-bg, #fff); color: var(--text, #1f2430); border: 1px solid var(--border, #e5e7eb); border-radius: 14px; box-shadow: 0 20px 60px rgba(0,0,0,.25); }
    #ayuda-overlay .ayuda-header { position: sticky; top: 0; background: var(--card-bg, #fff); }
    /* Neutraliza cualquier overflow/max-height que el style.css (posiblemente viejo)
       le ponga a .ayuda-cuerpo, para que NO cree un scroll anidado y scrollee solo la caja. */
    #ayuda-overlay .ayuda-cuerpo { overflow: visible; max-height: none; }
  </style>
  <div id="ayuda-overlay" class="ayuda-overlay" hidden>
    <div class="ayuda-box" role="dialog" aria-modal="true" aria-labelledby="ayuda-titulo" tabindex="-1">
    <div class="ayuda-header">
      <h2 id="ayuda-titulo">¿Cómo usar tu panel?</h2>
      <button type="button" id="cerrar-ayuda" class="ayuda-cerrar" aria-label="Cerrar ayuda">&times;</button>
    </div>
    <div class="ayuda-cuerpo">
      <p class="ayuda-intro">Una guía rápida para leer tu panel de un vistazo. No necesitás saber de marketing: acá te explicamos qué significa cada cosa.</p>

      <section class="ayuda-seccion">
        <h3>El recorrido de un contacto</h3>
        <p>Así es como alguien pasa de ver tu anuncio a convertirse en una venta. Todo entra solo a tu panel — no tenés que cargar nada a mano:</p>
        <ol class="ayuda-flujo">
          <li><strong>Anuncio</strong> — tu campaña en Google o Instagram/Facebook llega a gente interesada.</li>
          <li><strong>Clic</strong> — una persona hace clic para saber más.</li>
          <li><strong>Deja sus datos</strong> — completa su nombre y teléfono en tu página.</li>
          <li><strong>Ya es un lead</strong> — ese contacto aparece solo en tu panel, listo para atender.</li>
          <li><strong>Venta</strong> — tu vendedor lo contacta y, cuando cierra, se registra el monto (suma al ROAS).</li>
        </ol>
      </section>

      <section class="ayuda-seccion">
        <h3>Los tres números de arriba</h3>
        <p>Resumen tu situación del día (o del último día con datos cargados):</p>
        <ul>
          <li><strong>Estado</strong> — un semáforo de un vistazo:
            <ul>
              <li>🟢 <strong>Saludable</strong>: todo dentro de lo esperado.</li>
              <li>🟡 <strong>Precaución</strong>: el costo por contacto está bien, pero todavía no se recuperó lo invertido en ventas.</li>
              <li>🔴 <strong>Alerta</strong>: el costo por contacto superó el máximo definido para tu cuenta. Si se repite 2 días seguidos, te avisamos por WhatsApp automáticamente.</li>
              <li>⚪ <strong>Sin datos</strong>: no hubo contactos nuevos ese día.</li>
            </ul>
          </li>
          <li><strong>ROAS</strong> — por cada $1 invertido en publicidad, cuánto generaste en ventas. Un ROAS de "2x" significa que cada peso te devolvió el doble.</li>
          <li><strong>CPL</strong> — cuánto te cuesta en promedio conseguir un contacto interesado (un "lead").</li>
        </ul>
      </section>

      <section class="ayuda-seccion">
        <h3>Objetivo del mes</h3>
        <p>Tres barras muestran cuánto llevás este mes frente a la meta acordada: <strong>presupuesto invertido</strong>, <strong>leads generados</strong> y <strong>ventas cerradas</strong>. La barra se pone <strong>verde</strong> cuando alcanzás o superás el objetivo.</p>
      </section>

      <section class="ayuda-seccion">
        <h3>Gráfico de ROAS</h3>
        <p>Muestra cómo evoluciona tu ROAS día a día en las últimas dos semanas. La línea horizontal marca el punto de equilibrio (ROAS = 1x): por debajo, todavía no se recuperó lo invertido ese día.</p>
      </section>

      <section class="ayuda-seccion">
        <h3>Tabla de los últimos 14 días</h3>
        <p>El detalle exacto de cada día: inversión, leads, CPL, ventas y ROAS, con su semáforo correspondiente.</p>
      </section>

      <section class="ayuda-seccion">
        <h3>Cómo se registran tus ventas</h3>
        <p>Cuando alguien deja sus datos en tu anuncio, a las 48 horas le mandamos un WhatsApp automático a tu vendedor preguntando si cerró la venta. Si responde con el <strong>monto</strong> (por ejemplo, <code>150000</code>), el sistema lo registra solo — no tenés que hacer nada manual. Eso es lo que alimenta el ROAS y las barras de objetivo.</p>
      </section>

      <p class="ayuda-pie">¿Un número no te cierra? Escribinos antes de sacar conclusiones — siempre vale confirmar que los datos se estén cargando bien.</p>
    </div>
    </div>
  </div>

  <script>
    (function () {
      var overlay = document.getElementById('ayuda-overlay');
      var abrir = document.getElementById('btn-ayuda');
      var cerrar = document.getElementById('cerrar-ayuda');
      var box = overlay ? overlay.querySelector('.ayuda-box') : null;
      if (!overlay || !abrir) return;
      function abrirAyuda() {
        overlay.hidden = false;
        document.body.style.overflow = 'hidden'; // evita el doble scroll del fondo
        if (box) box.focus();
      }
      function cerrarAyuda() {
        overlay.hidden = true;
        document.body.style.overflow = '';
      }
      abrir.addEventListener('click', abrirAyuda);
      if (cerrar) cerrar.addEventListener('click', cerrarAyuda);
      // Cerrar al clickear el fondo (fuera de la caja).
      overlay.addEventListener('click', function (e) { if (e.target === overlay) cerrarAyuda(); });
      // Cerrar con Escape.
      document.addEventListener('keydown', function (e) {
        if ((e.key === 'Escape' || e.key === 'Esc') && !overlay.hidden) cerrarAyuda();
      });
    })();
  </script>

  <script src="assets/tema-toggle.js?v=<?= @filemtime(__DIR__ . '/assets/tema-toggle.js') ?>"></script>
</body>
</html>
