# Contributing

## Entwicklungsumgebung einrichten

### 1. Dolibarr Dev-Umgebung

```bash
# Dolibarr via Docker
docker run -d -p 8080:80 -v $(pwd)/htdocs:/var/www/html dolibarr/dolibarr:latest

# Modul symlinken
ln -s $(pwd)/wallboxbilling /var/www/html/htdocs/custom/
```

### 2. HA Addon Dev-Umgebung

```bash
# Python Abhängigkeiten
pip install -r wallbox-dolibarr/requirements.txt

# Lokal testen
python3 wallbox-dolibarr/main.py --test
```

## Code-Style

- **PHP:** PSR-12, Dolibarr Conventions
- **Python:** PEP 8, Typ-Hints bevorzugt
- **Kommentare:** Deutsch (im Code)

## Commits

```
feat: Neue Funktion
fix: Bugfix
docs: Dokumentation
refactor: Code-Umstrukturierung
test: Tests hinzugefügt
```

## Testing

### PHP Syntax prüfen
```bash
find . -name "*.php" -exec php -l {} \;
```

### Python Syntax prüfen
```bash
python3 -m py_compile wallbox-dolibarr/*.py
```

## Pull Requests

1. Fork erstellen
2. Feature Branch erstellen (`git checkout -b feature/xyz`)
3. Änderungen commiten
4. Pushen und PR erstellen