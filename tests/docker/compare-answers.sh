#!/usr/bin/env bash
# tests/docker/compare-answers.sh <before-ref> <after-ref> [rows] [days] [http_port] [db_port]
#
# Do the REPORTS STILL GIVE THE SAME ANSWERS after a change?
#
# measure-arms.sh counts what a change cost. This asks whether the numbers moved. They are
# different questions and only one of them can tell you a refactor broke something: a change
# that halves the query count and quietly shifts a total is worse than the code it replaced,
# and every gate in this programme would have passed it — the unit tests exercise the new path
# only, and the topology probe checks one aggregate.
#
# Method. Seed the I8 corpus ONCE, then run the same report set under each arm against that
# same unchanged data, and diff the answers byte for byte. Reports are read-only, so one corpus
# serves both arms and there is no reseeding variance between them.
#
# The corpus matters. Run this against the pre-I8 fixture and a report that ignores its date
# filter returns the same number as one that honours it, so the diff is empty and means nothing.
# seed-bench.sh asserts the corpus separates its ranges before any of this runs.
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
[ -f "$HARNESS_DIR/matrix.env" ] && source "$HARNESS_DIR/matrix.env"

BEFORE="${1:?before ref}"
AFTER="${2:?after ref}"
ROWS="${3:-150000}"
DAYS="${4:-180}"
HTTP_PORT="${5:-18970}"
DB_PORT="${6:-13970}"
PHP="${TOPOLOGY_PHP:-8.2}"
WP="${TOPOLOGY_WP:-6.7}"

CELL="answers"
CELL_DIR="$WORK_ROOT/answers/$CELL"
WP_DIR="$CELL_DIR/wp"
ART="$CELL_DIR/artifacts"
WORKTREES="$CELL_DIR/arms"

export COMPOSE_PROJECT_NAME="ssanswers" PHP_VERSION="$PHP" HTTP_PORT DB_PORT
export MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.0}"
export CELL_WP_DIR="$WP_DIR"

cleanup() { dc down -v --remove-orphans >/dev/null 2>&1 || true; }
trap cleanup EXIT

rm -rf "$WP_DIR"
mkdir -p "$WP_DIR" "$ART" "$WORKTREES"

for ref in "$BEFORE" "$AFTER"; do
  dir="$WORKTREES/$ref"
  # Tested on a FILE the worktree must contain, not on the directory existing. `[ -d "$dir" ]`
  # is true for an empty directory left by an interrupted run, so the worktree add was skipped,
  # vendor/ was rsynced into nothing, composer found no composer.json, and the arm booted with
  # the CURRENT tree's classmap — authoritative, and mapping classes that ref does not have.
  if [ ! -f "$dir/composer.json" ]; then
    rm -rf "$dir"
    git -C "$PLUGIN_SRC" worktree prune >/dev/null 2>&1
    git -C "$PLUGIN_SRC" worktree add --detach "$dir" "$ref" >/dev/null 2>&1 \
      || { err "cannot create a worktree at $ref"; exit 1; }
  fi
  # Same normalisation measure-arms.sh declares, for the same reason: at some refs the committed
  # autoloader is the dev one and the arm cannot boot at all.
  rsync -a "$PLUGIN_SRC/vendor/" "$dir/vendor/" >/dev/null 2>&1
  # FATAL, not a warning. A failed rebuild leaves the current tree's classmap in place, and it
  # is classmap-AUTHORITATIVE — so the arm cannot load a class that ref does not have, and the
  # run reports "not loadable" several minutes later with the cause scrolled off screen.
  ( cd "$dir" && composer run build:autoload >/dev/null 2>&1 ) \
    || { err "could not rebuild the autoloader at $ref — that arm would boot with the wrong classmap"; exit 1; }
done

log "[$CELL] build + up"
boot_stack "$ART" "$PHP" || { err "stack did not come up"; exit 1; }

wpc core download --version="$WP" --force > "$ART/install.log" 2>&1 || { err "core download failed"; exit 1; }
wp_config_debug "$ART/install.log"
wpc core install --url="http://127.0.0.1:${HTTP_PORT}" --title="SS answers" --admin_user=admin \
    --admin_password=admin --admin_email=qa@example.com --skip-email >>"$ART/install.log" 2>&1 \
    || { err "core install failed"; exit 1; }

