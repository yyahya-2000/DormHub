#!/bin/sh
# Provisions the two database roles the design calls for (§4.4.4).
#
#   $POSTGRES_USER   owns the database, runs the migrations and the seeders
#   $APP_DB_ROLE     the role the running application connects as
#
# The split is what makes the append-only guarantee of the audit log (FR-33,
# NFR-14) enforceable. The migration that revokes UPDATE and DELETE on
# `audit_logs` cannot revoke them from the role it is itself connected as, and
# the owner of a table cannot be locked out of it — so the service has to run
# as a role that owns nothing. If the two roles were one, the migration would
# skip itself and the guarantee would silently not hold.
#
# A separate database is created for the test suite, so that running the tests
# does not drop the development data. It carries the same grants.
#
# This script runs once, when the data directory is first initialised.
set -eu

APP_DB_ROLE="${APP_DB_ROLE:-app_rw}"
APP_DB_PASSWORD="${APP_DB_PASSWORD:-app-secret}"
TEST_DB_NAME="${TEST_DB_NAME:-${POSTGRES_DB}_test}"

# The role is global to the cluster and is created once.
psql -v ON_ERROR_STOP=1 \
     --username "$POSTGRES_USER" \
     --dbname "$POSTGRES_DB" \
     --set=approle="$APP_DB_ROLE" \
     --set=appsecret="$APP_DB_PASSWORD" \
     --set=testdb="$TEST_DB_NAME" <<'SQL'
-- No SUPERUSER, no CREATEDB, no CREATEROLE: this role reads and writes rows,
-- and nothing else.
CREATE ROLE :"approle" WITH LOGIN PASSWORD :'appsecret'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;

CREATE DATABASE :"testdb" OWNER :"USER";
SQL

# The grants are per-database, so both databases get the same treatment.
for database in "$POSTGRES_DB" "$TEST_DB_NAME"; do
    psql -v ON_ERROR_STOP=1 \
         --username "$POSTGRES_USER" \
         --dbname "$database" \
         --set=approle="$APP_DB_ROLE" <<'SQL'
GRANT CONNECT ON DATABASE :"DBNAME" TO :"approle";
GRANT USAGE ON SCHEMA public TO :"approle";

-- Tables the migrations have already created, if any.
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO :"approle";
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO :"approle";

-- Tables the migrations create later. Without this every new migration would
-- have to remember to grant, and one that forgot would fail at runtime. The
-- audit log is the deliberate exception: migration 2026_09_13_120400 takes
-- UPDATE and DELETE back off `audit_logs` once the table exists.
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO :"approle";
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO :"approle";
SQL
    echo "[initdb] granted '$APP_DB_ROLE' read and write on database '$database'"
done

echo "[initdb] application role '$APP_DB_ROLE' created without superuser rights"
