# Arquitectura Técnica del Sistema (Architecture)

La arquitectura sigue un enfoque **desacoplado e híbrido** (*Hybrid AI-First Architecture*). Aprovecha la madurez y el rendimiento para indexación SEO de WordPress junto con el procesamiento cognitivo de modelos LLM.

> ⚠️ **Estado actual vs objetivo:** este documento describe la **arquitectura objetivo (target)**. El repositorio implementa hoy un **MVP frontend estático** (HTML + CSS + un único `assets/site.js`) cuyos leads se entregan por WhatsApp, sin WordPress, MySQL ni OpenAI. Ver la brecha completa y el roadmap en [`ai/analysis.md`](analysis.md).
>
> **Actualización (capa cognitiva):** el bot "Uno" ya tiene modo IA real vía `chat.php` (OpenAI `gpt-4o-mini`, T=0.3) con function calling para captar leads, y degrada al guion scripteado si la IA no está disponible. La clave vive en `config.php` (backend).

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

Procesamiento de lenguaje a través de la **API de OpenAI** (Modelos GPT-4o o superiores) con una temperatura estricta (*T = 0.3*) para mitigar alucinaciones y garantizar un enfoque comercial directo.

### Capa de Datos (Persistencia)

Base de datos **MySQL** relacional nativa de WordPress, extendida con tablas personalizadas (`wp_infouno_leads`). Almacena la trazabilidad de las interacciones, metadatos del lead y logs conversacionales para auditoría y analítica predictiva.
