# Execution Template — Esqueleto de Trabajo Obligatorio

> Este archivo define el esqueleto de trabajo para **toda la sesión**. Se lee y se sigue antes de arrancar cualquier tarea. El orden es: este `execution.md` primero (define el esqueleto), luego `context-loader.md` dentro del paso de contexto.

---

## 0. Preámbulo

- **Proyecto:** Infouno — Agencia IA (sitio web + chatbot conversacional con IA).
- **Stack:** WordPress (Core v6+) + Elementor · OpenAI API (GPT-4o, T=0.3) · MySQL (`wp_infouno_leads`) · Make/Node.js (orquestación).
- **Idioma de trabajo:** Español.
- **Referencia de arquitectura:** ver `ai/architecture.md`.

---

## 1. Fases de Ejecución

### Fase 1 — Contexto
- [ ] Ejecutar el protocolo completo de `ai/context-loader.md` en orden.
- [ ] Confirmar que se entiende el objetivo de la tarea antes de tocar código.
- [ ] Identificar archivos y capas afectadas (Frontend / Cognitiva / Datos).

### Fase 2 — Planificación
- [ ] Descomponer la tarea en pasos concretos y verificables.
- [ ] Señalar riesgos (SEO, rendimiento/Core Web Vitals, seguridad de webhooks).
- [ ] Definir el criterio de "hecho" (Definition of Done).

### Fase 3 — Implementación
- [ ] Cambios mínimos y enfocados; respetar el estilo del código existente.
- [ ] No penalizar LCP (< 2.5s) ni el rastreo de Google Bot (scripts asíncronos).
- [ ] Mantener `T = 0.3` y enfoque comercial directo en prompts de IA.

### Fase 4 — Verificación
- [ ] Probar el comportamiento real, no solo asumir que funciona.
- [ ] Validar que no se rompe SEO, rendimiento ni la trazabilidad de leads.
- [ ] Reportar resultados con evidencia (qué se probó y qué salió).

### Fase 5 — Cierre
- [ ] Resumir qué cambió y por qué.
- [ ] Anotar deuda técnica o pendientes.
- [ ] No hacer commit/push salvo que el usuario lo pida.

---

## 2. Reglas Transversales

1. **Evidencia antes que afirmaciones.** No declarar "funciona" sin haberlo verificado.
2. **SEO y rendimiento primero.** Toda inyección de scripts es asíncrona y no bloqueante.
3. **Seguridad.** Webhooks siempre por HTTPS POST; nunca exponer claves de OpenAI ni credenciales de MySQL en el frontend.
4. **Trazabilidad.** Toda interacción relevante del lead se persiste en `wp_infouno_leads`.
5. **Cambios reversibles.** Antes de borrar o sobrescribir, revisar el destino.

---

## 3. Checklist Rápido (copiar como TODOs al inicio de cada tarea)

- [ ] Leí `execution.md` y ejecuté `context-loader.md`.
- [ ] Entendí el objetivo y la Definition of Done.
- [ ] Plan claro y por pasos.
- [ ] Implementación enfocada.
- [ ] Verificación con evidencia.
- [ ] Cierre y resumen.
