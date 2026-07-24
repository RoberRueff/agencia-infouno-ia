-- =========================================================
-- Migración 002 — columna clientes.domicilio
-- =========================================================
-- Domicilio del responsable del tratamiento (Ley 25.326, art. 6.b), para
-- mostrarlo en la política de privacidad (public/privacidad.php).
--
-- Aplicar SOLO en bases ya desplegadas (en una base nueva desde schema.sql la
-- columna ya viene). Cómo: phpMyAdmin → Importar, o por SSH.
--
-- OJO: MySQL no soporta "ADD COLUMN IF NOT EXISTS", así que esta migración NO
-- es idempotente — correrla una sola vez. Si ya la aplicaste, va a dar un error
-- "Duplicate column name 'domicilio'" que podés ignorar.
-- =========================================================

ALTER TABLE clientes
  ADD COLUMN domicilio VARCHAR(255) DEFAULT NULL
  COMMENT 'Domicilio del responsable (Ley 25.326, art. 6.b)'
  AFTER email_dueno;
