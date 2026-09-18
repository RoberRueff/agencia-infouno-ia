<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin_auth.php'; // admin_actual()

/**
 * Registra una acción del admin en la bitácora. Lee el actor de admin_actual()
 * (usuario nombrado o break-glass). BEST-EFFORT: una falla al insertar NUNCA
 * rompe la operación de negocio ya realizada — se traga la excepción y se deja
 * rastro con error_log. creado_en con el reloj de PHP (BsAs), nunca NOW().
 *
 * @param string   $accion    ej 'cliente.editar'
 * @param string   $entidad   'cliente'|'objetivo'|'usuario_admin'|'cuenta'
 * @param int|null $entidadId id de la entidad afectada (null si no aplica)
 * @param string   $detalle   texto legible (resumen + diff de campos)
 */
function registrar_auditoria(PDO $pdo, string $accion, string $entidad, ?int $entidadId, string $detalle): void
{
    try {
        $actor = admin_actual();
        $stmt = $pdo->prepare(
            'INSERT INTO auditoria
                (usuario_admin_id, actor_nombre, actor_rol, es_break_glass, accion, entidad, entidad_id, detalle, ip, creado_en)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $actor['id'] ?? null,
            (string) ($actor['nombre'] ?? ''),
            (string) ($actor['rol'] ?? ''),
            !empty($actor['break_glass']) ? 1 : 0,
            $accion,
            $entidad,
            $entidadId,
            mb_substr($detalle, 0, 1000),   // defensivo: no exceder VARCHAR(1000)
            $_SERVER['REMOTE_ADDR'] ?? null,
            date('Y-m-d H:i:s'),            // reloj de PHP
        ]);
    } catch (\Throwable $e) {
        error_log('auditoria: no se pudo registrar ' . $accion . ' — ' . $e->getMessage());
    }
}

/**
 * Arma el texto del diff SOLO con los campos que cambiaron: "etiqueta: viejo→nuevo".
 * La igualdad se determina comparando los valores ya normalizados por
 * _auditoria_fmt (evita falsos positivos por formato, ej DECIMAL "5000.00" vs
 * "5000", sin castear a float — así no se pierden cambios reales en ids
 * numéricos largos de Google/Meta Ads cerca de 2^53). Devuelve '' si nada cambió.
 *
 * @param array<string,mixed>  $viejo
 * @param array<string,mixed>  $nuevo
 * @param array<string,string> $etiquetas  campo => etiqueta legible
 */
function diff_campos(array $viejo, array $nuevo, array $etiquetas): string
{
    $cambios = [];
    foreach ($etiquetas as $campo => $etq) {
        $v = $viejo[$campo] ?? null;
        $n = $nuevo[$campo] ?? null;
        $igual = _auditoria_fmt($v) === _auditoria_fmt($n);
        if (!$igual) {
            $cambios[] = $etq . ': ' . _auditoria_fmt($v) . '→' . _auditoria_fmt($n);
        }
    }
    return implode(', ', $cambios);
}

/**
 * Normaliza un valor para mostrarlo en el diff. null/''/espacios => "(vacío)".
 * Un DECIMAL string con ceros sobrantes ("5000.00") se recorta a "5000" para que
 * el diff no muestre asimetrías ("5000.00→8000"); se recorta con rtrim (NO
 * casteando a float) para no perder precisión en ids numéricos largos de
 * Google/Meta Ads. Los numéricos nativos (int/float) ya los normaliza (string).
 */
function _auditoria_fmt($valor): string
{
    if (is_int($valor) || is_float($valor)) {
        return (string) $valor;                 // 8000.0 => "8000"
    }
    $s = trim((string) ($valor ?? ''));
    if ($s === '') {
        return '(vacío)';
    }
    if (is_numeric($s) && strpos($s, '.') !== false) {
        $s = rtrim(rtrim($s, '0'), '.');         // "5000.00" => "5000"
        if ($s === '' || $s === '-') {
            $s = '0';
        }
    }
    return $s;
}
