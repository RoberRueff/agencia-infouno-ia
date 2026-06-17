# Bot conversacional "Uno" con OpenAI — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convertir el bot scripteado "Uno" en un agente conversacional con OpenAI que capta leads vía function calling, persiste paso a paso y degrada al guion actual si la IA no está disponible.

**Architecture:** Frontend (`assets/site.js`) conversa contra un nuevo endpoint `chat.php` (DonWeb/PHP) que hace de proxy a OpenAI con `tools`. La persistencia/scoring/email se extrae a `db_lead.php`, compartida por `lead.php` y `chat.php`. El cierre (WhatsApp/Cal.com) lo maneja el frontend.

**Tech Stack:** PHP 8 (mysqli, curl) sobre cPanel/DonWeb · OpenAI Chat Completions API (`gpt-4o-mini`) · JS vanilla · MySQL/MariaDB.

## Global Constraints

- **Clave OpenAI solo en backend** (`config.php`), nunca en el frontend ni en el repo público.
- **Modelo por defecto:** `gpt-4o-mini`, `temperature = 0.3`. Configurable en `config.php`.
- **Fallback obligatorio:** si no hay `openai_key`, `chat_enabled=false`, o `chat.php` falla/timeout → el bot usa el guion scripteado actual sin romperse.
- **Guardrails (`ai/guardrails.md`):** G1 (rechazar temas ajenos con el fallback textual), G2 (nunca dar precios), G3 (clave en backend + prepared statements + render seguro), G4 (mencionar privacidad antes de pedir contacto).
- **Captura (`ai/rules.md`):** objetivo rubro→nombre→web→equipo→contacto; persistir cada dato (R4); scoring/VIP en backend (R3).
- **Tope de costo:** máximo 16 turnos de usuario por conversación; `max_tokens=450` por respuesta; máximo 1500 caracteres por mensaje.
- **Entorno:** PHP no corre localmente; `php -l` y `curl` a los endpoints se ejecutan en el server tras subir. El JS se valida local con `node --check assets/site.js`.
- **Sin git:** el proyecto no es repo git; los pasos "Commit" se omiten salvo que se corra `git init`. Sustituir cada commit por la verificación indicada.

---

### Task 1: Configuración de OpenAI en `config.php`

**Files:**
- Modify: `config.php`

**Interfaces:**
- Produces: claves `openai_key` (string), `openai_model` (string), `chat_enabled` (bool) dentro del array que devuelve `config.php`.

- [ ] **Step 1: Agregar las claves de OpenAI al array de config**

En `config.php`, dentro del `return [ ... ];`, después de la línea `'from_email'   => 'no-reply@infouno.com.ar',` agregar:

```php
  // --- Agente conversacional (OpenAI) ---
  // Pegá tu API key (sk-...) SOLO acá, en el server. Si queda vacía, el bot usa el guion scripteado.
  'openai_key'   => '',
  'openai_model' => 'gpt-4o-mini',   // cambiable a 'gpt-4o' si querés
  'chat_enabled' => true,            // false = forzar el guion scripteado
```

- [ ] **Step 2: Verificar sintaxis (en el server)**

Run (en DonWeb, o donde haya PHP): `php -l config.php`
Expected: `No syntax errors detected in config.php`

- [ ] **Step 3: Verificación local de estructura**

Run: `grep -n "openai_key\|openai_model\|chat_enabled" config.php`
Expected: las 3 claves presentes.

---

### Task 2: Extraer `db_lead.php` y refactorizar `lead.php`

**Files:**
- Create: `db_lead.php`
- Modify: `lead.php`

**Interfaces:**
- Produces: `infouno_save_lead(array $cfg, array $in): array` → `['ok'=>bool, 'vip'=>int, 'scoring'=>int]` (o `['ok'=>false,'error'=>string]`). Helpers `s($v,$max)` y `nn($v)` con guardas `function_exists`.
- Consumes (en `lead.php` y luego `chat.php`): la función anterior.

- [ ] **Step 1: Crear `db_lead.php` con la lógica compartida**

Create `db_lead.php`:

