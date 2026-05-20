# Wallbox-Dolibarr Addon

Home-Assistant-Addon: erfasst RFID-basierte Wallbox-Ladevorgänge und schreibt sie direkt in die Dolibarr-Spesenabrechnung des jeweiligen Mitarbeiters.

## Funktionen

- RFID-Lesung über Wallbox-Sensoren in HA (Alfen, generisch)
- Session-Tracking (Start, Ende, kWh) mit lokalem SQLite-Buffer
- SHA-256-Hash für RFID — keine Klartext-Speicherung (DSGVO/Datensparsamkeit)
- 7-Sekunden-Debounce gegen Doppellesungen
- Automatische Übertragung an Dolibarr `receive.php` mit DOLAPIKEY-Auth
- Crash-Recovery: aktive Sessions beim Neustart erkennen und behandeln
- Web-UI (Ingress): manuelle Sessions, Historie, CSV-Export

## Installation

1. Repository hinzufügen: `https://github.com/iron-exx/evcharge-dolibarr-invoice`
2. Addon „Wallbox Dolibarr Invoice" installieren
3. Konfiguration anpassen (siehe unten)
4. Addon starten

## Konfiguration

```yaml
log_level: INFO
wallbox_id: meine_wallbox
rfid_whitelist:
  - "A1B2C3D4"
  - "12345678"
ha_token: ""                      # leer = SUPERVISOR_TOKEN
dolibarr_url: "https://erp.example.com"
api_token: "<DOLAPIKEY>"
transmit_interval: 300
sensor_rfid:   sensor.alfen_eve_tag_socket_1
sensor_energy: sensor.alfen_energy_total
sensor_state:  sensor.alfen_eve_display_state_socket_1
```

| Schlüssel | Beschreibung |
|---|---|
| `wallbox_id` | Label, wird mit in der Spesenabrechnungs-Zeile angezeigt |
| `rfid_whitelist` | Liste der erlaubten RFID-Hex-Strings — alles andere wird ignoriert |
| `dolibarr_url` | Basis-URL, ohne `/custom/wallboxbilling/...` Pfad |
| `api_token` | DOLAPIKEY eines Dolibarr-Service-Users |
| `transmit_interval` | Sekunden zwischen Retry-Loops (Default 300 = 5 min) |

## Voraussetzungen

- Home Assistant Core mit Wallbox-Integration (z.B. Alfen)
- Dolibarr 20+ mit installiertem `wallboxbilling`-Modul (Version 1.1.2+)
- HA-Sensor für RFID, Energiezähler (kWh) und Wallbox-Status

## API-Endpoint Dolibarr-Seite

Das Addon spricht ausschließlich `POST /custom/wallboxbilling/receive.php` an. Body:

```json
{
  "rfid_hash": "9df4e8...",
  "wallbox_id": "meine_wallbox",
  "start_time": "2026-05-19T08:42:00+02:00",
  "end_time":   "2026-05-19T09:15:00+02:00",
  "kwh": 12.345
}
```

Header: `DOLAPIKEY: <token>`. Response: 200 mit `report_id` + `line_id` bei Erfolg, 422 bei unbekanntem/inaktivem RFID, 4xx/5xx bei Fehlern (Addon retried automatisch).
