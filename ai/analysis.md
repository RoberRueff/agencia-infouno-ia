# Análisis del Proyecto — Estado Actual vs Objetivo

> Documento de análisis técnico. Contrasta lo que la documentación de `ai/` describe como **arquitectura objetivo** con lo que el repositorio **realmente implementa hoy**. Fecha de análisis: 2026-06-17.

---

## 1. Resumen Ejecutivo

El repositorio es un **MVP frontend estático** (HTML + CSS + un único JS), no la plataforma WordPress + IA + MySQL que describen los documentos de arquitectura. El sitio funciona como **máquina de captación de leads de bajo costo**: toda la conversión termina en un mensaje pre-armado de **WhatsApp** (`wa.me`). No hay backend, base de datos ni LLM en producción.

Los documentos `architecture.md`, `taxonomy.md`, `rules.md`, `guardrails.md` y `checks.md` describen la **visión objetivo** (target). Este archivo marca la brecha y propone el camino.

---

## 2. Estructura Real del Repositorio

```text
infouno-agencia-ia/                 (raíz = solo lo que se publica/opera)
├── index.html              Home: hero, dolores, calculadora, servicios, bot "Uno", confianza, CTA
├── soluciones-ia.html      Página de soluciones de IA
├── servicios.html          Servicios
├── casos.html              Casos de éxito
├── nosotros.html           Nosotros
├── contacto.html           Formulario de contacto → WhatsApp + lead.php
├── privacidad.html         Política de privacidad (Ley 25.326)
├── lead.php                Backend de captura de leads (DonWeb/cPanel + MySQL)
├── config.php              Credenciales MySQL + emails (completar en el server)
├── assets/
│   ├── site.js             TODA la lógica frontend: WhatsApp, calculadora, bot "Uno", leads, Tweaks
│   ├── styles.css          Estilos (temas dark/light, acentos, tipografías)
│   └── logo.png
├── db/
│   └── schema.sql          DDL de la tabla wp_infouno_leads
├── ai/                     Documentación (análisis, arquitectura, taxonomía, reglas, guardrails, checks)
└── sin-publicar/           Material NO publicado (no enlazado por el sitio)
    ├── Infouno - Sitio Web.html  Export single-file viejo (sin bot/calc activos)
    ├── infouno-agencia.zip       Backup original
    ├── uploads/                  Documentos fuente (blueprint, docx, logo)
    └── screenshots/              Capturas (caja de dolores)
```

- **Páginas:** 7 públicas (navegación: Home · Soluciones · Servicios · Casos · Nosotros · Contacto · Privacidad).
- **Dependencias externas:** ninguna en runtime salvo Google Fonts (carga condicional desde el panel Tweaks). Sin frameworks, sin build.

---

## 3. Lógica Real (qué hace `assets/site.js`)

| Módulo | Función | Estado |
|---|---|---|
| **WhatsApp helper** (`waLink`) | Construye links `wa.me` con mensaje codificado. Número en `window.INFOUNO`. | ✅ Implementado |
| **Formulario contacto** | Captura nombre/empresa/interés/mensaje y abre WhatsApp con el resumen. | ✅ Implementado (solo `contacto.html`) |
| **Calculadora ROI** | Sliders (horas WhatsApp/stock, costo/hora) → horas y ahorro/mes + ROI. CTA → WhatsApp. Costo auto fijo $90.000. | ✅ Implementado (solo `index.html`) |
| **Bot "Uno"** | Flujo conversacional **scripteado**: rubro → nombre → diagnóstico → web → equipo (3 tramos) → WhatsApp → email (opcional) → mensaje final `wa.me`. Persiste cada paso en `lead.php`. | ✅ Implementado (solo `index.html`) |
| **Apertura proactiva del bot** | `setTimeout` a 5s; una vez por sesión vía `sessionStorage('botSeen')`. | ✅ Implementado (sin exit-intent) |
| **Panel "Tweaks"** | Tema dark/light, color de acento, tipografía. Persiste en `localStorage`, integra `postMessage` con editor visual. | ✅ Implementado |
| **Animaciones reveal / nav móvil** | IntersectionObserver + toggle de menú. | ✅ Implementado |

