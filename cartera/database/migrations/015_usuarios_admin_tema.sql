-- 015_usuarios_admin_tema.sql
-- Preferencia de tema visual del panel admin (claro/oscuro), elegida por el
-- usuario nombrado. 'auto' (default) sigue el prefers-color-scheme del
-- navegador. El acceso de emergencia break-glass no tiene fila en esta
-- tabla, así que nunca lee/escribe esta columna (ver admin_tema_actual()
-- en includes/admin_auth.php) — para ese caso el toggle funciona
-- visualmente pero no persiste entre sesiones.
-- ALTER — correr UNA sola vez (no idempotente).

ALTER TABLE usuarios_admin
  ADD COLUMN tema ENUM('auto','claro','oscuro') NOT NULL DEFAULT 'auto'
    COMMENT 'Preferencia de tema visual del panel admin, elegida por el usuario'
    AFTER totp_secret;