use_arm() {
  rm -rf "$WP_DIR/wp-content/plugins/wp-slimstat"
  rsync -a --delete --exclude '.git' --exclude 'node_modules' --exclude 'tests/e2e/node_modules' \
        "$WORKTREES/$1/" "$WP_DIR/wp-content/plugins/wp-slimstat/" >/dev/null 2>&1
  # INSTRUMENTS ALWAYS COME FROM THE CURRENT TREE, NEVER FROM THE ARM. A probe that differs
  # between arms measures itself as well as the change.
  #
  # This applies to the SEEDER too, and the first version of this script missed that: it copied
  # report-answers.php from the current tree but let the seeder come from the arm's worktree.
  # The I8 overlay support is newer than either ref, so the arm's seeder ignored the third
  # argument silently and built a corpus with 1,325 distinct resources instead of 4,094 — below
  # A4's cliff, i.e. exactly the pre-I8 fixture the whole seam exists to replace. The comparison
  # was still internally valid (both arms saw the same data) but it was not the corpus claimed.
  mkdir -p "$WP_DIR/wp-content/plugins/wp-slimstat/tests/docker"
  cp "$HARNESS_DIR/report-answers.php" "$WP_DIR/wp-content/plugins/wp-slimstat/tests/docker/" 2>/dev/null
  rsync -a "$PLUGIN_SRC/tests/bench/" "$WP_DIR/wp-content/plugins/wp-slimstat/tests/bench/" >/dev/null 2>&1
  chmod -R a+rwX "$WP_DIR/wp-content" 2>/dev/null || true
}

# Seed once, under the AFTER arm's schema, then never touch the data again.
use_arm "$AFTER"
wpc plugin activate wp-slimstat >>"$ART/install.log" 2>&1 || { err "activate failed"; exit 1; }
wpc eval 'include_once(WP_PLUGIN_DIR."/wp-slimstat/admin/index.php"); wp_slimstat_admin::init_tables($GLOBALS["wpdb"]); echo "t";' \
    >>"$ART/install.log" 2>&1

log "[$CELL] seeding $ROWS rows over $DAYS days (I8 overlay)"
dc exec -T -u www-data wp wp --path=/var/www/html eval-file \
   wp-content/plugins/wp-slimstat/tests/bench/lib/seed.php "$ROWS" "$DAYS" seed-profile-i8.json \
   > "$ART/seed.log" 2>&1 || { err "seeding failed"; exit 1; }

# Absolute window bounds, computed ONCE and passed to both arms. "Last 30 days" evaluated
# independently per arm would select different rows on either side of a minute boundary and diff
# for a reason that is not the code.
NOW=$(wpc eval 'echo time();' 2>/dev/null | tr -dc '0-9')
WIN_END="$NOW"
WIN_START=$((NOW - 30 * 86400))

answers_for() {
  local ref="$1" out="$2"
  use_arm "$ref"
  dc exec -T -u www-data \
     -e SLIMSTAT_ANSWERS_START="$WIN_START" -e SLIMSTAT_ANSWERS_END="$WIN_END" \
     -e SLIMSTAT_TIMING_REPS="${SLIMSTAT_TIMING_REPS:-5}" wp \
     wp --path=/var/www/html eval-file \
     wp-content/plugins/wp-slimstat/tests/docker/report-answers.php > "$out.raw" 2>&1
  grep -h 'SLIMSTAT-ANSWERS' "$out.raw" | sed 's/^SLIMSTAT-ANSWERS //' > "$out"
  grep -h 'SLIMSTAT-TIMING'  "$out.raw" | sed 's/^SLIMSTAT-TIMING //'  > "${out%.json}-timing.json"
}

# ── INTERLEAVED, not one block per arm ──────────────────────────────────────
# The first version ran BEFORE fully, then AFTER fully. The corpus is seeded immediately before
# the first arm, so that arm queried an InnoDB instance still flushing 150,000 rows of dirty
# pages while the second got a warm buffer pool — a first-mover penalty that always landed on
# `before`. A null control (same ref as both arms) measured +11.3% from that alone.
#
# Blocks alternate A-B-B-A so any monotonic drift in machine state falls on both arms equally,
# and the LAST block per arm is the one reported — the earlier blocks absorb warm-up.
BLOCKS="${SLIMSTAT_BLOCKS:-4}"
b=0
while [ "$b" -lt "$BLOCKS" ]; do
  # A-B-B-A: even blocks lead with BEFORE, odd blocks lead with AFTER.
  if [ $((b % 2)) -eq 0 ]; then
    answers_for "$BEFORE" "$ART/before.json"
    answers_for "$AFTER"  "$ART/after.json"
  else
    answers_for "$AFTER"  "$ART/after.json"
    answers_for "$BEFORE" "$ART/before.json"
  fi
  b=$((b + 1))
