CREATE TABLE IF NOT EXISTS viewer_sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  session_hash CHAR(64) NOT NULL,
  device_hash CHAR(64) NOT NULL,
  user_agent VARCHAR(255) NULL,
  ip_address VARCHAR(64) NULL,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_viewer_sessions_user FOREIGN KEY (user_id) REFERENCES comment_users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_viewer_sessions_hash (session_hash),
  KEY idx_viewer_sessions_user (user_id),
  KEY idx_viewer_sessions_device (device_hash),
  KEY idx_viewer_sessions_expires (expires_at),
  KEY idx_viewer_sessions_revoked (revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
