# Método DOS® — Diagnóstico Inteligente Nivel 2 (IOI®)

Wizard de diagnóstico (4 fases) que calcula el **IOI® — Índice de Oportunidad
Infouno** (0–100) de una PyME, estima el **costo anual de la ineficiencia** y
detecta los **3 puntos críticos de mejora**. Devuelve un veredicto categorizado
y un narrativo generado por IA.

> **Integrado al stack del sitio (PHP).** No requiere Node. Corre tal cual en
> DonWeb/cPanel junto al resto del sitio, igual que el Método UNO®.

## Cómo funciona

```
public/metodo-dos-nivel2.html   ──POST diagnostico2.php──►  public/diagnostico2.php
   (wizard 4 fases + JS inline)                                │
                                                               ├─ rate-limit + honeypot (../../ratelimit.php, bucket "diagnostico2")
                                                               ├─ 1) IOI® determinístico (src/Scoring/IOIEngine.php)
                                                               ├─ 2) guarda el lead en wp_infouno_leads (../../db_lead.php)
                                                               │        · source = "metodo-dos", con aviso por email
                                                               │        · el IOI® y el veredicto van en lead_message
                                                               └─ 3) pide el narrativo al LLM (../../config.php)
                                                                        · redacta SOBRE los números; no puntúa
```

- **El IOI® es 100 % determinístico.** Se calcula en `IOIEngine` antes de tocar
  el LLM. El modelo solo redacta el análisis; no cambia ni inventa el puntaje.
- **Persistencia real primero:** el lead se guarda **antes** de llamar al LLM.
  El equipo recibe el contacto aunque el modelo falle.
- **Misma configuración que el bot y el Método UNO:** API key y proveedor salen
  de `config.php` en la raíz (`api_base`, `openai_key`, `openai_model`).

## El motor IOI® (modular)

- **`src/Scoring/IOIEngine.php`** — lógica pura, sin HTTP/MySQL/LLM:
  - `calculatePhaseScore()` — normaliza cada fase a 0–100 (Σpuntos / Σmáx × 100).
  - `computeIOI()` — fórmula ponderada `A·0.35 + B·0.30 + C·0.20 + D·0.15`.
  - `calculateLoss()` — costo anual = horas/semana × 48 × valor/hora.
  - `resolveVerdict()` — veredicto por rango inclusivo.
  - `detectCriticalPoints()` — las 3 sub-áreas de peor estado operativo.
  - `diagnose()` — orquesta todo y ensambla el JSON de salida.
- **`src/Scoring/ScoringConfig.php`** — **todo lo tuneable en un solo lugar**:
  pesos de las fases, valor/hora (`HOURLY_RATE_ARS = 7000`), semanas
  (`WEEKS_PER_YEAR = 48`) y los rangos de veredicto. Cambiar un peso o un rango
  **no** requiere tocar el motor.

Las **4 fases**: **A** Dolor/Ineficiencia (0.35) · **B** Volumen/Escala (0.30) ·
**C** Madurez digital inversa (0.20) · **D** Intención/Acción (0.15).

Los **3 puntos críticos** salen de las áreas de deficiencia operativa (fases
A/B/C, se excluye D porque describe al comprador, no la operación), midiendo el
estado actual: peor estado → más crítico.

## Tests del motor

Sin framework ni build. Aserciones en PHP plano:

```bash
php metodo-dos/tests/IOIEngineTest.php
# → 28 corridos, 0 fallos
```

Si no tenés PHP local pero sí Docker:

```bash
docker run --rm -v "$(pwd)":/app -w /app php:8.2-cli php metodo-dos/tests/IOIEngineTest.php
```

El motor es lógica pura, así que se testea sin MySQL, sin HTTP y sin LLM.

## Requisitos

- Lo mismo que el resto del sitio: **PHP 8.0+ + MySQL** (DonWeb/cPanel) y un
  `config.php` completo en la raíz (ver `config.sample.php`).
- La tabla `wp_infouno_leads` (ver `db/schema.sql`).

No hay build, ni `npm install`, ni proceso aparte que mantener.

## Probar en local

```bash
# desde la raíz del proyecto, con un config.php válido
php -S localhost:8000
# y abrir http://localhost:8000/metodo-dos/public/metodo-dos-nivel2.html
```

Sin PHP, el formulario se muestra pero el envío del diagnóstico no responde.

## Archivos

| Archivo | Propósito |
|---|---|
| `public/metodo-dos-nivel2.html` | Wizard de 4 fases + JS inline. Postea a `diagnostico2.php`. |
| `public/diagnostico2.php` | Endpoint: rate-limit, IOI® determinístico, persiste el lead y pide el narrativo al LLM. |
| `src/Scoring/IOIEngine.php` | Motor IOI® (lógica pura, testeable). |
| `src/Scoring/ScoringConfig.php` | Configuración tuneable: pesos, valor/hora, semanas, rangos. |
| `tests/IOIEngineTest.php` | Suite de tests del motor (PHP plano, sin deps). |
