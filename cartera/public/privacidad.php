<?php
/**
 * Política de privacidad pública, personalizada por cliente (?cliente=ID).
 * Se linkea desde el checkbox de consentimiento de landing.php.
 *
 * El "responsable del tratamiento de los datos" (Ley 25.326, art. 2) es la
 * PyME cliente cuyo anuncio generó el lead — es ella quien va a contactar al
 * titular de los datos, no la plataforma. Por eso esta página toma el nombre
 * de la empresa desde `clientes` en vez de tener un texto fijo.
 *
 * IMPORTANTE: este texto es una plantilla de buena fe con la estructura
 * mínima que exige la Ley 25.326 (finalidad, carácter de los datos, derechos
 * ARCO). No reemplaza la revisión de un abogado antes de salir a producción.
 */

require_once __DIR__ . '/../includes/db.php';

$clienteId = (int) ($_GET['cliente'] ?? 0);

$pdo = db();
// Filtra estado_cuenta='activo' igual que el resto de los endpoints que
// resuelven un cliente por un dato externo (ver ai/checks.md): así no se
// puede enumerar ?cliente=1,2,3... ni exponer el email de clientes
// pausados/cancelados.
$clienteStmt = $pdo->prepare("SELECT nombre_empresa, email_dueno, domicilio FROM clientes WHERE id = ? AND estado_cuenta = 'activo'");
$clienteStmt->execute([$clienteId]);
$cliente = $clienteStmt->fetch();

if (!$cliente) {
    http_response_code(404);
    exit('Página no disponible.');
}

$ogDescripcion = 'Política de Privacidad de ' . htmlspecialchars($cliente['nombre_empresa']) . ' para el tratamiento de datos personales (Ley 25.326).';
?>
<!doctype html>
<html lang="es-AR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="description" content="<?= $ogDescripcion ?>">
<title>Política de Privacidad — <?= htmlspecialchars($cliente['nombre_empresa']) ?></title>
<meta property="og:type" content="website">
<meta property="og:locale" content="es_AR">
<meta property="og:title" content="Política de Privacidad — <?= htmlspecialchars($cliente['nombre_empresa']) ?>">
<meta property="og:description" content="<?= $ogDescripcion ?>">
<link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body>
  <header class="topbar">
    <h1>Política de Privacidad</h1>
  </header>
  <main class="dashboard" style="max-width: 640px;">
    <section class="glosario">
      <p><strong><?= htmlspecialchars($cliente['nombre_empresa']) ?></strong> es responsable del tratamiento de los datos personales que dejás en este formulario, en los términos de la Ley 25.326 de Protección de Datos Personales de la República Argentina. Los datos que nos brindás se incorporan a una base de datos cuyo responsable es <?= htmlspecialchars($cliente['nombre_empresa']) ?><?php if (!empty($cliente['domicilio'])): ?>, con domicilio en <?= htmlspecialchars($cliente['domicilio']) ?><?php endif; ?>.</p>

      <p><strong>Qué datos pedimos y para qué.</strong> Nombre y número de WhatsApp, con la única finalidad de que un vendedor de <?= htmlspecialchars($cliente['nombre_empresa']) ?> se contacte con vos para responder tu consulta. No usamos estos datos con fines distintos ni los cedemos a terceros ajenos a esa finalidad.</p>

      <p><strong>Carácter de los datos.</strong> Brindar tus datos es voluntario. Si no los proporcionás, simplemente no vamos a poder contactarte. Si los datos que dejás son inexactos o están desactualizados (por ejemplo, un número de WhatsApp equivocado), es posible que no podamos comunicarnos con vos.</p>

      <p><strong>Tus derechos.</strong> Como titular de los datos, tenés derecho a acceder, rectificar, actualizar y suprimir tus datos. Para ejercerlos, escribinos a <?= htmlspecialchars($cliente['email_dueno']) ?>. La Agencia de Acceso a la Información Pública, en su carácter de Órgano de Control de la Ley 25.326, tiene la atribución de atender las denuncias y reclamos que se interpongan con relación al incumplimiento de las normas sobre protección de datos personales (<a href="https://www.argentina.gob.ar/aaip" target="_blank" rel="noopener">www.argentina.gob.ar/aaip</a>).</p>

      <p><strong>Conservación.</strong> Conservamos tus datos mientras dure la gestión comercial de tu consulta y por el plazo que exija la normativa aplicable.</p>
    </section>
  </main>
</body>
</html>
