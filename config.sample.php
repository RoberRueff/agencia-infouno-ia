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

  // --- Agente conversacional (LLM con function calling) ---
  // El bot funciona con cualquier API compatible con OpenAI Chat Completions que soporte
  // tool calling. Pegá la API key SOLO acá, en el server. Si queda vacía, el bot usa el guion.
  'openai_key'   => '',              // la key del proveedor que apunte 'api_base' (sk-... o AIza...)
  //
  // 'api_base' define a qué proveedor se llama. Ejemplos:
  //   OpenAI  → 'https://api.openai.com/v1/chat/completions'              + model 'gpt-4o-mini'
  //   Gemini  → 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions' + model 'gemini-2.5-flash'
  // Si lo dejás vacío, usa OpenAI por defecto.
  'api_base'     => 'https://api.openai.com/v1/chat/completions',
  'openai_model' => 'gpt-4o-mini',   // OpenAI: 'gpt-4o-mini' / 'gpt-4o' · Gemini: 'gemini-2.5-flash'
  'chat_enabled' => true,            // false = forzar el guion scripteado

  // --- Alerta de lead VIP por WhatsApp (vía Make) ---
  // URL del webhook del escenario de Make. Vacío = alerta VIP desactivada (no cambia nada).
  'make_webhook_url' => '',
  // Secreto compartido que Make valida en el payload (poné cualquier string largo y random).
  'make_token'       => '',
];
