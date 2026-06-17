# Context Loader — Protocolo de Carga de Contexto

> Ejecutar este protocolo **completo y en orden** antes de arrancar cualquier tarea. Se invoca dentro de la Fase 1 (Contexto) definida en `ai/templates/execution.md`.

---

## Paso 1 — Identidad del Proyecto

- **Nombre:** Infouno — Agencia IA.
- **Tipo:** Sitio web corporativo + chatbot conversacional con IA para captación de leads.
- **Objetivo de negocio:** Generar y cualificar leads comerciales mediante un agente de IA, manteniendo un sitio rápido e indexable.

## Paso 2 — Arquitectura

> **IMPORTANTE — leer primero `ai/analysis.md`.** Hay una brecha grande entre la arquitectura objetivo y el estado real.

**Estado actual (lo que hay en el repo):** sitio **HTML estático** (6 páginas) + `assets/styles.css` + `assets/site.js`. La lógica (calculadora ROI, bot "Uno" scripteado, formulario) vive toda en `site.js` y **termina en un link de WhatsApp** (`wa.me`). **No hay** WordPress, MySQL, OpenAI ni backend.

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
| `casos.html` | Casos de éxito. |
| `contacto.html` | Formulario / conversión. |
| `assets/site.js` | **Toda la lógica frontend**: WhatsApp, calculadora ROI, bot "Uno", panel Tweaks, persistencia de leads (`postLead`). |
| `lead.php` | Backend receptor de leads (DonWeb/cPanel + MySQL): upsert paso a paso, validaciones, scoring/VIP, email. |
| `config.php` | Credenciales de MySQL y emails de notificación (completar en el server; no publicar). |
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
