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

exec docker-php-entrypoint "$@"
