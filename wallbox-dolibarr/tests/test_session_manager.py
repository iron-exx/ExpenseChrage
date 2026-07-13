"""Tests für session_manager — Fokus auf die manuelle Session-Erfassung
(login- vs. rfid_hash-Pfad) und die Übertragungs-Payload.

Lauffähig ohne HA/aiohttp: session_manager braucht nur Stdlib + utils.hash.
Ausführen:  cd wallbox-dolibarr && python3 -m pytest tests/ -q
"""
import os
import sys

import pytest

# Modul-Verzeichnis (wallbox-dolibarr/) auf den Pfad, damit
# `session_manager` und `utils.hash` lokal auflösen.
_HERE = os.path.dirname(os.path.abspath(__file__))
_ADDON_DIR = os.path.dirname(_HERE)
sys.path.insert(0, _ADDON_DIR)

from session_manager import SessionManager  # noqa: E402
from utils.hash import hash_rfid  # noqa: E402


@pytest.fixture()
def sm(tmp_path):
    """SessionManager mit temporärer SQLite-DB."""
    db = tmp_path / "sessions.db"
    return SessionManager(db_path=str(db))


def _completed(sm):
    rows = sm.get_completed_sessions(limit=50)
    return rows


def test_manual_session_via_login_sets_login_and_no_realcard(sm):
    """login-Pfad: login wird gespeichert, rfid_hash ist ein Platzhalter
    (NICHT der Hash einer echten Karte)."""
    sid = sm.add_manual_session(
        kwh=12.5, wallbox_id="alfen_eve", session_date="2026-07-10", login="sandro.ahrens"
    )
    assert sid is not None
    row = next(r for r in _completed(sm) if r["id"] == sid)
    assert row["login"] == "sandro.ahrens"
    assert row["total_kwh"] == pytest.approx(12.5)
    # Platzhalter-Hash != Hash irgendeiner realen Hex-Karte
    assert row["rfid_hash"] != hash_rfid("6C62083E")
    assert row["status"] == "completed"


def test_manual_session_via_rfid_hash(sm):
    """rfid_hash-Pfad: Hash wird gespeichert, login bleibt leer."""
    h = hash_rfid("6C62083E")
    sid = sm.add_manual_session(
        kwh=5.0, wallbox_id="alfen_eve", session_date="2026-07-10", rfid_hash=h
    )
    assert sid is not None
    row = next(r for r in _completed(sm) if r["id"] == sid)
    assert row["rfid_hash"] == h
    assert not row["login"]


def test_manual_session_requires_login_or_hash(sm):
    """Weder login noch rfid_hash → None (kein stiller Fehl-Insert)."""
    sid = sm.add_manual_session(kwh=5.0, wallbox_id="alfen_eve", session_date="2026-07-10")
    assert sid is None
    assert _completed(sm) == []


def test_transmit_prefers_login_over_rfid_hash(sm):
    """transmit_completed_sessions sendet 'login' (nicht rfid_hash), wenn
    die Session per Login erfasst wurde — SEC-01: kein Kartenhash nötig."""
    sm.add_manual_session(
        kwh=3.0, wallbox_id="alfen_eve", session_date="2026-07-10", login="sandro.ahrens"
    )

    captured = []

    class FakeClient:
        def transmit_session(self, data):
            captured.append(data)
            return (True, "")

    result = sm.transmit_completed_sessions(FakeClient())
    assert result["transmitted"] == 1
    assert result["failed"] == 0
    assert len(captured) == 1
    payload = captured[0]
    assert payload.get("login") == "sandro.ahrens"
    assert "rfid_hash" not in payload


def test_transmit_uses_rfid_hash_when_no_login(sm):
    """Ohne login wird rfid_hash übertragen (physischer Tap)."""
    h = hash_rfid("6C62083E")
    sm.add_manual_session(
        kwh=3.0, wallbox_id="alfen_eve", session_date="2026-07-10", rfid_hash=h
    )

    captured = []

    class FakeClient:
        def transmit_session(self, data):
            captured.append(data)
            return (True, "")

    sm.transmit_completed_sessions(FakeClient())
    assert captured[0].get("rfid_hash") == h
    assert "login" not in captured[0]
