# ExpenseCharge — Home Assistant Addon

Erfasst RFID-basierte Ladevorgänge der Alfen-Eve-Wallbox und schreibt sie direkt in die Dolibarr-Spesenabrechnung des jeweiligen Mitarbeiters.

## Funktionen

- RFID-Erkennung über die Alfen-Eve-Sensoren (`alfen_eve_tag_socket_1`, `alfen_eve_meter_reading_socket_1`, `alfen_eve_main_state_socket_1`)
- Session-Tracking (Start, Ende, kWh) mit lokalem SQLite-Buffer
- SHA-256-Hash für RFID — keine Klartext-Speicherung (DSGVO/Datensparsamkeit)
- 7-Sekunden-Debounce gegen Doppellesungen
- Robuste Status-Erkennung: substring-Match auf Alfen-Werte wie `Charging Power On`, `Available`, `Finishing`, `Faulted`
- End-Trigger via Status **oder** RFID-„No Tag" (Karte abgezogen)
- Automatische Übertragung an Dolibarr `receive.php` mit Token-Auth (Header `DOLAPIKEY`)
- Web-UI (Ingress):
  - **⚡ Erfassen** — Startseite mit eingebettetem Live-Block (laufende Sessions + Wallbox-Status, flackerfreies JS-Polling alle 5 s über `/live.json`) + manuelles Erfassen
  - **📋 Verlauf** — Historie + CSV-Export

## Installation

1. Repository hinzufügen: `https://github.com/iron-exx/ExpenseChrage`
2. Addon „ExpenseCharge" installieren
3. Konfiguration anpassen (siehe unten)
4. Addon starten

## Konfiguration

```yaml
log_level: INFO
wallbox_id: alfen_eve
rfid_whitelist:
  - "A1B2C3D4"
  - "12345678"
sensor_rfid:   sensor.alfen_eve_tag_socket_1
sensor_energy: sensor.alfen_eve_meter_reading_socket_1
sensor_state:  sensor.alfen_eve_main_state_socket_1
ha_token: ""                      # leer = SUPERVISOR_TOKEN wird automatisch genutzt
min_session_kwh: 0.05
api:
  dolibarr_url: "https://erp.example.com"
  api_token: "<gemeinsames API-Token, identisch mit Dolibarr-Modulkonfiguration>"
  transmit_interval: 300
  timeout: 30
```

| Schlüssel | Beschreibung |
|---|---|
| `wallbox_id` | Label, wird mit in der Spesenabrechnungs-Zeile angezeigt |
| `rfid_whitelist` | Liste der erlaubten RFID-Hex-Strings — alles andere wird ignoriert |
| `sensor_rfid` | HA-Entity für die RFID-Lesung (liefert Tag-ID oder `No Tag`) |
| `sensor_energy` | HA-Entity für den kumulativen Energiezähler in kWh |
| `sensor_state` | HA-Entity für den Wallbox-Status (Available / Charging / …) |
| `ha_token` | Nur nötig, falls `SUPERVISOR_TOKEN` nicht verfügbar ist — normalerweise leer lassen |
| `min_session_kwh` | Mindest-kWh ab dem eine Session als echte Ladung gewertet wird (Default 0.05). Karte gelesen ohne Anschluss → Session wird als `discarded` markiert, nicht übertragen |
| `api.dolibarr_url` | Basis-URL, ohne `/custom/wallboxbilling/...` Pfad |
| `api.api_token` | Gemeinsames Shared-Secret — muss identisch in der Dolibarr-Modulkonfiguration stehen (**kein** Dolibarr-Benutzer-DOLAPIKEY) |
| `api.transmit_interval` | Sekunden zwischen Retry-Loops (Default 300 = 5 min) |
| `api.timeout` | HTTP-Timeout in Sekunden für die Übertragung an Dolibarr |

## Voraussetzungen

- Home Assistant Core mit Alfen-Eve-Integration (oder kompatible Wallbox die drei vergleichbare Sensoren liefert)
- Dolibarr 20+ mit installiertem `wallboxbilling`-Modul (aktuelle Version siehe Repo-Root)

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

Header: `DOLAPIKEY: <gemeinsames API-Token>`. Response:
- **200** mit `{"success": true, "expensereport_id": ..., "line_id": ...}` bei Erfolg
- **200** mit `{"success": false, "message": "Session already exists"}` bei bereits übertragener Session (idempotent, kein Fehler)
- **401** wenn das API-Token falsch/fehlt
- **404** wenn der RFID-Hash keinem Dolibarr-Mitarbeiter zugeordnet ist
- **400** bei fehlenden/ungültigen Feldern (z.B. `kwh` ≤ 0, ungültiges Zeitformat)
- **500** bei internen Dolibarr-Fehlern (Details im Dolibarr-Syslog, nicht in der Response)
- Bei 4xx/5xx: Addon retried automatisch
