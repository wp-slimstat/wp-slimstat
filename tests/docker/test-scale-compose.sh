#!/usr/bin/env bash
# Read-only Docker CLI config check; no containers or volumes are created.
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
COMPOSE_PROJECT_NAME=ssscalesyntax PHP_VERSION=8.2 HTTP_PORT=0 DB_PORT=0 CELL_WP_DIR=/tmp/scale-syntax-only \
  docker compose -f "$here/docker-compose.yml" -f "$here/docker-compose.scale.yml" config --format json |
  python3 -c 'import json,sys; c=json.load(sys.stdin); d=c["services"]["db"]; assert not d.get("tmpfs"); assert "--skip-log-bin" in d["command"]; assert any(v["type"]=="volume" and v["target"]=="/var/lib/mysql" for v in d["volumes"]); print("PASS: scale rebuilds use disposable disk storage without binary logs")'
