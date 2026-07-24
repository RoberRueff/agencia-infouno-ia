<?php
require_once __DIR__ . '/whatsapp_client.php'; // WhatsAppClient (+ db.php transitivo)

/**
 * Cola durable de envíos de WhatsApp: los crons encolan y un worker drena con
 * reintentos/backoff. Reloj de PHP en todos los timestamps, nunca NOW().
 */

const COLA_WHATSAPP_MAX_INTENTOS = 5;

// Duración del "lease" del claim atómico: al tomar una fila, el worker empuja su
// proximo_intento este tanto hacia el futuro. Si el proceso muere después del
// claim pero antes de registrar el resultado, la fila se reintenta sola al vencer
// el lease (recuperación automática, sin quedar trabada). MySQL 5.7 no tiene
// SKIP LOCKED, por eso el claim es un UPDATE condicional, no un SELECT FOR UPDATE.
const COLA_WHATSAPP_LEASE_SEGUNDOS = 300;

// Plantilla sentinela para mensajes de TEXTO libre en la cola: avisos internos que
// no usan una plantilla de Meta (p. ej. el "objetivo en cero" de cron/04). El
// drenador los envía con enviarTexto() en vez de enviarDesdeCola().
const COLA_WHATSAPP_PLANTILLA_TEXTO = '__texto_libre__';

/**
 * Encola un mensaje de plantilla. Dedup por clave: si la clave ya existe es un
 * no-op (no reencola). proximo_intento = ahora (primer intento en el próximo
 * drenado). Todos los timestamps con el reloj de PHP.
 */
function encolar_whatsapp_plantilla(PDO $pdo, string $clave, string $destinatario, string $plantilla, array $parametros): void
{
    $ahora = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        'INSERT INTO cola_whatsapp
            (clave_idempotencia, destinatario, plantilla, parametros, estado, intentos, proximo_intento, creado_en, actualizado_en)
         VALUES (?, ?, ?, ?, \'pendiente\', 0, ?, ?, ?)
         ON DUPLICATE KEY UPDATE id = id'
    );
    $stmt->execute([
        $clave,
        $destinatario,
        $plantilla,
        json_encode($parametros, JSON_UNESCAPED_UNICODE),
        $ahora,
        $ahora,
        $ahora,
    ]);
}

/**
 * Encola un mensaje de TEXTO libre (aviso interno sin plantilla de Meta). Mismo
 * dedup por clave/idempotencia que encolar_whatsapp_plantilla; el drenador lo
 * envía con enviarTexto(). Durable: sobrevive a un WhatsApp caído (reintenta).
 */
function encolar_whatsapp_texto(PDO $pdo, string $clave, string $destinatario, string $texto): void
{
    encolar_whatsapp_plantilla($pdo, $clave, $destinatario, COLA_WHATSAPP_PLANTILLA_TEXTO, [$texto]);
}

/**
 * Worker: envía los mensajes 'pendiente' cuyo proximo_intento ya venció.
 * ok → 'enviado'; fallo reintentable → intentos++ y se reprograma con backoff
 * (o 'fallido' si llegó al tope); fallo permanente → 'fallido' inmediato.
 *
 * @return array{enviados:int, reintentar:int, fallidos:int}
 */
