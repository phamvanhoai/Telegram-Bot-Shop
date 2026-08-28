#!/usr/bin/env bash
set -Eeuo pipefail
exec 9>/tmp/telegram-shop-deploy.lock
flock -n 9 || { echo "DEPLOY_SKIPPED: another deployment is running"; exit 0; }
SITE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="${PHP_BIN:-/www/server/php/84/bin/php}"
COMPOSER="${COMPOSER_BIN:-/usr/local/bin/composer}"
NODE_BIN="${NODE_BIN:-/usr/local/node-v22/bin}"
PREVIOUS_COMMIT="$(git -C "$SITE" rev-parse HEAD)"
rollback() { echo "DEPLOY_FAILED: restoring ${PREVIOUS_COMMIT:0:8}"; git -C "$SITE" reset --hard "$PREVIOUS_COMMIT"; "$PHP" "$COMPOSER" install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress; "$PHP" "$SITE/artisan" optimize:clear || true; }
trap rollback ERR
cd "$SITE"
export PATH="$NODE_BIN:$PATH" COMPOSER_ALLOW_SUPERUSER=1
git fetch --prune origin main
git reset --hard origin/main
git clean -fd -e .well-known/
"$PHP" "$COMPOSER" install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress
npm ci --no-audit --no-fund
npm run build
"$PHP" artisan migrate --force
"$PHP" artisan optimize:clear
"$PHP" artisan optimize
"$PHP" artisan queue:restart
if [[ -n "${HEALTHCHECK_URL:-}" ]]; then curl --fail --silent --show-error --retry 5 "$HEALTHCHECK_URL" >/dev/null; fi
trap - ERR
echo "DEPLOY_SUCCESS: $(git rev-parse --short HEAD)"
