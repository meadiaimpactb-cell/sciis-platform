# SCIIS API — PHP 8.3 FPM + nginx in a single image (listens on 8080)
#
# Build context is the REPO ROOT so this file can copy both ./api (source) and
# ./infra/docker/api/*.conf (runtime config) while api/ stays framework-only.
#
#   docker build -f infra/docker/api/Dockerfile --target dev  .
#   docker build -f infra/docker/api/Dockerfile --target prod .

# ───────────────────────── base ─────────────────────────
FROM php:8.3-fpm-alpine AS base

ENV TZ=Asia/Riyadh \
    COMPOSER_ALLOW_SUPERUSER=1

RUN apk add --no-cache \
        nginx supervisor curl tzdata icu-dev libzip-dev libpng-dev libjpeg-turbo-dev \
        freetype-dev postgresql-dev oniguruma-dev linux-headers $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip gd bcmath pcntl opcache mbstring exif \
    && pecl install redis && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS linux-headers \
    && cp /usr/share/zoneinfo/Asia/Riyadh /etc/localtime

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY infra/docker/api/nginx.conf       /etc/nginx/nginx.conf
COPY infra/docker/api/php.ini          /usr/local/etc/php/conf.d/zz-sciis.ini
COPY infra/docker/api/www.conf         /usr/local/etc/php-fpm.d/zz-sciis.conf
COPY infra/docker/api/supervisord.conf /etc/supervisord.conf
COPY infra/docker/api/entrypoint.sh    /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# ───────────────────────── dev ─────────────────────────
FROM base AS dev
ENV APP_ENV=local
RUN apk add --no-cache $PHPIZE_DEPS linux-headers \
    && pecl install xdebug && docker-php-ext-enable xdebug \
    && apk del $PHPIZE_DEPS linux-headers \
    && printf "xdebug.mode=off\nxdebug.client_host=host.docker.internal\n" > /usr/local/etc/php/conf.d/xdebug.ini
# Source is bind-mounted by docker compose; vendor/ lives in a named volume.
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]

# ───────────────────────── prod ─────────────────────────
FROM base AS prod
ENV APP_ENV=production APP_DEBUG=false
COPY api/composer.json api/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader
COPY api/ .
RUN composer dump-autoload --optimize --classmap-authoritative \
    && chown -R www-data:www-data storage bootstrap/cache
# config/route/event caches are built at container start by entrypoint.sh
# (they depend on runtime env), never baked into the image.
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
