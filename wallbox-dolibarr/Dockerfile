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
# Retry-Schleife (5 Versuche) gegen transiente DNS-/Netzwerk-Aussetzer beim
# Laden des Alpine-Paketindex (dl-cdn.alpinelinux.org). Vor jedem Versuch wird
# der Index frisch geholt; bei DNS-Hängern wartet sie und versucht es erneut,
# statt den ganzen Addon-Build abzubrechen.
RUN for i in 1 2 3 4 5; do \
      echo "apk-Versuch $i/5..." && \
      apk add --no-cache \
        python3 \
        py3-aiohttp \
        py3-requests \
        sqlite-libs \
        ca-certificates \
      && break || { echo "apk fehlgeschlagen (Versuch $i) — warte 10s"; sleep 10; }; \
    done && python3 -c "import aiohttp, requests, sqlite3; print('deps OK', __import__('sys').version)"

# Addon-Dateien kopieren
COPY main.py            /usr/local/bin/main.py
COPY api_client.py      /usr/local/bin/api_client.py
COPY session_manager.py /usr/local/bin/session_manager.py
COPY web_server.py      /usr/local/bin/web_server.py
COPY utils/             /usr/local/bin/utils/
RUN chmod a+x /usr/local/bin/main.py

CMD ["python3", "/usr/local/bin/main.py"]
