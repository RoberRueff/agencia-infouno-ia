# SEO.md — Auditoría y Estrategia Maestra · Infouno

Documento rector de SEO: diagnóstico del estado real + estrategia + roadmap priorizado.
Escrito como auditoría de especialista, sobre el sitio **realmente desplegado** en
`infouno.com.ar` (8 páginas HTML estáticas + backend PHP + bot IA con Gemini).

> **Alcance:** SEO orgánico nacional, B2B (PyMEs y comercios argentinos). Mercado único
> (es-AR), sin internacionalización. **Repo-only** (la carpeta `seo/` está bloqueada en
> `robots.txt`; no se sube a producción). Docs operativos relacionados:
> [keyword-map.md](keyword-map.md) · [log.md](log.md) · [checklist.md](checklist.md) ·
> [dashboard-reporting.md](dashboard-reporting.md) · [looker-guide.md](looker-guide.md).
> _Auditoría: 2026-06-19._

---

## 1. Resumen ejecutivo

Infouno tiene una **base técnica on-page sólida y poco común para un sitio nuevo**: títulos,
metas, canonicals, Open Graph, schema de FAQ y un sitemap/robots bien armados. El cuello de
botella **no es técnico, es de autoridad y contenido**: es un dominio recién publicado, sin
backlinks, sin motor de contenidos (blog/clusters) y con varias páginas finas. En SEO B2B
competitivo (desarrollo web + automatización IA en Argentina), **gana quien construye autoridad
temática y enlaces**, no quien tiene mejores `<title>`.

**Las 5 palancas de mayor impacto (en orden):**

1. **Activar la indexación y la medición** — verificar en Search Console, enviar el sitemap,
   y arrancar el ciclo GA4+GSC. Sin esto, todo lo demás es a ciegas. *(Esfuerzo bajo, impacto
   habilitante.)*
2. **Motor de contenidos (blog/guías) sobre los pilares** — es la palanca #1 de crecimiento
   orgánico y hoy **no existe**. Clusters que alimentan a `servicios` y `soluciones-ia`.
3. **Profundizar y dar schema a Casos de éxito** — `casos.html` es fino (323 palabras) y sin
   datos estructurados; los casos son oro para E-E-A-T y para keywords por industria.
4. **Autoridad / link building** — un plan deliberado de enlaces (directorios PyME, cámaras,
   prensa digital, partnerships). Un dominio nuevo sin enlaces no rankea por más on-page que tenga.
5. **Coherencia de marca y entidad** — resolver el mensaje "WordPress" (el sitio es estático +
   Gemini), y sumar schema de `Organization` con `sameAs` para construir la entidad en Google.

---

## 2. Contexto de negocio y búsqueda

- **Quién:** Infouno (Infouno.ia), consultora/agencia que vende **desarrollo web con IA** y
  **automatización/chatbots** a PyMEs argentinas. No posiciona productos de clientes.
- **ICP / intención:** dueños y responsables de PyMEs y comercios buscando (a) una web que
  "venda sola", (b) automatizar WhatsApp/atención, (c) reducir tareas repetitivas. Intención
  mayormente **comercial-transaccional** ("agencia de…", "chatbot para WhatsApp empresas") con
  una capa **informacional** de nutrición ("cómo automatizar la atención por WhatsApp").
- **Competencia:** agencias de desarrollo web + nuevos players de "IA para PyMEs". El terreno
  informacional (guías, comparativas, casos) está **poco saturado en español rioplatense** →
  oportunidad real de autoridad temática.
- **Restricción de negocio (G2):** el contenido **no da precios**. Para queries de "cuánto
  cuesta", se responde con valor + invitación al diagnóstico, nunca con números. Esto condiciona
  el contenido BOFU.

---

## 3. Auditoría técnica

