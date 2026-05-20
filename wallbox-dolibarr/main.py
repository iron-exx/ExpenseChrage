#!/usr/bin/env python3
"""
Wallbox-Dolibarr Addon Hauptskript

Verbindet sich via Websocket API mit Home Assistant Core, abonniert die
drei Alfen-Eve-Sensoren (RFID-Tag, Zählerstand, Wallbox-Status) und
schreibt jede abgeschlossene Lade-Session direkt in die Dolibarr-
Spesenabrechnung des zugeordneten Mitarbeiters.

Datenfluss (Alfen Eve):
  sensor.alfen_eve_tag_socket_1            → RFID-Karte (z.B. "A1B2C3D4" / "No Tag")
  sensor.alfen_eve_meter_reading_socket_1  → Gesamt-Zählerstand kWh (kumulativ)
  sensor.alfen_eve_main_state_socket_1     → Wallbox-Status ("Available",
                                              "Charging Power On", "Finishing", …)

Session-Logik:
  • RFID-Wechsel auf bekannten Tag        → Session START
  • State-Wechsel auf "Available"/"Finishing"/"Stopped"/…  → Session ENDE
  • RFID-Wechsel auf "No Tag" (Karte ab)  → Session ENDE (Fallback)
  • Geladen = Zähler_END − Zähler_START
"""
import asyncio
import aiohttp
import json
import logging
import os
import sys
import yaml
from datetime import datetime
from typing import Dict, Any, Optional

# Hash-Utility importieren
sys.path.insert(0, '/usr/local/bin')
from utils.hash import hash_rfid

# Session Manager importieren
from session_manager import SessionManager

# API Client importieren (Phase 3)
from api_client import WallboxApiClient

# Ingress Web-Server für manuelle Sessions
from web_server import start_web_server

