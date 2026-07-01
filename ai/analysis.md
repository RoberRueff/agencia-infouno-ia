# Análisis del Proyecto — Estado Actual vs Objetivo

> Documento de análisis técnico. Contrasta lo que la documentación de `ai/` describe como **arquitectura objetivo** con lo que el repositorio **realmente implementa hoy**. Fecha de análisis: 2026-06-17.

---

## 1. Resumen Ejecutivo

El repositorio es un **sitio HTML estático** (8 páginas + `assets/`) con una **capa backend en PHP** sobre DonWeb/cPanel — todavía **no** la plataforma WordPress + Elementor que describen los documentos de arquitectura. Sobre ese MVP ya se sumaron las tres piezas que faltaban: **persistencia** (MySQL `wp_infouno_leads` vía `lead.php`/`db_lead.php`, paso a paso), **capa cognitiva** (bot "Uno" con LLM compatible con OpenAI vía `chat.php` — OpenAI `gpt-4o-mini` o Gemini `gemini-2.5-flash` según `api_base`, con fallback al guion scripteado) y **agenda** (agendador embebido). La conversión ofrece agenda y/o WhatsApp. Siguen pendientes **WordPress/Elementor** y la **orquestación** (Make/Node.js).

Los documentos `architecture.md`, `taxonomy.md`, `rules.md`, `guardrails.md` y `checks.md` describen la **visión objetivo** (target). Este archivo marca la brecha restante y el camino.

---

## 2. Estructura Real del Repositorio

```text
agencia-infouno-ia/                 (raíz = solo lo que se publica/opera)
├── index.html              Home: hero, dolores, calculadora, servicios, bot "Uno", confianza, CTA
├── soluciones-ia.html      Página de soluciones de IA
├── calculadora-roi.html    Landing de la calculadora de ROI (imán de tráfico SEO)
├── servicios.html          Servicios
├── casos.html              Casos de éxito
├── nosotros.html           Nosotros
├── contacto.html           Formulario de contacto → WhatsApp + lead.php
├── privacidad.html         Política de privacidad (Ley 25.326)
├── chat.php                Proxy del bot "Uno" al LLM compatible con OpenAI (OpenAI/Gemini según api_base; function calling, fallback al guion)
├── lead.php                Receptor de leads (formulario + bot) → delega en db_lead.php
├── db_lead.php             Persistencia compartida: sanitización + validación + scoring/VIP + upsert + email
├── ratelimit.php           Rate-limit anti-abuso (file-based) para chat.php/lead.php + anti-spam (honeypot)
├── config.php              Credenciales MySQL + OpenAI + emails (NO se versiona)
├── config.sample.php       Plantilla versionada de config.php (sin credenciales)
├── robots.txt              Reglas de rastreo + referencia al sitemap (SEO)
├── sitemap.xml             Mapa de las 9 URLs públicas (8 páginas + landing Método UNO) para Search Console (SEO)
├── assets/
│   ├── site.js             TODA la lógica frontend: WhatsApp, calculadora, bot "Uno", agenda, leads, Tweaks
│   ├── styles.css          Estilos (temas dark/light, acentos, tipografías)
│   └── logo.png
├── ai-kb/
│   └── kb_infouno.md       Base de conocimiento del bot "Uno" (system prompt de chat.php)
├── db/
│   └── schema.sql          DDL de la tabla wp_infouno_leads
├── metodo-uno/             Método UNO® — Diagnóstico Nivel 1 (wizard + diagnostico.php; PHP, sin Node)
│   └── public/             metodo-uno-nivel1.html (form) + diagnostico.php (LLM + persiste lead)
├── metodo-dos/             Método DOS® — Diagnóstico Inteligente Nivel 2 / IOI® (PHP, sin Node)
│   ├── public/             metodo-dos-nivel2.html (wizard 4 fases) + diagnostico2.php (IOI + persiste + LLM)
│   ├── src/Scoring/        IOIEngine.php (motor puro) + ScoringConfig.php (pesos/valor-hora/rangos)
│   └── tests/              IOIEngineTest.php (28 aserciones, PHP plano)
├── ai/                     Documentación (análisis, arquitectura, taxonomía, reglas, guardrails, checks)
└── sin-publicar/           Material NO publicado (no enlazado por el sitio)
    ├── Infouno - Sitio Web.html  Export single-file viejo (sin bot/calc activos)
    ├── infouno-agencia.zip       Backup original
    ├── uploads/                  Documentos fuente (blueprint, docx, logo)
    └── screenshots/              Capturas (caja de dolores)
```

- **Páginas:** 8 públicas (Home · Soluciones · Servicios · Casos · Nosotros · Contacto · Privacidad · Calculadora ROI).
- **Dependencias externas:** ninguna en runtime salvo Google Fonts (carga condicional desde el panel Tweaks). Sin frameworks, sin build.

---

## 3. Lógica Real (qué hace `assets/site.js`)

