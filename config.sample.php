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

  // --- Motor de IA (LLM) — COMPARTIDO por el bot (chat.php), Método UNO y Método DOS ---
  //
  // ⚠️ IMPORTANTE sobre el nombre "openai_*": estas claves se llaman así porque el código
  // usa el FORMATO de OpenAI (Chat Completions), NO porque uses OpenAI. Funcionan con
  // CUALQUIER proveedor compatible con OpenAI. El proveedor real lo define 'api_base':
  //   • OpenAI  → api_base 'https://api.openai.com/v1/chat/completions'                       + openai_model 'gpt-4o-mini'
  //   • Gemini  → api_base 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions' + openai_model 'gemini-2.5-flash'
  //
  // Pegá UNA sola API key acá (la comparten los tres). Va SOLO en el server, nunca en el
  // frontend. Si queda vacía, el bot cae al guion scripteado (y UNO/DOS no generan el texto).
  // Los valores de abajo apuntan a GEMINI, que es lo que usa el sitio hoy.
  'openai_key'   => '',              // key del proveedor de 'api_base' (Gemini o OpenAI 'sk-...')
  'api_base'     => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
  'openai_model' => 'gemini-2.5-flash',   // debe coincidir con el proveedor de 'api_base'
  'chat_enabled' => true,            // false = forzar el guion scripteado (sin IA)

  // --- Rate limiting de chat.php (anti-abuso del endpoint pago, hallazgo H1) ---
  // Si se omiten, usan los defaults. Subí/bajá según tu gasto y tráfico real.
  'rate_per_min'      => 15,     // máx. requests por IP por minuto
  'rate_per_hour'     => 60,     // máx. requests por IP por hora
  'rate_daily_global' => 1500,   // techo total de llamadas al LLM por día (salvavidas del presupuesto)
  // false (default seguro): usa REMOTE_ADDR. Poné true SOLO si estás detrás de un proxy de
  // confianza que setea X-Forwarded-For (ej. Cloudflare); si no, sería spoofeable.
  'trust_forwarded'   => false,

  // --- Alerta de lead VIP por WhatsApp (vía Make) ---
  // URL del webhook del escenario de Make. Vacío = alerta VIP desactivada (no cambia nada).
  'make_webhook_url' => '',
  // Secreto compartido que Make valida en el payload (poné cualquier string largo y random).
  'make_token'       => '',
];
