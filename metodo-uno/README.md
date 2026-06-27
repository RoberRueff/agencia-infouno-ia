# Método UNO® — Diagnóstico Nivel 1

Formulario de diagnóstico (wizard de 4 pasos) que captura el contexto comercial
de una PyME y devuelve un **Resumen Ejecutivo de Diagnóstico** generado por IA.

> **Integrado al stack del sitio (PHP).** No requiere Node. Corre tal cual en
> DonWeb/cPanel junto al resto del sitio. La versión anterior con servidor Express
> (`server.js`) fue reemplazada por `public/diagnostico.php`.

## Cómo funciona

```
public/metodo-uno-nivel1.html   ──POST diagnostico.php──►  public/diagnostico.php
   (wizard + JS inline)                                       │
                                                              ├─ valida + honeypot + rate-limit (../../ratelimit.php)
                                                              ├─ guarda el lead en wp_infouno_leads (../../db_lead.php)
                                                              │     · source = "metodo-uno", con aviso por email
                                                              └─ pide el diagnóstico al LLM (../../config.php)
                                                                    · API compatible con OpenAI (api_base/openai_key)
                                                                    · OpenAI gpt-4o-mini / Gemini gemini-2.5-flash
```

- **Misma configuración que el bot:** la API key y el proveedor salen de
  `config.php` en la raíz (`api_base`, `openai_key`, `openai_model`). No hay un
  `.env` propio ni una segunda key.
- **Persistencia real:** cada envío crea/actualiza un lead (`source = metodo-uno`)
  con el resumen cualitativo en `lead_message`, y dispara el email de aviso de
  `db_lead.php`. El equipo recibe el contacto aunque el LLM falle (el lead se
  guarda **antes** de llamar al modelo).
- **Anti-abuso:** honeypot (`website`) + rate-limit por IP/global (bucket
  `diagnostico`, separado del chat y los leads).

## Requisitos

- Lo mismo que el resto del sitio: **PHP + MySQL** (DonWeb/cPanel) y un
  `config.php` completo en la raíz (ver `config.sample.php`).
- La tabla `wp_infouno_leads` (ver `db/schema.sql`).

No hay build, ni `npm install`, ni proceso aparte que mantener.

## Probar en local

Necesita PHP (el wizard se ve como estático, pero el envío llama a
`diagnostico.php`):

```bash
# desde la raíz del proyecto, con un config.php válido
php -S localhost:8000
# y abrir http://localhost:8000/metodo-uno/public/metodo-uno-nivel1.html
```

Sin PHP, el formulario se muestra pero el envío del diagnóstico no responde.

## Archivos

| Archivo | Propósito |
|---|---|
| `public/metodo-uno-nivel1.html` | Wizard de 4 pasos + JS inline. Postea a `diagnostico.php`. |
| `public/diagnostico.php` | Endpoint: valida, persiste el lead y proxea al LLM. |
