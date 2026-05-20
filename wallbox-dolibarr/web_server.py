#!/usr/bin/env python3
"""
Ingress Web-Server — Wallbox Dolibarr Addon

Seiten:
  GET  /              Manuelle Session-Erfassung + letzte Sessions
  POST /              Speichert neue manuelle Session
  POST /transmit      Löst sofortige Übertragung an Dolibarr aus
  GET  /history       Monatliche Verlaufsansicht
  GET  /export        CSV-Export für einen Monat
"""
import csv
import io
import logging
import sqlite3
from datetime import datetime

from aiohttp import web

_LOGGER = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# CSS & HTML-Basis
# ---------------------------------------------------------------------------

_CSS = """
* { box-sizing: border-box; margin: 0; padding: 0 }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
       background: #f0f2f5; min-height: 100vh }
nav { background: #fff; border-bottom: 1px solid #e0e0e0;
      padding: 0 20px; display: flex; gap: 4px }
nav a { display: inline-block; padding: 13px 18px; text-decoration: none;
        font-size: 14px; font-weight: 600; color: #666;
        border-bottom: 3px solid transparent; transition: all .2s }
nav a.active { color: #03a9f4; border-bottom-color: #03a9f4 }
nav a:hover { color: #03a9f4 }
.page { max-width: 700px; margin: 24px auto; padding: 0 16px }
.card { background: #fff; border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,.1); padding: 24px; margin-bottom: 16px }
.card h3 { font-size: 15px; color: #888; margin-bottom: 16px;
           text-transform: uppercase; letter-spacing: .5px }
label { display: block; font-size: 12px; font-weight: 600; color: #666;
        margin: 14px 0 5px; text-transform: uppercase; letter-spacing: .4px }
input, select { width: 100%; padding: 10px 12px; border: 1.5px solid #ddd;
                border-radius: 7px; font-size: 15px; outline: none;
                transition: border-color .2s }
input:focus, select:focus { border-color: #03a9f4 }
.kwh-wrap { position: relative }
.kwh-wrap input { padding-right: 44px }
.kwh-unit { position: absolute; right: 12px; top: 50%;
            transform: translateY(-50%); color: #aaa; font-size: 14px }
.btn { display: inline-block; padding: 11px 20px; border: none; border-radius: 7px;
       font-size: 14px; font-weight: 600; cursor: pointer; transition: background .2s }
.btn-primary { background: #03a9f4; color: #fff; width: 100%; padding: 13px;
               font-size: 15px; margin-top: 18px }
.btn-primary:hover { background: #0288d1 }
.btn-transmit { background: #ff9800; color: #fff; width: 100%; margin-top: 10px }
.btn-transmit:hover { background: #f57c00 }
.btn-export { background: #4caf50; color: #fff; font-size: 13px;
              padding: 8px 16px; text-decoration: none; border-radius: 6px;
              display: inline-block }
.btn-export:hover { background: #388e3c }
.msg { padding: 12px 14px; border-radius: 7px; font-size: 13px;
       line-height: 1.5; margin-bottom: 14px }
.ok  { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7 }
.err { background: #ffebee; color: #c62828; border: 1px solid #ef9a9a }
.warn{ background: #fff3e0; color: #e65100; border: 1px solid #ffcc80 }
.session-row { display: flex; justify-content: space-between; align-items: center;
               padding: 9px 0; border-bottom: 1px solid #f5f5f5; font-size: 13px }
.session-row:last-child { border-bottom: none }
.session-kwh { font-weight: 700; color: #03a9f4; font-size: 15px }
.session-meta { color: #999; font-size: 12px; margin-top: 2px }
.tag { display: inline-block; padding: 3px 8px; border-radius: 10px;
       font-size: 11px; font-weight: 600; white-space: nowrap }
.tag-ok      { background: #e8f5e9; color: #2e7d32 }
.tag-pending { background: #fff3e0; color: #e65100 }
table { width: 100%; border-collapse: collapse; font-size: 13px }
thead th { text-align: left; padding: 8px 10px; background: #f5f5f5;
           font-size: 12px; text-transform: uppercase; letter-spacing: .4px;
           color: #666; border-bottom: 2px solid #e0e0e0 }
tbody td { padding: 9px 10px; border-bottom: 1px solid #f0f0f0; vertical-align: middle }
tbody tr:last-child td { border-bottom: none }
.month-tabs { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap }
.month-tab { padding: 7px 14px; border-radius: 20px; font-size: 13px;
             font-weight: 600; text-decoration: none; background: #eee; color: #555;
             transition: all .2s }
.month-tab.active { background: #03a9f4; color: #fff }
.month-tab:hover:not(.active) { background: #ddd }
.total-row { font-weight: 700; background: #f9f9f9 }
.total-row td { border-top: 2px solid #e0e0e0 }
.empty { color: #aaa; text-align: center; padding: 24px; font-size: 14px }
"""

