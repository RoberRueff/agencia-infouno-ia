-- 014_clientes_tema.sql
-- Preferencia de tema visual del dashboard (claro/oscuro), elegida por el
-- cliente. 'auto' (default) sigue el prefers-color-scheme del navegador,
-- igual que el comportamiento actual — no cambia nada hasta que el cliente
-- toque el botón de tema en dashboard.php.
-- ALTER — correr UNA sola vez (no idempotente).

ALTER TABLE clientes
  ADD COLUMN tema ENUM('auto','claro','oscuro') NOT NULL DEFAULT 'auto'
    COMMENT 'Preferencia de tema visual del dashboard, elegida por el cliente'
    AFTER sla_horas_objetivo;