| Área | Estado | Detalle / hallazgo |
|---|---|---|
| Indexabilidad | ✅ | `robots.txt` permite público, bloquea backend (`chat.php`, `lead.php`, `config*`) e internos (`/ai/`, `/db/`, `/seo/`, `/sin-publicar/`). |
| Sitemap | ✅ | `sitemap.xml` con las 8 URLs, `priority` y `lastmod`. Referenciado en robots. |
| Canonicals | ✅ | 1 canonical correcto por página, absoluto, HTTPS. |
| Títulos / metas | ✅ | Únicos y keyword-driven en las 8 páginas (largos dentro de rango). |
| Open Graph / Twitter | ⚠️ | Presentes (`og:type`, `og:title`, `twitter:card`), **pero `og:image` = `assets/logo.png`** en todas → falta imagen social dedicada **1200×630**. |
| Datos estructurados | ⚠️ | `ProfessionalService` + `WebSite` (home); `FAQPage` (servicios, soluciones-ia, calculadora). **Faltan:** `BreadcrumbList` (todo el sitio), `Organization` con `sameAs`, y schema en `casos`/`nosotros`/`contacto`. |
| Idioma / mobile | ✅ | `<html lang="es-AR">`, `viewport`, `charset utf-8`. Single-market (sin hreflang, correcto). |
| Imágenes | ✅ | Todas con `alt`. (Pocas imágenes: el diseño es CSS-driven, bueno para performance.) |
| Core Web Vitals | ✅* | HTML estático liviano, JS async; GA4 carga diferido tras consentimiento. *Sin field data aún (CrUX/GSC); medir tras tener tráfico.* |
| Encabezados | ✅ | 1 `<h1>` keyword-rich por página, jerarquía H2 razonable (salvo páginas finas con 1 H2). |
| HTTPS / estado | ✅ | Sitio servido por HTTPS, 200 OK, rápido (~0.25s la home). |
| URLs | ⚠️ | Planas `.html`. El plan de **silos** (`/soluciones/…`, `/casos-exito/…` con 301) está pendiente y atado a una eventual migración. |

**Veredicto técnico:** base por encima del promedio para un sitio nuevo. Los gaps son
**incrementales** (breadcrumbs, Organization schema, OG image, casos schema), no estructurales.

---

## 4. Auditoría on-page por página

| Página | Palabras (visible aprox.) | Schema | Diagnóstico |
|---|---|---|---|
| `index.html` | 683 | ProfessionalService, WebSite | OK pero mejorable: **sin FAQ** (pendiente), podría capturar más long-tail. |
| `servicios.html` | 973 | FAQPage (6) | **Pilar fuerte.** Buena profundidad + FAQ. Ampliar cuerpo keyword-driven. |
| `soluciones-ia.html` | 973 | FAQPage (6) | **Pilar fuerte.** Idem. Vigilar canibalización con servicios. |
| `calculadora-roi.html` | 673 | FAQPage (3) | **Activo linkable** (imán de enlaces/tráfico). Buen formato. |
| `casos.html` | **323** | **ninguno** | **Prioridad alta:** fino + sin schema. Casos = E-E-A-T + keywords por industria desaprovechados. |
| `nosotros.html` | 312 | ninguno | Fino. Falta `AboutPage`/`Organization`, equipo/autoría (E-E-A-T). |
| `contacto.html` | 222 | ninguno | OK (página de conversión). Sumar `ContactPage`. |
| `privacidad.html` | 355 | ninguno | Legal; correcto. `noindex` opcional (bajo valor SEO). |

---

## 5. Arquitectura de información y silos

**Hoy (plano):** 8 archivos `.html` en la raíz, con buen interlinking (16-20 enlaces internos
por página) e interlinking bidireccional entre los dos pilares (servicios ↔ soluciones-ia).

**Objetivo (silos temáticos):**
```
/ (home)
├── /soluciones/desarrollo-web-ia/      ← servicios.html
│     └── (clusters: web que vende, chatbot integrado, …)
├── /soluciones/automatizacion-procesos/ ← soluciones-ia.html
│     └── (clusters: automatizar WhatsApp, agente IA atención, stock…)
├── /casos-exito/                        ← casos.html
│     └── /casos-exito/<industria>/      ← 1 caso por industria (nuevo)
└── /herramientas/calculadora-roi/       ← calculadora-roi.html
```
La migración a silos con **301** mejora la semántica de URL y la fuerza temática, pero **depende
de la plataforma** (hoy estático; limpio recién con WordPress/rewrite). **No bloquea** el
crecimiento: se puede ganar autoridad con contenido sobre la estructura plana actual y migrar
después con redirects.

---

## 6. Motor de contenidos y keywords (la palanca #1)

