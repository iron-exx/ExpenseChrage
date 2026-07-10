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
import time
import aiohttp
import json
import logging
import os
import sys
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

# Pending-Auth-Fenster: wie lange (Sekunden) ein erkannter RFID-Tag
# "memoriert" wird, falls erst SPÄTER der Charging-Power-On-State kommt.
# Alfen brauchst manchmal Minuten zwischen NFC-Auth und tatsächlichem
# Charging-Start (Auto wird erst danach angesteckt).
_PENDING_AUTH_WINDOW = 600  # 10 Minuten

# Letzte erkannte autorisierte RFID — wird vom Charging-State-Trigger
# als Fallback verwendet, falls der RFID-Event verpasst wurde.
_pending_auth = None  # dict: {'rfid_hex': str, 'time': float}

# Zuletzt am RFID-Sensor anliegender Tag-Wert (ohne Zeitfenster). Bei Alfen
# bleibt der Tag stundenlang als Sensor-Wert "anliegend", ohne neues Event —
# startet das Laden erst viel später, ist _pending_auth längst abgelaufen.
# Dieser Cache dient dann als Fallback für den Charging-State-Trigger.
_latest_rfid = None  # str oder None

# Gecachter Energie-Zählerstand (kWh). Wird laufend aus den state_changed-
# Events des Energiesensors aktualisiert UND beim Start einmalig geseedet.
# Start/Ende einer Session lesen NUR diesen Cache — niemals get_state()
# während der Event-Schleife (das würde Frames stehlen, siehe websocket).
_latest_energy = None  # float oder None wenn (noch) kein gültiger Wert vorliegt


def _parse_energy(value):
    """Sensor-Wert → float kWh oder None bei 'unavailable'/'unknown'/Müll."""
    if value is None:
        return None
    s = str(value).strip().lower()
    if s in ('', 'unavailable', 'unknown', 'none', 'nan'):
        return None
    try:
        return float(value)
    except (ValueError, TypeError):
        return None

# Wallbox-Status: substring-Match (case-insensitive) gegen den echten Sensor-Wert.
# Alfen-Wallbox-States: "Available", "Preparing", "Charging Power On",
# "Charging Stopped", "Suspended EV", "Suspended EVSE", "Finishing",
# "Reserved", "Unavailable", "Faulted". Wir gruppieren nach Verhalten:
# Alfen-States die das ENDE einer Ladung markieren. Wird VOR _is_charging_state
# geprüft (Vorrang), da z.B. "Charging Terminating" zwar 'charging' enthält,
# aber das Lade-Ende bedeutet.
_IDLE_KEYWORDS = [
    'available', 'idle', 'finished', 'finishing', 'terminating',
    'stopped', 'disconnect', 'unavailable', 'faulted', 'suspend', 'error',
]

def _is_idle_state(s):
    """True wenn die Wallbox keine aktive Ladung (mehr) hat: abgeschlossen,
    am Beenden, Stecker raus, Fehler oder pausiert."""
    if not s:
        return False
    sl = s.lower()
    return any(k in sl for k in _IDLE_KEYWORDS)

