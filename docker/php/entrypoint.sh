#!/bin/sh
# On a fresh checkout backend/vendor is absent, because it is not committed.
# The application container installs it once; the worker and the scheduler wait
# for it rather than racing the same bind mount. This is what keeps the whole
# environment reachable with a single `docker compose up -d`.
set -e

cd /var/www/html

if [ "${COMPOSER_INSTALL_ON_BOOT:-0}" = "1" ]; then
    if [ ! -f vendor/autoload.php ]; then
        echo "[entrypoint] vendor/ is missing, installing PHP dependencies"
        COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist
    fi
else
    waited=0
    while [ ! -f vendor/autoload.php ] && [ "$waited" -lt 600 ]; do
        if [ "$waited" -eq 0 ]; then
            echo "[entrypoint] waiting for the application container to install vendor/"
        fi
        waited=$((waited + 2))
        sleep 2
    done
fi

# Rebuilt on every start rather than baked into the image: config:cache freezes
# the environment it runs in, and the environment is only complete here. Each
# command clears its own cache first, so a deploy cannot leave a stale one.
if [ "${APP_OPTIMIZE_ON_BOOT:-0}" = "1" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
    # The commands above run as root; php-fpm works as www-data.
    chown -R www-data:www-data storage bootstrap/cache
fi

exec docker-php-entrypoint "$@"
