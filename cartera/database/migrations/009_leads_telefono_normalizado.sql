-- 009_leads_telefono_normalizado.sql
-- Dedup de leads por teléfono. Columna generada STORED con los últimos 8
-- dígitos del teléfono (el número de abonado), calculada en SQL — fuente única
-- de la regla de normalización, sin duplicarla en PHP. STORED ⇒ se rellena
-- sola en los leads existentes al aplicar este ALTER (backfill mecánico).
-- El índice sirve el match por igualdad del chequeo de dedup en registrar_lead().
-- ALTER — correr UNA sola vez (no idempotente).

ALTER TABLE leads
  ADD COLUMN telefono_normalizado VARCHAR(8)
    GENERATED ALWAYS AS (
      RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
        telefono_lead, ' ', ''), '+', ''), '-', ''), '(', ''), ')', ''), '.', ''), '/', ''), 8)
    ) STORED
    COMMENT 'Últimos 8 dígitos de telefono_lead sin separadores para dedup; REPLACE anidado (no REGEXP_REPLACE) por compat MySQL 5.7. Ver includes/helpers.php::registrar_lead()'
    AFTER telefono_lead,
  ADD INDEX idx_dedup_telefono (cliente_id, telefono_normalizado, estado);
