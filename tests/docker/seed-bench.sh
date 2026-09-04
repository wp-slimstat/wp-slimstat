#!/usr/bin/env bash
# tests/docker/seed-bench.sh [rows] [days] [http_port] [db_port]
#
# Builds the I8 reshaped fixture in a disposable container and PROVES it is usable before
# anything measures against it.
#
# WHY. The 443,535-row reference dataset was made by duplicating a smaller dump 20x. That
# compressed the time axis to ~33 days and froze cardinality, so every scan reported
# examined ~= 401,240 at BOTH -30 and -90 days: a report that ignores the date filter entirely
# returns the same number as one that honours it. Row count was never the problem. Shape was.
#
# So this asserts the two properties that make a conclusion possible, and FAILS rather than
# seeding quietly:
#
#   1. distinct resources > 2048 — past A4's MEMORY temp-table cliff, so a GROUP BY resource
#      can actually spill to disk and the whole class of defect becomes reachable.
#   2. rows(30d) < rows(90d) < rows(all), strictly — so a range-filtered report and an
#      unfiltered one cannot return the same number by construction.
#
# A fixture that fails either is not a smaller fixture; it is one that cannot answer the
# questions it would be used for, which is worse than none because it looks like evidence.
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
[ -f "$HARNESS_DIR/matrix.env" ] && source "$HARNESS_DIR/matrix.env"

ROWS="${1:-150000}"
DAYS="${2:-180}"
HTTP_PORT="${3:-18960}"
DB_PORT="${4:-13960}"
PHP="${TOPOLOGY_PHP:-8.2}"
WP="${TOPOLOGY_WP:-6.7}"

CELL="bench-fixture"
CELL_DIR="$WORK_ROOT/bench/$CELL"
WP_DIR="$CELL_DIR/wp"
ART="$CELL_DIR/artifacts"

export COMPOSE_PROJECT_NAME="ssbench" PHP_VERSION="$PHP" HTTP_PORT DB_PORT
export MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.0}"
export CELL_WP_DIR="$WP_DIR"

# KEEP_BENCH=1 leaves the container up so a measurement can run against the seeded database.
keep="${KEEP_BENCH:-0}"
cleanup() { [ "$keep" = "1" ] || dc down -v --remove-orphans >/dev/null 2>&1 || true; }
trap cleanup EXIT

rm -rf "$WP_DIR"
mkdir -p "$WP_DIR" "$ART"

log "[$CELL] build + up (PHP $PHP, WP $WP)"
boot_stack "$ART" "$PHP" || { err "stack did not come up"; exit 1; }

wpc core download --version="$WP" --force > "$ART/install.log" 2>&1 || { err "core download failed"; exit 1; }
wp_config_debug "$ART/install.log"
wpc core install --url="http://127.0.0.1:${HTTP_PORT}" --title="SS bench" --admin_user=admin \
    --admin_password=admin --admin_email=qa@example.com --skip-email >>"$ART/install.log" 2>&1 \
    || { err "core install failed"; exit 1; }

sync_plugin_src "$WP_DIR"
wpc plugin activate wp-slimstat >>"$ART/install.log" 2>&1 || { err "activate failed"; exit 1; }
wpc eval 'include_once(WP_PLUGIN_DIR."/wp-slimstat/admin/index.php"); wp_slimstat_admin::init_tables($GLOBALS["wpdb"]); echo "tables";' \
    >>"$ART/install.log" 2>&1 || { err "init_tables failed"; exit 1; }

# ── EXERCISE_FRESH runs BEFORE seeding, on a virgin install ─────────────────
# Some properties only exist on a fresh site — that it is born with the right columns, and that
# it is NOT offered a migration. Neither can be checked after seeding, and the I8 corpus
# assertions below would reject a one-row table anyway. So this probe gets its own moment.
if [ -n "${EXERCISE_FRESH:-}" ]; then
  cp "$HARNESS_DIR/$EXERCISE_FRESH" "$WP_DIR/wp-content/plugins/wp-slimstat/tests/docker/" 2>/dev/null
  log "[$CELL] exercising $EXERCISE_FRESH on the fresh install"
  dc exec -T -u www-data wp wp --path=/var/www/html eval-file \
     "wp-content/plugins/wp-slimstat/tests/docker/$EXERCISE_FRESH" 2>&1 | tee "$ART/exercise-fresh.log"
  [ "${PIPESTATUS[0]}" -eq 0 ] || { err "the fresh-install probe failed"; exit 1; }
