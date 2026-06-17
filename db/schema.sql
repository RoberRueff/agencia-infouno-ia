-- =====================================================================
-- Infouno — Tabla de leads
-- Pegar este script en phpMyAdmin (cPanel de DonWeb) sobre tu base.
-- Charset utf8mb4 para soportar tildes, ñ y emojis.
-- =====================================================================

CREATE TABLE IF NOT EXISTS wp_infouno_leads (
  lead_id              INT AUTO_INCREMENT PRIMARY KEY,
  session_id           VARCHAR(64)  NOT NULL,
  lead_name            VARCHAR(120) DEFAULT NULL,
  lead_rubro           VARCHAR(150) DEFAULT NULL,                  -- rubro (bot) o interés (form)
  lead_company         VARCHAR(150) DEFAULT NULL,
  lead_message         TEXT         DEFAULT NULL,
  lead_infrastructure  ENUM('no_web','has_web') DEFAULT NULL,
  lead_size            ENUM('solo','team_small','team_large') DEFAULT NULL,
  lead_phone           VARCHAR(30)  DEFAULT NULL,                  -- WhatsApp normalizado
  lead_email           VARCHAR(150) DEFAULT NULL,                  -- validado (vacío si inválido)
  lead_scoring         INT          DEFAULT 0,
  lead_vip             TINYINT(1)   DEFAULT 0,
  lead_source          VARCHAR(20)  DEFAULT NULL,                  -- bot | form
  page                 VARCHAR(190) DEFAULT NULL,
  utm_source           VARCHAR(120) DEFAULT NULL,
  utm_medium           VARCHAR(120) DEFAULT NULL,
  utm_campaign         VARCHAR(150) DEFAULT NULL,
  lead_notified        TINYINT(1)   DEFAULT 0,                     -- 1 = ya se avisó por email
  created_at           DATETIME     DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_session (session_id),
  KEY idx_vip (lead_vip),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
