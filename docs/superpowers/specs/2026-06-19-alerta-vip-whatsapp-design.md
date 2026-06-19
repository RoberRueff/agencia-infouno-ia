# Spec — Alerta de lead VIP por WhatsApp al equipo

**Fecha:** 2026-06-19 · **Estado:** diseño aprobado, pendiente de implementación.
**Fase:** primera de la capa de Orquestación (Fase E del plan de automatizaciones).

---

## 1. Objetivo

Cuando entra un **lead VIP**, el equipo comercial recibe una **alerta instantánea por WhatsApp**
con los datos del lead y un link para responderle en un tap. Objetivo: **velocidad de
respuesta** — hoy el aviso es solo por email y se pierde, enfriando leads de alto valor.

## 2. Alcance

**Incluye (MVP):**
- Disparo de un webhook desde el backend al guardarse un lead que es **VIP + accionable**, una
  sola vez por lead.
- Un escenario de **Make** que recibe el webhook y envía un WhatsApp al/los número(s) del equipo.
- Mensaje con nombre, rubro, scoring y **link `wa.me`** al WhatsApp del lead.

**No incluye (YAGNI / fases siguientes):**
- Alertas para leads no-VIP (siguen solo por el email actual).
- Sincronización a CRM, auto-respuesta al lead, bot de WhatsApp (fases futuras; el webhook
  queda como punto de extensión).
- Canales Slack/Telegram/email enriquecido (se decidió WhatsApp).

## 3. Definición de VIP (ya existente en el código)

`db_lead.php` ya calcula `lead_vip = 1` cuando el lead **tiene web** (`lead_infrastructure =
has_web`) **y** **equipo grande** (`lead_size = team_large`). El scoring acompaña
(web +40, equipo grande +50, WhatsApp válido +20). No se cambia esta lógica.

## 4. Arquitectura y flujo de datos

```
Lead (bot o formulario)
        │
        ▼
db_lead.php :: infouno_save_lead()
   1. Upsert en wp_infouno_leads                      (sin cambios)
   2. Email interno al equipo                          (sin cambios)
   3. [NUEVO] si  $ok && $vip && $actionable && !$vip_notified:
        a. POST JSON (fire-and-forget) → make_webhook_url
        b. UPDATE wp_infouno_leads SET lead_vip_notified = 1 WHERE session_id = ?
        │
        ▼
Make (escenario):
   Webhook → valida token + vip==1 → WhatsApp Cloud API → número(s) del equipo
```

**Enfoque elegido:** webhook **push** desde PHP (no polling), por ser instantáneo, alineado a
la arquitectura objetivo (webhooks HTTPS POST) y no exponer la base.

## 5. Disparador en PHP (`db_lead.php`)

- **Ubicación:** dentro de `infouno_save_lead()`, junto al bloque de notificación por email
  existente (mismo patrón, un solo lugar).
- **Condición de disparo:**
  `$ok` (guardado OK) `&&` `$vip == 1` `&&` `$actionable` (`$phoneValid || $emailValid ||
  $source === 'form'`) `&&` `lead_vip_notified == 0`.
- **Anti-duplicado:** nueva columna `lead_vip_notified` (separada de `lead_notified` del email),
  porque el lead se guarda paso a paso (R4) y la función corre muchas veces por lead. Tras
  enviar, se setea a 1.
- **No bloqueante (importante):** `infouno_save_lead()` corre **dentro del turno del bot**
  (`chat.php`), así que el POST a Make **no debe demorar la respuesta**. Se usa un timeout
  corto (`CURLOPT_TIMEOUT` 2-3s; Make responde casi instantáneo al encolar) y errores
  silenciados (`@` / sin excepción).
- **Anti-duplicado best-effort:** `lead_vip_notified` se marca a 1 **al intentar el envío**
  (no se condiciona a un 2xx), para mantener el flujo simple y no bloqueante. Trade-off
  asumido: si Make estuviera caído justo en ese instante, esa alerta VIP se pierde — pero el
  lead **igual queda guardado** y el **email de respaldo igual sale**, así que no es crítico.
  El fallo del POST se loguea en `error_log` para visibilidad.
- **Resiliencia (no romper el flujo de leads):**
  - Si `make_webhook_url` está vacío → no se hace nada (feature desactivada, reversible).
  - Si Make falla/está caído → el lead **igual queda guardado** y el email **igual sale**;
    nunca se aborta ni se devuelve error.

## 6. Migración de base de datos

