# Wallbox-Dolibarr — Ausführliche Installationsanleitung

Diese Anleitung beschreibt die komplette Inbetriebnahme der RFID-basierten
Wallbox-Abrechnung mit **Home Assistant** (Erfassung) + **Dolibarr** (Abrechnung).

System-Übersicht:

```
Wallbox  ──(Modbus/REST/HTTP via HA-Integration)──►  Home Assistant
                                                          │
                                                          │  Session JSON (RFID-Hash, kWh, Zeitstempel)
                                                          ▼
                                                    Dolibarr REST-API
                                                          │
                                                          ▼
                                                  llx_wallbox_sessions  →  Monats-Cronjob  →  Abrechnung
```

> **Hinweis zur Verlustsicherheit:** Das HA-Addon puffert Sessions lokal in
> SQLite (WAL-Mode) und sendet sie erst, wenn Dolibarr erreichbar ist.
> Dolibarr prüft beim Einlegen jeder Session per `(rfid_hash, start_time,
> end_time)` auf Duplikate. Solange der HA-Buffer intakt ist, geht kein
> Ladevorgang verloren — auch bei Netzausfall.

---

## 1 — Voraussetzungen

| Komponente              | Mindestens          | Empfohlen           |
|-------------------------|---------------------|---------------------|
| Dolibarr                | 19.0                | 21.x oder 22.x      |
| PHP                     | 7.4                 | 8.2                 |
| MariaDB / MySQL         | 10.5 / 8.0          | 10.11 / 8.0.30+     |
| Home Assistant Core     | 2024.6              | aktuelles Stable    |
| Python (im HA-Addon)    | 3.13                | 3.13                |
| Wallbox                 | beliebig, sofern HA die Entitäten `power`, `energy`, `rfid` liefert |

Auf der Dolibarr-Seite müssen folgende Module aktiv sein:
- **API REST** (kommt mit Dolibarr, muss aber unter *Module → Multi-Module → API REST* aktiviert sein)
- **Geplante Aufgaben (Cron)** — sonst läuft die Monats-Abrechnung nicht
- Optional: Modul *Rechnungen* / *Drittfirmen* für PDF-Versand

---

## 2 — Dolibarr-Modul installieren

### 2.1 ZIP hochladen

1. Anmelden als **Admin** in Dolibarr.
2. *Home → Setup → Module/Anwendungen → Bereitstellen / installieren externes Modul*.
3. Datei `module_wallboxbilling-1.0.0.zip` (im Projekt-Root) auswählen → **Hochladen**.
4. Dolibarr entpackt nach `htdocs/custom/wallboxbilling/`.

Alternativ manuell auf dem Server (z. B. via SSH):

```bash
# [VPS]
cd /var/www/html/custom            # je nach Installation, ggf. /usr/share/dolibarr/htdocs/custom
unzip /tmp/module_wallboxbilling-1.0.0.zip
chown -R www-data:www-data wallboxbilling/
```

Stellen Sie sicher, dass `htdocs/custom/` in `htdocs/conf/conf.php` als externer
Modulpfad geführt wird:

```php
$dolibarr_main_url_root_alt = '/custom';
$dolibarr_main_document_root_alt = '/var/www/html/custom';
```

### 2.2 Modul aktivieren

