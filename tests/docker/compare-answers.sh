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

# The corpus profile. NAMED BY THE CALLER, never defaulted — and the defect that removed the
# default is the reason to state why at length.
#
# This line used to read `${SLIMSTAT_SEED_PROFILE:-seed-profile-i8.json}`, with a comment saying
# "the campaign passes seed-profile-verify.json". No caller did. R20260824-a51bf2 therefore ran
# the campaign's first real OLD<->NEW capture on the I8 fixture, where `get_recent_events`,
# `get_top_events` and `get_top_outbound` are empty on BOTH arms: three surfaces comparing equal
# about a question neither arm was asked. Nothing downstream could see it — the packet's nine
# controls read the answers document, and all nine PASSED, because the vacuity lives in the caps
# file the packet deliberately excludes.
#
# Moving the default up to verify-change.sh would relocate that defect rather than close it, and
# would make it worse in one specific way: CLAUDE.md's noise-floor command invokes THIS script
# directly, so the null control would have gone on seeding I8 while the run it is supposed to
# bound seeded verify — a floor measured against a different corpus from the thing above it.
#
# The stated reason for keeping a default here does not survive inspection either: `run.json`
# below already records `seed_profile`, so an archived run is interpretable from the artifact,
# not from what the default happened to be on the day. So there is no default. A caller that
# does not name a corpus gets an error instead of a fixture.
SEED_PROFILE="${SLIMSTAT_SEED_PROFILE:?name the corpus (seed-profile-verify.json for the campaign, seed-profile-i8.json to reproduce a pre-Run-58 record)}"

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

# $ART is CLEARED, not merely created. It used to be only mkdir -p'd, so every artifact from
# the previous run survived into this one — and seven `exit 1` paths sit between here and the
# first answers write (worktree add, autoloader rebuild, boot, core download, core install,
# activate, seed). Any of them left the PREVIOUS run's complete, self-consistent, two-arm pair
# sitting in $ART looking exactly like this run's output. That is how PITFALLS 60 happened one
# layer up: a pair copied out of here need not have come from one run, and no fingerprint check
# can see it, because both arms are legitimately different code.
rm -rf "$WP_DIR" "$ART"
mkdir -p "$WP_DIR" "$ART" "$WORKTREES"

# What this run IS, written before it can produce anything, so evidence copied out of $ART can
# be joined back to the run that made it. _arm_fingerprint identifies the CODE; two runs of the
# same ref pair are byte-identical in every field the answers documents carry, which is exactly
# the confusion PITFALLS 60 records. RUN_ID is the field that separates them.
RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
# timing_reps and blocks are recorded because their ABSENCE is what PITFALLS 89 cost: a null
# control at 1x1 certified a comparison at 5x4, and the mismatch existed only as prose in two
# log files, one line above the numbers it invalidated. A fact a gate can read, not a variable a
# reviewer has to trust.
printf '{"run_id":"%s","before_ref":"%s","after_ref":"%s","seed_profile":"%s","rows":%s,"days":%s,"null_control":%s,"timing_reps":%s,"blocks":%s,"php":"%s","wp":"%s"}\n' \
  "$RUN_ID" "$BEFORE" "$AFTER" "$SEED_PROFILE" "$ROWS" "$DAYS" \
  "${SLIMSTAT_NULL_CONTROL:-0}" "${SLIMSTAT_TIMING_REPS:-5}" "${SLIMSTAT_BLOCKS:-4}" \
  "$PHP" "$WP" > "$ART/run.json"

echo "CONTROLS:"
for ref in "$BEFORE" "$AFTER"; do
  # '-' means THE WORKING TREE, resolved here explicitly — handed to git instead, '-' is
  # @{-1}, the PREVIOUSLY CHECKED-OUT ref, and this script silently compared HEAD against
  # a weeks-old tree while reporting plausible differences (PITFALLS 51). Every arm's
  # RESOLVED identity is printed before any answer, so the next unintended arm is visible
  # in the CONTROLS block rather than deduced from the shape of its wrong numbers.
  if [ "$ref" = "-" ]; then
    echo "  arm '-': WORKING TREE ($(git -C "$PLUGIN_SRC" rev-parse --short HEAD)+uncommitted)"
    continue
  fi
  echo "  arm '$ref': $(git -C "$PLUGIN_SRC" rev-parse --short "$ref^{commit}" 2>/dev/null || echo 'UNRESOLVABLE')"
done

