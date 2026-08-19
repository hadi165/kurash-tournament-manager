#!/usr/bin/env bash
# dev.sh — start the Kurash Competition Manager for local development.
#
#   ./dev.sh          start the database and the app
#   ./dev.sh stop     stop both
#   ./dev.sh reset    wipe the database and re-import the legacy data
#
# The database runs in Docker because it should match the MariaDB on the
# DirectAdmin host. The app runs on the system PHP.
set -euo pipefail

PROJECT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
APP_DIR="${PROJECT_DIR}/kurash-manager"
DB_CONTAINER=kurash-mariadb
DB_PORT=3307
APP_PORT=${APP_PORT:-8000}

info() { printf '\033[36m›\033[0m %s\n' "$1"; }
ok()   { printf '\033[32m✓\033[0m %s\n' "$1"; }
die()  { printf '\033[31m✗\033[0m %s\n' "$1" >&2; exit 1; }

start_database() {
    if docker ps --format '{{.Names}}' | grep -qx "$DB_CONTAINER"; then
        ok "Database already running"
    elif docker ps -a --format '{{.Names}}' | grep -qx "$DB_CONTAINER"; then
        info "Starting the existing database container"
        docker start "$DB_CONTAINER" >/dev/null
    else
        info "Creating the database container"
        docker run -d --name "$DB_CONTAINER" \
            -e MARIADB_ROOT_PASSWORD=devroot \
            -e MARIADB_DATABASE=kurash \
            -e MARIADB_USER=kurash \
            -e MARIADB_PASSWORD=devpass \
            -p "127.0.0.1:${DB_PORT}:3306" \
            mariadb:10.11 >/dev/null
    fi

    info "Waiting for the database"
    for _ in $(seq 1 60); do
        if docker exec "$DB_CONTAINER" mariadb -ukurash -pdevpass -e 'SELECT 1' kurash >/dev/null 2>&1; then
            ok "Database ready on 127.0.0.1:${DB_PORT}"
            return
        fi
        sleep 1
    done
    die "Database did not come up. Check: docker logs ${DB_CONTAINER}"
}

ensure_test_database() {
    docker exec "$DB_CONTAINER" mariadb -uroot -pdevroot -e \
        "CREATE DATABASE IF NOT EXISTS kurash_test;
         GRANT ALL ON kurash_test.* TO 'kurash'@'%';
         FLUSH PRIVILEGES;" 2>/dev/null || true
}

case "${1:-start}" in
    stop)
        info "Stopping the app"
        pkill -f "artisan serve --port=${APP_PORT}" 2>/dev/null || true
        info "Stopping the database"
        docker stop "$DB_CONTAINER" >/dev/null 2>&1 || true
        ok "Stopped"
        ;;

    reset)
        start_database
        cd "$APP_DIR"
        info "Rebuilding the schema"
        php artisan migrate:fresh --force >/dev/null
        info "Importing the legacy SQLite data"
        # Needs pdo_sqlite AND pdo_mysql at once, which no single PHP install
        # here has — see tools/Dockerfile.dev.
        docker build -q -t kurash-dev:php83 -f "${PROJECT_DIR}/tools/Dockerfile.dev" "${PROJECT_DIR}/tools" >/dev/null
        docker run --rm --network host -v "${PROJECT_DIR}":/proj -w /proj/kurash-manager \
            -u "$(id -u):$(id -g)" -e HOME=/tmp \
            -e DB_HOST=127.0.0.1 -e DB_PORT="${DB_PORT}" -e DB_DATABASE=kurash \
            -e DB_USERNAME=kurash -e DB_PASSWORD=devpass \
            kurash-dev:php83 php artisan kurash:import-legacy /proj/data/kurash.db --fresh
        info "Creating an administrator"
        php artisan kurash:create-admin --name="Administrator" --email="admin@kurash.local"
        ok "Reset complete"
        ;;

    start)
        [ -f "${APP_DIR}/.env" ] || die "No ${APP_DIR}/.env — copy .env.example and run: php artisan key:generate"

        start_database
        ensure_test_database

        cd "$APP_DIR"
        [ -d vendor ] || { info "Installing PHP dependencies"; composer install; }
        [ -d node_modules ] || { info "Installing JS dependencies"; npm install; }
        [ -d public/build ] || { info "Building assets"; npm run build; }

        info "Running migrations"
        php artisan migrate --force >/dev/null

        if [ "$(php artisan tinker --execute='echo App\Models\User::count();' 2>/dev/null | tail -1 | tr -d '[:space:]')" = "0" ]; then
            printf '\n'
            info "No accounts exist yet — creating one"
            php artisan kurash:create-admin --name="Administrator" --email="admin@kurash.local"
        fi

        printf '\n'
        ok "Starting on http://127.0.0.1:${APP_PORT}"
        printf '  Ctrl-C to stop the app. The database keeps running; ./dev.sh stop closes both.\n\n'
        exec php artisan serve --port="${APP_PORT}"
        ;;

    *)
        die "Usage: ./dev.sh [start|stop|reset]"
        ;;
esac
