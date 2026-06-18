# Guía — Dashboard de reporting en Looker Studio (paso a paso)

Cómo construir el dashboard de los 3 módulos (embudo + ranking de keywords + origen de
leads) conectando **GA4** y **Google Search Console**, y cómo **clonarlo por cliente**.

> Contexto y decisiones: ver [dashboard-reporting.md](dashboard-reporting.md). El panel no es
> código: se arma en [lookerstudio.google.com](https://lookerstudio.google.com). Esta guía es
> el playbook repetible. _Creado: 2026-06-18._

---

## 0. Requisitos previos (una vez por propiedad/cliente)

- [ ] **GA4** recibiendo datos. Para Infouno: `G-54V1PR8K7V`.
- [ ] Los **eventos de conversión marcados como "Conversión / Key event"** en GA4:
      Admin → Eventos → activar el toggle "Marcar como evento clave" en `generate_lead`,
      `click_whatsapp`, `open_agenda`, `bot_lead_captured` (y `click_phone` si el cliente
      usa teléfono). Sin esto, GA4 igual los cuenta como eventos, pero no como conversiones.
- [ ] **Search Console** verificado para el dominio y con datos (puede tardar 24-48 h la
      primera vez).
- [ ] Misma cuenta Google con acceso a GA4 + GSC + Looker.

---

## 1. Crear el informe y conectar fuentes

1. En Looker Studio → **Crear → Informe**.
2. **Añadir datos → Google Analytics** → elegir la propiedad GA4 → la cuenta/stream.
3. **Añadir datos → Search Console** → elegir el sitio. Te pide elegir tabla:
   - **"Site Impression"** → métricas a nivel sitio (impresiones, clics, CTR, posición).
   - **"URL Impression"** → lo mismo desglosado por query/página. **Usá esta** para la tabla
     de keywords.
   - Tip: agregá **las dos** tablas de GSC como fuentes separadas; cada módulo usa la que le
     sirve.
4. Quedan 3 fuentes en el informe: `GA4`, `GSC – Site Impression`, `GSC – URL Impression`.

---

## 2. Control de fechas (arriba, afecta a todo)

- Insertar → **Control de período**. Por defecto "Últimos 28 días".
- Insertar → **Lista desplegable** opcional para comparar períodos.

---

## 3. Módulo 1 — Embudo (scorecards)

Cuatro tarjetas de puntuación (Insertar → **Tarjeta de puntuación**):

| Tarjeta | Fuente | Métrica |
|---|---|---|
| **Impresiones** | GSC – URL Impression | `Impressions` |
| **Clics orgánicos** | GSC – URL Impression | `Url Clicks` |
| **Leads totales** | GA4 | `Conversions` (o `Event count` filtrado a los eventos de lead) |
| **Tasa de conversión** | GA4 | campo calculado (ver abajo) |

**Campo calculado "Tasa de conversión"** (en la fuente GA4 o como campo del informe):

```
Leads / Clics × 100
```

Como Leads (GA4) y Clics (GSC) viven en **fuentes distintas**, Looker no los divide directo en
una sola tarjeta. Dos opciones:
- **Simple (recomendada para el MVP):** mostrar la tasa como texto/anotación calculada
  manualmente, o usar **"Combinar datos" (blend)** uniendo GA4 + GSC por fecha y crear el
  campo `Leads / Url Clicks * 100`.
- **Blend:** Recurso → **Combinar datos** → GA4 (dimensión `Date`, métrica `Conversions`) +
  GSC (dimensión `Date`, métrica `Url Clicks`) con join por `Date`. Sobre el blend, campo
  calculado `SUM(Conversions) / SUM(Url Clicks) * 100`.

---

## 4. Módulo 2 — Ranking de keywords (tabla)

- Insertar → **Tabla**. Fuente: **GSC – URL Impression**.
- **Dimensión:** `Query`.
- **Métricas:** `Url Clicks`, `Impressions`, `Average Position` (y opcional `Site CTR`).
- **Orden:** por `Url Clicks` descendente.
- Filtro recomendado: excluir filas con `Query` vacío.
- Esto es lo que muestra al cliente cómo escala cada término ("fábrica de aberturas…",
  "asesoramiento impositivo…").

---

## 5. Módulo 3 — Origen de leads (tabla)

- Insertar → **Tabla**. Fuente: **GA4**.
- **Dimensión:** `Event name`.
- **Métrica:** `Event count` (o `Conversions`).
- **Filtro:** incluir solo los eventos de conversión →
  `Event name` en (`generate_lead`, `click_whatsapp`, `open_agenda`, `bot_lead_captured`,
  `click_phone`).
- Resultado: desglose de qué acción tomó el usuario (formulario / WhatsApp / agenda / bot /
  teléfono), que es la "Tabla 2" del plan.

> Para Infouno, la **verdad de leads** sigue siendo `wp_infouno_leads` (server-side); GA4 acá
> mide la **proporción por canal**, no el conteo oficial (pierde eventos sin consentimiento o
> con adblock).

---

## 6. Estética y branding

- Tema del informe con los colores de Infouno (acento azul acero `#3E9BE6`).
- Encabezado con logo + nombre del cliente + período.
- Una sola página vertical para el MVP; scroll natural embudo → keywords → leads.

---

## 7. Clonar por cliente (el playbook repetible)

1. **Archivo → Hacer una copia** del informe plantilla.
2. En el diálogo, **reemplazar las fuentes**: GA4 del cliente y GSC del cliente.
3. Revisar que los nombres de eventos de conversión coincidan (si el cliente usa otros, ajustar
   el filtro del Módulo 3).
4. Ajustar logo/nombre/colores.
5. **Compartir → acceso de solo lectura** con el email del cliente (nunca edición; nunca
   mezclar propiedades de distintos clientes en un mismo informe).
6. Definir con el cliente **qué cuenta como lead** en su rubro (médico → WhatsApp; fábrica →
   formulario) y destacar esa tarjeta.

---

## 8. Checklist de "dashboard listo"

- [ ] Las 3 fuentes conectan y traen datos del período elegido.
- [ ] Embudo con las 4 tarjetas (la tasa vía blend si se quiere el ratio exacto).
- [ ] Tabla de keywords ordenada por clics, sin filas vacías.
- [ ] Tabla de origen de leads filtrada a los eventos de conversión.
- [ ] Control de fechas funcionando sobre todos los módulos.
- [ ] Link de solo lectura compartido y probado en una ventana incógnito.