for ref in "$BEFORE" "$AFTER"; do
  if [ "$ref" = "-" ]; then
    # The working tree, copied through the same rsync surface as sync_plugin_src uses —
    # never a git worktree, which cannot express uncommitted changes.
    dir="$WORKTREES/worktree"
    rm -rf "$dir"; mkdir -p "$dir"
    rsync -a --delete --exclude '.git' --exclude 'node_modules' --exclude 'tests/e2e/node_modules' \
          "$PLUGIN_SRC/" "$dir/" >/dev/null 2>&1
    continue
  fi
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
  # '-' resolved to the working-tree copy made above; see the CONTROLS block.
  local arm_dir="$WORKTREES/$1"
  [ "$1" = "-" ] && arm_dir="$WORKTREES/worktree"
  rm -rf "$WP_DIR/wp-content/plugins/wp-slimstat"
  rsync -a --delete --exclude '.git' --exclude 'node_modules' --exclude 'tests/e2e/node_modules' \
        "$arm_dir/" "$WP_DIR/wp-content/plugins/wp-slimstat/" >/dev/null 2>&1
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

log "[$CELL] seeding $ROWS rows over $DAYS days ($SEED_PROFILE)"
dc exec -T -u www-data wp wp --path=/var/www/html eval-file \
   wp-content/plugins/wp-slimstat/tests/bench/lib/seed.php "$ROWS" "$DAYS" "$SEED_PROFILE" \
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
  # The capability/status record is EXTRACTED, not left in the .raw. Without this the extended
  # tier's verdict had no reader at all: the instrument's WARN goes to stderr, which lands in a
  # .raw that nothing greps, that verify-change.sh does not copy into the adjudication packet, and
  # that each of the four interleaved blocks overwrites — so a surface that errored on one arm was
  # "recorded loudly" into a file deleted seven times. An extracted file is what makes the claim
  # true. It is deliberately NOT copied into the blind packet: it names capabilities per era.
  grep -h 'SLIMSTAT-CAPS'    "$out.raw" | sed 's/^SLIMSTAT-CAPS //'    > "${out%.json}-caps.json"
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
    # Told apart, because they are different problems with different fixes. An arm that could not
    # BOOT (activation, autoloader, a fatal) and an arm that booted and MEASURED NOTHING both
    # arrive here as an empty .json, and the generic message sent the operator to the raw file to
    # find out which.
    if grep -q 'SLIMSTAT-HOLLOW-REPORT' "$ART/$f.json.raw" 2>/dev/null; then
      err "  [FAIL] the $f arm ran but one or more reports returned no rows:"
      err "         $(grep -o 'SLIMSTAT-HOLLOW-REPORT FAIL: .*' "$ART/$f.json.raw" | head -1)"
      err "         An empty report compares equal to an empty report, so it cannot detect a"
      err "         change in the code that produces it. Fix the report or the corpus."
    else
      err "  [FAIL] the $f arm produced no answers — see $ART/$f.json.raw"
    fi
    exit 1
  fi
done
# WHICH CORPUS produced this, printed with the other controls. Two archived runs are otherwise
# indistinguishable — an I8 run and a verify run differ in whether three surfaces could answer at
# all, and a reader who cannot tell them apart cannot tell what an "identical" covered.
echo "  seed profile: $SEED_PROFILE  (rows=$ROWS days=$DAYS)"

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

# EXTENDED SURFACES that errored, named here rather than left in a scratch file. These are not
# fatal to the run by design — an errored extended surface is a FINDING about that era, and the
# instrument's core tier is what this comparison actually diffs — but a finding nobody prints is
# a finding nobody has. Read from the extracted CAPS record, per arm, so the two eras' errors are
# attributable rather than merged.
caps_by_arm = {}
detector = {}
for label in ('before', 'after'):
    caps_path = os.path.join(art, label + '-caps.json')
    if not os.path.exists(caps_path) or os.path.getsize(caps_path) == 0:
        print('  [WARN] %s arm emitted no CAPS record — the extended tier reported nothing' % label)
        continue
    try:
        caps = json.load(open(caps_path))
    except Exception as exc:
        print('  [WARN] %s arm CAPS record unreadable (%s)' % (label, exc))
        continue
    surfaces = caps.get('_arm_surfaces', {})
    caps_by_arm[label] = surfaces

    detector[label] = caps.get('_instrument', {})
    bad = sorted(k for k, v in surfaces.items() if v.get('class') == 'error')
    unsup = sorted(k for k, v in surfaces.items() if v.get('class') == 'unsupported')
    print('  [%s] %s arm extended surfaces: %d captured, %d errored%s' % (
        'PASS' if not bad else 'NOTE', label, len(surfaces), len(bad),
        (' — ' + ', '.join(bad)) if bad else ''))
    if unsup:
        print('         unsupported on this arm (recorded, not a failure): %s' % ', '.join(unsup))