def _base(nav_html, content, base_href=''):
    base_tag = f'  <base href="{base_href}/">\n' if base_href else ''
    return f"""<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
{base_tag}  <title>Wallbox Ladevorgänge</title>
  <style>{_CSS}</style>
</head>
<body>
<nav>{nav_html}</nav>
<div class="page">{content}</div>
</body>
</html>"""

def _nav(active):
    # Relative URLs — absoluter Pfad würde HA-eigene Seiten öffnen
    return (
        f'<a href="./" class="{"active" if active == "form" else ""}">⚡ Erfassen</a>'
        f'<a href="live" class="{"active" if active == "live" else ""}">🔴 Live</a>'
        f'<a href="history" class="{"active" if active == "history" else ""}">📋 Verlauf</a>'
    )

# ---------------------------------------------------------------------------
# DB-Hilfsfunktionen
# ---------------------------------------------------------------------------

def _db_month(db_path, year, month):
    """Alle Sessions eines Monats aus SQLite"""
    conn = sqlite3.connect(db_path)
    conn.row_factory = sqlite3.Row
    cur = conn.cursor()
    cur.execute("""
        SELECT id, rfid_hash, wallbox_id, start_time, end_time,
               total_kwh, status, transmitted_at
        FROM sessions
        WHERE strftime('%Y', start_time) = ?
          AND strftime('%m', start_time) = ?
        ORDER BY start_time DESC
    """, (str(year), str(month).zfill(2)))
    rows = [dict(r) for r in cur.fetchall()]
    conn.close()
    return rows

def _db_months(db_path):
    """Verfügbare Monate (max. 12) absteigend"""
    conn = sqlite3.connect(db_path)
    cur = conn.cursor()
    cur.execute("""
        SELECT DISTINCT strftime('%Y', start_time) AS y,
                        strftime('%m', start_time) AS m
        FROM sessions
        WHERE start_time IS NOT NULL
        ORDER BY y DESC, m DESC
        LIMIT 12
    """)
    rows = [(int(y), int(m)) for y, m in cur.fetchall()]
    conn.close()
    return rows

def _month_name(month):
    names = ['Jan','Feb','Mär','Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez']
    return names[month - 1]

def _db_active_sessions(db_path):
    """Alle laufenden Sessions (status='active') aus SQLite"""
    conn = sqlite3.connect(db_path)
    conn.row_factory = sqlite3.Row
    cur = conn.cursor()
    cur.execute("""
        SELECT id, rfid_hash, wallbox_id, start_time, start_energy_kwh
        FROM sessions
        WHERE status = 'active'
        ORDER BY start_time ASC
    """)
    rows = [dict(r) for r in cur.fetchall()]
    conn.close()
    return rows

def _fmt_duration(seconds):
    """Sekunden → 'Hh Mm Ss' oder 'Mm Ss'"""
    if seconds is None or seconds < 0:
        return '–'
    s = int(seconds)
    h, rem = divmod(s, 3600)
    m, s = divmod(rem, 60)
    if h:
        return f'{h}h {m:02d}m {s:02d}s'
    return f'{m}m {s:02d}s'

# ---------------------------------------------------------------------------
# Seiten-Builder
# ---------------------------------------------------------------------------

