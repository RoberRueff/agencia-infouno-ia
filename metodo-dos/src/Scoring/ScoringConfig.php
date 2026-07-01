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

    /** Valor/hora administrativo-operativo AR (ARS). Configurable. */
    public const HOURLY_RATE_ARS = 7000;

    /** Semanas laborales al año (descuenta vacaciones/feriados). */
    public const WEEKS_PER_YEAR = 48;

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
}
