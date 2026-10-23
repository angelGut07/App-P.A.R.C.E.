#!/bin/sh
set -e

# Render (y otras plataformas) indican el puerto en $PORT
if [ -n "$PORT" ]; then
    sed -i "s/Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
    sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
fi

# Migraciones pendientes en cada arranque (idempotente)
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php bin/parce migrate
fi

# Datos de demostración solo si se piden explícitamente (no en producción real)
if [ "${SEED_DEMO:-false}" = "true" ]; then
    php bin/parce seed
fi

exec "$@"