```php
<?php
/* =====================================================================
   Infouno — Persistencia compartida de leads (usada por lead.php y chat.php)
   Sanitización + mapeo a taxonomía + scoring/VIP (R3) + upsert (R4) + email.
   ===================================================================== */

if (!function_exists('s')) {
  function s($v, $max = 190) {
    $v = is_string($v) ? trim($v) : '';
    $v = strip_tags($v);
    if (mb_strlen($v) > $max) $v = mb_substr($v, 0, $max);
    return $v;
  }
}
if (!function_exists('nn')) {
  function nn($v) { return ($v === '' || $v === null) ? null : $v; }
}

/**
 * Guarda/actualiza un lead por session_id. Devuelve ['ok'=>bool,'vip'=>int,'scoring'=>int].
 */
function infouno_save_lead($cfg, $in) {
  $session = s($in['session_id'] ?? '', 64);
  if ($session === '') return ['ok' => false, 'error' => 'session'];

  $source  = s($in['source']  ?? 'bot', 20);
  $name    = s($in['name']    ?? '', 120);
  $rubro   = s($in['rubro']   ?? ($in['interes'] ?? ''), 150);
  $company = s($in['empresa'] ?? '', 150);
  $message = s($in['mensaje'] ?? '', 1000);
  $webTxt  = mb_strtolower(s($in['web']    ?? '', 60));
  $eqTxt   = mb_strtolower(s($in['equipo'] ?? '', 60));
  $page    = s($in['page'] ?? '', 190);
  $utm_s   = s($in['utm_source']   ?? '', 120);
  $utm_m   = s($in['utm_medium']   ?? '', 120);
  $utm_c   = s($in['utm_campaign'] ?? '', 150);

  // Check de sintaxis telefónica (Argentina)
  $phone = preg_replace('/\D+/', '', s($in['whatsapp'] ?? ($in['phone'] ?? ''), 30));
  $phone = preg_replace('/^0/', '', $phone);
  $phoneValid = strlen($phone) >= 8;

  // Check de dominio de email
  $email = s($in['email'] ?? '', 150);
  $disposable = ['mailinator.com','trashmail.com','10minutemail.com','guerrillamail.com','tempmail.com','yopmail.com','trash-mail.com'];
  $emailValid = false;
  if ($email !== '') {
    $emailValid = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    $dom = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
    if (in_array($dom, $disposable, true)) $emailValid = false;
  }
  if ($email !== '' && !$emailValid) $email = '';

  // Mapeo a la taxonomía
  $infra = null;
  if ($webTxt !== '') $infra = (strpos($webTxt, 'tengo') !== false || strpos($webTxt, 'rehacer') !== false) ? 'has_web' : 'no_web';
  $size = null;
  if ($eqTxt !== '') {
    if (strpos($eqTxt, 'grande') !== false || strpos($eqTxt, '+5') !== false) $size = 'team_large';
    elseif (strpos($eqTxt, 'chico') !== false || strpos($eqTxt, 'equipo') !== false) $size = 'team_small';
    else $size = 'solo';
  }

  // Lead scoring (R3)
  $score = 0;
  if ($infra === 'has_web')       $score += 40;
  if ($size === 'team_large')     $score += 50;
  elseif ($size === 'team_small') $score += 30;
  if ($phoneValid)                $score += 20;
  $vip = ($infra === 'has_web' && $size === 'team_large') ? 1 : 0;

  // Conexión
  $db = @new mysqli($cfg['db_host'], $cfg['db_user'], $cfg['db_pass'], $cfg['db_name']);
  if ($db->connect_errno) return ['ok' => false, 'error' => 'db'];
  $db->set_charset('utf8mb4');

  // ¿Ya existe? (para no notificar dos veces)
  $notified = 0;
  $q = $db->prepare('SELECT lead_notified FROM wp_infouno_leads WHERE session_id = ? LIMIT 1');
  $q->bind_param('s', $session);
  $q->execute();
  if ($r = $q->get_result()->fetch_assoc()) $notified = (int) $r['lead_notified'];
  $q->close();

  // Upsert por session_id (R4)
  $vName = nn($name); $vRubro = nn($rubro); $vCompany = nn($company); $vMessage = nn($message);
  $vPhone = nn($phone); $vEmail = nn($email); $vSource = nn($source); $vPage = nn($page);
  $vUs = nn($utm_s); $vUm = nn($utm_m); $vUc = nn($utm_c);

  $sql = 'INSERT INTO wp_infouno_leads
    (session_id, lead_name, lead_rubro, lead_company, lead_message, lead_infrastructure,
     lead_size, lead_phone, lead_email, lead_source, page, utm_source, utm_medium, utm_campaign,
     lead_scoring, lead_vip)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
     lead_name           = COALESCE(VALUES(lead_name), lead_name),
     lead_rubro          = COALESCE(VALUES(lead_rubro), lead_rubro),
     lead_company        = COALESCE(VALUES(lead_company), lead_company),
     lead_message        = COALESCE(VALUES(lead_message), lead_message),
     lead_infrastructure = COALESCE(VALUES(lead_infrastructure), lead_infrastructure),
     lead_size           = COALESCE(VALUES(lead_size), lead_size),
     lead_phone          = COALESCE(VALUES(lead_phone), lead_phone),
     lead_email          = COALESCE(VALUES(lead_email), lead_email),
     lead_source         = COALESCE(VALUES(lead_source), lead_source),
     page                = COALESCE(VALUES(page), page),
     utm_source          = COALESCE(VALUES(utm_source), utm_source),
     utm_medium          = COALESCE(VALUES(utm_medium), utm_medium),
     utm_campaign        = COALESCE(VALUES(utm_campaign), utm_campaign),
     lead_scoring        = GREATEST(lead_scoring, VALUES(lead_scoring)),
     lead_vip            = GREATEST(lead_vip, VALUES(lead_vip))';

  $stmt = $db->prepare($sql);
  $stmt->bind_param(
    'ssssssssssssssii',
    $session, $vName, $vRubro, $vCompany, $vMessage, $infra, $size, $vPhone, $vEmail,
    $vSource, $vPage, $vUs, $vUm, $vUc, $score, $vip
  );
  $ok = $stmt->execute();
  $stmt->close();

  // Notificación por email (una sola vez)
  $actionable = ($phoneValid || $emailValid || $source === 'form');
  if ($ok && $actionable && !$notified) {
    $subject = ($vip ? '[LEAD VIP] ' : '[Lead] ') . ($name !== '' ? $name : 'Nuevo contacto') . ($rubro !== '' ? ' — ' . $rubro : '');
    $body = implode("\n", [
      'Nombre:        ' . $name,
      'Rubro/Interés: ' . $rubro,
      'Empresa:       ' . $company,
      'WhatsApp:      ' . $phone,
      'Email:         ' . $email,
      'Web:           ' . $webTxt,
      'Equipo:        ' . $eqTxt,
      'Scoring:       ' . $score . ($vip ? '  (VIP)' : ''),
      'Origen:        ' . $source . ' · ' . $page,
      'UTM:           ' . trim($utm_s . ' / ' . $utm_m . ' / ' . $utm_c, ' /'),
      'Mensaje:       ' . $message,
    ]);
    $headers = 'From: ' . $cfg['from_email'] . "\r\n" . 'Content-Type: text/plain; charset=utf-8';
    @mail($cfg['notify_email'], $subject, $body, $headers);

    $u = $db->prepare('UPDATE wp_infouno_leads SET lead_notified = 1 WHERE session_id = ?');
    $u->bind_param('s', $session);
    $u->execute();
    $u->close();
  }

  $db->close();
  return ['ok' => (bool) $ok, 'vip' => $vip, 'scoring' => $score];
}
```

