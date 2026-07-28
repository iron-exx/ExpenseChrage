"""Wallbox-Profil-Auflösung — macht die Session-Logik herstellerunabhängig.

Löst die flache Addon-Konfiguration (`/data/options.json`) in ein
`WallboxProfile` auf. Für `wallbox_profile: "alfen_eve"` liefert das exakt
das bewährte, hartcodierte Alfen-Verhalten (unverändert). Für
`wallbox_profile: "custom"` kommen Auth-Modus, Zustand-Erkennung und
Energiequelle vollständig aus der Konfiguration — für Wallboxen anderer
Hersteller, Lastmanagement-Adapter mit vorgeschaltetem Zähler (z.B. Shelly
EM), oder Wallboxen ganz ohne eigenen Zähler.

Bausteine:
  auth_mode:
    tag_hold   — Tag liegt an, solange geladen wird (Alfen-Standard)
    tag_pulse  — Tag-Event nur kurz sichtbar, Ende kommt aus state_mode
    tag_toggle — 1. autorisierter Tap = Start, 2. Tap = Ende (ignoriert state_mode)
    none       — keine Autorisierungspflicht, Start kommt rein aus state_mode

  state_mode:
    state_keywords   — Substring-Match gegen einen Status-Sensor (Alfen-Standard)
    power_threshold  — Ableitung aus einem Leistungssensor + Schwellenwert
    energy_delta     — Ableitung aus Stillstand des kumulativen Zählers
    external_boolean — externe on/off-Entity bestimmt Start/Ende direkt
"""
from dataclasses import dataclass, field
from typing import List

_ALFEN_END_KEYWORDS = [
    'available', 'finishing', 'finished', 'terminating', 'disconnect',
    'unavailable', 'faulted', 'reserved', 'error',
]
_ALFEN_PAUSE_KEYWORDS = [
    'suspend', 'stopped', 'power off', 'paused', 'preparing',
]

_DEFAULT_SENSOR_RFID = "sensor.alfen_eve_tag_socket_1"
_DEFAULT_SENSOR_ENERGY = "sensor.alfen_eve_meter_reading_socket_1"
_DEFAULT_SENSOR_STATE = "sensor.alfen_eve_main_state_socket_1"

VALID_AUTH_MODES = ('tag_hold', 'tag_pulse', 'tag_toggle', 'none')
VALID_STATE_MODES = ('state_keywords', 'power_threshold', 'energy_delta', 'external_boolean')


@dataclass(frozen=True)
class WallboxProfile:
    auth_mode: str
    state_mode: str
    sensor_rfid: str
    sensor_energy: str
    sensor_state: str
    power_sensor: str
    active_entity: str
    power_threshold_w: float
    end_idle_minutes: float
    end_keywords: List[str] = field(default_factory=list)
    pause_keywords: List[str] = field(default_factory=list)


def resolve_profile(config: dict) -> WallboxProfile:
    """Löst die Addon-Konfiguration in ein WallboxProfile auf.

    `wallbox_profile: "alfen_eve"` (Default) erzwingt das bewährte
    Alfen-Verhalten — Auth/State-Modus und Keyword-Listen kommen NICHT aus
    der Konfiguration, nur die Entity-IDs bleiben überschreibbar (z.B. für
    einen zweiten Alfen-Anschluss `socket_2`).
    """
    profile_name = config.get('wallbox_profile', 'alfen_eve')

    sensor_rfid = config.get('sensor_rfid') or _DEFAULT_SENSOR_RFID
    sensor_energy = config.get('sensor_energy') or _DEFAULT_SENSOR_ENERGY
    sensor_state = config.get('sensor_state') or _DEFAULT_SENSOR_STATE

    if profile_name != 'custom':
        return WallboxProfile(
            auth_mode='tag_hold',
            state_mode='state_keywords',
            sensor_rfid=sensor_rfid,
            sensor_energy=sensor_energy,
            sensor_state=sensor_state,
            power_sensor='',
            active_entity='',
            power_threshold_w=100.0,
            end_idle_minutes=5.0,
            end_keywords=list(_ALFEN_END_KEYWORDS),
            pause_keywords=list(_ALFEN_PAUSE_KEYWORDS),
        )

    auth_mode = config.get('auth_mode', 'tag_hold')
    if auth_mode not in VALID_AUTH_MODES:
        auth_mode = 'tag_hold'

    state_mode = config.get('state_mode', 'state_keywords')
    if state_mode not in VALID_STATE_MODES:
        state_mode = 'state_keywords'

    end_keywords = config.get('end_keywords') or _ALFEN_END_KEYWORDS
    pause_keywords = config.get('pause_keywords') or _ALFEN_PAUSE_KEYWORDS

    return WallboxProfile(
        auth_mode=auth_mode,
        state_mode=state_mode,
        sensor_rfid=sensor_rfid,
        sensor_energy=sensor_energy,
        sensor_state=sensor_state,
        power_sensor=config.get('power_sensor', ''),
        active_entity=config.get('active_entity', ''),
        power_threshold_w=float(config.get('power_threshold_w', 100.0)),
        end_idle_minutes=float(config.get('end_idle_minutes', 5.0)),
        end_keywords=list(end_keywords),
        pause_keywords=list(pause_keywords),
    )


def classify_state(state_value, end_keywords, pause_keywords) -> str:
    """Klassifiziert einen Status-String gegen Keyword-Listen.

    Reihenfolge (wie bisher): ENDE > PAUSE > CHARGING > 'other'.
    """
    if not state_value:
        return 'other'
    sl = str(state_value).lower()
    if any(k in sl for k in end_keywords):
        return 'end'
    if any(k in sl for k in pause_keywords):
        return 'pause'
    if 'charging' in sl:
        return 'charging'
    return 'other'
