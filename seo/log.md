# Bitácora SEO — Infouno

Registro de lo implementado y lo pendiente. Espejo operativo de la Fase 1.5/1.6 de `ai/analysis.md`.

---

## ✅ Sprint 1 — Cimientos técnicos + on-page base (2026-06-17)

- **`robots.txt`** (raíz): permite páginas públicas, bloquea backend (`chat.php`, `lead.php`, `db_lead.php`, `config*.php`) e internos (`/ai/`, `/ai-kb/`, `/db/`, `/sin-publicar/`), referencia el sitemap.
- **`sitemap.xml`** (raíz): todas las URLs públicas con prioridades.
- **`canonical` + Open Graph + Twitter Card** en las 7 páginas originales.
- **Schema.org JSON-LD** `ProfessionalService` + `WebSite` en `index.html` (tel real, `areaServed: Argentina`).
- **Titles / meta descriptions** refinados con keywords nacionales por pilar.
- Verificado: JSON-LD válido, sitemap bien formado, 1 canonical/og/title por página, sin regresión de Core Web Vitals.

## ✅ Sprint 2 — Contenido on-page + datos estructurados (2026-06-17)

- **FAQ visible + `FAQPage` JSON-LD** en `servicios.html` (6 Q&A) y `soluciones-ia.html` (6 Q&A), texto espejo schema↔visible.
- **Interlinking bidireccional** entre pilares (CTA al pie de cada FAQ) → refuerza el silo.
- Contenido alineado a la narrativa de marca (Anthropic/Claude) y al guardrail G2 (sin precios).

## ✅ Landing Calculadora ROI (2026-06-17)

- Nueva página **`calculadora-roi.html`**: widget de calculadora reutilizado (`initCalc` en `site.js`, sin tocar JS), copy propio orientado a ahorro/ROI, explicador de 3 pasos, FAQ (3 Q&A) + `FAQPage`.
- Sumada al **`sitemap.xml`** (8 URLs) y enlazada desde el **footer** de las 7 páginas (columna Servicios).
- Cross-link interno con `soluciones-ia.html`.

## ✅ Medición — GA4 + consentimiento + eventos (2026-06-18)

- **Banner de consentimiento + Google Consent Mode v2** en `assets/site.js` (+ estilos en `styles.css`). GA4 (`G-54V1PR8K7V`) **opt-in**: no carga `gtag.js` ni setea cookies hasta aceptar (Ley 25.326 / G4). Decisión recordada en `localStorage`.
- **5 eventos de conversión** vía `window.infoTrack()`: `generate_lead` (form), `click_whatsapp` (delegado), `click_phone` (delegado; sin `tel:` aún), `open_agenda`, `bot_lead_captured` (cierre del bot, una vez por sesión).
- Verificado en Chrome headless: banner aparece/recuerda decisión, Aceptar carga GA4, Rechazar no, y 4/5 eventos disparan (falta `tel:` para el quinto).
- Base para el dashboard Looker: ver [dashboard-reporting.md](dashboard-reporting.md).

---

## ⏳ Pendiente

| Prioridad | Tarea | Tipo |
|---|---|---|
| Media | FAQ en el home (`index.html`) con contenido distinto (no duplicar) | Código |
| Media | Imagen OG dedicada 1200×630 (hoy se usa `logo.png`) | Diseño |
| Baja | Migración a silos `/soluciones/…`, `/casos-exito/…` + redirects 301 | Depende de WordPress |
| Baja | Expandir cuerpo de cada pilar con más contenido keyword-driven | Contenido |
| Continuo | SEO off-page: directorios AR, cámaras pyme, guest posts | Marketing |
| Continuo | Bucle mensual GA4 + Search Console (revisar keywords/errores) | Análisis |

> Decisiones de copy abiertas (a criterio del negocio): mención de "WordPress" en la meta del home y de "Anthropic/Claude" en el cuerpo de `soluciones-ia.html` (el bot real corre con OpenAI `gpt-4o-mini`). Es posicionamiento de marca, no bug técnico.
