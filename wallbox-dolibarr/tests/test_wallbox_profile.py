"""Tests für wallbox_profile — Profil-Auflösung und Zustand-Klassifizierung.

Ausführen:  cd wallbox-dolibarr && python3 -m pytest tests/ -q
"""
import os
import sys

_HERE = os.path.dirname(os.path.abspath(__file__))
_ADDON_DIR = os.path.dirname(_HERE)
sys.path.insert(0, _ADDON_DIR)

from wallbox_profile import (  # noqa: E402
    classify_state,
    resolve_profile,
    _ALFEN_END_KEYWORDS,
    _ALFEN_PAUSE_KEYWORDS,
)


def test_default_profile_is_alfen_eve():
    """Ohne wallbox_profile-Feld muss exakt das bewährte Alfen-Verhalten rauskommen."""
    profile = resolve_profile({})
    assert profile.auth_mode == "tag_hold"
    assert profile.state_mode == "state_keywords"
    assert profile.sensor_rfid == "sensor.alfen_eve_tag_socket_1"
    assert profile.sensor_energy == "sensor.alfen_eve_meter_reading_socket_1"
    assert profile.sensor_state == "sensor.alfen_eve_main_state_socket_1"
    assert profile.end_keywords == _ALFEN_END_KEYWORDS
    assert profile.pause_keywords == _ALFEN_PAUSE_KEYWORDS


def test_alfen_eve_profile_ignores_custom_overrides():
    """Bei wallbox_profile='alfen_eve' dürfen Custom-Felder (auth_mode, Keywords,
    etc.) NICHT durchschlagen — Alfen bleibt immer fest verdrahtet."""
    config = {
        "wallbox_profile": "alfen_eve",
        "auth_mode": "tag_toggle",
        "state_mode": "power_threshold",
        "end_keywords": ["should_not_apply"],
    }
    profile = resolve_profile(config)
    assert profile.auth_mode == "tag_hold"
    assert profile.state_mode == "state_keywords"
    assert profile.end_keywords == _ALFEN_END_KEYWORDS


def test_alfen_eve_profile_still_honours_custom_entity_ids():
    """Entity-IDs bleiben auch im Alfen-Profil überschreibbar (z.B. zweiter Anschluss)."""
    config = {
        "wallbox_profile": "alfen_eve",
        "sensor_rfid": "sensor.alfen_eve_tag_socket_2",
    }
    profile = resolve_profile(config)
    assert profile.sensor_rfid == "sensor.alfen_eve_tag_socket_2"


def test_custom_profile_shelly_em_power_threshold():
    """Shelly-EM-Szenario: separater Zähler + Leistungsschwelle statt Status-Sensor."""
    config = {
        "wallbox_profile": "custom",
        "auth_mode": "tag_pulse",
        "state_mode": "power_threshold",
        "sensor_rfid": "sensor.nfc_reader_tag",
        "sensor_energy": "sensor.shelly_em_total_kwh",
        "power_sensor": "sensor.shelly_em_power",
        "power_threshold_w": 200,
        "end_idle_minutes": 10,
    }
    profile = resolve_profile(config)
    assert profile.auth_mode == "tag_pulse"
    assert profile.state_mode == "power_threshold"
    assert profile.power_sensor == "sensor.shelly_em_power"
    assert profile.power_threshold_w == 200.0
    assert profile.end_idle_minutes == 10.0


def test_custom_profile_external_boolean_and_tag_pulse():
    """Tag startet nur, eine externe (ggf. selbstgebaute Template-)Entity beendet."""
    config = {
        "wallbox_profile": "custom",
        "auth_mode": "tag_pulse",
        "state_mode": "external_boolean",
        "active_entity": "binary_sensor.wallbox_charging_active",
    }
    profile = resolve_profile(config)
    assert profile.auth_mode == "tag_pulse"
    assert profile.state_mode == "external_boolean"
    assert profile.active_entity == "binary_sensor.wallbox_charging_active"


def test_custom_profile_no_meter_energy_delta():
    """Wallbox ohne eigenen Zähler + externem Zähler, Ende via Zähler-Stillstand."""
    config = {
        "wallbox_profile": "custom",
        "auth_mode": "none",
        "state_mode": "energy_delta",
        "sensor_energy": "sensor.shelly_em_total_kwh",
        "end_idle_minutes": 3,
    }
    profile = resolve_profile(config)
    assert profile.auth_mode == "none"
    assert profile.state_mode == "energy_delta"


def test_custom_profile_invalid_modes_fall_back_to_defaults():
    """Unbekannte Modi (z.B. Tippfehler in options.json) dürfen nicht crashen."""
    config = {
        "wallbox_profile": "custom",
        "auth_mode": "does_not_exist",
        "state_mode": "does_not_exist_either",
    }
    profile = resolve_profile(config)
    assert profile.auth_mode == "tag_hold"
    assert profile.state_mode == "state_keywords"


def test_custom_profile_empty_keyword_lists_fall_back_to_alfen_defaults():
    config = {
        "wallbox_profile": "custom",
        "end_keywords": [],
        "pause_keywords": [],
    }
    profile = resolve_profile(config)
    assert profile.end_keywords == _ALFEN_END_KEYWORDS
    assert profile.pause_keywords == _ALFEN_PAUSE_KEYWORDS


def test_custom_profile_custom_keyword_lists_are_used():
    config = {
        "wallbox_profile": "custom",
        "end_keywords": ["idle", "standby"],
        "pause_keywords": ["throttled"],
    }
    profile = resolve_profile(config)
    assert profile.end_keywords == ["idle", "standby"]
    assert profile.pause_keywords == ["throttled"]


def test_classify_state_precedence_end_over_pause_over_charging():
    end_kw, pause_kw = ["available"], ["suspend"]
    assert classify_state("Available", end_kw, pause_kw) == "end"
    assert classify_state("Suspended EV", end_kw, pause_kw) == "pause"
    assert classify_state("Charging Power On", end_kw, pause_kw) == "charging"
    assert classify_state("Preparing", end_kw, pause_kw) == "other"


def test_classify_state_empty_or_none_value_is_other():
    assert classify_state(None, ["available"], ["suspend"]) == "other"
    assert classify_state("", ["available"], ["suspend"]) == "other"


def test_classify_state_is_case_insensitive_substring_match():
    assert classify_state("CHARGING POWER ON", ["available"], ["suspend"]) == "charging"