**Diagnóstico:** existen los **pilares** (servicios, soluciones-ia) y un buen
[keyword-map.md](keyword-map.md), pero **no hay contenido de cluster** que construya autoridad
temática. Sin un motor de contenidos, el sitio compite solo con 4-5 páginas comerciales contra
competidores con decenas de artículos.

**Estrategia pillar-cluster:**
- **Pilar A — Desarrollo web con IA** (`servicios`) ← clusters informacionales:
  *"cómo hacer una web que venda sola"*, *"web con chatbot integrado"*, *"qué pedirle a una
  agencia web en 2026"*.
- **Pilar B — Automatización/chatbots** (`soluciones-ia`) ← clusters:
  *"cómo automatizar la atención por WhatsApp"*, *"qué es un agente de IA para empresas"*,
  *"automatizar el seguimiento de stock"*.
- **Pilar C — Casos por industria** (`casos`) ← un caso/artículo por rubro
  (contador, nutricionista/médico, fábrica, ecommerce) que ataca keywords de industria y nutre
  el embudo con prueba social.

**Reglas:** 1 keyword principal por URL (evitar canibalización), voseo, **sin precios** (G2),
cada artículo enlaza a su pilar y a un CTA (calculadora o diagnóstico). Ritmo sugerido: 2-4
piezas/mes sostenidas — la **constancia** vence al volumen esporádico.

**Quick win de keywords:** la calculadora de ROI es un **imán de enlaces** natural —
promocionarla como recurso es más fácil de linkear que una página de servicios.

---

## 7. E-E-A-T y señales de confianza

- ⚠️ **Coherencia de marca (mensaje "WordPress"):** la meta/copy de la home dice *"la estabilidad
  de WordPress"*, pero el sitio es **HTML estático + bot Gemini**. Para SEO no es penalizable,
  pero es una **incoherencia de posicionamiento** (y de honestidad de marca) que conviene
  resolver: o se alinea el discurso a lo real (rendimiento, IA propia) o se migra a WordPress.
  Hoy promete una tecnología que no se entrega.
- ⚠️ **Casos de éxito:** la prueba social es el activo E-E-A-T más fuerte para una agencia, y
  `casos.html` está infrautilizado (fino, sin schema). Desarrollar casos con resultados
  concretos + `Review`/`CreativeWork`/`ItemList` schema.
- ⚠️ **Entidad / autoría:** falta `Organization` con `sameAs` (perfiles sociales) para que Google
  consolide la entidad "Infouno"; y `nosotros` sin equipo/autoría visible. Autoría real refuerza
  confianza en contenido sobre IA.
- ✅ **Privacidad / legal:** `privacidad.html` + consentimiento (Ley 25.326) bien resuelto —
  señal de confianza positiva.

---

## 8. Autoridad / off-page (link building)

**Realidad:** dominio nuevo = autoridad ~0. **Sin enlaces, el on-page no alcanza.** Plan
deliberado y sostenido:

1. **Citaciones / directorios** AR de calidad (cámaras PyME, directorios de agencias, Clutch/
   similar, perfiles profesionales).
2. **Prensa/contenido digital:** publicar la calculadora de ROI y guías como recurso citable;
   guest posts en medios PyME/emprendedores.
3. **Partnerships:** intercambio con proveedores complementarios (contadores, estudios, etc.).
4. **Marca:** menciones (linkeadas o no) construyen entidad; consistencia de NAP si se hace local.

Métrica: priorizar **relevancia temática y geográfica** (AR, tech/PyME) sobre volumen.

---

## 9. Local SEO (decisión pendiente)

Hoy **sin Google Business Profile** (decisión del `README`). Vale evaluarlo: para una agencia AR,
un GBP + señales locales captura *"agencia desarrollo web [ciudad]"* y suma confianza/mapa.
Si el negocio es 100% remoto/nacional, es opcional; si hay base física o foco regional, es un
quick win de alto retorno. **Recomendación:** abrir GBP aunque sea como entidad de servicio
nacional (service-area business) — bajo costo, suma entidad y reseñas.

---

## 10. Medición y analítica

Ya implementado en esta etapa (ver [dashboard-reporting.md](dashboard-reporting.md)):
- ✅ **GA4** + banner de consentimiento + **Consent Mode v2** (Ley 25.326).
- ✅ **5 eventos de conversión** (`generate_lead`, `click_whatsapp`, `open_agenda`,
  `bot_lead_captured`, `click_phone` dormido).
