#!/usr/bin/env bash
# reset-local-database.sh — the only sanctioned way to rebuild a local Kurash
# database, and the only one that keeps the accounts.
#
# The Kurash database has been emptied three times. Every time it was a
# `migrate:fresh` believed to be aimed somewhere disposable, and every time the
# users table went with it — referees, officials, the administrator — none of
# which any seeder can recreate, because they are typed in by hand and their
# passwords exist only as hashes.
#
# So this script does not trust itself. It backs up, proves the backup is real,
# exports the accounts separately, proves that export is real, asks a human,
# rebuilds, restores, and then proves the accounts came back. Any check that
# fails stops the run with the backup left on disk.
#
# It never invokes migrate:fresh, migrate:reset or db:wipe. It drops the schema
# itself and rebuilds with plain `migrate`, so App\Support\DatabaseGuard — which
# refuses those commands against any database not ending in _test — needs no
# exception for this script. There is no bypass to find.
set -euo pipefail

PROJECT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)
APP_DIR="${PROJECT_DIR}/kurash-manager"
BACKUP_DIR="${APP_DIR}/storage/app/backups"
DB_CONTAINER=${DB_CONTAINER:-kurash-mariadb}
STAMP=$(date +%Y%m%d-%H%M%S)

info()  { printf '\033[36m›\033[0m %s\n' "$1"; }
ok()    { printf '\033[32m✓\033[0m %s\n' "$1"; }
warn()  { printf '\033[33m!\033[0m %s\n' "$1"; }
die()   { printf '\033[31m✗ %s\033[0m\n' "$1" >&2; exit 1; }

# ── A human, at a terminal ────────────────────────────────────────────────────
#
# Checked before anything else. An agent, a cron job, a CI runner and a piped
# `yes` all fail here, and none of them can set a flag to get past it: there is
# no --force, no --yes and no environment variable that skips this block. The
# third incident was an automated process running a script it believed was
# harmless, so "the caller promised it is fine" is not accepted as evidence.
[ -t 0 ] || die "Refusing: stdin is not a terminal. This script must be run by a person, interactively. There is no non-interactive mode."
[ -t 1 ] || die "Refusing: stdout is not a terminal."
[ -z "${CI:-}" ] || die "Refusing: CI is set. This never runs in automation."
[ -z "${CLAUDE_CODE:-}${CLAUDECODE:-}" ] || die "Refusing: this looks like an agent session. Ask the user to run it themselves."

# ── Where we are pointed ──────────────────────────────────────────────────────
cd "$APP_DIR"
[ -f .env ] || die "No ${APP_DIR}/.env"

# Read through artisan so the name is the one the application resolves, not a
# grep of .env that would miss DB_URL or a config override.
DB_NAME=$(php artisan tinker --execute='echo config("database.connections.".config("database.default").".database");' 2>/dev/null | tail -1 | tr -d '[:space:]')
[ -n "$DB_NAME" ] || die "Could not resolve the database name."

case "$DB_NAME" in
    *_test) die "Refusing: '${DB_NAME}' is a test database. The test suite rebuilds that on its own; this script is for the development database." ;;
esac

printf '\n'
warn "About to DESTROY and rebuild the database '${DB_NAME}'."
printf '  Every championship, athlete, bout and result in it will be gone.\n'
printf '  Accounts will be exported first and restored afterwards.\n\n'

# ── 1. Full backup ────────────────────────────────────────────────────────────
info "Backing up the whole database"
php artisan kurash:backup --label="before-guarded-reset" || die "Backup command failed. Nothing has been touched."

FULL_BACKUP=$(ls -t "${BACKUP_DIR}"/kurash-*before-guarded-reset*.sql.gz 2>/dev/null | head -1)
[ -n "$FULL_BACKUP" ] || die "Backup command reported success but no file appeared in ${BACKUP_DIR}. Nothing has been touched."
[ -s "$FULL_BACKUP" ] || die "Backup file ${FULL_BACKUP} is empty. Nothing has been touched."

BACKUP_BYTES=$(stat -c%s "$FULL_BACKUP")
[ "$BACKUP_BYTES" -ge 1000 ] || die "Backup file is only ${BACKUP_BYTES} bytes — too small to contain a schema. Nothing has been touched."
ok "Full backup: ${FULL_BACKUP} (${BACKUP_BYTES} bytes)"

# ── 2. The accounts, on their own ─────────────────────────────────────────────
#
# Separate from the full dump on purpose. Restoring one table out of a whole-
# database archive under time pressure is how a restore goes wrong; this file
# can be piped straight back in.
USERS_DUMP="${BACKUP_DIR}/users-${STAMP}.sql"
info "Exporting the users table separately"

