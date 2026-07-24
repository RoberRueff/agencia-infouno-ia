<?php

/**
 * Decide cómo mostrar la comparación de una métrica del reporte mensual entre
 * el mes del reporte y el mes anterior. Devuelve la flecha (↑/↓/→), la clase
 * CSS de color (reusa .delta-bien / .delta-mal / .delta-igual de style.css) y
 * si la comparación es válida.
 *
 * "Comparable" exige que AMBOS meses tengan el denominador de la métrica con
 * datos: leads > 0 para el CPL, inversión > 0 para el ROAS. Con el denominador
 * en 0 la vista fuerza la métrica a 0 (guarda CASE WHEN … = 0 THEN 0) y una
 * flecha mentiría — es el mismo falso-positivo que se corrigió en el benchmark
 * por rubro (un mes sin leads no "mejoró" su CPL bajándolo a 0).
 *
 * @param float $actual             Valor de la métrica en el mes del reporte.
 * @param float $anterior           Valor de la métrica en el mes anterior.
 * @param bool  $actualComparable   El mes del reporte tiene datos del denominador.
 * @param bool  $anteriorComparable El mes anterior tiene datos del denominador.
 * @param bool  $masBajoEsMejor     true para CPL (bajar es bueno); false para ROAS.
 * @return array{comparable: bool, flecha: string, clase: string}
 */
function comparar_metrica(
    float $actual,
    float $anterior,
    bool $actualComparable,
    bool $anteriorComparable,
    bool $masBajoEsMejor
): array {
    if (!$actualComparable || !$anteriorComparable) {
        return ['comparable' => false, 'flecha' => '', 'clase' => ''];
    }
    if ($actual === $anterior) {
        return ['comparable' => true, 'flecha' => '→', 'clase' => 'delta-igual'];
    }
    $subio = $actual > $anterior;
    $mejor = $masBajoEsMejor ? !$subio : $subio;
    return [
        'comparable' => true,
        'flecha'     => $subio ? '↑' : '↓',
        'clase'      => $mejor ? 'delta-bien' : 'delta-mal',
    ];
}
