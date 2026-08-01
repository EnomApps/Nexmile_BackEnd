#!/usr/bin/env bash
#
# Deploys the current master to the server.
#
#   cd /var/www/nexmile && ./deploy.sh
#
# Records the commit it started from and rolls back to it if the site fails its
# health check afterwards, so a bad deploy self-heals instead of leaving the
# client looking at a 500.

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/nexmile}"

# The public URL, not 127.0.0.1. Nginx routes by Host header and serves TLS,
# so a loopback request matches no server block and 404s even when the site is
# perfectly healthy. This also proves DNS and the certificate still work.
HEALTH_URL="${HEALTH_URL:-https://api.nexmile.in/up}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.5-fpm}"
BRANCH="${BRANCH:-master}"

cd "$APP_DIR"

log()  { printf '\n\033[1;36m==> %s\033[0m\n' "$1"; }
fail() { printf '\n\033[1;31mFAILED: %s\033[0m\n' "$1" >&2; }

PREVIOUS_COMMIT="$(git rev-parse HEAD)"

build() {
    log "Fetching ${BRANCH}"
    git fetch --quiet origin "$BRANCH"
    git reset --hard --quiet "origin/${BRANCH}"

    log "Installing dependencies"
    composer install --no-dev --optimize-autoloader --no-interaction --quiet

    log "Running migrations"
    php artisan migrate --force --no-interaction

    log "Rebuilding caches"
    # Clear before caching: cached config ignores .env changes entirely.
    php artisan config:clear --quiet
    php artisan route:clear --quiet
    php artisan view:clear --quiet
    php artisan config:cache --quiet
    php artisan route:cache --quiet
    php artisan view:cache --quiet

    log "Fixing permissions"
    sudo chown -R "$USER":www-data storage bootstrap/cache
    sudo chmod -R 775 storage bootstrap/cache

    log "Reloading PHP-FPM"
    sudo systemctl reload "$PHP_FPM_SERVICE"
}

health_check() {
    local attempts="${1:-5}"
    local attempt code
    for attempt in $(seq 1 "$attempts"); do
        code="$(curl -sL -o /dev/null -w '%{http_code}' --max-time 15 "$HEALTH_URL" || echo 000)"
        if [ "$code" = "200" ]; then
            printf '    health check OK (%s)\n' "$HEALTH_URL"
            return 0
        fi
        printf '    health check returned %s, retrying...\n' "$code"
        sleep 3
    done
    return 1
}

rollback() {
    fail "rolling back to ${PREVIOUS_COMMIT:0:8}"
    git reset --hard --quiet "$PREVIOUS_COMMIT"
    composer install --no-dev --optimize-autoloader --no-interaction --quiet
    php artisan config:clear --quiet
    php artisan config:cache --quiet
    php artisan route:cache --quiet
    php artisan view:cache --quiet
    sudo systemctl reload "$PHP_FPM_SERVICE"

    if health_check; then
        fail "deploy reverted; the site is back on the previous commit"
    else
        fail "rollback did not restore health — needs manual attention"
    fi
    exit 1
}

log "Deploying from ${PREVIOUS_COMMIT:0:8}"

# Confirm the health check passes *before* touching anything. Without this a
# misconfigured HEALTH_URL is indistinguishable from a broken release: the
# deploy rolls back, the check fails again, and the script reports a dead site
# that was never down.
log "Pre-flight health check"
if ! health_check 2; then
    fail "the site is already failing its health check at ${HEALTH_URL}"
    printf '  Nothing was deployed. Either the site is genuinely down, or\n'
    printf '  HEALTH_URL is pointing at the wrong place. Check by hand:\n\n'
    printf '      curl -i %s\n\n' "$HEALTH_URL"
    printf '  Then re-run, overriding the URL if needed:\n\n'
    printf '      HEALTH_URL=https://your-domain/up ./deploy.sh\n\n'
    exit 1
fi

if ! build; then
    rollback
fi

log "Health check"
if ! health_check; then
    rollback
fi

# Only restart the worker once the release is known good, so in-flight jobs are
# not killed for a deploy that is about to be reverted.
if systemctl list-units --full --all 2>/dev/null | grep -q 'nexmile-worker.service'; then
    log "Restarting queue worker"
    sudo systemctl restart nexmile-worker
fi

log "Deployed $(git rev-parse --short HEAD)"
