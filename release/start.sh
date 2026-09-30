#!/bin/sh
set -eu
cd "$(dirname "$0")"

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
    printf 'TMR_TELEGRAM_WORKER_SUPERVISOR_START\n'
    while :; do
      printf 'TMR_TELEGRAM_WORKER_START\n'
      php worker.php 2>&1 | tee -a storage/worker.log || true
      printf 'TMR_TELEGRAM_WORKER_RESTART\n'
      rm -f storage/worker.pid
      sleep 10
    done
  ) &
else
  printf 'TMR_TELEGRAM_WORKER_DISABLED\n'
fi

(
  while :; do
    php scripts/report-worker.php 2>&1 | tee -a storage/reporting.log || true
    sleep 10
  done
) &

exec frankenphp run --config /app/Caddyfile
