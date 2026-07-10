#!/usr/bin/env python3
"""
Session Manager für Wallbox-Ladevorgänge

Verwaltet SQLite-Datenbank für Lade-Sessions:
- Session-Start (RFID-Validierung, Whitelist-Check)
- Session-Ende (Energie-Berechnung, Zeitstempel)
- RFID-Debouncing (5-10 Sekunden Unterdrückung)
- Persistenz für Neustart-Überlebung
"""
import sqlite3
import time
import logging
from typing import Optional, Dict, Any
from datetime import datetime, timedelta

# Hash-Utility importieren
import sys
sys.path.insert(0, '/usr/local/bin')
from utils.hash import hash_rfid, verify_rfid_hash

# Debounce-Zeit in Sekunden (HA-07)
DEBOUNCE_SECONDS = 7


def format_iso8601(dt: Any) -> str:
    """
    Konvertiert datetime zu ISO 8601 String mit Zeitzone

    Args:
        dt: datetime-Objekt oder String

    Returns:
        ISO 8601 formatierter String

    Note:
        Wenn dt bereits ein String ist, wird er unverändert zurückgegeben
    """
    if isinstance(dt, str):
        return dt

    if isinstance(dt, datetime):
        return dt.strftime("%Y-%m-%dT%H:%M:%S%z")

    return str(dt)

