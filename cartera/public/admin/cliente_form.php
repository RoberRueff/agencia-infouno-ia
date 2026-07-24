<?php
require_once __DIR__ . '/../../includes/admin_auth.php';
require_once __DIR__ . '/../../includes/auditoria.php';
require_once __DIR__ . '/../../includes/helpers.php';
exigir_admin();

$pdo = db();

$rubros = ['Metalúrgica', 'Agro', 'Autopartes', 'Construcción', 'Textil', 'Alimenticia', 'Otro'];
$estados = ['onboarding', 'activo', 'pausado', 'cancelado'];

$id = isset($_GET['id']) ? (int) $_GET['id'] : (isset($_POST['id']) ? (int) $_POST['id'] : null);
$cliente = [
    'nombre_empresa' => '',
    'rubro_industrial' => 'Otro',
    'contacto_whatsapp_vendedor' => '',
    'email_dueno' => '',
    'domicilio' => '',
    'cpl_maximo_permitido' => '',
    'sla_horas_objetivo' => 24,
    'estado_cuenta' => 'onboarding',
    'google_ads_customer_id' => '',
    'meta_ad_account_id' => '',
    'fecha_alta' => date('Y-m-d'),
];

if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM clientes WHERE id = ?');
    $stmt->execute([$id]);
    $existente = $stmt->fetch();
    if (!$existente) {
        http_response_code(404);
        exit('Cliente no encontrado.');
    }
    $cliente = $existente;
}

