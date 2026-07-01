<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Scoring/IOIEngine.php';

$tests = 0; $failures = 0;

function check(string $name, $expected, $actual): void {
    global $tests, $failures;
    $tests++;
    if ($expected === $actual) { echo "PASS: $name\n"; return; }
    $failures++;
    echo "FAIL: $name — esperado " . var_export($expected, true) . ", obtuvo " . var_export($actual, true) . "\n";
}

function check_close(string $name, float $expected, float $actual, float $eps = 0.001): void {
    global $tests, $failures;
    $tests++;
    if (abs($expected - $actual) < $eps) { echo "PASS: $name\n"; return; }
    $failures++;
    echo "FAIL: $name — esperado ~$expected, obtuvo $actual\n";
}

// --- calculatePhaseScore ---
check_close('phase: 3 respuestas perfectas → 100',
    100.0,
    IOIEngine::calculatePhaseScore([
        ['points' => 5, 'max' => 5],
        ['points' => 3, 'max' => 3],
        ['points' => 2, 'max' => 2],
    ]));

check_close('phase: mitad de puntos → 50',
    50.0,
    IOIEngine::calculatePhaseScore([
        ['points' => 5, 'max' => 10],
        ['points' => 0, 'max' => 0],
    ]));

check_close('phase: distinta cantidad de preguntas normaliza igual',
    75.0,
    IOIEngine::calculatePhaseScore([
        ['points' => 3, 'max' => 4],
        ['points' => 3, 'max' => 4],
    ]));

check('phase: máximo 0 → 0.0 (sin división por cero)',
    0.0,
    IOIEngine::calculatePhaseScore([['points' => 0, 'max' => 0]]));

// --- computeIOI ---
check_close('ioi: caso conocido 88/75/90/60 → 80.3',
    80.3,
    IOIEngine::computeIOI(['A' => 88.0, 'B' => 75.0, 'C' => 90.0, 'D' => 60.0]));

check_close('ioi: todo 100 → 100',
    100.0,
    IOIEngine::computeIOI(['A' => 100.0, 'B' => 100.0, 'C' => 100.0, 'D' => 100.0]));

check_close('ioi: todo 0 → 0',
    0.0,
    IOIEngine::computeIOI(['A' => 0.0, 'B' => 0.0, 'C' => 0.0, 'D' => 0.0]));

// --- calculateLoss ---
check('loss: 12h/sem → 12 × 48 × 7000 = 4.032.000',
    4032000,
    IOIEngine::calculateLoss(12.0));

check('loss: 0h → 0',
    0,
    IOIEngine::calculateLoss(0.0));

// --- resolveVerdict (bordes inclusivos) ---
$vt = static fn(float $x): string => IOIEngine::resolveVerdict($x)['titulo'];
check('verdict: 100 → Transformación Inmediata', 'Transformación Inmediata', $vt(100.0));
check('verdict: 90 → Transformación Inmediata',  'Transformación Inmediata', $vt(90.0));
check('verdict: 89 → Alto Potencial',            'Alto Potencial',            $vt(89.0));
check('verdict: 80.3 redondea a 80 → Alto Potencial', 'Alto Potencial',       $vt(80.3));
check('verdict: 75 → Alto Potencial',            'Alto Potencial',            $vt(75.0));
check('verdict: 74 → Potencial Progresivo',      'Potencial Progresivo',      $vt(74.0));
check('verdict: 60 → Potencial Progresivo',      'Potencial Progresivo',      $vt(60.0));
check('verdict: 59 → Ordenamiento Estructural',  'Ordenamiento Estructural',  $vt(59.0));
check('verdict: 40 → Ordenamiento Estructural',  'Ordenamiento Estructural',  $vt(40.0));
check('verdict: 39 → Baja Prioridad',            'Baja Prioridad',            $vt(39.0));
check('verdict: 0 → Baja Prioridad',             'Baja Prioridad',            $vt(0.0));

// --- detectCriticalPoints ---
$crit = IOIEngine::detectCriticalPoints([
    ['label' => 'Canales', 'score' => 2, 'max' => 10],   // 0.20  (peor)
    ['label' => 'CRM',     'score' => 9, 'max' => 10],   // 0.90
    ['label' => 'Procesos','score' => 4, 'max' => 10],   // 0.40
    ['label' => 'Errores', 'score' => 3, 'max' => 10],   // 0.30
]);
check('critical: devuelve exactamente 3', 3, count($crit));
check('critical: ordenados por mayor brecha',
    ['Canales', 'Errores', 'Procesos'],
    $crit);

// --- diagnose (integración del motor) ---
$out = IOIEngine::diagnose([
    'phases' => [
        'A' => [['points' => 88, 'max' => 100]],
        'B' => [['points' => 75, 'max' => 100]],
        'C' => [['points' => 90, 'max' => 100]],
        'D' => [['points' => 60, 'max' => 100]],
    ],
    'hours_per_week' => 12.0,
    'critical_items' => [
        ['label' => 'B', 'score' => 75, 'max' => 100],
        ['label' => 'D', 'score' => 60, 'max' => 100],
        ['label' => 'A', 'score' => 88, 'max' => 100],
        ['label' => 'C', 'score' => 90, 'max' => 100],
    ],
]);
check('diagnose: ioi_final = 80', 80, $out['ioi_final']);
check('diagnose: veredicto Alto Potencial', 'Alto Potencial', $out['veredicto']['titulo']);
check('diagnose: costo anual 4.032.000', 4032000, $out['costo_inaccion']['anual_ars']);
check('diagnose: 3 puntos críticos', 3, count($out['puntos_criticos']));
check('diagnose: peor fase primero (D)', 'D', $out['puntos_criticos'][0]);
check('diagnose: fases normalizadas', ['A' => 88, 'B' => 75, 'C' => 90, 'D' => 60], $out['fases']);

echo "\n$tests corridos, $failures fallos\n";
exit($failures === 0 ? 0 : 1);