class SessionManager:
    """Verwaltet Lade-Sessions in SQLite"""

    def __init__(self, db_path: str = "/data/sessions.db"):
        self.db_path = db_path
        self._logger = logging.getLogger(__name__)  # muss vor _init_database() stehen
        self._last_rfid_time: Dict[str, float] = {}  # Für Debouncing
        self._init_database()

    def _init_database(self):
        """Initialisiert die SQLite-Datenbank mit Sessions-Tabelle (PER-01, DB-01 Vorbereitung)"""
        conn = sqlite3.connect(self.db_path)
        cursor = conn.cursor()

        # WAL Mode aktivieren für bessere Concurrenty (PER-02)
        cursor.execute("PRAGMA journal_mode=WAL")
        cursor.execute("PRAGMA synchronous=NORMAL")
        cursor.execute("PRAGMA busy_timeout=5000")

        # Tabelle für Lade-Sessions (HA-03, Felder für Phase 2)
        cursor.execute('''
            CREATE TABLE IF NOT EXISTS sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                rfid_hash TEXT NOT NULL,
                wallbox_id TEXT NOT NULL DEFAULT 'alfen_eve',
                start_time TEXT NOT NULL,
                end_time TEXT,
                start_energy_kwh REAL NOT NULL DEFAULT 0.0,
                end_energy_kwh REAL,
                total_kwh REAL,
                status TEXT NOT NULL DEFAULT 'active',
                created_at TEXT NOT NULL,
                transmitted_at TEXT,
                start_energy_valid INTEGER NOT NULL DEFAULT 1
            )
        ''')

        # Migrationen für bereits existierende Tabellen (idempotent)
        for col_ddl, col_name in [
            ('ALTER TABLE sessions ADD COLUMN transmitted_at TEXT', 'transmitted_at'),
            ('ALTER TABLE sessions ADD COLUMN start_energy_valid INTEGER NOT NULL DEFAULT 1', 'start_energy_valid'),
            ('ALTER TABLE sessions ADD COLUMN login TEXT', 'login'),
        ]:
            try:
                cursor.execute(col_ddl)
                self._logger.info("Datenbank-Schema erweitert: %s hinzugefügt", col_name)
            except sqlite3.OperationalError:
                pass  # Spalte existiert bereits

        # Index für rfid_hash (DB-02 Vorbereitung)
        cursor.execute('''
            CREATE INDEX IF NOT EXISTS idx_rfid_hash ON sessions(rfid_hash)
        ''')

        # Index für status (für aktive Sessions)
        cursor.execute('''
            CREATE INDEX IF NOT EXISTS idx_status ON sessions(status)
        ''')

        conn.commit()
        conn.close()
        self._logger.info("SQLite Datenbank initialisiert mit WAL Mode: %s", self.db_path)

    def debounce_rfid(self, rfid_hex: str) -> bool:
        """
        Prüft ob RFID-Lesung innerhalb des Debounce-Intervalls liegt (HA-07)

        Args:
            rfid_hex: RFID als Hex-String

        Returns:
            True wenn RFID akzeptiert wird (nicht debounced)
        """
        current_time = time.time()
        rfid_hash = hash_rfid(rfid_hex)

        if rfid_hash in self._last_rfid_time:
            time_diff = current_time - self._last_rfid_time[rfid_hash]
            if time_diff < DEBOUNCE_SECONDS:
                self._logger.debug("RFID debounced: %s (%.1fs < %ds)",
                                rfid_hash[:16], time_diff, DEBOUNCE_SECONDS)
                return False

        self._last_rfid_time[rfid_hash] = current_time
        return True

    def is_rfid_authorized(self, rfid_hex: str, whitelist: list) -> bool:
        """
        Prüft ob RFID in der Whitelist ist (HA-02, HA-04)

        Args:
            rfid_hex: RFID als Hex-String
            whitelist: Liste der erlaubten RFID-Karten (aus config.yaml)

        Returns:
            True wenn RFID autorisiert ist
        """
        if not whitelist:
            self._logger.warning("Keine RFID-Whitelist konfiguriert")
            return False

        rfid_hash = hash_rfid(rfid_hex)

        # Whitelist enthält Hex-Strings, wir vergleichen Hashes
        for whitelisted_rfid in whitelist:
            if verify_rfid_hash(whitelisted_rfid, rfid_hash):
                self._logger.info("RFID autorisiert: %s...", rfid_hash[:16])
                return True

        self._logger.warning("RFID NICHT autorisiert: %s...", rfid_hash[:16])
        return False

    def get_active_session(self) -> Optional[Dict[str, Any]]:
        """
        Holt die aktuell aktive Session (für Neustart-Recovery, PER-01)

        Returns:
            Session-Dict oder None
        """
        conn = sqlite3.connect(self.db_path)
        conn.row_factory = sqlite3.Row
        cursor = conn.cursor()

        cursor.execute('''
            SELECT * FROM sessions
            WHERE status = 'active'
            ORDER BY start_time DESC
            LIMIT 1
        ''')

        row = cursor.fetchone()
        conn.close()

        if row:
            return dict(row)
        return None

    def recover_active_sessions(self) -> list:
        """
        Findet aktive Sessions beim Neustart (PER-02)
        Prüft ob Session noch aktiv oder abgeschlossen

        Returns:
            Liste der wiederhergestellten Sessions
        """
        conn = sqlite3.connect(self.db_path)
        conn.row_factory = sqlite3.Row
        cursor = conn.cursor()

        cursor.execute('''
            SELECT * FROM sessions
            WHERE status = 'active'
            ORDER BY start_time DESC
        ''')

        active_sessions = [dict(row) for row in cursor.fetchall()]
        conn.close()

        if active_sessions:
            self._logger.info("Gefundene aktive Sessions beim Start: %d", len(active_sessions))

        return active_sessions

    def mark_session_incomplete(self, session_id: int, reason: str = 'crash_recovery'):
        """
        Markiert eine Session als unvollständig (PER-03)

        Args:
            session_id: ID der Session
            reason: Grund für die Markierung
        """
        conn = sqlite3.connect(self.db_path)
        cursor = conn.cursor()
        cursor.execute('''
            UPDATE sessions
            SET status = 'incomplete', end_time = ?
            WHERE id = ?
        ''', (datetime.now().isoformat(), session_id))
        conn.commit()
        conn.close()
        self._logger.warning("Session %d als unvollständig markiert: %s", session_id, reason)

    def get_sessions_by_wallbox(self, wallbox_id: str) -> list:
        """
        Holt alle Sessions für eine spezifische Wallbox (EXT-01)

        Args:
            wallbox_id: ID der Wallbox

        Returns:
            Liste von Session-Dicts
        """
        conn = sqlite3.connect(self.db_path)
        conn.row_factory = sqlite3.Row
        cursor = conn.cursor()

        cursor.execute('''
            SELECT * FROM sessions
            WHERE wallbox_id = ?
            ORDER BY start_time DESC
        ''', (wallbox_id,))

        rows = cursor.fetchall()
        conn.close()

        return [dict(row) for row in rows]

    def start_session(self, rfid_hex: str, start_energy_kwh: float,
                      wallbox_id: str = "alfen_eve",
                      start_energy_valid: bool = True) -> Optional[int]:
        """
        Startet eine neue Lade-Session (HA-03, HA-04)

        Args:
            rfid_hex: RFID als Hex-String
            start_energy_kwh: Energie-Zählerstand bei Start
            wallbox_id: ID der Wallbox
            start_energy_valid: False wenn der Zählerstand beim Start unbekannt
                                war (Sensor lieferte keinen Wert) — Ende markiert
                                die Session dann als unvollständig statt zu rechnen.

        Returns:
            Session-ID oder None bei Fehler
        """
        # Prüfen ob bereits eine aktive Session läuft
        active = self.get_active_session()
        if active:
            self._logger.warning("Bestehende aktive Session: ID=%s", active['id'])
            return None

        rfid_hash = hash_rfid(rfid_hex)
        start_time = datetime.now().replace(microsecond=0).isoformat()
        created_at = datetime.now().replace(microsecond=0).isoformat()

        conn = sqlite3.connect(self.db_path)
        cursor = conn.cursor()

        cursor.execute('''
            INSERT INTO sessions (rfid_hash, wallbox_id, start_time, start_energy_kwh,
                                  status, created_at, start_energy_valid)
            VALUES (?, ?, ?, ?, 'active', ?, ?)
        ''', (rfid_hash, wallbox_id, start_time, start_energy_kwh, created_at,
              1 if start_energy_valid else 0))

        session_id = cursor.lastrowid
        conn.commit()
        conn.close()

        self._logger.info("Session gestartet: ID=%s, RFID=%s..., Energie=%.3f kWh (gültig=%s)",
                       session_id, rfid_hash[:16], start_energy_kwh, start_energy_valid)
        return session_id

    def end_session(self, end_energy_kwh: float, min_kwh: float = 0.05,
                    end_energy_valid: bool = True) -> Optional[Dict[str, Any]]:
        """
        Beendet die aktive Lade-Session.

        Args:
            end_energy_kwh:   Energie-Zählerstand bei Ende
            min_kwh:          Mindest-Verbrauch (Default 0.05 kWh) ab dem die
                              Session als echte Ladung gewertet wird. Sessions
                              unterhalb → 'discarded' (z.B. Karte gehalten ohne
                              Ladung).
            end_energy_valid: False wenn der End-Zählerstand unbekannt war.

        Status-Logik:
          - Start- ODER End-Zähler ungültig → 'incomplete' (SICHTBAR, nicht
            still verworfen — Admin kann manuell nachtragen).
          - Gültige Reads, aber < min_kwh → 'discarded' (echte Ghost-Session).
          - Sonst → 'completed' (wird übertragen).

        Returns:
            Session-Dict NUR bei 'completed', sonst None.
        """
        active = self.get_active_session()
        if not active:
            self._logger.warning("Keine aktive Session zum Beenden")
            return None

        end_time = datetime.now().replace(microsecond=0).isoformat()
        start_valid = int(active.get('start_energy_valid', 1)) == 1
        energy_trustworthy = start_valid and end_energy_valid
        total_kwh = max(0.0, end_energy_kwh - active['start_energy_kwh'])

        conn = sqlite3.connect(self.db_path)
        cursor = conn.cursor()

        # Fall 1: Energie-Readings nicht vertrauenswürdig → incomplete (sichtbar)
        if not energy_trustworthy:
            cursor.execute('''
                UPDATE sessions
                SET end_time = ?, end_energy_kwh = ?, total_kwh = ?, status = 'incomplete'
                WHERE id = ?
            ''', (end_time, end_energy_kwh if end_energy_valid else None,
                  total_kwh if energy_trustworthy else None, active['id']))
            conn.commit()
            conn.close()
            self._logger.warning(
                "Session %s unvollständig: Zählerstand ungültig (start_valid=%s, "
                "end_valid=%s) — kWh nicht berechenbar, bitte manuell prüfen/nachtragen",
                active['id'], start_valid, end_energy_valid
            )
            return None

        # Fall 2: gültige Reads aber zu wenig geladen → discarded (echte Ghost-Session)
        if total_kwh < min_kwh:
            cursor.execute('''
                UPDATE sessions
                SET end_time = ?, end_energy_kwh = ?, total_kwh = ?,
                    status = 'discarded', transmitted_at = ?
                WHERE id = ?
            ''', (end_time, end_energy_kwh, total_kwh, end_time, active['id']))
            conn.commit()
            conn.close()
            self._logger.info(
                "Session %s verworfen: nur %.3f kWh (< %.3f kWh) — keine echte Ladung",
                active['id'], total_kwh, min_kwh
            )
            return None

        # Fall 3: echte Ladung → completed
        cursor.execute('''
            UPDATE sessions
            SET end_time = ?, end_energy_kwh = ?, total_kwh = ?, status = 'completed'
            WHERE id = ?
        ''', (end_time, end_energy_kwh, total_kwh, active['id']))

        conn.commit()
        conn.close()

        completed_session = {
            'id': active['id'],
            'rfid_hash': active['rfid_hash'],
            'wallbox_id': active['wallbox_id'],
            'start_time': active['start_time'],
            'end_time': end_time,
            'start_energy_kwh': active['start_energy_kwh'],
            'end_energy_kwh': end_energy_kwh,
            'total_kwh': total_kwh
        }

        self._logger.info("Session beendet: ID=%s, Verbrauch=%.2f kWh",
                       active['id'], total_kwh)
        return completed_session

    def get_completed_sessions(self, limit: int = 10) -> list:
        """
        Holt abgeschlossene Sessions (für API-Übertragung an Dolibarr, Phase 3)

        Args:
            limit: Maximale Anzahl an Sessions

        Returns:
            Liste von Session-Dicts
        """
        conn = sqlite3.connect(self.db_path)
        conn.row_factory = sqlite3.Row
        cursor = conn.cursor()

        cursor.execute('''
            SELECT * FROM sessions
            WHERE status = 'completed'
            ORDER BY end_time DESC
            LIMIT ?
        ''', (limit,))

        rows = cursor.fetchall()
        conn.close()

        return [dict(row) for row in rows]

    def add_manual_session(self, kwh: float, wallbox_id: str,
                           session_date: str, rfid_hash: Optional[str] = None,
                           login: Optional[str] = None) -> Optional[int]:
        """
        Legt eine manuelle Session direkt als 'completed' an (für UI-Eingaben).

        Args:
            kwh:          Verbrauchte Energie in kWh
            wallbox_id:   Wallbox-ID aus der Konfiguration
            session_date: Datum als ISO-String (YYYY-MM-DD)
            rfid_hash:    SHA-256 Hash der RFID-Karte (physischer Tap-Ersatz)
            login:        Dolibarr-Login des Mitarbeiters (Auswahl per Name) —
                          genau eins von rfid_hash/login muss gesetzt sein

        Returns:
            Session-ID oder None bei Fehler
        """
        if not login and not rfid_hash:
            self._logger.error("add_manual_session: weder rfid_hash noch login angegeben")
            return None

        try:
            date_obj = datetime.fromisoformat(session_date)
        except (ValueError, TypeError):
            date_obj = datetime.now()

        # Aktuelle Uhrzeit verwenden — verhindert Duplikat-Kollisionen bei
        # mehreren manuellen Sessions am selben Tag (Dolibarr lehnt sonst
        # mit "Session already exists" ab, da Identität+start+end identisch)
        now_dt   = datetime.now().replace(microsecond=0)
        start_dt = date_obj.replace(
            hour=now_dt.hour,
            minute=now_dt.minute,
            second=now_dt.second,
            microsecond=0,
        )
        end_dt   = start_dt + timedelta(minutes=1)
        start_time = start_dt.isoformat()
        end_time   = end_dt.isoformat()
        now        = now_dt.isoformat()

        # rfid_hash-Spalte ist NOT NULL — bei Login-Auswahl einen klar erkennbaren
        # Platzhalter speichern, der NIE als echter Kartenwert an Dolibarr geht
        # (transmit_completed_sessions bevorzugt login, wenn gesetzt)
        stored_hash = rfid_hash or hash_rfid(f"__manual_login__:{login}")

        conn = sqlite3.connect(self.db_path)
        cursor = conn.cursor()
        cursor.execute('''
            INSERT INTO sessions
                (rfid_hash, login, wallbox_id, start_time, end_time,
                 start_energy_kwh, end_energy_kwh, total_kwh, status, created_at)
            VALUES (?, ?, ?, ?, ?, 0.0, ?, ?, 'completed', ?)
        ''', (stored_hash, login, wallbox_id, start_time, end_time, kwh, kwh, now))

        session_id = cursor.lastrowid
        conn.commit()
        conn.close()

        self._logger.info("Manuelle Session erstellt: ID=%s, %.3f kWh, Datum=%s",
                          session_id, kwh, session_date)
        return session_id

    def transmit_completed_sessions(self, api_client: Any) -> Dict[str, Any]:
        """
        Überträgt abgeschlossene (noch nicht übertragene) Sessions an Dolibarr

        Args:
            api_client: WallboxApiClient Instanz für API-Calls

        Returns:
            Dict mit transmitted, failed, errors
        """
        conn = sqlite3.connect(self.db_path)
        cursor = conn.cursor()

        # Sessions finden: abgeschlossen (NICHT discarded/incomplete) und noch nicht übertragen
        cursor.execute('''
            SELECT id, rfid_hash, wallbox_id, start_time, end_time, total_kwh, login
            FROM sessions
            WHERE status = 'completed'
              AND end_time IS NOT NULL
              AND transmitted_at IS NULL
        ''')

        rows = cursor.fetchall()

        result = {
            "transmitted": 0,
            "failed": 0,
            "errors": []
        }

        for row in rows:
            session_id = row[0]
            login = row[6] if len(row) > 6 else None
            session_data = {
                "wallbox_id": row[2],
                "start_time": format_iso8601(row[3]),
                "end_time": format_iso8601(row[4]),
                "kwh": row[5] if row[5] else 0.0
            }
            if login:
                session_data["login"] = login
            else:
                session_data["rfid_hash"] = row[1]

            # Session an Dolibarr übertragen
            success, error = api_client.transmit_session(session_data)

            if success:
                # transmitted_at setzen
                cursor.execute('''
                    UPDATE sessions SET transmitted_at = ? WHERE id = ?
                ''', (datetime.now().isoformat(), session_id))
                result["transmitted"] += 1
                self._logger.info("Session %s erfolgreich übertragen", session_id)
            else:
                error_msg = f"Session {session_id}: {error}"
                result["errors"].append(error_msg)
                result["failed"] += 1
                self._logger.error("Fehler bei Session %s: %s", session_id, error)

                # Bei Fehler: Schleife abbrechen (keine weiteren Transmissions)
                break

        conn.commit()
        conn.close()

        self._logger.info("API-Übertragung abgeschlossen: %s übertragen, %s fehlgeschlagen",
                         result["transmitted"], result["failed"])

        return result
