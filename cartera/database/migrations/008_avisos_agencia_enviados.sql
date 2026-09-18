-- 008_avisos_agencia_enviados.sql
-- Dedup del digest diario de avisos de cartera (cron/05). A nivel día (no por
-- cliente): alertas_enviadas exige cliente_id y el aviso es de cartera. Es
-- idempotente (CREATE TABLE IF NOT EXISTS), se puede re-correr sin romper.
CREATE TABLE IF NOT EXISTS avisos_agencia_enviados (
  fecha DATE NOT NULL PRIMARY KEY,
  cantidad_avisos INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