# THE CLASSIFIER'S OWN PRECONDITION, printed OUTSIDE the per-arm loop on purpose: inside it, an
# unreadable or missing CAPS file `continue`s, so the arm whose record is gone would print no line
# at all — and a control that goes SILENT exactly when something is wrong is the failure mode this
# block exists to remove. Absent is FAIL here.
#
# What it asserts: a statement that CANNOT succeed registered as an error in this container, BOTH
# unsuppressed and under suppress_errors(true) — the second being the state most report code runs
# in. The instrument reports the two halves; the AND is made HERE, because "both are required" is
# a judgement and this is the line that publishes a verdict. Both arms print the same fact by
# construction, so two PASS lines are one fact printed twice, not two controls.
#
# NON-ABORTING, and the reason is worth stating rather than assumed: a dead detector cannot
# produce a silently green run here. A CORE report whose query failed comes back empty, which the
# instrument's hollow gate already exits 1 on; an EXTENDED surface fails identically on both arms
# of one container, so the both-arms-empty control below names it. The detector's value is
# DIAGNOSTIC — it turns "this report is hollow" into "this report errored and here is the SQL" —
# so a print earns its keep where throwing away a four-minute boot would not.
for label in ('before', 'after'):
    inst = detector.get(label, {})
    halves = (('unsuppressed', 'detector_loud'), ('suppressed', 'detector_suppressed'))
    broke = [name for name, key in halves if inst.get(key) is not True]
    live = not broke and inst != {}

    note = ''
    if not live:
        note = " — the extended tier's error/empty classes are unsafe to read on this arm"
        if label not in detector:
            note += ' (no CAPS record for this arm)'
        elif broke:
            note += ' (failed: %s)' % ', '.join(broke)

    print('  [%s] %s arm: the error detector fired, suppressed and unsuppressed%s' % (
        'PASS' if live else 'FAIL', label, note))

# THE VACUITY FLOOR, mechanised rather than remembered. A surface EMPTY on BOTH arms compares
# equal and reports agreement about a question neither arm was asked — PITFALLS 44's shape, and
# PITFALLS 38's before it. Three surfaces sat in exactly that state for the whole of S1's first
# run (no events, no outbound links in the corpus) and nothing said so; the corpus overlay fixed
# those three, and this line is what catches the fourth. Stated as a control rather than left to
# whoever reads the table, because "I checked" is the thing this programme keeps disproving.
if len(caps_by_arm) == 2:
    a_s, b_s = caps_by_arm['before'], caps_by_arm['after']
    vacuous = sorted(k for k in set(a_s) & set(b_s)
                     if a_s[k].get('class') == 'empty' and b_s[k].get('class') == 'empty')
    print('  [%s] no extended surface is empty on BOTH arms%s' % (
        'PASS' if not vacuous else 'FAIL',
        '' if not vacuous else ': ' + ', '.join(vacuous) +
        ' — these compare equal while proving nothing; enrich the corpus before trusting them'))
    # THE EXTENDED TIER'S NULL CONTROL, and it only means anything in this mode. Under
    # SLIMSTAT_NULL_CONTROL the two "arms" are the SAME code over the same corpus, so every
    # extended surface must return the same value twice; anything that moves is nondeterministic
    # and would read as a code difference on a real run. The answers document has had a control
    # like this since run-rollup-floor compared two passes byte-for-byte, but the extended tier
    # was moved onto its own line for the blind's sake and the control did not move with it —
    # so the 22 newest values were the only ones nothing checked for repeatability.
    #
    # Reported per surface rather than as one verdict: "something moved" sends a reader back to
    # a container, "get_overview_summary moved" sends them to a clock-dependent report.
    if null_control_env:
        unstable = sorted(
            k for k in set(a_s) & set(b_s)
            if json.dumps(a_s[k].get('value'), sort_keys=True)
            != json.dumps(b_s[k].get('value'), sort_keys=True)
            or a_s[k].get('class') != b_s[k].get('class')
        )
        print('  [%s] NULL CONTROL: extended surfaces repeat across two passes of one arm%s' % (
            'PASS' if not unstable else 'FAIL',
            '' if not unstable else ': %d moved — %s' % (len(unstable), ', '.join(unstable))))

    # THE ABORT, and it is BELOW the control above rather than beside the FAIL line, because the
    # two orders are not equivalent. A null control runs the SAME ref as both arms, so every
    # surface the corpus does not populate is empty on both by construction — aborting on that
    # would make the noise-floor run unrunnable on any corpus with a quiet surface, and the noise
    # floor is what CLAUDE.md requires BEFORE any latency claim. So the vacuity abort is a
    # statement about a REAL comparison, and under SLIMSTAT_NULL_CONTROL it is a warning instead.
    #
    # Until this existed the check printed FAIL and carried on: R20260824-a51bf2 named three
    # vacuous surfaces and then published `DIFFERENCES in 2 of 26 reports` and handed the packet
    # to the builder. Nothing downstream could catch it either — the packet's own nine controls
    # read the ANSWERS document, and every one of them passed on that run, because the vacuity
    # lives in the caps file the packet deliberately excludes.
    if vacuous:
        if null_control_env:
            print('  [NOTE] NULL CONTROL: the vacuity above is expected — one ref, two arms.')
        else:
            print('\nVERDICT: ABORTED — an extended comparison is vacuous')
            sys.exit(1)

