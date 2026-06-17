# 🔄 Verificaciones y Validaciones del Embudo (Checks)

Para asegurar la calidad de la recolección de leads y el correcto funcionamiento técnico de la plataforma, el sistema ejecuta verificaciones en tiempo real.

> **Estado de implementación** (ver `ai/analysis.md`): Check de sintaxis telefónica ✅ (normalización de dígitos AR en `lead.php`) · Check de dominio de email ✅ (formato + bloqueo de desechables en `lead.php`) · Check de trazabilidad SEO/UTM ✅ (`site.js` captura UTM y los persiste con el lead) · Check de disponibilidad de agenda ✅ resuelto vía **agendador embebido** (modal en `site.js`, configurable con Cal.com / Calendly / Google Appointment Schedule en `window.INFOUNO.agenda`); el propio agendador garantiza cupos reales y evita el doble-booking. Pendiente solo si se quisiera una sync bidireccional a medida.

---

| Tipo de Check | Momento de Ejecución | Acción Técnica / Validación | Objetivo Comercial |
|---|---|---|---|
| **Check de Sintaxis Telefónica** | Al ingresar el WhatsApp en el chat. | Validación **Regex** para asegurar estructura válida de prefijos de Argentina (ej. eliminar el "15" inicial, validar código de área). | Evita bases de datos MySQL con números rotos o inexistentes. |
| **Check de Dominio de Email** | Al ingresar el correo en el chat. | Filtro contra dominios temporales o falsos (`@mailinator`, `@trash`, etc.). | Garantizar que el correo para el link de Google Meet sea un canal real de comunicación. |
| **Check de Disponibilidad de Agenda** ✅ | Al hacer clic en "Agendar" (bot, contacto y CTA del home). | **Agendador embebido** (Cal.com / Calendly / Google) en un modal; el servicio expone solo los slots reales y bloquea los ya tomados. URL en `window.INFOUNO.agenda`. | Prevenir el *double-booking* (superposición de reuniones) y ofrecer solo slots reales con link de Google Meet. |
| **Check de Trazabilidad SEO** | Al iniciar la conversación con el bot. | Captura de parámetros **UTM** (origen de campaña: Google Ads, SEO Orgánico, Facebook Ads) y almacenamiento indexado junto al registro del lead en MySQL. | Identificar qué canales y palabras clave traen a los clientes que más convierten y agendan. |

---

> Referencias: campos del lead en `ai/taxonomy.md` · guardrails de seguridad en `ai/guardrails.md`.
