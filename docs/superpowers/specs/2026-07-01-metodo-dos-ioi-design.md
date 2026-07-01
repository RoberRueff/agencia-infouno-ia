# Diseño — Diagnóstico Inteligente Método DOS® (Motor IOI®)

> Spec de diseño. Fecha: 2026-07-01. Estado: aprobado para pasar a plan de implementación.
> Fuente de verdad del comportamiento: al implementar, actualizar los docs `ai/` correspondientes en el mismo commit (protocolo del proyecto).

## 1. Objetivo

Construir el **Diagnóstico Inteligente Método DOS® — Nivel 2**: la evolución del Método UNO® (Nivel 1). Un wizard de 4 fases que captura el contexto operativo de una PyME y devuelve un **IOI® (Índice de Oportunidad Infouno)** en escala 0–100, un veredicto categorizado, el costo anual de la ineficiencia y los 3 puntos críticos de mejora.

**Prioridad declarada por el usuario:** el motor de scoring debe ser **modular y fácil de actualizar** — pesos, mapeo de respuestas y rangos aislados de la lógica.

## 2. Decisiones cerradas (no reabrir sin acuerdo)

| Decisión | Resultado | Razón |
|---|---|---|
| Stack | **PHP integrado**, patrón del Método UNO® | Corre en DonWeb/cPanel; reutiliza `config.php`, `db_lead.php`, `ratelimit.php`, tabla `wp_infouno_leads`; cero infra nueva; coherencia con UNO. Descartado Next.js/TS (exigiría Node + build + hosting aparte). |
| Lenguaje motor | **PHP `declare(strict_types=1)`** con type hints y return types | "100% tipado" pedido, sin salir del stack. |
| 4 fases (A/B/C/D) | Tomadas tal cual la propuesta | Ver §4. |
| 3 puntos críticos | **Detección determinística en el motor**; el LLM solo los redacta | Scoring 100% testeable y reproducible. |
| Costo de inacción | `horas_semana × 48 semanas × 7.000 ARS` (constantes configurables) | 48 semanas contempla vacaciones/feriados. |

## 3. Arquitectura de archivos (espejo de `metodo-uno/`)

```
metodo-dos/
├── README.md                       Cómo funciona, requisitos, cómo probar (espejo del README de UNO)
├── public/
│   ├── metodo-dos-nivel2.html      Wizard de 4 fases (JS inline) → POST a diagnostico2.php
│   └── diagnostico2.php            Endpoint: valida + honeypot + rate-limit + persiste + LLM
├── src/
│   └── Scoring/
│       ├── IOIEngine.php           Lógica PURA (sin I/O): scoring, fórmula, costo, veredicto, puntos críticos
│       └── ScoringConfig.php       TODO lo tuneable: pesos, valor/hora, semanas, rangos, mapeo respuesta→puntos
└── tests/
    └── IOIEngineTest.php           Aserciones en PHP plano: `php metodo-dos/tests/IOIEngineTest.php` (sin deps, sin build)
```

**Regla de modularidad (invariante de diseño):** `IOIEngine.php` **no conoce** HTTP, MySQL ni el LLM. Recibe estructuras de datos y devuelve estructuras de datos. Todo lo que el usuario querrá ajustar con el tiempo (pesos, valor/hora, rangos, puntos por respuesta) vive **únicamente** en `ScoringConfig.php`. Cambiar un peso o el catálogo de preguntas **no** toca la lógica del motor.

## 4. Las 4 fases del IOI®

Cada fase es un grupo de preguntas del wizard. IOI® alto = empresa madura para transformarse ya (índice de *oportunidad*).