- [ ] **Step 2: Reemplazar `lead.php` por la versión fina que usa `db_lead.php`**

Replace todo el contenido de `lead.php` por:

```php
<?php
/* =====================================================================
   Infouno — Receptor de leads (formulario + bot scripteado)
   Delega la persistencia en db_lead.php (compartido con chat.php).
   ===================================================================== */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'method']);
  exit;
}

$cfg = require __DIR__ . '/config.php';

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'json']);
  exit;
}

require_once __DIR__ . '/db_lead.php';
$r = infouno_save_lead($cfg, $in);

if (!($r['ok'] ?? false)) {
  $err = $r['error'] ?? 'save';
  http_response_code($err === 'session' ? 400 : 500);
}
echo json_encode($r);
```

- [ ] **Step 3: Verificar sintaxis (en el server)**

Run: `php -l db_lead.php && php -l lead.php`
Expected: `No syntax errors detected` en ambos.

- [ ] **Step 4: Verificar que el formulario sigue guardando (en el server, tras subir)**

Run:
```bash
curl -sS -X POST https://infouno.com.ar/lead.php \
  -H 'Content-Type: application/json' \
  -d '{"session_id":"TEST-form-1","source":"form","name":"Prueba","interes":"Sitio web","empresa":"ACME"}'
```
Expected: `{"ok":true,"vip":0,"scoring":0}` y la fila aparece en `wp_infouno_leads` (verificar en phpMyAdmin).

---

### Task 3: Base de conocimiento `ai-kb/kb_infouno.md`

**Files:**
- Create: `ai-kb/kb_infouno.md`

**Interfaces:**
- Produces: archivo Markdown leído por `chat.php` e inyectado en el system prompt.

