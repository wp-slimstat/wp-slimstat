#!/usr/bin/env bash
# tests/docker/run-email-author-counts.sh <pro-ref|-> [http_port] [db_port]
#
# D50's owed measurement: the email cron's per-author pageview counts, one Pro arm per
# invocation (free = working tree in both arms by construction, and it carries the
# probe). The probe runs the count_records-per-login ORACLE — the before-arm's loop,
# verbatim — and, where the arm has it, authorPageviewMaps(); the equivalence table it
# emits is the before-vs-after answer comparison.
#
# Hand truths over the fixed corpus (window pinned to 1..2000000000):
#   alice  4 — three lowercase rows + one 'Alice' case-drift row the SQL `=` matches;
#              a fifth row sits OUTSIDE the window and must not count
#   bob    1
#   carol  0 — a real user with no pageviews (the skip-decision case)
#   admin  0
#   'ghost' — slim rows with no wp_user: in the grouped map, matched by nobody
#   NULL   — one authorless row: nowhere
# Oracle cost on this corpus: 4 statements (one per user). Maps cost: 1 statement.
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

PRO_REF="${1:--}"
HTTP_PORT="${2:-18970}"
DB_PORT="${3:-13970}"
PHP="${EA_PHP:-8.2}"
WP="${EA_WP:-6.7}"

CELL="ea-$(echo "$PRO_REF" | tr -cd '[:alnum:]' | cut -c1-12)"
CELL_DIR="$WORK_ROOT/ea/$CELL"
WP_DIR="$CELL_DIR/wp"
ART="$CELL_DIR/artifacts"
PROJECT="ssea$(echo "$CELL" | tr -cd '[:alnum:]' | tr '[:upper:]' '[:lower:]')"
BASE_URL="http://127.0.0.1:${HTTP_PORT}"
status="PASS"; reason=""

export COMPOSE_PROJECT_NAME="$PROJECT" PHP_VERSION="$PHP" HTTP_PORT DB_PORT
export MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.0}"
export CELL_WP_DIR="$WP_DIR"
rm -rf "$WP_DIR"
mkdir -p "$WP_DIR" "$ART"

build_pro_arm "$PRO_REF" "$CELL_DIR" "$ART" || exit 1

finish() {
  scan_debug_log "$WP_DIR" "$ART" || true
  write_verdict "$ART" "$CELL" "$PHP" "$WP" "$status" "$reason"
  dc down -v --remove-orphans >/dev/null 2>&1 || true
  cleanup_pro_arm
  log "$CELL → $status ${reason:+($reason)}"
}
trap finish EXIT

echo "CONTROLS:"
echo "  free: WORKING TREE ($(git -C "$PLUGIN_SRC" rev-parse --short HEAD)) — identical in both arms by construction"
echo "  pro arm: $(pro_arm_desc)"

log "[$CELL] build + up"
boot_stack "$ART" "$PHP" || { fail "stack did not come up"; exit 1; }

provision_wp_cell "$ART" "$WP" "$BASE_URL" "$PLUGIN_SRC" || exit 1

for u in alice bob carol; do
  wpc user create "$u" "$u@example.com" --role=subscriber >>"$ART/install.log" 2>&1 \
    || fail "user create $u failed"
done

SEED_SQL="INSERT INTO wp_slim_stats (ip, author, resource, dt, visit_id) VALUES
('10.0.0.1','alice','/p1',1700000100,1),
('10.0.0.1','alice','/p2',1700000200,1),
('10.0.0.1','alice','/p3',1700000300,1),
('10.0.0.2','Alice','/p4',1700000400,2),
('10.0.0.1','alice','/p5',2100000000,1),
('10.0.0.3','bob','/p6',1700002000,3),
('10.0.0.4','ghost','/p7',1700003000,4),
('10.0.0.5',NULL,'/p8',1700004000,5);"

dc exec -T db mysql -uroot -proot wordpress -e "$SEED_SQL" >/dev/null 2>&1 \
  || { fail "seed failed"; exit 1; }
n=$(dc exec -T db mysql -uroot -proot wordpress -N -e \
    "SELECT COUNT(*) FROM wp_slim_stats;" 2>/dev/null | tr -dc '0-9')
echo "  seeded: wp_slim_stats holds ${n:-?} rows (expect 8)"
[ "${n:-0}" = "8" ] || fail "seed row count ${n:-?} != 8"

for run in 1 2; do
  wpc eval-file /var/www/html/wp-content/plugins/wp-slimstat/tests/docker/probe-email-author-counts.php \
    > "$ART/probe-run$run.out" 2>&1 || fail "probe run $run errored"
  awk '/^EA-JSON-BEGIN$/{f=1;next} /^EA-JSON-END$/{f=0} f' "$ART/probe-run$run.out" \
    > "$ART/ea-$run.json"
  [ -s "$ART/ea-$run.json" ] || fail "probe run $run produced no JSON"
done
if cmp -s "$ART/ea-1.json" "$ART/ea-2.json"; then
  echo "  null control: two runs byte-identical ($(wc -c < "$ART/ea-1.json" | tr -d ' ') bytes)"
  cp "$ART/ea-1.json" "$ART/ea.json"
else
  fail "null control FAILED — same arm, two different answers"
  diff "$ART/ea-1.json" "$ART/ea-2.json" | head -20
fi
grep -E '^  (corpus|window)' "$ART/probe-run1.out" | sed 's/^/  /'

echo ""
echo "RESULT ($CELL): artifacts in $ART"