| Fase | Peso | Mide | Dirección del score |
|---|---|---|---|
| **A — Dolor / Ineficiencia** | 0.35 | Magnitud del problema operativo: horas semanales perdidas, tareas repetitivas, errores/reprocesos. | Más dolor → más alto. Las horas perdidas alimentan además el costo de inacción. |
| **B — Volumen / Escala** | 0.30 | Tamaño de la operación: consultas/pedidos por período, equipo, clientes/mes. | Más volumen → más alto. |
| **C — Madurez digital (inversa)** | 0.20 | Qué tan poco automatizado está hoy (CRM, canales, procesos manuales). | Menos herramientas hoy → más alto (más margen de mejora). |
| **D — Intención / Capacidad de acción** | 0.15 | Urgencia declarada + rol decisor + disposición a agendar/invertir. | Más urgencia/decisión → más alto. |

El motor es agnóstico al catálogo exacto de preguntas: solo necesita, por fase, los puntos obtenidos y el máximo posible. El catálogo de preguntas y sus puntos vive en `ScoringConfig.php`.

## 5. El motor (`IOIEngine.php`)

```php
declare(strict_types=1);

// En ScoringConfig.php:
const PHASE_WEIGHTS   = ['A' => 0.35, 'B' => 0.30, 'C' => 0.20, 'D' => 0.15];
const HOURLY_RATE_ARS = 7000;   // valor/hora administrativo-operativo AR (configurable)
const WEEKS_PER_YEAR  = 48;     // contempla vacaciones/feriados (configurable)
```

Funciones (firmas objetivo):

- `calculatePhaseScore(array $responses): float`
  Normalización: `Σ puntos_obtenidos / Σ puntos_máximos × 100`. Garantiza 0–100 aunque las fases tengan distinta cantidad de preguntas. Si el máximo es 0, devuelve `0.0` (guarda contra división por cero).

- `computeIOI(array $phases): float`
  Recibe los 4 scores normalizados y aplica `A.norm*0.35 + B.norm*0.30 + C.norm*0.20 + D.norm*0.15`. Redondea a entero 0–100.

- `calculateLoss(float $hoursPerWeek): int`
  `hoursPerWeek × WEEKS_PER_YEAR × HOURLY_RATE_ARS` → costo anual en ARS.

- `resolveVerdict(float $ioi): array`
  Lookup por rangos **inclusivos** (tabla §6) → `['rango'=>..., 'titulo'=>..., 'mensaje'=>...]`.

- `detectCriticalPoints(array $items): array`
  Determinístico: recibe ítems `['label','score','max']` donde `score` = **estado operativo actual** (más alto = mejor). Ordena ascendente por `score/max` y devuelve las **3** `label` de peor estado. Salida: etiquetas que luego el LLM redacta en prosa. Es la única fuente de los "3 puntos críticos"; el LLM no los inventa.
  **Wiring (decidido en implementación):** los `items` se arman en el wizard solo desde las áreas de **deficiencia operativa** — fases **A** (dolor), **B** (cuellos de botella) y **C** (madurez) —, **excluyendo D** (Intención) porque describe al comprador, no la operación. El wizard convierte sus puntos de *oportunidad* (más puntos = peor estado) a *estado actual* con `score = max − puntos`, de modo que el peor estado real (p. ej. "sin CRM", "muchos errores/reprocesos") quede como punto crítico. El motor queda intacto (siempre elige el peor `score/max`).

## 6. Veredicto — rangos inclusivos (en `ScoringConfig.php`)

| IOI® | Rango | Sentido |
|---|---|---|
| 90–100 | **Transformación Inmediata** | Oportunidad máxima, actuar ya. |
| 75–89 | **Alto Potencial** | Fuerte encaje. |
| 60–74 | **Potencial Progresivo** | Buen encaje por etapas. |
| 40–59 | **Ordenamiento Estructural** | Primero ordenar procesos. |
| 0–39 | **Baja Prioridad** | Poco margen actual. |

Implementación como lista ordenada de rangos con lookup, no `if` anidados, para que agregar/mover un rango sea un solo cambio de datos. Rangos contiguos e inclusivos sin huecos ni solapes (validado en tests).

## 7. JSON de salida

```json
{
  "ioi_final": 80,
  "veredicto": { "rango": "Alto Potencial", "titulo": "...", "mensaje": "..." },
  "costo_inaccion": { "horas_semanales": 12, "anual_ars": 4032000 },
  "puntos_criticos": ["...", "...", "..."],
  "fases": { "A": 88, "B": 75, "C": 90, "D": 60 }
}
```

