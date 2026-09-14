-- Heti menza etlap - feliratkozasi rendszer
-- Futtasd le egyszer a Hostinger phpMyAdmin feluleten.

CREATE TABLE IF NOT EXISTS subscribers (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email             VARCHAR(190)  NOT NULL,
  status            ENUM('active','inactive') NOT NULL DEFAULT 'active',
  consent_version   VARCHAR(32)   NOT NULL,
  consent_at        DATETIME      NOT NULL,
  unsubscribe_token CHAR(64)      NOT NULL,
  source            VARCHAR(64)   DEFAULT NULL,
  ip_hash           CHAR(64)      DEFAULT NULL,
  created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  unsubscribed_at   DATETIME      DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_email (email),
  UNIQUE KEY uniq_token (unsubscribe_token),
  KEY idx_status (status),
  KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Egyszeru visszaeles-vedelem: feliratkozasi kiserletek IP-hash szerint.
CREATE TABLE IF NOT EXISTS signup_attempts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip_hash     CHAR(64)  NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ip_time (ip_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