def _is_charging_state(s):
    """True wenn die Wallbox aktiv lädt (Energie fließt). Idle-Zustände haben
    Vorrang — 'Charging Terminating' zählt z.B. als Ende, nicht als Laden."""
    if not s:
        return False
    if _is_idle_state(s):
        return False
    return 'charging' in s.lower()

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
        # HA verlangt streng monoton steigende, eindeutige Message-IDs pro
        # Verbindung. Ein einziger Zähler für alle Kommandos (subscribe, get_states).
        self._msg_id = 0

    def _next_id(self) -> int:
        self._msg_id += 1
        return self._msg_id

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
        """Abonniert Entitäts-Updates via Websocket (D-10, event-basiert).

        WICHTIG: Sobald diese Schleife läuft, ist sie der EINZIGE Leser des
        Sockets. get_state()/get_all_states() dürfen NICHT mehr aufgerufen
        werden (sie würden Frames stehlen) — stattdessen den gecachten Wert
        aus dem Event-Stream nutzen.
        """
        sub_id = self._next_id()
        await self._ws.send_json({
            'id': sub_id,
            'type': 'subscribe_events',
            'event_type': 'state_changed'
        })

        msg = await self._ws.receive_json()
        if msg.get('type') != 'result' or not msg.get('success'):
            raise RuntimeError(f"Subscribe fehlgeschlagen: {msg}")

        _LOGGER.info("Erfolgreich Entitäts-Updates abonniert")

        # Nachrichten verarbeiten — alle Frames gehören jetzt diesem Loop
        while True:
            msg = await self._ws.receive_json()
            if msg.get('type') == 'event':
                event = msg.get('event', {})
                entity_id = event.get('data', {}).get('entity_id')
                new_state = event.get('data', {}).get('new_state', {})

                if entity_id and new_state:
                    await callback(entity_id, new_state)

    async def get_all_states(self) -> Dict[str, Any]:
        """Holt EINMALIG alle Entitäts-States als dict {entity_id: state}.

        NUR vor dem Start der subscribe_entities-Schleife aufrufen — danach
        konkurriert das receive_json() mit dem Event-Loop. Demultiplext nach
        der eigenen Message-ID, damit zwischenzeitliche Frames korrekt
        übersprungen werden.
        """
        req_id = self._next_id()
        await self._ws.send_json({'id': req_id, 'type': 'get_states'})

        # Lese bis das 'result' mit UNSERER id kommt (andere Frames überspringen)
        for _ in range(50):
            msg = await self._ws.receive_json()
            if msg.get('id') == req_id and msg.get('type') == 'result':
                if not msg.get('success'):
                    _LOGGER.warning("get_states fehlgeschlagen: %s", msg.get('error'))
                    return {}
                return {s.get('entity_id'): s for s in msg.get('result', [])}
        _LOGGER.warning("get_states: kein passendes result nach 50 Frames")
        return {}

    async def get_state(self, entity_id: str) -> Optional[Dict[str, Any]]:
        """Einzelnen State holen (nur vor dem subscribe-Loop verwenden)."""
        states = await self.get_all_states()
        return states.get(entity_id)

    async def disconnect(self):
        """Trennt die Verbindung"""
        if self._ws:
            await self._ws.close()
        if self._session:
            await self._session.close()
        _LOGGER.info("Verbindung getrennt")


async def _start_session_for(rfid_hex: str, source: str):
    """Startet eine neue Session falls noch keine aktiv ist. Der Start-
    Zählerstand kommt aus dem gecachten Energie-Wert (_latest_energy), der
    laufend aus dem Event-Stream aktualisiert wird — NICHT per get_state()."""
    if session_manager.get_active_session():
        return None  # bereits aktiv — kein Doppelstart

    if _latest_energy is None:
        _LOGGER.warning(
            "Session-Start (%s) ohne gültigen Energie-Zählerstand — Sensor '%s' "
            "lieferte noch keinen Wert. Session wird gestartet, aber Start-Zähler=0; "
            "Ende markiert sie ggf. als unvollständig.",
            source, current_config.get('sensor_energy', _DEFAULT_SENSOR_ENERGY)
        )
    start_energy = _latest_energy if _latest_energy is not None else 0.0

    wallbox_id = current_config.get('wallbox_id', 'wallbox')
    session_id = session_manager.start_session(
        rfid_hex, start_energy, wallbox_id=wallbox_id,
        start_energy_valid=(_latest_energy is not None)
    )
    if session_id:
        _LOGGER.info("Ladevorgang gestartet (%s): Session #%s, Start-Zähler=%.3f kWh",
                     source, session_id, start_energy)
    return session_id


