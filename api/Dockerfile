# syntax=docker/dockerfile:1.6
# SCIIS API — PHP 8.3 FPM with pdo_pgsql, redis, gd, intl, zip, bcmath, pcntl, opcache.

FROM php:8.3-fpm-bookworm AS base

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    TZ=Asia/Riyadh

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git unzip curl ca-certificates \
        libpq-dev libzip-dev libicu-dev \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
        libonig-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql gd intl zip bcmath pcntl opcache; \
    pecl install igbinary-3.2.16; \
    pecl install redis-6.1.0; \
    docker-php-ext-enable redis igbinary; \
    apt-get purge -y --auto-remove; \
    rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.memory_consumption=256'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'realpath_cache_size=4096K'; \
        echo 'realpath_cache_ttl=600'; \
        echo 'upload_max_filesize=30M'; \
        echo 'post_max_size=32M'; \
        echo 'memory_limit=512M'; \
        echo 'expose_php=0'; \
    } > /usr/local/etc/php/conf.d/sciis.ini

WORKDIR /var/www/api

# ---------------------------------------------------------------- vendor
FROM base AS vendor
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# ---------------------------------------------------------------- app
FROM base AS app
COPY --from=vendor /var/www/api/vendor ./vendor
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

USER www-data
EXPOSE 9000
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD php -r "exit(@fsockopen('127.0.0.1', 9000) ? 0 : 1);"
CMD ["php-fpm"]

# Horizon worker: docker run <image> php artisan horizon
# Scheduler:      docker run <image> php artisan schedule:work
