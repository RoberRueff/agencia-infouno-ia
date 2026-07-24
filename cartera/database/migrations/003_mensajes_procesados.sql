-- =========================================================
-- Migración 003 — tabla mensajes_procesados (anti-replay del webhook)
-- =========================================================
-- Guarda el id de cada mensaje entrante de WhatsApp ya procesado para no
-- volver a registrar la misma venta si un POST firmado se reenvía.
-- Ver public/webhook_whatsapp.php.
--
-- Aplicar SOLO en bases ya desplegadas (en una base nueva ya viene en
-- schema.sql). Idempotente: CREATE TABLE IF NOT EXISTS.
-- =========================================================

CREATE TABLE IF NOT EXISTS mensajes_procesados (
  message_id VARCHAR(255) NOT NULL PRIMARY KEY,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
