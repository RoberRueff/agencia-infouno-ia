<?php
/**
 * Copiar este archivo como config.php (mismo directorio) y completar
 * con los datos reales. config.php NO debe subirse a git (ver .gitignore).
 *
 * En Donweb/cPanel: los datos de MySQL están en "Bases de Datos MySQL",
 * el host normalmente es "localhost".
 */

return [
    'db' => [
        'host' => 'localhost',
        'name' => 'usuario_basedatos',
        'user' => 'usuario_dbuser',
        'pass' => 'CAMBIAR_ESTA_PASSWORD',
        'charset' => 'utf8mb4',
    ],

    'google_ads' => [
        'developer_token' => 'TU_DEVELOPER_TOKEN',
        'client_id' => 'TU_CLIENT_ID.apps.googleusercontent.com',
        'client_secret' => 'TU_CLIENT_SECRET',
        'refresh_token' => 'TU_REFRESH_TOKEN',
        'login_customer_id' => 'ID_CUENTA_MCC_SIN_GUIONES',
    ],

    'meta' => [
        // System User Token de larga duración con permisos ads_read
        'access_token' => 'TU_META_ACCESS_TOKEN',
        'graph_api_version' => 'v19.0',
    ],

    'whatsapp' => [
        'phone_number_id' => 'TU_PHONE_NUMBER_ID',
        'access_token' => 'TU_WHATSAPP_CLOUD_API_TOKEN',
        'graph_api_version' => 'v19.0',
        'webhook_verify_token' => 'UN_STRING_SECRETO_PARA_VERIFICAR_EL_WEBHOOK',
        // App Secret de la app de Meta (Configuración básica → Clave secreta de la app).
        // Se usa para verificar la firma X-Hub-Signature-256 de cada POST entrante
        // al webhook y descartar mensajes que no vengan realmente de Meta.
        'app_secret' => 'TU_META_APP_SECRET',
        'admin_interno_whatsapp' => '5491100000000',
        // Modo prueba/dry-run: si es true, WhatsAppClient NO envía nada a la Graph
        // API — loguea el mensaje que se habría mandado y simula éxito. Sirve para
        // correr los crons en un entorno de prueba sin disparar WhatsApp real a
        // vendedores/clientes. En producción debe ser false (o ausente).
        'modo_prueba' => false,
    ],

    'app' => [
        'timezone' => 'America/Argentina/Buenos_Aires',
        'dashboard_base_url' => 'https://tudominio.com.ar/dashboard.php',
        'session_name' => 'mkt_saas_session',
        // Clave compartida que debe mandar cualquier landing page / integración
        // externa en el header "X-Api-Key" al llamar a public/lead_capture.php.
        // Generar una random larga, ej: bin2hex(random_bytes(32)) en un script PHP.
        'lead_capture_api_key' => 'CAMBIAR_POR_UNA_CLAVE_LARGA_Y_ALEATORIA',
        // Acceso de EMERGENCIA (break-glass): esta password + 2FA siguen
        // funcionando aunque no haya usuarios en la tabla usuarios_admin, y
        // sirven de bootstrap inicial. Los usuarios normales del panel (con
        // rol dueño/operador y 2FA por persona) viven en la tabla usuarios_admin.
        // Hash de la contraseña del panel de administración (public/admin/).
        // Generar así (una vez, en un script PHP temporal o en un REPL):
        //   echo password_hash('tu-contraseña-elegida', PASSWORD_DEFAULT);
        // Pegar el resultado acá. Nunca poner la contraseña en texto plano.
        'admin_password_hash' => 'CAMBIAR_POR_EL_RESULTADO_DE_password_hash()',
        // 2FA (TOTP) del panel de administración. OPCIONAL: si se deja vacío, el
        // login del admin es solo contraseña. Para activarlo, generar un secreto
        // (una vez) y cargarlo en la app autenticadora (Google Authenticator,
        // Authy, 1Password, etc.):
        //   require 'includes/totp.php';
        //   $s = totp_generar_secreto();
        //   echo "Secreto (cargar en la app o pegar acá): $s\n";
        //   echo "URI: " . totp_otpauth_uri($s, 'admin', 'Marketing PyMEs') . "\n";
        // Pegar el secreto (base32) acá. Si perdés el dispositivo, cambiá o
        // vaciá este valor desde el server para recuperar el acceso.
        'admin_totp_secret' => '',
    ],
];