def _build_form_page(session_manager, config, message_html='', base_href=''):
    whitelist = config.get('rfid_whitelist', [])
    today     = datetime.now().date().isoformat()

    rfid_opts = '\n'.join(
        f'<option value="{r}">{r}</option>' for r in whitelist
    ) or '<option value="">— keine RFID konfiguriert —</option>'

    # Letzte 5 Sessions mit Status
    rows_html = ''
    try:
        rows = session_manager.get_completed_sessions(limit=5)
        for s in rows:
            kwh       = s.get('total_kwh') or 0
            date      = (s.get('start_time') or '')[:10]
            rid       = (s.get('rfid_hash') or '')[:8]
            manual    = ' · manuell' if (s.get('start_time') or '').endswith('T12:00:00') else ''
            tag       = ('<span class="tag tag-ok">✓ übertragen</span>'
                         if s.get('transmitted_at')
                         else '<span class="tag tag-pending">⏳ ausstehend</span>')
            rows_html += (
                f'<div class="session-row">'
                f'<span><div class="session-kwh">{kwh:.3f} kWh</div>'
                f'<div class="session-meta">{date} · {rid}…{manual}</div></span>'
                f'{tag}</div>'
            )
    except Exception:
        pass

    sessions_block = (
        f'<div class="card"><h3>Letzte Sessions</h3>{rows_html}</div>'
        if rows_html else ''
    )

    content = f"""
{message_html}
<div class="card">
  <h3>Manueller Ladevorgang</h3>
  <form method="POST" action="./">
    <label>RFID-Karte</label>
    <select name="rfid" required>{rfid_opts}</select>
    <label>Geladene Energie</label>
    <div class="kwh-wrap">
      <input type="number" name="kwh" min="0.001" step="0.001"
             placeholder="z.B. 12.500" required>
      <span class="kwh-unit">kWh</span>
    </div>
    <label>Datum</label>
    <input type="date" name="date" value="{today}" required>
    <button type="submit" class="btn btn-primary">Ladevorgang speichern</button>
  </form>
  <form method="POST" action="transmit" onsubmit="this.querySelector('button').textContent='Übertrage…'">
    <button type="submit" class="btn btn-transmit">📤 Jetzt an Dolibarr übertragen</button>
  </form>
</div>
{sessions_block}"""

    return _base(_nav('form'), content, base_href)


def _build_history_page(session_manager, year, month, base_href=''):
    months = _db_months(session_manager.db_path)

    # Fallback: aktueller Monat
    now = datetime.now()
    if not months:
        months = [(now.year, now.month)]
    if year == 0 or month == 0:
        year, month = months[0]

    # Monats-Tabs
    tabs_html = ''
    for y, m in months:
        active = 'active' if (y == year and m == month) else ''
        tabs_html += (
            f'<a href="history?year={y}&month={m}" class="month-tab {active}">'
            f'{_month_name(m)} {y}</a>'
        )

    # Sessions des gewählten Monats
    rows = _db_month(session_manager.db_path, year, month)
    total_kwh       = sum(s.get('total_kwh') or 0 for s in rows)
    total_sessions  = len(rows)
    count_pending   = sum(1 for s in rows if not s.get('transmitted_at'))
    count_sent      = total_sessions - count_pending

    if rows:
        table_rows = ''
        for s in rows:
            kwh      = s.get('total_kwh') or 0
            date     = (s.get('start_time') or '')[:10]
            time_str = (s.get('start_time') or '')[11:16]
            rid      = (s.get('rfid_hash') or '')[:12] + '…'
            wbx      = s.get('wallbox_id') or '—'
            tag      = ('<span class="tag tag-ok">✓ übertragen</span>'
                        if s.get('transmitted_at')
                        else '<span class="tag tag-pending">⏳ ausstehend</span>')
            table_rows += (
                f'<tr><td>{date}<br><span style="color:#aaa;font-size:11px">{time_str}</span></td>'
                f'<td style="font-size:11px;color:#888">{rid}</td>'
                f'<td>{wbx}</td>'
                f'<td style="font-weight:700;color:#03a9f4">{kwh:.3f}</td>'
                f'<td>{tag}</td></tr>'
            )
        table_rows += (
            f'<tr class="total-row">'
            f'<td colspan="3">Gesamt ({total_sessions} Sessions)</td>'
            f'<td style="color:#03a9f4">{total_kwh:.3f} kWh</td>'
            f'<td><span style="font-size:12px;color:#888">'
            f'{count_sent} übertragen · {count_pending} ausstehend</span></td></tr>'
        )
        table_html = f"""
<table>
  <thead><tr>
    <th>Datum</th><th>RFID</th><th>Wallbox</th><th>kWh</th><th>Status</th>
  </tr></thead>
  <tbody>{table_rows}</tbody>
</table>"""
    else:
        table_html = '<div class="empty">Keine Sessions in diesem Monat</div>'

    export_url = f'export?year={year}&month={month}'
    content = f"""
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
    <h3 style="margin:0">Verlauf</h3>
    <a href="{export_url}" class="btn-export">⬇ CSV exportieren</a>
  </div>
  <div class="month-tabs">{tabs_html}</div>
  {table_html}
</div>"""

    return _base(_nav('history'), content, base_href)


