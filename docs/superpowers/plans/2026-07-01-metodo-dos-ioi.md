# Método DOS® — Motor IOI® · Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir el Diagnóstico Inteligente Método DOS® — Nivel 2: un motor de scoring IOI® modular en PHP más su wizard y endpoint, integrado al sitio como el Método UNO®.

**Architecture:** Motor de lógica pura (`IOIEngine`) separado de toda la configuración tuneable (`ScoringConfig`), consumido por un endpoint PHP (`diagnostico2.php`) que valida, persiste el lead y pide el narrativo al LLM. El wizard estático postea al endpoint. El motor se testea sin HTTP, MySQL ni LLM.

**Tech Stack:** PHP 8.0+ (`declare(strict_types=1)`), MySQL (vía `db_lead.php`), API LLM compatible con OpenAI (vía `config.php`). Sin build, sin dependencias, sin framework de tests.

## Global Constraints

- **PHP 8.0+** con `declare(strict_types=1)` en todos los archivos `.php` nuevos (`usort` estable requerido).
- **Cero dependencias nuevas / cero build.** Nada de Composer, npm ni frameworks.
- **Reutilizar infraestructura existente:** `config.php`, `db_lead.php` (`infouno_save_lead`), `ratelimit.php` (`infouno_rate_check`), tabla `wp_infouno_leads`.
- **Pesos IOI®:** `A=0.35, B=0.30, C=0.20, D=0.15` (suman 1.0), en `ScoringConfig::PHASE_WEIGHTS`.
- **Constantes de costo:** `HOURLY_RATE_ARS=7000`, `WEEKS_PER_YEAR=48`.
- **Rangos de veredicto (inclusivos):** 90–100 Transformación Inmediata · 75–89 Alto Potencial · 60–74 Potencial Progresivo · 40–59 Ordenamiento Estructural · 0–39 Baja Prioridad.
- **Persistencia:** `lead_source='metodo-dos'`; desglose IOI® en `lead_message`. No tocar `lead_scoring`.
- **Idioma/tono:** español, voseo argentino, T=0.3. Bot no da precios.
- **Seguridad:** claves solo en backend; honeypot + rate-limit (bucket `diagnostico2`); escape en el render.
- **No commit/push salvo que el usuario lo pida** (regla del proyecto; anula el commit automático del skill).

---

### Task 1: Test harness + normalización de fase (`calculatePhaseScore`)

**Files:**
- Create: `metodo-dos/src/Scoring/IOIEngine.php`
- Test: `metodo-dos/tests/IOIEngineTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: `IOIEngine::calculatePhaseScore(array $responses): float` — cada `$response` es `['points'=>int|float, 'max'=>int|float]`. Devuelve `Σpoints / Σmax × 100`, o `0.0` si `Σmax <= 0`. Y el harness de tests: `check(string, mixed $expected, mixed $actual)`, `check_close(string, float $expected, float $actual, float $eps=0.001)`.

- [ ] **Step 1: Write the failing test**

Create `metodo-dos/tests/IOIEngineTest.php`:

```php
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

echo "\n$tests corridos, $failures fallos\n";
exit($failures === 0 ? 0 : 1);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php metodo-dos/tests/IOIEngineTest.php`
Expected: FAIL — error fatal `Class "IOIEngine" not found` (el archivo del motor todavía no existe).

- [ ] **Step 3: Write minimal implementation**

Create `metodo-dos/src/Scoring/IOIEngine.php`:

```php
<?php
declare(strict_types=1);

/**
 * Método DOS® — Motor IOI® (Índice de Oportunidad Infouno).
 * Lógica PURA: sin HTTP, sin MySQL, sin LLM. Testeable en aislamiento.
 */
