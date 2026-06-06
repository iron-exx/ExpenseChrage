# Wallbox-Dolibarr Integration

RFID-basierte Abrechnung von Wallbox-Ladevorgängen — Sessions werden vom Home-Assistant-Addon **direkt in die Dolibarr-Spesenabrechnung** des jeweiligen Mitarbeiters geschrieben.

![Dolibarr-Modul](https://img.shields.io/badge/Dolibarr--Modul-1.1.4-blue)
![HA-Addon](https://img.shields.io/badge/HA--Addon-1.2.8-blue)
![Dolibarr](https://img.shields.io/badge/Dolibarr-20.x--22.x-green)
![Python](https://img.shields.io/badge/Python-3.12+-green)

## Funktionsweise

```
┌─────────────────┐                ┌──────────────────────┐
│  Home Assistant │   POST JSON    │      Dolibarr        │
│  Addon (Python) │ ─────────────► │  receive.php (PHP)   │
│                 │   DOLAPIKEY    │                      │
│  - RFID-Reader  │                │  ① RFID→User Lookup  │
│  - Energie-Zähl │                │  ② Spesenabrechnung  │
│  - SQLite-Buffer│                │     des Monats       │
│  - Retry-Loop   │                │     finden / anlegen │
└─────────────────┘                │  ③ Zeile in          │
                                   │     expensereport_det│
                                   └──────────────────────┘
```

Jede Ladung landet sofort als Position im Spesenreport des Mitarbeiters. Pro Monat und Mitarbeiter wird automatisch ein Draft-Report erstellt oder ein bestehender erweitert. Es gibt **keinen Cronjob**, **keine eigene Sessions-Tabelle** und **keine separate Abrechnungsseite** — alles steckt nativ in Dolibarrs Spesenabrechnungsmodul.

## Features

### Home Assistant Addon
- RFID-Authentifizierung mit SHA-256-Hash (kein Klartext-Speichern)
- Whitelist-Prüfung + 7s-Debounce gegen Doppellesungen
- Multi-Wallbox-Support
- SQLite-Buffer mit WAL-Mode für Crash-Recovery
- Restart-Recovery: laufende Sessions werden beim Neustart behandelt
- Auto-Retry mit Exponential-Backoff bei API-Fehlern
- Web-UI (Ingress): manuelle Sessions, History, CSV-Export

### Dolibarr-Modul
- Direkter Insert in `llx_expensereport` / `llx_expensereport_det` als Spesentyp `TK_ELE`
- **Multi-RFID pro Mitarbeiter**: beliebig viele Karten pro Benutzer
- Pro-User-Preis (€/kWh) oder Default
- Duplikat-Schutz via Marker `[wbx:HASH:UNIXTS]` in Comments-Feld
- **Soft-Delete für RFID-Mapping** (§147 AO, 10 Jahre Aufbewahrungspflicht): deaktivierte Karten bleiben in der Historie nachvollziehbar
- DOLAPIKEY-Auth, SHA-256-Hash-kompatibel (Dolibarr 20+)

## Voraussetzungen

| Komponente | Version |
|---|---|
| Dolibarr | 20.x – 22.x |
| Home Assistant | 2024.x+ mit Supervisor |
| Python | 3.12+ (im HA-Container) |

## Schnellinstallation

### 1. Dolibarr-Modul

1. `module_wallboxbilling-1.1.4.zip` im Dolibarr-Modulmanager hochladen
2. Modul **aktivieren**
3. Unter „Wallbox-Abrechnung Konfiguration":
   - Default-Preis pro kWh setzen
   - RFID-Karten pro Mitarbeiter zuordnen

Verify: `https://<dolibarr>/custom/wallboxbilling/receive.php` → muss `{"version":"1.1.4","mode":"direct-to-expensereport"}` zeigen.

### 2. Home Assistant Addon

1. Repository hinzufügen: `https://github.com/iron-exx/evcharge-dolibarr-invoice`
2. Addon „Wallbox Dolibarr Invoice" installieren
3. Konfiguration:
   ```yaml
   wallbox_id: meine_wallbox
   rfid_whitelist:
     - "A1B2C3D4"
   dolibarr_url: "https://erp.example.com"
   api_token: "<DOLAPIKEY>"
   sensor_rfid:   sensor.alfen_eve_tag_socket_1
   sensor_energy: sensor.alfen_eve_meter_reading_socket_1
   sensor_state:  sensor.alfen_eve_main_state_socket_1
   ```

### 3. Addon-Web-UI

Zwei Tabs im Ingress:

- **⚡ Erfassen** — Hauptseite mit:
  - **Live-Block oben** (sofern Sensoren liefern): aktueller Zählerstand, Wallbox-Status als farbiger Chip (grün=Charging, grau=Idle, rot=Faulted), laufende Sessions mit RFID-Prefix, Start-Zeit, Dauer und Live-kWh-Delta. **Flackerfreies JS-Polling** (`fetch('live.json')` alle 5 s)
  - Manuelles Erfassen einer Ladung
  - „Jetzt an Dolibarr übertragen" Button
- **📋 Verlauf** — abgeschlossene Sessions pro Monat, CSV-Export

## Datenfluss im Detail

1. **RFID gelesen** (`sensor.alfen_eve_tag_socket_1`): wechselt von `No Tag` auf eine Tag-ID (z.B. `A1B2C3D4`)
2. **Whitelist + Debounce**: 7-Sekunden-Sperre gegen Doppellesungen
3. **Session starten**: lokale SQLite speichert `start_time` + `start_energy_kwh` (Zählerstand aus `sensor.alfen_eve_meter_reading_socket_1`)
4. **Session beenden** — getriggert durch:
   - Wallbox-Status (`sensor.alfen_eve_main_state_socket_1`) wechselt auf `Available`, `Finishing`, `Stopped`, `Faulted`, … (substring-Match auf `charging`/`idle`/etc.), **oder**
   - RFID wechselt zurück auf `No Tag` (Karte abgezogen)
   - `total_kwh = end_zähler − start_zähler`
5. **Ghost-Session-Filter**: Sessions unter `min_session_kwh` (Default 0.05 kWh) werden als `discarded` markiert und NICHT übertragen — passiert wenn die Karte gehalten wird ohne dass eine Ladung tatsächlich beginnt
6. **Transmit** (alle 5 min oder sofort):
   - POST an `receive.php` mit `{rfid_hash, wallbox_id, start_time, end_time, kwh}`
   - PHP-Endpoint: RFID→User → Spesenabrechnung suchen/anlegen → Zeile rein
   - Bei Erfolg: `transmitted_at` lokal gesetzt
   - Bei `RFID_NOT_MAPPED` (422): Admin muss erst Mapping machen, Addon retried automatisch
   - Bei `RFID_INACTIVE` (422): Karte wurde deaktiviert, Admin muss reaktivieren

## Sicherheit & Compliance

- ✅ RFID nur als SHA-256-Hash persistiert (Datensparsamkeit, DSGVO)
- ✅ DOLAPIKEY-Auth (token-basiert, im HA-Secret)
- ✅ SQL-Injection-Schutz via `$db->escape()`
- ✅ Soft-Delete der RFID-Mappings → Aufbewahrungspflicht §147 AO (10 Jahre)
- ✅ Spesenabrechnungen unterliegen Dolibarrs Standard-Audit-Trail
- ✅ Modul-Deinstallation droppt **keine** aufbewahrungspflichtigen Tabellen

## Projektstruktur

```
Wallbox-Dolibarr/
├── Dolibarr/htdocs/custom/wallboxbilling/   # Dolibarr-Modul
│   ├── receive.php                          # POST-Endpoint
│   ├── admin/setup.php                      # Konfiguration + RFID-Verwaltung
│   ├── core/modules/modWallboxbilling.class.php
│   ├── lib/wallboxbilling.lib.php
│   └── langs/                               # de_DE, en_US
├── wallbox-dolibarr/                        # HA-Addon
│   ├── main.py                              # Hauptloop + Websocket
│   ├── session_manager.py                   # SQLite + RFID
│   ├── api_client.py                        # Dolibarr POST
│   ├── web_server.py                        # Ingress UI
│   ├── utils/hash.py                        # SHA-256
│   ├── Dockerfile
│   └── config.yaml
└── module_wallboxbilling-1.1.4.zip          # aktuelles Dolibarr-Modul
```

## Lizenz

MIT — siehe `LICENSE`.

## Support

GitHub: [iron-exx/evcharge-dolibarr-invoice](https://github.com/iron-exx/evcharge-dolibarr-invoice)
