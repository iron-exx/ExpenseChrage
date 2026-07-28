# ExpenseCharge — Home Assistant Addon

Erfasst RFID-basierte Ladevorgänge einer Wallbox und schreibt sie direkt in die Dolibarr-Spesenabrechnung des jeweiligen Mitarbeiters. Herstellerunabhängig konfigurierbar — von Alfen Eve bis zu generischen RFID-Leser+Zähler-Kombinationen (siehe [Wallbox-Profile](#wallbox-profile-herstellerunabhängige-konfiguration)).

## Funktionen

- **Wallbox-Profile**: vorgefertigtes Alfen-Eve-Profil oder vollständig freie Konfiguration für andere Hersteller, Lastmanagement-Adapter mit vorgeschaltetem Zähler (z.B. Shelly EM) oder Wallboxen ganz ohne eigenen Zähler
- Session-Tracking (Start, Ende, kWh) mit lokalem SQLite-Buffer
- SHA-256-Hash für RFID — keine Klartext-Speicherung (DSGVO/Datensparsamkeit)
- 7-Sekunden-Debounce gegen Doppellesungen
- Robuste Status-Erkennung: substring-Match auf Wallbox-Statuswerte wie `Charging Power On`, `Available`, `Finishing`, `Faulted` (Alfen-Profil) — oder alternativ Leistungsschwelle, Zähler-Stillstand, bzw. externe Aktiv-Entity (Custom-Profil)
- End-Trigger via Status, Leistung/Zähler-Idle, externer Entity **oder** Zweit-Tap (`tag_toggle`)
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

## Wallbox-Profile (herstellerunabhängige Konfiguration)

`wallbox_profile` schaltet zwischen zwei Betriebsarten um:

- **`alfen_eve`** (Default) — das bewährte, fest verdrahtete Alfen-Verhalten. Auth-Modus und Zustand-Erkennung sind fixiert (Tag hält an, Status-Keyword-Matching); nur die Entity-IDs (`sensor_rfid`/`sensor_energy`/`sensor_state`) bleiben anpassbar, z.B. für einen zweiten Anschluss.
- **`custom`** — Auth-Modus (`auth_mode`) und Zustand-Erkennung (`state_mode`) sind frei kombinierbar (Details unten).

> **Wichtig zur Bedienung der Home-Assistant-Konfigurationsoberfläche:** Home Assistant zeigt in der Addon-Konfiguration **immer alle Felder gleichzeitig** an — es gibt kein automatisches Ein-/Ausblenden je nach gewähltem `wallbox_profile`/`auth_mode`/`state_mode`. Jedes Feld hat inzwischen einen eigenen Hilfetext direkt in der HA-Oberfläche (Tooltip/Beschreibung unter dem Feldnamen), der erklärt, bei welcher Kombination es überhaupt wirksam ist. **Felder, die zur aktuellen Auswahl nicht passen, einfach leer/auf Default lassen — sie werden dann ignoriert.**

### Schritt für Schritt: Custom-Konfiguration einrichten

1. **`wallbox_profile` auf `custom` stellen.** Erst dann werden `auth_mode`/`state_mode` und ihre Zusatzfelder überhaupt ausgewertet (bei `alfen_eve` werden sie ignoriert).
2. **Eine Frage beantworten: "Wie merkt das System, WER laden darf?"** → das ist `auth_mode`, siehe Tabelle unten. Bei `none` `sensor_rfid` leer lassen.
3. **Eine zweite Frage beantworten: "Wie merkt das System, WANN geladen wird bzw. die Ladung endet?"** → das ist `state_mode`, siehe Tabelle unten.
4. **Nur die zu `state_mode` passenden Zusatzfelder ausfüllen:**
   - `state_keywords` → optional `end_keywords`/`pause_keywords` (leer = bewährte Alfen-Defaults, meist ausreichend)
   - `power_threshold` → `power_sensor` + `power_threshold_w` + `end_idle_minutes` ausfüllen
   - `energy_delta` → `end_idle_minutes` ausfüllen (kein extra Sensor nötig, nutzt `sensor_energy`)
   - `external_boolean` → `active_entity` ausfüllen
5. **`sensor_energy` immer setzen** — unabhängig vom Modus, das ist der kumulative kWh-Zähler, aus dem `total_kwh = Ende − Start` berechnet wird.
6. **Speichern → Addon neu starten.** Im Log erscheint beim Start eine Zeile `Wallbox-Profil: custom (auth_mode=..., state_mode=..., ...)` — damit lässt sich sofort prüfen, ob die gewählte Kombination korrekt angekommen ist.
7. **Testen:** einmal einen kompletten Ladevorgang durchspielen (bzw. simulieren) und die Addon-Logs beobachten (`Ladevorgang gestartet` / `Ladevorgang beendet`).

### Typische Ausgangssituationen → passende Kombination

| Deine Hardware-Situation | `auth_mode` | `state_mode` |
|---|---|---|
| Wallbox mit eigenem Status-Sensor und Tag-Sensor (wie Alfen) | `tag_hold` oder `tag_pulse` | `state_keywords` |
| Separater RFID-Leser (z.B. an einem Relais), Wallbox/Zähler ohne Status-Text | `tag_pulse` oder `tag_toggle` | `power_threshold` oder `external_boolean` |
| Vorgeschalteter Zähler (Shelly EM o.ä.) statt Wallbox-eigenem Zähler | beliebig | `power_threshold` (wenn Leistung verfügbar) sonst `energy_delta` |
| Wallbox ganz ohne eigenen Zähler und ohne Statusausgabe | `tag_hold`/`tag_pulse`/`tag_toggle` | `energy_delta` (mit externem Zähler als `sensor_energy`) |
| Reines Monitoring ohne Zugriffskontrolle (keine RFID-Pflicht) | `none` | `power_threshold`, `energy_delta` oder `external_boolean` |
| Eigene, selbst gebaute HA-Logik (Template mit Hysterese/Sonderfällen) | beliebig | `external_boolean` |

**Werte für `auth_mode`** (wer darf laden):

| Wert | Bedeutung |
|---|---|
| `tag_hold` | Tag liegt an, solange geladen wird |
| `tag_pulse` | Tag-Event nur kurz sichtbar (z.B. Wallbox setzt selbst zurück), Ende kommt aus `state_mode` |
| `tag_toggle` | 1. autorisierter Tap = Start, 2. Tap = Ende — unabhängig vom `state_mode` |
| `none` | keine Autorisierungspflicht, Start kommt rein aus `state_mode` (reines Logging/Monitoring) |

**Werte für `state_mode`** (wann wird geladen/beendet):

| Wert | Bedeutung | zusätzliche Optionen (nur bei diesem Wert relevant) |
|---|---|---|
| `state_keywords` | Substring-Match gegen `sensor_state` (Alfen-Standard) | `end_keywords`, `pause_keywords` (leer = Alfen-Defaults) |
| `power_threshold` | Ableitung aus einem Leistungssensor (z.B. vorgeschalteter Shelly EM ohne eigenen Wallbox-Status) | `power_sensor`, `power_threshold_w`, `end_idle_minutes` |
| `energy_delta` | Ende, wenn der kumulative Zähler `end_idle_minutes` lang stillsteht — für Wallboxen ganz ohne Status- oder Leistungssignal | `end_idle_minutes` |
| `external_boolean` | eine on/off-Entity (`active_entity`) bestimmt Start/Ende direkt — z.B. eine selbst gebaute HA-Template-Entity, die Leistung, Hysterese und eigene Pause-Logik kombiniert | `active_entity` |

**Beispiel — Shelly EM als vorgeschalteter Zähler + separater RFID-Leser, ohne Wallbox-Status:**

```yaml
wallbox_profile: custom
auth_mode: tag_pulse
sensor_rfid: sensor.nfc_reader_tag
sensor_energy: sensor.shelly_em_total_kwh
state_mode: power_threshold
power_sensor: sensor.shelly_em_power
power_threshold_w: 200
end_idle_minutes: 10
```

**Beispiel — Tag startet nur, eine selbst gebaute Template-Entity meldet das Ende:**

```yaml
wallbox_profile: custom
auth_mode: tag_pulse
sensor_energy: sensor.shelly_em_total_kwh
state_mode: external_boolean
active_entity: binary_sensor.wallbox_charging_active
```

## Voraussetzungen

- Home Assistant Core mit Alfen-Eve-Integration (Standardprofil) oder einer beliebigen anderen Wallbox/Zähler-Kombination (Custom-Profil, siehe oben)
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
