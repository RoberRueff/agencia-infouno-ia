-- 012_auditoria.sql
-- Bitácora append-only de las acciones del panel admin: quién (usuario nombrado
-- o break-glass) hizo qué acción sobre qué entidad y cuándo, con el diff de los
-- campos sensibles en 'detalle'. Idempotente. NO siembra filas.
-- creado_en es DATETIME seteado por el reloj de PHP (BsAs), nunca NOW()/
-- CURRENT_TIMESTAMP: MySQL corre en UTC y mezclarlos desalinea las horas.
-- usuario_admin_id sin FK a propósito: el log es histórico e independiente; la
-- identidad queda en los snapshots actor_nombre/actor_rol aunque el usuario se
-- borre o se renombre.

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
