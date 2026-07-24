<?php
/**
 * Guarda la preferencia de tema (claro/oscuro) del cliente logueado.
 * Lo consume el botón de tema de dashboard.php vía fetch() en segundo
 * plano — no devuelve HTML, solo códigos de estado.
 *
 * POST JSON: { "tema": "claro"|"oscuro", "csrf_token": "..." }
 */

require_once __DIR__ . '/../includes/auth.php';

$cliente = exigir_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

if (!validar_csrf_token($body['csrf_token'] ?? null)) {
    http_response_code(403);
    exit;
}

$tema = $body['tema'] ?? '';
if (!in_array($tema, ['claro', 'oscuro'], true)) {
    http_response_code(400);
    exit;
}

$pdo = db();
$upd = $pdo->prepare('UPDATE clientes SET tema = ? WHERE id = ?');
$upd->execute([$tema, $cliente['id']]);

http_response_code(204);
