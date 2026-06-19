# Alerta de lead VIP por WhatsApp — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cuando entra un lead VIP, disparar una alerta instantánea de WhatsApp al equipo vía Make, sin tocar el flujo de leads existente.

**Architecture:** `db_lead.php::infouno_save_lead()` hace un POST HTTPS best-effort (no bloqueante) a un webhook de Make cuando un lead es VIP + accionable, una sola vez (gate `lead_vip_notified`). Make recibe el JSON, valida un token, y envía un mensaje de plantilla por WhatsApp Cloud API con un link `wa.me` al lead.

**Tech Stack:** PHP 8 (mysqli, curl) sobre DonWeb/cPanel · MySQL · Make (no-code) · WhatsApp Business Cloud API (Meta).

## Global Constraints

- **No bloqueante:** el POST corre dentro del turno del bot (`chat.php`); usar `CURLOPT_TIMEOUT` de 2-3s. Nunca demorar ni abortar la respuesta.
- **Nunca romper el guardado de leads:** si Make falla o el config está vacío, el lead igual se guarda y el email igual sale. Errores silenciados (`@`) + log en `error_log`.
- **Desactivable por config:** sin `make_webhook_url` el comportamiento es idéntico al actual.
- **Solo VIP:** disparar solo si `lead_vip == 1` (tiene web + equipo grande) **y** accionable (`$phoneValid || $emailValid || $source === 'form'`).
- **Una sola vez por lead:** gate con la columna `lead_vip_notified`, marcada al intentar el envío (best-effort).
- **Seguridad:** secretos solo en `config.php` (no versionado); token compartido en el payload; HTTPS.
- **Sin suite de tests:** la verificación es manual (curl, request bin, phpMyAdmin), corriendo contra el server real (no hay PHP local). Idioma: español.

**Referencia:** spec en `docs/superpowers/specs/2026-06-19-alerta-vip-whatsapp-design.md`.

---

## File Structure

- `db/schema.sql` — agrega la columna `lead_vip_notified` al CREATE TABLE (nuevos deploys).
- `config.sample.php` — documenta `make_webhook_url` y `make_token`.
- `db_lead.php` — lee `lead_vip_notified` en el SELECT y agrega el bloque de disparo del webhook tras el bloque de email.
- `config.php` (solo server, no versionado) — el usuario completa las claves reales.
- Make + Meta — configuración externa (no-code), documentada en tareas operativas.

---

## Task 1: Migración de DB — columna `lead_vip_notified`

**Files:**
- Modify: `db/schema.sql:24-25` (agregar columna tras `lead_notified`)

**Interfaces:**
- Produces: columna `wp_infouno_leads.lead_vip_notified TINYINT(1) DEFAULT 0`, leída/escrita por `db_lead.php` en la Task 3.

- [ ] **Step 1: Agregar la columna al schema versionado**

En `db/schema.sql`, dentro del `CREATE TABLE`, agregar la línea de `lead_vip_notified` justo después de `lead_notified` (línea 25):

```sql
  lead_notified        TINYINT(1)   DEFAULT 0,                     -- 1 = ya se avisó por email
  lead_vip_notified    TINYINT(1)   DEFAULT 0,                     -- 1 = ya se alertó al equipo por WhatsApp (Make)
```

- [ ] **Step 2: Correr el ALTER en producción**

En cPanel → phpMyAdmin → base `c1900716_infouno` → pestaña SQL, ejecutar:

```sql
ALTER TABLE wp_infouno_leads
  ADD COLUMN lead_vip_notified TINYINT(1) DEFAULT 0 AFTER lead_notified;
```

Expected: "Se modificó la tabla" sin error. Verificar en la estructura que la columna aparece.

- [ ] **Step 3: Commit**

```bash
git add db/schema.sql
git commit -m "feat(db): columna lead_vip_notified para gate de alerta VIP por WhatsApp"
```

---

## Task 2: Claves de config (`make_webhook_url`, `make_token`)

**Files:**
- Modify: `config.sample.php` (agregar las 2 claves tras el bloque del LLM)

**Interfaces:**
- Produces: `$cfg['make_webhook_url']` (string, vacío = desactivado) y `$cfg['make_token']` (string), consumidos por `db_lead.php` en la Task 3.

- [ ] **Step 1: Documentar las claves en la plantilla**

En `config.sample.php`, antes del `];` final, agregar:

```php
  // --- Alerta de lead VIP por WhatsApp (vía Make) ---
  // URL del webhook del escenario de Make. Vacío = alerta VIP desactivada (no cambia nada).
  'make_webhook_url' => '',
  // Secreto compartido que Make valida en el payload (poné cualquier string largo y random).
  'make_token'       => '',
```

- [ ] **Step 2: Commit**

```bash
git add config.sample.php
git commit -m "feat(config): claves make_webhook_url y make_token para alerta VIP"
```

---

## Task 3: Disparo del webhook en `db_lead.php`

**Files:**
- Modify: `db_lead.php:78-83` (SELECT lee también `lead_vip_notified`)
- Modify: `db_lead.php:145` (bloque de webhook tras el de email, antes de `$db->close()`)

**Interfaces:**
- Consumes: `$cfg['make_webhook_url']`, `$cfg['make_token']` (Task 2); columna `lead_vip_notified` (Task 1).
- Consumes (variables locales ya existentes en la función): `$ok`, `$vip`, `$phoneValid`, `$emailValid`, `$source`, `$phone`, `$name`, `$rubro`, `$company`, `$email`, `$infra`, `$size`, `$score`, `$page`, `$utm_s`, `$utm_m`, `$utm_c`, `$session`, `$db`.

- [ ] **Step 1: Extender el SELECT para leer `lead_vip_notified`**

Reemplazar el bloque actual (líneas 78-83):

```php
  // ¿Ya existe? (para no notificar dos veces)
  $notified = 0;
  $q = $db->prepare('SELECT lead_notified FROM wp_infouno_leads WHERE session_id = ? LIMIT 1');
  $q->bind_param('s', $session);
  $q->execute();
  if ($r = $q->get_result()->fetch_assoc()) $notified = (int) $r['lead_notified'];
  $q->close();
```

por:

```php
  // ¿Ya existe? (para no notificar dos veces: email y WhatsApp por separado)
  $notified = 0; $vipNotified = 0;
  $q = $db->prepare('SELECT lead_notified, lead_vip_notified FROM wp_infouno_leads WHERE session_id = ? LIMIT 1');
  $q->bind_param('s', $session);
  $q->execute();
  if ($r = $q->get_result()->fetch_assoc()) { $notified = (int) $r['lead_notified']; $vipNotified = (int) $r['lead_vip_notified']; }
  $q->close();
```

- [ ] **Step 2: Agregar el bloque de webhook tras el bloque de email**

Localizar el cierre del bloque de email (línea 145, el `}` que cierra `if ($ok && $actionable && !$notified)`) y, **inmediatamente después** de ese `}` y **antes** de `$db->close();` (línea 147), insertar:

```php

  // Alerta de lead VIP por WhatsApp (vía Make). Best-effort, NO bloqueante: nunca
  // demora ni rompe el guardado. Solo VIP + accionable, una sola vez por lead.
  if ($ok && $vip && $actionable && !$vipNotified && !empty($cfg['make_webhook_url'])) {
    $waLink = $phoneValid ? ('https://wa.me/' . $phone) : '';
    $payload = json_encode([
      'token'      => $cfg['make_token'] ?? '',
      'session_id' => $session,
      'name'       => $name,
      'rubro'      => $rubro,
      'company'    => $company,
      'phone'      => $phone,
      'email'      => $email,
      'web'        => $infra,
      'equipo'     => $size,
      'scoring'    => $score,
      'vip'        => $vip,
      'wa_link'    => $waLink,
      'page'       => $page,
      'utm'        => ['source' => $utm_s, 'medium' => $utm_m, 'campaign' => $utm_c],
    ]);
    $ch = curl_init($cfg['make_webhook_url']);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST           => true,
      CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
      CURLOPT_POSTFIELDS     => $payload,
      CURLOPT_TIMEOUT        => 3,
    ]);
    $resp = @curl_exec($ch);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($resp === false) @error_log('infouno: webhook Make fallo — ' . $cerr);

    // Marca best-effort (al intentar): evita re-disparar en cada paso del bot.
    $uv = $db->prepare('UPDATE wp_infouno_leads SET lead_vip_notified = 1 WHERE session_id = ?');
    $uv->bind_param('s', $session);
    $uv->execute();
    $uv->close();
  }
```

- [ ] **Step 3: Verificar sintaxis (estructura, sin PHP local)**