# SLIMSTAT_NULL_CONTROL=1 runs the SAME ref as both arms deliberately: any delta it reports is
# environmental by construction, because there is no code difference to produce one. It is the
# decisive test for the timing block, which — unlike the answers block above — has no control of
# its own. A blind adjudicator named its absence as the reason no latency claim here is supported.
null_control = null_control_env
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
          % (reps, os.environ['SLIMSTAT_BLOCKS']))
    print('  %-26s %10s %10s %10s' % ('report', 'before', 'after', 'delta'))
    print('  ' + '-' * 60)
    for key in keys:
        x, y = ta[key]['median'], tb[key]['median']
        print('  %-26s %10.2f %10.2f %+10.2f' % (key, x, y, y - x))
    print()
    # THIS RUN'S OWN SEPARATION, not a remembered floor.
    #
    # These three lines used to assert "MEASURED NOISE FLOOR of this harness ... within +/-1.3 ms
    # overall" as though it had been measured for the run being printed. It had not: it was a
    # figure from one null control, hardcoded, and reprinted verbatim under every subsequent
    # comparison. Two null controls on the campaign corpus measured chart_weekly spreads of
    # ~4 ms on a quiet machine and ~55 ms on a loaded one — so the sentence was off by a factor
    # of forty when it mattered most, and it was the sentence a reader would use to decide
    # whether a delta meant anything.
    #
    # What can be said from the run itself, with no remembered constant and no distributional
    # assumption, is whether the two arms' OBSERVED RANGES OVERLAP. Overlapping ranges cannot
    # separate the arms whatever the medians do. Disjoint ranges are a real separation in this
    # run's own conditions, and the gap is printed so it can be weighed against the deltas above.
    # It is still not a substitute for the deterministic counters, which is why they come first.
    print('  Raw spread (min..max) per arm, and whether the two ranges are DISJOINT in this run.')
    print('  Overlapping ranges cannot separate the arms however far apart the medians sit; a')
    print('  remembered noise floor from another run cannot settle it either. The counters above')
    print('  are what carry a claim — this is context for them.')
    # The floor lives HERE as well as in verify-change.sh, because this is where the claim is
    # EMITTED and this script is a documented entry point in its own right -- CLAUDE.md tells
    # you to run it directly for the noise floor, which walks straight past the caller's guard.
    # Below 5 x 4 the spreads are too few samples to mean anything: at 1 rep min..max is a
    # point, so `disjoint` is true of any two unequal numbers. PITFALLS 89.
    if not isinstance(reps, int) or reps < 5 or int(os.environ['SLIMSTAT_BLOCKS']) < 4:
        print('    REFUSED: %s reps x %s blocks is below the 5 x 4 floor a separation verdict'
              % (reps, os.environ['SLIMSTAT_BLOCKS']))
        print('    needs. At 1 rep min..max is a point and `disjoint` is true of any two')
        print('    unequal numbers -- see PITFALLS 89. The counters above are unaffected.')
    else:
        for key in keys:
            lo_a, hi_a = ta[key]['min'], ta[key]['max']
            lo_b, hi_b = tb[key]['min'], tb[key]['max']
            gap = max(lo_b - hi_a, lo_a - hi_b, 0.0)   # 0 when the ranges overlap, either order
            note = ('disjoint by %.2f ms' % gap) if gap > 0 else 'OVERLAP — not separated'
            print('    %-24s before %.2f..%.2f   after %.2f..%.2f   %s'
                  % (key, lo_a, hi_a, lo_b, hi_b, note))
    print()
    print('  Run `SLIMSTAT_NULL_CONTROL=1` with one ref as both arms for this machine\'s floor')
    print('  today; it varies with load, which is the reason the figure is not baked in here.')
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
