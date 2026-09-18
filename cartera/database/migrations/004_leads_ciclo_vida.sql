-- Ciclo de vida del lead: agrega el estado intermedio 'en_negociacion' y el
-- motivo de pérdida. Los valores previos del enum se conservan (los datos
-- existentes quedan intactos). ALTER — correr UNA sola vez (no idempotente).
ALTER TABLE leads
  MODIFY COLUMN estado ENUM('pendiente','contactado','en_negociacion','vendido','no_vendido') NOT NULL DEFAULT 'pendiente',
  ADD COLUMN motivo_perdida VARCHAR(255) DEFAULT NULL AFTER estado;
