# Running and deploying

The short version is in [../README.md](../README.md); this is everything else.

A web system that supports the everyday activities of students living in university dormitories:
guest passes approved by the staff of the dormitory and checked at the security post,
announcements, lost-and-found and maintenance requests. The backend is a Laravel 13 REST API on
PHP 8.3 backed by PostgreSQL 17, Redis and S3-compatible object storage; the client is a React
and TypeScript single-page application built by Vite. The repository holds the two applications
side by side — `backend/` and `frontend/` — plus the Docker Compose environment that runs the
whole stack locally.

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

### The API reference

http://localhost:8080/api/docs renders `backend/api/openapi.yaml` — the same file Orval reads to
generate the front end's client. Raw: http://localhost:8080/api/docs/openapi.yaml.

`Authorize` takes the token from `POST /api/v1/auth/login`. Seeded accounts share the password
`password`:

```sh
curl -sS -X POST http://localhost:8080/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.test","password":"password"}'
```

Swagger UI is vendored under `backend/public/swagger-ui/`, so the page needs no route to the
internet.

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

## Deployment

`docker-compose.prod.yml` is the server stack. It is used on its own — never layered over
`docker-compose.yml` — so no bind mount or published port of the development environment can reach
a server by being forgotten. The source tree is baked into the images, the SPA is built by
`docker/nginx/Dockerfile` and served from the same origin as the API. The stack publishes one port,
on the loopback: TLS and the public address belong to the host's nginx, which on this server already
serves another site.

`.github/workflows/deploy.yml` runs the tests on every push and pull request and deploys from a
green `main`: SSH to the server, `git reset --hard` to the pushed commit, then `scripts/deploy.sh`.
`.gitlab-ci.yml` is untouched — the project also goes to the faculty GitLab.

### Once, on the server

```sh
git clone https://github.com/yyahya-2000/DormHub.git /srv/dormhub
cd /srv/dormhub
cp scripts/env.example .env && chmod 600 .env   # then fill in every empty value
docker run --rm php:8.3-cli-alpine php -r \
    'echo "base64:", base64_encode(random_bytes(32)), PHP_EOL;'   # APP_KEY
./scripts/deploy.sh
docker compose -f docker-compose.prod.yml run --rm app \
    php artisan db:seed --class=RoleSeeder --database=pgsql_owner
docker compose -f docker-compose.prod.yml run --rm app \
    php artisan dormitory:create-administrator you@example.ru 'Your Name'
```

`db:seed` without `--class` also plants the demonstration accounts, whose password is `password`.
On a server reachable from the internet, seed the roles and create the administrator — the command
generates a one-time password and marks the account for a change on first sign-in.

Point the domain's A record at the server. Then put the stack behind the host's nginx —
`docker/nginx/host-vhost.conf.example` is the server block — and issue the certificate with the
certbot that already serves the other site:

```sh
cp docker/nginx/host-vhost.conf.example /etc/nginx/sites-available/<domain>
sed -i 's/DOMAIN/<domain>/' /etc/nginx/sites-available/<domain>
ln -s /etc/nginx/sites-available/<domain> /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
certbot --nginx -d <domain>
```

### Repository secrets

| Secret | Value |
|---|---|
| `DEPLOY_HOST` | server address |
| `DEPLOY_USER` | the user that owns the checkout and is in the `docker` group |
| `DEPLOY_PATH` | the checkout, e.g. `/srv/dormhub` |
| `DEPLOY_SSH_KEY` | private key, whose public half is in that user's `authorized_keys` |
| `DEPLOY_KNOWN_HOSTS` | output of `ssh-keyscan -H <server>` |
| `DEPLOY_PORT` | SSH port, only if it is not 22 |

Nothing else belongs in GitHub: the domain, `APP_KEY` and every password live in `.env` on the
server, which is in `.gitignore`.

### What a deploy does, and how to undo it

