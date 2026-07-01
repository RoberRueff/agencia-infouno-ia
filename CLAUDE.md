# CLAUDE.md — Infouno · Agencia IA

Guía para Claude Code al trabajar en este repositorio. Idioma de trabajo: **español**.

## ⚠️ Protocolo obligatorio (leer antes de cualquier tarea)

Este proyecto define un esqueleto de trabajo en `ai/`. Antes de tocar nada, ejecutar **en orden**:

1. **`ai/templates/execution.md`** — esqueleto de trabajo de la sesión (fases y reglas transversales).
2. **`ai/context-loader.md`** — protocolo de carga de contexto (dentro de la Fase 1).
3. **`ai/analysis.md`** — **leer primero dentro del contexto:** estado real vs objetivo y la brecha pendiente.

Los documentos `ai/` son la **fuente de verdad**. Este archivo solo orienta y enlaza; si hay conflicto, mandan los `ai/`. Al cambiar el comportamiento del sistema, **actualizar el doc `ai/` correspondiente en el mismo commit** para no reintroducir deriva de documentación.

## Qué es

Sitio corporativo + chatbot "Uno" para **captar y cualificar leads** de PyMEs argentinas, manteniendo un sitio rápido e indexable.

## Stack

- **Actual:** HTML estático (8 páginas) + `assets/site.js` · backend PHP en DonWeb/cPanel (`chat.php`, `lead.php`, `db_lead.php`) · LLM vía API compatible con OpenAI, proveedor configurable por `api_base` (OpenAI `gpt-4o-mini` / Gemini `gemini-2.5-flash`, T=0.3) con fallback al guion · MySQL (`wp_infouno_leads`). Sin build system ni gestor de paquetes.
- **Objetivo (pendiente):** WordPress (Core v6+) + Elementor · orquestación Make/Node.js. Ver `ai/architecture.md` y el roadmap en `ai/analysis.md`.

## Mapa rápido

| Recurso | Propósito |
|---|---|
| `*.html` (raíz) | 8 páginas públicas. El bot vive solo en `index.html`; la calculadora ROI, en `index.html` y `calculadora-roi.html`. |
| `assets/site.js` | Toda la lógica frontend: WhatsApp, calculadora ROI, bot "Uno" (IA + guion), agenda, leads, Tweaks. |
| `chat.php` | Proxy del bot al LLM vía API compatible con OpenAI (OpenAI/Gemini según `api_base`); function calling + fallback. La API key vive solo aquí (vía `config.php`). |
| `lead.php` / `db_lead.php` | Recepción y persistencia de leads (upsert por `session_id`, scoring/VIP, email). |
| `ratelimit.php` | Rate-limit anti-abuso (file-based, sin deps) para `chat.php`/`lead.php`/`diagnostico.php`/`diagnostico2.php`. Anti-spam de leads (honeypot + throttling). |
| `metodo-uno/` | Landing del **Método UNO® — Diagnóstico Nivel 1** (wizard) + `public/diagnostico.php`: proxea al LLM (reusa `config.php`) y persiste el lead vía `db_lead.php` (`source=metodo-uno`). PHP, sin Node. Enlazada desde el nav/CTA del home. |
| `metodo-dos/` | **Método DOS® — Diagnóstico Inteligente Nivel 2 (IOI®)**: wizard de 4 fases + `public/diagnostico2.php`. Motor de scoring **modular y puro** en `src/Scoring/` (`IOIEngine` + `ScoringConfig`), con tests en `tests/` (`php metodo-dos/tests/IOIEngineTest.php`). Calcula el IOI® (0–100) determinístico, costo de inacción y 3 puntos críticos; persiste el lead (`source=metodo-dos`) y pide el narrativo al LLM. Enlazada desde el CTA del home. |
| `config.php` | Credenciales MySQL + LLM (`api_base`/key) + emails. **No se versiona** (`.gitignore`); plantilla en `config.sample.php`. Guía de deploy en `ai/deploy-checklist.md`. |
| `ai-kb/kb_infouno.md` | Base de conocimiento inyectada en el system prompt de `chat.php`. |
| `db/schema.sql` | DDL de `wp_infouno_leads`. |
| `ai/` | Documentación: protocolo, análisis, arquitectura, taxonomía, reglas, guardrails, checks. |
| `seo/` | Documentación y seguimiento SEO: keyword map, bitácora, checklist manual (solo docs; los archivos funcionales viven en la raíz). |

Detalle completo del mapa de archivos en `ai/context-loader.md` (Paso 3).

## Correr en local

- **Solo HTML/CSS/JS:** abrir los `.html` en el navegador o `python3 -m http.server`. El bot detecta que `chat.php` no responde y cae al guion scripteado.
- **Con backend (chat.php/lead.php):** requiere PHP + MySQL → `php -S localhost:8000` desde la raíz, con un `config.php` completo. En producción corre en DonWeb/cPanel.

No hay suite de tests automatizada: verificar el comportamiento real (bot, calculadora, formulario, persistencia) en el navegador.

## Reglas y restricciones

- **SEO y rendimiento primero:** scripts asíncronos/no bloqueantes; no penalizar LCP (< 2.5s) ni el rastreo de Google.
- **Seguridad:** nunca claves del LLM (OpenAI/Gemini) ni credenciales MySQL en el frontend; backend con prepared statements y sanitización; `escapeHtml` en el chat.
- **IA:** `T = 0.3`, tono comercial directo (voseo argentino). El bot **no da precios** (G2) y solo asesora sobre Infouno (G1). Reglas completas en `ai/guardrails.md` y `ai/rules.md`.
- **Trazabilidad:** todo lead relevante se persiste paso a paso en `wp_infouno_leads` (R4), con UTM.
- **Privacidad:** Ley 25.326 — consentimiento visible antes de capturar datos (`privacidad.html`).
- **Cambios mínimos y reversibles;** respetar el estilo del código existente. No hacer commit/push salvo que el usuario lo pida.