$error = null;
$guardado = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validar_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Sesión inválida, recargá la página e intentá de nuevo.';
    } else {
        $cliente['nombre_empresa'] = trim($_POST['nombre_empresa'] ?? '');
        $cliente['rubro_industrial'] = in_array($_POST['rubro_industrial'] ?? '', $rubros, true) ? $_POST['rubro_industrial'] : 'Otro';
        $cliente['contacto_whatsapp_vendedor'] = trim($_POST['contacto_whatsapp_vendedor'] ?? '');
        $cliente['email_dueno'] = trim($_POST['email_dueno'] ?? '');
        $cliente['domicilio'] = trim($_POST['domicilio'] ?? '') ?: null;
        $cliente['cpl_maximo_permitido'] = (float) ($_POST['cpl_maximo_permitido'] ?? 0);
        $cliente['sla_horas_objetivo'] = (int) ($_POST['sla_horas_objetivo'] ?? 24);
        $cliente['estado_cuenta'] = in_array($_POST['estado_cuenta'] ?? '', $estados, true) ? $_POST['estado_cuenta'] : 'onboarding';
        $cliente['google_ads_customer_id'] = trim($_POST['google_ads_customer_id'] ?? '') ?: null;
        $cliente['meta_ad_account_id'] = trim($_POST['meta_ad_account_id'] ?? '') ?: null;
        $cliente['fecha_alta'] = $_POST['fecha_alta'] ?? date('Y-m-d');
        $passwordNueva = $_POST['password'] ?? '';

        if ($cliente['nombre_empresa'] === '' || $cliente['email_dueno'] === '' || $cliente['contacto_whatsapp_vendedor'] === '') {
            $error = 'Nombre de empresa, email y WhatsApp del vendedor son obligatorios.';
        } elseif ($cliente['sla_horas_objetivo'] < 1) {
            $error = 'El objetivo de SLA (horas) debe ser un número entero de 1 o más.';
        } elseif (!$id && $passwordNueva === '') {
            $error = 'La contraseña de acceso al dashboard es obligatoria para un cliente nuevo.';
        } else {
            $esEdicion = (bool) $id;
            if ($id) {
                $params = [
                    'nombre_empresa' => $cliente['nombre_empresa'],
                    'rubro_industrial' => $cliente['rubro_industrial'],
                    'contacto_whatsapp_vendedor' => $cliente['contacto_whatsapp_vendedor'],
                    'email_dueno' => $cliente['email_dueno'],
                    'domicilio' => $cliente['domicilio'],
                    'cpl_maximo_permitido' => $cliente['cpl_maximo_permitido'],
                    'sla_horas_objetivo' => $cliente['sla_horas_objetivo'],
                    'estado_cuenta' => $cliente['estado_cuenta'],
                    'google_ads_customer_id' => $cliente['google_ads_customer_id'],
                    'meta_ad_account_id' => $cliente['meta_ad_account_id'],
                    'fecha_alta' => $cliente['fecha_alta'],
                    'id' => $id,
                ];
                $campoPassword = '';

                if ($passwordNueva !== '') {
                    $campoPassword = ', password_hash = :password_hash';
                    $params['password_hash'] = password_hash($passwordNueva, PASSWORD_DEFAULT);
                }

                $stmt = $pdo->prepare("
                    UPDATE clientes SET
                        nombre_empresa = :nombre_empresa,
                        rubro_industrial = :rubro_industrial,
                        contacto_whatsapp_vendedor = :contacto_whatsapp_vendedor,
                        email_dueno = :email_dueno,
                        domicilio = :domicilio,
                        cpl_maximo_permitido = :cpl_maximo_permitido,
                        sla_horas_objetivo = :sla_horas_objetivo,
                        estado_cuenta = :estado_cuenta,
                        google_ads_customer_id = :google_ads_customer_id,
                        meta_ad_account_id = :meta_ad_account_id,
                        fecha_alta = :fecha_alta
                        $campoPassword
                    WHERE id = :id
                ");
                $stmt->execute($params);
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO clientes (
                        nombre_empresa, rubro_industrial, contacto_whatsapp_vendedor, email_dueno,
                        domicilio, cpl_maximo_permitido, sla_horas_objetivo, estado_cuenta, google_ads_customer_id, meta_ad_account_id,
                        fecha_alta, password_hash
                    ) VALUES (
                        :nombre_empresa, :rubro_industrial, :contacto_whatsapp_vendedor, :email_dueno,
                        :domicilio, :cpl_maximo_permitido, :sla_horas_objetivo, :estado_cuenta, :google_ads_customer_id, :meta_ad_account_id,
                        :fecha_alta, :password_hash
                    )
                ');
                $params = $cliente;
                $params['password_hash'] = password_hash($passwordNueva, PASSWORD_DEFAULT);
                $stmt->execute($params);
                $id = (int) $pdo->lastInsertId();
            }
            $guardado = true;

            $ETIQUETAS_CLIENTE = [
                'nombre_empresa' => 'nombre', 'rubro_industrial' => 'rubro',
                'contacto_whatsapp_vendedor' => 'WhatsApp vendedor', 'email_dueno' => 'email',
                'domicilio' => 'domicilio', 'cpl_maximo_permitido' => 'CPL máx',
                'sla_horas_objetivo' => 'SLA horas', 'estado_cuenta' => 'estado',
                'google_ads_customer_id' => 'Google Ads ID', 'meta_ad_account_id' => 'Meta Ads ID',
                'fecha_alta' => 'fecha alta',
            ];
            if ($esEdicion) {
                $detalle = diff_campos($existente, $cliente, $ETIQUETAS_CLIENTE);
                if ($passwordNueva !== '') {
                    $detalle = ($detalle === '' ? '' : $detalle . ', ') . 'contraseña del dashboard reseteada';
                }
                if ($detalle === '') { $detalle = 'sin cambios'; }
                registrar_auditoria($pdo, 'cliente.editar', 'cliente', (int) $id, $detalle);
            } else {
                registrar_auditoria($pdo, 'cliente.crear', 'cliente', (int) $id, 'alta de cliente ' . $cliente['nombre_empresa']);
            }
        }
    }
}

