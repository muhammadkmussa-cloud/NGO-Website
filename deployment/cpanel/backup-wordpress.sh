#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 2 ]]; then
  printf 'Usage: %s EXACT_WORDPRESS_DOCROOT EXACT_BACKUP_DIRECTORY\n' "$0" >&2
  exit 2
fi

docroot="$(realpath "$1")"
backup_parent="$2"

if [[ "$docroot" == "/" || ! -f "$docroot/wp-config.php" ]]; then
  printf 'Refusing backup: the exact WordPress document root with wp-config.php is required.\n' >&2
  exit 1
fi

if ! command -v wp >/dev/null 2>&1; then
  printf 'WP-CLI is required for a credential-safe database export. Use cPanel Backup Wizard if WP-CLI is unavailable.\n' >&2
  exit 1
fi

mkdir -p "$backup_parent"
backup_parent="$(realpath "$backup_parent")"

if [[ "$backup_parent" == "/" || "$backup_parent" == "$docroot" || "$backup_parent" == "$docroot"/* ]]; then
  printf 'Refusing backup: backup directory must be outside the live document root.\n' >&2
  exit 1
fi

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup_dir="$backup_parent/wordpress-$timestamp"
archive="$backup_dir/wordpress-files.tar.gz"
database_dump="$backup_dir/wordpress-database.sql"

mkdir -p "$backup_dir"
tar -C "$docroot" -czf "$archive" .
wp --path="$docroot" db export "$database_dump" --quiet

tar -tzf "$archive" >/dev/null
test -s "$database_dump"
grep -Eq 'CREATE TABLE|INSERT INTO' "$database_dump"
sha256sum "$archive" "$database_dump" > "$backup_dir/SHA256SUMS"

printf 'Backup created and structurally verified: %s\n' "$backup_dir"
printf 'Before cutover, restore the SQL file into a temporary cPanel database and verify its WordPress tables.\n'