def _build_live_page(session_manager, api_state, base_href=''):
    """Live-Ansicht laufender Lade-Sessions mit Fortschritt (Auto-Refresh 5s)"""
    active = _db_active_sessions(session_manager.db_path)

    current_energy = api_state.get('current_energy') if api_state else None
    wallbox_state  = api_state.get('wallbox_state')  if api_state else None
    last_update    = api_state.get('last_update')    if api_state else None

    # Sensor-Status-Banner
    if current_energy is None:
        sensor_banner = (
            '<div class="msg err" style="margin-bottom:14px">'
            'Kein aktueller Energie-Wert vom HA-Sensor. '
            'Prüfe sensor_energy in der Addon-Konfiguration.'
            '</div>'
        )
    else:
        state_chip = ''
        if wallbox_state:
            sl = wallbox_state.lower()
            if 'charging' in sl and 'stopped' not in sl:
                color = '#2e7d32'   # grün
            elif any(k in sl for k in ['available', 'idle', 'finished', 'finishing']):
                color = '#999'       # grau
            elif any(k in sl for k in ['faulted', 'unavailable', 'stopped']):
                color = '#c0392b'    # rot
            else:
                color = '#f39c12'    # orange (Übergang)
            state_chip = f'<span style="background:{color};color:#fff;padding:2px 8px;border-radius:3px;font-size:12px">{wallbox_state}</span>'
        sensor_banner = (
            f'<div class="msg ok" style="margin-bottom:14px">'
            f'Zähler aktuell: <strong>{current_energy:.3f} kWh</strong> {state_chip}'
            f'<span style="float:right;color:#666;font-size:12px">'
            f'Sensor zuletzt: {last_update or "—"}</span>'
            f'</div>'
        )

    if not active:
        body = (
            '<div style="text-align:center;padding:40px;color:#888">'
            '<div style="font-size:48px;margin-bottom:8px">⚡</div>'
            '<div>Aktuell läuft kein Ladevorgang.</div>'
            '<div style="font-size:13px;margin-top:6px">'
            'Sobald eine RFID-Karte an die Wallbox gehalten wird, '
            'erscheint die Session hier in Echtzeit.</div>'
            '</div>'
        )
    else:
        now = datetime.now()
        rows_html = ''
        for s in active:
            try:
                start_dt = datetime.fromisoformat(s['start_time'])
            except (ValueError, TypeError):
                start_dt = now
            elapsed = (now - start_dt).total_seconds()

            start_energy = float(s.get('start_energy_kwh') or 0.0)
            if current_energy is not None and current_energy >= start_energy:
                kwh_delta = current_energy - start_energy
                kwh_str   = f'{kwh_delta:.3f} kWh'
            else:
                kwh_str = '—'

            rfid_prefix = (s.get('rfid_hash') or '')[:16] + '…'
            wallbox     = s.get('wallbox_id') or '—'
            start_str   = start_dt.strftime('%d.%m.%Y %H:%M:%S')

            rows_html += f"""
  <div style="border:1px solid #e0e0e0;border-radius:6px;padding:14px;margin-bottom:10px;
              background:#fff;border-left:4px solid #2e7d32">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
      <div>
        <div style="font-size:12px;color:#666">Session #{s['id']} · {wallbox}</div>
        <code style="font-size:13px">{rfid_prefix}</code>
      </div>
      <div style="font-size:24px;font-weight:bold;color:#2e7d32">{kwh_str}</div>
    </div>
    <div style="display:flex;gap:24px;font-size:13px;color:#555">
      <div><b>Start:</b> {start_str}</div>
      <div><b>Dauer:</b> {_fmt_duration(elapsed)}</div>
      <div><b>Start-Zählerstand:</b> {start_energy:.3f} kWh</div>
    </div>
  </div>"""
        body = rows_html

    content = f"""
<div class="card">
  <h2>🔴 Laufende Ladevorgänge</h2>
  {sensor_banner}
  {body}
  <div style="text-align:center;color:#888;font-size:11px;margin-top:12px">
    Auto-Refresh alle 5 Sekunden
  </div>
</div>
<meta http-equiv="refresh" content="5">"""
    return _base(_nav('live'), content, base_href)