final class IOIEngine
{
    /** Normaliza una fase a 0–100: Σpuntos / Σmáximos × 100. */
    public static function calculatePhaseScore(array $responses): float
    {
        $points = 0.0; $max = 0.0;
        foreach ($responses as $r) {
            $points += (float) ($r['points'] ?? 0);
            $max    += (float) ($r['max'] ?? 0);
        }
        if ($max <= 0.0) {
            return 0.0;
        }
        return ($points / $max) * 100.0;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php metodo-dos/tests/IOIEngineTest.php`
Expected: PASS en las 4 aserciones. Última línea: `4 corridos, 0 fallos`.

- [ ] **Step 5: Commit**

```bash
git add metodo-dos/src/Scoring/IOIEngine.php metodo-dos/tests/IOIEngineTest.php
git commit -m "feat(metodo-dos): motor IOI + normalización de fase (calculatePhaseScore)"
```

---

### Task 2: Configuración de pesos + fórmula ponderada (`computeIOI`)

**Files:**
- Create: `metodo-dos/src/Scoring/ScoringConfig.php`
- Modify: `metodo-dos/src/Scoring/IOIEngine.php`
- Test: `metodo-dos/tests/IOIEngineTest.php`

**Interfaces:**
- Consumes: `IOIEngine::calculatePhaseScore` (Task 1).
- Produces: `ScoringConfig::PHASE_WEIGHTS` (`['A'=>0.35,'B'=>0.30,'C'=>0.20,'D'=>0.15]`); `IOIEngine::computeIOI(array $phases): float` — recibe scores normalizados `['A'=>float,'B'=>float,'C'=>float,'D'=>float]` y devuelve el IOI® crudo (0–100, **sin** redondear). El redondeo a entero ocurre en `diagnose()` (Task 6).

- [ ] **Step 1: Write the failing test**

Append to `metodo-dos/tests/IOIEngineTest.php` (antes de la línea `echo "\n$tests corridos...`):

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php metodo-dos/tests/IOIEngineTest.php`
Expected: FAIL — error fatal `Call to undefined method IOIEngine::computeIOI()`.

- [ ] **Step 3: Write minimal implementation**

Create `metodo-dos/src/Scoring/ScoringConfig.php`:

```php
<?php
declare(strict_types=1);

/**
 * Método DOS® — Toda la configuración tuneable del scoring IOI®.
 * Cambiar pesos, valor/hora, semanas o rangos NO requiere tocar IOIEngine.
 */
final class ScoringConfig
{
    /** Pesos de las 4 fases del IOI®. Suman 1.0. */
    public const PHASE_WEIGHTS = ['A' => 0.35, 'B' => 0.30, 'C' => 0.20, 'D' => 0.15];
}
```

Add `require_once` for the config at the top of `IOIEngine.php` (after `declare`):

```php
require_once __DIR__ . '/ScoringConfig.php';
```

Add the method inside `IOIEngine` (after `calculatePhaseScore`):

```php
    /** Aplica la fórmula ponderada. Devuelve el IOI® crudo (0–100, sin redondear). */
    public static function computeIOI(array $phases): float
    {
        $ioi = 0.0;
        foreach (ScoringConfig::PHASE_WEIGHTS as $key => $weight) {
            $ioi += ((float) ($phases[$key] ?? 0.0)) * $weight;
        }
        return $ioi;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php metodo-dos/tests/IOIEngineTest.php`
Expected: PASS. `7 corridos, 0 fallos`.

- [ ] **Step 5: Commit**

```bash
git add metodo-dos/src/Scoring/ScoringConfig.php metodo-dos/src/Scoring/IOIEngine.php metodo-dos/tests/IOIEngineTest.php
git commit -m "feat(metodo-dos): pesos configurables + fórmula ponderada (computeIOI)"
```

---

### Task 3: Costo de inacción (`calculateLoss`)

**Files:**
- Modify: `metodo-dos/src/Scoring/ScoringConfig.php`, `metodo-dos/src/Scoring/IOIEngine.php`
- Test: `metodo-dos/tests/IOIEngineTest.php`

**Interfaces:**
- Consumes: `ScoringConfig` (Task 2).
- Produces: `ScoringConfig::HOURLY_RATE_ARS` (7000), `ScoringConfig::WEEKS_PER_YEAR` (48); `IOIEngine::calculateLoss(float $hoursPerWeek): int` — costo anual ARS = `hoursPerWeek × WEEKS_PER_YEAR × HOURLY_RATE_ARS`, redondeado a entero.

- [ ] **Step 1: Write the failing test**

Append to `metodo-dos/tests/IOIEngineTest.php` (antes del `echo` final):

```php
// --- calculateLoss ---
check('loss: 12h/sem → 12 × 48 × 7000 = 4.032.000',
    4032000,
    IOIEngine::calculateLoss(12.0));

check('loss: 0h → 0',
    0,
    IOIEngine::calculateLoss(0.0));
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php metodo-dos/tests/IOIEngineTest.php`
Expected: FAIL — `Call to undefined method IOIEngine::calculateLoss()`.

- [ ] **Step 3: Write minimal implementation**

Add to `ScoringConfig`:

```php
    /** Valor/hora administrativo-operativo AR (ARS). Configurable. */
    public const HOURLY_RATE_ARS = 7000;

    /** Semanas laborales al año (descuenta vacaciones/feriados). */
    public const WEEKS_PER_YEAR = 48;
```

Add to `IOIEngine` (after `computeIOI`):

```php
    /** Costo anual de la ineficiencia (ARS). */
    public static function calculateLoss(float $hoursPerWeek): int
    {
        return (int) round($hoursPerWeek * ScoringConfig::WEEKS_PER_YEAR * ScoringConfig::HOURLY_RATE_ARS);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php metodo-dos/tests/IOIEngineTest.php`
Expected: PASS. `9 corridos, 0 fallos`.

- [ ] **Step 5: Commit**

```bash
git add metodo-dos/src/Scoring/ScoringConfig.php metodo-dos/src/Scoring/IOIEngine.php metodo-dos/tests/IOIEngineTest.php
git commit -m "feat(metodo-dos): calculadora del dolor (calculateLoss, 48 sem × 7000 ARS)"
```

---

### Task 4: Veredicto por rangos inclusivos (`resolveVerdict`)

**Files:**
- Modify: `metodo-dos/src/Scoring/ScoringConfig.php`, `metodo-dos/src/Scoring/IOIEngine.php`
- Test: `metodo-dos/tests/IOIEngineTest.php`

**Interfaces:**
- Consumes: `ScoringConfig` (Task 2).
- Produces: `ScoringConfig::VERDICT_RANGES` (lista descendente de `['min'=>int,'max'=>int,'titulo'=>string,'mensaje'=>string]`); `IOIEngine::resolveVerdict(float $ioi): array` → `['rango'=>string,'titulo'=>string,'mensaje'=>string]`. Redondea el IOI® a entero y devuelve el primer rango cuyo `min <=` ese entero.

- [ ] **Step 1: Write the failing test**

Append to `metodo-dos/tests/IOIEngineTest.php` (antes del `echo` final):

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php metodo-dos/tests/IOIEngineTest.php`
Expected: FAIL — `Call to undefined method IOIEngine::resolveVerdict()`.

- [ ] **Step 3: Write minimal implementation**

Add to `ScoringConfig`:

```php
    /** Rangos de veredicto, inclusivos, ordenados de mayor a menor. */
    public const VERDICT_RANGES = [
        ['min' => 90, 'max' => 100, 'titulo' => 'Transformación Inmediata',
         'mensaje' => 'Tu operación está madura para transformarse ya. El retorno de automatizar es inmediato.'],
        ['min' => 75, 'max' => 89, 'titulo' => 'Alto Potencial',
         'mensaje' => 'Hay un encaje fuerte. Con foco en los puntos críticos, el impacto llega rápido.'],
        ['min' => 60, 'max' => 74, 'titulo' => 'Potencial Progresivo',
         'mensaje' => 'Buen potencial por etapas. Conviene priorizar y avanzar de forma progresiva.'],
        ['min' => 40, 'max' => 59, 'titulo' => 'Ordenamiento Estructural',
         'mensaje' => 'Primero conviene ordenar procesos base antes de escalar con automatización.'],
        ['min' => 0, 'max' => 39, 'titulo' => 'Baja Prioridad',
         'mensaje' => 'Hoy el margen de mejora por automatización es acotado. Revisemos en unos meses.'],
    ];
```

Add to `IOIEngine` (after `calculateLoss`):

```php
    /** Veredicto por rango inclusivo. Redondea el IOI® a entero antes de comparar. */
    public static function resolveVerdict(float $ioi): array
    {
        $score = (int) round($ioi);
        foreach (ScoringConfig::VERDICT_RANGES as $range) {
            if ($score >= $range['min']) {
                return ['rango' => $range['titulo'], 'titulo' => $range['titulo'], 'mensaje' => $range['mensaje']];
            }
        }
        $last = ScoringConfig::VERDICT_RANGES[count(ScoringConfig::VERDICT_RANGES) - 1];
        return ['rango' => $last['titulo'], 'titulo' => $last['titulo'], 'mensaje' => $last['mensaje']];
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php metodo-dos/tests/IOIEngineTest.php`
Expected: PASS. `20 corridos, 0 fallos`.

- [ ] **Step 5: Commit**

```bash
git add metodo-dos/src/Scoring/ScoringConfig.php metodo-dos/src/Scoring/IOIEngine.php metodo-dos/tests/IOIEngineTest.php
git commit -m "feat(metodo-dos): veredicto por rangos inclusivos (resolveVerdict)"
```

---

### Task 5: Detección determinística de puntos críticos (`detectCriticalPoints`)

**Files:**
- Modify: `metodo-dos/src/Scoring/IOIEngine.php`
- Test: `metodo-dos/tests/IOIEngineTest.php`

**Interfaces:**
- Consumes: nada.
- Produces: `IOIEngine::detectCriticalPoints(array $items): array` — cada `$item` es `['label'=>string,'score'=>float,'max'=>float]`. Ordena ascendente por ratio `score/max` (mayor brecha primero; `max<=0` cuenta como ratio 0.0) y devuelve las **3** `label` de peor ratio. `usort` estable (PHP 8.0+) → los empates conservan el orden de entrada.

- [ ] **Step 1: Write the failing test**

Append to `metodo-dos/tests/IOIEngineTest.php` (antes del `echo` final):

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php metodo-dos/tests/IOIEngineTest.php`
Expected: FAIL — `Call to undefined method IOIEngine::detectCriticalPoints()`.

- [ ] **Step 3: Write minimal implementation**

Add to `IOIEngine` (after `resolveVerdict`):

```php
    /** Los 3 ítems de mayor brecha (peor score/max). Determinístico y estable. */
    public static function detectCriticalPoints(array $items): array
    {
        usort($items, static function (array $a, array $b): int {
            $ra = ((float) $a['max']) > 0 ? (float) $a['score'] / (float) $a['max'] : 0.0;
            $rb = ((float) $b['max']) > 0 ? (float) $b['score'] / (float) $b['max'] : 0.0;
            return $ra <=> $rb;
        });
        return array_map(
            static fn(array $i): string => (string) $i['label'],
            array_slice($items, 0, 3)
        );
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php metodo-dos/tests/IOIEngineTest.php`
Expected: PASS. `22 corridos, 0 fallos`.

- [ ] **Step 5: Commit**

```bash
git add metodo-dos/src/Scoring/IOIEngine.php metodo-dos/tests/IOIEngineTest.php
git commit -m "feat(metodo-dos): detección determinística de 3 puntos críticos"
```

---

### Task 6: Orquestador del diagnóstico (`diagnose`)

**Files:**
- Modify: `metodo-dos/src/Scoring/IOIEngine.php`
- Test: `metodo-dos/tests/IOIEngineTest.php`

**Interfaces:**
- Consumes: todos los métodos anteriores.
- Produces: `IOIEngine::diagnose(array $input): array`. `$input = ['phases'=>['A'=>[responses],'B'=>[...],'C'=>[...],'D'=>[...]], 'hours_per_week'=>float, 'critical_items'=>[['label','score','max'],...]]`. Devuelve el JSON de salida (§7 del spec): `ioi_final` (int), `veredicto` (array), `costo_inaccion` (`horas_semanales`,`anual_ars`), `puntos_criticos` (3 labels), `fases` (`['A'=>int,...]`).

- [ ] **Step 1: Write the failing test**

Append to `metodo-dos/tests/IOIEngineTest.php` (antes del `echo` final):

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php metodo-dos/tests/IOIEngineTest.php`
Expected: FAIL — `Call to undefined method IOIEngine::diagnose()`.

- [ ] **Step 3: Write minimal implementation**

Add to `IOIEngine` (after `detectCriticalPoints`):

```php
    /** Orquesta el diagnóstico completo. Determinístico: no llama al LLM. */
    public static function diagnose(array $input): array
    {
        $phaseScores = [];
        foreach (['A', 'B', 'C', 'D'] as $key) {
            $phaseScores[$key] = self::calculatePhaseScore($input['phases'][$key] ?? []);
        }
        $ioiRaw   = self::computeIOI($phaseScores);
        $hours    = (float) ($input['hours_per_week'] ?? 0.0);

        return [
            'ioi_final'      => (int) round($ioiRaw),
            'veredicto'      => self::resolveVerdict($ioiRaw),
            'costo_inaccion' => [
                'horas_semanales' => $hours,
                'anual_ars'       => self::calculateLoss($hours),
            ],
            'puntos_criticos' => self::detectCriticalPoints($input['critical_items'] ?? []),
            'fases'           => array_map(
                static fn(float $s): int => (int) round($s),
                $phaseScores
            ),
        ];
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php metodo-dos/tests/IOIEngineTest.php`
Expected: PASS. `28 corridos, 0 fallos`.

- [ ] **Step 5: Commit**

```bash
git add metodo-dos/src/Scoring/IOIEngine.php metodo-dos/tests/IOIEngineTest.php
git commit -m "feat(metodo-dos): orquestador diagnose() que ensambla el JSON de salida"
```

---

### Task 7: Endpoint del diagnóstico (`diagnostico2.php`)

**Files:**
- Create: `metodo-dos/public/diagnostico2.php`
- Reference (no modificar): `metodo-uno/public/diagnostico.php` (plantilla), `db_lead.php` (`infouno_save_lead`, `s()`), `ratelimit.php` (`infouno_rate_check`), `config.php`.

**Interfaces:**
- Consumes: `IOIEngine::diagnose` (Task 6); `infouno_save_lead($cfg, array): array`; `infouno_rate_check($cfg, ['bucket'=>'diagnostico2']): array`; `s($v,$max): string`.
- Produces: respuesta JSON con `ioi_final`, `veredicto`, `costo_inaccion`, `fases` (del motor) + `narrativo` (texto del LLM) + `puntos_criticos` (redactados por el LLM). El wizard (Task 8) la consume.

- [ ] **Step 1: Write the endpoint**

Create `metodo-dos/public/diagnostico2.php`. Espeja el patrón de `metodo-uno/public/diagnostico.php` (rate-limit → honeypot → validación → persistir lead → LLM → responder), pero calculando el IOI® **antes** de persistir y pasándoselo al LLM para el narrativo:

```php
<?php
/* =====================================================================
   Infouno — Método DOS® Nivel 2: diagnóstico IOI® (PHP)
   IOI® determinístico (IOIEngine) + persistencia (db_lead.php) + narrativo LLM.
   Misma infraestructura que el Método UNO®. Sirve en DonWeb/cPanel sin Node.
   ===================================================================== */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['error' => 'method']);
  exit;
}

try {
  $cfg = require __DIR__ . '/../../config.php';

  require_once __DIR__ . '/../../ratelimit.php';
  $rl = infouno_rate_check($cfg, ['bucket' => 'diagnostico2']);
  if (!$rl['ok']) {
    http_response_code(429);
    echo json_encode(['error' => 'Estamos recibiendo muchas solicitudes. Probá de nuevo en un minuto.']);
    exit;
  }

  $d = json_decode(file_get_contents('php://input'), true);
  if (!is_array($d)) {
    http_response_code(400);
    echo json_encode(['error' => 'json']);
    exit;
  }

  // Honeypot: bot → OK genérico sin guardar ni llamar al LLM.
  if (!empty($d['hp'])) {
    echo json_encode(['ok' => true]);
    exit;
  }

  // Validación mínima (espejo del wizard).
  foreach (['empresa', 'contacto', 'email'] as $f) {
    if (empty($d[$f]) || !is_string($d[$f]) || trim($d[$f]) === '') {
      http_response_code(400);
      echo json_encode(['error' => "Campo requerido faltante: $f"]);
      exit;
    }
  }

  // 1) IOI® determinístico (no depende del LLM).
  require_once __DIR__ . '/../src/Scoring/IOIEngine.php';
  $result = IOIEngine::diagnose([
    'phases'         => $d['phases']         ?? [],
    'hours_per_week' => (float) ($d['hours_per_week'] ?? 0),
    'critical_items' => $d['critical_items'] ?? [],
  ]);

  // 2) Persistir el lead ANTES del LLM (no se pierde aunque el LLM falle).
  require_once __DIR__ . '/../../db_lead.php';
  $session = s($d['session_id'] ?? '', 64);
  if ($session === '') $session = 'metodo2-' . bin2hex(random_bytes(8));

  $resumen = 'IOI® ' . $result['ioi_final'] . '/100 · ' . $result['veredicto']['titulo']
    . ' · Costo inacción anual: $' . number_format((float) $result['costo_inaccion']['anual_ars'], 0, ',', '.') . ' ARS'
    . ' · Fases A/B/C/D: ' . implode('/', [$result['fases']['A'], $result['fases']['B'], $result['fases']['C'], $result['fases']['D']])
    . ' · Puntos críticos: ' . implode(', ', $result['puntos_criticos']);

  @infouno_save_lead($cfg, [
    'session_id' => $session,
    'source'     => 'metodo-dos',
    'name'       => $d['contacto'] ?? '',
    'empresa'    => $d['empresa']  ?? '',
    'rubro'      => $d['rubro']    ?? '',
    'email'      => $d['email']    ?? '',
    'whatsapp'   => $d['telefono'] ?? '',
    'mensaje'    => $resumen,
    'page'       => 'metodo-dos/public/metodo-dos-nivel2.html',
  ]);

  // 3) Narrativo con el LLM (redacta sobre números YA calculados; no puntúa).
  $narrativo = infouno_dos_llm($cfg, $result, $d);

  // 4) Responder (el motor manda; el LLM solo enriquece).
  echo json_encode([
    'ioi_final'       => $result['ioi_final'],
    'veredicto'       => $result['veredicto'],
    'costo_inaccion'  => $result['costo_inaccion'],
    'fases'           => $result['fases'],
    'puntos_criticos' => $result['puntos_criticos'],
    'narrativo'       => $narrativo, // puede ser null si el LLM falló; el front usa el veredicto igual
  ]);

} catch (\Throwable $e) {
  error_log('infouno metodo-dos: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['error' => 'Hubo un problema al procesar el diagnóstico. Recibimos tus datos igual.']);
}

/* ===================================================================== */

/** Pide al LLM el narrativo del veredicto sobre los números ya calculados. */
function infouno_dos_llm(array $cfg, array $result, array $d): ?string {
  if (empty($cfg['openai_key'])) return null;
  $endpoint = !empty($cfg['api_base']) ? $cfg['api_base'] : 'https://api.openai.com/v1/chat/completions';

  $system = "Sos un consultor senior de Infouno, agencia argentina de estrategia digital y automatización para PyMEs.\n"
    . "Ya calculamos el diagnóstico Método DOS® (IOI®). NO recalcules ni cambies los números: redactás el análisis sobre ellos.\n"
    . "Devolvé un texto de máximo 250 palabras, tono directo y profesional, voseo argentino, sin tecnicismos, sin dar precios.\n"
    . "Integrá: qué significa el IOI® y su veredicto, el costo de la inacción, y desarrollá los 3 puntos críticos como próximos pasos accionables.";

  $user = "IOI® final: {$result['ioi_final']}/100\n"
    . "Veredicto: {$result['veredicto']['titulo']}\n"
    . "Costo de inacción anual: {$result['costo_inaccion']['anual_ars']} ARS ({$result['costo_inaccion']['horas_semanales']} h/semana perdidas)\n"
    . "Scores por fase (A Dolor / B Volumen / C Madurez inversa / D Intención): "
    . "{$result['fases']['A']}/{$result['fases']['B']}/{$result['fases']['C']}/{$result['fases']['D']}\n"
    . "3 puntos críticos (mayor brecha): " . implode(', ', $result['puntos_criticos']) . "\n"
    . "Empresa: " . ($d['empresa'] ?? '') . " · Rubro: " . ($d['rubro'] ?? '');

  $payload = json_encode([
    'model'       => $cfg['openai_model'] ?? 'gpt-4o-mini',
    'temperature' => 0.3,
    'max_tokens'  => 4096,
    'messages'    => [
      ['role' => 'system', 'content' => $system],
      ['role' => 'user',   'content' => $user],
    ],
  ]);

  $ch = curl_init($endpoint);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['openai_key']],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 30,
  ]);
  $raw  = curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($raw === false || $code !== 200) {
    error_log('infouno metodo-dos LLM: code=' . $code);
    return null;
  }
  $data = json_decode($raw, true);
  $text = $data['choices'][0]['message']['content'] ?? '';
  return (is_string($text) && trim($text) !== '') ? trim($text) : null;
}
```

- [ ] **Step 2: Verify PHP syntax**

Run: `php -l metodo-dos/public/diagnostico2.php`
Expected: `No syntax errors detected in metodo-dos/public/diagnostico2.php`.

- [ ] **Step 3: Smoke-test end-to-end (con `config.php` válido)**

Con el server local corriendo desde la raíz (`php -S localhost:8000`), enviá un POST:

```bash
curl -s -X POST http://localhost:8000/metodo-dos/public/diagnostico2.php \
  -H 'Content-Type: application/json' \
  -d '{"empresa":"Test SA","contacto":"Ana","email":"ana@test.com","rubro":"retail","hours_per_week":12,
       "phases":{"A":[{"points":88,"max":100}],"B":[{"points":75,"max":100}],"C":[{"points":90,"max":100}],"D":[{"points":60,"max":100}]},
       "critical_items":[{"label":"Canales","score":2,"max":10},{"label":"CRM","score":9,"max":10},{"label":"Procesos","score":4,"max":10},{"label":"Errores","score":3,"max":10}]}'
```

Expected: JSON con `"ioi_final":80`, `"titulo":"Alto Potencial"`, `"anual_ars":4032000`, `puntos_criticos` con 3 labels, y `narrativo` (texto si hay LLM configurado, o `null` si no). Verificá además que se creó/actualizó un lead con `lead_source='metodo-dos'` en `wp_infouno_leads`.

- [ ] **Step 4: Commit**

```bash
git add metodo-dos/public/diagnostico2.php
git commit -m "feat(metodo-dos): endpoint diagnostico2.php (IOI + persistencia + narrativo LLM)"
```

---

### Task 8: Wizard de 4 fases (`metodo-dos-nivel2.html`)

**Files:**
- Create: `metodo-dos/public/metodo-dos-nivel2.html`
- Reference (no modificar): `metodo-uno/public/metodo-uno-nivel1.html` (plantilla de estructura/estilo del wizard).

**Interfaces:**
- Consumes: `diagnostico2.php` (Task 7). Postea `{empresa, contacto, email, rubro, telefono, hours_per_week, phases:{A,B,C,D}, critical_items, session_id, hp}` y renderiza `ioi_final`, `veredicto`, `costo_inaccion`, `puntos_criticos`, `narrativo`.
- Produces: nada consumido por tareas posteriores.

- [ ] **Step 1: Build the wizard**

Create `metodo-dos/public/metodo-dos-nivel2.html` espejando la estructura de `metodo-uno/public/metodo-uno-nivel1.html` (mismo estilo visual, JS inline, misma nota de consentimiento Ley 25.326). Requisitos concretos:

1. **4 pasos = 4 fases** (A Dolor/Ineficiencia · B Volumen/Escala · C Madurez digital · D Intención/Acción), más un paso de datos de contacto (empresa, contacto, email, teléfono, rubro).
2. Cada pregunta de opción declara su valor de puntos (`data-points`) y el máximo de la fase se calcula sumando el `data-points` máximo de cada pregunta. El JS arma `phases:{A:[{points,max},...],...}`.
3. **Fase A** incluye la pregunta "¿Cuántas horas por semana estimás que se pierden en tareas repetitivas?" → alimenta `hours_per_week` (número directo, no puntos-only).
4. `critical_items`: por cada sub-área evaluable, empujá `{label, score, max}` con el label legible (p. ej. "Automatización de canales", "Errores/reprocesos"). El motor elige las 3 peores.
5. **Honeypot** `hp` (input oculto) y `session_id` (generá uno con `crypto.randomUUID()` o timestamp) en el POST.
6. **Nota de consentimiento visible** antes de enviar datos (Ley 25.326), igual que el resto del sitio; link a `../../privacidad.html`.
7. Al recibir la respuesta, renderizá: número IOI® grande, título+mensaje del veredicto, costo de inacción formateado en ARS, los 3 puntos críticos y el `narrativo` del LLM (si vino `null`, mostrá solo el veredicto/mensaje). Usá `textContent` (no `innerHTML`) para todo lo que venga del backend — regla XSS del proyecto.

- [ ] **Step 2: Verify it renders**

Con `php -S localhost:8000` desde la raíz, abrí `http://localhost:8000/metodo-dos/public/metodo-dos-nivel2.html`. Recorré las 4 fases + contacto, enviá y confirmá que se muestran IOI®, veredicto, costo y puntos críticos. Sin PHP el form se ve pero el envío no responde (comportamiento esperado, igual que UNO).

- [ ] **Step 3: Commit**

```bash
git add metodo-dos/public/metodo-dos-nivel2.html
git commit -m "feat(metodo-dos): wizard de 4 fases del diagnóstico Método DOS"
```

---

### Task 9: README + integración documental y de navegación

**Files:**
- Create: `metodo-dos/README.md`
- Modify: `sitemap.xml`, `robots.txt` (si bloquea `diagnostico2.php` como a `diagnostico.php`), `index.html` (nav/CTA hacia el Método DOS, espejo del enlace al Método UNO), `ai/context-loader.md` y `ai/analysis.md` (mapa de archivos), `CLAUDE.md` (fila del mapa rápido).

**Interfaces:**
- Consumes: todo lo anterior.
- Produces: documentación y enlaces; nada de código.

- [ ] **Step 1: Write the README**

Create `metodo-dos/README.md` espejando `metodo-uno/README.md`: qué es, diagrama de flujo (`metodo-dos-nivel2.html → diagnostico2.php → IOIEngine + db_lead + LLM`), requisitos (PHP+MySQL, `config.php`), cómo probar en local, y la tabla de archivos (`public/`, `src/Scoring/`, `tests/`). Documentá que el motor se testea con `php metodo-dos/tests/IOIEngineTest.php` y que los pesos/valor-hora/rangos se tunean en `ScoringConfig.php`.

- [ ] **Step 2: Wire navigation + SEO**

- Agregá la URL del wizard a `sitemap.xml` (espejo de la entrada del Método UNO).
- En `robots.txt`, si `diagnostico.php` está bloqueado al rastreo, bloqueá también `diagnostico2.php` (es backend, no debe indexarse).
- En `index.html`, agregá el enlace/CTA al Método DOS® donde hoy figura el del Método UNO® (nav y/o sección de CTA), con copy propio ("Diagnóstico Inteligente Nivel 2 · IOI®").

- [ ] **Step 3: Update `ai/` docs (protocolo del proyecto)**

Actualizá en el **mismo cambio** (evita deriva de documentación):
- `ai/context-loader.md` (Paso 3, Mapa de Archivos): filas de `metodo-dos/public/*` y `metodo-dos/src/Scoring/*`.
- `ai/analysis.md`: mencioná el Método DOS® en la estructura del repo y, si corresponde, en el roadmap.
- `CLAUDE.md`: fila en el "Mapa rápido" para `metodo-dos/`.

- [ ] **Step 4: Verify links**

Run: `php -S localhost:8000` y verificá desde `index.html` que el CTA lleva al wizard del Método DOS®, y que `sitemap.xml` valida (XML bien formado): `php -r "echo simplexml_load_file('sitemap.xml') ? 'OK\n' : 'MAL\n';"`
Expected: `OK` y navegación funcional.

- [ ] **Step 5: Commit**

```bash
git add metodo-dos/README.md sitemap.xml robots.txt index.html ai/context-loader.md ai/analysis.md CLAUDE.md
git commit -m "docs(metodo-dos): README, navegación, SEO y actualización de docs ai/"
```

---

## Self-Review (hecha)

**Cobertura del spec:**
- §3 Arquitectura de archivos → Tasks 1–9 crean exactamente esa estructura. ✓
- §4 Las 4 fases → Task 8 (wizard) las materializa; el motor es agnóstico. ✓
- §5 Motor (5 funciones + diagnose) → Tasks 1–6, una función por task con TDD. ✓
- §6 Veredicto rangos inclusivos → Task 4, con test de los 10 bordes. ✓
- §7 JSON de salida → Task 6 (`diagnose`) + Task 7 (endpoint lo emite con `narrativo`). ✓
- §8 Flujo del endpoint (persistir antes del LLM) → Task 7, orden explícito. ✓
- §9 Testing → harness en Task 1, extendido en 2–6. ✓
- §10 Alcance/YAGNI → sin columna IOI dedicada, sin orquestación; respetado. ✓
- §11 Restricciones (SEO/seguridad/trazabilidad/privacidad/idioma) → Tasks 7–9. ✓

**Placeholders:** ninguno; todo el código del motor va completo. Los Tasks 7–8 (endpoint/wizard) llevan código completo del endpoint y requisitos concretos del wizard (el HTML del wizard se construye espejando la plantilla existente, con la lista exacta de campos/pasos).

**Consistencia de tipos/nombres:** `calculatePhaseScore`, `computeIOI`, `calculateLoss`, `resolveVerdict`, `detectCriticalPoints`, `diagnose` — nombres idénticos en firmas, tests y endpoint. Claves del JSON (`ioi_final`, `veredicto`, `costo_inaccion`, `puntos_criticos`, `fases`, `narrativo`) consistentes entre Task 6, 7 y 8. `ScoringConfig::PHASE_WEIGHTS / HOURLY_RATE_ARS / WEEKS_PER_YEAR / VERDICT_RANGES` consistentes.
```
