#!/usr/bin/env bash
set -Eeuo pipefail

directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
environment_file="$directory/.env"
if [[ ! -f "$environment_file" ]]; then
    echo 'Copy deployment/oracle/environment.example to deployment/oracle/.env and fill in the hostname and service settings first.' >&2
    exit 1
fi
if ! command -v docker >/dev/null || ! docker compose version >/dev/null 2>&1; then
    echo 'Docker Engine and the Docker Compose plugin are required on the Oracle VM.' >&2
    exit 1
fi

# Keep local secrets private and never print the expanded Compose configuration.
chmod 600 "$environment_file"
compose=(docker compose --env-file "$environment_file" -f "$directory/compose.yml")
"${compose[@]}" config --quiet
"${compose[@]}" up -d --build
"${compose[@]}" ps