# ---------------------------------------------------------------------------
# App-Factory
# ---------------------------------------------------------------------------

def create_app(session_manager, config, api_state):
    """
    api_state: dict mit key 'client' → WallboxApiClient oder None.
    Wird von main.py befüllt und kann sich zur Laufzeit ändern.
    """
    from utils.hash import hash_rfid

    # -- GET / ---------------------------------------------------------------
    async def handle_get(request):
        base_href = request.headers.get('X-Ingress-Path', '').rstrip('/')
        msg = request.rel_url.query.get('msg', '')
        t   = request.rel_url.query.get('t', '')
        msg_html = f'<div class="msg {t}">{msg}</div>' if msg else ''
        return web.Response(
            text=_build_form_page(session_manager, config, msg_html, base_href=base_href),
            content_type='text/html'
        )

    # -- POST / --------------------------------------------------------------
    async def handle_post(request):
        base_href = request.headers.get('X-Ingress-Path', '').rstrip('/')
        msg_html = ''
        try:
            data     = await request.post()
            rfid_hex = data.get('rfid', '').strip()
            kwh_str  = data.get('kwh', '').strip()
            date_str = data.get('date', datetime.now().date().isoformat()).strip()

            if not rfid_hex:
                raise ValueError("Keine RFID ausgewählt.")
            kwh = float(kwh_str) if kwh_str else 0.0
            if kwh <= 0:
                raise ValueError("kWh muss größer als 0 sein.")

            rfid_hash  = hash_rfid(rfid_hex)
            wallbox_id = config.get('wallbox_id', 'wallbox')
            sid        = session_manager.add_manual_session(rfid_hash, kwh, wallbox_id, date_str)

            if sid:
                msg_html = (f'<div class="msg ok">Session #{sid} gespeichert: '
                            f'<strong>{kwh:.3f} kWh</strong> am {date_str}</div>')
            else:
                raise ValueError("Session konnte nicht gespeichert werden.")
        except Exception as exc:
            msg_html = f'<div class="msg err">Fehler: {exc}</div>'

        return web.Response(
            text=_build_form_page(session_manager, config, msg_html, base_href=base_href),
            content_type='text/html'
        )

    # -- POST /transmit -------------------------------------------------------
    async def handle_transmit(request):
        base_href = request.headers.get('X-Ingress-Path', '').rstrip('/')
        client = api_state.get('client')

        if not client:
            from api_client import WallboxApiClient
            url   = config.get('dolibarr_url', '')
            token = config.get('api_token', '')
            if url and token and url != 'https://dolibarr.example.com':
                try:
                    c = WallboxApiClient(base_url=url, api_token=token, timeout=30)
                    if c.check_connection():
                        client = c
                        api_state['client'] = c
                        _LOGGER.info("API-Client on-demand erstellt")
                except Exception as e:
                    _LOGGER.warning("API-Client Fehler: %s", e)

        if not client:
            msg_html = '<div class="msg err">Dolibarr API nicht erreichbar — bitte URL und Token prüfen.</div>'
        else:
            try:
                result = session_manager.transmit_completed_sessions(client)
                sent   = result.get('transmitted', 0)
                failed = result.get('failed', 0)
                if sent == 0 and failed == 0:
                    msg_html = '<div class="msg warn">Keine ausstehenden Sessions.</div>'
                elif failed > 0:
                    err = result['errors'][0] if result['errors'] else ''
                    msg_html = f'<div class="msg err">{sent} übertragen, {failed} fehlgeschlagen: {err}</div>'
                else:
                    msg_html = f'<div class="msg ok">{sent} Session(s) erfolgreich an Dolibarr übertragen.</div>'
            except Exception as exc:
                msg_html = f'<div class="msg err">Übertragungsfehler: {exc}</div>'

        return web.Response(
            text=_build_form_page(session_manager, config, msg_html, base_href=base_href),
            content_type='text/html'
        )

    # -- GET /history --------------------------------------------------------
    async def handle_history(request):
        base_href = request.headers.get('X-Ingress-Path', '').rstrip('/')
        year  = int(request.rel_url.query.get('year',  0))
        month = int(request.rel_url.query.get('month', 0))
        return web.Response(
            text=_build_history_page(session_manager, year, month, base_href=base_href),
            content_type='text/html'
        )

    # -- GET /export ----------------------------------------------------------
    async def handle_export(request):
        now   = datetime.now()
        year  = int(request.rel_url.query.get('year',  now.year))
        month = int(request.rel_url.query.get('month', now.month))
        rows  = _db_month(session_manager.db_path, year, month)

        output = io.StringIO()
        writer = csv.writer(output, delimiter=';')
        writer.writerow(['Datum', 'Uhrzeit', 'RFID (Prefix)', 'Wallbox', 'kWh', 'Status', 'Übertragen am'])
        for s in rows:
            date_s  = (s.get('start_time') or '')[:10]
            time_s  = (s.get('start_time') or '')[11:16]
            rfid_s  = (s.get('rfid_hash') or '')[:16] + '…'
            wbx_s   = s.get('wallbox_id') or ''
            kwh_s   = f"{(s.get('total_kwh') or 0):.3f}".replace('.', ',')
            status  = 'übertragen' if s.get('transmitted_at') else 'ausstehend'
            tx_time = (s.get('transmitted_at') or '')[:16]
            writer.writerow([date_s, time_s, rfid_s, wbx_s, kwh_s, status, tx_time])

        filename = f'wallbox_{year}_{str(month).zfill(2)}.csv'
        # aiohttp content_type darf KEINE Parameter (z.B. charset) enthalten —
        # sonst ValueError → 500. Mit BOM für korrekte Umlaut-Darstellung in Excel.
        body = '﻿' + output.getvalue()
        return web.Response(
            body=body.encode('utf-8'),
            content_type='text/csv',
            charset='utf-8',
            headers={'Content-Disposition': f'attachment; filename="{filename}"'}
        )

    # -- GET /live ------------------------------------------------------------
    async def handle_live(request):
        base_href = request.headers.get('X-Ingress-Path', '').rstrip('/')
        return web.Response(
            text=_build_live_page(session_manager, api_state, base_href=base_href),
            content_type='text/html'
        )

    app = web.Application()
    app.router.add_get('/',          handle_get)
    app.router.add_post('/',         handle_post)
    app.router.add_post('/transmit', handle_transmit)
    app.router.add_get('/live',      handle_live)
    app.router.add_get('/history',   handle_history)
    app.router.add_get('/export',    handle_export)
    return app


async def start_web_server(session_manager, config, api_state, port=8099):
    app    = create_app(session_manager, config, api_state)
    runner = web.AppRunner(app)
    await runner.setup()
    site = web.TCPSite(runner, '0.0.0.0', port)
    await site.start()
    _LOGGER.info("Ingress Web-Server gestartet auf Port %d", port)