function procesar_cola_whatsapp(PDO $pdo, WhatsAppClient $wa, int $limite = 50): array
{
    $ahora = date('Y-m-d H:i:s');
    // $limite es int (no input de usuario): interpolado seguro; LIMIT no admite placeholder en algunos setups.
    $sel = $pdo->prepare(
        "SELECT id, destinatario, plantilla, parametros, intentos
         FROM cola_whatsapp
         WHERE estado = 'pendiente' AND proximo_intento <= ?
         ORDER BY proximo_intento ASC
         LIMIT " . (int) $limite
    );
    $sel->execute([$ahora]);
    $filas = $sel->fetchAll();

    $res = ['enviados' => 0, 'reintentar' => 0, 'fallidos' => 0];
    foreach ($filas as $f) {
        // Claim atómico: un único drenador puede tomar cada fila. El UPDATE empuja
        // proximo_intento a un lease futuro con guard en el WHERE; si otra corrida
        // concurrente ya la reclamó, afecta 0 filas y se saltea → sin doble envío
        // por solapamiento. Queda semántica at-least-once solo ante crash (el envío
        // salió pero el proceso murió antes del UPDATE del resultado): el lease
        // vence y se reintenta; el dedup de negocio de cada cron evita el doble
        // efecto.
        $claim = $pdo->prepare(
            "UPDATE cola_whatsapp SET proximo_intento = ?, actualizado_en = ?
             WHERE id = ? AND estado = 'pendiente' AND proximo_intento <= ?"
        );
        $claim->execute([
            date('Y-m-d H:i:s', time() + COLA_WHATSAPP_LEASE_SEGUNDOS),
            date('Y-m-d H:i:s'),
            $f['id'],
            $ahora,
        ]);
        if ($claim->rowCount() !== 1) {
            continue; // otra corrida ya reclamó esta fila
        }

        $parametros = json_decode($f['parametros'], true) ?: [];
        $r = $f['plantilla'] === COLA_WHATSAPP_PLANTILLA_TEXTO
            ? $wa->enviarTextoDesdeCola($f['destinatario'], (string) ($parametros[0] ?? ''))
            : $wa->enviarDesdeCola($f['destinatario'], $f['plantilla'], $parametros);
        $ahoraIter = date('Y-m-d H:i:s');

        if (!empty($r['ok'])) {
            $pdo->prepare("UPDATE cola_whatsapp SET estado='enviado', enviado_en=?, actualizado_en=? WHERE id=?")
                ->execute([$ahoraIter, $ahoraIter, $f['id']]);
            $res['enviados']++;
            continue;
        }

        $nuevoIntentos = (int) $f['intentos'] + 1;
        $error = mb_substr((string) ($r['error'] ?? 'error desconocido'), 0, 255);

        if (empty($r['reintentable']) || $nuevoIntentos >= COLA_WHATSAPP_MAX_INTENTOS) {
            $pdo->prepare("UPDATE cola_whatsapp SET estado='fallido', intentos=?, ultimo_error=?, actualizado_en=? WHERE id=?")
                ->execute([$nuevoIntentos, $error, $ahoraIter, $f['id']]);
            $res['fallidos']++;
        } else {
            $proximo = date('Y-m-d H:i:s', time() + _cola_backoff_segundos($nuevoIntentos));
            $pdo->prepare("UPDATE cola_whatsapp SET intentos=?, proximo_intento=?, ultimo_error=?, actualizado_en=? WHERE id=?")
                ->execute([$nuevoIntentos, $proximo, $error, $ahoraIter, $f['id']]);
            $res['reintentar']++;
        }
    }
    return $res;
}

/** Backoff (segundos) tras el intento fallido nro $intentos (1..4): 5m, 30m, 2h, 6h. */
function _cola_backoff_segundos(int $intentos): int
{
    $escala = [1 => 300, 2 => 1800, 3 => 7200, 4 => 21600];
    return $escala[$intentos] ?? 21600;
}

/**
 * Cuenta las filas de la cola agrupadas por estado. Las tres claves
 * (pendiente/enviado/fallido) vienen siempre presentes, en 0 si no hay filas.
 *
 * @return array{pendiente:int, enviado:int, fallido:int}
 */
function contar_cola_por_estado(PDO $pdo): array
{
    $conteos = ['pendiente' => 0, 'enviado' => 0, 'fallido' => 0];
    foreach ($pdo->query("SELECT estado, COUNT(*) AS n FROM cola_whatsapp GROUP BY estado") as $f) {
        if (isset($conteos[$f['estado']])) {
            $conteos[$f['estado']] = (int) $f['n'];
        }
    }
    return $conteos;
}

/**
 * Lista filas de la cola, más recientes primero. Si $estado es
 * pendiente|enviado|fallido filtra por ese estado; si no, trae todas.
 * $limite es int (no input de usuario): interpolado seguro (LIMIT no admite
 * placeholder en algunos setups). Mismo criterio que el worker.
 */