fi

log "[$CELL] seeding $ROWS rows over $DAYS days with the I8 overlay"
dc exec -T -u www-data wp wp --path=/var/www/html eval-file \
   wp-content/plugins/wp-slimstat/tests/bench/lib/seed.php "$ROWS" "$DAYS" seed-profile-i8.json \
   2>&1 | tee "$ART/seed.log" | tail -6

# ── the two properties, asserted ────────────────────────────────────────────
read -r ROWS_ALL ROWS_90 ROWS_30 DISTINCT_RES DISTINCT_REF <<<"$(
  wpc eval '
    global $wpdb; $t = $wpdb->prefix . "slim_stats"; $now = time();
    printf("%d %d %d %d %d",
      (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}"),
      (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE dt >= " . ($now - 90*86400)),
      (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE dt >= " . ($now - 30*86400)),
      (int) $wpdb->get_var("SELECT COUNT(DISTINCT resource) FROM {$t}"),
      (int) $wpdb->get_var("SELECT COUNT(DISTINCT referer) FROM {$t}"));
  ' 2>/dev/null)"

printf '{"rows":%s,"rows_90d":%s,"rows_30d":%s,"distinct_resource":%s,"distinct_referer":%s,"days":%s}\n' \
  "${ROWS_ALL:-0}" "${ROWS_90:-0}" "${ROWS_30:-0}" "${DISTINCT_RES:-0}" "${DISTINCT_REF:-0}" "$DAYS" \
  > "$ART/fixture.json"

echo
echo "  I8 fixture shape"
printf '    rows            %s\n' "${ROWS_ALL:-0}"
printf '    last 90d        %s\n' "${ROWS_90:-0}"
printf '    last 30d        %s\n' "${ROWS_30:-0}"
printf '    distinct resource %s\n' "${DISTINCT_RES:-0}"
printf '    distinct referer  %s\n' "${DISTINCT_REF:-0}"
echo

fail=0
if [ "${DISTINCT_RES:-0}" -le 2048 ]; then
  err "distinct resources = ${DISTINCT_RES:-0}, not > 2048 — A4's MEMORY temp-table cliff stays unreachable"
  fail=1
fi
if [ "${ROWS_30:-0}" -ge "${ROWS_90:-0}" ] || [ "${ROWS_90:-0}" -ge "${ROWS_ALL:-0}" ]; then
  err "rows 30d=${ROWS_30:-0} 90d=${ROWS_90:-0} all=${ROWS_ALL:-0} — the ranges do not separate, so no date-range conclusion on this fixture is falsifiable (the exact defect I8 exists to remove)"
  fail=1
fi

[ "$fail" -eq 0 ] || exit 1

# ── exercise any migration handed to us, against the seeded table ───────────
# EXERCISE=<file> runs a probe inside the container after seeding. A migration that has only
# ever run against a mock is a claim: the ALTER's algorithm is the server's choice, INSERT
# IGNORE exists for what happens on a duplicate, and `<=>` matters only because the columns are
# nullable. None of that behaviour exists in a double.
if [ -n "${EXERCISE:-}" ]; then
  cp "$HARNESS_DIR/$EXERCISE" "$WP_DIR/wp-content/plugins/wp-slimstat/tests/docker/" 2>/dev/null
  log "[$CELL] exercising $EXERCISE"
  dc exec -T -u www-data wp wp --path=/var/www/html eval-file \
     "wp-content/plugins/wp-slimstat/tests/docker/$EXERCISE" 2>&1 | tee "$ART/exercise.log"
  ex_rc=${PIPESTATUS[0]}
  [ "$ex_rc" -eq 0 ] || { err "the exercised probe failed"; exit 1; }
fi

log "[$CELL] fixture is usable: ranges separate, cardinality past the cliff"
[ "$keep" = "1" ] && log "[$CELL] container left up on http://127.0.0.1:${HTTP_PORT} (KEEP_BENCH=1)"
exit 0
