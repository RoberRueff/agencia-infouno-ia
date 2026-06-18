# Dashboard de Reporting por Cliente (SEO + Leads) — Plan de implementación

Plan del entregable de **reporting** que Infouno ofrece a sus clientes: una vista por
cliente con embudo, ranking de keywords y origen de leads. Infouno funciona como **piloto
y plantilla**; luego se clona por cliente.

> Estado: **en implementación** — Tareas 2-4 hechas y verificadas en navegador (banner de
> consentimiento + Consent Mode v2 + GA4 `G-54V1PR8K7V` + los 5 eventos de conversión).
> Pendientes: Tarea 1 (verificar GSC) y Tarea 6 (plantilla Looker). Fuente de verdad técnica
> del proyecto: `ai/` (ver `ai/analysis.md`). Este doc vive en `seo/` por ser un entregable
> de medición/SEO. _Creado: 2026-06-17 · Actualizado: 2026-06-18._

---

## 1. Decisión de arquitectura

**MVP = Looker Studio + GA4 + Google Search Console. No se construye panel a medida.**

Looker conecta nativo a GSC y GA4, es gratis, multi-cuenta y replicable por cliente con
plantillas. Un panel propio (sobre las APIs de GA4/GSC) reinventaría eso por semanas de
trabajo; solo se justifica más adelante y solo para Infouno, si se quiere cruzar los leads
de `wp_infouno_leads` con el tráfico (Looker no ve la tabla MySQL).

**Regla de alcance:**
- **Clientes** (cualquier web): GA4 + GSC + Looker. Punto.
- **Infouno** (sitio propio): lo anterior **+** los datos reales de `wp_infouno_leads`,
  más ricos que los eventos de GA4.

### Decisiones tomadas (locked)
- **Piloto:** Infouno primero → después se clona a clientes.
- **Consentimiento:** banner de cookies + **Google Consent Mode v2** (cumple Ley 25.326 / G4).
- **Dashboard:** Looker Studio estándar, 3 módulos. Sin panel a medida.

---

## 2. Estado de partida (relevante)

- **No hay ninguna instrumentación de analytics hoy:** cero GA4, GTM, gtag o `dataLayer`
  en todo el repo (verificado por grep).
- **Sí** hay trazabilidad server-side de leads en `wp_infouno_leads` (UTM, scoring, VIP)
  vía `lead.php`/`db_lead.php`/`chat.php`.
- **No hay banner de cookies.** Existe `privacidad.html`, pero GA4 setea cookies y exige
  consentimiento previo.

---

## 3. Secuencia de ejecución (Infouno piloto)

| # | Tarea | Toca | Estado |
|---|---|---|---|
| 1 | Crear GA4 (`G-54V1PR8K7V`) + verificar propiedad en GSC | Cuentas Google | ⏳ GA4 creada; falta verificar GSC |
| 2 | Banner de consentimiento + Consent Mode v2 (default `denied`) | `assets/site.js` + `styles.css` | ✅ hecho y verificado |
| 3 | Cargar `gtag.js` async **tras** consentimiento | (absorbido en Tarea 2: `site.js` corre en las 8 páginas) | ✅ hecho |
| 4 | Disparar los 5 eventos de conversión en los handlers existentes | `assets/site.js` | ✅ 4/5 verificados; `click_phone` listo pero sin `tel:` aún |
| 5 | Verificar en navegador + DebugView | navegador | ✅ verificado en Chrome headless; ⏳ falta DebugView en la cuenta GA4 |
| 6 | Construir plantilla Looker (3 módulos) | Looker | ⏳ pendiente |

**Orden crítico cumplido:** 2 → 3 → 4. El consentimiento va primero; nada de `gtag` antes
del banner. La Tarea 3 quedó absorbida por la 2 (el módulo de `site.js` está en las 8 páginas).

> **Nota `click_phone`:** instrumentado vía listener delegado, pero hoy el sitio no tiene
> links `tel:` (el teléfono solo vive en el JSON-LD). El evento se activará solo cuando se
> agregue un teléfono clickeable.

---

## 4. Eventos de conversión (Tarea 4)

Los handlers de click ya existen en `assets/site.js`; solo hay que disparar el evento.

| Evento GA4 | Disparador | Ubicación actual |
|---|---|---|
| `generate_lead` | submit del form de contacto | `assets/site.js` (handler del form) |
| `click_whatsapp` | clicks a `wa.me` | `waLink()` / botones — `site.js:20`, `162` |
| `click_phone` | clicks a `tel:` | agregar en los `.html` con teléfono |
| `open_agenda` | apertura del agendador | `site.js:86`, `251`, `401` |
| `bot_lead_captured` | function call `guardar_lead` del bot | `site.js` / `chat.php` |

Mapean directo al módulo "Origen de leads" del dashboard.

---

## 5. Dashboard Looker Studio (Tarea 6) — 3 módulos

1. **Embudo** (scorecards): Impresiones (GSC) · Clics orgánicos (GSC) · Leads totales
   (GA4, suma de eventos de conversión) · Tasa de conversión = `Leads / Clics × 100`
   (campo calculado).
2. **Tabla 1 — Ranking de keywords** (GSC): Query · Clics · Impresiones · Posición media.
   Ordenada por clics.
3. **Tabla 2 — Origen de leads** (GA4): desglose por `event_name`
   (form / WhatsApp / teléfono / agenda).

Se construye **una vez** como template y se clona por cliente cambiando las fuentes de
datos. Filtro de rango de fechas arriba.

---

## 6. Productizar por cliente (playbook)

Operativo, no código de este repo:
1. Acceso a GA4 + GSC del cliente.
2. Instrumentar su web (mismo patrón Fase 3/4; adaptar el snippet si no es web nuestra).
3. Clonar la plantilla Looker, reconectar fuentes, compartir link de solo-lectura.
4. Definir qué cuenta como "lead" para ese rubro (médico → WhatsApp; fábrica → formulario).

---

## 7. Automatización (diferido — encaja en la Capa de Orquestación de `ai/architecture.md`)

Recién con datos fluyendo y A–C estables:
- Lead de formulario → Make → alta en CRM (HubSpot/Pipedrive) + alerta Slack/WhatsApp +
  mail de confirmación al cliente.
- Servicios profesionales → bot WhatsApp + link a agenda (Calendly/TuTurno).

---

## 8. Riesgos

1. **Consentimiento/cookies (legal + G4):** el más serio. Sin banner + Consent Mode, GA4
   viola Ley 25.326. **Bloqueante de la Tarea 3.**
2. **Rendimiento (LCP):** mitigado cargando `gtag` async y diferido tras consentimiento.
   Medir home antes/después (objetivo < 2.5s).
3. **Doble fuente de verdad de leads (Infouno):** GA4 y `wp_infouno_leads` darán números
   distintos (GA4 pierde eventos sin consentimiento o con adblock). **Decisión:**
   MySQL = verdad de leads; GA4 = verdad de tráfico.
4. **Privacidad multi-cliente:** accesos de solo-lectura; nunca mezclar propiedades.

---

## 9. Definition of Done (MVP, Fase Infouno)

- GA4 recibe los 5 eventos de conversión (verificado en DebugView).
- Banner operativo; sin cookies de GA4 antes de aceptar.
- GSC enlazado y devolviendo queries.
- Dashboard Looker con los 3 módulos mostrando datos reales de Infouno.
- LCP del home sigue < 2.5s (medido antes/después).
