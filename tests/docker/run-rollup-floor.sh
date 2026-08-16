#!/usr/bin/env bash
# tests/docker/run-rollup-floor.sh — C49 / the ROLLUP floor cells.
#
# Chart.php:445 claims the shipped `GROUP BY … WITH ROLLUP` chart shape is safe below
# MySQL 8.0.12 because it omits ORDER BY. A comment that says "X keeps us safe from Y"
# is an experiment not yet run (PITFALLS 52) — this script is the experiment, and it
# widens to the whole answer set while it is there:
#
#   one corpus → three servers (mysql:8.0 reference, 5.7, 5.6) → the same absolute
#   window → the REAL read path (report-answers.php, every report) → classified diff.
#
# The seeder is random_int-based and NOT seedable, so re-seeding per cell would compare
# three different corpora. Instead the corpus is seeded ONCE (8.0 cell), dumped as
# data-only INSERTs, imported into the 5.x cells, and corpus identity is PROVEN with a
# COUNT(*) + SUM(CRC32(…)) fingerprint per cell before any answer is compared.
#
# CONTROLS, printed before any result:
#   - SELECT VERSION() per cell — the arms must DIFFER (PITFALLS 27: identical outputs
#     are only evidence once different inputs are a control);
#   - corpus fingerprint per cell — the data must NOT differ;
#   - per-cell null control — report-answers run twice, byte-compared;
#   - the debug log scanned per cell — a fatal is a FAIL, not a footnote.
#
# Verdict gates: every chart_* report byte-identical across versions; no report errored
# or empty on one version only; fingerprints identical; versions distinct. Order-only
# differences on list reports are RECORDED, not failed — 5.6 implicitly sorts GROUP BY
# and the register's hygiene section already names that caveat (C49). A VALUE diff on
# any report fails the run.
#
# Usage:  ./run-rollup-floor.sh [rows] [days]      (defaults 30000, 120)
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

ROWS="${1:-30000}"; DAYS="${2:-120}"
PHP_VER=8.2; WP_VER=6.8
export HTTP_PORT=18910 DB_PORT=13910 PHP_VERSION="$PHP_VER"
export COMPOSE_PROJECT_NAME=ssqa_rollup

RUN_ROOT="$WORK_ROOT/rollup-floor"; rm -rf "$RUN_ROOT"; mkdir -p "$RUN_ROOT"

# An interrupt between boot_stack and the explicit down leaves the stack holding the
# ports and wedging the next run — same trap compare-answers.sh carries.
trap 'dc down -v >/dev/null 2>&1 || true' EXIT
CORPUS="$RUN_ROOT/corpus.sql"
WINFILE="$RUN_ROOT/window.env"

# label → image → extra compose file ('' = none). 8.0 FIRST: it seeds and dumps.
CELLS=(
  "80|mysql:8.0|"
  "57|mysql:5.7|docker-compose.mysql57.yml"
  "56|mysql:5.6|docker-compose.mysql56.yml"
)

fingerprint_sql() {
  cat <<'SQL'
SELECT CONCAT(
  'stats=', (SELECT COUNT(*) FROM wp_slim_stats), ':',
  (SELECT COALESCE(SUM(CRC32(CONCAT_WS('|',id,dt,resource,browser,browser_version,platform,country,visit_id,ip))),0) FROM wp_slim_stats),
  ' events=', (SELECT COUNT(*) FROM wp_slim_events), ':',
  (SELECT COALESCE(SUM(CRC32(CONCAT_WS('|',id,dt,type,notes))),0) FROM wp_slim_events)
);
SQL
}

