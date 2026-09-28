#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
project_root="$(cd "$script_dir/../.." && pwd)"
artifact_dir="${1:-$project_root/artifacts}"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
archive="$artifact_dir/roi-cpanel-$timestamp.tar.gz"

mkdir -p "$artifact_dir"

(
  cd "$project_root/frontend"
  npm ci
  npm test
  npm run build
)

(
  cd "$project_root/laravel-backend"
  composer validate --strict
  php artisan test
)

tar -C "$project_root/laravel-backend" -czf "$archive" \
  artisan \
  composer.json \
  composer.lock \
  app \
  bootstrap/app.php \
  bootstrap/providers.php \
  config \
  database/migrations \
  public \
  resources \
  routes

if tar -tzf "$archive" | grep -Eq '(^|/)(\.env($|\.)|auth\.json$|.*\.log$|\.phpunit\.result\.cache$|database\.sqlite$)'; then
  printf 'Refusing release: a forbidden secret, log, cache, or local database artifact was packaged.\n' >&2
  exit 1
fi

sha256sum "$archive" > "$archive.sha256"
printf 'Release artifact: %s\nChecksum: %s.sha256\n' "$archive" "$archive"
