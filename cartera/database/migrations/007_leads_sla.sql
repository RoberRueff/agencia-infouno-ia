-- 007_leads_sla.sql
-- SLA de respuesta del lead: timestamp del primer toque humano del dueño sobre
-- el lead, más el objetivo de SLA (en horas) configurable por cliente.
-- ALTER — correr UNA sola vez (no idempotente).

ALTER TABLE leads
  ADD COLUMN fecha_primer_contacto DATETIME DEFAULT NULL AFTER motivo_perdida;

ALTER TABLE clientes
  ADD COLUMN sla_horas_objetivo SMALLINT UNSIGNED NOT NULL DEFAULT 24
    COMMENT 'Objetivo de SLA de primera respuesta al lead, en horas (feature SLA)'
    AFTER cpl_maximo_permitido;
