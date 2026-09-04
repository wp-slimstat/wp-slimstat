#!/usr/bin/env bash
# tests/docker/run-matrix.sh — run the whole PHP×WP matrix from matrix.env,
# concurrency-limited, and aggregate a PASS/FAIL/BLOCKED grid.
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
source "$HARNESS_DIR/matrix.env"

export STRICT_DEPRECATIONS RUN_E2E
mkdir -p "$WORK_ROOT/cells"

command -v docker >/dev/null || { err "docker not found"; exit 1; }
# Build the Pro artifact if it's not already there — one command to run the matrix.
if [ ! -f "$PRO_ZIP" ]; then
  log "Pro shipped ZIP missing — building it through build/build-dist.sh…"
  bash "$HARNESS_DIR/build-pro.sh" || { err "Pro build failed"; exit 1; }
fi

# Pre-build the PHP images once so cells don't each pay the build cost.
log "pre-building ${#PHPS[@]} PHP images…"
for php in "${PHPS[@]}"; do
  PHP_VERSION="$php" HTTP_PORT=1 DB_PORT=1 CELL_WP_DIR=/tmp/_x \
    docker compose -f "$HARNESS_DIR/docker-compose.yml" -p "ssqa_prebuild_${php//./}" \
    build --build-arg PHP_VERSION="$php" wp >"$WORK_ROOT/prebuild-$php.log" 2>&1 \
    && log "  image php:$php ✓" || warn "  image php:$php build failed (see prebuild-$php.log)"
done

# Launch cells with a concurrency cap.
idx=0; running=0
for wp in "${WPS[@]}"; do
  for php in "${PHPS[@]}"; do
    http=$((BASE_HTTP_PORT + idx)); db=$((BASE_DB_PORT + idx)); idx=$((idx+1))
    cell="php${php}-wp${wp}"; mkdir -p "$WORK_ROOT/cells/$cell/artifacts"
    log "launching $cell (http $http, db $db)"
    bash "$HARNESS_DIR/run-cell.sh" "$php" "$wp" "$http" "$db" \
      > "$WORK_ROOT/cells/$cell/run.log" 2>&1 &
    running=$((running+1))
    if [ "$running" -ge "$CONCURRENCY" ]; then wait -n 2>/dev/null || wait; running=$((running-1)); fi
  done
done
wait

# ── Aggregate ───────────────────────────────────────────────────────────────
SUMMARY="$WORK_ROOT/matrix-summary.md"
{
  echo "# wp-slimstat PHP×WP matrix — $(date -u +%FT%TZ)"
  echo
  printf '| PHP \\\\ WP |'; for wp in "${WPS[@]}"; do printf ' WP %s |' "$wp"; done; echo
  printf '|---|'; for _ in "${WPS[@]}"; do printf '---|'; done; echo
  for php in "${PHPS[@]}"; do
    printf '| **%s** |' "$php"
    for wp in "${WPS[@]}"; do
      j="$WORK_ROOT/cells/php${php}-wp${wp}/artifacts/cell.json"
      st=$([ -f "$j" ] && grep -o '"status":"[^"]*"' "$j" | head -1 | cut -d'"' -f4 || echo MISSING)
      case "$st" in
        PASS) icon="✅ PASS";; FAIL) icon="❌ FAIL";;
        BLOCKED-BY-WP-CORE) icon="🚫 BLOCKED";; *) icon="⚠️ $st";;
      esac
      printf ' %s |' "$icon"
    done; echo
  done
  echo
  echo "_BLOCKED = WordPress core can't boot on that PHP (not a plugin failure)._"
} | tee "$SUMMARY"

cat "$WORK_ROOT"/cells/*/artifacts/cell.json 2>/dev/null | (command -v jq >/dev/null && jq -s '.' || cat) \
  > "$WORK_ROOT/matrix-summary.json" 2>/dev/null || true

# Mirror durable reports out of /tmp.
DEST="$PLUGIN_SRC/../jaan-to/outputs/dev/php-matrix/$(date +%Y%m%d-%H%M%S)"
mkdir -p "$DEST" 2>/dev/null && cp "$SUMMARY" "$WORK_ROOT/matrix-summary.json" "$DEST/" 2>/dev/null \
  && log "summary mirrored to $DEST"

fails=$(grep -l '"status":"FAIL"' "$WORK_ROOT"/cells/*/artifacts/cell.json 2>/dev/null | wc -l | tr -d ' ')
log "done. plugin FAILs: $fails (BLOCKED cells are not failures)."
[ "$fails" -eq 0 ]