- [ ] **Step 1: Crear la base de conocimiento destilada del sitio**

Create `ai-kb/kb_infouno.md`:

```markdown
# Base de conocimiento — Infouno (para el asistente "Uno")

## Qué es Infouno
Consultora tecnológica argentina. Combina la estabilidad de WordPress + MySQL con IA real para que las PyMEs reduzcan costos y vendan más. Hecho en Argentina, trato cercano, orientado a resultados.

## Los 3 pilares de servicio
1. **Desarrollo Web con IA (producto estrella):** sitios institucionales y e-commerce veloces, autoadministrables y robustos sobre WordPress + MySQL. Catálogos que se actualizan solos, sugerencias automáticas de productos y SEO interno optimizado.
2. **Automatización de Procesos (upsell clave):** agentes IA integrados a WhatsApp y a la web que responden consultas, muestran catálogo y cierran ventas 24/7. Sincronización inteligente de stock, facturación y bases de datos, eliminando carga manual.
3. **SEO Potenciado con IA:** optimización de palabras clave y estructura, creación de contenido optimizado y análisis de competencia para ganar posiciones en Google.

## A quién atiende
PyMEs y profesionales de toda la Argentina: comercios, e-commerce/retail, servicios profesionales (estudios contables, jurídicos, salud, etc.), oficios y emprendimientos.

## Tono
Cercano, argentino (voseo), claro y directo. Cero tecnicismos innecesarios. Empático con el dolor del dueño de PyME (tiempo perdido en tareas repetitivas, no aparecer en Google, atender de madrugada).

## Política de precios (G2 — ESTRICTO)
NUNCA dar precios ni estimaciones de montos. Los costos varían según el nivel de automatización y se definen exclusivamente en una consultoría gratuita de 15 minutos por Google Meet.

## Cierre / objetivo comercial
Llevar a una consultoría gratuita de 15 min (Google Meet). Coordinación por WhatsApp o agenda online.

## Datos a captar del lead (en orden natural, sin interrogar)
rubro del negocio · nombre · si tiene web (ya tengo / arranco de cero / quiero rehacerla) · tamaño de equipo (solo / chico 2-5 / grande +5) · WhatsApp · email (opcional).
```

- [ ] **Step 2: Verificar que existe y tiene contenido**

Run: `wc -l ai-kb/kb_infouno.md && grep -c "pilares\|precios" ai-kb/kb_infouno.md`
Expected: archivo con contenido y secciones presentes.

---

### Task 4: Endpoint `chat.php` (proxy a OpenAI con tools)

**Files:**
- Create: `chat.php`

**Interfaces:**
- Consumes: `infouno_save_lead()` de `db_lead.php`; claves de `config.php`; `ai-kb/kb_infouno.md`.
- Produces (HTTP):
  - `GET /chat.php` → `{"ok":true,"enabled":bool}`
  - `POST /chat.php` body `{session_id, page, messages:[{role,content}]}` → `{"ok":true,"reply":string|null,"readyToClose":bool,"leadFields":object}`

- [ ] **Step 1: Crear `chat.php`**

Create `chat.php`:

```php
<?php
/* =====================================================================
   Infouno — Agente conversacional "Uno" (proxy a OpenAI)
   GET  = ping de disponibilidad  → {ok, enabled}
   POST = turno de conversación   → {ok, reply, readyToClose, leadFields}
   La clave de OpenAI vive solo acá (config.php). Persiste vía db_lead.php.
   ===================================================================== */

header('Content-Type: application/json; charset=utf-8');

$cfg = require __DIR__ . '/config.php';
$enabled = !empty($cfg['chat_enabled']) && !empty($cfg['openai_key']);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  echo json_encode(['ok' => true, 'enabled' => $enabled]);
  exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405); echo json_encode(['ok' => false, 'error' => 'method']); exit;
}
if (!$enabled) { echo json_encode(['ok' => true, 'enabled' => false, 'reply' => null]); exit; }

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'json']); exit; }

$session = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($in['session_id'] ?? ''));
$page    = is_string($in['page'] ?? null) ? $in['page'] : '';
$msgsIn  = is_array($in['messages'] ?? null) ? $in['messages'] : [];

// Sanitizar y limitar el historial
$history = [];
$turns = 0;
foreach ($msgsIn as $m) {
  $role = (($m['role'] ?? '') === 'assistant') ? 'assistant' : 'user';
  $content = trim((string) ($m['content'] ?? ''));
  if ($content === '') continue;
  if (mb_strlen($content) > 1500) $content = mb_substr($content, 0, 1500);
  $history[] = ['role' => $role, 'content' => $content];
  if ($role === 'user') $turns++;
}

// Tope de turnos (control de costo)
if ($turns > 16) {
  echo json_encode(['ok' => true, 'reply' => 'Para no hacerte perder tiempo, lo mejor es seguirlo con una persona del equipo. Coordinemos la consultoría 👇', 'readyToClose' => true, 'leadFields' => new stdClass()]);
  exit;
}

// System prompt + base de conocimiento
$kb = @file_get_contents(__DIR__ . '/ai-kb/kb_infouno.md');
if ($kb === false) $kb = '';
$system = "Sos \"Uno\", el asistente comercial de Infouno (agencia de webs e IA para PyMEs argentinas). "
  . "Hablás en español rioplatense (voseo), cercano, claro y breve (2-4 oraciones por mensaje). "
  . "OBJETIVO: entender el negocio del usuario y llevarlo a agendar una consultoría gratuita de 15 min. "
  . "Mientras conversás, captá de forma natural (sin interrogatorio): rubro, nombre, si tiene web, tamaño de equipo, WhatsApp y email (opcional). "
  . "Cada vez que te enterés de uno de esos datos, llamá a la función guardar_lead con lo que sepas. "
  . "Pedí el nombre y el rubro ANTES de dar ejemplos o soluciones personalizadas. "
  . "Antes de pedir WhatsApp o email, aclarale que respetás su privacidad (política en privacidad.html, Ley 25.326) y que los datos son solo para contactarlo. "
  . "Cuando ya tengas al menos rubro, nombre y un contacto (WhatsApp o email), llamá a listo_para_agendar. "
  . "REGLAS ESTRICTAS: (1) Está PROHIBIDO dar precios o estimaciones de montos; si te preguntan, explicá que dependen del nivel de automatización y se definen en la consultoría de 15 min. "
  . "(2) Respondé SOLO sobre Infouno y sus servicios; si te piden tareas ajenas (programar, temas académicos, política, usarte como ChatGPT), respondé EXACTAMENTE: "
  . "'Disculpame, como asistente de Infouno solo puedo asesorarte en automatizaciones para potenciar tu negocio. Contame, ¿tu empresa ya cuenta con sitio web?' y reconducí. "
  . "(3) No inventes servicios, plazos ni datos que no estén en el CONOCIMIENTO.\n\n"
  . "CONOCIMIENTO:\n" . $kb;

$tools = [
  [
    'type' => 'function',
    'function' => [
      'name' => 'guardar_lead',
      'description' => 'Guarda o actualiza los datos del lead a medida que se conocen durante la charla.',
      'parameters' => [
        'type' => 'object',
        'properties' => [
          'name'     => ['type' => 'string', 'description' => 'Nombre de la persona'],
          'rubro'    => ['type' => 'string', 'description' => 'Rubro o actividad del negocio'],
          'web'      => ['type' => 'string', 'description' => 'Estado web: "ya tengo web", "arranco de cero" o "quiero rehacerla"'],
          'equipo'   => ['type' => 'string', 'description' => 'Tamaño: "solo", "equipo chico (2 a 5)" o "equipo grande (+5)"'],
          'whatsapp' => ['type' => 'string', 'description' => 'Número de WhatsApp'],
          'email'    => ['type' => 'string', 'description' => 'Email de contacto'],
        ],
      ],
    ],
  ],
  [
    'type' => 'function',
    'function' => [
      'name' => 'listo_para_agendar',
      'description' => 'Indica que el lead ya está listo para mostrarle los botones de agenda/WhatsApp.',
      'parameters' => ['type' => 'object', 'properties' => new stdClass()],
    ],
  ],
];

$messages = array_merge([['role' => 'system', 'content' => $system]], $history);
$readyToClose = false;
$savedFields = [];

for ($i = 0; $i < 3; $i++) {
  $resp = infouno_openai($cfg, $messages, $tools);
  if (!$resp || empty($resp['choices'][0]['message'])) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'openai']);
    exit;
  }
  $msg = $resp['choices'][0]['message'];
  $messages[] = $msg;
  $toolCalls = $msg['tool_calls'] ?? [];

  if (empty($toolCalls)) {
    echo json_encode([
      'ok' => true,
      'reply' => (string) ($msg['content'] ?? ''),
      'readyToClose' => $readyToClose,
      'leadFields' => $savedFields ? $savedFields : new stdClass(),
    ]);
    exit;
  }

  foreach ($toolCalls as $tc) {
    $fn = $tc['function']['name'] ?? '';
    $args = json_decode($tc['function']['arguments'] ?? '{}', true);
    if (!is_array($args)) $args = [];
    $result = ['ok' => true];

    if ($fn === 'guardar_lead') {
      require_once __DIR__ . '/db_lead.php';
      $payload = array_merge($args, ['session_id' => $session, 'source' => 'bot-ia', 'page' => $page]);
      $r = infouno_save_lead($cfg, $payload);
      $savedFields = array_merge($savedFields, $args);
      $result = ['ok' => (bool) ($r['ok'] ?? false)];
    } elseif ($fn === 'listo_para_agendar') {
      $readyToClose = true;
    }

    $messages[] = ['role' => 'tool', 'tool_call_id' => $tc['id'] ?? '', 'content' => json_encode($result)];
  }
}

// Si agotó el bucle de tools sin texto final
echo json_encode(['ok' => true, 'reply' => '¿Coordinamos una llamada de 15 min para verlo en detalle?', 'readyToClose' => $readyToClose, 'leadFields' => $savedFields ? $savedFields : new stdClass()]);

/** Llama a OpenAI Chat Completions. Devuelve el array decodificado o null. */
function infouno_openai($cfg, $messages, $tools) {
  $payload = json_encode([
    'model'       => $cfg['openai_model'] ?? 'gpt-4o-mini',
    'temperature' => 0.3,
    'max_tokens'  => 450,
    'messages'    => $messages,
    'tools'       => $tools,
  ]);
  $ch = curl_init('https://api.openai.com/v1/chat/completions');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $cfg['openai_key']],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 30,
  ]);
  $raw  = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($raw === false || $code !== 200) return null;
  return json_decode($raw, true);
}
```

