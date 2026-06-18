# Context Loader — Protocolo de Carga de Contexto

> Ejecutar este protocolo **completo y en orden** antes de arrancar cualquier tarea. Se invoca dentro de la Fase 1 (Contexto) definida en `ai/templates/execution.md`.

---

## Paso 1 — Identidad del Proyecto

- **Nombre:** Infouno — Agencia IA.
- **Tipo:** Sitio web corporativo + chatbot conversacional con IA para captación de leads.
- **Objetivo de negocio:** Generar y cualificar leads comerciales mediante un agente de IA, manteniendo un sitio rápido e indexable.

## Paso 2 — Arquitectura

> **IMPORTANTE — leer primero `ai/analysis.md`.** Hay una brecha grande entre la arquitectura objetivo y el estado real.

**Estado actual (lo que hay en el repo):** sitio **HTML estático** (7 páginas) + `assets/styles.css` + `assets/site.js`, **más una capa backend en PHP** sobre DonWeb/cPanel. El frontend (calculadora ROI, bot "Uno", formulario, agenda) vive en `site.js`. El bot "Uno" corre en **dos modos**: IA real vía `chat.php` (OpenAI `gpt-4o-mini`, T=0.3, con *function calling*) y, si la IA no está disponible, degrada al **guion scripteado**. Los leads se **persisten paso a paso** en MySQL (`wp_infouno_leads`) vía `lead.php`/`db_lead.php`, con scoring/VIP y aviso por email; el cierre ofrece agenda embebida y/o WhatsApp. **Todavía NO hay** WordPress/Elementor ni orquestación (Make/Node.js): esas dos capas siguen siendo objetivo.

**Arquitectura objetivo (`ai/architecture.md`):**

1. **Presentación (Frontend):** WordPress v6+ + Elementor. Foco SEO y Core Web Vitals (LCP < 2.5s).
2. **Edge / Agent:** Script de Voiceflow / Typebot inyectado de forma asíncrona.
3. **Middleware / Orquestación:** Make / Node.js. Recibe webhooks HTTPS POST.
4. **Cognitiva (IA Engine):** OpenAI API (GPT-4o+), temperatura `T = 0.3`, con RAG de contexto local.
5. **Datos (Persistencia):** MySQL nativo de WordPress + tabla `wp_infouno_leads`. CRM / Google Calendar.

## Paso 3 — Mapa de Archivos

| Recurso | Propósito |
|---|---|
| `index.html` | Página principal (home). |
| `nosotros.html` | Página "Nosotros". |
| `servicios.html` | Catálogo de servicios. |
| `soluciones-ia.html` | Soluciones de IA. |
| `calculadora-roi.html` | Landing de la calculadora de ROI (widget reutilizado de `site.js`; imán de tráfico SEO). |
| `casos.html` | Casos de éxito. |
| `contacto.html` | Formulario / conversión. |
| `assets/site.js` | **Toda la lógica frontend**: WhatsApp, calculadora ROI, bot "Uno" (modo IA + guion), agenda embebida, panel Tweaks, persistencia de leads (`postLead`). |
| `chat.php` | Proxy del bot "Uno" a OpenAI (`gpt-4o-mini`, T=0.3) con *function calling* (`guardar_lead`, `listo_para_agendar`) y fallback al guion. La API key vive solo aquí (vía `config.php`). |
| `lead.php` | Receptor de leads (formulario + bot scripteado). Delega la persistencia en `db_lead.php`. |
| `db_lead.php` | Persistencia compartida (`lead.php` + `chat.php`): sanitización, validación tel/email, mapeo a taxonomía, scoring/VIP (R3), upsert por `session_id` (R4) y email. |
| `config.php` | Credenciales de MySQL, OpenAI y emails (completar en el server; **no se versiona**). |
| `config.sample.php` | Plantilla versionada de `config.php`, sin credenciales. |
| `robots.txt` | Reglas de rastreo (bloquea backend/internos) + referencia al `sitemap.xml`. |
| `sitemap.xml` | Mapa de las 7 URLs públicas para Google Search Console. |
| `ai-kb/kb_infouno.md` | Base de conocimiento del bot "Uno" (se inyecta en el system prompt de `chat.php`). |
| `db/schema.sql` | DDL de la tabla `wp_infouno_leads` (pegar en phpMyAdmin). |
| `assets/styles.css` | Estilos (temas dark/light, acentos, tipografías). |
| `sin-publicar/uploads/blueprint_home_infouno.md` | Especificación funcional de la home (embudo). |
| `sin-publicar/` | Material no publicado: export viejo, zip, documentos fuente y capturas. |
| `ai/analysis.md` | **Análisis estado actual vs objetivo + roadmap (LEER PRIMERO).** |
| `ai/architecture.md` | Arquitectura técnica objetivo de referencia. |
| `ai/taxonomy.md` | Taxonomía de URLs (SEO Silo) y esquema de leads MySQL. |
| `ai/rules.md` | Reglas de negocio operativas (activación, captura, scoring, persistencia). |
| `ai/guardrails.md` | Barreras de seguridad y control de la IA (scope, precios, SQLi/XSS, Ley 25.326). |
| `ai/checks.md` | Verificaciones del embudo en tiempo real (teléfono, email, agenda, UTM). |
| `ai/templates/execution.md` | Esqueleto de trabajo de la sesión. |
| `seo/` | Documentación y seguimiento SEO (keyword map, bitácora, checklist manual). Solo docs; `robots.txt`/`sitemap.xml` y las páginas viven en la raíz. |
| `robots.txt` / `sitemap.xml` | Archivos funcionales SEO (raíz). Reglas de rastreo y mapa de URLs. |

## Paso 4 — Restricciones y Prioridades

- **No degradar SEO ni rendimiento.** Scripts asíncronos, no bloquear render.
- **Seguridad:** sin claves de API ni credenciales en el cliente; webhooks por HTTPS.
- **Coherencia comercial:** tono directo, orientado a conversión (T = 0.3).
- **Idioma:** todo en español.

## Paso 5 — Checklist de Salida del Loader

- [ ] Entiendo el objetivo de negocio del proyecto.
- [ ] Tengo presente la arquitectura y sus 5 capas.
- [ ] Sé qué archivos toca la tarea actual.
- [ ] Conozco las restricciones (SEO, rendimiento, seguridad, idioma).
- [ ] Listo para volver a la Fase 2 (Planificación) de `execution.md`.
