#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 4 || "$4" != "CONFIRM_STAGE" ]]; then
  printf 'Usage: %s RELEASE_ARCHIVE RELEASES_DIRECTORY SHARED_ENV_FILE CONFIRM_STAGE\n' "$0" >&2
  exit 2
fi

archive="$(realpath "$1")"
releases_dir="$2"
shared_env="$(realpath "$3")"

test -f "$archive"
test -f "$shared_env"

grep -qx 'APP_ENV=production' "$shared_env"
grep -qx 'APP_DEBUG=false' "$shared_env"
grep -qx 'ENVIRONMENT=production' "$shared_env"
grep -qx 'ALLOW_DEV_PAYMENT_BYPASSES=false' "$shared_env"
grep -qx 'PAYMENTS_ENABLED=false' "$shared_env"

mkdir -p "$releases_dir"
releases_dir="$(realpath "$releases_dir")"
release_id="$(date -u +%Y%m%dT%H%M%SZ)"
release_dir="$releases_dir/$release_id"
mkdir -p "$release_dir"
tar -xzf "$archive" -C "$release_dir"
shared_storage="$(dirname "$shared_env")/storage/app/public"
ln -s "$shared_env" "$release_dir/.env"
mkdir -p \
  "$shared_storage" \
  "$release_dir/storage/app" \
  "$release_dir/storage/framework/cache/data" \
  "$release_dir/storage/framework/sessions" \
  "$release_dir/storage/framework/views" \
  "$release_dir/storage/logs" \
  "$release_dir/bootstrap/cache"
ln -s "$shared_storage" "$release_dir/storage/app/public"

(
  cd "$release_dir"
  composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
  php artisan migrate --force
  php artisan roi:sync-admin
  php artisan storage:link
  php artisan optimize
)

chmod -R u+rwX,g+rwX "$release_dir/storage" "$release_dir/bootstrap/cache"
touch "$release_dir/.ready-for-cutover"
printf 'Staged release: %s\n' "$release_dir"
printf 'No document root was changed. Run health checks before the cPanel cutover.\n'