```sql
ALTER TABLE wp_infouno_leads
  ADD COLUMN lead_vip_notified TINYINT(1) DEFAULT 0 AFTER lead_notified;
```
- Se corre una vez en phpMyAdmin (producción) y se agrega a `db/schema.sql` para nuevos deploys.

## 7. Payload PHP → Make (JSON)

```json
{
  "token": "<secreto_compartido>",
  "session_id": "L...",
  "name": "Juan Perez",
  "rubro": "estudio contable",
  "company": "",
  "phone": "5491155551234",
  "email": "",
  "web": "has_web",
  "equipo": "team_large",
  "scoring": 110,
  "vip": 1,
  "wa_link": "https://wa.me/5491155551234",
  "page": "/",
  "utm": { "source": "", "medium": "", "campaign": "" }
}
```
- `wa_link` se arma con el teléfono normalizado del lead (sin `+`, formato internacional).

## 8. Escenario de Make

1. **Webhook** (trigger) — recibe el JSON.
2. **Filtro** — continúa solo si `token` coincide con el secreto **y** `vip == 1` (defensa en
   profundidad; PHP ya filtró).
3. **WhatsApp Cloud API** — envía el mensaje de plantilla al/los número(s) del equipo,
   rellenando variables. Los números viven en Make (uno o varios; itera). La Cloud API **no
   envía a grupos**, solo a números individuales.
4. *(Punto de extensión: futuras ramas de CRM / auto-respuesta cuelgan de este mismo webhook.)*

## 9. Plantilla de mensaje (Meta)

Mensaje iniciado por el negocio → requiere **plantilla pre-aprobada** por Meta. Propuesta:

```
🔥 LEAD VIP — Infouno

{{1}}  ·  {{2}}              (Nombre · Rubro)
Score {{3}} · +5 personas · Tiene web
📱 Responder ya: {{4}}        (link wa.me al lead)
```

Variables enviadas: `{{1}}`=nombre, `{{2}}`=rubro, `{{3}}`=scoring, `{{4}}`=`wa_link`.

## 10. Configuración (`config.php` / `config.sample.php`)

```php
'make_webhook_url' => '',   // URL del webhook de Make. Vacío = alerta VIP desactivada.
'make_token'       => '',   // secreto compartido que Make valida en el payload.
```
- Sin `make_webhook_url` el comportamiento es idéntico al actual (reversible, como `ga4`).

## 11. Seguridad

- `make_webhook_url` y `make_token` viven solo en `config.php` (server, no versionado).
- **Token compartido** en el payload → Make rechaza disparos que no lo traigan, aunque alguien
  adivine la URL del webhook.
- Comunicación **HTTPS** únicamente.

## 12. Prerrequisitos (operativos, lado Infouno)

1. Cuenta de **Meta Business** + app de **WhatsApp Business Cloud API**.
2. ⚠️ **Número de teléfono dedicado** a la Cloud API. **No** puede ser el `1159397079` si está
   activo en la app de WhatsApp (la Cloud API exige un número no usado en la app). Principal
   bloqueante operativo.
3. **Plantilla de mensaje aprobada** por Meta (1-2 días de aprobación).
4. Cuenta de **Make** (free tier) con el escenario: Webhook → Filtro → WhatsApp Cloud API.

## 13. Plan de pruebas

1. **VIP completo** (web + equipo grande + WhatsApp) → llega 1 WhatsApp al equipo con el link
   `wa.me` funcionando.
2. **No-VIP** → **no** dispara WhatsApp; sí el email de siempre.
3. **VIP guardado varias veces** (pasos del bot) → **un solo** WhatsApp (gate
   `lead_vip_notified`).
4. **Make caído** (URL inválida a propósito) → el lead **igual se guarda** y el email **igual
   sale**; el fallo queda en `error_log`.
5. **Feature off** (`make_webhook_url` vacío) → comportamiento idéntico al actual.

## 14. Criterio de "hecho" (Definition of Done)

- Columna `lead_vip_notified` creada en prod y en `db/schema.sql`.
- `db_lead.php` dispara el webhook según la condición, una sola vez, sin afectar el flujo ante
  fallos de Make.
- `config.sample.php` documenta las dos claves nuevas.
- Escenario de Make activo enviando el WhatsApp con la plantilla aprobada.
- Las 5 pruebas de la sección 13 pasan.

## 15. Puntos de extensión (fases futuras, fuera de este spec)

El mismo webhook/escenario habilita después, sin tocar PHP:
- **CRM:** alta del lead en HubSpot/Pipedrive.
- **Auto-respuesta al lead:** mail/WhatsApp de confirmación + link de agenda (+ catálogo PDF).
- **Bot de WhatsApp** para rubros profesionales.
