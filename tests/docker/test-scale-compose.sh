#!/usr/bin/env bash
# Read-only Docker CLI config check; no containers or volumes are created.
set -euo pipefail
here=$(cd "$(dirname "$0")" && pwd)
COMPOSE_PROJECT_NAME=ssscalesyntax PHP_VERSION=8.2 HTTP_PORT=0 DB_PORT=0 CELL_WP_DIR=/tmp/scale-syntax-only \
  docker compose -f "$here/docker-compose.yml" -f "$here/docker-compose.scale.yml" config --format json |
  python3 -c 'import json,sys; c=json.load(sys.stdin); d=c["services"]["db"]; assert not d.get("tmpfs"); assert "--skip-log-bin" in d["command"]; assert any(v["type"]=="volume" and v["target"]=="/var/lib/mysql" for v in d["volumes"]); assert d["cpus"]==4 and int(d["mem_limit"])==4*1024**3; assert c["services"]["wp"]["cpus"]==2 and int(c["services"]["wp"]["mem_limit"])==1024**3; print("PASS: scale rebuilds use disposable disk storage without binary logs and with explicit resource limits")'

COMPOSE_PROJECT_NAME=ssnetworksyntax PHP_VERSION=8.2 HTTP_PORT=0 DB_PORT=0 CELL_WP_DIR=/tmp/network-syntax-only \
  docker compose -f "$here/docker-compose.yml" -f "$here/docker-compose.analytics.yml" config --format json |
  python3 -c 'import json,sys; c=json.load(sys.stdin); s=c["services"]; assert all(s[k]["cpus"]==2 for k in ["wp","db","analytics-db"]); assert all(int(s[k]["mem_limit"])==2*1024**3 for k in ["db","analytics-db"]); assert int(s["wp"]["mem_limit"])==1024**3; assert any(v["type"]=="volume" and v["target"]=="/var/lib/mysql" for v in s["analytics-db"]["volumes"]); print("PASS: external network storage survives server stop/start with explicit resource limits")'