- `puntos_criticos`: los 3 detectados por `detectCriticalPoints` — **labels del catálogo** (strings del código, inmunes a inyección). El LLM **los desarrolla como próximos pasos accionables dentro del `narrativo`**, no reescribe el array. El wizard pinta las labels como bullets y el `narrativo` como prosa.
- `fases`: scores normalizados por fase (transparencia/depuración y para el narrativo del LLM).

## 8. Flujo del endpoint (`diagnostico2.php`)

Reutiliza la infraestructura existente, igual que `metodo-uno/public/diagnostico.php`:

1. **Valida + honeypot + rate-limit** — reusa `ratelimit.php` con un bucket propio `diagnostico2` (separado de `chat`, `lead`, `diagnostico`).
2. **Calcula IOI®** con `IOIEngine` — determinístico, **sin** depender del LLM.
3. **Persiste el lead ANTES del LLM** vía `db_lead.php` con `source='metodo-dos'` (la columna `lead_source` admite 20 chars). El desglose IOI® (puntaje, veredicto, costo, fases) se guarda en `lead_message` (mismo patrón que UNO, que guarda ahí su resumen cualitativo). El equipo recibe el lead + aviso por email **aunque el LLM falle**.
4. **Llama al LLM** (reusa `config.php`: `api_base`/`openai_key`/`openai_model`, T=0.3) para redactar el narrativo del veredicto y los 3 puntos críticos **sobre los números ya calculados** — el LLM redacta, no calcula ni puntúa.
5. **Devuelve el JSON** (§7) al wizard, que lo renderiza.

Persistencia — nota de implementación: el IOI® numérico se guarda dentro de `lead_message` (texto). **No** se reutiliza `lead_scoring` (tiene semántica propia de VIP con `GREATEST()` y lo contaminaría). Si más adelante se quiere IOI® como columna consultable, se agrega una columna dedicada a `wp_infouno_leads` en un cambio aparte de schema.

## 9. Testing (`IOIEngineTest.php`)

Sin framework ni build (coherente con "no npm, no build" del proyecto): script PHP con aserciones, corrible con `php metodo-dos/tests/IOIEngineTest.php`. Casos mínimos:

- `calculatePhaseScore`: normalización correcta con distinta cantidad de preguntas; máximo 0 → 0.0.
- `computeIOI`: caso conocido (A=88,B=75,C=90,D=60 → 80.3 → 80) y bordes 0 y 100.
- `calculateLoss`: `12h × 48 × 7000 = 4.032.000`.
- `resolveVerdict`: los 5 rangos, incluyendo los bordes exactos (90, 89, 75, 74, 60, 59, 40, 39, 0, 100) — sin huecos ni solapes.
- `detectCriticalPoints`: devuelve exactamente 3 y son las de mayor brecha.

El motor es lógica pura, así que se testea sin MySQL, sin HTTP y sin LLM.

## 10. Alcance / YAGNI

**Incluye:** los 3 pilares (normalización, costo de inacción, veredicto) + motor modular + wizard + endpoint + persistencia + narrativo LLM + tests del motor.

**Fuera de alcance (por ahora):** columna IOI® dedicada en el schema; orquestación Make/n8n; RAG; migración a WordPress. Se anotan como deuda futura, no se implementan.

## 11. Restricciones del proyecto que respeta

- **SEO/rendimiento:** wizard estático con JS inline no bloqueante; el endpoint es backend.
- **Seguridad:** API key y credenciales solo en backend (`config.php`); prepared statements vía `db_lead.php`; honeypot + rate-limit; escape en el render del wizard.
- **Trazabilidad:** todo diagnóstico persiste como lead (`source=metodo-dos`) con UTM.
- **Privacidad (Ley 25.326):** consentimiento visible antes de capturar datos, igual que el resto del sitio.
- **Idioma:** español; tono comercial directo (voseo), T=0.3.
```
