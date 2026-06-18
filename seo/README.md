# SEO — Infouno

Centro de documentación y seguimiento del trabajo SEO. **Solo documentación**: los archivos funcionales SEO viven en la raíz del proyecto (no se mueven aquí, por estándar de los buscadores).

> Fuente de verdad técnica del proyecto: `ai/` (ver `ai/analysis.md`). Esta carpeta consolida el plan, el keyword research y el seguimiento SEO para tenerlos de un vistazo.

## Índice

| Archivo | Contenido |
|---|---|
| [keyword-map.md](keyword-map.md) | Investigación de palabras clave por pilar e intención. |
| [log.md](log.md) | Bitácora de lo implementado (por sprint) y lo pendiente. |
| [checklist.md](checklist.md) | Acciones manuales fuera del código (Search Console, GBP, OG, etc.). |
| [dashboard-reporting.md](dashboard-reporting.md) | Plan del dashboard de reporting por cliente (GA4 + GSC + Looker). Estado: en implementación. |
| [looker-guide.md](looker-guide.md) | Guía paso a paso para construir el dashboard en Looker Studio y clonarlo por cliente. |

## Dónde vive cada cosa (no mover)

| Recurso SEO | Ubicación real | Por qué ahí |
|---|---|---|
| `robots.txt` | **raíz** (`/robots.txt`) | Google solo lo lee en la raíz exacta del dominio. |
| `sitemap.xml` | **raíz** (`/sitemap.xml`) | Convención; referenciado desde `robots.txt`. |
| Páginas `.html` | **raíz** | Su ruta en disco *es* su URL pública. Moverlas rompe canonical/sitemap/enlaces. |
| `canonical`, Open Graph, Twitter Card | dentro del `<head>` de cada `.html` | Son meta-etiquetas, no archivos. |
| Schema.org (JSON-LD) | inline en `index.html`, `servicios.html`, `soluciones-ia.html`, `calculadora-roi.html` | Debe estar en la página que describe. |

## Estrategia (resumen)

- **Público:** B2B nacional (PyMEs y comercios argentinos). Infouno es la **agencia que vende** desarrollo web con IA y automatización; no posiciona productos de cliente.
- **Alcance:** Argentina (sin SEO local / Google Business Profile por ahora).
- **Pilares:** (A) desarrollo web con IA · (B) automatización / chatbots · (C) casos de éxito por industria.
- **Plataforma:** SEO sobre el HTML estático actual (quick-wins), sin esperar la migración a WordPress.

_Última actualización: 2026-06-17._
