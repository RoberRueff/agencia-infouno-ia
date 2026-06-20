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

**H1 — Sin rate-limiting en `chat.php` → abuso económico (DoS financiero).** `PENDIENTE`
Endpoint público sin throttling que proxea un LLM **pago**. El tope de 16 turnos es por
conversación, no limita la cantidad de requests → un atacante puede quemar el presupuesto de API.
- **Remediación:** rate-limit por IP (token-bucket en archivo/APCu/DB) + **tope de gasto diario**
  en el panel del proveedor + idealmente Cloudflare delante. Nonce/PoW liviano para el widget.

### 🟠 MEDIO

**M1 — Email header injection en `db_lead.php` (`mail()`).** ✅ `RESUELTO (df857dc)`
`s()` no eliminaba `\r\n` internos → el `$subject` (con `$name`/`$rubro`) era inyectable.
- **Fix aplicado:** `s()` ahora hace `preg_replace('/[\r\n]+/', ' ', $v)`. Cierra el vector de
  raíz (s() se usa para todos los campos).

**M2 — Sin anti-automatización en `lead.php` → spam de leads / contaminación de DB.** `PENDIENTE`
Endpoint abierto, sin captcha/honeypot/rate-limit.
- **Remediación:** honeypot oculto + check de tiempo mínimo de submit + rate-limit por IP.
  Opcional: Cloudflare Turnstile / hCaptcha.

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
- **L2 — Server banner:** se expone `openresty/1.27.1.1`. Suprimir `server_tokens`. `PENDIENTE`
- **L3 — HTTPS forzado:** confirmar `http://` → 301 `https` + HSTS (no verificable por el WAF). `PENDIENTE`
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
| 3 | H1 — rate-limit en `chat.php` + tope de gasto del proveedor | ⏳ pendiente |
| 4 | M2 — honeypot + Turnstile en el form | ⏳ pendiente |
| 5 | L4 — SPF/DKIM/DMARC del dominio | ⏳ pendiente (DNS) |
| 6 | L2/L3 — server_tokens off + forzar HTTPS/HSTS | ⏳ pendiente |

> **Nota de deploy:** `.htaccess` va a `public_html`. `db_lead.php` (fix M1) hay que **subirlo**
> a DonWeb para que tome efecto. Verificar headers con `curl -I` post-deploy.