async def _end_active_session(reason: str):
    """Beendet die aktive Session mit dem gecachten Energie-Zählerstand.
    - kWh = end − start aus dem Event-Stream-Cache
    - Sessions ohne gültige Energie-Readings → 'incomplete' (sichtbar, nicht
      still verworfen), echte Mini-Ladungen < min_session_kwh → 'discarded'."""
    min_kwh = float(current_config.get('min_session_kwh', 0.05))
    energy_valid = _latest_energy is not None
    end_energy = _latest_energy if energy_valid else 0.0

    completed = session_manager.end_session(
        end_energy, min_kwh=min_kwh, end_energy_valid=energy_valid
    )
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
    global session_manager, current_config, ha_ws, api_state, _latest_energy, _latest_rfid

    sensor_rfid   = current_config.get('sensor_rfid',   _DEFAULT_SENSOR_RFID)
    sensor_energy = current_config.get('sensor_energy', _DEFAULT_SENSOR_ENERGY)
    sensor_state  = current_config.get('sensor_state',  _DEFAULT_SENSOR_STATE)

    state_value = state.get('state')

    # ----- Energie-Cache + Live-State für Web-Server pflegen ----------------
    if entity_id == sensor_energy:
        parsed = _parse_energy(state_value)
        if parsed is not None:
            _latest_energy = parsed            # Cache für Session-Start/-Ende
            if api_state is not None:
                api_state['current_energy'] = parsed
                api_state['last_update'] = datetime.now().isoformat(timespec='seconds')
    elif entity_id == sensor_state:
        if api_state is not None:
            api_state['wallbox_state'] = state_value
            api_state['last_update'] = datetime.now().isoformat(timespec='seconds')

    # ----- RFID-Sensor (Session-Start / Karte abgezogen) --------------------
    if entity_id == sensor_rfid:
        global _pending_auth
        sv = (state_value or '').strip()
        sv_low = sv.lower()

        # "No Tag" / unknown → Karte entfernt
        if sv_low in _RFID_NONE_VALUES:
            _latest_rfid = None
            if session_manager.get_active_session():
                await _end_active_session('rfid_removed')
            return

        # Anliegenden Tag cachen (auch vor Debounce/Whitelist — für Charging-Fallback)
        _latest_rfid = sv

        # Echter Tag erkannt — Debounce + Whitelist
        if not session_manager.debounce_rfid(sv):
            return
        whitelist = current_config.get('rfid_whitelist', [])
        if not session_manager.is_rfid_authorized(sv, whitelist):
            _LOGGER.warning("Nicht autorisierte RFID: %s... (Whitelist-Eintrag fehlt)",
                            hash_rfid(sv)[:16])
            return

        # Tag als "pending auth" merken — falls Charging erst später startet
        _pending_auth = {'rfid_hex': sv, 'time': time.time()}

        # Session sofort starten (klassischer Fall: Karte → Laden)
        await _start_session_for(sv, 'rfid_event')
        return

    # ----- Wallbox-Status-Sensor (Session-Start/Ende per State-Wechsel) -----
    if entity_id == sensor_state:
        active = session_manager.get_active_session()

        # Idle hat VORRANG vor Charging — "Charging Terminating" zählt als Ende.
        if _is_idle_state(state_value):
            if active:
                await _end_active_session(f'state={state_value}')
            _pending_auth = None  # Auth verbraucht
            return

        if _is_charging_state(state_value):
            # Wallbox lädt jetzt aktiv. Wenn KEINE Session läuft, aber eine
            # RFID kürzlich autorisiert wurde → Session nachträglich starten.
            # Fängt RFID-Events ab die das Addon verpasst hat (z.B. während
            # Restart, Websocket-Reconnect, oder lange Auth→Charging-Delay).
            if active:
                _LOGGER.debug("Wallbox lädt (state='%s'), Session #%s läuft",
                              state_value, active['id'])
                return
            whitelist = current_config.get('rfid_whitelist', [])
            if _pending_auth and (time.time() - _pending_auth['time']) < _PENDING_AUTH_WINDOW:
                rfid_hex = _pending_auth['rfid_hex']
                _LOGGER.info("Session-Start nachträglich via charging-state — "
                             "RFID-Auth war vor %.0f Sekunden",
                             time.time() - _pending_auth['time'])
                await _start_session_for(rfid_hex, f'charging_state={state_value}')
                _pending_auth = None
            elif _latest_rfid and _latest_rfid.lower() not in _RFID_NONE_VALUES \
                    and session_manager.is_rfid_authorized(_latest_rfid, whitelist):
                # Alfen hält den Tag stundenlang ohne neues Event: kein frisches
                # Pending-Auth, aber der aktuell anliegende Tag ist gültig →
                # damit die Session starten.
                _LOGGER.info("Session-Start via charging-state mit anliegendem Tag "
                             "(kein frisches Auth-Event, aber Tag gültig).")
                await _start_session_for(_latest_rfid, f'charging_state_held_tag={state_value}')
            else:
                _LOGGER.warning("Wallbox lädt (state='%s') aber kein gültiger RFID-Tag "
                                "anliegend. Session wird NICHT erfasst.", state_value)
            return

        _LOGGER.debug("Wallbox-State Zwischenzustand: '%s'", state_value)
        return

    # ----- Energie-Sensor: Cache schon oben gepflegt; hier nur Debug-Log -----
    if entity_id == sensor_energy:
        active = session_manager.get_active_session()
        if active and _latest_energy is not None:
            delta = _latest_energy - float(active.get('start_energy_kwh') or 0.0)
            _LOGGER.debug("Aktive Session #%s: Zähler=%.3f kWh, Geladen=%.3f kWh",
                          active['id'], _latest_energy, delta)
        return


