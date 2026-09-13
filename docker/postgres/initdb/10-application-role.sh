#!/bin/sh
# Provisions the second database role the design calls for (§4.4.4).
#
# Two roles, not one:
#   $POSTGRES_USER   owns the database and runs the migrations
#   $APP_DB_ROLE     the role the running application connects as
#
# The split is what makes the append-only guarantee of the audit log (FR-33,
# NFR-14) enforceable. The migration that revokes UPDATE and DELETE on
# `audit_logs` cannot revoke them from the role it is itself connected as, so
# the application role has to exist before the migration runs — otherwise the
# migration skips itself and the guarantee silently does not hold.
#
# This script runs once, when the data directory is first initialised.
set -eu

APP_DB_ROLE="${APP_DB_ROLE:-app_rw}"
APP_DB_PASSWORD="${APP_DB_PASSWORD:-app-secret}"

psql -v ON_ERROR_STOP=1 \
     --username "$POSTGRES_USER" \
     --dbname "$POSTGRES_DB" \
     --set=approle="$APP_DB_ROLE" \
     --set=appsecret="$APP_DB_PASSWORD" <<'SQL'
-- No SUPERUSER, no CREATEDB, no CREATEROLE: this role reads and writes rows,
-- and nothing else.
CREATE ROLE :"approle" WITH LOGIN PASSWORD :'appsecret'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;

GRANT CONNECT ON DATABASE :"DBNAME" TO :"approle";
GRANT USAGE ON SCHEMA public TO :"approle";

-- Tables the migrations have already created, if any.
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO :"approle";
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO :"approle";

-- Tables the migrations create later. Without this every migration would have
-- to remember to grant, and one that forgot would fail at runtime. The audit
-- log is the deliberate exception: migration 2026_09_13_120400 takes UPDATE
-- and DELETE back off `audit_logs` once the table exists.
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO :"approle";
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO :"approle";
SQL

echo "[initdb] application role '$APP_DB_ROLE' created without superuser rights"