done

# ── CONTROLS, before any result ─────────────────────────────────────────────
echo
echo "CONTROLS"
for f in before after; do
  if [ ! -s "$ART/$f.json" ]; then
    err "  [FAIL] the $f arm produced no answers — see $ART/$f.json.raw"
    exit 1
  fi
done
SLIMSTAT_NULL_CONTROL="${SLIMSTAT_NULL_CONTROL:-0}" SLIMSTAT_BLOCKS="$BLOCKS" python3 - "$ART" "$WIN_START" "$WIN_END" <<'PY'
import json, sys, os
art, start, end = sys.argv[1], int(sys.argv[2]), int(sys.argv[3])
a = json.load(open(os.path.join(art, 'before.json')))
b = json.load(open(os.path.join(art, 'after.json')))

# A comparison over an empty or trivial answer set is vacuously equal. Prove it is not.
print('  [%s] before arm answered %d reports' % ('PASS' if len(a) >= 10 else 'FAIL', len(a)))
print('  [%s] after arm answered %d reports' % ('PASS' if len(b) >= 10 else 'FAIL', len(b)))
print('  [%s] corpus is non-trivial: %s rows counted' % (
    'PASS' if a.get('count_records_id', 0) > 10000 else 'FAIL', a.get('count_records_id')))
print('  [%s] top_resource returned rows: %d' % (
    'PASS' if len(a.get('top_resource', [])) > 0 else 'FAIL', len(a.get('top_resource', []))))
print('  [%s] the date window selects a strict subset: %s of %s' % (
    'PASS' if 0 < a.get('rows_in_window', 0) < a.get('count_records_id', 0) else 'FAIL',
    a.get('rows_in_window'), a.get('count_records_id')))

# The corpus must be past A4's MEMORY temp-table cliff, or this is the pre-I8 fixture wearing
# I8's name. Measured via the reports themselves, not trusted from the seeder's own summary.
distinct_res = a.get('count_records_resource', 0)
print('  [%s] corpus cardinality past the 2048 cliff: %s distinct resources' % (
    'PASS' if distinct_res > 2048 else 'FAIL', distinct_res))

# THE ARMS MUST DIFFER. Two identical files are the strongest possible "equivalent" and also
# what a harness that failed to swap arms produces. A blind auditor named this as the one thing
# the artifacts could not establish about themselves.
null_control_env = os.environ.get('SLIMSTAT_NULL_CONTROL') == '1'
same_arm = a.get('_arm_fingerprint') == b.get('_arm_fingerprint')
print('  [%s] the two arms are actually different code: %s vs %s  (%s PHP files hashed)' % (
    'FAIL' if same_arm else 'PASS',
    str(a.get('_arm_fingerprint'))[:12], str(b.get('_arm_fingerprint'))[:12], a.get('_arm_files', '?')))
if same_arm and not null_control_env:
    print('         The two refs differ, but their src/ + admin/ PHP is byte-identical, so this')
    print('         comparison could not observe the change. Either the change is outside the')
    print('         shipped PHP surface (tests, docs, CI) — in which case there is nothing for')
    print('         this harness to measure — or the wrong refs were passed.')

# SLIMSTAT_NULL_CONTROL=1 runs the SAME ref as both arms deliberately: any delta it reports is
# environmental by construction, because there is no code difference to produce one. It is the
# decisive test for the timing block, which — unlike the answers block above — has no control of
# its own. A blind adjudicator named its absence as the reason no latency claim here is supported.
null_control = os.environ.get('SLIMSTAT_NULL_CONTROL') == '1'
null_control_env = null_control
if same_arm and null_control:
    print('  [NOTE] NULL CONTROL: both arms are the same code. Any timing delta below is')
    print('         environmental — it is the noise floor of this harness, not a result.')
elif same_arm or distinct_res <= 2048:
    print('\nVERDICT: ABORTED — the comparison would not mean what it says')
    sys.exit(1)

if len(a) < 10 or len(b) < 10 or a.get('count_records_id', 0) <= 10000:
    print('\nVERDICT: ABORTED — the comparison would be vacuous')
    sys.exit(1)

# ── the diff ────────────────────────────────────────────────────────────────
print()
diffs = []
for key in sorted(set(a) | set(b)):
    if key.startswith('_arm_'):
        continue   # provenance, asserted DIFFERENT above; not a report answer
    if a.get(key) != b.get(key):
        diffs.append(key)