async def check_startup_session():
    """Prüft beim Start ob eine aktive Session existiert UND ob die Wallbox
    aktuell lädt — fängt verlorene Sessions ab wenn das Addon während einer
    laufenden Ladung neugestartet wurde."""
    global session_manager, api_client, _pending_auth, _latest_energy, _latest_rfid

    # 1) Alte 'active' Sessions in SQLite als unvollständig markieren
    recovered_sessions = session_manager.recover_active_sessions()
    if recovered_sessions:
        _LOGGER.info("=== Startup Recovery: %d aktive Sessions gefunden ===", len(recovered_sessions))
        for session in recovered_sessions:
            session_manager.mark_session_incomplete(session['id'], 'restart_recovery')
            _LOGGER.warning("Session %d als unvollständig markiert (Addon-Neustart)", session['id'])

    # 2) EINMALIGER Snapshot ALLER States (vor dem subscribe-Loop, konkurrenzfrei).
    #    Seedet den Energie-Cache und prüft ob gerade geladen wird.
    sensor_rfid   = current_config.get('sensor_rfid',   _DEFAULT_SENSOR_RFID)
    sensor_state  = current_config.get('sensor_state',  _DEFAULT_SENSOR_STATE)
    sensor_energy = current_config.get('sensor_energy', _DEFAULT_SENSOR_ENERGY)
    try:
        snapshot = await ha_ws.get_all_states()
    except Exception as exc:
        _LOGGER.warning("Konnte Wallbox-Zustand beim Start nicht lesen: %s", exc)
        return

    rfid_val   = (snapshot.get(sensor_rfid)  or {}).get('state', '') or ''
    state_val  = (snapshot.get(sensor_state) or {}).get('state', '') or ''
    energy_raw = (snapshot.get(sensor_energy) or {}).get('state')

    # Anliegenden Tag cachen — Fallback für Charging-Start ohne frisches Event
    if rfid_val and rfid_val.lower() not in _RFID_NONE_VALUES:
        _latest_rfid = rfid_val

    # Energie-Cache seeden — ab jetzt hat Session-Start/-Ende einen gültigen Wert
    seeded = _parse_energy(energy_raw)
    if seeded is not None:
        _latest_energy = seeded
        if api_state is not None:
            api_state['current_energy'] = seeded
            api_state['last_update'] = datetime.now().isoformat(timespec='seconds')
    _LOGGER.info("Wallbox-Zustand beim Start: state='%s', rfid='%s', zaehler=%s kWh",
                 state_val, rfid_val, ('%.3f' % seeded) if seeded is not None else 'n/a')

    # Wenn gerade geladen wird UND ein gültiger Tag anliegt → Session starten
    if _is_charging_state(state_val):
        whitelist = current_config.get('rfid_whitelist', [])
        if rfid_val and rfid_val.lower() not in _RFID_NONE_VALUES \
           and session_manager.is_rfid_authorized(rfid_val, whitelist):
            _LOGGER.warning(
                "Wallbox lädt bereits beim Addon-Start (state=%s) — starte Session "
                "mit aktuellem Tag. Hinweis: bisher geladene kWh sind verloren, "
                "ab jetzt wird wieder erfasst.", state_val
            )
            await _start_session_for(rfid_val, 'startup_charging_detected')
        else:
            _LOGGER.warning(
                "Wallbox lädt (state=%s) aber kein gültiger RFID-Tag (rfid=%s). "
                "Diese Ladung wird NICHT erfasst. Manuell nachtragen unter "
                "⚡ Erfassen im Addon-UI.", state_val, rfid_val
            )

    # Falls gerade ein Tag anliegt, ihn als pending-auth merken (für Charging-Wechsel)
    if rfid_val and rfid_val.lower() not in _RFID_NONE_VALUES:
        whitelist = current_config.get('rfid_whitelist', [])
        if session_manager.is_rfid_authorized(rfid_val, whitelist):
            _pending_auth = {'rfid_hex': rfid_val, 'time': time.time()}


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
    api_config   = current_config.get("api", {})
    dolibarr_url = api_config.get("dolibarr_url", "")
    api_token    = api_config.get("api_token", "")
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
            transmit_interval = current_config.get("api", {}).get("transmit_interval", 300)

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
