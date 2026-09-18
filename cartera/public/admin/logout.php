<?php
require_once __DIR__ . '/../../includes/admin_auth.php';

// Solo POST con token CSRF válido: evita que un tercero fuerce el cierre de sesión
// con un GET (p. ej. <img src="logout.php">). Un intento inválido no cierra nada.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validar_csrf_token($_POST['csrf_token'] ?? null)) {
    header('Location: cartera.php');
    exit;
}
cerrar_sesion_admin();
header('Location: login.php');
exit;
