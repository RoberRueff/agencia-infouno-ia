<?php
/**
 * Ciclo de vida del lead: transiciones de estado que el dueño gestiona desde su
 * dashboard, y el conteo del embudo. Toda transición valida que el lead sea del
 * cliente logueado (filtro cliente_id en el WHERE) y que esté en un estado
 * abierto, de modo que no se pueda tocar un lead ajeno ni cerrar uno ya cerrado.
 *
 * Regla de timezone: el día de la venta se toma con date() de PHP y la ventana
 * del embudo con literales fijados por PHP; nunca NOW()/CURDATE() de MySQL.
 *
 * Excepción: el circuito de SLA (sla_resumen() y la columna de antigüedad de
 * leads_abiertos()) corre a propósito sobre el reloj de MySQL (NOW()/TIMESTAMPDIFF),
 * porque fecha_ingreso se escribe con NOW() y hay que medir la antigüedad contra
 * ese mismo reloj para no desalinearse por el huso horario entre MySQL (UTC) y
 * PHP (America/Argentina/Buenos_Aires).
 */

require_once __DIR__ . '/helpers.php';

// Motivos de pérdida (lista fija; 'Otro' habilita una nota corta libre).
const LEAD_MOTIVOS = ['Precio', 'No responde', 'Compró a la competencia', 'No calificaba', 'Otro'];

// Etiquetas amigables por valor de leads.estado (los valores de DB no se renombran).
const LEAD_ESTADO_LABELS = [
    'pendiente'      => 'Nuevo',
    'contactado'     => 'Contactado',
    'en_negociacion' => 'En negociación',
    'vendido'        => 'Ganado',
    'no_vendido'     => 'Perdido',
];

// Estados en los que un lead sigue "abierto" (worklist + transicionable).
const LEAD_ESTADOS_ABIERTOS = ['pendiente', 'contactado', 'en_negociacion'];

/**
 * Leads abiertos del cliente (worklist accionable), recientes primero.
 * Cap de 50 para no renderizar listas enormes.
 *
 * @return array<int, array{id:int, nombre_lead:string, telefono_lead:string, fecha_ingreso:string, estado:string, fecha_primer_contacto:?string, horas_sin_atender:int}>
 */
