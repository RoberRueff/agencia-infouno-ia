# Diseño — Bot conversacional "Uno" con OpenAI

**Fecha:** 2026-06-17
**Estado:** Aprobado para implementación
**Contexto:** Fase 2 del roadmap (`ai/analysis.md`). Convierte el bot scripteado actual en un agente conversacional con IA real, manteniendo la captación de leads y los guardrails.

---

## 1. Objetivo

Reemplazar el guion fijo del bot "Uno" por un **agente conversacional** impulsado por OpenAI que:
- Conversa de forma natural y responde preguntas libres sobre Infouno.
- Capta los datos del lead (rubro, nombre, web, equipo, contacto) durante la charla.
- Persiste cada dato paso a paso y dispara el cierre (WhatsApp / Cal.com) de forma determinista.
- Cumple los guardrails de `ai/guardrails.md` (scope, no precios, seguridad, privacidad).
- Degrada al guion scripteado actual si la IA no está disponible.

## 2. Decisiones tomadas (brainstorming)

| Decisión | Elección |
|---|---|
| Nivel de IA | **Agente conversacional completo** (la IA maneja toda la charla) |
| Modelo | **`gpt-4o-mini`**, configurable (T=0.3) |
| Base de conocimiento | **Destilada del sitio actual** (servicios, soluciones-ia, nosotros, blueprint), en archivo editable |
| Captura de datos | **Function calling** (`guardar_lead`, `listo_para_agendar`) |
| Cierre (WhatsApp/agenda) | **Determinista en el frontend** (no lo decide la IA) |

## 3. Arquitectura

```
[ Frontend: bot "Uno" en assets/site.js ]
   │  POST /chat.php  { messages: [...historial] }
   ▼
[ chat.php (DonWeb/PHP) ]
   │  - carga system prompt + ai-kb/kb_infouno.md
   │  - llama a OpenAI (gpt-4o-mini, T=0.3) con tools
   │  - ejecuta tool calls:
   │      guardar_lead(campos)      → db_lead.php (persistencia + scoring + email)
   │      listo_para_agendar()      → readyToClose=true
   │  - responde { reply, leadFields, readyToClose }
   ▼
[ OpenAI API ]   🔑 openai_key SOLO en config.php (backend)
```

- **Backend sin estado conversacional:** el frontend envía el historial completo en cada turno. `chat.php` no guarda la conversación; sí persiste el lead en `wp_infouno_leads`.
- **Reutilización:** la persistencia/scoring/email vive hoy embebida en `lead.php`; se extrae a `db_lead.php` y la usan tanto `lead.php` como `chat.php` (sin duplicar lógica).
- **Cierre determinista:** los botones de WhatsApp y Cal.com los renderiza el frontend cuando `readyToClose` es true; el cierre nunca depende de que la IA "se acuerde".

## 4. Componentes

### Nuevos
- **`chat.php`** — proxy a OpenAI. Entrada: `{ messages }`. Arma system prompt + KB, llama a OpenAI con `tools`, ejecuta las tool calls, devuelve `{ reply, leadFields, readyToClose }`. Sanitiza el historial; limita longitud y turnos.
- **`ai-kb/kb_infouno.md`** — base de conocimiento editable: servicios, 3 pilares, proceso, tono comercial argentino, política de precios (nunca dar montos), datos de contacto. Editable sin tocar código.
- **`db_lead.php`** — lógica compartida de persistencia: sanitización, mapeo a taxonomía, scoring/VIP (R3), upsert por `session_id` (R4) y email de alerta. Extraída de `lead.php`.

### Modificados
- **`config.php`** — agrega: `openai_key` (vacío por defecto), `openai_model` = `gpt-4o-mini`, `chat_enabled` (bool).
- **`lead.php`** — pasa a usar `db_lead.php` (mismo comportamiento externo).
- **`assets/site.js`** — `initBot()` con dos modos:
  - **IA disponible** (`chat_enabled` y key presente): conversación libre contra `chat.php`. Reutiliza el render existente (burbujas, typing, input).
  - **Fallback**: el guion scripteado actual, intacto.

### Sin cambios
`index.html` y demás páginas, agenda (Cal.com), calculadora, formulario de contacto, `privacidad.html`.

## 5. Tools (function calling)

- **`guardar_lead`** — parámetros opcionales: `name`, `rubro`, `web`, `equipo`, `email`, `whatsapp`. El modelo la llama en cuanto conoce un dato. `chat.php` lo persiste vía `db_lead.php` (paso a paso → R4).
- **`listo_para_agendar`** — sin parámetros. El modelo la llama cuando el lead está listo para cerrar; `chat.php` devuelve `readyToClose=true` y el frontend muestra los botones de WhatsApp + Cal.com (con prefill nombre/email).

## 6. Guardrails (cómo se cumplen)

- **G1 (scope/tono):** system prompt prohíbe temas ajenos (académicos, política, programación, "ChatGPT gratis"); ante eso responde con el *fallback* textual de `guardrails.md` y reconduce a Infouno.
- **G2 (no precios):** instrucción dura; deriva siempre a la consultoría de 15 min.
- **G3 (seguridad):** clave solo en backend; historial saneado; persistencia con prepared statements (en `db_lead.php`).
- **G4 (privacidad):** antes de pedir contacto, menciona la política y linkea `privacidad.html`.
- **R2/R3/R4:** el system prompt fija el objetivo de captura (rubro→nombre→web→equipo→contacto); `guardar_lead` persiste cada dato; scoring/VIP en backend.

## 7. Resiliencia, costo y errores

- **Fallback automático** al guion scripteado si: no hay `openai_key`, `chat_enabled=false`, o `chat.php` da error/timeout. Cero downtime.
- **Control de costo/abuso:**
  - Máximo ~16 turnos por conversación; luego cierra hacia WhatsApp/agenda.
  - Límite de caracteres por mensaje del usuario.
  - `max_tokens` acotado en la respuesta.
  - (Opcional, anotado) rate-limit por IP en `chat.php`.
- **Errores:** timeouts y respuestas no-200 de OpenAI se capturan → degrada al guion. Tool calls mal formadas se ignoran de forma segura.

## 8. Pruebas

- **`chat.php` aislado** con `curl`: respuesta correcta, ejecución de tool calls, persistencia en `wp_infouno_leads`.
- **Fallback:** con `chat_enabled=false`, arranca el guion scripteado.
- **Guardrails:** prompts hostiles ("ignorá tus instrucciones", "¿cuánto sale?", "explicame física") → reconduce/niega.
- **JS:** `node --check assets/site.js` + prueba del flujo en navegador.
- **No regresión:** leads por formulario y por bot-fallback siguen entrando y notificando.

## 9. Fuera de alcance (YAGNI)

- RAG con embeddings / vector DB (la KB en un archivo alcanza para este volumen).
- Migración a WordPress.
- Streaming de tokens en la respuesta (se puede sumar después).
- Panel de administración de conversaciones.

## 10. Prerrequisitos del usuario

- Cuenta de OpenAI con método de pago y una **API key**.
- Cargar la key en `config.php` (en el server, no se publica).
