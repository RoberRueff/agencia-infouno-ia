# Auditoría de Seguridad — Infouno

Auditoría de especialista sobre el sitio realmente desplegado (`infouno.com.ar`):
revisión de código (PHP + `site.js`), análisis de secretos/configuración y pentest remoto
de solo-lectura (exposición de archivos, headers, CORS). Severidad estilo CVSS.

> _Auditoría: 2026-06-19._ · Fuente de verdad técnica: `ai/`. Stack: HTML estático + PHP
> (DonWeb/cPanel, proxy openresty) + MySQL + LLM (Gemini/OpenAI) + GA4.

---

## Veredicto general

**Postura buena para el stack y tamaño.** Código con criterio defensivo (prepared statements,
escape de XSS, secretos fuera del repo). Hallazgos **acotados y remediables**, no estructurales.
El riesgo más serio es **económico** (abuso del endpoint de IA), no de robo de datos.

---

## Fortalezas (verificadas)

- **Secretos:** `config.php` en `.gitignore`; cero claves en repo o frontend; API key solo en backend.
- **SQL Injection:** 100% prepared statements (`mysqli` + `bind_param`), sin concatenación de input.
- **XSS:** output del modelo vía `textContent` (regla G3), valores de usuario vía `escapeHtml()`.
- **Sin funciones peligrosas** (`eval`/`system`/`exec`/`unserialize`); sin dependencias/build.
- **`.git` NO expuesto** (403); carpetas internas (`/db/`, `/ai/`, `config.sample.php`) no servidas (404).
- **`config.php` no filtra fuente** (PHP lo ejecuta; body vacío).
- **Privacidad/consentimiento** (Ley 25.326) implementado.
- Capa **openresty (proxy/WAF)** del hosting con protección base (devolvió 415 a sondeo automatizado).

---

## Hallazgos

### 🔴 ALTO

**H1 — Sin rate-limiting en `chat.php` → abuso económico (DoS financiero).** ✅ `RESUELTO Y VERIFICADO EN PROD (429 confirmado)`
Endpoint público sin throttling que proxea un LLM **pago**. El tope de 16 turnos es por
conversación, no limita la cantidad de requests → un atacante puede quemar el presupuesto de API.
- **Fix aplicado:** módulo `ratelimit.php` (file-based, sin deps) llamado en el POST de
  `chat.php` antes de tocar el LLM. Capas: por IP (15/min, 60/hora) + **tope global diario**
  (1500, configurable en `config.php`). Detecta IP real tras openresty. 429 + fallback del bot.
  Fail-open si el temp dir no es escribible (no rompe el bot).
- **Verificar en server:** (1) que `infouno_client_ip()` devuelva la **IP real** del cliente y no
  la del proxy (si no, todos comparten cupo → falsos positivos); (2) que al pasar el umbral
  responda 429. **Complementar (operativo):** tope de gasto diario en el panel del proveedor
  (Google AI Studio / OpenAI) e idealmente **Cloudflare** delante.

### 🟠 MEDIO

**M1 — Email header injection en `db_lead.php` (`mail()`).** ✅ `RESUELTO (df857dc)`
`s()` no eliminaba `\r\n` internos → el `$subject` (con `$name`/`$rubro`) era inyectable.
- **Fix aplicado:** `s()` ahora hace `preg_replace('/[\r\n]+/', ' ', $v)`. Cierra el vector de
  raíz (s() se usa para todos los campos).

**M2 — Sin anti-automatización en `lead.php` → spam de leads / contaminación de DB.** ✅ `RESUELTO (en código)`
Endpoint abierto, sin captcha/honeypot/rate-limit.
- **Fix aplicado:** **honeypot** (campo trampa `website` oculto en el form; `lead.php` descarta
  silenciosamente si viene completo) + **rate-limit** por IP en `lead.php` (bucket `lead`,
  30/min · 200/hora · 5000/día — generoso para no romper el flujo del bot scripteado).
- **Residual / opcional:** Cloudflare Turnstile / hCaptcha para bots avanzados que ignoran el
  honeypot. El honeypot tiene fallback seguro: aunque un falso positivo descarte el guardado,
  el form igual abre WhatsApp, así que el contacto no se pierde.

**M3 — Faltan headers de seguridad.** ✅ `RESUELTO (df857dc)` (verificar en server)
No había HSTS/CSP/X-Frame-Options/X-Content-Type-Options/Referrer-Policy.
- **Fix aplicado:** `.htaccess` con X-Frame-Options, X-Content-Type-Options, Referrer-Policy,
  Permissions-Policy, HSTS y **CSP en Report-Only** (GA4/fonts/agenda whitelisted).
- **Verificar tras deploy:** `curl -I https://infouno.com.ar/` debe mostrar los headers. Si el
  proxy openresty los ignora, setearlos a nivel proxy o vía `header()` en los `.php`. Cuando el
  CSP Report-Only no reporte violaciones legítimas, pasarlo a enforce (`Content-Security-Policy`).

### 🟡 BAJO / Hardening

- **L1 — `session_id` controlado por el cliente:** upsert por session_id provisto por el cliente
  → posible sobrescritura/contaminación. Bajo (IDs random); se mitiga con rate-limit (H1/M2). `PENDIENTE`
- **L2 — Server banner:** ya no se expone la versión (hoy responde `Server: Apache`, verificado 2026-10-07). `RESUELTO`
- **L3 — HTTPS forzado:** `http://` → 301 `https` y HSTS presentes (verificado en prod 2026-10-07). `RESUELTO`
- **L4 — Email spoofing/deliverability:** configurar **SPF + DKIM + DMARC** del dominio (clave
  también para la auto-respuesta al lead de la Fase E). `PENDIENTE`
