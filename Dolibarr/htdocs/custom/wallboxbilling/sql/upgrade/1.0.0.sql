-- Migration 1.0.0: Fehlende Spalten in llx_wallbox_rfid nachträglich hinzufügen
-- Ausführen falls die Tabelle bereits ohne price_kwh / entity existiert.

ALTER TABLE llx_wallbox_rfid ADD COLUMN price_kwh DECIMAL(10,4) DEFAULT NULL AFTER label;
ALTER TABLE llx_wallbox_rfid ADD COLUMN entity INTEGER DEFAULT 1 AFTER active;
