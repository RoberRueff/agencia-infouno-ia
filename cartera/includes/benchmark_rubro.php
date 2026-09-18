<?php
/**
 * Benchmark del rubro: promedio de CPL y ROAS del mes de las OTRAS PyMEs activas
 * del mismo rubro que el cliente, para darle contexto de mercado en el dashboard.
 *
 * Promedia las columnas cpl_mes/roas_mes ya calculadas por vista_objetivos_progreso
 * (no reimplementa las fórmulas). Pares = clientes activos del mismo rubro con
 * inversión > 0 Y al menos un lead ese mes, excluyendo al propio cliente. Se exige
 * leads > 0 porque la vista fuerza cpl_mes = 0 cuando no hubo leads (o no hubo
 * inversión): sin ese filtro, un par que gastó pero no generó ningún lead aportaría
 * un CPL de 0 al promedio y lo hundiría artificialmente, marcando 🔴 a un cliente
 * con CPL legítimamente bueno. Devuelve null si hay menos de 3 pares (anonimato:
 * con 1 o 2 el promedio de-anonimizaría).
 *
 * $mes es el primer día del mes (YYYY-MM-01), igual que vista_objetivos_progreso.mes_anio.
 *
 * @return array{pares:int, cpl_prom:float, roas_prom:float}|null
 */
function benchmark_rubro(PDO $pdo, int $clienteId, string $rubro, string $mes): ?array
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)          AS pares,
               AVG(vop.cpl_mes)  AS cpl_prom,
               AVG(vop.roas_mes) AS roas_prom
        FROM vista_objetivos_progreso vop
        JOIN clientes c ON c.id = vop.cliente_id
        WHERE c.rubro_industrial = ?
          AND c.estado_cuenta = 'activo'
          AND vop.mes_anio = ?
          AND vop.inversion_real_mes > 0
          AND vop.leads_reales_mes > 0
          AND vop.cliente_id <> ?
    ");
    $stmt->execute([$rubro, $mes, $clienteId]);
    $fila = $stmt->fetch();

    $pares = (int) ($fila['pares'] ?? 0);
    if ($pares < 3) {
        return null;
    }

    return [
        'pares'     => $pares,
        'cpl_prom'  => (float) $fila['cpl_prom'],
        'roas_prom' => (float) $fila['roas_prom'],
    ];
}
