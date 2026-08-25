#!/usr/bin/env bash
# dev.sh — start the Kurash Competition Manager for local development.
#
#   ./dev.sh          start the database and the app
#   ./dev.sh stop     stop both
#   ./dev.sh reset    back up, wipe the database, refill it with demo data
#                     (accounts are carried across — nothing else is)
#
# The database runs in Docker because it should match the MariaDB on the
# DirectAdmin host. The app runs on the system PHP.
#
# The app listens on every interface, not just loopback, because a competition
# is several screens at once — a mat screen on a tablet, a scoreboard on the
# projector machine, the desk on a laptop — and none of them are this computer.
# Override with APP_HOST=127.0.0.1 ./dev.sh to go back to loopback only.
set -euo pipefail

PROJECT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
APP_DIR="${PROJECT_DIR}/kurash-manager"
DB_CONTAINER=kurash-mariadb
DB_PORT=3307
APP_PORT=${APP_PORT:-8000}
APP_HOST=${APP_HOST:-0.0.0.0}

# The address other machines on the network reach this one by, taken from the
# interface that carries the default route rather than from the first one
# listed — docker's bridges are also "local addresses" and none of them are
# reachable from a tablet at the mat.
lan_address() {
    ip route get 1.1.1.1 2>/dev/null | grep -oP 'src \K\S+' || true
}

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

user_count() {
    php artisan tinker --execute='echo App\Models\User::count();' 2>/dev/null \
        | tail -1 | tr -d '[:space:]'
}

USERS_DUMP=""

# Accounts are not demonstration data.
#
# Nothing regenerates them: they are typed in by hand, one per official and one
# per mat, and the passwords behind them exist only as hashes. A reset that
# dropped the users table along with everything else has already cost somebody
# every login they had, so the table is carried across the rebuild instead.
preserve_users() {
    USERS_DUMP=$(mktemp)
    trap 'rm -f "$USERS_DUMP"' EXIT

    if ! docker exec "$DB_CONTAINER" mariadb-dump -ukurash -pdevpass \
        --no-create-info --complete-insert --skip-extended-insert --skip-comments \
        kurash users > "$USERS_DUMP" 2>/dev/null; then
        info "No users table yet — no accounts to carry across"
        : > "$USERS_DUMP"
        return
    fi

    info "Holding on to $(grep -c '^INSERT INTO' "$USERS_DUMP" || true) account(s)"
}

# Put the accounts back into the rebuilt schema.
#
# The inserts run with the foreign key check off and scoreboard_championship_id
# is cleared immediately after: it points at a championship that migrate:fresh
# has just dropped, and NULL is the only value it can honestly hold now.
#
# A failure here is reported rather than fatal — the caller falls through to
# creating an administrator, which leaves a usable database instead of an
# empty one, and the backup taken moments earlier still holds the originals.
restore_users() {
    [ -s "$USERS_DUMP" ] || return 0

    if ! {
        printf 'SET FOREIGN_KEY_CHECKS=0;\n'
        cat "$USERS_DUMP"
        printf 'SET FOREIGN_KEY_CHECKS=1;\n'
        printf 'UPDATE `users` SET `scoreboard_championship_id` = NULL;\n'
    } | docker exec -i "$DB_CONTAINER" mariadb -ukurash -pdevpass kurash 2>/dev/null; then
        printf '\033[31m✗\033[0m %s\n' "Could not restore the accounts — they are still in the backup above." >&2
        return 0
    fi

    ok "$(user_count) account(s) restored"
}

case "${1:-start}" in
    stop)
        info "Stopping the app"
        pkill -f "artisan serve .*--port=${APP_PORT}" 2>/dev/null || true
        pkill -f "artisan serve --port=${APP_PORT}" 2>/dev/null || true
        info "Stopping the database"
        docker stop "$DB_CONTAINER" >/dev/null 2>&1 || true
        ok "Stopped"
        ;;

    reset)
        start_database
        cd "$APP_DIR"

        # The backup comes first and a failed one stops the reset. Everything
        # below this line is destructive, and this file is the only warning
        # anybody gets before it runs.
        info "Backing up the current database"
        php artisan kurash:backup --label=before-reset \
            || die "Backup failed — refusing to reset. Fix the backup first."

        preserve_users

        info "Rebuilding the schema"
        php artisan migrate:fresh --force >/dev/null

        restore_users

        # Some account has to exist before the seeder runs: kurash:demo
        # attributes every result it records to User::first(), and bout_events
        # is append-only, so a demo built against an empty users table can
        # never be given an operator afterwards.
        if [ "$(user_count)" = "0" ]; then
            info "No accounts survived — creating an administrator"
            php artisan kurash:create-admin --name="Administrator" --email="admin@kurash.local"
        fi

        # A reset used to reload the old system's SQLite export. That export
        # held seven placeholder athletes, no dates of birth and no bouts at
        # all, so it demonstrated nothing; the demo seeder builds a championship
        # that is entered, weighed, drawn and part-fought, which is what the
        # screens need before they are worth looking at.
        info "Seeding a demonstration championship"
        php artisan kurash:demo --fresh-all
        ok "Reset complete — accounts kept, competition data rebuilt"
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

        if [ "$(user_count)" = "0" ]; then
            printf '\n'
            info "No accounts exist yet — creating one"
            php artisan kurash:create-admin --name="Administrator" --email="admin@kurash.local"
        fi

        LAN_IP=$(lan_address)
        [ -n "$LAN_IP" ] || LAN_IP=127.0.0.1

        # Absolute URLs the application builds outside a request — a queued
        # job, a console command, a mail — have no Host header to read, so
        # they fall back to this. Exported rather than written into .env
        # because a DHCP lease changes and a file does not; Laravel's dotenv
        # leaves an already-set variable alone, so this wins over the default.
        export APP_URL="http://${LAN_IP}:${APP_PORT}"

        # PHP's built-in server handles one request at a time unless told
        # otherwise, and a competition has several screens polling at once:
        # one scoreboard's two-second poll would stall the mat screen behind
        # it. Forking workers is what keeps them independent.
        export PHP_CLI_SERVER_WORKERS=${PHP_CLI_SERVER_WORKERS:-10}

        printf '\n'
        ok "Starting on http://${LAN_IP}:${APP_PORT}"
        printf '  This machine:  http://127.0.0.1:%s\n' "${APP_PORT}"
        printf '  On the network: http://%s:%s\n' "${LAN_IP}" "${APP_PORT}"

        if [ "$APP_HOST" = "0.0.0.0" ] && [ "$LAN_IP" = "127.0.0.1" ]; then
            printf '  \033[33m!\033[0m No network address found — only this machine can reach it.\n'
        fi

        printf '  Ctrl-C to stop the app. The database keeps running; ./dev.sh stop closes both.\n\n'
        exec php artisan serve --host="${APP_HOST}" --port="${APP_PORT}"
        ;;

    *)
        die "Usage: ./dev.sh [start|stop|reset]"
        ;;
esac
