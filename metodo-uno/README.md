# Método UNO® — Formulario de Diagnóstico Nivel 1

Proxy seguro en **Node.js + Express** para el formulario de diagnóstico IA de [Infouno](https://infouno.com.ar).

Evita exponer la API key de Anthropic en el frontend: el formulario HTML llama al proxy local (`/api/diagnostico`), que agrega la key desde una variable de entorno y reenvía la solicitud a la API de Anthropic.

---

## Estructura

```
metodo-uno/
├── server.js                    ← proxy Express
├── package.json
├── .env                         ← tu API key real (NO subir al repo)
├── .env.example                 ← plantilla pública
├── .gitignore
├── README.md
└── public/
    └── metodo-uno-nivel1.html   ← formulario HTML
```

---

## Instalación local

### 1. Clonar / descargar el proyecto

```bash
git clone https://github.com/roberrueff/agencia-infouno-ia.git
cd agencia-infouno-ia/metodo-uno
```

### 2. Instalar dependencias

```bash
npm install
```

### 3. Configurar variables de entorno

```bash
cp .env.example .env
```

Editá `.env` y pegá tu API key real:

```
ANTHROPIC_API_KEY=sk-ant-TU_KEY_REAL_AQUI
PORT=3000
```

> Obtenés tu key en [console.anthropic.com](https://console.anthropic.com)

### 4. Iniciar el servidor

```bash
npm start
```

Abrí el navegador en `http://localhost:3000` — verás el formulario.

---

## Despliegue en servidor Ubuntu con PM2

### Requisitos previos

```bash
# Node.js 18+
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt-get install -y nodejs

# PM2 (gestor de procesos)
sudo npm install -g pm2
```

### Pasos de deploy

```bash
# 1. Subir el proyecto al servidor (vía git o scp)
git clone https://github.com/roberrueff/agencia-infouno-ia.git
cd agencia-infouno-ia/metodo-uno

# 2. Instalar dependencias
npm install --omit=dev

# 3. Crear el .env en producción
cp .env.example .env
nano .env   # pegá tu ANTHROPIC_API_KEY real

# 4. Iniciar con PM2
pm2 start server.js --name metodo-uno

# 5. Guardar para que reinicie solo al reboot
pm2 save
pm2 startup   # seguí las instrucciones que imprime
```

### Comandos PM2 útiles

```bash
pm2 status            # ver estado del proceso
pm2 logs metodo-uno   # ver logs en tiempo real
pm2 restart metodo-uno
pm2 stop metodo-uno
```

### Configurar Nginx como reverse proxy (recomendado)

```nginx
server {
    listen 80;
    server_name tu-dominio.com;

    location / {
        proxy_pass         http://localhost:3000;
        proxy_http_version 1.1;
        proxy_set_header   Upgrade $http_upgrade;
        proxy_set_header   Connection 'upgrade';
        proxy_set_header   Host $host;
        proxy_cache_bypass $http_upgrade;
    }
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

---

## Seguridad

- La `ANTHROPIC_API_KEY` **nunca** sale del servidor
- El `.env` está en `.gitignore` — nunca se sube al repositorio
- El proxy valida que la key esté presente antes de reenviar el request
- Para producción: agregá rate-limiting con `express-rate-limit`

---

## Stack

- Node.js 18+
- Express 4
- dotenv
- cors