$csrf = generar_csrf_token();
$tema = admin_tema_actual();
?>
<!doctype html>
<html lang="es-AR"<?= $tema !== 'auto' ? ' data-theme="' . htmlspecialchars($tema) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin — <?= $id ? 'Editar cliente' : 'Nuevo cliente' ?></title>
<link rel="stylesheet" href="../assets/style.css?v=<?= @filemtime(__DIR__ . '/../assets/style.css') ?>">
</head>
<body>
  <header class="topbar">
    <h1><?= $id ? 'Editar cliente' : 'Nuevo cliente' ?></h1>
    <nav>
      <?= render_btn_tema($tema, $csrf) ?>
      &nbsp;|&nbsp;
      <a href="cartera.php" class="logout-link">Cartera</a>
      &nbsp;|&nbsp;
      <a href="clientes.php" class="logout-link">← Volver al listado</a>
    </nav>
  </header>

  <main class="dashboard">
    <?php if ($error): ?>
      <p class="alerta-error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <?php if ($guardado): ?>
      <p class="alerta-ok">Guardado correctamente.</p>
    <?php endif; ?>

    <form method="post" class="form-card" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <?php if ($id): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>

      <label for="nombre_empresa">Nombre de la empresa *</label>
      <input type="text" id="nombre_empresa" name="nombre_empresa" required value="<?= htmlspecialchars($cliente['nombre_empresa']) ?>">

      <label for="rubro_industrial">Rubro industrial</label>
      <select id="rubro_industrial" name="rubro_industrial">
        <?php foreach ($rubros as $r): ?>
          <option value="<?= htmlspecialchars($r) ?>" <?= $cliente['rubro_industrial'] === $r ? 'selected' : '' ?>><?= htmlspecialchars($r) ?></option>
        <?php endforeach; ?>
      </select>

      <label for="contacto_whatsapp_vendedor">WhatsApp del vendedor (con código de país, ej 549...) *</label>
      <input type="text" id="contacto_whatsapp_vendedor" name="contacto_whatsapp_vendedor" required value="<?= htmlspecialchars($cliente['contacto_whatsapp_vendedor']) ?>">

      <label for="email_dueno">Email del dueño/gerente *</label>
      <input type="email" id="email_dueno" name="email_dueno" required value="<?= htmlspecialchars($cliente['email_dueno']) ?>">

      <label for="domicilio">Domicilio del responsable (para la política de privacidad — Ley 25.326)</label>
      <input type="text" id="domicilio" name="domicilio" value="<?= htmlspecialchars($cliente['domicilio'] ?? '') ?>">

      <label for="cpl_maximo_permitido">CPL máximo permitido ($)</label>
      <input type="number" step="0.01" id="cpl_maximo_permitido" name="cpl_maximo_permitido" value="<?= htmlspecialchars((string) $cliente['cpl_maximo_permitido']) ?>">

      <label for="sla_horas_objetivo">Objetivo de respuesta al lead (horas)</label>
      <input type="number" step="1" min="1" id="sla_horas_objetivo" name="sla_horas_objetivo" value="<?= htmlspecialchars((string) $cliente['sla_horas_objetivo']) ?>">

      <label for="estado_cuenta">Estado de la cuenta</label>
      <select id="estado_cuenta" name="estado_cuenta">
        <?php foreach ($estados as $e): ?>
          <option value="<?= htmlspecialchars($e) ?>" <?= $cliente['estado_cuenta'] === $e ? 'selected' : '' ?>><?= htmlspecialchars($e) ?></option>
        <?php endforeach; ?>
      </select>

      <label for="google_ads_customer_id">Google Ads Customer ID</label>
      <input type="text" id="google_ads_customer_id" name="google_ads_customer_id" value="<?= htmlspecialchars($cliente['google_ads_customer_id'] ?? '') ?>">

      <label for="meta_ad_account_id">Meta Ad Account ID</label>
      <input type="text" id="meta_ad_account_id" name="meta_ad_account_id" value="<?= htmlspecialchars($cliente['meta_ad_account_id'] ?? '') ?>">

      <label for="fecha_alta">Fecha de alta</label>
      <input type="date" id="fecha_alta" name="fecha_alta" value="<?= htmlspecialchars(is_string($cliente['fecha_alta']) ? substr($cliente['fecha_alta'], 0, 10) : $cliente['fecha_alta']) ?>">

      <label for="password"><?= $id ? 'Nueva contraseña del dashboard (dejar vacío para no cambiarla)' : 'Contraseña de acceso al dashboard *' ?></label>
      <input type="password" id="password" name="password" <?= $id ? '' : 'required' ?>>

      <button type="submit">Guardar</button>
    </form>
  </main>
  <script src="../assets/tema-toggle.js?v=<?= @filemtime(__DIR__ . '/../assets/tema-toggle.js') ?>"></script>
</body>
</html>