function leads_abiertos(PDO $pdo, int $clienteId): array
{
    $stmt = $pdo->prepare("
        SELECT id, nombre_lead, telefono_lead, fecha_ingreso, estado,
               fecha_primer_contacto,
               TIMESTAMPDIFF(HOUR, fecha_ingreso, NOW()) AS horas_sin_atender
        FROM leads
        WHERE cliente_id = ? AND estado IN ('pendiente', 'contactado', 'en_negociacion')
        ORDER BY fecha_ingreso DESC
        LIMIT 50
    ");
    $stmt->execute([$clienteId]);
    return $stmt->fetchAll();
}

/**
 * Embudo de leads ingresados en el mes (mes = 'YYYY-MM-01' fijado por PHP),
 * contados por su estado actual, más el total y la tasa de conversión
 * (vendidos / total).
 *
 * @return array{conteos: array<string,int>, total:int, conversion:float}
 */
function embudo_leads(PDO $pdo, int $clienteId, string $mes): array
{
    $mesSiguiente = date('Y-m-01', strtotime($mes . ' +1 month'));

    $stmt = $pdo->prepare("
        SELECT estado, COUNT(*) AS cantidad
        FROM leads
        WHERE cliente_id = ? AND fecha_ingreso >= ? AND fecha_ingreso < ?
        GROUP BY estado
    ");
    $stmt->execute([$clienteId, $mes, $mesSiguiente]);

    $conteos = ['pendiente' => 0, 'contactado' => 0, 'en_negociacion' => 0, 'vendido' => 0, 'no_vendido' => 0];
    $total = 0;
    foreach ($stmt->fetchAll() as $fila) {
        $conteos[$fila['estado']] = (int) $fila['cantidad'];
        $total += (int) $fila['cantidad'];
    }
    $conversion = $total > 0 ? $conteos['vendido'] / $total : 0.0;

    return ['conteos' => $conteos, 'total' => $total, 'conversion' => $conversion];
}

/**
 * Mueve un lead abierto del cliente a 'en_negociacion'. Devuelve true si cambió.
 */
function lead_a_negociacion(PDO $pdo, int $leadId, int $clienteId): bool
{
    $stmt = $pdo->prepare("
        UPDATE leads SET estado = 'en_negociacion',
               fecha_primer_contacto = COALESCE(fecha_primer_contacto, NOW())
        WHERE id = ? AND cliente_id = ? AND estado IN ('pendiente', 'contactado')
    ");
    $stmt->execute([$leadId, $clienteId]);
    return $stmt->rowCount() > 0;
}

/**
 * Marca un lead abierto del cliente como 'no_vendido' con su motivo.
 * Devuelve true si cambió.
 */
function lead_a_perdido(PDO $pdo, int $leadId, int $clienteId, string $motivo): bool
{
    $stmt = $pdo->prepare("
        UPDATE leads SET estado = 'no_vendido', motivo_perdida = ?,
               fecha_primer_contacto = COALESCE(fecha_primer_contacto, NOW())
        WHERE id = ? AND cliente_id = ? AND estado IN ('pendiente', 'contactado', 'en_negociacion')
    ");
    $stmt->execute([$motivo, $leadId, $clienteId]);
    return $stmt->rowCount() > 0;
}

/**
 * Marca un lead abierto del cliente como 'vendido' con su monto y registra la
 * venta en metricas_diarias del día (mismo camino que el webhook), en una
 * transacción. Si el lead ya estaba cerrado no registra la venta (evita doble
 * conteo ante doble submit). Devuelve true si registró la venta.
 */
function lead_a_ganado(PDO $pdo, int $leadId, int $clienteId, float $monto): bool
{
    $pdo->beginTransaction();

    $upd = $pdo->prepare("
        UPDATE leads SET estado = 'vendido', monto_venta = ?,
               fecha_primer_contacto = COALESCE(fecha_primer_contacto, NOW())
        WHERE id = ? AND cliente_id = ? AND estado IN ('pendiente', 'contactado', 'en_negociacion')
    ");
    $upd->execute([$monto, $leadId, $clienteId]);

    if ($upd->rowCount() !== 1) {
        $pdo->rollBack();
        return false;
    }

    $metricaId = obtener_o_crear_metrica_dia($clienteId, date('Y-m-d'));
    $updMetrica = $pdo->prepare('UPDATE metricas_diarias SET monto_venta = monto_venta + ?, ventas_cant = ventas_cant + 1 WHERE id = ?');
    $updMetrica->execute([$monto, $metricaId]);

    $pdo->commit();
    return true;
}

/**
 * Ranking de motivos de pérdida del mes para un cliente (leads no_vendido,
 * por fecha_ingreso). Los 4 motivos fijos (LEAD_MOTIVOS sin "Otro") agrupan por
 * nombre; las pérdidas cargadas como "Otro" quedan guardadas como texto libre y
 * se acumulan en un bucket "Otro", con sus notas listadas aparte. Es un conteo,
 * no una fórmula protegida (ROAS/CPL/semáforo).
 *
 * @return array{total:int, ranking:array<int,array{motivo:string,cantidad:int}>, notas_otro:array<int,string>}
 */
function motivos_perdida(PDO $pdo, int $clienteId, string $mes): array
{
    $mesSiguiente = date('Y-m-01', strtotime($mes . ' +1 month'));
    $stmt = $pdo->prepare(
        "SELECT motivo_perdida, COUNT(*) AS cantidad
         FROM leads
         WHERE cliente_id = ? AND estado = 'no_vendido'
           AND fecha_ingreso >= ? AND fecha_ingreso < ?
         GROUP BY motivo_perdida
         ORDER BY motivo_perdida"
    );
    $stmt->execute([$clienteId, $mes, $mesSiguiente]);

    $fijos = array_diff(LEAD_MOTIVOS, ['Otro']); // los 4 motivos fijos
    $conteos = [];   // motivo fijo => cantidad
    $otroTotal = 0;
    $notasOtro = [];
    $total = 0;
    foreach ($stmt->fetchAll() as $fila) {
        $cant = (int) $fila['cantidad'];
        $total += $cant;
        $motivo = $fila['motivo_perdida'];
        if ($motivo !== null && in_array($motivo, $fijos, true)) {
            $conteos[$motivo] = ($conteos[$motivo] ?? 0) + $cant;
        } else {
            $otroTotal += $cant;
            $notasOtro[] = ($motivo === null || trim((string) $motivo) === '') ? '(sin especificar)' : $motivo;
        }
    }

    $ranking = [];
    foreach ($conteos as $motivo => $cantidad) {
        $ranking[] = ['motivo' => $motivo, 'cantidad' => $cantidad];
    }
    if ($otroTotal > 0) {
        $ranking[] = ['motivo' => 'Otro', 'cantidad' => $otroTotal];
    }
    // Orden determinista: cantidad desc, desempate por nombre asc.
    usort($ranking, function ($a, $b) {
        return $b['cantidad'] <=> $a['cantidad'] ?: strcmp($a['motivo'], $b['motivo']);
    });

    return ['total' => $total, 'ranking' => $ranking, 'notas_otro' => $notasOtro];
}

/**
 * Resumen del SLA de respuesta para un cliente. Todo el cálculo de tiempo va con
 * el reloj de MySQL (NOW()/TIMESTAMPDIFF), consistente con cómo se escribe
 * fecha_ingreso (NOW()); no se mezcla con date()/time() de PHP.
 *
 * - Métricas del mes ($mes = 'YYYY-MM-01'): sobre los leads ingresados en el mes.
 *   "Atendido" = fecha_primer_contacto NOT NULL (primer toque humano).
 * - "Sin atender ahora": estado vivo, NO acotado al mes (leads abiertos que el
 *   humano nunca tocó y ya superaron el SLA).
 *
 * @return array{atendidos:int, promedio_horas:?float, dentro_sla:int, pct_dentro_sla:float, sin_atender_ahora:int}
 */
function sla_resumen(PDO $pdo, int $clienteId, string $mes, int $slaHoras): array
{
    $mesSiguiente = date('Y-m-01', strtotime($mes . ' +1 month')); // aritmética de meses sobre literal fijo, no "ahora"

    $stmt = $pdo->prepare("
        SELECT
          COUNT(fecha_primer_contacto) AS atendidos,
          AVG(TIMESTAMPDIFF(HOUR, fecha_ingreso, fecha_primer_contacto)) AS promedio_horas,
          SUM(CASE WHEN fecha_primer_contacto IS NOT NULL
                    AND TIMESTAMPDIFF(HOUR, fecha_ingreso, fecha_primer_contacto) <= ?
                   THEN 1 ELSE 0 END) AS dentro_sla
        FROM leads
        WHERE cliente_id = ? AND fecha_ingreso >= ? AND fecha_ingreso < ?
    ");
    $stmt->execute([$slaHoras, $clienteId, $mes, $mesSiguiente]);
    $row = $stmt->fetch();

    $atendidos = (int) $row['atendidos'];
    $dentroSla = (int) $row['dentro_sla'];
    $promedioHoras = $row['promedio_horas'] !== null ? (float) $row['promedio_horas'] : null;

    $sinAtenderStmt = $pdo->prepare("
        SELECT COUNT(*) FROM leads
        WHERE cliente_id = ?
          AND fecha_primer_contacto IS NULL
          AND estado IN ('pendiente','contactado')
          AND TIMESTAMPDIFF(HOUR, fecha_ingreso, NOW()) >= ?
    ");
    $sinAtenderStmt->execute([$clienteId, $slaHoras]);
    $sinAtenderAhora = (int) $sinAtenderStmt->fetchColumn();

    return [
        'atendidos'         => $atendidos,
        'promedio_horas'    => $promedioHoras,
        'dentro_sla'        => $dentroSla,
        'pct_dentro_sla'    => $atendidos > 0 ? $dentroSla / $atendidos : 0.0,
        'sin_atender_ahora' => $sinAtenderAhora,
    ];
}

/**
 * Id del lead abierto (pendiente/contactado/en_negociacion) más reciente del
 * cliente, o null si no hay ninguno. Lo usa el webhook de WhatsApp para elegir
 * qué lead cerrar (best-effort: el mensaje entrante no trae id de lead).
 */
function lead_abierto_mas_reciente(PDO $pdo, int $clienteId): ?int
{
    $stmt = $pdo->prepare("
        SELECT id FROM leads
        WHERE cliente_id = ? AND estado IN ('pendiente', 'contactado', 'en_negociacion')
        ORDER BY fecha_ingreso DESC
        LIMIT 1
    ");
    $stmt->execute([$clienteId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}
