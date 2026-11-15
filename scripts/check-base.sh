#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
php_bin="${PHP_BIN:-/Applications/XAMPP/xamppfiles/bin/php}"

if [[ ! -x "$php_bin" ]]; then
  echo "XAMPP PHP CLI not found: $php_bin" >&2
  exit 1
fi

exec "$php_bin" "$project_root/scripts/test-base.php"
