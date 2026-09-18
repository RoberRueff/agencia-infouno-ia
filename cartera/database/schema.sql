-- =========================================================
-- Esquema MySQL — Marketing as a Service para PyMEs (Donweb)
-- Compatible con MySQL 5.7+/8.0 (soporte de columnas generadas)
-- Importar vía phpMyAdmin de cPanel o: mysql -u USER -p DB < schema.sql
-- =========================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------
-- Tabla: clientes
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS clientes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre_empresa VARCHAR(255) NOT NULL,
  rubro_industrial ENUM('Metalúrgica','Agro','Autopartes','Construcción','Textil','Alimenticia','Otro') NOT NULL DEFAULT 'Otro',
  contacto_whatsapp_vendedor VARCHAR(30) NOT NULL,
  email_dueno VARCHAR(255) NOT NULL,
  domicilio VARCHAR(255) DEFAULT NULL COMMENT 'Domicilio del responsable (Ley 25.326, art. 6.b). Se muestra en privacidad.php si está cargado.',
  cpl_maximo_permitido DECIMAL(10,2) NOT NULL DEFAULT 0,
  sla_horas_objetivo SMALLINT UNSIGNED NOT NULL DEFAULT 24 COMMENT 'Objetivo de SLA de primera respuesta al lead, en horas (feature SLA)',
  tema ENUM('auto','claro','oscuro') NOT NULL DEFAULT 'auto' COMMENT 'Preferencia de tema visual del dashboard, elegida por el cliente',
  estado_cuenta ENUM('activo','pausado','onboarding','cancelado') NOT NULL DEFAULT 'onboarding',
  google_ads_customer_id VARCHAR(50) DEFAULT NULL,
  meta_ad_account_id VARCHAR(50) DEFAULT NULL,
  fecha_alta DATE NOT NULL,
  password_hash VARCHAR(255) NOT NULL COMMENT 'Login del dashboard, generado con password_hash()',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_email (email_dueno),
  KEY idx_estado (estado_cuenta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Tabla: objetivos_comerciales
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS objetivos_comerciales (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  mes_anio DATE NOT NULL COMMENT 'Se guarda siempre como día 1 del mes, ej 2026-07-01',
  presupuesto_asignado DECIMAL(12,2) NOT NULL DEFAULT 0,
  meta_leads INT UNSIGNED NOT NULL DEFAULT 0,
  meta_ventas DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cliente_mes (cliente_id, mes_anio),
  CONSTRAINT fk_objetivos_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Tabla: metricas_diarias
-- Una fila por cliente por día. Los totales, CPL y ROAS son
-- columnas generadas (STORED) para no recalcular en cada query.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS metricas_diarias (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  inversion_google DECIMAL(10,2) NOT NULL DEFAULT 0,
  inversion_meta DECIMAL(10,2) NOT NULL DEFAULT 0,
  leads_google INT UNSIGNED NOT NULL DEFAULT 0,
  leads_meta INT UNSIGNED NOT NULL DEFAULT 0,
  ventas_cant INT UNSIGNED NOT NULL DEFAULT 0,
  monto_venta DECIMAL(12,2) NOT NULL DEFAULT 0,
  inversion_total DECIMAL(10,2) GENERATED ALWAYS AS (inversion_google + inversion_meta) STORED,
  leads_totales INT GENERATED ALWAYS AS (leads_google + leads_meta) STORED,
  cpl_dia DECIMAL(10,2) GENERATED ALWAYS AS (
    IF(leads_totales = 0, 0, inversion_total / leads_totales)
  ) STORED,
  roas_dia DECIMAL(10,2) GENERATED ALWAYS AS (
    IF(inversion_total = 0, 0, monto_venta / inversion_total)
  ) STORED,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cliente_fecha (cliente_id, fecha),
  CONSTRAINT fk_metricas_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Tabla: leads
-- Un lead individual por fila. Necesaria para el seguimiento de WhatsApp a
-- las 48hs y para no reenviar el mismo mensaje dos veces.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS leads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  nombre_lead VARCHAR(255) NOT NULL,
  telefono_lead VARCHAR(30) NOT NULL,
  telefono_normalizado VARCHAR(8)
    GENERATED ALWAYS AS (
      RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        telefono_lead, ' ', ''), '+', ''), '-', ''), '(', ''), ')', ''), '.', ''), '/', ''), 8)
    ) STORED
    COMMENT 'Últimos 8 dígitos de telefono_lead sin separadores; clave de dedup. REPLACE anidado (no REGEXP_REPLACE) por compatibilidad con MySQL 5.7. Ver includes/helpers.php::registrar_lead()',
  fuente ENUM('google','meta') NOT NULL,
  fecha_ingreso DATETIME NOT NULL,
  mensaje_seguimiento_enviado TINYINT(1) NOT NULL DEFAULT 0,
  fecha_seguimiento_enviado DATETIME DEFAULT NULL,
  respuesta_texto VARCHAR(500) DEFAULT NULL,
  monto_venta DECIMAL(12,2) DEFAULT NULL,
  estado ENUM('pendiente','contactado','en_negociacion','vendido','no_vendido') NOT NULL DEFAULT 'pendiente',
  motivo_perdida VARCHAR(255) DEFAULT NULL COMMENT 'Motivo cuando estado=no_vendido; lo setea el dueño desde el dashboard (ver includes/leads_lifecycle.php)',
  fecha_primer_contacto DATETIME DEFAULT NULL COMMENT 'Primer toque humano del dueño sobre el lead (feature SLA). NULL = nunca atendido por el humano; el flip automático a contactado del cron/02 no lo setea.',
  consentimiento_datos TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Ley 25.326 — si el titular aceptó la política de privacidad al dejar sus datos (siempre 1 en integraciones server-to-server ya consentidas en origen; landing.php lo exige explícitamente)',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cliente (cliente_id),
  KEY idx_seguimiento_pendiente (mensaje_seguimiento_enviado, fecha_ingreso),
  KEY idx_telefono (telefono_lead),
  KEY idx_dedup_telefono (cliente_id, telefono_normalizado, estado),
  CONSTRAINT fk_leads_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Tabla: alertas_enviadas
