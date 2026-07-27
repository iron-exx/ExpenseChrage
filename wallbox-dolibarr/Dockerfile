ARG BUILD_FROM=ghcr.io/home-assistant/base:3.23
FROM ${BUILD_FROM}

# Build-Args (wurden früher über build.json übergeben)
ARG BUILD_VERSION=dev
ARG BUILD_ARCH=amd64
ARG BUILD_DATE
ARG BUILD_REF

LABEL \
  io.hass.name="ExpenseCharge" \
  io.hass.description="Schreibt RFID-Ladevorgänge der Alfen Eve Wallbox direkt als Position in die Dolibarr-Spesenabrechnung des Mitarbeiters" \
  io.hass.type="addon" \
  io.hass.version="${BUILD_VERSION}" \
  io.hass.arch="${BUILD_ARCH}" \
  org.opencontainers.image.source="https://github.com/iron-exx/ExpenseChrage"

# Alle Abhängigkeiten über apk. 5-fach-Retry gegen transiente Aussetzer.
#
# HINWEIS: Wenn dieser Schritt mit "dl-cdn.alpinelinux.org DNS: transient
# error" fehlschlägt, ist das DNS des HA-BUILD-Containers kaputt (häufig
# IPv6) — KEIN Fehler dieses Addons. Entweder Host-DNS reparieren
# (ha dns options --servers dns://1.1.1.1 && ha dns restart) ODER das
# vorgebaute Image aus GHCR nutzen (siehe image:-Feld in config.yaml,
# wird per GitHub Actions gebaut und vom HA-Host nur noch gepullt).
RUN for i in 1 2 3 4 5; do \
      echo "apk-Versuch $i/5..." && \
      apk add --no-cache \
        python3 \
        py3-aiohttp \
        py3-requests \
        sqlite-libs \
        ca-certificates \
      && break || { echo "apk fehlgeschlagen (Versuch $i) — warte 8s"; sleep 8; }; \
    done; \
    python3 -c "import aiohttp, requests, sqlite3; print('deps OK', __import__('sys').version)"

# Addon-Dateien kopieren
COPY main.py            /usr/local/bin/main.py
COPY api_client.py      /usr/local/bin/api_client.py
COPY session_manager.py /usr/local/bin/session_manager.py
COPY web_server.py      /usr/local/bin/web_server.py
COPY utils/             /usr/local/bin/utils/
RUN chmod a+x /usr/local/bin/main.py

CMD ["python3", "/usr/local/bin/main.py"]
