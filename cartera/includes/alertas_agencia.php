<?php
/**
 * Alertas de agencia para el panel admin (se muestran en la cartera).
 * Se calculan al cargar la página a partir de datos leídos de MySQL:
 * no hay push, ni cron, ni tabla de dedup.
 *
 * Regla de timezone del proyecto: todo cálculo contra "hoy"/"este mes" se hace
 * con el reloj de PHP (date()/strtotime()). Los usos de MySQL son un MAX() sobre
 * columnas almacenadas y comparaciones contra literales de fecha fijados por PHP,
 * que no leen la hora de MySQL, así que no cruzan relojes.
 *
 * Cada función de señal devuelve una lista de avisos con la forma uniforme:
 *   ['tipo' => string, 'icono' => string, 'texto' => string]
 * (texto ya formateado, sin escapar; la vista lo escapa con htmlspecialchars()).
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/crons_heartbeat.php';

// Umbrales de las señales de agencia.
const ALERTA_DIAS_SIN_LEADS         = 5;    // días sin un solo lead
const ALERTA_PROYECCION_PRESUPUESTO = 1.0;  // proyección >= 100% del presupuesto
const ALERTA_DIAS_MIN_MES           = 5;    // no evaluar presupuesto antes del día 5
const ALERTA_CLIENTES_ROJO_SEMANA   = 3;    // "varios" clientes en rojo = 3+
const ALERTA_DIAS_RACHA             = 7;    // ventana (días) de la racha roja
const ALERTA_DIAS_ESTADO_DORMIDO     = 21;   // activo sin inversión ni leads => sugerir pausar
const ALERTA_DIAS_ONBOARDING_TRABADO = 14;   // onboarding sin activarse => revisar

/**
 * Orquestador: junta las 6 señales en una sola lista de avisos.
 * Orden: crons caídos (infra) -> racha roja -> presupuesto -> sin leads -> estado dormido -> onboarding trabado.
 *
 * @return array<int, array{tipo:string, icono:string, texto:string, cliente_id?:int}>
 */
function alertas_agencia(PDO $pdo): array
{
    return array_merge(
        alertas_crons_caidos($pdo),
        alertas_racha_roja($pdo),
        alertas_presupuesto($pdo),
        alertas_sin_leads($pdo),
        alertas_estado_dormido($pdo),
        alertas_onboarding_trabado($pdo)
    );
}

/**
 * Clientes activos que no generaron leads en los últimos $dias días.
 *
 * @return array<int, array{tipo:string, icono:string, texto:string}>
 */
