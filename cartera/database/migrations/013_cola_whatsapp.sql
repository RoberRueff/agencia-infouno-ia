-- 013_cola_whatsapp.sql
-- Cola durable (patrón outbox) para los envíos automáticos de WhatsApp: los crons
-- encolan en vez de enviar inline, y un worker drena con reintentos/backoff. Así
-- ningún mensaje se pierde por un fallo transitorio. Idempotente. NO siembra filas.
-- clave_idempotencia es UNIQUE: encolar dos veces la misma clave es un no-op.
-- proximo_intento/creado_en/actualizado_en/enviado_en son DATETIME seteados por el
-- reloj de PHP (BsAs), nunca NOW()/CURRENT_TIMESTAMP: MySQL corre en UTC y mezclar
-- los relojes desalinea el backoff.

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
