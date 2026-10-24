#!/usr/bin/env bash
# tests/docker/run-chart-premise.sh [http_port] [db_port]
#
# One disposable cell for probe-chart-totals.php: seed the I8 corpus, run the probe, tear
# down. Run 8 drove the probe by hand inside another cell; the premise it now also carries
# (the WITH ROLLUP super-row, F7's open chart route) deserves a repeatable runner, because
# "the next 'these two queries are the same query' claim is checked rather than argued"
# only holds if checking it is one command.
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

HTTP_PORT="${1:-18970}"
DB_PORT="${2:-13970}"
PHP="${CHART_PHP:-8.2}"
WP="${CHART_WP:-6.7}"
ROWS="${CHART_ROWS:-150000}"
DAYS="${CHART_DAYS:-180}"

CELL="chart-premise"
CELL_DIR="$WORK_ROOT/bench/$CELL"
WP_DIR="$CELL_DIR/wp"
ART="$CELL_DIR/artifacts"
PROJECT="sschartpremise"
BASE_URL="http://127.0.0.1:${HTTP_PORT}"
status="PASS"; reason=""

export COMPOSE_PROJECT_NAME="$PROJECT" PHP_VERSION="$PHP" HTTP_PORT DB_PORT
export MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.0}"
export CELL_WP_DIR="$WP_DIR"
rm -rf "$WP_DIR"
mkdir -p "$WP_DIR" "$ART"

finish() {
  write_verdict "$ART" "$CELL" "$PHP" "$WP" "$status" "$reason"
  dc down -v --remove-orphans >/dev/null 2>&1 || true
  log "$CELL → $status ${reason:+($reason)}"
}
trap finish EXIT

log "[$CELL] build + up"
boot_stack "$ART" "$PHP" || { fail "stack did not come up"; exit 1; }

# Free-only: ARM_PRO_ZIP is unset, so provision_wp_cell skips the Pro half.
provision_wp_cell "$ART" "$WP" "$BASE_URL" "$PLUGIN_SRC" || exit 1

echo "CONTROLS:"
echo "  free: WORKING TREE ($(git -C "$PLUGIN_SRC" rev-parse --short HEAD)+uncommitted?)"
echo "  corpus: $ROWS rows over $DAYS days (I8 overlay)"

log "[$CELL] seeding $ROWS rows over $DAYS days"
dc exec -T -u www-data wp wp --path=/var/www/html eval-file \
   wp-content/plugins/wp-slimstat/tests/bench/lib/seed.php "$ROWS" "$DAYS" seed-profile-i8.json \
   > "$ART/seed.log" 2>&1 || { fail "seed failed (see seed.log)"; exit 1; }

dc exec -T -u www-data wp wp --path=/var/www/html eval-file \
   wp-content/plugins/wp-slimstat/tests/docker/probe-chart-totals.php \
   | tee "$ART/chart-premise.log"
probe_exit=${PIPESTATUS[0]}

# Exit 2 is the probe's DOCUMENTED verdict code (A5 refuted, with the ROLLUP verdict in
# the body); 0 would mean every aggregate went additive, 1 is a real failure.
[ "$probe_exit" = "2" ] || [ "$probe_exit" = "0" ] || fail "probe errored (exit $probe_exit)"
