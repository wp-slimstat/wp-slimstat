#!/usr/bin/env bash
# tests/docker/run-goals-author-scope.sh <free-ref|-> [http_port] [db_port]
#
# D58's owed measurement: get_goals_raw()/get_funnels_raw() called the way the
# per-author email cron calls them — site-wide, then per author, then one author again
# in the same process. One FREE arm per invocation ('-' = working tree, a ref = a
# detached worktree); the probe is always copied from the CURRENT tree so both arms
# run the identical instrument (the compare-answers.sh convention).
#
# The corpus is FIXED and the answers are hand-computed (visitor identity =
# fingerprint, distinct per visitor by construction):
#
#   goal "Buy page" (resource contains /buy):
#     site   total 5 · uniques 4 (f1,f2,f3,f5) · visitors 5 · cr 80%
#     alice  total 3 · uniques 2 (f1,f2)       · visitors 2 · cr 100%
#     bob    total 1 · uniques 1 (f3)          · visitors 2 · cr 50%
#   funnel /f1 -> /f2:
#     site   step1 3 (f1,f2,f3) · step2 2 (f1,f3)
#     alice  step1 2 (f1,f2)    · step2 1 (f1)
#     bob    step1 1 (f3)       · step2 1 (f3)
#   the NULL-author row (f5, /buy) is in the SITE numbers and in NO author's — it is
#   what separates "scoped" from "site-wide minus somebody".
#
# Every scope's triple differs from every other's, so a scope-blind arm cannot emit
# these numbers by accident. On the BEFORE arm the expected reading is alice == bob ==
# site (the defect, plus its cache layer); on the AFTER arm the table above.
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

FREE_REF="${1:--}"
HTTP_PORT="${2:-18960}"
DB_PORT="${3:-13960}"
PHP="${GA_PHP:-8.2}"
WP="${GA_WP:-6.7}"

CELL="ga-$(echo "$FREE_REF" | tr -cd '[:alnum:]' | cut -c1-12)"
CELL_DIR="$WORK_ROOT/ga/$CELL"
WP_DIR="$CELL_DIR/wp"
ART="$CELL_DIR/artifacts"
PROJECT="ssga$(echo "$CELL" | tr -cd '[:alnum:]' | tr '[:upper:]' '[:lower:]')"
BASE_URL="http://127.0.0.1:${HTTP_PORT}"
status="PASS"; reason=""

export COMPOSE_PROJECT_NAME="$PROJECT" PHP_VERSION="$PHP" HTTP_PORT DB_PORT
export MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.0}"
export CELL_WP_DIR="$WP_DIR"
rm -rf "$WP_DIR"
mkdir -p "$WP_DIR" "$ART"

build_free_arm "$FREE_REF" "$CELL_DIR" || exit 1

finish() {
  scan_debug_log "$WP_DIR" "$ART" || true
  write_verdict "$ART" "$CELL" "$PHP" "$WP" "$status" "$reason"
  dc down -v --remove-orphans >/dev/null 2>&1 || true
  cleanup_free_arm
  log "$CELL → $status ${reason:+($reason)}"
}
trap finish EXIT

echo "CONTROLS:"
free_arm_desc
log "[$CELL] build + up"
boot_stack "$ART" "$PHP" || { fail "stack did not come up"; exit 1; }

provision_wp_cell "$ART" "$WP" "$BASE_URL" "$FREE_SRC" || exit 1

# The instrument comes from the CURRENT tree in both arms — the arm's worktree may
# predate the probe, and two arms measured by two probes measure nothing.
cp "$HARNESS_DIR/probe-goals-author-scope.php" \
   "$WP_DIR/wp-content/plugins/wp-slimstat/tests/docker/probe-goals-author-scope.php"

# ── fixed corpus (see hand truths above) ────────────────────────────────────────────
SEED_SQL="INSERT INTO wp_slim_stats (ip, author, fingerprint, resource, dt, visit_id) VALUES
('10.0.0.1','alice','f1','/buy',1700000100,1),
('10.0.0.1','alice','f1','/buy',1700000200,1),
('10.0.0.1','alice','f1','/f1',1700000300,1),
('10.0.0.1','alice','f1','/f2',1700000400,1),
('10.0.0.2','alice','f2','/buy',1700001000,2),
('10.0.0.2','alice','f2','/f1',1700001100,2),
('10.0.0.3','bob','f3','/buy',1700002000,3),
('10.0.0.3','bob','f3','/f1',1700002100,3),
('10.0.0.3','bob','f3','/f2',1700002200,3),
('10.0.0.4','bob','f4','/other',1700003000,4),
('10.0.0.5',NULL,'f5','/buy',1700004000,5);"

dc exec -T db mysql -uroot -proot wordpress -e "$SEED_SQL" >/dev/null 2>&1 \
  || { fail "seed failed"; exit 1; }
n=$(dc exec -T db mysql -uroot -proot wordpress -N -e \
    "SELECT COUNT(*) FROM wp_slim_stats;" 2>/dev/null | tr -dc '0-9')
echo "  seeded: wp_slim_stats holds ${n:-?} rows (expect 11)"
[ "${n:-0}" = "11" ] || fail "seed row count ${n:-?} != 11"

# ── probe twice; a deterministic instrument must answer identically ─────────────────
for run in 1 2; do
  wpc eval-file /var/www/html/wp-content/plugins/wp-slimstat/tests/docker/probe-goals-author-scope.php \
    > "$ART/probe-run$run.out" 2>&1 || fail "probe run $run errored"
  awk '/^GA-JSON-BEGIN$/{f=1;next} /^GA-JSON-END$/{f=0} f' "$ART/probe-run$run.out" \
    > "$ART/ga-$run.json"
  [ -s "$ART/ga-$run.json" ] || fail "probe run $run produced no JSON"
done
if cmp -s "$ART/ga-1.json" "$ART/ga-2.json"; then
  echo "  null control: two runs byte-identical ($(wc -c < "$ART/ga-1.json" | tr -d ' ') bytes)"
  cp "$ART/ga-1.json" "$ART/ga.json"
else
  fail "null control FAILED — same arm, two different answers"
  diff "$ART/ga-1.json" "$ART/ga-2.json" | head -20
fi
grep -E '^  (corpus|goals option|window)' "$ART/probe-run1.out" | sed 's/^/  /'

echo ""
echo "RESULT ($CELL): artifacts in $ART"
