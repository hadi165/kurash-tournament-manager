#!/usr/bin/env bash
# deploy.sh — release a new version on the VPS.
#
#   ssh kurash@server '/var/www/kurash/deploy.sh'
#   ssh kurash@server '/var/www/kurash/deploy.sh --rollback'
#
# Releases are built in a new directory and swapped in by moving a symlink, so
# the site is never serving a half-installed tree. If anything fails before the
# swap, the running release is untouched.
set -euo pipefail

APP_ROOT=${APP_ROOT:-/var/www/kurash}
REPO=${REPO:-git@github.com:hadi165/kurash-tournament-manager.git}
BRANCH=${BRANCH:-main}
KEEP_RELEASES=${KEEP_RELEASES:-5}

CURRENT="${APP_ROOT}/current"
RELEASES="${APP_ROOT}/releases"
SHARED="${APP_ROOT}/shared"

info() { printf '\033[36m›\033[0m %s\n' "$1"; }
ok()   { printf '\033[32m✓\033[0m %s\n' "$1"; }
die()  { printf '\033[31m✗\033[0m %s\n' "$1" >&2; exit 1; }

restart_services() {
    # The worker holds the old code in memory; it must be told to pick up the
    # new release or it will keep running the previous one indefinitely.
    if systemctl is-enabled --quiet kurash-worker 2>/dev/null; then
        sudo systemctl restart kurash-worker
    fi

    sudo systemctl reload php8.3-fpm 2>/dev/null || true
}

rollback() {
    local previous
    previous=$(ls -1dt "${RELEASES}"/*/ 2>/dev/null | sed -n 2p || true)

    [ -n "$previous" ] || die "No previous release to roll back to."

    info "Rolling back to $(basename "$previous")"
    ln -sfn "${previous%/}" "$CURRENT"

    cd "$CURRENT"
    php artisan migrate --force --pretend >/dev/null 2>&1 || true
    php artisan optimize

    restart_services
    ok "Rolled back to $(basename "$previous")"
    exit 0
}

[ "${1:-}" = "--rollback" ] && rollback

command -v php >/dev/null    || die "php is not on PATH"
command -v composer >/dev/null || die "composer is not on PATH"
command -v npm >/dev/null    || die "npm is not on PATH"
[ -f "${SHARED}/.env" ]      || die "Missing ${SHARED}/.env — see deploy/README.md"

RELEASE="${RELEASES}/$(date +%Y%m%d%H%M%S)"
mkdir -p "$RELEASE" "$RELEASES" "$SHARED"

info "Fetching ${BRANCH}"
git clone --depth 1 --branch "$BRANCH" "$REPO" "$RELEASE/src" >/dev/null 2>&1 \
    || die "Clone failed. Check the deploy key has read access to ${REPO}."

# The Laravel application is a subdirectory of the repository.
mv "$RELEASE/src/kurash-manager"/* "$RELEASE/src/kurash-manager"/.[!.]* "$RELEASE/" 2>/dev/null || true
rm -rf "$RELEASE/src"

cd "$RELEASE"

info "Linking shared state"
# .env and storage/ survive releases: one holds the credentials, the other the
# backups and logs. Everything else is rebuilt from the repository.
ln -sfn "${SHARED}/.env" "${RELEASE}/.env"
rm -rf "${RELEASE}/storage"
ln -sfn "${SHARED}/storage" "${RELEASE}/storage"

info "Installing dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet

info "Building assets"
npm ci --silent
npm run build --silent

# Migrations run before the swap: the new code must never serve traffic against
# a schema it does not expect.
info "Migrating"
php artisan migrate --force

info "Caching configuration"
php artisan optimize

info "Publishing the release"
ln -sfn "$RELEASE" "$CURRENT"

restart_services

info "Pruning old releases"
ls -1dt "${RELEASES}"/*/ | tail -n "+$((KEEP_RELEASES + 1))" | xargs -r rm -rf

ok "Deployed $(basename "$RELEASE")"
printf '  Roll back with: %s --rollback\n' "$0"
