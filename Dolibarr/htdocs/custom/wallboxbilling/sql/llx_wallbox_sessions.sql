-- Tabelle für Wallbox-Ladevorgänge (DB-01, API-05)

CREATE TABLE IF NOT EXISTS llx_wallbox_sessions (
    rowid INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
    fk_user INTEGER NOT NULL DEFAULT 0,
    rfid_hash VARCHAR(64) NOT NULL,
    wallbox_id VARCHAR(50) NOT NULL DEFAULT 'alfen_eve',
    start_time DATETIME NOT NULL,
    end_time DATETIME NULL DEFAULT NULL,
    kwh REAL NOT NULL DEFAULT 0.0,
    price_per_kwh REAL NOT NULL DEFAULT 0.30,
    total_cost REAL NOT NULL DEFAULT 0.0,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    date_creation DATETIME NOT NULL,
    transmitted_at DATETIME NULL DEFAULT NULL,
    tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_wallbox_sessions_rfid (rfid_hash),
    INDEX idx_wallbox_sessions_user (fk_user),
    INDEX idx_wallbox_sessions_start (start_time),
    INDEX idx_wallbox_sessions_status (status),
    INDEX idx_wallbox_sessions_transmitted (transmitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
