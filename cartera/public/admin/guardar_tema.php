<?php
/**
 * Guarda la preferencia de tema (claro/oscuro) del admin logueado.
 * Mismo contrato que public/guardar_tema.php (cliente), pero para la
 * sesión de admin. El acceso de emergencia break-glass no tiene fila en
 * usuarios_admin — responde 204 igual, sin ejecutar ningún UPDATE.
 *
 * POST JSON: { "tema": "claro"|"oscuro", "csrf_token": "..." }
 */

require_once __DIR__ . '/../../includes/admin_auth.php';

exigir_admin();

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

$admin = admin_actual();
if ($admin !== null && $admin['id'] !== null) {
    $pdo = db();
    $upd = $pdo->prepare('UPDATE usuarios_admin SET tema = ? WHERE id = ?');
    $upd->execute([$tema, $admin['id']]);
}

http_response_code(204);
