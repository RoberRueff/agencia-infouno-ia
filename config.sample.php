<?php
/* =====================================================================
   Infouno — Configuración del backend de leads
   ---------------------------------------------------------------------
   1) En cPanel (DonWeb) → "MySQL Databases":
        - Creá una base de datos.
        - Creá un usuario y asignale TODOS los permisos sobre esa base.
   2) Completá los datos de abajo con esos valores reales.
   3) Subí este archivo a la raíz del sitio (junto a lead.php).

   ⚠️ No publiques este archivo en repositorios públicos: tiene credenciales.
   ===================================================================== */

return [
  // --- Base de datos (cPanel suele prefijar usuario y base con tu cuenta) ---
  'db_host' => 'localhost',
  'db_name' => 'TU_BASE',          // ej: c1234567_infouno
  'db_user' => 'TU_USUARIO',       // ej: c1234567_infouno
  'db_pass' => 'TU_PASSWORD',

  // --- Notificación por email de cada lead nuevo ---
  'notify_email' => 'ventas@infouno.com.ar',     // a dónde llegan los avisos
  'from_email'   => 'no-reply@infouno.com.ar',   // remitente (mejor un buzón de tu dominio)

  // --- Agente conversacional (OpenAI) ---
  // Pegá tu API key (sk-...) SOLO acá, en el server. Si queda vacía, el bot usa el guion scripteado.
  'openai_key'   => '',
  'openai_model' => 'gpt-4o-mini',   // cambiable a 'gpt-4o' si querés
  'chat_enabled' => true,            // false = forzar el guion scripteado
];