function alertas_sin_leads(PDO $pdo, int $dias = ALERTA_DIAS_SIN_LEADS): array
{
    // ultimo_lead = último día con leads_totales > 0 por cliente activo.
    // El MAX(CASE ...) compara solo columnas almacenadas del lado de MySQL.
    $filas = $pdo->query("
        SELECT c.id AS cliente_id, c.nombre_empresa, c.fecha_alta,
               MAX(CASE WHEN m.leads_totales > 0 THEN m.fecha END) AS ultimo_lead
        FROM clientes c
        LEFT JOIN metricas_diarias m ON m.cliente_id = c.id
        WHERE c.estado_cuenta = 'activo'
        GROUP BY c.id, c.nombre_empresa, c.fecha_alta
    ")->fetchAll();

    $hoy = strtotime(date('Y-m-d'));
    $crudos = [];

    foreach ($filas as $f) {
        if ($f['ultimo_lead'] !== null) {
            $diasSin = (int) floor(($hoy - strtotime($f['ultimo_lead'])) / 86400);
            if ($diasSin >= $dias) {
                $crudos[] = [
                    'dias'  => $diasSin,
                    'texto' => $f['nombre_empresa'] . ' — sin leads hace ' . $diasSin
                        . ' días (último: ' . date('d/m', strtotime($f['ultimo_lead'])) . ')',
                ];
            }
        } else {
            // Nunca tuvo leads: solo avisar si está activo hace al menos $dias
            // (un cliente recién dado de alta no dispara un falso positivo).
            $diasActivo = (int) floor(($hoy - strtotime($f['fecha_alta'])) / 86400);
            if ($diasActivo >= $dias) {
                $crudos[] = [
                    'dias'  => $diasActivo,
                    'texto' => $f['nombre_empresa'] . ' — sin leads registrados (activa hace '
                        . $diasActivo . ' días)',
                ];
            }
        }
    }

    // Peor (más días sin leads) primero.
    usort($crudos, fn($a, $b) => $b['dias'] <=> $a['dias']);

    return array_map(
        fn($c) => ['tipo' => 'sin_leads', 'icono' => '📉', 'texto' => $c['texto']],
        $crudos
    );
}

/**
 * Clientes activos sin actividad real (sin inversión NI leads) hace $dias días
 * o más — candidatos a pausar. Distinta de alertas_sin_leads (que mira solo
 * leads con umbral corto): acá "actividad" incluye la inversión y el umbral es
 * más largo, así que marca un cliente que en la práctica dejó de operar (ciclo
 * de vida), no un problema de conversión. Es una SUGERENCIA: no cambia
 * estado_cuenta, solo avisa (el aviso trae cliente_id para linkear al editor).
 *
 * @return array<int, array{tipo:string, icono:string, texto:string, cliente_id:int}>
 */
function alertas_estado_dormido(PDO $pdo, int $dias = ALERTA_DIAS_ESTADO_DORMIDO): array
{
    // ultima_actividad = último día con inversión o leads por cliente activo.
    // El MAX(CASE ...) compara solo columnas almacenadas del lado de MySQL.
    $filas = $pdo->query("
        SELECT c.id AS cliente_id, c.nombre_empresa, c.fecha_alta,
               MAX(CASE WHEN m.inversion_total > 0 OR m.leads_totales > 0 THEN m.fecha END) AS ultima_actividad
        FROM clientes c
        LEFT JOIN metricas_diarias m ON m.cliente_id = c.id
        WHERE c.estado_cuenta = 'activo'
        GROUP BY c.id, c.nombre_empresa, c.fecha_alta
    ")->fetchAll();

    $hoy = strtotime(date('Y-m-d'));
    $crudos = [];

    foreach ($filas as $f) {
        if ($f['ultima_actividad'] !== null) {
            $diasSin = (int) floor(($hoy - strtotime($f['ultima_actividad'])) / 86400);
            if ($diasSin >= $dias) {
                $crudos[] = [
                    'cliente_id' => (int) $f['cliente_id'],
                    'dias'       => $diasSin,
                    'texto'      => $f['nombre_empresa'] . ' — activo sin actividad hace ' . $diasSin
                        . ' días (última: ' . date('d/m', strtotime($f['ultima_actividad'])) . '), ¿pausar?',
                ];
            }
        } else {
            // Nunca tuvo actividad: solo avisar si está activo hace al menos $dias
            // (no marcar una alta reciente como falso positivo).
            $diasActivo = (int) floor(($hoy - strtotime($f['fecha_alta'])) / 86400);
            if ($diasActivo >= $dias) {
                $crudos[] = [
                    'cliente_id' => (int) $f['cliente_id'],
                    'dias'       => $diasActivo,
                    'texto'      => $f['nombre_empresa'] . ' — activo sin actividad registrada (activo hace '
                        . $diasActivo . ' días), ¿pausar?',
                ];
            }
        }
    }

    // Peor (más días sin actividad) primero.
    usort($crudos, fn($a, $b) => $b['dias'] <=> $a['dias']);

    return array_map(
        fn($c) => ['tipo' => 'estado_dormido', 'icono' => '💤', 'texto' => $c['texto'], 'cliente_id' => $c['cliente_id']],
        $crudos
    );
}

/**
 * Clientes en onboarding hace $dias días o más — revisar si ya se activan. Los
 * clientes en onboarding están excluidos de los crons y de la captura de leads,
 * así que no generan datos: la única señal es el tiempo desde fecha_alta. Es una
 * SUGERENCIA: no cambia estado_cuenta (trae cliente_id para linkear al editor).
 *
 * @return array<int, array{tipo:string, icono:string, texto:string, cliente_id:int}>
 */
function alertas_onboarding_trabado(PDO $pdo, int $dias = ALERTA_DIAS_ONBOARDING_TRABADO): array
{
    $filas = $pdo->query("
        SELECT id AS cliente_id, nombre_empresa, fecha_alta
        FROM clientes
        WHERE estado_cuenta = 'onboarding'
    ")->fetchAll();

    $hoy = strtotime(date('Y-m-d'));
    $crudos = [];

    foreach ($filas as $f) {
        $diasEnOnboarding = (int) floor(($hoy - strtotime($f['fecha_alta'])) / 86400);
        if ($diasEnOnboarding >= $dias) {
            $crudos[] = [
                'cliente_id' => (int) $f['cliente_id'],
                'dias'       => $diasEnOnboarding,
                'texto'      => $f['nombre_empresa'] . ' — ' . $diasEnOnboarding
                    . ' días en onboarding, ¿activar o revisar?',
            ];
        }
    }

    // Peor (más días en onboarding) primero.
    usort($crudos, fn($a, $b) => $b['dias'] <=> $a['dias']);

    return array_map(
        fn($c) => ['tipo' => 'onboarding_trabado', 'icono' => '⏳', 'texto' => $c['texto'], 'cliente_id' => $c['cliente_id']],
        $crudos
    );
}

/**
 * Clientes activos cuyo ritmo de gasto proyecta igualar/superar el presupuesto
 * del mes antes de fin de mes. Solo se evalúa a partir del día ALERTA_DIAS_MIN_MES.
 *
 * @return array<int, array{tipo:string, icono:string, texto:string}>
 */
function alertas_presupuesto(PDO $pdo): array
{
    $diasTranscurridos = (int) date('j');   // día del mes (1..31), reloj de PHP
    $diasDelMes        = (int) date('t');   // días del mes actual, reloj de PHP
    if ($diasTranscurridos < ALERTA_DIAS_MIN_MES) {
        return [];   // demasiado pronto para proyectar
    }

    $stmt = $pdo->prepare("
        SELECT cliente_id, nombre_empresa, presupuesto_asignado, inversion_real_mes
        FROM vista_objetivos_progreso
        WHERE mes_anio = :mes AND presupuesto_asignado > 0
    ");
    $stmt->execute(['mes' => primer_dia_mes()]);

    $crudos = [];
    foreach ($stmt->fetchAll() as $o) {
        $presupuesto = (float) $o['presupuesto_asignado'];
        $proyeccion  = (float) $o['inversion_real_mes'] / $diasTranscurridos * $diasDelMes;
        if ($proyeccion >= $presupuesto * ALERTA_PROYECCION_PRESUPUESTO) {
            $pct           = (int) round($proyeccion / $presupuesto * 100);
            $diasRestantes = $diasDelMes - $diasTranscurridos;
            $crudos[] = [
                'pct'   => $pct,
                'texto' => $o['nombre_empresa'] . ' — gasto proyectado a ' . $pct
                    . '% del presupuesto (' . $diasRestantes . ' días de mes por delante)',
            ];
        }
    }

    // Mayor sobregasto proyectado primero.
    usort($crudos, fn($a, $b) => $b['pct'] <=> $a['pct']);

    return array_map(
        fn($c) => ['tipo' => 'presupuesto', 'icono' => '💸', 'texto' => $c['texto']],
        $crudos
    );
}

/**
 * Aviso de cartera: >= ALERTA_CLIENTES_ROJO_SEMANA clientes activos que estuvieron
 * en 🔴 algún día de los últimos ALERTA_DIAS_RACHA días. Devuelve 0 o 1 aviso.
 *
 * @return array<int, array{tipo:string, icono:string, texto:string}>
 */
function alertas_racha_roja(PDO $pdo): array
{
    // Ventana calculada con el reloj de PHP; se compara contra la columna fecha
    // (almacenada) como literal, sin leer la hora de MySQL.
    $desde = date('Y-m-d', strtotime('-' . (ALERTA_DIAS_RACHA - 1) . ' days'));

    $stmt = $pdo->prepare("
        SELECT DISTINCT vms.cliente_id, vms.nombre_empresa
        FROM vista_metricas_semaforo vms
        INNER JOIN clientes c ON c.id = vms.cliente_id AND c.estado_cuenta = 'activo'
        WHERE vms.fecha >= :desde AND vms.estado_semaforo LIKE CONVERT(:roja USING utf8mb4) COLLATE utf8mb4_general_ci
        ORDER BY vms.nombre_empresa
    ");
    // Se matchea el texto ASCII del estado rojo (no el emoji 🔴): evita el
    // choque de collations con la columna del view y el colapso de emojis
    // bajo utf8mb4_general_ci. El COLLATE explícito hace la comparación
    // portable (general_ci existe en MySQL 5.7 y 8.0). Sigue leyendo el
    // estado_semaforo que decide el view, no reimplementa la fórmula.
    // CONVERT(... USING utf8mb4) es necesario porque, con PDO::ATTR_EMULATE_PREPARES
    // en false (prepares nativos), el parámetro bindeado llega sin charset definido
    // (MySQL lo trata como 'binary'); aplicar COLLATE utf8mb4_general_ci directo
    // sobre eso falla con error 1253. Convertirlo primero a utf8mb4 lo resuelve.
    $stmt->execute(['desde' => $desde, 'roja' => '%Alerta: CPL Alto%']);
    $nombres = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);

    if (count($nombres) < ALERTA_CLIENTES_ROJO_SEMANA) {
        return [];
    }

    return [[
        'tipo'  => 'racha_roja',
        'icono' => '🔴',
        'texto' => count($nombres) . ' clientes estuvieron en rojo esta semana ('
            . implode(', ', $nombres) . ')',
    ]];
}

/**
 * Arma el digest de texto libre para el WhatsApp diario de cartera a partir de
 * los avisos de alertas_agencia(). Agrupa por tipo en el orden del orquestador
 * (cron_caido -> racha_roja -> presupuesto -> sin_leads -> estado_dormido ->
 * onboarding_trabado), lista hasta $capPorTipo líneas por grupo y agrega
 * "…y N más" si sobran. Es texto libre para WhatsApp (NO HTML):
 * no se escapa. Encabezado con la fecha de hoy (reloj de PHP).
 *
 * @param array<int, array{tipo:string, icono:string, texto:string}> $avisos
 */
function armar_digest_cartera(array $avisos, string $urlCartera, int $capPorTipo = 5): string
{
    // Orden y buckets fijos (las 6 señales de alertas_agencia(); infra primero).
    $porTipo = ['cron_caido' => [], 'racha_roja' => [], 'presupuesto' => [], 'sin_leads' => [], 'estado_dormido' => [], 'onboarding_trabado' => []];
    foreach ($avisos as $a) {
        if (isset($porTipo[$a['tipo']])) {
            $porTipo[$a['tipo']][] = $a;
        }
    }

    $lineas = ['📊 Resumen de cartera — ' . date('d/m')];

    foreach ($porTipo as $grupo) {
        if (!$grupo) {
            continue;
        }
        $mostrados = array_slice($grupo, 0, $capPorTipo);
        foreach ($mostrados as $a) {
            $lineas[] = $a['icono'] . ' ' . $a['texto'];
        }
        $sobran = count($grupo) - count($mostrados);
        if ($sobran > 0) {
            $lineas[] = '…y ' . $sobran . ' más';
        }
    }

    $lineas[] = '';
    $lineas[] = 'Ver la cartera: ' . $urlCartera;

    return implode("\n", $lineas);
}