- ✅ Backend con **leads en MySQL** (verdad de conversión más rica que GA4).

**Pendiente (habilitante, prioridad máxima):**
- Verificar el dominio en **Search Console** + enviar sitemap.
- Marcar eventos como **conversión** en GA4 + verificar en DebugView.
- Construir el **dashboard Looker** ([looker-guide.md](looker-guide.md)).
- **Ciclo mensual GA4 + GSC:** revisar queries emergentes, posiciones, CTR, errores de cobertura
  → realimenta el keyword-map y el calendario de contenidos.

---

## 11. Roadmap priorizado (por impacto × esfuerzo)

**Ola 1 — Habilitar y quick-wins (días, esfuerzo bajo, alto retorno):**
- [ ] Verificar GSC + enviar sitemap; conectar GA4/GSC (desbloquea todo lo demás).
- [ ] `BreadcrumbList` schema en las 8 páginas (mejora SERP, esfuerzo mínimo).
- [ ] `Organization` con `sameAs` en la home; `ContactPage` en contacto.
- [ ] Imagen **OG 1200×630** dedicada (hoy se usa el logo).
- [ ] FAQ visible + `FAQPage` en la **home**.
- [ ] Resolver el mensaje "WordPress" (alinear copy a lo real).

**Ola 2 — Profundidad y prueba social (semanas):**
- [ ] Reescribir/ampliar **Casos** + schema (`Review`/`ItemList`) + 1 caso por industria.
- [ ] Ampliar `nosotros` (equipo/autoría, `AboutPage`).
- [ ] Engrosar el cuerpo de los dos pilares con contenido keyword-driven.

**Ola 3 — Motor de contenidos y autoridad (continuo, mayor impacto a mediano plazo):**
- [ ] Lanzar blog/guías con el modelo pillar-cluster (2-4 piezas/mes).
- [ ] Plan de link building (directorios, prensa, partnerships).
- [ ] Evaluar GBP / local.

**Ola 4 — Estructural (cuando el negocio lo justifique):**
- [ ] Migración a **silos** `/soluciones/…` con 301 (atada a WordPress).
- [ ] Field data de Core Web Vitals (CrUX) y optimización fina si hace falta.

---

## 12. KPIs y metas

| Métrica | Fuente | Meta inicial (90 días) |
|---|---|---|
| Páginas indexadas | GSC Cobertura | 8/8 |
| Impresiones orgánicas | GSC | Tendencia creciente mes a mes |
| Clics orgánicos | GSC | Primeros clics en keywords de marca + long-tail |
| Posición media en keywords pilar | GSC | Entrar a top 20 → top 10 progresivo |
| Leads orgánicos | GA4 + `wp_infouno_leads` | Atribuir leads a tráfico orgánico |
| Tasa de conversión (leads/clics) | Looker (GA4+GSC) | Establecer baseline y mejorar |

> **Verdad de leads:** `wp_infouno_leads` (server-side); GA4 mide proporción por canal. Definido
> en [dashboard-reporting.md](dashboard-reporting.md).

---

## 13. Riesgos y watch-outs

1. **Expectativa de inmediatez:** SEO en dominio nuevo madura en **meses**. La medición debe
   gestionar la expectativa (las primeras semanas son indexación, no ranking).
2. **Canibalización** entre `servicios` y `soluciones-ia` (temas solapados) → mantener 1 keyword
   principal por URL e interlinking claro.
3. **Contenido fino** penaliza percepción de calidad → priorizar profundidad antes que más URLs.
4. **Promesa vs entrega ("WordPress"):** incoherencia de marca que erosiona confianza si un
   prospecto técnico lo nota.
5. **Dependencia de constancia:** el motor de contenidos y el link building solo funcionan
   **sostenidos**; arranques esporádicos no construyen autoridad.

---

> **Próximo paso recomendado:** ejecutar la **Ola 1** (sobre todo verificar GSC + enviar sitemap),
> porque habilita la medición que dirige todo lo demás. Cada ola realimenta el
> [keyword-map.md](keyword-map.md) y la [bitácora](log.md).