`scripts/deploy.sh` builds the images, dumps the database to `backups/db-<timestamp>.dump`, then
runs the migrations **against the new image while the old containers are still serving**. Only a
migration that succeeded is followed by `up -d`; a migration that fails leaves the previous release
running and prints the `pg_restore` line for the dump it just took. The framework caches are
rebuilt by the entrypoint of every new container, so a deploy cannot leave a stale route cache
behind. Last, `/up` is polled through nginx, and the deploy fails if it does not answer.

To roll back, reset the checkout to the previous commit and run the script again:

```sh
cd /srv/dormhub && git reset --hard <previous sha> && ./scripts/deploy.sh
```

That restores the code, not the schema. If the failed release also migrated, restore the dump
first:

```sh
docker compose -f docker-compose.prod.yml exec -T postgres \
    pg_restore -U dormitory -d dormitory --clean --if-exists < backups/db-<timestamp>.dump
```

## Continuous integration

`.gitlab-ci.yml` describes a GitLab CI pipeline that runs on every push and on every merge request.
Four jobs in three stages:

| Stage | Job | What it runs |
|---|---|---|
| `style` | `backend:style` | `pint --test` over `backend/` |
| `style` | `frontend:style` | `oxlint` over `frontend/` |
| `test` | `backend:test` | migrations and `php artisan test` against PostgreSQL 17 and Redis, with line coverage |
| `build` | `frontend:build` | `tsc -b` and `vite build`, publishing `frontend/dist` |

The PHP jobs start from `php:8.3-cli-alpine` and build the same extensions as `docker/php/Dockerfile`,
plus PCOV. PCOV is the coverage driver rather than Xdebug: it counts executed lines and nothing else,
which is what NFR-12 asks for, and it costs a fraction of Xdebug's run time. Neither driver belongs in
the application image — coverage is a property of the pipeline, not of the deployable unit.
Composer's download cache and `backend/vendor` are keyed on `composer.lock`, npm's cache on
`package-lock.json`, so a run that changes no dependency downloads nothing. `node_modules` is not
cached, because `npm ci` deletes it before it starts.

### The test job

`postgres:17-alpine` and `redis:8-alpine` run as service containers. A service container cannot be
handed the repository's init directory, so the job runs
`docker/postgres/initdb/10-application-role.sh` against the database itself — the same script Compose
runs at first boot. The restricted `app_rw` role and the `dormitory_test` database therefore exist in
CI exactly as they do locally, and the privilege assertion of the audit log test has something real to
assert against instead of skipping. The suite then runs on the `pgsql_owner` connection against
`dormitory_test`, because `RefreshDatabase` creates and drops tables.

The cache, session and queue stores stay pointed at Redis in the job environment, which is deliberate.
The isolation those tests need lives in `backend/tests/TestCase.php`, not in `phpunit.xml`, for the
reason «Tests» above gives; leaving the production-shaped stores configured in CI is what keeps
that guard honest. If it ever stops working, the login throttle tests of FR-08 fail in the pipeline
rather than on somebody's machine.

The job writes an empty `.env` before it starts. Laravel's test runner reads the environment file to
restore it around the run, and the repository carries none; every value comes from the job variables.

### Coverage

`php artisan test --coverage --min=70` prints the percentage per file and a total over `app/`. The
job exposes it three ways: the `Total: NN.N %` line, which the `coverage:` expression in the job turns
into the pipeline's coverage figure and badge; a Cobertura report, which annotates the changed lines
of a merge request; and a JUnit report, which puts failures on the merge request itself.

`--min=70` is the threshold of NFR-12, and **the job fails below it**. The suite measured 80.9 % of the
lines of `app/` when this section was written, so the gate has room as it stands; a gate that only
warns is a gate that gets ignored, and a requirement no pipeline enforces is a requirement on paper.
Relaxing it to a warning is one line — `allow_failure: true` on the job — if a later increment needs
room to land code before its tests.

## Shutting down

```sh
docker compose down          # stops the containers, keeps the data
docker compose down -v       # also drops the PostgreSQL, Redis and MinIO volumes
```