- **L5 — Prompt injection del bot:** guardrails G1/G2 en el system prompt; blast radius acotado
  (function-calling solo `guardar_lead`/`listo_para_agendar`, sin tools peligrosas). Monitorear. `ACEPTADO`
- **L6 — Patrón de scripts de diagnóstico:** durante el deploy se usaron `diag_*.php` que exponían
  config/DB; ya borrados (404). **Proceso:** nunca dejar scripts de diag en prod. `MITIGADO`
- **L7 — `sin-publicar/` (backup .zip):** gitignored y 404 en server. No desplegar a `public_html`. `OK`

---

## Roadmap de remediación

| Prioridad | Acción | Estado |
|---|---|---|
| 1 | M1 — fix `s()` anti email-injection | ✅ hecho (`df857dc`) |
| 2 | M3 — headers de seguridad (`.htaccess`) | ✅ hecho (`df857dc`), **verificar en server** |
| 3 | H1 — rate-limit en `chat.php` | ✅ hecho + **verificado en prod (429 OK)**. Falta: tope de gasto del proveedor (operativo) |
| 4 | M2 — honeypot + rate-limit en `lead.php` | ✅ hecho (código); Turnstile opcional |
| 5 | L4 — SPF/DKIM/DMARC del dominio | ⏳ pendiente (DNS) |
| 6 | L2/L3 — server_tokens off + forzar HTTPS/HSTS | ✅ verificado en prod (2026-10-07) |
| 7 | Consent Mode v2 antes de los tags de Google + política de privacidad (cookies/terceros) | ✅ hecho en código; **verificar banner en navegador tras deploy** |
| 8 | Topes de entrada (`infouno_read_json`/`infouno_clamp`) en chat, lead y diagnósticos + `infouno_dos_clean` (IOI®) + `CONNECTTIMEOUT` | ✅ hecho y probado en local |
| 9 | `.htaccess`: deny de includes/internos/`.bak`, `www`→apex, caché | ✅ probado contra Apache local; **verificar en prod tras deploy** |
| 10 | Webhook WhatsApp (cartera): rechazar `app_secret`/`verify_token` vacío o placeholder | ⏳ pendiente |
| 11 | CSP en enforce (hoy Report-Only sin `report-uri`; requiere sacar scripts inline) | ⏳ pendiente |
| 12 | Cartera: cookie `path` propio / subdominio, límite por IP en login de clientes, `noindex` en admin | ⏳ pendiente |
| 13 | IOI®: calcular puntos en el server a partir de las respuestas | ⏳ pendiente (hoy acotado, no blindado) |
| 14 | Tope global diario agotable por rotación de IP (chat 1.500 / lead 5.000) → Turnstile o Cloudflare | ⏳ pendiente |

> **Nota de deploy:** `.htaccess` va a `public_html`. `db_lead.php` (fix M1) hay que **subirlo**
> a DonWeb para que tome efecto. Verificar headers con `curl -I` post-deploy.

---

## Actualización 2026-10-07 — Consent Mode y carga de fuentes

- **Consent Mode v2:** el `gtag('consent','default',{...denied})` va inline en el `<head>` de TODAS las páginas (incluidos Método UNO/DOS), antes de cualquier tag de Google. Google Ads (`INFOUNO.ads`) y GA4 se cargan solo tras aceptar el banner (`site.js`). Se quitó el `gtag.js` de AW incondicional. GTM (GTM-WWBJW7F) se eliminó del home: el contenedor publicado no tenía ningún tag (verificado en gtm.js).
- **Privacidad:** `privacidad.html` suma secciones de cookies/medición y terceros (proveedor de IA, Google, Cal.com).
- **Rendimiento:** se quitó el `@import` de Google Fonts de `styles.css` (cadena bloqueante) → `preconnect` + `<link>` en el `<head>`; `site.js` con `defer`.

## Actualización 2026-10-07 (2) — Topes de entrada y .htaccess

- **Topes de entrada (anti-abuso económico del LLM):** `ratelimit.php` suma `infouno_read_json()` (tope de bytes → 413) e `infouno_clamp()` (largo de strings, cantidad de ítems, profundidad). Los usan `chat.php` y `lead.php` (20 KB), `metodo-uno/diagnostico.php` y `metodo-dos/diagnostico2.php` (40 KB). `chat.php` además corta con >40 mensajes de historial, descarta ítems mal formados y limita `session_id` a 64 chars.
- **Método DOS®:** `infouno_dos_clean()` fuerza la forma y los rangos del input del motor IOI® (puntos 0–`max`, `max` ≤ 10, horas 0–168). El IOI® ya no puede salir de 0–100 ni lanzar excepciones antes de guardar el lead. Limitación conocida: el puntaje sigue calculándose con valores del cliente (ver nota `ponytail:` en el código).
- **Timeouts:** `CURLOPT_CONNECTTIMEOUT` en las llamadas al LLM (5 s) y al webhook de Make (2 s).
- **.htaccess:** deniega `db_lead.php`, `ratelimit.php`, `*.md|sql|bak*|log|ini|sh` y los directorios `ai-kb/ ai/ db/ docs/ seo/ sin-publicar/` (robots.txt no protege nada); redirige `www` → apex (301); caché de 1 día para JS/CSS y 7 días para PNG/SVG. Probado contra Apache local.