# ── timings, reported beside the verdict, never as part of it ───────────────
# Correctness is the gate; cost is information. A run that got faster and changed an answer is a
# failure, so the two are never combined into one number.
ta_path = os.path.join(art, 'before-timing.json')
tb_path = os.path.join(art, 'after-timing.json')
if os.path.exists(ta_path) and os.path.exists(tb_path):
    ta, tb = json.load(open(ta_path)), json.load(open(tb_path))
    keys = sorted(k for k in ta if not k.startswith('_') and k in tb)

    # ── COUNTERS FIRST. These are the claim; milliseconds are context. ──────
    # Handler_read_rnd_next is rows read by full scan, Created_tmp_disk_tables is the
    # MEMORY-to-disk spill A4 names, Sort_rows is what a filesort moved. They do not vary
    # with machine load, so a difference here IS the change and an absence of difference
    # means any latency delta is environmental.
    print('  deterministic counters, one clean execution per report')
    counter_names = ['Handler_read_rnd_next', 'Handler_read_next', 'Created_tmp_disk_tables', 'Sort_rows']
    moved = []
    for key in keys:
        ca, cb = ta[key].get('counters', {}), tb[key].get('counters', {})
        diffs_here = [(n, ca.get(n, 0), cb.get(n, 0)) for n in counter_names if ca.get(n, 0) != cb.get(n, 0)]
        if diffs_here:
            moved.append(key)
            print('    %-26s CHANGED' % key)
            for n, x, y in diffs_here:
                print('        %-26s %12d -> %12d  (%+d)' % (n, x, y, y - x))
        else:
            print('    %-26s identical  (rnd_next %s, tmp_disk %s)'
                  % (key, ca.get('Handler_read_rnd_next', '?'), ca.get('Created_tmp_disk_tables', '?')))
    print()

    if not moved:
        print('  => No counter moved. The two arms do the SAME work at the storage engine.')
        print('     Any millisecond difference below is therefore environmental, not a result.')
    else:
        print('  => Counters moved for: %s. That difference is real work, and IS reportable.' % ', '.join(moved))
    print()

    # ── milliseconds, explicitly subordinate ────────────────────────────────
    reps = ta.get('_reps', '?')
    print('  latency, %s reps x %s interleaved blocks per arm, caches and transients cleared'
          % (reps, os.environ.get('SLIMSTAT_BLOCKS', '4')))
    print('  %-26s %10s %10s %10s' % ('report', 'before', 'after', 'delta'))
    print('  ' + '-' * 60)
    for key in keys:
        x, y = ta[key]['median'], tb[key]['median']
        print('  %-26s %10.2f %10.2f %+10.2f' % (key, x, y, y - x))
    print()
    print('  Raw spread (min..max). MEASURED NOISE FLOOR of this harness, from a null control')
    print('  (same ref as both arms) with interleaving on: within +/-1.3 ms overall, and within')
    print('  +/-0.9 ms on the heavy reports. Before interleaving it was +12.7 ms (+11.3%) on')
    print('  top_resource alone. Treat any delta inside the floor as noise:')
    for key in keys:
        print('    %-24s before %.2f..%.2f   after %.2f..%.2f'
              % (key, ta[key]['min'], ta[key]['max'], tb[key]['min'], tb[key]['max']))
    print()

if not diffs:
    print('VERDICT: IDENTICAL — %d reports, every answer byte-for-byte equal' % len([k for k in a if not k.startswith('_arm_')]))
    sys.exit(0)

print('VERDICT: DIFFERENCES in %d of %d reports\n' % (len(diffs), len(set(a) | set(b))))
for key in diffs:
    x, y = a.get(key), b.get(key)
    if isinstance(x, list) or isinstance(y, list):
        x_n, y_n = len(x or []), len(y or [])
        print('  %-28s  before %d rows, after %d rows' % (key, x_n, y_n))
        for i in range(max(x_n, y_n)):
            xr = (x or [])[i] if i < x_n else None
            yr = (y or [])[i] if i < y_n else None
            if xr != yr:
                print('      row %d:\n        before %s\n        after  %s' % (i, xr, yr))
                break
    else:
        print('  %-28s  before %s, after %s' % (key, x, y))

print('\nEach difference is a defect or an EXPECTED-DIFFS entry. Never a shrug.')
sys.exit(2)
PY
rc=$?

log "[$CELL] answers: $ART/before.json  $ART/after.json"
exit $rc