Confirmar que el bloque quedó entre el cierre del email y `$db->close();`:

Run: `grep -n "make_webhook_url\|\$db->close()\|lead_vip_notified" db_lead.php`
Expected: el `if (... make_webhook_url ...)` aparece **antes** de `$db->close();`, y `lead_vip_notified` aparece en el SELECT y en el UPDATE.

- [ ] **Step 4: Commit**

```bash
git add db_lead.php
git commit -m "feat(leads): dispara webhook a Make para alertar lead VIP por WhatsApp"
```

---

## Task 4: Verificar el lado PHP con un request bin (sin Make todavía)

Esto prueba que el POST sale con el payload correcto, el gate anti-duplicado, el filtro VIP y la resiliencia — **sin** depender aún de Make/WhatsApp. Se usa un *request bin* gratuito que captura el POST.

**Files:** ninguno (verificación operativa). Requiere los cambios de Tasks 1-3 **subidos a DonWeb**.

- [ ] **Step 1: Crear un request bin**

Abrir [webhook.site](https://webhook.site) → copiar la "Your unique URL" (ej. `https://webhook.site/abc-123`).

- [ ] **Step 2: Apuntar el config del server al bin**

En cPanel → File Manager → `config.php` (server), poner:

```php
  'make_webhook_url' => 'https://webhook.site/abc-123',
  'make_token'       => 'un-secreto-largo-y-random-1900',
```

Subir el `db_lead.php` y `db/schema.sql` nuevos (y confirmar que el ALTER de la Task 1 Step 2 ya corrió).

- [ ] **Step 3: Disparar un lead VIP completo**

```bash
curl -s -X POST https://infouno.com.ar/chat.php -H 'Content-Type: application/json' \
  -d '{"session_id":"VIP_BIN_TEST","page":"/verify","messages":[{"role":"user","content":"Hola, soy Test VIP, estudio contable, equipo grande de mas de 5 personas, ya tengo web, mi WhatsApp es 11 5555 9999"}]}'
```

Expected: en webhook.site aparece **un** POST con JSON que incluye `"vip":1`, `"token":"un-secreto-largo-y-random-1900"`, `"name":"Test VIP"`, `"wa_link":"https://wa.me/..."`.

- [ ] **Step 4: Verificar el gate anti-duplicado**

Repetir el mismo `curl` del Step 3 (mismo `session_id`).

Expected: webhook.site **NO** recibe un segundo POST (la primera vez se marcó `lead_vip_notified=1`).

- [ ] **Step 5: Verificar que un lead NO-VIP no dispara**

```bash
curl -s -X POST https://infouno.com.ar/chat.php -H 'Content-Type: application/json' \
  -d '{"session_id":"NOVIP_BIN_TEST","page":"/verify","messages":[{"role":"user","content":"Hola, soy Test Chico, soy monotributista y trabajo solo, arranco de cero, mi WhatsApp es 11 5555 0000"}]}'
```

Expected: webhook.site **NO** recibe POST (no es VIP: no tiene web + no es equipo grande).

- [ ] **Step 6: Verificar resiliencia (Make caído)**

Cambiar en `config.php` del server `make_webhook_url` a una URL inválida (ej. `https://infouno.com.ar/no-existe-xyz`). Disparar un VIP nuevo (`session_id":"VIP_DOWN_TEST"`, mismo cuerpo del Step 3). 

Expected: `chat.php` responde **200 normal** (no se cuelga ni 500), el lead **se guarda** en `wp_infouno_leads`, y en `error_log` (cPanel → Errores) aparece la línea `infouno: webhook Make fallo`.

- [ ] **Step 7: Limpiar filas de prueba**

En phpMyAdmin, borrar las filas `VIP_BIN_TEST`, `NOVIP_BIN_TEST`, `VIP_DOWN_TEST`.

---

## Task 5: Escenario de Make (webhook + validación)

**Files:** ninguno (configuración externa en Make).

- [ ] **Step 1: Crear el escenario y el webhook**

En [make.com](https://make.com) → Create a new scenario → primer módulo **Webhooks → Custom webhook** → Add → copiar la URL generada.

- [ ] **Step 2: Alimentar la estructura del webhook**

Con el webhook "escuchando", disparar un VIP de prueba apuntando `make_webhook_url` (en `config.php` del server) a la URL de Make y corriendo el `curl` del Task 4 Step 3 (usar `session_id":"MAKE_STRUCT_TEST"`). Make captura la estructura del JSON (token, name, rubro, scoring, vip, wa_link, …).

- [ ] **Step 3: Agregar el filtro de seguridad**

Entre el webhook y el siguiente módulo, agregar un **Filter**: continuar solo si `token` = `un-secreto-largo-y-random-1900` **AND** `vip` = `1`.

- [ ] **Step 4: Limpiar la fila de prueba**

En phpMyAdmin, borrar `MAKE_STRUCT_TEST`.

---

## Task 6: Plantilla de WhatsApp + módulo Cloud API en Make

**Files:** ninguno (configuración externa en Meta + Make).

**Prerrequisitos:** Meta Business + app de WhatsApp Business Cloud API + **número dedicado** (no el `1159397079` si está en la app de WhatsApp).

- [ ] **Step 1: Crear la plantilla en Meta**

En Meta Business → WhatsApp Manager → Plantillas de mensaje → Crear, categoría "Utility". Cuerpo con 4 variables:

```
🔥 LEAD VIP — Infouno

{{1}} · {{2}}
Score {{3}} · +5 personas · Tiene web
📱 Responder ya: {{4}}
```

Enviar a aprobación (1-2 días).

- [ ] **Step 2: Conectar WhatsApp Cloud API en Make**

En el escenario, después del Filter, agregar módulo **WhatsApp Business Cloud → Send a Message** (o "Send a Template Message"). Crear la conexión con el token de la app de Meta y el Phone Number ID.

- [ ] **Step 3: Mapear la plantilla**

Elegir la plantilla aprobada y mapear: `{{1}}`=`name`, `{{2}}`=`rubro`, `{{3}}`=`scoring`, `{{4}}`=`wa_link`. Destinatario: el/los número(s) del equipo (uno o varios módulos / un router).

- [ ] **Step 4: Activar el escenario**

Poner el escenario en **ON** (scheduling: immediately).

---

## Task 7: Verificación end-to-end + cierre

**Files:**
- Modify: `seo/log.md` o `ai/analysis.md` (registrar la fase implementada) — ver Step 5.

- [ ] **Step 1: Apuntar el config a Make (definitivo)**

En `config.php` del server, dejar `make_webhook_url` con la URL real del webhook de Make y `make_token` con el secreto. Confirmar `db_lead.php` y `db/schema.sql` subidos y el ALTER corrido.

- [ ] **Step 2: VIP real → llega WhatsApp**

```bash
curl -s -X POST https://infouno.com.ar/chat.php -H 'Content-Type: application/json' \
  -d '{"session_id":"E2E_VIP_FINAL","page":"/","messages":[{"role":"user","content":"Hola, soy Cierre E2E, estudio contable, equipo grande de mas de 5, ya tengo web, mi WhatsApp es 11 5555 9999"}]}'
```

Expected: llega **un** WhatsApp al equipo con nombre, rubro, score y el link `wa.me` que abre el chat con el lead.

- [ ] **Step 3: NO-VIP no dispara**

Disparar el cuerpo NO-VIP del Task 4 Step 5 (`session_id":"E2E_NOVIP_FINAL"`).
Expected: **no** llega WhatsApp; sí el email de siempre.

- [ ] **Step 4: Limpiar filas de prueba**

En phpMyAdmin, borrar `E2E_VIP_FINAL` y `E2E_NOVIP_FINAL`.

- [ ] **Step 5: Documentar la fase y commit**

En `seo/log.md` (o donde corresponda), agregar una línea en la bitácora indicando que la alerta VIP por WhatsApp (Fase E, paso 1) quedó implementada y verificada. Luego:

```bash
git add seo/log.md
git commit -m "docs: registra alerta VIP por WhatsApp implementada (Fase E)"
```

---

## Definition of Done

- Columna `lead_vip_notified` en prod y en `db/schema.sql`.
- `db_lead.php` dispara el webhook solo para VIP+accionable, una vez, sin bloquear ni romper el flujo ante fallos de Make.
- `config.sample.php` documenta las dos claves.
- Escenario de Make activo enviando el WhatsApp con la plantilla aprobada.
- Las verificaciones de Tasks 4 y 7 pasan (VIP dispara, no-VIP no, once-only, Make caído resiliente, feature off = sin cambios).