1. *Setup → Module/Anwendungen* → Tab **Andere** (oder per Suche „Wallbox").
2. Auf den Schalter neben **Wallbox Billing** klicken.
   - Dolibarr ruft jetzt `modWallboxbilling::init()` auf:
     - Tabellen werden angelegt: `llx_wallbox_sessions`, `llx_wallbox_rfid`,
       `llx_wallbox_billing_history`.
     - Indizes werden angelegt (Duplikat-Warnungen sind harmlos).
     - Berechtigungen werden registriert.
     - Cronjob *Wallbox Monthly Billing* wird eingetragen (Tab *Tools → Cron*).

> Wenn die Aktivierung mit `Error during initialization of module` abbricht:
> Logfile `documents/dolibarr.log` prüfen — meist fehlende Schreibrechte auf
> dem DB-User oder eine vorhandene Tabelle aus einer alten Installation.

### 2.3 Berechtigungen vergeben

Unter *Benutzer & Gruppen → [Benutzer] → Berechtigungen* die drei neuen
Rechte verteilen:

| Recht-Key                  | Wer braucht es?                           |
|----------------------------|--------------------------------------------|
| `wallboxbilling.user`      | jeder Mitarbeiter, der seine Sessions sehen darf |
| `wallboxbilling.admin`     | Verwaltung von RFID-Karten + Setup        |
| `wallboxbilling.billing`   | Buchhaltung — darf Monatsabrechnungen erzeugen / freigeben |

---

## 3 — API-Token für das HA-Addon

1. Anmelden als *technischer* Benutzer (oder ein dedizierter Service-User).
2. *Persönlicher Bereich (oben rechts) → Benutzerkarte → Tab „API"*.
3. **DOLAPIKEY** generieren — den Wert kopieren (wird **einmalig** angezeigt).
4. Token sicher im HA-Addon (Schritt 4.3) hinterlegen.

> Best Practice: einen Service-User `ha-wallbox` anlegen, dem nur die Rechte
> `wallboxbilling.user` + API-Zugriff gegeben werden. So bleiben Audit-Logs
> sauber und der Token kann jederzeit rotiert werden, ohne andere Workflows
> zu brechen.

API-Endpoint testen (alle Tokens redacted):

```bash
# [LOKAL] - sollte 'status: ok' liefern
curl -H "DOLAPIKEY: <TOKEN>" \
  https://dolibarr.example.com/api/index.php/wallboxbilling/health
```

---

## 4 — Home Assistant Addon einrichten

Das Addon ist ein **echtes Home Assistant Supervisor Add-on** (mit `config.yaml`
+ `build.json` + `Dockerfile`), nicht ein freistehender Docker-Container.
Damit gibt es drei Wege zur Installation — empfohlen: **via GitHub-Repository**
(Abschnitt 4.A). Manuelle Installation in `/addons/local/` ist als Fallback
in Abschnitt 4.B beschrieben.

### 4.A — Installation via GitHub-Repository (empfohlen)

Das ist der einfachste und HA-konforme Weg: Sie pushen das `Homeassistant/`-
Verzeichnis als HA-Addon-Repository auf GitHub, und HA übernimmt
Installation + Updates automatisch.

#### 4.A.1 GitHub-Repository vorbereiten

Ein HA-Addon-Repository hat folgende Struktur (jedes Addon liegt in einem
**Unterverzeichnis**):

```
github.com/iron-exx/evcharge-dolibarr-invoice   ← Repository (bereits angelegt)
├── repository.yaml                              ← Repo-Metadaten für HA
├── README.md
├── INSTALL.md
├── module_wallboxbilling-1.0.0.zip              ← Dolibarr-Modul (Releases)
├── Dolibarr/                                    ← Dolibarr Modul-Quellcode
│   └── htdocs/custom/wallboxbilling/
└── wallbox-dolibarr/                            ← HA-Addon (Ordner = Slug!)
    ├── config.yaml
    ├── build.json
    ├── Dockerfile
    ├── main.py
    ├── api_client.py
    ├── session_manager.py
    ├── utils/hash.py
    ├── requirements.txt
    └── README.md
```

> **Wichtig:** Der Slug (`wallbox-dolibarr`) im Ordnernamen **muss** mit
> `slug:` in `config.yaml` übereinstimmen — sonst findet HA das Addon nicht.

Das Repository ist bereits unter `https://github.com/iron-exx/evcharge-dolibarr-invoice`
eingerichtet — keine weiteren Schritte nötig.

#### 4.A.2 Repository in Home Assistant einbinden

1. HA öffnen → *Einstellungen → Add-ons → Add-on Store* (unten rechts).
2. Drei-Punkte-Menü (⋮) oben rechts → **Repositories**.
3. URL eingeben: `https://github.com/iron-exx/evcharge-dolibarr-invoice` → **Hinzufügen**.
4. Store-Seite neu laden → Sektion **„Wallbox-Dolibarr Add-ons"** erscheint
   mit dem Addon „Wallbox-Dolibarr Addon" darin.

#### 4.A.3 Addon installieren

1. Addon antippen → **Installieren** (HA baut das Image — kann 2–5 Min.
   dauern; läuft auf Pi5 deutlich schneller als auf Pi3).
2. Tab **Konfiguration** → Optionen ausfüllen (siehe 4.C unten).
3. Tab **Info** → **Start** + **Bei Boot starten** + **Watchdog** aktivieren.
4. Tab **Protokolle** beobachten.

#### 4.A.4 Updates ausrollen

```bash
# [LOKAL]
# wallbox-dolibarr/config.yaml: version: "1.0.1"  hochzählen
git commit -am "release: v1.0.1" && git push
```

HA erkennt die neue Version automatisch innerhalb von ~24h (oder sofort über
*Store → Check for updates*). Im Addon erscheint ein Update-Knopf.

---

### 4.B — Manuelle Installation (Fallback)

Nur sinnvoll, wenn kein GitHub-Account verfügbar ist oder das Setup
komplett offline laufen muss.

#### 4.B.1 Dateien kopieren

```bash
# [VPS auf dem HA-Host — SSH via "Terminal & SSH" Addon oder direkt]
cd /addons
git clone https://github.com/iron-exx/evcharge-dolibarr-invoice.git
# HA braucht nur das wallbox-dolibarr/ Unterverzeichnis:
ln -s evcharge-dolibarr-invoice/wallbox-dolibarr wallbox-dolibarr 2>/dev/null || true
```

`/addons` ist der Standard-Pfad für lokale Add-ons in HAOS — bei Container-
oder Supervised-Installationen ggf. `/usr/share/hassio/addons/local`.

#### 4.B.2 Local Add-on Store neu laden

1. HA → *Einstellungen → Add-ons → Add-on Store* → ⋮ → **Reload**.
2. Unter Sektion „Local add-ons" erscheint **„Wallbox-Dolibarr Addon"**.
3. Installieren → konfigurieren (4.C) → starten.

Nachteil: Updates müssen jedes Mal manuell per `scp` neu übertragen werden.

---

### 4.C — Konfigurationsoptionen

Im Addon-Tab **Konfiguration** (egal ob 4.A oder 4.B installiert) folgende
Werte setzen — Vorlage ist `Homeassistant/config.yaml`:

```yaml
log_level: INFO

# Mehrere Wallboxen möglich (EXT-01)
wallboxes:
  - id: "alfen_eve_garage"          # frei wählbar, wird in Dolibarr in
                                     #   llx_wallbox_sessions.wallbox_id eingetragen
    name: "Alfen Eve (Garage)"
    enabled: true
    default: true

# Abwärtskompatibilität: wenn nur eine Wallbox, kann wallbox_id allein gesetzt werden
wallbox_id: "alfen_eve_garage"

# Nur akzeptierte RFID-Karten (Klartext-Hex, wird vor Speicherung gehasht)
rfid_whitelist:
  - "EFCD083E"
  - "12AB34CD"

api:
  dolibarr_url:      "https://dolibarr.example.com"
  api_token:         "<DOLAPIKEY aus Abschnitt 3>"
  transmit_interval: 300          # Sekunden — alle 5 Min. Buffer an Dolibarr senden
  timeout:           30           # Sekunden pro API-Call
```

> **Token-Hygiene:** `api_token` direkt im Klartext einzutragen ist ok, weil
> die Addon-Konfiguration im HA-Supervisor verschlüsselt abgelegt wird und
> nur Admin-User sie sehen können. Trotzdem **niemals** dieses YAML in Git
> einchecken — die hier abgedruckte Vorlage enthält absichtlich nur einen
> Platzhalter.

#### 4.C.1 Wallbox-Entitäten in HA

Das Addon liest die Werte über die HA-Websocket-API von vorhandenen
Entitäten. Mindestens nötig pro Wallbox:

| Funktion                       | Beispiel-Entity (Alfen)                       |
|--------------------------------|------------------------------------------------|
| Aktuell genutzte RFID-Karte    | `sensor.alfen_eve_rfid_tag`                    |
| Energiezähler (kumuliert kWh)  | `sensor.alfen_eve_energy_total`                |
| Aktuelle Leistung (W oder kW)  | `sensor.alfen_eve_active_power`                |
| Ladezustand                    | `sensor.alfen_eve_charging_state` (`charging` / `idle`) |

Diese Entitäten müssen **vorher** über die HA-Hersteller-Integration in HA
existieren (Alfen/Wallbox/Easee/KEBA/go-eCharger/EVCC/Modbus — je nach
Wallbox). Das Addon selbst spricht **keine** Wallbox-Hardware direkt an.

### 4.D — Start + erste Logs

Nach Klick auf **Start** sollten im Tab **Protokolle** in dieser Reihenfolge
erscheinen:

```
[INFO] Loaded config: 1 wallbox(es), 2 whitelisted RFIDs
[INFO] Connected to Home Assistant WebSocket API
[INFO] Subscribed to state changes for sensor.alfen_eve_*
[INFO] Buffer DB opened: /data/wallbox_sessions.sqlite (WAL mode)
[INFO] Dolibarr health: ok (module=wallboxbilling version=1.0.0)
[INFO] Transmit loop started (interval=300s)
```

Wenn `Dolibarr health: …` fehlt oder einen Fehler liefert: Token + URL in
4.C prüfen, dann Addon **Neustarten**.

### 4.E — Buffer-Persistenz (Verlustsicherheit)

Das Addon speichert in `/data/wallbox_sessions.sqlite` (im HA-Addon-Volume,
also persistent über Container-Neustarts hinweg) **jede** erkannte Session
*zuerst lokal*, bevor sie an Dolibarr geht. Erst wenn Dolibarr mit
`{success:true}` antwortet, wird die Session als `transmitted_at` markiert.

Folgen:

- HA-Reboot mitten in einem Ladevorgang → Session wird beim nächsten Start
  aus dem Buffer rekonstruiert.
- Dolibarr offline → Sessions stapeln sich im Buffer, werden periodisch
  retried.
- Wenn das HA-System komplett ausfällt: Backup von `/data/wallbox_sessions.sqlite`
  (siehe Security-Checkliste Abschnitt 10) re-importieren → keine
  Vorgänge verloren.

Direkt einsehen während Betrieb:

```bash
# [VPS — innerhalb des Addon-Containers]
docker exec -it $(docker ps -qf "name=wallbox-dolibarr") \
  sqlite3 /data/wallbox_sessions.sqlite \
  "SELECT id, rfid_hash, kwh, transmitted_at FROM sessions ORDER BY id DESC LIMIT 10;"
```

---

## 5 — Ablauf eines Ladevorgangs (End-to-End-Test)

1. RFID-Karte an die Wallbox halten → HA-Entität `rfid_entity` ändert sich.
2. Auto laden → `power_entity` > 0, `energy_entity` zählt hoch.
3. Karte erneut halten oder Stecker ziehen → `status_entity` wechselt auf `idle`.
4. Das Addon schließt die Session: berechnet `kwh = end_energy − start_energy`,
   hasht das RFID per SHA-256, sendet JSON an Dolibarr.
5. Dolibarr legt Zeile in `llx_wallbox_sessions` an, antwortet `{success:true, id:N}`.
6. Sichtbar in Dolibarr unter *Wallbox Billing → Ladevorgänge*.

Sanity-Check via SQL:

```sql
-- [VPS]
SELECT rowid, fk_user, SUBSTRING(rfid_hash,1,12) AS rfid,
       start_time, end_time, kwh, status, transmitted_at
FROM   llx_wallbox_sessions
ORDER  BY rowid DESC LIMIT 10;
```

---

## 6 — RFID-Karten Benutzern zuordnen

1. *Wallbox Billing → Setup* öffnen (`/custom/wallboxbilling/admin.php`).
2. In der Benutzerliste pro Mitarbeiter:
   - **RFID Hex** = der auf der Karte aufgedruckte / per Reader ausgelesene Wert (z. B. `EFCD083E`).
   - **Preis pro kWh** = Strompreis (z. B. `0.30` netto — Steuer-Logik nachgelagert).
   - **Kostenstelle** (optional) für DATEV-Export.
3. **Speichern** → Dolibarr speichert nur den SHA-256-Hash (Datenschutz: kein
   Klartext-RFID in der DB).

> Sessions, die *vor* der Zuordnung entstanden sind, werden bei der ersten
> Zuordnung **nachträglich** dem User zugewiesen (über den Hash-Match). Es geht
> kein Ladevorgang verloren — er ist nur bis zur Zuordnung „unverteilt"
> (`fk_user = 0`).

---

## 7 — Monatliche Abrechnung

### 7.1 Cron prüfen

*Tools → Geplante Aufgaben* — der Job *Wallbox Monthly Billing* muss
**aktiv** sein. Standardlauf: alle ~30 Tage.

Manuelle Auslösung (zum Testen):

- In der Web-UI: Job auswählen → **Jetzt ausführen**.
- Aus der CLI:

```bash
# [VPS]
php /var/www/html/scripts/cron/dolibarr_cron_run.php
```

### 7.2 Was passiert beim Lauf?

1. `WallboxBillingCron::runMonthlyBilling()` rechnet alle Sessions des
   **Vormonats** mit `status = 'completed'` ab.
2. Pro `fk_user` + `price_per_kwh` wird eine Zeile in
   `llx_wallbox_billing_history` erzeugt (`UNIQUE(fk_user, billing_month,
   billing_year)` verhindert Doppelabrechnung).
3. Die JSON-Liste aller Einzel-Sessions wird in `session_details` archiviert
   — dadurch ist die Abrechnung *immer* nachvollziehbar, auch wenn später
   einzelne Sessions gelöscht würden.

### 7.3 Export (CSV / DATEV)

*Tools → Exporte → Wallbox Billing* — liefert wahlweise:

- **CSV** mit Spalten `user, month, kwh, price, total_cost, session_count`
- **DATEV EXTF** (Buchungsstapel) mit SKR03-Konten (vorkonfiguriert; falls
  SKR04 / eigener Kontenrahmen, in der Export-Klasse anpassen).

---

## 8 — Fehlersuche

| Symptom                                           | Wahrscheinliche Ursache & Behebung                                                 |
|---------------------------------------------------|------------------------------------------------------------------------------------|
| `401 Missing DOLAPIKEY` im HA-Log                 | Token in `config.yaml` falsch / Header-Name muss `DOLAPIKEY` (Großbuchstaben) sein |
| `Invalid rfid_hash format`                        | Addon sendet Klartext statt Hash — `utils/hash.py` prüfen                          |
| Sessions stehen in HA-Buffer, kommen nicht in DB  | Dolibarr-API nicht erreichbar; `curl … /health` testen, Firewall, base_url prüfen   |
| Cron läuft nicht                                  | *Setup → Sicherheit → System-Cron* aktivieren + auf dem Host Cron-Eintrag einrichten |
| `Error: Table 'llx_wallbox_sessions' doesn't exist` | Modul wurde nicht über die UI aktiviert — Aktivierung wiederholen, dann läuft `init()` |
| Doppelte Abrechnungen                             | Sollte nicht passieren wegen `UNIQUE KEY uk_user_month_year`; falls doch: Zeile in `llx_wallbox_billing_history` löschen und Cron erneut starten |
| Modul-Aktivierung schlägt mit Fatal Error fehl    | Vor dem Fix-Stand v1.0.0 hatte `init()` eine Endlos-Rekursion. Aktuelle ZIP einsetzen. |

Log-Quellen:

```bash
# [VPS — Dolibarr]
tail -f /var/www/html/documents/dolibarr.log

# [VPS — HA-Addon: über den Supervisor-Container]
ha addons logs local_wallbox-dolibarr --follow              # bei HAOS
# oder direkt am Docker-Daemon:
docker logs -f $(docker ps -qf "name=wallbox-dolibarr")
```

---

## 9 — Upgrade auf eine neue Modul-Version

1. Neue ZIP in *Setup → Module → Deploy external module from file* hochladen.
2. Dolibarr fragt, ob überschrieben werden soll → **Ja**.
3. Modul kurz **deaktivieren** + **wieder aktivieren** → `init()` läuft erneut
   und legt fehlende Spalten / Indizes nach (idempotent dank
   `CREATE TABLE IF NOT EXISTS` + ignore-on-duplicate für Indizes).
4. Daten bleiben erhalten — `init()` legt nur an, löscht nichts.

> Niemals *Module entfernen* in der Web-UI klicken, wenn produktive Daten
> existieren — das ruft `remove()` und löscht die Permissions / Cron-Einträge,
> aber Dolibarr fragt auch nach DROP TABLE. Stattdessen nur deaktivieren.

---

## 10 — Sicherheits-Checkliste

- [ ] `DOLAPIKEY` ist nicht im Git eingecheckt (HA `secrets.yaml`).
- [ ] Dolibarr läuft **ausschließlich** über HTTPS (Reverse-Proxy / Let's Encrypt).
- [ ] DB-Backup des Dolibarr-Schemas inklusive `llx_wallbox_*` ist im Backup-Job enthalten.
- [ ] HA-Buffer (`/data/wallbox_sessions.sqlite` im Addon-Volume) wird ebenfalls gesichert — fängt Netzausfall ab. *Snapshot-Tipp:* Das HA-Backup (*Einstellungen → System → Backups*) erfasst das Addon-Volume automatisch, wenn das Addon zum Backup-Zeitpunkt ausgewählt ist.
- [ ] Service-User für die API hat **nur** `wallboxbilling.user`-Recht, keinen Admin-Zugang.
- [ ] Klartext-RFIDs werden **nirgendwo** geloggt (im Code ist nur `substr(hash,0,16)…` geloggt).

---

Fertig. Erste produktive Abrechnung läuft frühestens am 1. des Folgemonats —
bis dahin am besten 2–3 Test-Ladevorgänge laufen lassen und manuell den
Cron-Job triggern, um den End-to-End-Flow zu verifizieren.
