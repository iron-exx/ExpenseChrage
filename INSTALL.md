# Wallbox-Dolibarr — Installationsanleitung

Komplette Inbetriebnahme: **Home Assistant** (RFID-Erfassung) → **Dolibarr** (direkt in die Spesenabrechnung).

```
Wallbox  ──►  Home Assistant Addon  ──POST──►  Dolibarr receive.php  ──►  Spesenabrechnung
                  │                                                          des Mitarbeiters
                  └─ SQLite-Buffer (Crash-Recovery, Retry)
```

> **Verlustsicherheit:** Das HA-Addon puffert Sessions lokal in SQLite (WAL-Mode). Bei Netzausfall werden sie beim nächsten Erreichen von Dolibarr nachgereicht. Dolibarr verhindert Duplikate über einen Marker `[wbx:HASH:UNIXTS]` in der Zeile.

---

## 1 — Voraussetzungen

| Komponente | Mindestens | Empfohlen |
|---|---|---|
| Dolibarr | 20.0 | 21.x / 22.x |
| PHP | 8.0 | 8.2 |
| MariaDB / MySQL | 10.5 / 8.0 | 10.11 / 8.0.30+ |
| Home Assistant Core | 2024.6 | aktuelles Stable |
| Python (im HA-Addon) | 3.12 | 3.12+ |
| Wallbox | beliebig — HA muss `power`, `energy`, `rfid`, `state` als Sensoren liefern |

In Dolibarr muss **kein** zusätzliches Modul aktiv sein. Das wallboxbilling-Modul nutzt direkt Dolibarrs Spesenabrechnungs-Tabellen (`llx_expensereport`, `llx_expensereport_det`).

---

## 2 — Dolibarr-Modul installieren

1. Anmelden als **Admin**.
2. *Home → Konfiguration → Module/Anwendungen → Externes Modul hinzufügen*.
3. `module_wallboxbilling-1.1.2.zip` hochladen.
4. Modul **aktivieren** (orangenen Schalter klicken).
5. Verify: `https://<dolibarr>/custom/wallboxbilling/receive.php` aufrufen → muss zurückgeben:
   ```json
   {"status":"ok","version":"1.1.2","mode":"direct-to-expensereport","endpoint":"wallboxbilling/receive.php",...}
   ```

### 2.1 — API-Service-User anlegen

1. *Benutzer & Gruppen → Benutzer → Neuer Benutzer*.
2. Login z.B. `wallbox_api`, Status **aktiv**.
3. Karteireiter *DOLAPIKEY* → **API-Key generieren** (wird einmalig angezeigt — sicher kopieren).
4. Rechte: **kein Admin nötig**, nur „wallboxbilling.config" oder generelle Lese-Schreibrechte für Spesenabrechnungen.

> Den API-Key später ins HA-Addon eintragen (`api_token`).

### 2.2 — RFID-Karten zuordnen

1. *Home → Konfiguration → Wallbox-Abrechnung*.
2. Default-Preis pro kWh setzen (z.B. `0.30 €/kWh`).
3. Pro Mitarbeiter: RFID-Hex eingeben → „+ Karte hinzufügen".
   - Mehrere Karten pro Mitarbeiter möglich.
   - Karten lassen sich einzeln × deaktivieren (Soft-Delete, Mapping bleibt für §147-AO-Aufbewahrungspflicht erhalten).
   - Über die Historie ↻ können deaktivierte Karten reaktiviert werden.

---

## 3 — Home Assistant Addon installieren

### 3.1 — Repository hinzufügen

1. *Einstellungen → Add-ons → Add-on-Store → ⋮ → Repositories*.
2. URL eintragen: `https://github.com/iron-exx/evcharge-dolibarr-invoice`.
3. „Hinzufügen" → Repository erscheint im Store.

### 3.2 — Addon installieren

