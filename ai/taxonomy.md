# Taxonomía de Datos y Contenidos (Taxonomy)

Una correcta taxonomía estructura el sitio para que Google entienda perfectamente la autoridad del dominio (**E-E-A-T**) y, al mismo tiempo, guíe orgánicamente al usuario hacia el chatbot de recolección de leads.

---

## A. Estructura de URLs (SEO Silo Architecture)

> **Estado actual:** el sitio sirve archivos planos `.html` (`index.html`, `soluciones-ia.html`, `servicios.html`, `casos.html`, `nosotros.html`, `contacto.html`). La estructura de silos de abajo es el **objetivo SEO** a alcanzar al migrar a WordPress (ver `ai/analysis.md`).

### Mapa actual (archivos `.html`)

| URL actual | Equivalente objetivo (silo) |
|---|---|
| `/index.html` | `/` |
| `/soluciones-ia.html` | `/soluciones/` |
| `/calculadora-roi.html` | (herramienta / imán de leads, mantener) |
| `/servicios.html` | (consolidar en `/soluciones/`) |
| `/casos.html` | `/casos-exito/` |
| `/nosotros.html` | `/nosotros/` |
| `/contacto.html` | `/contacto/` |

### Objetivo (SEO Silo)

- **`infouno.com.ar/`** — *Home Page*: captadora principal, Hero con propuesta de valor, widget e imán de leads.
- **`infouno.com.ar/soluciones/`** — Página pilar de categoría.
  - `.../soluciones/desarrollo-web-ia` — WordPress + MySQL + automatización integrada.
  - `.../soluciones/automatizacion-procesos` — Chatbots conversacionales, agentes 24/7 y sincronización de stock.
- **`infouno.com.ar/casos-exito/`** — Sección transaccional de prueba social enfocada a industrias argentinas.
  - `.../casos-exito/ecommerce-retail`
  - `.../casos-exito/servicios-profesionales`

```text
infouno.com.ar/
├── soluciones/                         (pilar de categoría)
│   ├── desarrollo-web-ia
│   └── automatizacion-procesos
└── casos-exito/                        (prueba social / transaccional)
    ├── ecommerce-retail
    └── servicios-profesionales
```

---

## B. Esquema de Datos de los Leads (Campos MySQL)

> **Estado actual:** ✅ la persistencia ya está implementada. `lead.php`/`db_lead.php` guardan cada lead en MySQL (`wp_infouno_leads`, ver `db/schema.sql`) con upsert por `session_id`, mapeo a esta taxonomía (`lead_infrastructure`, `lead_size`), `lead_scoring` y `lead_vip`. El esquema de abajo refleja lo que hoy se persiste. Lo único pendiente vs el objetivo es la migración a WordPress y la clasificación de rubro por IA "automatizada" (hoy el rubro entra tal como lo capta el bot).

Cada contacto capturado por el bot interactivo o las calculadoras se normaliza bajo la siguiente estructura taxonómica relacional (tabla `wp_infouno_leads`):

| Campo | Tipo | Descripción |
|---|---|---|
| `lead_id` | `INT` (Primary Key) | Identificador único del lead. |
| `lead_name` | `VARCHAR` | Capturado dinámicamente. |
| `lead_rubro` | `VARCHAR` | Clasificación industrial automatizada por la IA. |
| `lead_infrastructure` | `ENUM('no_web', 'has_web')` | Estado de presencia web actual. |
| `lead_size` | `ENUM('solo', 'team_small', 'team_large')` | Tamaño del negocio. |
| `lead_phone` | `VARCHAR` | Formato internacional validado. |
| `lead_email` | `VARCHAR` | Validado contra dominios reales. |
| `lead_scoring` | `INT` | Algoritmo interno basado en el tamaño del negocio y el estado web. |
