# Dormitory Student Activity Support System

A web system that supports the everyday activities of students living in university dormitories:
guest passes approved by the duty officer and checked at the security post, announcements,
lost-and-found and maintenance requests. The backend is a Laravel 13 REST API on PHP 8.3 backed by
PostgreSQL 17, Redis and S3-compatible object storage; the client is a React and TypeScript
single-page application built by Vite. The repository holds the two applications side by side —
`backend/` and `frontend/` — plus the Docker Compose environment that runs the whole stack locally.

## Requirements

- Docker with Compose v2
- Node.js 20 or newer (only for running the frontend dev server outside Docker)

## Bringing the environment up

```sh
cp backend/.env.example backend/.env
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --database=pgsql_owner
docker compose exec app php artisan db:seed --database=pgsql_owner
```

`--database=pgsql_owner` is not optional. Schema work runs as the owning role; everything else runs
as the application role. The next section says why.

PHP dependencies are not committed, so the application container installs them on its first start;
the worker and the scheduler wait for that to finish. Nothing else has to be prepared by hand.

Compose starts seven services:

| Service | Image | What it does | Published on |
|---|---|---|---|
| `app` | local build, PHP 8.3 FPM | REST API application | — (behind `web`) |
| `web` | `nginx:1.29-alpine` | web server in front of the application | http://localhost:8080 |
| `queue` | local build | `php artisan queue:work` | — |
| `scheduler` | local build | `php artisan schedule:work` | — |
| `postgres` | `postgres:17-alpine` | system of record | `localhost:5432` |
| `redis` | `redis:8-alpine` | queue, cache, sessions, rate-limit counters | `localhost:6379` |
| `minio` | `quay.io/minio/minio` | S3-compatible object storage | http://localhost:9000, console http://localhost:9001 |

Every port and credential has a default, so `docker compose up -d` needs no further configuration.
To change one, put the variable in a `.env` file next to `docker-compose.yml`: `APP_PORT`,
`POSTGRES_PORT`, `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD`, `APP_DB_ROLE`,
`APP_DB_PASSWORD`, `TEST_DB_NAME`, `REDIS_PORT`, `MINIO_PORT`, `MINIO_CONSOLE_PORT`,
`MINIO_ROOT_USER`, `MINIO_ROOT_PASSWORD`, `MINIO_BUCKET`.

### Two database roles, two connections

The audit log is append-only (FR-33, NFR-14). The guarantee is enforced by the database, not by a
check in a model: a migration revokes UPDATE and DELETE on `audit_logs` from the role the service
connects as. A role cannot revoke those rights from itself, and the owner of a table cannot be locked
out of it — so the service has to run as a role that owns nothing.

`docker/postgres/initdb/10-application-role.sh` provisions two roles, and `config/database.php`
defines one connection for each:

| Connection | Role | Used by |
|---|---|---|
| `pgsql` (default) | `app_rw`, no superuser rights, owns nothing | requests, queue worker, scheduler |
| `pgsql_owner` | `dormitory`, owns the database | `migrate`, `db:seed`, and nothing else |

Under `pgsql` a direct `UPDATE` or `DELETE` on `audit_logs` — query builder, raw SQL, Eloquent, it
makes no difference — comes back as `SQLSTATE[42501] permission denied for table audit_logs`, while
`INSERT` and `SELECT` work normally. Every other table keeps full DML: the init script sets
`ALTER DEFAULT PRIVILEGES`, so tables created by later migrations are covered without each migration
having to remember to grant.

`DB_APPLICATION_ROLE` tells the revocation migration which role to target and must match
`DB_USERNAME`, which must differ from `DB_OWNER_USERNAME`.

### Object storage

The bucket is created once per fresh volume:

```sh
docker compose exec minio mc alias set local http://127.0.0.1:9000 dormitory dormitory-secret
docker compose exec minio mc mb --ignore-existing local/dormitory
```

## Backend

The API answers on http://localhost:8080. Artisan runs inside the application container:

```sh
docker compose exec app php artisan migrate:fresh --seed --database=pgsql_owner
docker compose logs -f queue
```

### Tests

The suite runs against its own database, `dormitory_test`, created by the same init script, so a test
run never touches development data. It needs the owning connection, because `RefreshDatabase` creates
and drops tables:

```sh
docker compose exec \
  -e DB_CONNECTION=pgsql_owner -e DB_DATABASE=dormitory_test \
  -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync \
  -e MAIL_MAILER=array -e FILESYSTEM_DISK=local \
  app php artisan test
```

The overrides after the two database ones are not cosmetic. `phpunit.xml` asks for array cache,
array sessions and a synchronous queue, but its `<env>` entries do not overwrite a variable the
container already defines, so without them the login throttle keeps its counters in Redis and leaks
state from one test into the next.

The container image is pinned to PHP 8.3 and `composer.json` pins the Composer platform to the same
version, so the lock file resolves to packages that run there even when the host has a newer PHP.
Install dependencies through the container rather than the host:

```sh
docker compose exec app composer install
```

Schema changes reach the database only through a migration committed to the repository; nothing is
applied by hand.

## Frontend

```sh
cd frontend
npm install
npm run dev
```

Vite serves the SPA on http://localhost:5173 and it talks to the API at http://localhost:8080.
`frontend/.npmrc` pins the public npm registry, so `npm install` works on a machine whose global npm
configuration points at a private mirror.

Production build and linting:

```sh
npm run build
npm run lint
```

## Shutting down

```sh
docker compose down          # stops the containers, keeps the data
docker compose down -v       # also drops the PostgreSQL, Redis and MinIO volumes
```
