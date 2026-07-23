#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/bist_project}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
COMPOSER_BIN="${COMPOSER_BIN:-/usr/local/bin/composer}"

cd "$APP_DIR"
export APP_ENV=prod
export APP_DEBUG=0

echo "[1/8] Composer production dependencies"
"$COMPOSER_BIN" install --no-dev --prefer-dist --no-progress --no-interaction --optimize-autoloader --classmap-authoritative

echo "[2/8] Environment preflight"
"$PHP_BIN" bin/console app:production:check --env=prod --no-debug --strict --no-interaction

echo "[3/8] Database migrations"
"$PHP_BIN" bin/console doctrine:migrations:migrate --env=prod --no-debug --no-interaction

echo "[4/8] Messenger transport infrastructure"
"$PHP_BIN" bin/console messenger:setup-transports --env=prod --no-debug --no-interaction

echo "[5/8] Cache rebuild"
"$PHP_BIN" bin/console cache:clear --env=prod --no-debug --no-warmup
"$PHP_BIN" bin/console cache:warmup --env=prod --no-debug

echo "[6/8] Asset compilation"
"$PHP_BIN" bin/console asset-map:compile --env=prod --no-debug

echo "[7/8] Schema and production checks"
"$PHP_BIN" bin/console doctrine:migrations:up-to-date --env=prod --no-debug --no-interaction
"$PHP_BIN" bin/console doctrine:schema:validate --env=prod --no-debug
"$PHP_BIN" bin/console app:production:check --env=prod --no-debug --strict --no-interaction

echo "[8/8] Gracefully stop old workers"
"$PHP_BIN" bin/console messenger:stop-workers --env=prod --no-debug || true

echo "Deployment checks completed. Restart bist-worker and reload PHP-FPM/Nginx."
