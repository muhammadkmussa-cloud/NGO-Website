#!/usr/bin/env bash
set -euo pipefail

failures=0

pass() { printf 'PASS  %s\n' "$1"; }
fail() { printf 'FAIL  %s\n' "$1" >&2; failures=$((failures + 1)); }

if command -v php >/dev/null 2>&1; then
  if php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);'; then
    pass "PHP $(php -r 'echo PHP_VERSION;') is supported"
  else
    fail "PHP 8.3 or newer is required"
  fi
else
  fail "PHP CLI is unavailable"
fi

required_extensions=(bcmath curl fileinfo mbstring openssl pdo_mysql tokenizer xml zip)
for extension in "${required_extensions[@]}"; do
  if php -r "exit(extension_loaded('${extension}') ? 0 : 1);" 2>/dev/null; then
    pass "PHP extension ${extension}"
  else
    fail "Missing PHP extension ${extension}"
  fi
done

for command_name in composer mysql mysqldump tar; do
  if command -v "$command_name" >/dev/null 2>&1; then
    pass "${command_name} is available"
  else
    fail "${command_name} is unavailable"
  fi
done

if (( failures > 0 )); then
  printf '\nPreflight failed with %d blocking issue(s). Do not remove the live site.\n' "$failures" >&2
  exit 1
fi

printf '\nPreflight passed. Confirm the domain document root in cPanel Domains before continuing.\n'
