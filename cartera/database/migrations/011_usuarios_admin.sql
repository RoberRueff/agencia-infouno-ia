-- 011_usuarios_admin.sql
-- Multi-usuario del panel admin: usuarios nombrados con identidad y rol,
-- reemplazan la credencial única de config (que queda como break-glass).
-- Idempotente. NO siembra filas: el bootstrap es el break-glass del config
-- (con la password de config entrás en modo dueño y creás tu usuario nombrado).
-- El rol se guarda en ASCII ('dueno') a propósito: se compara en el gate de
-- autorización y un valor sin acento evita mismatches por codificación.

CREATE TABLE IF NOT EXISTS usuarios_admin (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre        VARCHAR(120) NOT NULL,
  email         VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  rol           ENUM('dueno','operador') NOT NULL DEFAULT 'operador',
  activo        TINYINT(1) NOT NULL DEFAULT 1,
  totp_secret   VARCHAR(64) DEFAULT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_usuario_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
