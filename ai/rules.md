# Reglas de Negocio del Sistema (Rules)

Las reglas operativas controlan cómo reacciona la plataforma y la IA según las acciones del microempresario o profesional.

> **Estado de implementación** (ver detalle en `ai/analysis.md`): R1 ✅ completa (timer 5s + exit-intent por `mouseleave`, una vez por sesión) · R2 ✅ cumplida (el bot pide rubro + nombre **antes** de cualquier ejemplo/solución personalizada) · R3 ✅ implementada de forma estricta: el bot capta tres tramos de equipo (solo / chico 2-5 / grande +5) y `lead.php` marca **VIP solo si** hay equipo grande (+5) **y** web previa, con scoring + email de alerta (la "alerta por webhook" se resuelve hoy con email) · R4 ✅ implementada (`site.js` guarda paso a paso vía `fetch('/lead.php')` con upsert por `session_id`).

---

## R1 — Regla de Activación Proactiva (Gancho)

El script del agente de IA debe dispararse de forma **activa** ante cualquiera de estos eventos:

- A los **5 segundos exactos** de inactividad de scroll del usuario, o
- Al detectar intención de salida (**Exit-intent trigger**).

## R2 — Regla de Captura Temprana

El bot está **obligado a solicitar el nombre y el rubro del negocio antes** de pasar a dar cualquier ejemplo o solución técnica personalizada. Asegura datos mínimos de valor en el primer minuto.

## R3 — Regla de Ruteo Automático (Lead Scoring)

Si el lead se clasifica como:

- `lead_size = team_large` (**+5 personas**), **y**
- `lead_infrastructure = has_web` (infraestructura web previa),

entonces el sistema lo cataloga inmediatamente en MySQL como **"Lead VIP"** y despacha una **alerta prioritaria por webhook** al equipo comercial humano.

## R4 — Regla de Persistencia Asíncrona

Cada respuesta del chat interactivo se guarda **paso a paso** mediante llamadas asíncronas de fondo (**Fetch API**). Si el usuario abandona la web en la última pregunta, sus respuestas previas ya quedan registradas para campañas de recuperación de leads fríos.

---

> Referencias: esquema de campos en `ai/taxonomy.md` · arquitectura en `ai/architecture.md`.