- [ ] **Step 2: Verificar sintaxis (en el server)**

Run: `php -l chat.php`
Expected: `No syntax errors detected in chat.php`

- [ ] **Step 3: Verificar el ping GET (en el server, tras subir, SIN key cargada)**

Run: `curl -sS https://infouno.com.ar/chat.php`
Expected: `{"ok":true,"enabled":false}` (porque `openai_key` está vacío).

- [ ] **Step 4: Verificar conversación (tras cargar la key en config.php)**

Run:
```bash
curl -sS -X POST https://infouno.com.ar/chat.php \
  -H 'Content-Type: application/json' \
  -d '{"session_id":"TEST-ia-1","page":"/index.html","messages":[{"role":"assistant","content":"¡Hola! Soy Uno. ¿A qué se dedica tu negocio?"},{"role":"user","content":"Tengo una panadería"}]}'
```
Expected: JSON `{"ok":true,"reply":"...","readyToClose":false,...}` con una respuesta natural en español. Verificar en phpMyAdmin que se creó/actualizó la fila `TEST-ia-1` con `lead_rubro` ~ "panadería".

- [ ] **Step 5: Verificar guardrail de precios y de scope**

Run (precios):
```bash
curl -sS -X POST https://infouno.com.ar/chat.php -H 'Content-Type: application/json' \
  -d '{"session_id":"TEST-ia-2","messages":[{"role":"user","content":"¿cuánto sale una web?"}]}'
```
Expected: la respuesta NO da un monto; deriva a la consultoría de 15 min.

Run (scope):
```bash
curl -sS -X POST https://infouno.com.ar/chat.php -H 'Content-Type: application/json' \
  -d '{"session_id":"TEST-ia-3","messages":[{"role":"user","content":"escribime un poema sobre el mar"}]}'
```
Expected: responde con el fallback de Infouno y reconduce, sin escribir el poema.

---

### Task 5: Modo IA + fallback en `assets/site.js`

**Files:**
- Modify: `assets/site.js` (función `initBot`, alrededor de líneas 178-300)

**Interfaces:**
- Consumes: endpoint `chat.php` (GET ping, POST turno); helpers existentes `botSay`, `meSay`, `scroll`, `escapeHtml`, `waLink`, `agendaConfigured`, `openAgenda`, `leadSession`, `clearFoot`, `foot`, `body`.
- Produces: arranque dual del bot (IA o guion) transparente para el resto del código.

- [ ] **Step 1: Agregar helpers de render asíncrono y el cierre compartido**

En `assets/site.js`, dentro de `initBot()`, justo después de la función `clearFoot()` (línea ~230), agregar:

