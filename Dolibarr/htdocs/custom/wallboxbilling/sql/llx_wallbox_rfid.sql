-- RFID-Karten-Zuordnung (User ↔ RFID-Hash)

CREATE TABLE IF NOT EXISTS llx_wallbox_rfid (
    rowid         INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
    fk_user       INTEGER NOT NULL,
    rfid_hash     VARCHAR(64) NOT NULL,
    label         VARCHAR(64),
    price_kwh     DECIMAL(10,4) DEFAULT NULL,
    active        TINYINT DEFAULT 1,
    entity        INTEGER DEFAULT 1,
    date_creation DATETIME NOT NULL,
    UNIQUE INDEX uk_wallbox_rfid_user_entity (fk_user, entity),
    INDEX idx_wallbox_rfid_hash (rfid_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
