#!/usr/bin/env bash
set -euo pipefail

# Builds an isolated database for the PHP base site from its included public
# catalogue. It deliberately refuses to replace an existing database.
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -n "${MYSQL_BIN:-}" ]]; then
  mysql_bin="$MYSQL_BIN"
elif [[ -x /Applications/XAMPP/xamppfiles/bin/mysql ]]; then
  mysql_bin=/Applications/XAMPP/xamppfiles/bin/mysql
else
  mysql_bin="$(command -v mysql || true)"
fi
if [[ -n "${PHP_BIN:-}" ]]; then
  php_bin="$PHP_BIN"
elif [[ -x /Applications/XAMPP/xamppfiles/bin/php ]]; then
  php_bin=/Applications/XAMPP/xamppfiles/bin/php
else
  php_bin="$(command -v php || true)"
fi
mysql_host="${BASE_DB_HOST:-127.0.0.1}"
mysql_port="${BASE_DB_PORT:-3306}"
mysql_user="${BASE_DB_USER:-root}"
db_name="${BASE_DB_NAME:-silver_screen_base}"

if [[ -z "$mysql_bin" ]] || ! command -v "$mysql_bin" >/dev/null 2>&1; then
  echo "MySQL client not found. Set MYSQL_BIN or add mysql to PATH." >&2
  exit 1
fi
if [[ -z "$php_bin" ]] || ! command -v "$php_bin" >/dev/null 2>&1; then
  echo "PHP CLI not found. Set PHP_BIN or add php to PATH." >&2
  exit 1
fi
if [[ ! "$db_name" =~ ^[A-Za-z][A-Za-z0-9_]{0,63}$ ]]; then
  echo "BASE_DB_NAME must be a valid MySQL database name (1 to 64 letters, digits, or underscores; start with a letter)." >&2
  exit 1
fi

if [[ -n "${BASE_DB_PASSWORD:-}" ]]; then
  export MYSQL_PWD="$BASE_DB_PASSWORD"
fi

mysql_args=(-h "$mysql_host" -P "$mysql_port" -u "$mysql_user")
existing="$("$mysql_bin" "${mysql_args[@]}" -N -e \
  "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$db_name'")"
if [[ "$existing" != "0" ]]; then
  echo "$db_name already exists; refusing to replace its data." >&2
  exit 1
fi

"$mysql_bin" "${mysql_args[@]}" -e \
  "CREATE DATABASE \`$db_name\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
"$mysql_bin" "${mysql_args[@]}" "$db_name" < "$script_dir/catalogue.sql"
BASE_DB_NAME="$db_name" "$php_bin" "$script_dir/expand-programme.php"
BASE_DB_NAME="$db_name" "$php_bin" "$script_dir/refresh-schedule.php"

echo "Installed $db_name with the public catalogue and seat maps."
