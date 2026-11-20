# syntax=docker/dockerfile:1
#
# Sound Management — imagen PHP-FPM (Fase 1).
#
#   target "dev"  : el código se monta como volumen (docker-compose.yml).
#                   Instalar dependencias una vez:
#                     docker compose run --rm app composer install
#   target "prod" : código y dependencias (sin dev) dentro de la imagen.
#
# Las migraciones YA NO se ejecutan automáticamente al arrancar el contenedor.
# Se lanzan a mano y de forma explícita:
#   docker compose exec app php artisan migrate
#
# PHP 8.4: composer.lock exige PHP >= 8.3 (nwidart/laravel-modules v13) y
# Laravel 11.51 admite 8.2–8.4.

ARG PHP_VERSION=8.4

FROM php:${PHP_VERSION}-fpm-bookworm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libonig-dev \
        libxml2-dev \
        libzip-dev \
        libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring exif pcntl bcmath gd zip intl opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/app.ini /usr/local/etc/php/conf.d/zz-app.ini

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=1

WORKDIR /var/www

# ─── Desarrollo ──────────────────────────────────────────────
FROM base AS dev
CMD ["php-fpm"]

# ─── Producción ──────────────────────────────────────────────
FROM base AS prod

ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

COPY . .
RUN rm -f public/hot \
    && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

USER www-data
CMD ["php-fpm"]
