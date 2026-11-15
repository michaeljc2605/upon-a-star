#!/usr/bin/env bash
set -Eeuo pipefail

export PORT="${PORT:-10000}"
if [[ ! "$PORT" =~ ^[0-9]{1,5}$ ]] || (( 10#$PORT < 1 || 10#$PORT > 65535 || 10#$PORT == 4000 )); then
    echo 'PORT must be a valid public HTTP port other than the private API port 4000.' >&2
    exit 1
fi

export PUBLIC_URL="${PUBLIC_URL:-${RENDER_EXTERNAL_URL:-}}"
PUBLIC_URL="$(node <<'NODE'
const raw = process.env.PUBLIC_URL;
try {
  if (!raw) throw new Error();
  const url = new URL(raw);
  const allowedProtocol = url.protocol === 'https:' || (url.protocol === 'http:' && process.env.ALLOW_HTTP === 'true');
  if (!allowedProtocol || url.username || url.password || url.search || url.hash || url.pathname !== '/') throw new Error();
  process.stdout.write(url.origin);
} catch {
  console.error('Set PUBLIC_URL (or RENDER_EXTERNAL_URL) to the public HTTPS origin. HTTP is allowed only with ALLOW_HTTP=true for local checks.');
  process.exit(1);
}
NODE
)"
export PUBLIC_URL

if [[ -z "${MYSQL_HOST:-}" || -z "${MYSQL_USER:-}" ]]; then
    echo 'MYSQL_HOST and MYSQL_USER must be supplied by the database service.' >&2
    exit 1
fi

export MYSQL_PORT="${MYSQL_PORT:-3306}"
export MYSQL_DATABASE="${MYSQL_DATABASE:-silver_screen}"
export MYSQL_PASSWORD="${MYSQL_PASSWORD:-}"
export MYSQL_SSL="${MYSQL_SSL:-true}"
export MYSQL_SSL_CA="${MYSQL_SSL_CA:-/etc/ssl/certs/ca-certificates.crt}"
export TZ=Asia/Singapore
export NODE_ENV=production

# PHP and Node address the same persistent database. No private .env is baked in.
export BASE_DB_HOST="$MYSQL_HOST"
export BASE_DB_PORT="$MYSQL_PORT"
export BASE_DB_USER="$MYSQL_USER"
export BASE_DB_PASSWORD="$MYSQL_PASSWORD"
export BASE_DB_NAME="$MYSQL_DATABASE"
export PHP_DB_NAME="$MYSQL_DATABASE"
export PHP_SITE_PATH=''
export PHP_AUTH_API_ORIGIN='http://127.0.0.1:4000'
export PHP_AUTH_BROWSER_ORIGIN="$PUBLIC_URL"
export PHP_CLIENT_ORIGIN="$PUBLIC_URL"
export CLIENT_ORIGIN="$PUBLIC_URL"
export GOOGLE_REDIRECT_URI="${GOOGLE_REDIRECT_URI:-$PUBLIC_URL/api/auth/google/callback}"

children=()
cleanup() {
    trap - EXIT TERM INT
    for pid in "${children[@]}"; do kill -TERM "$pid" 2>/dev/null || true; done
    for attempt in {1..50}; do
        running=false
        for pid in "${children[@]}"; do
            if kill -0 "$pid" 2>/dev/null; then running=true; fi
        done
        if [[ "$running" == false ]]; then break; fi
        sleep 0.1
    done
    for pid in "${children[@]}"; do
        if kill -0 "$pid" 2>/dev/null; then kill -KILL "$pid" 2>/dev/null || true; fi
    done
    for pid in "${children[@]}"; do wait "$pid" 2>/dev/null || true; done
}
trap cleanup EXIT
trap 'exit 143' TERM
trap 'exit 130' INT

if [[ "${INITIALIZE_EMPTY_DATABASE:-false}" == true ]]; then
    node --max-old-space-size=128 /opt/upon-a-star/deployment/init-database.mjs
fi

# Validate required extensions and configuration before opening the public port.
php -r 'foreach (["pdo_mysql", "curl", "mbstring"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "Required PHP extension is unavailable.\n"); exit(1); } }'
apache2ctl -t

PORT=4000 node --max-old-space-size=128 /opt/upon-a-star/server/src/server.js &
node_pid=$!
children+=("$node_pid")
ready=false
for attempt in {1..60}; do
    if ! kill -0 "$node_pid" 2>/dev/null; then
        echo 'The private API stopped before startup completed.' >&2
        exit 1
    fi
    if curl --fail --silent --max-time 1 http://127.0.0.1:4000/api/health > /dev/null; then
        ready=true
        break
    fi
    sleep 0.25
done
if [[ "$ready" != true ]]; then
    echo 'The private API did not become ready.' >&2
    exit 1
fi

apache2-foreground &
apache_pid=$!
children+=("$apache_pid")
echo 'Upon a Star is running with its private API and public PHP site.'

# Either service exiting makes the whole container unhealthy; stop its sibling.
set +e
wait -n "$node_pid" "$apache_pid"
status=$?
set -e
echo 'An application service stopped; shutting down the container.' >&2
if [[ "$status" == 0 ]]; then status=1; fi
exit "$status"