-- Evita reenviar la misma alerta (CPL alto 2 días seguidos)
-- más de una vez por cliente/día/tipo.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS alertas_enviadas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  fecha DATE NOT NULL,
  tipo VARCHAR(50) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_alerta (cliente_id, fecha, tipo),
  CONSTRAINT fk_alertas_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Tabla: avisos_agencia_enviados
-- Dedup del digest diario de avisos de cartera (cron/05_avisos_cartera.php).
-- A nivel día: el aviso es de cartera (no de un cliente puntual).
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS avisos_agencia_enviados (
  fecha DATE NOT NULL PRIMARY KEY,
  cantidad_avisos INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Tabla: crons_latidos
-- Heartbeat de los cron jobs. Cada cron registra su último latido al arrancar
-- (includes/crons_heartbeat.php); el panel de cartera y el digest de agencia
-- detectan un cron caído. ultimo_latido es DATETIME (no TIMESTAMP), igual que
-- intentos_login: se maneja con el reloj de PHP, nunca con NOW() de MySQL.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS crons_latidos (
  cron_nombre   VARCHAR(50) NOT NULL PRIMARY KEY,
  ultimo_latido DATETIME NOT NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Tabla: intentos_login
-- Bloqueo temporal contra fuerza bruta en login.php y admin/login.php.
-- "identificador" es "cliente:<email>" o "admin:<ip>" — ver includes/auth.php.
--
-- OJO: estas 2 columnas son DATETIME a propósito, no TIMESTAMP. TIMESTAMP en
-- MySQL se convierte según el time_zone de la sesión al guardar/leer; como
-- includes/auth.php arma y compara estos valores íntegramente con funciones
-- de PHP (date()/time()/strtotime(), con date_default_timezone_set() a
-- America/Argentina/Buenos_Aires) y nunca con NOW() de MySQL, usar DATETIME
-- evita que MySQL reinterprete la hora con su propio huso horario (que en
-- muchos hostings/containers por defecto es UTC) y descuadre el cálculo.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS intentos_login (
  identificador VARCHAR(255) NOT NULL PRIMARY KEY,
  intentos INT UNSIGNED NOT NULL DEFAULT 0,
  ultimo_intento DATETIME NOT NULL,
  bloqueado_hasta DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Tabla: solicitudes_reset
-- Pedidos de "olvidé mi contraseña". El reset es mediado por admin: esta tabla
-- solo registra la solicitud (sin revelar si el email existe), y el admin
-- regenera la contraseña desde public/admin/ con el formulario de cliente.
-- No se guarda ningún token ni contraseña acá.
-- FK con ON DELETE CASCADE (consistente con el resto): si se borra el cliente,
-- se borran sus solicitudes.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS solicitudes_reset (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  email_solicitado VARCHAR(255) NOT NULL,
  ip_solicitante VARCHAR(45) DEFAULT NULL,
  estado ENUM('pendiente','resuelta') NOT NULL DEFAULT 'pendiente',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resuelta_at DATETIME DEFAULT NULL,
  KEY idx_estado (estado),
  KEY idx_cliente (cliente_id),
  CONSTRAINT fk_solicitudes_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Tabla: mensajes_procesados
-- Anti-replay del webhook de WhatsApp: guarda el id de cada mensaje entrante
-- ya procesado (message.id de Meta) para no volver a registrar la misma venta
-- si un POST firmado se reenvía. Ver public/webhook_whatsapp.php.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS mensajes_procesados (
  message_id VARCHAR(255) NOT NULL PRIMARY KEY,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Tabla: usuarios_admin
-- Usuarios nombrados del panel admin (identidad + rol). Reemplaza la credencial
-- única de config, que queda como break-glass. Rol en ASCII ('dueno') porque se
-- compara en el gate de autorización (ver includes/admin_auth.php).
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios_admin (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre        VARCHAR(120) NOT NULL,
  email         VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  rol           ENUM('dueno','operador') NOT NULL DEFAULT 'operador',
  activo        TINYINT(1) NOT NULL DEFAULT 1,
  totp_secret   VARCHAR(64) DEFAULT NULL,
  tema          ENUM('auto','claro','oscuro') NOT NULL DEFAULT 'auto' COMMENT 'Preferencia de tema visual del panel admin, elegida por el usuario',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_usuario_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Tabla: auditoria
-- Bitácora append-only de acciones del panel admin (ver migración 012).
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS auditoria (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_admin_id INT UNSIGNED DEFAULT NULL,
  actor_nombre     VARCHAR(120)  NOT NULL,
  actor_rol        VARCHAR(20)   NOT NULL,
  es_break_glass   TINYINT(1)    NOT NULL DEFAULT 0,
  accion           VARCHAR(60)   NOT NULL,
  entidad          VARCHAR(40)   NOT NULL,
  entidad_id       INT UNSIGNED DEFAULT NULL,
  detalle          VARCHAR(1000) NOT NULL DEFAULT '',
  ip               VARCHAR(45)   DEFAULT NULL,
  creado_en        DATETIME      NOT NULL,
  KEY idx_creado (creado_en),
  KEY idx_entidad (entidad, entidad_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------
-- Tabla: cola_whatsapp
-- Cola durable de envíos de WhatsApp con reintentos/backoff (ver migración 013).
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS cola_whatsapp (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave_idempotencia VARCHAR(150) NOT NULL,
  destinatario       VARCHAR(30)  NOT NULL,
  plantilla          VARCHAR(60)  NOT NULL,
  parametros         JSON         NOT NULL,
  estado             ENUM('pendiente','enviado','fallido') NOT NULL DEFAULT 'pendiente',
  intentos           TINYINT UNSIGNED NOT NULL DEFAULT 0,
  proximo_intento    DATETIME     NOT NULL,
  ultimo_error       VARCHAR(255) DEFAULT NULL,
  creado_en          DATETIME     NOT NULL,
  actualizado_en     DATETIME     NOT NULL,
  enviado_en         DATETIME     DEFAULT NULL,
  UNIQUE KEY uq_clave (clave_idempotencia),
  KEY idx_due (estado, proximo_intento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------
-- Vista: vista_metricas_semaforo
-- Métricas diarias + el semáforo de salud comercial calculado.
-- Punto único de verdad para ROAS/CPL/semáforo — leer siempre de acá,
-- no reimplementar la fórmula en PHP.
-- ---------------------------------------------------------
CREATE OR REPLACE VIEW vista_metricas_semaforo AS
SELECT
  m.id,
  m.cliente_id,
  c.nombre_empresa,
  m.fecha,
  m.inversion_google,
  m.inversion_meta,
  m.inversion_total,
  m.leads_google,
  m.leads_meta,
  m.leads_totales,
  m.ventas_cant,
  m.monto_venta,
  m.cpl_dia,
  m.roas_dia,
  c.cpl_maximo_permitido,
  CASE
    WHEN m.leads_totales = 0 THEN '⚪ Sin Datos / Sin Leads'
    -- OJO: el texto 'Alerta: CPL Alto' lo consume includes/alertas_agencia.php
    -- (alertas_racha_roja) para detectar clientes en rojo. Si cambiás este texto,
    -- actualizá también esa función.
    WHEN m.cpl_dia > c.cpl_maximo_permitido THEN '🔴 Alerta: CPL Alto'
    WHEN m.roas_dia < 1 THEN '🟡 Precaución: ROAS Bajo'
    ELSE '🟢 Saludable'
  END AS estado_semaforo
FROM metricas_diarias m
INNER JOIN clientes c ON c.id = m.cliente_id;

-- ---------------------------------------------------------
-- Vista: vista_objetivos_progreso
-- Objetivos del mes + reales agregados desde metricas_diarias
-- (JOIN + SUM directo por mes, sin necesidad de ningún campo intermedio).
-- ---------------------------------------------------------
CREATE OR REPLACE VIEW vista_objetivos_progreso AS
SELECT
  o.id,
  o.cliente_id,
  c.nombre_empresa,
  o.mes_anio,
  o.presupuesto_asignado,
  o.meta_leads,
  o.meta_ventas,
  COALESCE(SUM(m.inversion_total), 0) AS inversion_real_mes,
  COALESCE(SUM(m.leads_totales), 0) AS leads_reales_mes,
  COALESCE(SUM(m.monto_venta), 0) AS ventas_reales_mes,
  CASE WHEN o.presupuesto_asignado = 0 THEN 0
       ELSE COALESCE(SUM(m.inversion_total), 0) / o.presupuesto_asignado
  END AS pct_cumplimiento_presupuesto,
  CASE WHEN o.meta_ventas = 0 THEN 0
       ELSE COALESCE(SUM(m.monto_venta), 0) / o.meta_ventas
  END AS pct_cumplimiento_ventas,
  CASE WHEN COALESCE(SUM(m.inversion_total), 0) = 0 THEN 0
       ELSE COALESCE(SUM(m.monto_venta), 0) / COALESCE(SUM(m.inversion_total), 0)
  END AS roas_mes,
  CASE WHEN COALESCE(SUM(m.leads_totales), 0) = 0 THEN 0
       ELSE COALESCE(SUM(m.inversion_total), 0) / COALESCE(SUM(m.leads_totales), 0)
  END AS cpl_mes
FROM objetivos_comerciales o
INNER JOIN clientes c ON c.id = o.cliente_id
LEFT JOIN metricas_diarias m
  ON m.cliente_id = o.cliente_id
  AND YEAR(m.fecha) = YEAR(o.mes_anio)
  AND MONTH(m.fecha) = MONTH(o.mes_anio)
GROUP BY o.id, o.cliente_id, c.nombre_empresa, o.mes_anio, o.presupuesto_asignado, o.meta_leads, o.meta_ventas;

-- ---------------------------------------------------------
-- Vista: vista_atribucion_canal
-- Atribución por canal (Google vs Meta) por cliente y mes: ROAS/CPL/conversión en SQL.
-- CRITERIO TEMPORAL (deuda conocida, ver roadmap "Later"): la inversión se agrupa por
-- día de gasto (metricas_diarias.fecha) y las ventas/leads por el mes de ENTRADA del
-- lead (leads.fecha_ingreso = cohorte). Difiere de metricas_diarias /
-- vista_objetivos_progreso, que cuentan la venta en el mes de CIERRE (caja). Por eso
-- las "ventas del mes" de esta vista pueden no coincidir con la tarjeta de ROAS.
-- Unificar el criterio es una feature pendiente (brainstorm→spec).
-- ---------------------------------------------------------
CREATE OR REPLACE VIEW vista_atribucion_canal AS
SELECT
  cliente_id, mes_anio, canal,
  SUM(inversion)    AS inversion,
  SUM(leads)        AS leads,
  SUM(ventas_cant)  AS ventas_cant,
  SUM(ventas_monto) AS ventas_monto,
  IF(SUM(inversion) = 0, 0, SUM(ventas_monto) / SUM(inversion)) AS roas,
  IF(SUM(leads)     = 0, 0, SUM(inversion) / SUM(leads))        AS cpl,
  IF(SUM(leads)     = 0, 0, SUM(ventas_cant) / SUM(leads))      AS `conversion`
FROM (
  -- Inversión Google (despivote de columna a fila).
  SELECT cliente_id, DATE_FORMAT(fecha, '%Y-%m-01') AS mes_anio, 'google' AS canal,
         inversion_google AS inversion, 0 AS leads, 0 AS ventas_cant, 0 AS ventas_monto
  FROM metricas_diarias
  UNION ALL
  -- Inversión Meta.
  SELECT cliente_id, DATE_FORMAT(fecha, '%Y-%m-01'), 'meta',
         inversion_meta, 0, 0, 0
  FROM metricas_diarias
  UNION ALL
  -- Leads y ventas atribuidas por canal de origen (fuente) y al mes de ENTRADA
  -- del lead (fecha_ingreso = cohorte), no al mes de cierre de la venta.
  SELECT cliente_id, DATE_FORMAT(fecha_ingreso, '%Y-%m-01'), fuente,
         0, 1,
         IF(estado = 'vendido', 1, 0),
         IF(estado = 'vendido', COALESCE(monto_venta, 0), 0)
  FROM leads
) unido
GROUP BY cliente_id, mes_anio, canal;