function listar_cola(PDO $pdo, ?string $estado, int $limite = 200): array
{
    $campos = 'id, clave_idempotencia, destinatario, plantilla, parametros, estado, intentos, '
        . 'proximo_intento, ultimo_error, creado_en, actualizado_en, enviado_en';
    $orden = ' ORDER BY actualizado_en DESC, id DESC LIMIT ' . (int) $limite;
    if (in_array($estado, ['pendiente', 'enviado', 'fallido'], true)) {
        $stmt = $pdo->prepare("SELECT $campos FROM cola_whatsapp WHERE estado = ?" . $orden);
        $stmt->execute([$estado]);
        return $stmt->fetchAll();
    }
    return $pdo->query("SELECT $campos FROM cola_whatsapp" . $orden)->fetchAll();
}

/**
 * Reintenta un mensaje fallido: lo resetea a 'pendiente' con intentos=0 y
 * proximo_intento=ahora (reloj de PHP), para que el worker de cron/02 lo tome en
 * su próxima pasada. Solo actúa si la fila está en 'fallido' (guard atómico en el
 * WHERE). Devuelve destinatario/plantilla para la auditoría, o null si no había
 * fila fallida con ese id.
 *
 * @return array{destinatario:string, plantilla:string}|null
 */
function reintentar_fallido(PDO $pdo, int $id): ?array
{
    $ahora = date('Y-m-d H:i:s');
    $upd = $pdo->prepare(
        "UPDATE cola_whatsapp
         SET estado='pendiente', intentos=0, proximo_intento=?, ultimo_error=NULL, actualizado_en=?
         WHERE id = ? AND estado = 'fallido'"
    );
    $upd->execute([$ahora, $ahora, $id]);
    if ($upd->rowCount() === 0) {
        return null;
    }
    $sel = $pdo->prepare("SELECT destinatario, plantilla FROM cola_whatsapp WHERE id = ?");
    $sel->execute([$id]);
    $fila = $sel->fetch();
    return $fila ? ['destinatario' => $fila['destinatario'], 'plantilla' => $fila['plantilla']] : null;
}

/**
 * Reintenta de una todos los mensajes fallidos (mismo reset que
 * reintentar_fallido). Devuelve cuántos se reencolaron.
 */
function reintentar_todos_fallidos(PDO $pdo): int
{
    $ahora = date('Y-m-d H:i:s');
    $upd = $pdo->prepare(
        "UPDATE cola_whatsapp
         SET estado='pendiente', intentos=0, proximo_intento=?, ultimo_error=NULL, actualizado_en=?
         WHERE estado = 'fallido'"
    );
    $upd->execute([$ahora, $ahora]);
    return $upd->rowCount();
}

/**
 * Descarta (borra) un mensaje fallido. Solo actúa si la fila está en 'fallido'.
 * Borrar libera la clave_idempotencia: un evento de negocio futuro idéntico se
 * podrá volver a encolar. Devuelve la info para la auditoría, o null.
 *
 * @return array{destinatario:string, plantilla:string, ultimo_error:?string}|null
 */
function descartar_fallido(PDO $pdo, int $id): ?array
{
    $sel = $pdo->prepare("SELECT destinatario, plantilla, ultimo_error FROM cola_whatsapp WHERE id = ? AND estado = 'fallido'");
    $sel->execute([$id]);
    $fila = $sel->fetch();
    if (!$fila) {
        return null;
    }
    $del = $pdo->prepare("DELETE FROM cola_whatsapp WHERE id = ? AND estado = 'fallido'");
    $del->execute([$id]);
    if ($del->rowCount() === 0) {
        return null; // otra sesión lo tomó entre el SELECT y el DELETE
    }
    return [
        'destinatario' => $fila['destinatario'],
        'plantilla'    => $fila['plantilla'],
        'ultimo_error' => $fila['ultimo_error'],
    ];
}

/**
 * Enmascara un teléfono para escribirlo en la auditoría sin filtrar el número
 * completo (el spec pide que el destinatario nunca se loguee). Deja los últimos
 * 4 dígitos: '5491122334455' → '…****4455'. Números de <=4 caracteres → '****'.
 */
function enmascarar_destinatario(string $tel): string
{
    $tel = trim($tel);
    if (strlen($tel) <= 4) {
        return '****';
    }
    return '…****' . substr($tel, -4);
}
