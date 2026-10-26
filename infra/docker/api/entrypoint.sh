#!/bin/sh
# Entry point for api / horizon / scheduler containers.
set -e
cd /var/www/html

# Dev: install dependencies into the named volume on first boot
if [ "${APP_ENV}" = "local" ] && [ ! -f vendor/autoload.php ] && [ -f composer.json ]; then
  echo "[entrypoint] composer install (first run)"
  composer install --no-interaction --prefer-dist
fi

if [ -f artisan ]; then
  # Runtime caches depend on env → built at start, not in the image
  if [ "${APP_ENV}" = "production" ] || [ "${APP_ENV}" = "staging" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan event:cache
    php artisan view:clear >/dev/null 2>&1 || true
  fi
  php artisan storage:link >/dev/null 2>&1 || true
fi

# opcache timestamp validation: on in dev, off in prod
export PHP_OPCACHE_VALIDATE_TIMESTAMPS="$([ "${APP_ENV}" = "local" ] && echo 1 || echo 0)"

exec "$@"