```javascript
  // Burbuja "pensando" que se rellena cuando llega la respuesta async
  function thinking() {
    const t = document.createElement('div'); t.className = 'bmsg';
    t.innerHTML = `<div class="bav">${botIco}</div><div class="bb btyping-wrap"><span class="btyping"><i></i><i></i><i></i></span></div>`;
    body.appendChild(t); scroll(); return t;
  }
  // Rellena como TEXTO PLANO (G3: nunca innerHTML con texto del modelo)
  function fillText(t, text) {
    const bb = t.querySelector('.bb'); bb.classList.remove('btyping-wrap');
    bb.textContent = text; scroll();
  }
  // Botones de cierre (agenda + WhatsApp). Compartido por guion e IA.
  function renderCierre() {
    clearFoot();
    const wrap = document.createElement('div'); wrap.style.display = 'flex'; wrap.style.flexDirection = 'column'; wrap.style.gap = '8px';
    const summary = `Hola Infouno 👋 Soy ${lead.nombre || ''}.\nRubro: ${lead.rubro || ''}\nWeb: ${lead.web || ''}\nEquipo: ${lead.equipo || ''}\nMi WhatsApp: ${lead.whatsapp || ''}${lead.email ? '\nEmail: ' + lead.email : ''}\nQuiero agendar la consultoría gratuita de 15 min.`;
    if (agendaConfigured()) {
      const cal = document.createElement('button'); cal.type = 'button'; cal.className = 'btn btn--block';
      cal.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg> Agendar mi reunión';
      cal.addEventListener('click', () => openAgenda({ name: lead.nombre, email: lead.email }));
      wrap.appendChild(cal);
    }
    const a = document.createElement('a'); a.href = waLink(summary); a.target = '_blank'; a.rel = 'noopener';
    a.className = agendaConfigured() ? 'btn btn--ghost btn--block' : 'btn btn--wa btn--block';
    a.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.86 9.86 0 0 0 12.04 2Z"/></svg> '
      + (agendaConfigured() ? 'O coordinar por WhatsApp' : 'Confirmar por WhatsApp');
    wrap.appendChild(a);
    foot.appendChild(wrap);
  }
```

- [ ] **Step 2: Agregar el modo IA (conversación libre contra chat.php)**

En `assets/site.js`, dentro de `initBot()`, después de `renderCierre()` (del paso anterior), agregar:

```javascript
  // ---- Modo IA: conversación libre ----
  const aiHistory = [];
  let aiTurns = 0, aiBusy = false;
  const AI_GREETING = '¡Hola! 👋 Soy Uno, el asistente de Infouno. Contame, ¿a qué se dedica tu negocio? Así te muestro cómo podemos ayudarte.';

  function aiStart() {
    step = 1;
    const t = thinking();
    setTimeout(() => {
      fillText(t, AI_GREETING);
      aiHistory.push({ role: 'assistant', content: AI_GREETING });
      aiInput();
    }, 500);
  }

  function aiInput() {
    foot.innerHTML = '';
    const row = document.createElement('div'); row.className = 'bot__inrow';
    row.innerHTML = `<input type="text" placeholder="Escribí tu mensaje…"><button aria-label="Enviar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4z"/></svg></button>`;
    const inp = row.querySelector('input'), btn = row.querySelector('button');
    const send = () => {
      const v = inp.value.trim();
      if (!v || aiBusy) return;
      meSay(v); aiSend(v);
    };
    btn.onclick = send; inp.addEventListener('keydown', e => { if (e.key === 'Enter') send(); });
    foot.appendChild(row); setTimeout(() => inp.focus(), 100);
  }

  function aiSend(text) {
    aiBusy = true; aiTurns++;
    aiHistory.push({ role: 'user', content: text });
    const t = thinking();
    fetch('/chat.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ session_id: leadSession(), page: location.pathname, messages: aiHistory })
    })
      .then(r => r.json())
      .then(d => {
        aiBusy = false;
        if (!d || d.ok === false || (!d.reply && !d.readyToClose)) { fillText(t, 'Uy, tuve un problemita. Sigamos por WhatsApp 👇'); renderCierre(); return; }
        if (d.leadFields) Object.assign(lead, mapLeadFields(d.leadFields));
        if (d.reply) { fillText(t, d.reply); aiHistory.push({ role: 'assistant', content: d.reply }); }
        else { t.remove(); }
        if (d.readyToClose) renderCierre(); else aiInput();
      })
      .catch(() => { aiBusy = false; fillText(t, 'Uy, se me cortó la conexión. Coordinemos por WhatsApp 👇'); renderCierre(); });
  }

  // Mapea los campos que devuelve chat.php (en/es) al objeto lead local
  function mapLeadFields(f) {
    return { nombre: f.name, rubro: f.rubro, web: f.web, equipo: f.equipo, whatsapp: f.whatsapp, email: f.email };
  }
```

