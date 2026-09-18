-- 010_crons_latidos.sql
-- Heartbeat de crons: cada cron registra acá su último latido al arrancar (ver
-- includes/crons_heartbeat.php). El panel de cartera y el digest de agencia
-- detectan un cron caído comparando ultimo_latido contra su umbral.
-- ultimo_latido es DATETIME (no TIMESTAMP) a propósito: se escribe y compara
-- íntegramente con el reloj de PHP (date()/strtotime(), zona BsAs), nunca con
-- NOW() de MySQL — mismo criterio que intentos_login, para que MySQL no
-- reinterprete la hora con su huso (UTC en muchos hostings) y descuadre el cálculo.
-- Es idempotente (CREATE TABLE IF NOT EXISTS), se puede re-correr sin romper.
CREATE TABLE IF NOT EXISTS crons_latidos (
  cron_nombre   VARCHAR(50) NOT NULL PRIMARY KEY,
  ultimo_latido DATETIME NOT NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
