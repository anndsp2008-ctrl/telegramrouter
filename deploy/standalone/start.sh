#!/bin/sh
set -eu
cd /app

for name in APP_KEY DB_HOST DB_NAME DB_USER DB_PASS; do
  if [ -z "$(printenv "$name" 2>/dev/null || true)" ]; then
    printf 'Missing required environment variable: %s\n' "$name" >&2
    exit 1
  fi
done

mkdir -p storage/sessions storage/auth-jobs storage/cache
chmod 700 storage storage/sessions storage/auth-jobs storage/cache
php -l index.php >/dev/null
php -l app/TelegramRouter.php >/dev/null
printf 'ok\n' > health

if [ "${TMR_RUN_WORKER:-1}" = "1" ]; then
  (
    while :; do
      php worker.php >> storage/worker.log 2>&1 || true
      rm -f storage/worker.pid
      sleep 10
    done
  ) &
fi

exec frankenphp run --config /app/Caddyfile