| Módulo | Función | Estado |
|---|---|---|
| **WhatsApp helper** (`waLink`) | Construye links `wa.me` con mensaje codificado. Número en `window.INFOUNO`. | ✅ Implementado |
| **Formulario contacto** | Captura nombre/empresa/interés/mensaje y abre WhatsApp con el resumen. | ✅ Implementado (solo `contacto.html`) |
| **Calculadora ROI** | Sliders (horas WhatsApp/stock, costo/hora) → horas y ahorro/mes + ROI. CTA → WhatsApp. Costo auto fijo $90.000. | ✅ Implementado (solo `index.html`) |
| **Bot "Uno"** | Modo **IA** (`chat.php` → OpenAI) o, como fallback, **guion scripteado**: rubro → nombre → diagnóstico → web → equipo (3 tramos) → WhatsApp → email (opcional) → cierre (agenda/WhatsApp). Persiste cada paso vía `lead.php`/`chat.php`. | ✅ Implementado (solo `index.html`) |
| **Apertura proactiva del bot** | `setTimeout` a 5s; una vez por sesión vía `sessionStorage('botSeen')`. | ✅ Implementado (sin exit-intent) |
| **Panel "Tweaks"** | Tema dark/light, color de acento, tipografía. Persiste en `localStorage`, integra `postMessage` con editor visual. | ✅ Implementado |
| **Animaciones reveal / nav móvil** | IntersectionObserver + toggle de menú. | ✅ Implementado |

**El bot "Uno" tiene dos modos.** Si `chat.php` está habilitado (hay `openai_key` y `chat_enabled`), corre en **modo IA** (LLM compatible con OpenAI — OpenAI `gpt-4o-mini` o Gemini `gemini-2.5-flash` según `api_base`, T=0.3) con *function calling* (`guardar_lead`, `listo_para_agendar`) y la base de conocimiento de `ai-kb/kb_infouno.md` inyectada en el system prompt; si no, degrada al **guion scripteado** (plantilla fija por pasos). Defensas: `escapeHtml()` en el frontend (XSS del widget) y, en backend, *prepared statements* (mysqli) + sanitización en `db_lead.php`. Aún **no hay RAG**: la KB se inyecta entera, sin recuperación selectiva.

---

## 4. Matriz de Brechas (Documentado vs Implementado)

| Área | Objetivo documentado | Estado real | Brecha |
|---|---|---|---|
| **Frontend** | WordPress v6+ + Elementor (SSR, Core Web Vitals) | HTML estático servido tal cual | Migrar a WP o asumir estático como definitivo |
| **Motor IA** | OpenAI GPT-4o, T=0.3, RAG | Agente conversacional vía `chat.php` (API compatible con OpenAI: OpenAI `gpt-4o-mini` o Gemini `gemini-2.5-flash` según `api_base`, T=0.3), con fallback al guion | ✅ Implementado (KB en archivo, sin RAG por ahora) |
| **Persistencia** | MySQL `wp_infouno_leads` | MySQL `wp_infouno_leads` vía `lead.php`/`db_lead.php` (upsert por `session_id`, scoring/VIP, email) | ✅ Implementado (falta solo MySQL nativo de WP) |
| **Orquestación** | Make / Node.js + webhooks HTTPS | Ninguna | Sin middleware |
| **Taxonomía URLs** | Silos `/soluciones/…`, `/casos-exito/…` | Archivos planos `.html` | URLs no coinciden con el plan SEO |
| **R1 Activación** | 5s inactividad **o** exit-intent | 5s (timer) + exit-intent | ✅ Cumplido |
| **R2 Captura temprana** | Nombre + rubro antes de soluciones | Bot pide rubro → nombre → recién ahí el ejemplo | ✅ Cumplido |
| **R3 Lead scoring VIP** | Clasifica y alerta por webhook | Bot capta 3 tramos de equipo; `lead.php` marca VIP (equipo +5 + web) + email | ✅ Cumplido (alerta vía email) |
| **R4 Persistencia asíncrona** | Guardado paso a paso (Fetch) | `fetch('/lead.php')` paso a paso con `keepalive` (upsert por `session_id`) | ✅ Cumplido (recupera leads fríos) |
| **G1 Scope/tono** | Fallback anti "ChatGPT gratis" | Modo IA (`gpt-4o-mini`) con system prompt acotado a Infouno + fallback al guion | ✅ Cubierto (ver `guardrails.md`) |
| **G2 No precios** | Bot nunca da precios | El bot no habla de precios | ✅ Cumplido por diseño |
| **G3 MySQL/XSS guard** | Sanitización backend SQLi/XSS | Prepared statements (`mysqli`) + sanitización en `db_lead.php`; `escapeHtml`/`textContent` en frontend | ✅ Cubierto (ver `security-audit.md`) |
| **G4 Ley 25.326** | T&C visibles, datos de uso exclusivo | `privacidad.html` + consentimiento en bot/form + Consent Mode v2/GA4 opt-in + link en footer | ✅ Cumplido |
| **Checks (tel/email/agenda/UTM)** | Validaciones en tiempo real + Google Calendar | tel/email/UTM en `lead.php`+`site.js`; agenda vía agendador embebido | ✅ Cubiertos (agenda con widget, no API a medida) |

