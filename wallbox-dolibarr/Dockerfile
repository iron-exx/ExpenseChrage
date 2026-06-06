ARG BUILD_FROM=ghcr.io/home-assistant/base:3.23
FROM ${BUILD_FROM}

# Build-Args (wurden früher über build.json übergeben)
ARG BUILD_VERSION=dev
ARG BUILD_ARCH=amd64
ARG BUILD_DATE
ARG BUILD_REF

LABEL \
  io.hass.name="Wallbox Dolibarr Invoice" \
  io.hass.description="Schreibt RFID-Ladevorgänge der Alfen Eve Wallbox direkt als Position in die Dolibarr-Spesenabrechnung des Mitarbeiters" \
  io.hass.type="addon" \
  io.hass.version="${BUILD_VERSION}" \
  io.hass.arch="${BUILD_ARCH}" \
  org.opencontainers.image.source="https://github.com/iron-exx/evcharge-dolibarr-invoice"

# Alle Abhängigkeiten über apk (kein pip nötig, PEP-668-kompatibel).
#
# WICHTIG — DNS-Workaround: Auf manchen HA-Hosts kann der Build-Container
# dl-cdn.alpinelinux.org nicht auflösen ("DNS: transient error"), obwohl das
# Base-Image von ghcr.io problemlos lädt (kaputtes/IPv6-DNS im Build-Netz).
# Daher setzen wir im Build-Schritt explizit öffentliche Resolver in
# /etc/resolv.conf (wirkt nur während dieses RUN, das Laufzeit-DNS des
# Containers bleibt unberührt). Zusätzlich 5-fach-Retry gegen Aussetzer.
RUN printf 'nameserver 1.1.1.1\nnameserver 8.8.8.8\nnameserver 9.9.9.9\n' > /etc/resolv.conf; \
    for i in 1 2 3 4 5; do \
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
