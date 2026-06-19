# Deploy Checklist — DonWeb / cPanel

Procedimiento a prueba de balas para desplegar el sitio (o **replicarlo a un cliente**) en
DonWeb. Nace de los errores reales del primer deploy: todos eran **silenciosos** (el sitio
"parecía" andar mientras el backend estaba caído). Seguir en orden.

> Fuente de verdad técnica: `ai/`. La config sensible vive solo en el server (`config.php`,
> **no versionado**). _Creado: 2026-06-18._

---

## 0. Qué se sube y qué NO

- **Se sube** (FTP/File Manager): los `.html`, `assets/`, `chat.php`, `lead.php`,
  `db_lead.php`, `robots.txt`, `sitemap.xml`, `ai-kb/`.
- **NO se sube desde el repo** (está en `.gitignore`): **`config.php`** → se crea a mano en el
  server (Paso 4). Es la causa #1 de backend caído tras un deploy.
- **No hace falta en producción:** `ai/`, `seo/`, `db/`, `sin-publicar/` (son docs/fuente).

---

## 1. Subir los archivos

1. cPanel → **Administrador de archivos** → carpeta raíz del dominio (normalmente `public_html`).
2. Subí todos los archivos públicos. Verificá que `index.html` y `chat.php` queden en la raíz.

---

## 2. Crear base de datos + usuario MySQL

1. cPanel → **Bases de datos MySQL**.
2. **Crear base**: nombre corto (ej. `infouno`) → queda prefijado: **`cXXXXXXX_infouno`**. Anotalo.
3. **Crear usuario**: nombre + **contraseña**. ⚠️ **Generala SOLO con letras y números** —
   evitá símbolos `$ \ " ' ` `. (Ver gotcha en Paso 4). Anotá usuario completo y contraseña.
4. **Agregar usuario a la base** → **TODOS LOS PRIVILEGIOS** → Guardar.

> De acá salen `db_name`, `db_user`, `db_pass`. `db_host` en DonWeb es `localhost`.

---

## 3. Crear la tabla de leads

1. cPanel → **phpMyAdmin** → seleccioná tu base → pestaña **SQL**.
2. Pegá y ejecutá el contenido de **`db/schema.sql`** (tabla `wp_infouno_leads`).
3. Confirmá que la tabla aparece a la izquierda.

---

## 4. Crear `config.php` en la raíz

1. File Manager → raíz (junto a `lead.php`) → **+ Archivo** → `config.php`.
2. Pegá la plantilla de `config.sample.php` y completá. **Ejemplo con Gemini:**

```php
<?php
return [
  'db_host'      => 'localhost',
  'db_name'      => 'cXXXXXXX_infouno',
  'db_user'      => 'cXXXXXXX_infouno',
  'db_pass'      => 'PassSoloLetrasYNumeros',
  'notify_email' => 'ventas@tudominio.com.ar',
  'from_email'   => 'no-reply@tudominio.com.ar',
  'api_base'     => 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions',
  'openai_model' => 'gemini-2.5-flash',
  'openai_key'   => 'AIza...tu_key',
  'chat_enabled' => true,
];
```

### ⚠️ Gotchas que ya nos rompieron el deploy
- **Comillas simples** en todos los valores. Si la `db_pass` tiene un `$` y la ponés entre
  comillas **dobles**, PHP la interpreta como variable → MySQL error **1045**. Por eso la pass
  va sin símbolos.
- **Sin espacios ni líneas antes de `<?php`** (rompe `chat.php`, que hace `require` antes de todo).
- **URL de Gemini exacta:** `generativelanguage` (inglés, con "g"), **no** `generativelanguaje`.
- **Modelo Gemini vigente:** `gemini-2.5-flash`. Los modelos se retiran (`gemini-2.0-flash` ya
  no existe). Si da 404 "model no longer available", listá los modelos disponibles:
  `GET https://generativelanguage.googleapis.com/v1beta/models?key=TU_KEY`.
- **OpenAI en vez de Gemini:** `api_base => 'https://api.openai.com/v1/chat/completions'` +
  `openai_model => 'gpt-4o-mini'` + key `sk-...`.

---

## 5. Verificación (no asumir — comprobar)

Desde una terminal, reemplazá el dominio:

```bash
D=https://tudominio.com.ar
curl -s $D/config.php -o /dev/null -w "config.php: %{http_code}\n"   # 200=ok · 500=sintaxis · 404=falta
curl -s $D/chat.php  -w "\n"                                          # {"ok":true,"enabled":true}
curl -s $D/lead.php  -o /dev/null -w "lead.php: %{http_code}\n"       # 405 = vivo (solo POST)
# Turno real del bot:
curl -s -X POST $D/chat.php -H 'Content-Type: application/json' \
  -d '{"session_id":"DEPLOY_TEST","messages":[{"role":"user","content":"Hola, soy Test, tengo un estudio contable, mi WhatsApp es 11 5555 0000"}]}'
```

Después, en **phpMyAdmin → `wp_infouno_leads`**: debe aparecer la fila `DEPLOY_TEST`.
**Borrá la fila de prueba** al terminar.

> Si algo falla y `chat.php` devuelve `{"ok":false,"error":"openai"}` (502) o no guarda, el
> código descarta el detalle. Subí un diagnóstico temporal que muestre el error crudo de la API
> o de MySQL (conexión, tabla, INSERT) y **borralo después**. No dejes scripts de diag en el server.

---

## 6. Tabla de errores típicos (troubleshooting)

| Síntoma | Causa | Fix |
|---|---|---|
| `chat.php` → 500, body vacío | Falta `config.php` o tiene error de sintaxis | Crear/corregir `config.php` (Paso 4) |
| `GET /config.php` → 500 | Sintaxis en `config.php` (comillas curvas, coma faltante) | Retipear comillas simples rectas |
| `chat.php` → `{"enabled":false}` | `openai_key` vacía o `chat_enabled:false` | Pegar la key del proveedor |
| `chat.php` POST → 502 `error:openai`, 404 "model no longer available" | Modelo retirado | Usar `gemini-2.5-flash` / listar modelos |
| `chat.php` POST → 502, 404 "URL not found" | Typo en `api_base` (`languaje`) | Corregir a `generativelanguage` |
| Bot responde pero **no guarda** lead | Conexión MySQL falla (error 1045) | Resetear pass del user, sin símbolos; igualar en `config.php` |
| 1045 con pass cargada | `$` en pass + comillas dobles | Pass sin símbolos + comillas simples |

---

## 7. Replicar para un cliente

Mismo procedimiento, cambiando por cliente:
1. Su base/usuario MySQL + tabla (Pasos 2-3).
2. Su `config.php` (sus credenciales, su `notify_email`, su key).
3. Su GA4 (`window.INFOUNO.ga4` en `assets/site.js`) y su agenda/WhatsApp.
4. Verificación del Paso 5 contra su dominio.
5. Dashboard de reporting: ver `seo/looker-guide.md`.
