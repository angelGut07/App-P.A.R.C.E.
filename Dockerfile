# P.A.R.C.E — imagen única: API PHP + frontend React servidos por Apache
# desde el mismo dominio (la cookie de sesión siempre es de primera parte).

# 1) Frontend (React + Vite)
FROM node:20-alpine AS frontend
WORKDIR /build
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

# 2) Autoload de Composer (el backend no tiene dependencias de producción)
FROM composer:2 AS vendor
WORKDIR /build
COPY composer.json composer.lock ./
COPY app/ app/
RUN composer install --no-dev --no-interaction --optimize-autoloader --no-scripts

# 3) Imagen final
FROM php:8.2-apache
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers setenvif \
    && sed -i 's#/var/www/html#/var/www/html/public#g' /etc/apache2/sites-available/000-default.conf

COPY docker/apache.conf /etc/apache2/conf-enabled/parce.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/parce.ini
COPY docker/entrypoint.sh /usr/local/bin/parce-entrypoint

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=vendor /build/vendor vendor/
COPY --from=frontend /build/dist/ public/

RUN rm -rf frontend tests docker \
    && mkdir -p storage/logs storage/cache public/uploads \
    && chown -R www-data:www-data storage public/uploads \
    && chmod +x /usr/local/bin/parce-entrypoint

ENV APP_ENV=production APP_DEBUG=false
EXPOSE 80
ENTRYPOINT ["parce-entrypoint"]
CMD ["apache2-foreground"]
