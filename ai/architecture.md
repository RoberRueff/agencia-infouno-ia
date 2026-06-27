# Arquitectura Técnica del Sistema (Architecture)

La arquitectura sigue un enfoque **desacoplado e híbrido** (*Hybrid AI-First Architecture*). Aprovecha la madurez y el rendimiento para indexación SEO de WordPress junto con el procesamiento cognitivo de modelos LLM.

> ⚠️ **Estado actual vs objetivo:** este documento describe la **arquitectura objetivo (target)**. El repositorio implementa hoy un **frontend HTML estático** (8 páginas + `assets/site.js`) con una **capa backend en PHP** (DonWeb/cPanel): persistencia en MySQL (`wp_infouno_leads`) y capa cognitiva (LLM compatible con OpenAI — OpenAI/Gemini según `api_base`) vía `chat.php`. Lo que falta del objetivo es **WordPress/Elementor** y la **orquestación** (Make/Node.js). Ver la brecha completa y el roadmap en [`ai/analysis.md`](analysis.md).
>
> **Actualización (capa cognitiva):** el bot "Uno" ya tiene modo IA real vía `chat.php` con function calling para captar leads, y degrada al guion scripteado si la IA no está disponible. Usa la API Chat Completions **compatible con OpenAI**; el proveedor se elige con `api_base` en `config.php`: OpenAI (`gpt-4o-mini`) o Gemini (`gemini-2.5-flash`), T=0.3. La clave vive en `config.php` (backend).

## Diagrama de Capas

```text
[ FRONTEND ARCHITECTURE: WordPress + Elementor ] ──(Renderizado SSR / Foco SEO)
                       │
          🚀 Eventos de Navegación / API REST
                       │
                       ▼
[ EDGE LAYER / AGENT: Voiceflow / Typebot Script ]
                       │
       🔐 Webhooks Seguros (HTTPS POST)
                       │
                       ▼
┌──────────────────────┴────────────────────────────────────────┐
│ MIDDLEWARE & CAPA DE ORQUESTACIÓN (Make / Node.js)             │
└──────────────┬──────────────────────────────┬─────────────────┘
               │                              │
               ▼                              ▼
   [ COGNITIVE ENGINE ]             [ DATA PERSISTENCE ]
     - OpenAI API (GPT)               - MySQL DB (Tablas de Leads)
     - RAG (Contexto Local)           - CRM / Google Calendar
```

## Descripción de Capas

### Capa de Presentación (Frontend)

WordPress (Core v6+) optimizado para **Core Web Vitals** (LCP < 2.5s). Los scripts del chatbot se inyectan de forma asíncrona para no penalizar el rendimiento ni el rastreo de Google Bot.

### Capa Cognitiva (IA Engine)

Procesamiento de lenguaje a través de una **API compatible con OpenAI Chat Completions** con *function calling*, temperatura estricta (*T = 0.3*) para mitigar alucinaciones y enfoque comercial directo. El **endpoint es configurable** en `config.php` (`api_base`), así que el mismo `chat.php` corre indistintamente sobre **OpenAI** (`gpt-4o-mini`/`gpt-4o`) o **Google Gemini** (`gemini-2.5-flash`, vía su endpoint compatible con OpenAI) cambiando solo la config.

> ⚠️ **Privacidad (Ley 25.326):** el bot envía PII del lead al LLM. Los tiers gratuitos (p. ej. Gemini free) pueden usar los datos para entrenar; para producción conviene un tier pago (OpenAI API o Gemini con billing), que no entrena con los datos.

### Capa de Datos (Persistencia)

Base de datos **MySQL** relacional nativa de WordPress, extendida con tablas personalizadas (`wp_infouno_leads`). Almacena la trazabilidad de las interacciones, metadatos del lead y logs conversacionales para auditoría y analítica predictiva.
