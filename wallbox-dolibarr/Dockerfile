ARG BUILD_FROM=ghcr.io/home-assistant/base:3.23
FROM ${BUILD_FROM}

# Build-Args (wurden früher über build.json übergeben)
ARG BUILD_VERSION=dev
ARG BUILD_ARCH=amd64
ARG BUILD_DATE
ARG BUILD_REF

LABEL \
  io.hass.name="Wallbox Dolibarr Invoice" \
  io.hass.description="RFID-basierte Abrechnung von Wallbox-Ladevorgängen via Dolibarr" \
  io.hass.type="addon" \
  io.hass.version="${BUILD_VERSION}" \
  io.hass.arch="${BUILD_ARCH}" \
  org.opencontainers.image.source="https://github.com/iron-exx/evcharge-dolibarr-invoice"

# Alle Abhängigkeiten über apk (kein pip nötig, PEP-668-kompatibel)
RUN apk add --no-cache \
    python3 \
    py3-aiohttp \
    py3-requests \
    py3-yaml \
    sqlite-libs

# Addon-Dateien kopieren
COPY main.py            /usr/local/bin/main.py
COPY api_client.py      /usr/local/bin/api_client.py
COPY session_manager.py /usr/local/bin/session_manager.py
COPY web_server.py      /usr/local/bin/web_server.py
COPY utils/             /usr/local/bin/utils/
RUN chmod a+x /usr/local/bin/main.py

CMD ["python3", "/usr/local/bin/main.py"]