run_cell() {
  local label="$1" image="$2" extra="$3"
  local art="$RUN_ROOT/$label"; mkdir -p "$art"
  export MYSQL_IMAGE="$image"
  export DC_EXTRA_FILE=""
  [ -n "$extra" ] && export DC_EXTRA_FILE="$HARNESS_DIR/$extra"
  export CELL_WP_DIR="$RUN_ROOT/wp-$label"; mkdir -p "$CELL_WP_DIR"

  log "[$label] booting $image (php $PHP_VER, wp $WP_VER)"
  boot_stack "$art" "$PHP_VER" || { err "[$label] stack boot failed"; return 1; }
  dc exec -T db mysql -uroot -proot -N -e 'SELECT VERSION();' > "$art/version.txt" 2>/dev/null
  [ -s "$art/version.txt" ] || { err "[$label] could not read server version — the arms-differ control has no input"; return 1; }
  log "[$label] server reports $(cat "$art/version.txt")"

  provision_wp_cell "$art" "$WP_VER" "http://127.0.0.1:${HTTP_PORT}" "$PLUGIN_SRC" || return 1
  wpc eval 'include_once(WP_PLUGIN_DIR."/wp-slimstat/admin/index.php"); wp_slimstat_admin::init_tables($GLOBALS["wpdb"]); echo "t";' \
      >>"$art/install.log" 2>&1

  if [ "$label" = "80" ]; then
    log "[$label] seeding $ROWS rows over $DAYS days (I8 profile) — the ONE corpus"
    wpc eval-file wp-content/plugins/wp-slimstat/tests/bench/lib/seed.php \
       "$ROWS" "$DAYS" seed-profile-i8.json \
       > "$art/seed.log" 2>&1 || { err "[$label] seeding failed"; return 1; }
    # Data-only dump; --complete-insert names columns so a column-order difference
    # between per-version fresh schemas cannot silently shift values.
    dc exec -T db mysqldump -uroot -proot --no-create-info --skip-triggers --complete-insert \
       wordpress wp_slim_stats wp_slim_events > "$CORPUS" 2>"$art/dump.err" \
       || { err "[$label] corpus dump failed"; return 1; }
    # Absolute window, computed once, shared by every cell.
    local max_dt
    max_dt="$(dc exec -T db mysql -uroot -proot -N -e 'SELECT MAX(dt) FROM wordpress.wp_slim_stats;')"
    echo "WIN_START=$((max_dt - 28*86400))" >  "$WINFILE"
    echo "WIN_END=$max_dt"                  >> "$WINFILE"
  else
    log "[$label] importing the 8.0-seeded corpus"
    # TRUNCATE on an FK-referenced parent is refused outright (error 1701) regardless of
    # order or child emptiness — wp_slim_events references wp_slim_stats(id). The toggle
    # is safe here: both tables are empty on a fresh install and the fingerprint below
    # would expose any residue the clear missed.
    dc exec -T db mysql -uroot -proot -e \
       'SET FOREIGN_KEY_CHECKS=0; TRUNCATE wordpress.wp_slim_events; TRUNCATE wordpress.wp_slim_stats; SET FOREIGN_KEY_CHECKS=1;' \
       || { err "[$label] truncate failed"; return 1; }
    dc exec -T db mysql -uroot -proot wordpress < "$CORPUS" 2>"$art/import.err" \
       || { err "[$label] corpus import failed"; return 1; }
  fi

  fingerprint_sql | dc exec -T db mysql -uroot -proot -N wordpress > "$art/fingerprint.txt" \
      || { err "[$label] fingerprint failed"; return 1; }
  log "[$label] corpus fingerprint: $(cat "$art/fingerprint.txt")"

  # shellcheck disable=SC1090
  source "$WINFILE"
  local pass
  for pass in 1 2; do
    dc exec -T -u www-data \
       -e SLIMSTAT_ANSWERS_START="$WIN_START" -e SLIMSTAT_ANSWERS_END="$WIN_END" \
       -e SLIMSTAT_TIMING_REPS=1 wp \
       wp --path=/var/www/html eval-file \
       wp-content/plugins/wp-slimstat/tests/docker/report-answers.php > "$art/answers-$pass.raw" 2>&1
    grep -h 'SLIMSTAT-ANSWERS' "$art/answers-$pass.raw" | sed 's/^SLIMSTAT-ANSWERS //' > "$art/answers-$pass.json"
    [ -s "$art/answers-$pass.json" ] || { err "[$label] answers pass $pass produced no JSON (see answers-$pass.raw)"; return 1; }
  done
  if cmp -s "$art/answers-1.json" "$art/answers-2.json"; then
    cp "$art/answers-1.json" "$art/answers.json"
    log "[$label] null control PASS (two passes byte-identical)"
  else
    err "[$label] NULL CONTROL FAILED — two passes over one corpus differ:"
    diff "$art/answers-1.json" "$art/answers-2.json" | head -10
    return 1
  fi

  scan_debug_log "$CELL_WP_DIR" "$art" && { err "[$label] debug.log holds a wp-slimstat fatal"; return 1; }
  dc down -v > "$art/down.log" 2>&1
  return 0
}

