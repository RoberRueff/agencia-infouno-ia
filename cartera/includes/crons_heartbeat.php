<?php
/**
 * Heartbeat de crons: cada cron registra un latido al arrancar; el panel de
 * cartera y el digest de agencia detectan un cron caído a partir de esos latidos.
 *
 * Regla de timezone: el circuito corre 100% en reloj de PHP. registrar_latido()
 * guarda date('Y-m-d H:i:s') (no NOW() de MySQL) y estado_crons() calcula la
 * antigüedad con time()/strtotime(). Ningún extremo lee la hora de MySQL, así
 * que no cruza husos (MySQL UTC vs PHP America/Argentina/Buenos_Aires).
 */

require_once __DIR__ . '/helpers.php';

/**
 * Crons esperados: clave = nombre que cada cron pasa a registrar_latido();
 * 'etiqueta' legible para el panel; 'max_horas' = máximo sin latir antes de
 * considerarlo caído (frecuencia nominal + gracia). Única fuente de verdad de
 * qué crons se monitorean y con qué umbral.
 */
const CRONS_REGISTRO = [
    '01_daily_ingest'              => ['etiqueta' => 'Ingesta diaria',            'max_horas' => 30],
    '02_whatsapp_followup'         => ['etiqueta' => 'Seguimiento WhatsApp',      'max_horas' => 2],
    '03_alert_and_weekly_report'   => ['etiqueta' => 'Alertas y reporte semanal', 'max_horas' => 30],
    '04_create_monthly_objectives' => ['etiqueta' => 'Objetivos mensuales',       'max_horas' => 840],
    '05_avisos_cartera'            => ['etiqueta' => 'Avisos de cartera',          'max_horas' => 30],
];

/**
 * Registra (o actualiza) el latido de un cron con la hora actual de PHP.
 * Se llama al inicio de cada cron, justo después de $pdo = db().
 *
 * Escritura best-effort: es observabilidad auxiliar, no el trabajo real del
 * cron. Si falla (ej. la tabla `crons_latidos` todavía no existe porque el
 * código se subió antes que la migración 010), se loguea con log_line() y se
 * sigue de largo — nunca debe tumbar el cron que la llama.
 */
function registrar_latido(PDO $pdo, string $nombre): void
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO crons_latidos (cron_nombre, ultimo_latido) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE ultimo_latido = VALUES(ultimo_latido)'
        );
        $stmt->execute([$nombre, date('Y-m-d H:i:s')]);
    } catch (\PDOException $e) {
        log_line("No se pudo registrar el latido de $nombre: " . $e->getMessage());
    }
}

/**
 * Estado de cada cron del registro. Lee todos los latidos en una query y los
 * cruza con CRONS_REGISTRO en PHP; la antigüedad se calcula con el reloj de PHP.
 *
 * Lectura best-effort, igual criterio que registrar_latido(): si la tabla
 * `crons_latidos` todavía no existe (código subido antes que la migración 010),
 * se loguea con log_line() y se sigue como si no hubiera latidos todavía —
 * cada cron queda en estado 'sin_datos', que por diseño nunca dispara aviso
 * (ver alertas_crons_caidos()). El panel de cartera y el digest de agencia
 * llaman esta función en medio de un cron real (cron/05, cron/03) — no puede
 * tumbarlo.
 *
 * @return array<int, array{cron_nombre:string, etiqueta:string,
 *   ultimo_latido:?string, horas_sin_latido:?float, estado:string}>
 *   estado ∈ 'al_dia' | 'caido' | 'sin_datos'
 */
function estado_crons(PDO $pdo): array
{
    $latidos = [];
    try {
        $stmt = $pdo->prepare('SELECT cron_nombre, ultimo_latido FROM crons_latidos');
        $stmt->execute();
        foreach ($stmt->fetchAll() as $fila) {
            $latidos[$fila['cron_nombre']] = $fila['ultimo_latido'];
        }
    } catch (\PDOException $e) {
        log_line('No se pudo leer crons_latidos: ' . $e->getMessage());
    }

    $ahora = time();
    $resultado = [];
    foreach (CRONS_REGISTRO as $nombre => $info) {
        $ultimo = $latidos[$nombre] ?? null;
        if ($ultimo === null) {
            $estado = 'sin_datos';
            $horas = null;
        } else {
            $horas = ($ahora - strtotime($ultimo)) / 3600;
            $estado = $horas > $info['max_horas'] ? 'caido' : 'al_dia';
        }
        $resultado[] = [
            'cron_nombre'      => $nombre,
            'etiqueta'         => $info['etiqueta'],
            'ultimo_latido'    => $ultimo,
            'horas_sin_latido' => $horas,
            'estado'           => $estado,
        ];
    }
    return $resultado;
}

/**
 * Avisos de agencia por crons caídos, en la forma uniforme de alertas_agencia.
 * Un cron 'sin_datos' NO dispara aviso (arranque en frío sin falsos positivos).
 *
 * @return array<int, array{tipo:string, icono:string, texto:string}>
 */
function alertas_crons_caidos(PDO $pdo): array
{
    $avisos = [];
    foreach (estado_crons($pdo) as $cron) {
        if ($cron['estado'] !== 'caido') {
            continue;
        }
        $horas = (int) $cron['horas_sin_latido'];
        $desde = date('d/m H:i', strtotime($cron['ultimo_latido']));
        $avisos[] = [
            'tipo'  => 'cron_caido',
            'icono' => '💀',
            'texto' => "Cron «{$cron['etiqueta']}» sin correr hace {$horas}h (última vez: {$desde}).",
        ];
    }
    return $avisos;
}
