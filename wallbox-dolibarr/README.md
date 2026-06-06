# Wallbox-Dolibarr Addon

Home-Assistant-Addon: erfasst RFID-basierte Ladevorgänge der Alfen-Eve-Wallbox und schreibt sie direkt in die Dolibarr-Spesenabrechnung des jeweiligen Mitarbeiters.

## Funktionen

- RFID-Erkennung über die Alfen-Eve-Sensoren (`alfen_eve_tag_socket_1`, `alfen_eve_meter_reading_socket_1`, `alfen_eve_main_state_socket_1`)
- Session-Tracking (Start, Ende, kWh) mit lokalem SQLite-Buffer
- SHA-256-Hash für RFID — keine Klartext-Speicherung (DSGVO/Datensparsamkeit)
- 7-Sekunden-Debounce gegen Doppellesungen
- Robuste Status-Erkennung: substring-Match auf Alfen-Werte wie `Charging Power On`, `Available`, `Finishing`, `Faulted`
- End-Trigger via Status **oder** RFID-„No Tag" (Karte abgezogen)
- Automatische Übertragung an Dolibarr `receive.php` mit DOLAPIKEY-Auth
- Web-UI (Ingress):
  - **⚡ Erfassen** — Startseite mit eingebettetem Live-Block (laufende Sessions + Wallbox-Status, flackerfreies JS-Polling alle 5 s über `/live.json`) + manuelles Erfassen
  - **📋 Verlauf** — Historie + CSV-Export

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
sensor_energy: sensor.alfen_eve_meter_reading_socket_1
sensor_state:  sensor.alfen_eve_main_state_socket_1
```

| Schlüssel | Beschreibung |
|---|---|
| `wallbox_id` | Label, wird mit in der Spesenabrechnungs-Zeile angezeigt |
| `rfid_whitelist` | Liste der erlaubten RFID-Hex-Strings — alles andere wird ignoriert |
| `dolibarr_url` | Basis-URL, ohne `/custom/wallboxbilling/...` Pfad |
| `api_token` | DOLAPIKEY eines Dolibarr-Service-Users |
| `transmit_interval` | Sekunden zwischen Retry-Loops (Default 300 = 5 min) |
| `min_session_kwh` | Mindest-kWh ab dem eine Session als echte Ladung gewertet wird (Default 0.05). Karte gelesen ohne Anschluss → Session wird als `discarded` markiert, nicht übertragen |
| `sensor_rfid` | HA-Entity für die RFID-Lesung (liefert Tag-ID oder `No Tag`) |
| `sensor_energy` | HA-Entity für den kumulativen Energiezähler in kWh |
| `sensor_state` | HA-Entity für den Wallbox-Status (Available / Charging / …) |

## Voraussetzungen

- Home Assistant Core mit Alfen-Eve-Integration (oder kompatible Wallbox die drei vergleichbare Sensoren liefert)
- Dolibarr 20+ mit installiertem `wallboxbilling`-Modul (Version 1.1.4+)

## API-Endpoint (Dolibarr-Seite)

Das Addon spricht ausschließlich `POST /custom/wallboxbilling/receive.php` an. Body:

```json
{
  "rfid_hash": "<sha256 hex>",
  "wallbox_id": "meine_wallbox",
  "start_time": "2026-05-19T08:42:00+02:00",
  "end_time":   "2026-05-19T09:15:00+02:00",
  "kwh": 12.345
}
```

Header: `DOLAPIKEY: <token>`. Response:
- **200** mit `report_id` + `line_id` bei Erfolg
- **422 RFID_NOT_MAPPED** wenn der RFID-Hash keinem Dolibarr-User zugeordnet ist
- **422 RFID_INACTIVE** wenn die Karte deaktiviert wurde (Soft-Delete)
- 4xx/5xx bei sonstigen Fehlern — Addon retried automatisch
