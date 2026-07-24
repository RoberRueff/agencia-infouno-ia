<?php
require_once __DIR__ . '/helpers.php'; // primer_dia_mes()

/**
 * Salud de la cartera por mes para los últimos $meses meses (default 6).
 *
 * Por cada cliente activo y mes dentro de la ventana, toma el estado del último
 * día con datos de ese mes (foto a fin de mes) y cuenta por color. El color sale
 * de vista_metricas_semaforo (el semáforo ya calculado) — acá solo se agrupa por
 * el emoji que la vista ya emitió, no se recalcula ninguna fórmula de CPL/ROAS.
 *
 * La ventana se fija con el reloj de PHP (primer_dia_mes() + aritmética de mes);
 * MAX(fecha) es interno de MySQL (compara datos almacenados entre sí). No se usa
 * NOW()/CURDATE(), así que no cruza husos.
 *
 * @return array<int, array{mes:string, verde:int, amarillo:int, rojo:int, gris:int, total:int}>
 *   Lista de $meses elementos en orden cronológico ascendente (más viejo primero).
 */
function tendencia_cartera(PDO $pdo, int $meses = 6): array
{
    $mesActual = primer_dia_mes();
    $desde = date('Y-m-01', strtotime($mesActual . ' -' . ($meses - 1) . ' months'));
    $hasta = date('Y-m-01', strtotime($mesActual . ' +1 month'));

    // Esqueleto: los $meses meses en orden ascendente (más viejo primero), en cero.
    $resultado = [];
    for ($i = $meses - 1; $i >= 0; $i--) {
        $mes = date('Y-m-01', strtotime($mesActual . ' -' . $i . ' months'));
        $resultado[$mes] = ['mes' => $mes, 'verde' => 0, 'amarillo' => 0, 'rojo' => 0, 'gris' => 0, 'total' => 0];
    }

    $stmt = $pdo->prepare("
        SELECT ult.mes AS mes,
               vms.estado_semaforo,
               COUNT(*) AS n
        FROM vista_metricas_semaforo vms
        JOIN (
            SELECT cliente_id,
                   DATE_FORMAT(fecha, '%Y-%m-01') AS mes,
                   MAX(fecha) AS max_fecha
            FROM metricas_diarias
            WHERE fecha >= ? AND fecha < ?
            GROUP BY cliente_id, DATE_FORMAT(fecha, '%Y-%m-01')
        ) ult ON ult.cliente_id = vms.cliente_id AND vms.fecha = ult.max_fecha
        JOIN clientes c ON c.id = vms.cliente_id AND c.estado_cuenta = 'activo'
        GROUP BY ult.mes, vms.estado_semaforo
    ");
    $stmt->execute([$desde, $hasta]);

    foreach ($stmt->fetchAll() as $fila) {
        $mes = $fila['mes'];
        if (!isset($resultado[$mes])) {
            continue; // defensivo: fuera de la ventana esperada
        }
        $n = (int) $fila['n'];
        $estado = (string) $fila['estado_semaforo'];
        if (str_contains($estado, '🔴')) {
            $color = 'rojo';
        } elseif (str_contains($estado, '🟡')) {
            $color = 'amarillo';
        } elseif (str_contains($estado, '🟢')) {
            $color = 'verde';
        } else {
            $color = 'gris';
        }
        $resultado[$mes][$color] += $n;
        $resultado[$mes]['total'] += $n;
    }

    return array_values($resultado);
}
