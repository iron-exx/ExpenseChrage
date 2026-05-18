-- RFID-Karten-Zuordnung (User ↔ RFID-Hash)
-- Diese Tabelle wird vom Dolibarr-Modul installiert.

CREATE TABLE IF NOT EXISTS llx_wallbox_rfid (
    rowid         INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
    fk_user       INTEGER NOT NULL,
    rfid_hash     VARCHAR(64) NOT NULL,
    label         VARCHAR(64),
    price_kwh     DECIMAL(10,4) DEFAULT NULL,
    active        TINYINT DEFAULT 1,
    entity        INTEGER DEFAULT 1,
    date_creation DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE UNIQUE INDEX uk_wallbox_rfid_user_entity ON llx_wallbox_rfid (fk_user, entity);
CREATE INDEX idx_wallbox_rfid_hash ON llx_wallbox_rfid (rfid_hash);