USER_COUNT_BEFORE=$(php artisan tinker --execute='echo App\Models\User::count();' 2>/dev/null | tail -1 | tr -d '[:space:]')
[ -n "$USER_COUNT_BEFORE" ] || die "Could not count users. Nothing has been touched."

docker exec "$DB_CONTAINER" mariadb-dump \
    --user="$(grep -E '^DB_USERNAME=' .env | cut -d= -f2-)" \
    --password="$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2-)" \
    --no-create-info --complete-insert --skip-extended-insert --skip-comments \
    "$DB_NAME" users > "$USERS_DUMP" 2>/dev/null \
    || die "Could not export the users table. Nothing has been touched. Full backup kept at ${FULL_BACKUP}"

[ -s "$USERS_DUMP" ] || die "Users export is empty. Nothing has been touched. Full backup kept at ${FULL_BACKUP}"

DUMPED_ROWS=$(grep -c '^INSERT INTO' "$USERS_DUMP" || true)
if [ "$DUMPED_ROWS" -ne "$USER_COUNT_BEFORE" ]; then
    die "Users export has ${DUMPED_ROWS} rows but the database has ${USER_COUNT_BEFORE}. Nothing has been touched. Full backup kept at ${FULL_BACKUP}"
fi
ok "Exported ${DUMPED_ROWS} account(s) to ${USERS_DUMP}"

# ── 3. Confirmation ───────────────────────────────────────────────────────────
printf '\n'
printf '  Type the database name (\033[1m%s\033[0m) to confirm, anything else to abort: ' "$DB_NAME"
read -r TYPED
[ "$TYPED" = "$DB_NAME" ] || { warn "Aborted. Nothing was touched. Backups kept."; exit 0; }

# ── 4. Rebuild ────────────────────────────────────────────────────────────────
#
# Drop the schema and migrate it back. Not migrate:fresh: that command is
# refused against this database by DatabaseGuard, and rightly — the guard should
# not carry an exception whose only purpose is to let one script through.
info "Dropping and recreating the schema"
docker exec "$DB_CONTAINER" mariadb -uroot -p"$(grep -E '^DB_ROOT_PASSWORD=' .env | cut -d= -f2- || echo devroot)" \
    -e "DROP DATABASE \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\`; GRANT ALL ON \`${DB_NAME}\`.* TO '$(grep -E '^DB_USERNAME=' .env | cut -d= -f2-)'@'%'; FLUSH PRIVILEGES;" 2>/dev/null \
    || die "Could not rebuild the schema. THE BACKUP IS AT ${FULL_BACKUP} AND THE ACCOUNTS AT ${USERS_DUMP}. Restore before doing anything else."

info "Running migrations"
php artisan migrate --force >/dev/null || die "Migrations failed after the drop. THE BACKUP IS AT ${FULL_BACKUP} AND THE ACCOUNTS AT ${USERS_DUMP}."

# ── 5. Restore the accounts ───────────────────────────────────────────────────
info "Restoring the accounts"
{
    printf 'SET FOREIGN_KEY_CHECKS=0;\n'
    cat "$USERS_DUMP"
    printf 'SET FOREIGN_KEY_CHECKS=1;\n'
    printf 'UPDATE `users` SET `scoreboard_championship_id` = NULL;\n'
} | docker exec -i "$DB_CONTAINER" mariadb \
      -u"$(grep -E '^DB_USERNAME=' .env | cut -d= -f2-)" \
      -p"$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2-)" "$DB_NAME" 2>/dev/null \
  || die "RESTORE FAILED. The database has been rebuilt but the accounts are NOT back. The export is at ${USERS_DUMP} and the full backup at ${FULL_BACKUP}. Neither has been deleted. Restore by hand before using this database."

# ── 6. Prove they came back ───────────────────────────────────────────────────
USER_COUNT_AFTER=$(php artisan tinker --execute='echo App\Models\User::count();' 2>/dev/null | tail -1 | tr -d '[:space:]')

if [ "$USER_COUNT_AFTER" != "$USER_COUNT_BEFORE" ]; then
    die "ACCOUNT COUNT MISMATCH: ${USER_COUNT_BEFORE} before, ${USER_COUNT_AFTER} after. The export is at ${USERS_DUMP} and the full backup at ${FULL_BACKUP}. Neither has been deleted. Do not use this database until it is sorted out."
fi

ok "${USER_COUNT_AFTER} account(s) restored, matching the ${USER_COUNT_BEFORE} exported"
ok "Reset complete."
printf '\n  Backups kept (nothing here is ever deleted automatically):\n'
printf '    %s\n    %s\n\n' "$FULL_BACKUP" "$USERS_DUMP"
printf '  The database now has your accounts and no competition data.\n'
printf '  To fill it with demonstration data:  php artisan kurash:demo\n\n'
