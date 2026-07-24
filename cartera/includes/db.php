<?php
/**
 * Conexión PDO única (singleton) a MySQL, reutilizada por cron jobs y páginas web.
 *
 * Este archivo lo requiere (directa o transitivamente) prácticamente todo
 * punto de entrada del proyecto, así que es el lugar correcto para apagar
 * display_errors en contexto web: sin esto, un error no controlado en
 * producción (ej. un PDOException) imprime la ruta completa del archivo, el
 * número de línea y a veces la query en la respuesta HTTP — se confirmó en
 * pruebas locales. En CLI (cron) se deja encendido a propósito, porque cPanel
 * manda esa salida por mail y es la forma de enterarse si un cron falló.
 */
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
error_reporting(E_ALL);

function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $path = __DIR__ . '/../config/config.php';
        if (!file_exists($path)) {
            throw new RuntimeException('Falta config/config.php — copiá config.example.php y completalo.');
        }
        $config = require $path;
        date_default_timezone_set($config['app']['timezone'] ?? 'America/Argentina/Buenos_Aires');
    }
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = app_config()['db'];
        $dsn = "mysql:host={$c['host']};dbname={$c['name']};charset={$c['charset']}";
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Alinea el reloj de la sesión MySQL con el de PHP: NOW()/CURDATE() quedan
        // en el mismo huso que time()/date() (America/Argentina/Buenos_Aires,
        // -03:00). Sin esto la sesión corre en el huso del server (UTC en Donweb) y
        // un lead cargado de noche cae en un día/mes distinto entre fecha_ingreso
        // (NOW()) y su métrica diaria (date()). Se usa el offset numérico de
        // date('P') —ya fijado por app_config()— porque MySQL lo acepta sin las
        // tablas de husos (que un nombre como 'America/...' sí requiere); Argentina
        // no tiene DST, así que el offset es constante.
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}