overall=0
for cell in "${CELLS[@]}"; do
  IFS='|' read -r label image extra <<< "$cell"
  if ! run_cell "$label" "$image" "$extra"; then
    overall=1
    dc down -v >/dev/null 2>&1 || true
    err "cell $label ($image) FAILED — aborting remaining cells; artifacts in $RUN_ROOT/$label"
    break
  fi
done

if [ "$overall" -ne 0 ]; then
  echo "VERDICT: FAIL — a cell did not complete"
  exit 1
fi

# ── Compare: 8.0 is the reference; classify every report per 5.x arm ────────────────────
python3 - "$RUN_ROOT" <<'PYEOF'
import json, sys, os
root = sys.argv[1]

def load(label):
    with open(os.path.join(root, label, 'answers.json')) as fh:
        return json.load(fh)

def canon_rows(v):
    # Order-insensitive form of a report's rows for the order-only classification.
    if isinstance(v, list):
        return sorted(json.dumps(r, sort_keys=True) for r in v)
    return json.dumps(v, sort_keys=True)

ref = load('80')
fps = {l: open(os.path.join(root, l, 'fingerprint.txt')).read().strip() for l in ('80','57','56')}
vers = {l: open(os.path.join(root, l, 'version.txt')).read().strip() for l in ('80','57','56')}

print('CONTROLS')
print(f'  versions: 8.0={vers["80"]}  5.7={vers["57"]}  5.6={vers["56"]}')
assert len({vers[l].split('.')[0] + vers[l].split('.')[1] for l in vers}) == 3, 'arms do not differ'
print(f'  fingerprints identical: {fps["80"] == fps["57"] == fps["56"]}  ({fps["80"]})')
if not (fps['80'] == fps['57'] == fps['56']):
    print('VERDICT: FAIL — corpora differ between cells; nothing below is comparable')
    sys.exit(1)

fail = []
order_only = {l: [] for l in ('57','56')}
for label in ('57','56'):
    arm = load(label)
    if set(arm) != set(ref):
        fail.append(f'{label}: report set differs: only-ref={sorted(set(ref)-set(arm))} only-arm={sorted(set(arm)-set(ref))}')
        continue
    for rid in sorted(ref):
        a, b = ref[rid], arm[rid]
        if json.dumps(a, sort_keys=True) == json.dumps(b, sort_keys=True):
            continue
        if canon_rows(a) == canon_rows(b):
            order_only[label].append(rid)
            if rid.startswith('chart'):
                fail.append(f'{label}: {rid} is ORDER-different — the chart split must be order-independent, and is not')
            continue
        fail.append(f'{label}: {rid} VALUE diff')

print()
print(f'reports compared per arm: {len(ref)}')
for label in ('57','56'):
    oo = order_only[label]
    print(f'  5.{label[1]}: order-only diffs: {len(oo)}' + (f' ({", ".join(oo)})' if oo else ''))

if fail:
    print()
    for f in fail:
        print('  FAIL ' + f)
    print(f'VERDICT: FAIL — {len(fail)} difference(s) beyond order-only')
    sys.exit(1)
print()
print('VERDICT: PASS — every report answers identically on 5.6/5.7/8.0 up to recorded row order; every chart report byte-identical')
PYEOF