**El bot NO usa IA.** Las respuestas "por rubro" son una plantilla fija; no hay clasificación, ni LLM, ni RAG. La única defensa de seguridad presente es `escapeHtml()` al renderizar lo que el usuario tipea en el chat (mitiga XSS en el propio widget).

---

## 4. Matriz de Brechas (Documentado vs Implementado)

| Área | Objetivo documentado | Estado real | Brecha |
|---|---|---|---|
| **Frontend** | WordPress v6+ + Elementor (SSR, Core Web Vitals) | HTML estático servido tal cual | Migrar a WP o asumir estático como definitivo |
| **Motor IA** | OpenAI GPT-4o, T=0.3, RAG | Agente conversacional con `gpt-4o-mini` (T=0.3) vía `chat.php`, con fallback al guion | ✅ Implementado (KB en archivo, sin RAG por ahora) |
| **Persistencia** | MySQL `wp_infouno_leads` | Ninguna; lead va por WhatsApp | No se almacena ni puntúa nada |
| **Orquestación** | Make / Node.js + webhooks HTTPS | Ninguna | Sin middleware |
| **Taxonomía URLs** | Silos `/soluciones/…`, `/casos-exito/…` | Archivos planos `.html` | URLs no coinciden con el plan SEO |
| **R1 Activación** | 5s inactividad **o** exit-intent | Solo 5s (timer) | Falta exit-intent |
| **R2 Captura temprana** | Nombre + rubro antes de soluciones | Bot pide rubro → nombre → recién ahí el ejemplo | ✅ Cumplido |
| **R3 Lead scoring VIP** | Clasifica y alerta por webhook | Bot capta 3 tramos de equipo; `lead.php` marca VIP (equipo +5 + web) + email | ✅ Cumplido (alerta vía email) |
| **R4 Persistencia asíncrona** | Guardado paso a paso (Fetch) | No existe; solo al final por WhatsApp | Sin recuperación de leads fríos |
| **G1 Scope/tono** | Fallback anti "ChatGPT gratis" | No aplica (no hay LLM) | Relevante solo al integrar IA |
| **G2 No precios** | Bot nunca da precios | El bot no habla de precios | ✅ Cumplido por diseño |
| **G3 MySQL/XSS guard** | Sanitización backend SQLi/XSS | Solo `escapeHtml` en frontend | Falta backend; sin SQL no hay SQLi hoy |
| **G4 Ley 25.326** | T&C visibles, datos de uso exclusivo | No verificado en las páginas | Revisar T&C / aviso de privacidad |
| **Checks (tel/email/agenda/UTM)** | Validaciones en tiempo real + Google Calendar | tel/email/UTM en `lead.php`+`site.js`; agenda vía agendador embebido | ✅ Cubiertos (agenda con widget, no API a medida) |

---

## 5. Riesgos y Observaciones

1. **Pérdida de leads:** sin persistencia, si el usuario no toca "Confirmar por WhatsApp", el lead se pierde por completo (contradice R4).
2. **Sin trazabilidad:** no se capturan UTM ni origen de campaña → no se puede medir qué canal convierte (contradice Check de Trazabilidad SEO).
3. **Validación inexistente:** teléfono y email entran como texto libre → datos sucios (contradice Checks de sintaxis/dominio).
4. **Coherencia de marketing:** las páginas dicen "WordPress + MySQL + IA real", pero el sitio es estático sin IA. Es copy aspiracional; alinear discurso con entregable para no sobreprometer.
5. **Privacidad (Ley 25.326):** confirmar que existe aviso de privacidad y T&C antes de capturar datos personales.

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

**Fase 2 — Plataforma objetivo**
- Migrar a WordPress + MySQL si el negocio lo justifica; mantener Core Web Vitals.
- ✅ Agenda integrada (agendador embebido). Pendiente opcional: orquestación (Make/n8n) si se quiere sync a CRM.
- Incorporar capa cognitiva (OpenAI) con los guardrails de `guardrails.md` solo cuando el bot deje de ser scripteado.

---

> Referencias: visión en `ai/architecture.md` · datos en `ai/taxonomy.md` · reglas en `ai/rules.md` · seguridad en `ai/guardrails.md` · validaciones en `ai/checks.md` · especificación funcional en `sin-publicar/uploads/blueprint_home_infouno.md`.