# Logging Setup (D-17, D-20)
LOG_LEVEL = os.getenv('LOG_LEVEL', 'INFO').upper()
logging.basicConfig(
    level=getattr(logging, LOG_LEVEL, logging.INFO),
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
_LOGGER = logging.getLogger(__name__)

# Alfen-Sensor-Standardwerte (überschreibbar in Addon-Konfiguration)
_DEFAULT_SENSOR_RFID   = "sensor.alfen_eve_tag_socket_1"
_DEFAULT_SENSOR_ENERGY = "sensor.alfen_eve_meter_reading_socket_1"
_DEFAULT_SENSOR_STATE  = "sensor.alfen_eve_main_state_socket_1"

# RFID-Werte die als "keine Karte" interpretiert werden
_RFID_NONE_VALUES = {'', 'no tag', 'no_tag', 'none', 'unknown', 'unavailable'}

# Wallbox-Status: substring-Match (case-insensitive) gegen den echten Sensor-Wert.
# Alfen-Wallbox-States: "Available", "Preparing", "Charging Power On",
# "Charging Stopped", "Suspended EV", "Suspended EVSE", "Finishing",
# "Reserved", "Unavailable", "Faulted". Wir gruppieren nach Verhalten:
def _is_charging_state(s):
    """True wenn die Wallbox aktiv lädt (Energie fließt)."""
    if not s:
        return False
    sl = s.lower()
    return 'charging' in sl and 'stopped' not in sl

def _is_idle_state(s):
    """True wenn die Wallbox keine Session mehr aktiv hat (Ladung abgeschlossen,
    Stecker raus oder Karte entfernt)."""
    if not s:
        return False
    sl = s.lower()
    return any(k in sl for k in [
        'available', 'idle', 'finished', 'finishing', 'stopped',
        'disconnected', 'unavailable', 'faulted'
    ])

# Globale Variablen für Session-Tracking
session_manager = None
current_config = {}
ha_ws = None
api_client = None
api_state = None  # Live-Zustand für Web-Server (current_energy, wallbox_state)


def load_config():
    """Lädt Addon-Konfiguration aus /data/options.json (D-04)"""
    config_path = '/data/options.json'
    try:
        with open(config_path, 'r') as f:
            config = json.load(f)
            _LOGGER.info("Konfiguration geladen von %s", config_path)

            # API-Konfiguration validieren (Task 3)
            api_config = config.get('api', {})
            if api_config:
                dolibarr_url = api_config.get('dolibarr_url', '')
                if dolibarr_url and not (dolibarr_url.startswith('http://') or dolibarr_url.startswith('https://')):
                    _LOGGER.warning("API-Konfiguration: dolibarr_url muss mit http:// oder https:// beginnen")

                api_token = api_config.get('api_token', '')
                if not api_token or api_token == 'your_dolapikey_here':
                    _LOGGER.warning("API-Token nicht konfiguriert oder noch Default-Wert")

            return config
    except Exception as e:
        _LOGGER.error("Fehler beim Laden der Konfiguration: %s", e)
        return {}


class HomeAssistantWebsocket:
    """Verbindung zur Home Assistant Websocket API (D-02, D-10)"""

    def __init__(self, host: str = "homeassistant", port: int = 8123, token: str = ''):
        self.host = host
        self.port = port
        self.ws_url = f"ws://{host}:{port}/api/websocket"
        self.access_token = token or os.getenv('SUPERVISOR_TOKEN', '')
        self.session_id: Optional[str] = None
        self._ws: Optional[aiohttp.ClientWebSocketResponse] = None
        self._session: Optional[aiohttp.ClientSession] = None

    async def connect(self):
        """Verbindet sich mit HA Websocket API"""
        self._session = aiohttp.ClientSession()
        try:
            self._ws = await self._session.ws_connect(self.ws_url)
            _LOGGER.info("Verbunden mit HA Websocket API: %s", self.ws_url)

            # Auth-Response empfangen
            msg = await self._ws.receive_json()
            if msg.get('type') != 'auth_required':
                raise ConnectionError("Unerwartete Antwort von HA")

            # Auth senden
            await self._ws.send_json({
                'type': 'auth',
                'access_token': self.access_token
            })

            # Auth-Bestätigung
            msg = await self._ws.receive_json()
            if msg.get('type') != 'auth_ok':
                raise PermissionError("Authentifizierung fehlgeschlagen")

            _LOGGER.info("Erfolgreich authentifiziert bei Home Assistant")
            return True

        except Exception as e:
            _LOGGER.error("Verbindungsfehler: %s", e)
            await self.disconnect()
            raise

    async def subscribe_entities(self, callback):
        """Abonniert Entitäts-Updates via Websocket (D-10, event-basiert)"""
        # Subscribe to state changes
        msg_id = 1
        await self._ws.send_json({
            'id': msg_id,
            'type': 'subscribe_events',
            'event_type': 'state_changed'
        })

        msg = await self._ws.receive_json()
        if msg.get('type') != 'result' or not msg.get('success'):
            raise RuntimeError(f"Subscribe fehlgeschlagen: {msg}")

        _LOGGER.info("Erfolgreich Entitäts-Updates abonniert")

        # Nachrichten verarbeiten
        while True:
            msg = await self._ws.receive_json()
            if msg.get('type') == 'event':
                event = msg.get('event', {})
                entity_id = event.get('data', {}).get('entity_id')
                new_state = event.get('data', {}).get('new_state', {})

                if entity_id and new_state:
                    await callback(entity_id, new_state)

    async def get_state(self, entity_id: str) -> Optional[Dict[str, Any]]:
        """Holt den aktuellen State einer Entität"""
        msg_id = 2
        await self._ws.send_json({
            'id': msg_id,
            'type': 'get_states'
        })

        msg = await self._ws.receive_json()
        if msg.get('type') == 'result' and msg.get('success'):
            states = msg.get('result', [])
            for state in states:
                if state.get('entity_id') == entity_id:
                    return state
        return None

    async def disconnect(self):
        """Trennt die Verbindung"""
        if self._ws:
            await self._ws.close()
        if self._session:
            await self._session.close()
        _LOGGER.info("Verbindung getrennt")


async def _end_active_session(reason: str):
    """Beendet die aktive Session mit dem aktuellen Energie-Zählerstand."""
    sensor_energy = current_config.get('sensor_energy', _DEFAULT_SENSOR_ENERGY)
    energy_state  = await ha_ws.get_state(sensor_energy)
    try:
        end_energy = float(energy_state.get('state', 0)) if energy_state else 0.0
    except (ValueError, TypeError):
        end_energy = 0.0

    completed = session_manager.end_session(end_energy)
    if completed:
        _LOGGER.info("Ladevorgang beendet (%s): Session #%s, %.3f kWh",
                     reason, completed['id'], completed['total_kwh'])
    return completed


async def sensor_callback(entity_id: str, state: Dict[str, Any]):
    """
    Callback für Sensor-Updates mit Session-Tracking.

    Alfen-Wallbox-Datenfluss:
      1. RFID-Sensor wechselt auf eine Tag-ID (z.B. "A1B2C3D4")
         → ggf. Session starten (wenn whitelisted und keine aktive läuft).
      2. State-Sensor wechselt zu "Charging Power On" → Energie fließt,
         Live-Zustand wird aktualisiert.
      3. State-Sensor wechselt zu "Available" / "Finishing" / "Stopped"
         → Session beenden (Energie-Delta = end − start).
      4. Alternativ: RFID wechselt auf "No Tag" → ebenfalls Session beenden
         (Karte abgezogen ohne State-Wechsel, z.B. bei Abbruch).
    """
    global session_manager, current_config, ha_ws, api_state

    sensor_rfid   = current_config.get('sensor_rfid',   _DEFAULT_SENSOR_RFID)
    sensor_energy = current_config.get('sensor_energy', _DEFAULT_SENSOR_ENERGY)
    sensor_state  = current_config.get('sensor_state',  _DEFAULT_SENSOR_STATE)

    state_value = state.get('state')

    # ----- Live-State für Web-Server pflegen --------------------------------
    if api_state is not None:
        if entity_id == sensor_energy:
            try:
                api_state['current_energy'] = float(state_value)
                api_state['last_update'] = datetime.now().isoformat(timespec='seconds')
            except (TypeError, ValueError):
                pass
        elif entity_id == sensor_state:
            api_state['wallbox_state'] = state_value
            api_state['last_update'] = datetime.now().isoformat(timespec='seconds')

    # ----- RFID-Sensor (Session-Start / Karte abgezogen) --------------------
    if entity_id == sensor_rfid:
        sv = (state_value or '').strip()
        sv_low = sv.lower()

        # "No Tag" / unknown → Karte entfernt: aktive Session beenden
        if sv_low in _RFID_NONE_VALUES:
            if session_manager.get_active_session():
                await _end_active_session('rfid_removed')
            return

        # Echter Tag erkannt
        if not session_manager.debounce_rfid(sv):
            return  # zu schnell hintereinander gelesen — ignorieren

        whitelist = current_config.get('rfid_whitelist', [])
        if not session_manager.is_rfid_authorized(sv, whitelist):
            _LOGGER.warning("Nicht autorisierte RFID: %s... (Whitelist-Eintrag fehlt)",
                            hash_rfid(sv)[:16])
            return

        # Energie-Zähler atomar lesen für Start-Wert
        energy_state = await ha_ws.get_state(sensor_energy)
        try:
            start_energy = float(energy_state.get('state', 0)) if energy_state else 0.0
        except (ValueError, TypeError):
            start_energy = 0.0

        wallbox_id = current_config.get('wallbox_id', 'wallbox')
        session_id = session_manager.start_session(sv, start_energy, wallbox_id=wallbox_id)
        if session_id:
            _LOGGER.info("Ladevorgang gestartet: Session #%s, Start-Zähler=%.3f kWh, Wallbox=%s",
                         session_id, start_energy, wallbox_id)
        return

    # ----- Wallbox-Status-Sensor (Session-Ende per State-Wechsel) -----------
    if entity_id == sensor_state:
        active = session_manager.get_active_session()
        if not active:
            return  # nichts zu tun

        if _is_idle_state(state_value):
            await _end_active_session(f'state={state_value}')
        elif _is_charging_state(state_value):
            _LOGGER.debug("Wallbox lädt (state='%s')", state_value)
        else:
            _LOGGER.debug("Wallbox-State Zwischenzustand: '%s'", state_value)
        return

    # ----- Energie-Sensor (nur loggen während aktiver Session) --------------
    if entity_id == sensor_energy:
        active = session_manager.get_active_session()
        if active:
            try:
                kwh = float(state_value) if state_value else 0.0
                delta = kwh - float(active.get('start_energy_kwh') or 0.0)
                _LOGGER.debug("Aktive Session #%s: Zähler=%.3f kWh, Geladen=%.3f kWh",
                              active['id'], kwh, delta)
            except (ValueError, TypeError):
                pass
        return


async def check_startup_session():
    """Prüft beim Start ob eine aktive Session existiert und führt Recovery durch (PER-02, PER-03)"""
    global session_manager, api_client

    # Startup Recovery: Alle aktiven Sessions finden (PER-02)
    recovered_sessions = session_manager.recover_active_sessions()

    if recovered_sessions:
        _LOGGER.info("=== Startup Recovery: %d aktive Sessions gefunden ===", len(recovered_sessions))

        # Aktive Sessions beim Neustart werden grundsätzlich als unvollständig
        # markiert — wir kennen den realen Wallbox-Status nicht mehr. Die
        # nächste echte Ladung erzeugt eine neue, saubere Session.
        for session in recovered_sessions:
            session_manager.mark_session_incomplete(session['id'], 'restart_recovery')
            _LOGGER.warning("Session %d als unvollständig markiert (Addon-Neustart)", session['id'])

        _LOGGER.info("=== Startup Recovery abgeschlossen ===")
    else:
        _LOGGER.info("Keine aktiven Sessions beim Start - alles bereit")


async def main():
    """Hauptschleife (D-03, D-10, D-11) - erweitert für Session-Tracking und API-Transmission"""
    global session_manager, current_config, ha_ws, api_client, api_state

    _LOGGER.info("Wallbox-Dolibarr Addon startet...")

    # Session Manager initialisieren (PER-01)
    session_manager = SessionManager(db_path="/data/sessions.db")

    # Konfiguration laden (für Whitelist und API)
    current_config = load_config()

    # API Client initialisieren — flat config (dolibarr_url auf Top-Level)
    api_client = None
    # api_state: gemeinsamer Live-Zustand, wird vom Sensor-Callback aktualisiert
    # und vom Web-Server für die Live-Anzeige laufender Sessions gelesen.
    api_state  = {
        'client': None,
        'current_energy': None,    # aktueller Energiezähler-Stand in kWh
        'wallbox_state': None,     # 'Charging' / 'Idle' / 'Stopped' / None
        'last_update': None,       # ISO-Timestamp der letzten Sensor-Aktualisierung
    }
    dolibarr_url = current_config.get("dolibarr_url", "")
    api_token    = current_config.get("api_token", "")
    if dolibarr_url and dolibarr_url != "https://dolibarr.example.com" and api_token:
        try:
            api_client = WallboxApiClient(
                base_url=dolibarr_url,
                api_token=api_token,
                timeout=30
            )
            if api_client.check_connection():
                api_state['client'] = api_client
                _LOGGER.info("Dolibarr API Verbindung erfolgreich: %s", dolibarr_url)
            else:
                _LOGGER.warning("Dolibarr API nicht erreichbar — wird später erneut versucht")
                api_client = None
        except Exception as e:
            _LOGGER.error("Fehler beim Initialisieren des API-Clients: %s", e)
            api_client = None
    else:
        _LOGGER.info("Keine Dolibarr API-Konfiguration — Addon läuft ohne API-Transmission")

    # HA-Token ermitteln: SUPERVISOR_TOKEN hat Vorrang, Fallback auf ha_token aus Konfiguration
    supervisor_token = os.getenv('SUPERVISOR_TOKEN', '')
    config_ha_token  = current_config.get('ha_token', '')
    ha_token = supervisor_token or config_ha_token
    if not ha_token:
        _LOGGER.error(
            "Kein HA-Token verfügbar! Bitte Long-Lived Access Token unter "
            "Einstellungen → Profil → Langlebige Zugriffstoken erstellen "
            "und als 'ha_token' in der Addon-Konfiguration eintragen."
        )
    else:
        token_src = 'SUPERVISOR_TOKEN' if supervisor_token else 'ha_token (Konfiguration)'
        _LOGGER.info("HA-Authentifizierung via %s", token_src)

    ha_ws = HomeAssistantWebsocket(token=ha_token)

    try:
        # Verbinden
        await ha_ws.connect()

        # Prüfen ob aktive Session nach Neustart existiert (PER-01)
        await check_startup_session()

        # Periodic API Transmission als Hintergrund-Task (Task 4 - Fix: subscribe_entities blockiert)
        async def periodic_transmission():
            """Periodische API-Übertragung als Hintergrund-Task"""
            import time
            last_transmit = 0
            transmit_interval = current_config.get("transmit_interval", 300)

            while True:
                if api_client:
                    current_time = time.time()
                    if (current_time - last_transmit) >= transmit_interval:
                        result = session_manager.transmit_completed_sessions(api_client)

                        if result["transmitted"] > 0:
                            _LOGGER.info("Sessions an Dolibarr übertragen: %s", result["transmitted"])

                        if result["failed"] > 0:
                            _LOGGER.error("Fehler bei API-Übertragung: %s Sessions fehlgeschlagen", result["failed"])
                            # Bei Fehlern: Verbindung neu testen
                            if not api_client.check_connection():
                                _LOGGER.warning("API-Verbindung verloren - deaktiviere temporär")
                                # api_client auf None setzen deaktiviert weitere Versuche
                                # TODO: Reconnect-Logik in Zukunft

                        last_transmit = current_time

                await asyncio.sleep(1)

        # Hintergrund-Task starten
        if api_client:
            transmission_task = asyncio.create_task(periodic_transmission())
            _LOGGER.info("API-Transmission Hintergrund-Task gestartet")

        # Ingress Web-Server für manuelle Ladevorgänge starten
        asyncio.create_task(start_web_server(session_manager, current_config, api_state, port=8099))
        _LOGGER.info("Ingress Web-Server Task gestartet (Port 8099)")

        # Sensor-Updates abonnieren (event-basiert, D-10) - blockiert bis zur Unterbrechung
        await ha_ws.subscribe_entities(sensor_callback)

    except KeyboardInterrupt:
        _LOGGER.info("Addon wird beendet...")
    except Exception as e:
        _LOGGER.error("Fehler: %s", e, exc_info=True)
        # Crash + Supervisor restart (D-11)
        raise
    finally:
        await ha_ws.disconnect()


if __name__ == '__main__':
    asyncio.run(main())