1. Im Store: „Wallbox Dolibarr Invoice" → **Installieren**.
2. Karteireiter *Konfiguration*:
   ```yaml
   log_level: INFO
   wallbox_id: meine_wallbox
   rfid_whitelist:
     - "A1B2C3D4"
     - "12345678"
   ha_token: ""                      # leer lassen — Supervisor-Token wird automatisch genutzt
   dolibarr_url: "https://erp.example.com"
   api_token: "<DOLAPIKEY aus 2.1>"
   transmit_interval: 300            # alle 5 min an Dolibarr senden
   min_session_kwh: 0.05             # Sessions unter 50 Wh werden verworfen (Ghost-Filter)
   sensor_rfid:   sensor.alfen_eve_tag_socket_1
   sensor_energy: sensor.alfen_eve_meter_reading_socket_1
   sensor_state:  sensor.alfen_eve_main_state_socket_1
   ```

   > **Wichtig:** Die drei Sensoren müssen wirklich existieren — prüfen mit
   > *Entwicklerwerkzeuge → Zustände → Filter „alfen_eve"*. Bei abweichenden
   > Wallboxen die entsprechenden Sensoren eintragen.

3. **Speichern → Starten**.
4. Logs prüfen: muss zeigen `Dolibarr API Verbindung erfolgreich`.

### 3.3 — Ingress-UI

Über *Add-on öffnen* erreichbar, drei Tabs:
- **⚡ Erfassen** — manuelle Session nachtragen
- **🔴 Live** — laufende Ladevorgänge in Echtzeit (Auto-Refresh 5 s)
- **📋 Verlauf** — Historie pro Monat + CSV-Export
- Übertragungs-Status pro Session

---

## 4 — Funktionsprüfung (End-to-End)

1. Karte an die Wallbox halten → Ladevorgang starten.
2. HA-Logs (`Add-on → Log`) sollten zeigen:
   - `RFID autorisiert: <hash16>…`
   - `Session gestartet: ID=…`
3. Ladung beenden (Stecker ziehen / Wallbox auf Idle).
4. Innerhalb von `transmit_interval` Sekunden (Standard 5 min):
   - `Session erfolgreich übertragen` im Log
5. In Dolibarr: *Geschäftspartner → Spesenabrechnungen* → Draft für den aktuellen Monat des Mitarbeiters → Zeile mit kWh × Preis vorhanden.

---

## 5 — Fehlerbehebung

| Symptom | Ursache | Lösung |
|---|---|---|
| `HTTP 401` im HA-Log | API-Key falsch | DOLAPIKEY in Dolibarr neu generieren, ins Addon eintragen |
| `HTTP 422 RFID_NOT_MAPPED` | Karte nicht zugeordnet | Unter *Wallbox-Abrechnung Konfiguration* der Karte einen Benutzer zuordnen |
| `HTTP 422 RFID_INACTIVE` | Karte wurde deaktiviert | Historie ↻ in der Konfiguration → reaktivieren |
| `Server returned HTML statt JSON` | Modul nicht installiert / nologin-Fix fehlt | ZIP 1.1.2 hochladen, Modul deaktivieren+aktivieren |
| Session-Daten fehlen in Spesenreport | Falscher Monat | start_time prüfen — landet immer im Monat der Ladung, nicht „heute" |

---

## 6 — Upgrade

1. Neue ZIP im Modulmanager hochladen → „Überschreiben? **Ja**".
2. Modul deaktivieren + wieder aktivieren → `init()` läuft erneut (idempotent).
3. RFID-Mappings, Preise und Spesenabrechnungen bleiben erhalten.

> **Niemals** *„Modul entfernen"* in der Web-UI klicken solange produktive Daten existieren — verwende stattdessen die eingebaute „Modul deaktivieren"-Funktion unter *Wallbox-Abrechnung Konfiguration*. Diese behält das aufbewahrungspflichtige RFID-Mapping.

---

## 7 — Sicherheits-Checkliste

- [ ] `DOLAPIKEY` nicht im Git eingecheckt (HA `secrets.yaml`)
- [ ] Dolibarr ausschließlich über HTTPS (Reverse-Proxy / Let's Encrypt)
- [ ] DB-Backup enthält `llx_wallbox_rfid` und `llx_expensereport*`
- [ ] HA-Backup enthält das Addon-Volume (`/data/sessions.db`)
- [ ] Service-User für die API ist **nicht** Admin
- [ ] Klartext-RFIDs werden nicht geloggt (im Code: nur `substr(hash, 0, 16)…`)

---

Fertig. Mehrere Testladungen vor produktivem Betrieb durchführen.
