<?php
declare(strict_types=1);

require_once __DIR__ . '/ScoringConfig.php';

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

    /** Aplica la fórmula ponderada. Devuelve el IOI® crudo (0–100, sin redondear). */
    public static function computeIOI(array $phases): float
    {
        $ioi = 0.0;
        foreach (ScoringConfig::PHASE_WEIGHTS as $key => $weight) {
            $ioi += ((float) ($phases[$key] ?? 0.0)) * $weight;
        }
        return $ioi;
    }

    /** Costo anual de la ineficiencia (ARS). */
    public static function calculateLoss(float $hoursPerWeek): int
    {
        return (int) round($hoursPerWeek * ScoringConfig::WEEKS_PER_YEAR * ScoringConfig::HOURLY_RATE_ARS);
    }

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
}
