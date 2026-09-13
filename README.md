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
docker compose exec app php artisan migrate
```

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
`APP_DB_PASSWORD`, `REDIS_PORT`, `MINIO_PORT`, `MINIO_CONSOLE_PORT`, `MINIO_ROOT_USER`,
`MINIO_ROOT_PASSWORD`, `MINIO_BUCKET`.

### Two database roles

PostgreSQL is provisioned with two roles rather than one, by
`docker/postgres/initdb/10-application-role.sh`:

- `dormitory` owns the database and runs the migrations;
- `app_rw` is the role the running service is meant to connect as, with no superuser rights.

The audit log is append-only (FR-33, NFR-14), and the migration that enforces it revokes UPDATE and
DELETE on `audit_logs` from `app_rw`. A role cannot revoke those rights from itself, so the two roles
have to be distinct or the migration skips itself and reports why on stderr. `DB_APPLICATION_ROLE`
names the second role and must stay different from `DB_USERNAME`.

### Object storage

The bucket is created once per fresh volume:

```sh
docker compose exec minio mc alias set local http://127.0.0.1:9000 dormitory dormitory-secret
docker compose exec minio mc mb --ignore-existing local/dormitory
```

## Backend

The API answers on http://localhost:8080. Artisan runs inside the application container:

```sh
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan test
docker compose logs -f queue
```

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
