# Wallbox-Dolibarr Integration

RFID-basierte automatische Abrechnung von Wallbox-Ladevorgängen mit Home Assistant und Dolibarr.

![Version](https://img.shields.io/badge/Version-1.0.0-blue)
![Dolibarr](https://img.shields.io/badge/Dolibarr-21.x--22.x-green)
![Python](https://img.shields.io/badge/Python-3.13+-green)

## Features

### Home Assistant Addon
- ⭐ RFID-Authentifizierung mit SHA-256 Hash
- ⚡ Echtzeit-Session-Tracking
- 🔄 API-Transmission an Dolibarr
- 💾 SQLite mit WAL Mode für Crash-Recovery
- 🔁 Automatischer Neustart bei Addon-Absturz

### Dolibarr Modul
- 👥 User-Management mit RFID-Hash
- 📊 Monatliche automatische Abrechnung (Cron-Job)
- 📄 PDF-Rechnungen via TCPDF
- 📁 CSV-Export für externe Analyse
- 🇩🇪 DATEV EXTF Format für deutsche Buchhaltung
- 🔌 REST-API Endpoint für HA-Addon

## Systemübersicht

```
┌─────────────────┐     REST API      ┌─────────────────┐
│  Home Assistant │ ─────────────────► │    Dolibarr     │
│  Addon (Python) │                   │  Module (PHP)   │
│                 │                   │                 │
│  - Websocket    │   JSON (SHA-256)  │  - User mgmt    │
│  - RFID Track   │                   │  - Billing      │
│  - Session mgr  │                   │  - Invoicing    │
└─────────────────┘                   └─────────────────┘
```

## Voraussetzungen

| Komponente | Version | Anmerkung |
|------------|---------|-----------|
| Home Assistant | 2024.x+ | Mit Supervisor/Addon |
| Dolibarr | 21.x - 22.x | Mit TCPDF Modul |
| SQLite | 3.x | (im HA Addon enthalten) |
| Python | 3.13+ | (im HA Container) |

## Installation

### 1. Dolibarr Modul installieren

```bash
# Modul in Dolibarr htdocs/custom/ kopieren
cp -r wallboxbilling /var/www/html/htdocs/custom/

# Oder via Symlink falls Dolibarr woanders liegt
ln -s /path/to/wallboxbilling /var/www/html/htdocs/custom/wallboxbilling
```

**Im Dolibarr Admin:**
1. Gehe zu *Setup → Modules → Interfaces*
2. Suche nach "Wallbox-Abrechnung"
3. Klicke auf "Aktivieren"

Das Modul erstellt automatisch:
- `llx_wallbox_sessions` – Lade-Sessions
- `llx_wallbox_rfid` – RFID-Zuordnungen
- `llx_wallbox_billing_history` – Abrechnungshistorie

### 2. Home Assistant Addon

```bash
# Addon-Dateien nach /addons kopieren
mkdir -p /addons/local/wallbox_dolibarr
cp -r Homeassistant/* /addons/local/wallbox_dolibarr/
```

**In Home Assistant:**
1. *Settings → Add-ons → Add-on Store*
2.右上角 → "Add local repository"
3. Pfad: `/addons/local/wallbox_dolibarr`
4. Addon installieren und starten

## Konfiguration

### Dolibarr API Token erstellen

1. *Setup → Users → Benutzer wählen*
2. *Allow API access* aktivieren
3. API-Token kopieren (DOLAPIKEY)

### HA Addon config.yaml

```yaml
log_level: "INFO"

# RFID-Whitelist (Hex-Strings, SHA-256 wird intern berechnet)
rfid_whitelist:
  - "EFCD083E"
  - "A1B2C3D4"

# Wallbox-Konfiguration (optional - mehrere möglich)
wallboxes:
  - id: "alfen_eve"
    name: "Alfen Eve"
    enabled: true
    default: true

# Dolibarr API
api:
  dolibarr_url: "https://doli.meinedomain.de"
  api_token: "your_dolapikey_here"
  transmit_interval: 300  # Sekunden
  timeout: 30
```

## Funktionsweise

### Session-Tracking (HA)

1. RFID wird an der Wallbox gelesen
2. SHA-256 Hash wird gebildet
3. Whitelist-Prüfung (7s Debounce)
4. Session in SQLite gestartet
5. Bei Ladeende: Session beendet, an Dolibarr übertragen

### Abrechnung (Dolibarr)

1. Cron-Job läuft monatlich (1. des Monats)
2. Sessions nach User gruppiert
3. Kosten berechnet: kWh × Preis/kWh
4. PDF-Rechnung generiert
5. In Billing History gespeichert

## DATEV Export

```php
$config = array(
    'berater_nr' => '12345',
    'mandanten_nr' => '001',
    'buchungskreis' => '00'
);

$export->generateDatev($billings, '/path/to/export.csv', $config);
```

**Format:** EXTF 5.0
- Debitorenkonto: `1xxxxx` (10000 + User-ID)
- Umsatzkonto: `1400`
- Beträge in Cent

## Entwicklung

### Projektstruktur

```
Wallbox-Dolibarr/
├── wallboxbilling/          # Dolibarr Modul
│   ├── class/              # PHP Klassen
│   │   ├── billing.class.php
│   │   ├── export.class.php
│   │   └── wallboxbilling.class.php
│   ├── core/
│   │   ├── modules/
│   │   │   ├── modWallboxbilling.class.php
│   │   │   └── doc/pdf_wallboxbilling.class.php
│   │   └── modules/modWallboxbilling.class.php
│   ├── api/
│   ├── sql/
│   └── langs/
├── Homeassistant/           # HA Addon
│   ├── main.py             # Hauptskript
│   ├── session_manager.py  # Session-Tracking
│   ├── api_client.py       # Dolibarr API
│   ├── config.yaml         # Addon-Konfiguration
│   ├── Dockerfile
│   └── utils/
│       └── hash.py         # SHA-256 RFID
└── README.md
```

### PHP Tests (Dolibarr)

```bash
cd htdocs/custom/wallboxbilling
php -l class/billing.class.php
php -l class/export.class.php
```

### Python Tests (HA)

```bash
cd Homeassistant
python3 -m py_compile session_manager.py
python3 -m py_compile api_client.py
```

## Sicherheit

- ✅ RFID wird nur als SHA-256 Hash gespeichert
- ✅ API-Auth via DOLAPIKEY Token
- ✅ SQL-Injection geschützt via Prepared Statements
- ✅ Keine PII in öffentlichen Verzeichnissen

## Lizenz

MIT License -siehe LICENSE Datei

## Support

- GitHub Issues: https://github.com/dein-repo/wallbox-dolibarr/issues
- Dokumentation: https://doku.wiki/wallbox-dolibarr