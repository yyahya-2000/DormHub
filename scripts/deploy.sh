#!/bin/sh
# Deploys the checkout the workflow has already reset to the pushed commit.
# Run on the server, from the repository root or from anywhere:
#
#     ./scripts/deploy.sh
#
# The order is the point. Images are built and migrations run against the new
# image while the old containers keep serving; only a migration that succeeded
# is followed by a restart. A migration that fails halfway leaves the previous
# release running against a database it no longer matches — which is why the
# dump below is taken first and named in the failure message.
set -eu

cd "$(dirname "$0")/.."

COMPOSE="docker compose -f docker-compose.prod.yml"

# Compose reads .env by itself; this script needs the same values for pg_dump.
set -a
. ./.env
set +a

STAMP=$(date -u +%Y%m%dT%H%M%SZ)

echo "==> building"
$COMPOSE build

echo "==> starting infrastructure"
$COMPOSE up -d --wait postgres redis minio
$COMPOSE up -d minio-bootstrap

echo "==> dumping the database to backups/db-$STAMP.dump"
mkdir -p backups
$COMPOSE exec -T postgres pg_dump \
    -U "${POSTGRES_USER:-dormitory}" -d "${POSTGRES_DB:-dormitory}" -Fc \
    > "backups/db-$STAMP.dump"
ls -1t backups/db-*.dump | tail -n +11 | xargs -r rm --

echo "==> migrating"
if ! $COMPOSE run --rm app php artisan migrate --database=pgsql_owner --force; then
    echo "migration failed; the previous release is still running." >&2
    echo "restore with: $COMPOSE exec -T postgres pg_restore -U ${POSTGRES_USER:-dormitory} -d ${POSTGRES_DB:-dormitory} --clean --if-exists < backups/db-$STAMP.dump" >&2
    exit 1
fi

echo "==> restarting the stack"
$COMPOSE up -d --remove-orphans

# The framework caches are rebuilt by the entrypoint of every new container,
# so there is nothing to clear here.

echo "==> health check"
attempt=0
until $COMPOSE exec -T web wget -q -O /dev/null http://127.0.0.1/up; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
        echo "/up did not answer within 60 s" >&2
        $COMPOSE ps
        exit 1
    fi
    sleep 2
done

docker image prune -f >/dev/null
echo "==> deployed $(git rev-parse --short HEAD)"
