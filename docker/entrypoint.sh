#!/bin/sh
set -e

# Render (y otras plataformas) indican el puerto en $PORT
if [ -n "$PORT" ]; then
    sed -i "s/Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
    sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
fi

# Certificado CA de la base de datos gestionada (p. ej. el ca.pem de Aiven)
# pegado como variable de entorno: se guarda en un archivo y se usa para TLS.
if [ -n "$DB_SSL_CA_PEM" ]; then
    printf '%s\n' "$DB_SSL_CA_PEM" > /etc/ssl/certs/db-ca.pem
    export DB_SSL_CA=/etc/ssl/certs/db-ca.pem
fi

# Migraciones pendientes en cada arranque (idempotente)
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    php bin/parce migrate
fi

# Datos de demostración solo si se piden explícitamente (no en producción real)
if [ "${SEED_DEMO:-false}" = "true" ]; then
    php bin/parce seed
fi

# Los comandos anteriores corren como root y pueden crear logs: Apache
# (www-data) debe poder seguir escribiendo en storage/ y en las cargas.
chown -R www-data:www-data storage public/uploads

exec "$@"