- [ ] **Step 3: Decidir el modo al abrir el bot (IA si está disponible, si no el guion)**

En `assets/site.js`, reemplazar la función `open()` actual:

```javascript
  function open() {
    bot.classList.add('open'); fab.classList.add('hide'); opened = true;
    clearTimeout(autoTimer);
    if (step === 0) startBot();
  }
```

Y agregar la función `startBot()` justo antes de `function flow()`:

```javascript
  // Decide el modo: IA si chat.php está habilitado, si no el guion scripteado
  function startBot() {
    step = 1;
    const t = thinking();
    const fallback = () => { t.remove(); step = 0; flow(); };
    const ctrl = ('AbortController' in window) ? new AbortController() : null;
    const timer = setTimeout(() => { if (ctrl) ctrl.abort(); }, 3500);
    fetch('/chat.php', ctrl ? { signal: ctrl.signal } : undefined)
      .then(r => r.json())
      .then(d => { clearTimeout(timer); t.remove(); if (d && d.enabled) { step = 0; aiStart(); } else { step = 0; flow(); } })
      .catch(() => { clearTimeout(timer); fallback(); });
  }
```

Nota: `flow()` y `aiStart()` ya setean `step=1`; el reset a `step=0` antes de llamarlas evita el doble-arranque.

- [ ] **Step 4: Validar sintaxis JS (local)**

Run: `node --check assets/site.js`
Expected: sin salida (OK).

- [ ] **Step 5: Probar el fallback en el navegador (local, sin backend)**

Abrir `index.html` en el navegador (sin servidor PHP). Al abrir el bot, `GET /chat.php` falla → debe arrancar el **guion scripteado** normal (rubro → nombre → …). 
Expected: el bot funciona como antes; sin errores en consola que rompan el flujo.

- [ ] **Step 6: Probar el modo IA (en el server, con key)**

Con `chat.php` desplegado y la key cargada, abrir el sitio. El bot debe saludar y mantener una conversación libre; al dar rubro/nombre/contacto, aparecen los botones de Agenda/WhatsApp.
Expected: conversación natural + cierre con botones; fila del lead en `wp_infouno_leads`.

---

### Task 6: Actualizar documentación

**Files:**
- Modify: `ai/analysis.md`, `ai/architecture.md`, `ai/guardrails.md`

**Interfaces:** ninguno (solo docs).

- [ ] **Step 1: Marcar la capa cognitiva como implementada en `ai/analysis.md`**

En `ai/analysis.md`, en la matriz de brechas, reemplazar la fila del Motor IA:

```markdown
| **Motor IA** | OpenAI GPT-4o, T=0.3, RAG | Agente conversacional con `gpt-4o-mini` (T=0.3) vía `chat.php`, con fallback al guion | ✅ Implementado (KB en archivo, sin RAG por ahora) |
```

- [ ] **Step 2: Nota de estado en `ai/architecture.md`**

En `ai/architecture.md`, en el aviso de estado actual, agregar al final:

```markdown
> **Actualización (capa cognitiva):** el bot "Uno" ya tiene modo IA real vía `chat.php` (OpenAI `gpt-4o-mini`, T=0.3) con function calling para captar leads, y degrada al guion scripteado si la IA no está disponible. La clave vive en `config.php` (backend).
```

- [ ] **Step 3: Marcar G1 como activo en `ai/guardrails.md`**

En `ai/guardrails.md`, en el bloque de estado de implementación, cambiar la mención a G1:

```markdown
G1 ✅ activo: el system prompt de `chat.php` prohíbe temas ajenos y responde con el fallback textual reconduciendo a Infouno (aplica en modo IA; en modo guion no hay generación libre).
```

- [ ] **Step 4: Verificar referencias**

Run: `grep -rn "chat.php\|gpt-4o-mini" ai/`
Expected: las referencias nuevas presentes en los 3 archivos.

---

## Notas de despliegue (post-implementación)

1. Subir a DonWeb: `config.php` (con la key), `db_lead.php`, `lead.php`, `chat.php`, `ai-kb/kb_infouno.md`, `assets/site.js`.
2. Cargar `openai_key` en `config.php` (cuenta OpenAI con método de pago).
3. Verificar con los `curl` de las Tasks 4 y 2.
4. Si algo falla con OpenAI, poner `chat_enabled => false` para volver al guion al instante.
