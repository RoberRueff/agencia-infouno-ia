-- =========================================================
-- Migración 001 — tabla solicitudes_reset
-- =========================================================
-- Aplicar SOLO en bases que ya estaban desplegadas ANTES de la feature de
-- recuperación de contraseña ("¿Olvidaste tu contraseña?"). En una base creada
-- desde cero con database/schema.sql esta tabla ya viene incluida y NO hace
-- falta correr esta migración.
--
-- Cómo aplicarla en Donweb/cPanel:
--   phpMyAdmin → seleccionar la base → pestaña "Importar" → elegir este archivo
--   → Continuar. (O por SSH: mysql -u USUARIO -p BASE < 001_solicitudes_reset.sql)
--
-- Es idempotente: CREATE TABLE IF NOT EXISTS no falla si ya existe.
-- =========================================================

SET NAMES utf8mb4;

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
