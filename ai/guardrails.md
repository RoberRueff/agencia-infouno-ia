# 🛡️ Barreras de Seguridad y Control (Guardrails)

Los *Guardrails* protegen a la inteligencia artificial de manipulación maliciosa (**Prompt Injections**), aseguran el cumplimiento legal de datos en Argentina y garantizan que mantenga su foco de venta comercial.

> **Estado de implementación** (ver `ai/analysis.md`): el bot actual es **scripteado, sin LLM**, por lo que G1 (scope/tono) no aplica todavía. G2 ✅ cumplido por diseño (el bot no menciona precios). G3 ✅ reforzado: `escapeHtml()` en el frontend mitiga XSS en el chat y `lead.php` usa *prepared statements* (mysqli) + sanitización del input antes de tocar MySQL, bloqueando SQL Injection. G4 ✅ implementado: página `privacidad.html` (Ley 25.326), nota de consentimiento en el bot (paso de captura) y bajo el formulario, y link "Privacidad" en el footer de todas las páginas. Estos guardrails serán obligatorios al incorporar la capa cognitiva (OpenAI).

---

## G1 — Guardrail de Tono y Alcance (System Scope)

La IA tiene **terminantemente prohibido** responder preguntas académicas, políticas, de programación o resolver problemas ajenos a los servicios de Infouno.

Si el usuario intenta usar el bot como un "ChatGPT gratuito", debe ejecutar el siguiente **fallback**:

> "Disculpame, como asistente de Infouno solo puedo asesorarte en automatizaciones para potenciar tu negocio. Contame, ¿tu empresa ya cuenta con sitio web?"

## G2 — Guardrail Presupuestario y Técnico

El bot **jamás dará estimaciones exactas de precios finales** de los proyectos en el chat. Directriz estricta:

- Indicar que los costos varían según el nivel de automatización.
- Los costos se definen **exclusivamente** en la llamada de **15 minutos en Google Meet**.

## G3 — Seguridad de Inyección de Código (MySQL Guard)

Todo input de texto que ingrese desde el chat hacia los webhooks pasa por un proceso de **sanitización estricta** en el backend de WordPress para bloquear:

- Ataques de **SQL Injection**.
- Scripts maliciosos de **XSS**,

antes de impactar las bases de datos.

## G4 — Protección de Datos Personales

El sitio y el chat incluirán **términos y condiciones visibles** en conformidad con la **Ley N° 25.326 de Protección de Datos Personales** (Argentina), informando que los datos son de uso exclusivo de Infouno para coordinar la consultoría comercial.

---

> Referencias: reglas operativas en `ai/rules.md` · arquitectura en `ai/architecture.md`.
