#!/usr/bin/env python3
"""
Ingress Web-Server für manuelle Ladevorangs-Erfassung.

Läuft auf Port 8099 (ingress_port in config.yaml).
Home Assistant proxied den Traffic über das Ingress-Panel.
"""
import logging
from datetime import datetime

from aiohttp import web

_LOGGER = logging.getLogger(__name__)

_HTML = """<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Manueller Ladevorgang</title>
  <style>
    *{{box-sizing:border-box;margin:0;padding:0}}
    body{{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
          background:#f0f2f5;min-height:100vh;display:flex;
          align-items:center;justify-content:center;padding:20px}}
    .card{{background:#fff;border-radius:12px;
           box-shadow:0 2px 10px rgba(0,0,0,.12);
           padding:28px;width:100%;max-width:380px}}
    h2{{font-size:20px;color:#212121;margin-bottom:20px}}
    label{{display:block;font-size:12px;font-weight:600;
           color:#666;margin:16px 0 5px;text-transform:uppercase;letter-spacing:.4px}}
    input,select{{width:100%;padding:10px 12px;border:1.5px solid #ddd;
                  border-radius:7px;font-size:15px;outline:none;
                  transition:border-color .2s}}
    input:focus,select:focus{{border-color:#03a9f4}}
    .kwh-wrap{{position:relative}}
    .kwh-wrap input{{padding-right:44px}}
    .kwh-unit{{position:absolute;right:12px;top:50%;
               transform:translateY(-50%);color:#aaa;font-size:14px}}
    button{{margin-top:22px;width:100%;padding:13px;background:#03a9f4;
            color:#fff;border:none;border-radius:7px;font-size:15px;
            font-weight:600;cursor:pointer;transition:background .2s}}
    button:hover{{background:#0288d1}}
    .msg{{margin-top:16px;padding:12px 14px;border-radius:7px;font-size:13px;line-height:1.5}}
    .ok{{background:#e8f5e9;color:#2e7d32;border:1px solid #a5d6a7}}
    .err{{background:#ffebee;color:#c62828;border:1px solid #ef9a9a}}
    .sessions{{margin-top:22px;border-top:1px solid #eee;padding-top:16px}}
    .sessions h3{{font-size:13px;color:#888;margin-bottom:10px;text-transform:uppercase;letter-spacing:.4px}}
    .session-row{{display:flex;justify-content:space-between;align-items:center;
                  padding:7px 0;border-bottom:1px solid #f5f5f5;font-size:13px}}
    .session-row:last-child{{border-bottom:none}}
    .session-kwh{{font-weight:700;color:#03a9f4}}
    .session-meta{{color:#999;font-size:12px}}
  </style>
</head>
<body>
  <div class="card">
    <h2>⚡ Manueller Ladevorgang</h2>
    {message_html}
    <form method="POST">
      <label>RFID-Karte</label>
      <select name="rfid" required>
        {rfid_options}
      </select>
      <label>Geladene Energie</label>
      <div class="kwh-wrap">
        <input type="number" name="kwh" min="0.001" step="0.001"
               placeholder="z.B. 12.500" required>
        <span class="kwh-unit">kWh</span>
      </div>
      <label>Datum</label>
      <input type="date" name="date" value="{today}" required>
      <button type="submit">Ladevorgang speichern</button>
    </form>
    {sessions_html}
  </div>
</body>
</html>"""


def _build_page(session_manager, config, message_html=''):
    whitelist  = config.get('rfid_whitelist', [])
    wallbox_id = config.get('wallbox_id', 'wallbox')
    today      = datetime.now().date().isoformat()

    rfid_options = '\n'.join(
        f'<option value="{r}">{r}</option>'
        for r in whitelist
    ) or '<option value="">— keine RFID konfiguriert —</option>'

    # Letzte 5 abgeschlossene Sessions
    sessions_html = ''
    try:
        rows = session_manager.get_completed_sessions(limit=5)
        if rows:
            rows_html = ''
            for s in rows:
                kwh  = s.get('total_kwh') or 0
                date = (s.get('start_time') or '')[:10]
                rid  = (s.get('rfid_hash') or '')[:8]
                manual = ' • manuell' if s.get('start_time', '').endswith('T12:00:00') else ''
                rows_html += (
                    f'<div class="session-row">'
                    f'<span><span class="session-kwh">{kwh:.3f} kWh</span>'
                    f'<br><span class="session-meta">{date} • {rid}…{manual}</span></span>'
                    f'</div>'
                )
            sessions_html = f'<div class="sessions"><h3>Letzte Sessions</h3>{rows_html}</div>'
    except Exception:
        pass

    return _HTML.format(
        message_html=message_html,
        rfid_options=rfid_options,
        today=today,
        sessions_html=sessions_html,
    )


def create_app(session_manager, config):
    """Erzeugt die aiohttp-App mit den Routen."""

    from utils.hash import hash_rfid  # lokaler Import wegen sys.path

    async def handle_get(request):
        html = _build_page(session_manager, config)
        return web.Response(text=html, content_type='text/html')

    async def handle_post(request):
        try:
            data       = await request.post()
            rfid_hex   = data.get('rfid', '').strip()
            kwh_str    = data.get('kwh', '').strip()
            date_str   = data.get('date', datetime.now().date().isoformat()).strip()

            if not rfid_hex:
                raise ValueError("Keine RFID ausgewählt.")
            kwh = float(kwh_str) if kwh_str else 0.0
            if kwh <= 0:
                raise ValueError("kWh muss größer als 0 sein.")

            wallbox_id = config.get('wallbox_id', 'wallbox')
            rfid_hash  = hash_rfid(rfid_hex)

            sid = session_manager.add_manual_session(rfid_hash, kwh, wallbox_id, date_str)
            if sid:
                msg_html = (
                    f'<div class="msg ok">Session #{sid} gespeichert: '
                    f'<strong>{kwh:.3f} kWh</strong> am {date_str} — '
                    f'wird beim nächsten Übertragungs-Intervall an Dolibarr gesendet.</div>'
                )
            else:
                raise ValueError("Session konnte nicht gespeichert werden.")

        except Exception as exc:
            msg_html = f'<div class="msg err">Fehler: {exc}</div>'

        html = _build_page(session_manager, config, message_html=msg_html)
        return web.Response(text=html, content_type='text/html')

    app = web.Application()
    app.router.add_get('/', handle_get)
    app.router.add_post('/', handle_post)
    return app


async def start_web_server(session_manager, config, port: int = 8099):
    """Startet den Ingress-Web-Server."""
    app    = create_app(session_manager, config)
    runner = web.AppRunner(app)
    await runner.setup()
    site = web.TCPSite(runner, '0.0.0.0', port)
    await site.start()
    _LOGGER.info("Ingress Web-Server gestartet auf Port %d", port)
