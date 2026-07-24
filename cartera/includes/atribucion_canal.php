<?php

/**
 * Atribución por canal (Google vs Meta) del mes indicado para un cliente.
 *
 * Lee la vista vista_atribucion_canal, que ya calcula ROAS/CPL/conversión en
 * SQL — acá no se recalcula ninguna de esas fórmulas. Devuelve 0, 1 o 2 filas
 * (una por canal con datos en el mes), ordenadas por canal. El $mes es el
 * primer día del mes ('YYYY-MM-01'), fijado por PHP (primer_dia_mes()).
 *
 * @return array<int, array<string, mixed>>
 */
function atribucion_por_canal(PDO $pdo, int $clienteId, string $mes): array
{
    $stmt = $pdo->prepare(
        'SELECT canal, inversion, leads, ventas_cant, ventas_monto, roas, cpl, `conversion`
         FROM vista_atribucion_canal
         WHERE cliente_id = ? AND mes_anio = ?
         ORDER BY canal'
    );
    $stmt->execute([$clienteId, $mes]);
    return $stmt->fetchAll();
}