---

## 5. Riesgos y Observaciones

1. **Pérdida de leads:** ✅ mitigado. `site.js` persiste paso a paso vía `fetch('/lead.php')` con `keepalive`; el lead queda registrado aunque el usuario no llegue al cierre (R4).
2. **Trazabilidad:** ✅ mitigado. Se capturan UTM en `sessionStorage` y se guardan junto al lead (Check de Trazabilidad SEO).
3. **Validación de datos:** ✅ mitigado en backend. `db_lead.php` normaliza el teléfono (AR) y valida el email (formato + bloqueo de desechables) antes de persistir.
4. **Coherencia de marketing:** parcialmente vigente. El copy menciona "WordPress + MySQL", pero el frontend es HTML estático (sí hay IA real en el bot y MySQL en el backend). Alinear el discurso con el entregable para no sobreprometer la parte de WordPress.
5. **Dependencia del proveedor LLM / costo:** nuevo riesgo. El modo IA depende de una API externa (OpenAI o Gemini según `api_base`); `chat.php` topea turnos (16) y `max_tokens` para acotar costo y degrada al guion si la API falla. Conviene monitorear gasto y errores 5xx.
6. **Privacidad (Ley 25.326):** ✅ cubierto. `privacidad.html`, nota de consentimiento en el bot y bajo el formulario, y link "Privacidad" en el footer de todas las páginas.

---

## 6. Roadmap Sugerido (de MVP a Objetivo)

**Fase 0 — Consolidar el MVP estático (rápido, alto impacto)**
- ✅ Capturar UTM en `sessionStorage` y persistirlos con el lead (`site.js` → `captureUTM`/`postLead`).
- ✅ Validar teléfono (normalización AR) y email (bloqueo de desechables) en `lead.php`.
- ✅ Exit-intent como segundo disparador del bot (`site.js`, R1 completa).
- ✅ Aviso de privacidad (Ley 25.326, G4): `privacidad.html` + nota en bot/form + link en footer.

**Fase 0 cerrada.**

**Fase 1 — Persistencia mínima** ✅ implementada sobre DonWeb/cPanel
- ✅ Endpoint `lead.php` que recibe cada paso del bot y el formulario vía `fetch` (cumple R4, upsert por `session_id`).
- ✅ Guarda leads con el esquema de `taxonomy.md` (`db/schema.sql`); calcula `lead_scoring` y marca VIP (R3); avisa por email.
- ✅ Agenda resuelta con **agendador embebido** (modal en `site.js`, integrado en bot, contacto y CTA del home; URL en `window.INFOUNO.agenda`).

**Fase 1.5 — Baseline SEO on-page** ✅ implementada sobre el HTML estático (Sprint 1)
- ✅ `robots.txt` (bloquea backend/internos) + `sitemap.xml` con las 7 URLs.
- ✅ `canonical` + Open Graph + Twitter Card en las 7 páginas.
- ✅ Schema.org JSON-LD (`ProfessionalService` + `WebSite`) en el home.
- ✅ `title`/`meta description` refinados con keywords nacionales (web con IA + automatización/chatbots para PyMEs Argentina).

**Fase 1.6 — Contenido on-page + datos estructurados** ✅ implementada (Sprint 2)
- ✅ Sección FAQ visible (6 Q&A) + `FAQPage` JSON-LD en `servicios.html` (web con IA) y `soluciones-ia.html` (automatización/chatbots), con texto espejo schema↔visible.
- ✅ Interlinking bidireccional entre los dos pilares (refuerza el silo SEO) vía CTA al pie de cada FAQ.
- ✅ FAQ alineado a la narrativa de marca (Anthropic/Claude) y al guardrail G2 (sin precios).
- ✅ Landing dedicada de la **calculadora de ROI** (`calculadora-roi.html`): widget reutilizado (`initCalc` en `site.js`), copy propio orientado a ahorro/ROI, explicador, FAQ + `FAQPage`, en sitemap y enlazada desde el footer de todo el sitio.
- Pendiente (Sprint 2+): FAQ en el home, imagen OG dedicada (1200×630), migración a silos `/soluciones/…` con redirects 301.

**Fase 2 — Plataforma objetivo**
- Migrar a WordPress + MySQL si el negocio lo justifica; mantener Core Web Vitals.
- ✅ Agenda integrada (agendador embebido). Pendiente opcional: orquestación (Make/n8n) si se quiere sync a CRM.
- Incorporar capa cognitiva (OpenAI) con los guardrails de `guardrails.md` solo cuando el bot deje de ser scripteado.

---

> Referencias: visión en `ai/architecture.md` · datos en `ai/taxonomy.md` · reglas en `ai/rules.md` · seguridad en `ai/guardrails.md` · validaciones en `ai/checks.md` · especificación funcional en `sin-publicar/uploads/blueprint_home_infouno.md`.
