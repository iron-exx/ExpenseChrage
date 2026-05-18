-- Wallbox Billing Sessions Tabelle (D-07, DB-03, DB-01 Vorbereitung)

CREATE TABLE IF NOT EXISTS `llx_wallboxbilling_sessions` (
  `rowid` INTEGER PRIMARY KEY AUTO_INCREMENT,
  `user_id` INTEGER NOT NULL DEFAULT 0,
  `rfid_hash` VARCHAR(128) NOT NULL DEFAULT '',
  `wallbox_id` VARCHAR(50) NOT NULL DEFAULT '',
  `start_time` DATETIME NULL DEFAULT NULL,
  `end_time` DATETIME NULL DEFAULT NULL,
  `kwh` DECIMAL(10,3) NOT NULL DEFAULT 0.000,
  `price_per_kwh` DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
  `total_cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL DEFAULT NULL,
  INDEX idx_wallboxbilling_rfid (rfid_hash),
  INDEX idx_wallboxbilling_user (user_id),
  INDEX idx_wallboxbilling_start (start_time),
  INDEX idx_wallboxbilling_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
