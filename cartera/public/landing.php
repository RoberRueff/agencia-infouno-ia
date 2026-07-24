<?php
/**
 * Landing page pública de captura de leads — una URL por cliente
 * (?cliente=ID), pensada para usar como destino de los anuncios de Google Ads
 * o Meta Ads de esa PyME.
 *
 * A diferencia de lead_capture.php (pensado para integraciones server-to-server
 * con una API key), esta página la carga un visitante anónimo en el navegador:
 * no puede llevar ningún secreto (quedaría visible en el HTML/JS). Se protege
 * con un campo honeypot (los bots lo completan, un humano no lo ve) en vez de
 * una API key.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$clienteId = (int) ($_GET['cliente'] ?? $_POST['cliente'] ?? 0);

$pdo = db();
$clienteStmt = $pdo->prepare("SELECT id, nombre_empresa FROM clientes WHERE id = ? AND estado_cuenta = 'activo'");
$clienteStmt->execute([$clienteId]);
$cliente = $clienteStmt->fetch();

if (!$cliente) {
    http_response_code(404);
    exit('Página no disponible.');
}

// Detecta el origen del tráfico por los parámetros que agregan las propias
// plataformas de ads, si no viene explícito por ?fuente=
$fuente = $_GET['fuente'] ?? $_POST['fuente'] ?? null;
if (!in_array($fuente, ['google', 'meta'], true)) {
    if (isset($_GET['gclid'])) {
        $fuente = 'google';
    } elseif (isset($_GET['fbclid'])) {
        $fuente = 'meta';
    } else {
        $fuente = 'google';
    }
}

$error = null;
$enviado = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validar_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'La página quedó desactualizada, recargá y volvé a intentar.';
    } elseif (trim($_POST['sitio_web'] ?? '') !== '') {
        // Honeypot completado -> es un bot. Fingimos éxito sin guardar nada.
        $enviado = true;
    } elseif (landing_excede_limite($_SERVER['REMOTE_ADDR'] ?? 'desconocida')) {
        // Demasiados envíos desde esta IP en poco tiempo (flood de bots).
        // Mismo mensaje de éxito, sin registrar, para no delatar el límite.
        $enviado = true;
    } else {
        $nombreLead = trim($_POST['nombre_lead'] ?? '');
        $telefonoLead = trim($_POST['telefono_lead'] ?? '');
        $aceptaPrivacidad = isset($_POST['acepto_privacidad']);

        if ($nombreLead === '' || $telefonoLead === '') {
            $error = 'Completá tu nombre y tu WhatsApp para que te contactemos.';
        } elseif (!$aceptaPrivacidad) {
            $error = 'Para continuar, tenés que aceptar la Política de Privacidad.';
        } else {
            $leadId = registrar_lead($clienteId, $nombreLead, $telefonoLead, $fuente, true);
            $enviado = $leadId !== null;
            if (!$enviado) {
                $error = 'No pudimos registrar tu consulta. Intentá de nuevo en unos minutos.';
            }
        }
    }
}

$csrf = generar_csrf_token();

// URL absoluta de esta landing (sin gclid/fbclid u otros parámetros de
// tracking) para og:url — así el preview que arma WhatsApp/Facebook al
// compartir el link queda estable y no reproduce el click id de un ad.
$urlCanonica = (conexion_es_https() ? 'https' : 'http') . '://'
    . ($_SERVER['HTTP_HOST'] ?? 'balanzassip.com.ar')
    . '/landing.php?cliente=' . (int) $cliente['id'];
$ogDescripcion = htmlspecialchars($cliente['nombre_empresa']) . ': dejanos tus datos y te contactamos por WhatsApp.';
?>
<!doctype html>
<html lang="es-AR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, follow">
<meta name="description" content="<?= $ogDescripcion ?>">
<title><?= htmlspecialchars($cliente['nombre_empresa']) ?> — Dejanos tu consulta</title>
<meta property="og:type" content="website">
<meta property="og:locale" content="es_AR">
<meta property="og:site_name" content="<?= htmlspecialchars($cliente['nombre_empresa']) ?>">
<meta property="og:title" content="<?= htmlspecialchars($cliente['nombre_empresa']) ?> — Dejanos tu consulta">
<meta property="og:description" content="<?= $ogDescripcion ?>">
<meta property="og:url" content="<?= htmlspecialchars($urlCanonica) ?>">
<link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body class="login-page">
  <main class="login-box">
    <?php if ($enviado): ?>
      <h1>¡Gracias!</h1>
      <p>Recibimos tu consulta. Un asesor de <?= htmlspecialchars($cliente['nombre_empresa']) ?> se va a comunicar con vos a la brevedad.</p>
    <?php else: ?>
      <h1><?= htmlspecialchars($cliente['nombre_empresa']) ?></h1>
      <p>Dejanos tus datos y te contactamos.</p>
      <?php if ($error): ?>
        <p class="alerta-error"><?= htmlspecialchars($error) ?></p>
      <?php endif; ?>
      <form method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="cliente" value="<?= (int) $cliente['id'] ?>">
        <input type="hidden" name="fuente" value="<?= htmlspecialchars($fuente) ?>">

        <!-- Honeypot: invisible para una persona, un bot suele completar todos los campos -->
        <div style="position:absolute; left:-9999px;" aria-hidden="true">
          <label for="sitio_web">No completar este campo</label>
          <input type="text" id="sitio_web" name="sitio_web" tabindex="-1" autocomplete="off">
        </div>

        <label for="nombre_lead">Nombre y apellido</label>
        <input type="text" id="nombre_lead" name="nombre_lead" required autofocus>

        <label for="telefono_lead">WhatsApp (con código de área)</label>
        <input type="tel" id="telefono_lead" name="telefono_lead" required placeholder="011 5555-5555">

        <label style="display:flex; align-items:flex-start; gap:0.5rem; font-size:0.8rem; color:var(--muted);">
          <input type="checkbox" id="acepto_privacidad" name="acepto_privacidad" required style="margin-top:0.2rem;">
          <span>Acepto que mis datos se usen para que <?= htmlspecialchars($cliente['nombre_empresa']) ?> se comunique conmigo, según la <a href="privacidad.php?cliente=<?= (int) $cliente['id'] ?>" target="_blank" rel="noopener" style="color:inherit;">Política de Privacidad</a>.</span>
        </label>

        <button type="submit">Quiero que me contacten</button>
      </form>
    <?php endif; ?>
  </main>
</body>
</html>
