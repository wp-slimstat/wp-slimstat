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
# S3 ADDITION — the two fingerprint gates run here, on every cell, for the same reason the
# reports do. `HEX`, `CAST` and `CHAR_LENGTH` semantics are precisely what differs across these
# three servers, so an ENCODING_V1 claim proven once on 8.0 leaves 5.6 and 5.7 asserting a shape
# nobody executed. This script is the only one in the tree that already stands up all three
# against ONE corpus, which is what makes it the right host:
#
#   verify-sql-encoder.php          MySQL's evaluation of the row expression == the PHP encoder,
#                                   over seven adversarial literals.
#   verify-export-fingerprint.php   the MySQL-side strong fingerprint == the fingerprint an
#                                   independent Python process recomputes over the SQLite export
#                                   of the same corpus. Nine controls, each able to fail the run.
#
# and two lanes that exist because a gate nobody has seen fail is a gate nobody has seen:
#
#   control self-test   force each of the nine controls to fail in turn; the run must exit 1
#                       naming exactly that control. This is what makes "the controls are
#                       reachable and wired to the exit code" a measurement rather than a claim.
#   live mutations      restore a real value-path defect into the export writer and require the
#                       live equality to catch it — with the corpus precondition each mutation
#                       depends on MEASURED first, because a mutation that cannot fire looks
#                       exactly like a gate that works (PITFALLS 44).
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

# ── S3 — the fingerprint gates ────────────────────────────────────────────────────────────
FP_DIR=wp-content/plugins/wp-slimstat/tests/docker
FP_SQL="$FP_DIR/verify-sql-encoder.php"
FP_EXPORT="$FP_DIR/verify-export-fingerprint.php"
# Which cells carry the three EXTRA lanes. The gates themselves run on every cell — that is the
# whole point of hosting them here — but forcing nine controls, restoring two value-path defects
# and applying two reachability mutations is FOURTEEN further full passes over the corpus beyond
# the certifying one, and their subject is this FILE's control flow rather than the server's
# semantics. Named as a variable so the scope is one line to widen, and stated in the Run record
# rather than left for a reader to infer from the artifact set. The cost is linear in the control
# count: control 10 buys another whole corpus round trip.
FP_LANES_CELLS="80"
# The control-count RATCHET, same idiom as tests/mutations/FLOOR and for the same reason: a gate
# that quietly went from nine controls to two still exits 0, and "the run passed" is exactly the
# sentence that would report it. Every control from 1 to this number must print [OK], and the
# self-test forces every one of them in turn. Raise it deliberately when a control is added;
# lowering it to match a deletion is the move this exists to make visible.
#
# READ FROM A FILE, not written here, because `composer test:control-wiring` ratchets against the
# same number with no container at all. Two hand-maintained copies of a count is how the count
# stops meaning anything - and the docker copy would be the one nothing checks per commit.
FP_CONTROL_FLOOR="$(tr -dc '0-9' < "$HARNESS_DIR/reachability/CONTROL-FLOOR" 2>/dev/null)"
[ -n "$FP_CONTROL_FLOOR" ] || { err "tests/docker/reachability/CONTROL-FLOOR is missing or empty - the control ratchet has no value"; exit 1; }

# The cell's own copy of the plugin, on the host side of the bind mount. The live-mutation lane
# patches THIS and never the repository working tree — a mutation that escapes into the tree is
# how a "restored defect" ships (the shape PITFALLS 59 records one layer over).
cell_plugin_dir() { echo "$CELL_WP_DIR/wp-content/plugins/wp-slimstat"; }

# One invocation of the export-fidelity gate. Args: art basename [force_control_n]
# Returns the gate's exit code; output lands in $art/<basename>.txt.
run_export_gate() {
  local art="$1" base="$2" force="${3:-0}"
  # ONE invocation. The subject reads `(int) getenv(...)`, so an absent variable and an explicit
  # 0 are indistinguishable to it - a forced/unforced branch would be two spellings of one
  # command that every lane below compares artifacts across, free to drift in the --exec or the
  # redirection. `< /dev/null` because `docker compose exec` reads stdin and has already eaten a
  # caller's loop input once (PITFALLS 65).
  dc exec -T -u www-data -e "SLIMSTAT_FP_FORCE_CONTROL_FAIL=$force" wp \
     wp --path=/var/www/html --exec="define('DISABLE_WP_CRON', true);" eval-file "$FP_EXPORT" \
     > "$art/$base.txt" 2>&1 < /dev/null
}

# The two gates, on every cell. A failure here fails the cell — they are gates, not probes.
fp_gates() { # <label> <art>
  local label="$1" art="$2" rc controls

  log "[$label] SQL-encoder gate (MySQL's evaluation of the row expression vs the PHP encoder)"
  wpc eval-file "$FP_SQL" > "$art/fp-sql-encoder.txt" 2>&1
  rc=$?
  if [ "$rc" -ne 0 ] || ! grep -q '^PASS: SQL path == PHP path' "$art/fp-sql-encoder.txt"; then
    err "[$label] verify-sql-encoder.php FAILED (rc=$rc)"
    sed 's/^/      /' "$art/fp-sql-encoder.txt" | head -30
    return 1
  fi
  # The number that gate exists to print, asserted rather than logged. `fixture-verified=n/n` is
  # how it says its own adversarial inputs survived construction on THIS server, which is exactly
  # what 5.6/5.7 silently broke once (PITFALLS 66); a PASS line above a 5/7 would read as green.
  grep -qE '^SLIMSTAT-SQL-ENCODER .* fixture-verified=([0-9]+)/\1$' "$art/fp-sql-encoder.txt" || {
    err "[$label] the SQL-encoder gate did not verify every fixture row it built on this server"
    grep -h '^SLIMSTAT-SQL-ENCODER' "$art/fp-sql-encoder.txt" | sed 's/^/      /'
    return 1
  }
  log "[$label] $(grep -h '^SLIMSTAT-SQL-ENCODER' "$art/fp-sql-encoder.txt")"

  log "[$label] export-fidelity gate (MySQL fingerprint vs the Python re-encode of its export)"
  run_export_gate "$art" fp-export
  rc=$?
  if [ "$rc" -ne 0 ]; then
    err "[$label] verify-export-fingerprint.php FAILED (rc=$rc)"
    sed 's/^/      /' "$art/fp-export.txt" | head -40
    return 1
  fi
  # Exit 0 is not enough. A control the file failed to EVALUATE prints no line at all, and the
  # absence of a line is invisible to an exit code — so the count is asserted here, out of
  # process, against the ratchet rather than against whatever the file happens to print.
  # Per NUMBER, not a count: a gate printing nine lines for controls 1-8 plus a duplicate passes
  # a count and fails this. A count check beside it could never be the failure, so there is not
  # one - two candidate causes for one red gate is one more than a reader can use.
  controls=$(grep -c '^  \[OK\] [1-9]' "$art/fp-export.txt" | tr -d ' ')
  local want
  for want in $(seq 1 "$FP_CONTROL_FLOOR"); do
    grep -q "^  \[OK\] $want " "$art/fp-export.txt" || {
      err "[$label] the export gate never reported control $want as [OK] ($controls of $FP_CONTROL_FLOOR present) - a control that did not run is not a control that passed"
      sed 's/^/      /' "$art/fp-export.txt" | head -40
      return 1
    }
  done
  # `forced=0` proves this artifact came from a certifying run and not from a self-test pass.
  grep -q '^SLIMSTAT-EXPORT-FP .* forced=0$' "$art/fp-export.txt" || {
    err "[$label] the export gate's header does not read forced=0 — this artifact is a self-test, not a certification"
    grep -h '^SLIMSTAT-EXPORT-FP' "$art/fp-export.txt" | sed 's/^/      /'
    return 1
  }
  log "[$label] $(grep -h '^SLIMSTAT-EXPORT-FP' "$art/fp-export.txt")"
  grep -h '^  \[..\] [0-9]' "$art/fp-export.txt" | cut -c1-118 | sed 's/^/      /'
}

# LANE 1 — the control self-test. Six forced failures and one negative control.
#
# What it establishes, per control n: the `$control(...)` call site is REACHED on a normal run
# (a forced n that produces no `[!!] n` line is an unreachable call site), and its failure
# CHANGES THE EXIT CODE (a forced n that prints `[!!] n` and still exits 0 is a control wired to
# nothing). The negative control — a control number that does not exist — must leave the run
# passing, which is what stops "forcing makes it fail" from being satisfied by an env var that
# breaks the run for any reason at all.
fp_control_selftest() { # <label> <art>
  local label="$1" art="$2" n rc bad=0
  log "[$label] control self-test: forcing each of the $FP_CONTROL_FLOOR controls in turn"

  for n in $(seq 1 "$FP_CONTROL_FLOOR"); do
    run_export_gate "$art" "fp-forced-$n" "$n"
    rc=$?
    if [ "$rc" -eq 0 ]; then
      err "[$label] forcing control $n left the run PASSING — its failure does not reach the exit code"
      bad=1
      continue
    fi
    if ! grep -q "^  \[!!\] $n " "$art/fp-forced-$n.txt"; then
      err "[$label] forcing control $n produced no '[!!] $n' line — that call site is not reached"
      grep -h '^  \[..\] [0-9]' "$art/fp-forced-$n.txt" | sed 's/^/      /'
      bad=1
      continue
    fi
    # Exactly ONE control may fail: forcing n must not take any other control down with it, or
    # "the run failed" would stop naming which control did.
    local failed
    failed=$(grep -c '^  \[!!\] [0-9]' "$art/fp-forced-$n.txt" | tr -d ' ')
    if [ "$failed" != "1" ]; then
      err "[$label] forcing control $n failed $failed controls — the self-test cannot attribute the exit"
      grep -h '^  \[..\] [0-9]' "$art/fp-forced-$n.txt" | sed 's/^/      /'
      bad=1
      continue
    fi
    log "[$label]   control $n: forced → exit $rc, exactly [!!] $n — reachable and exit-effective"
  done

  # The negative control. 99 is not a control number, so nothing may change.
  run_export_gate "$art" fp-forced-none 99
  rc=$?
  if [ "$rc" -ne 0 ]; then
    err "[$label] the negative control (force=99, no such control) FAILED the run — forcing breaks the gate for reasons unrelated to the control it names"
    sed 's/^/      /' "$art/fp-forced-none.txt" | head -30
    bad=1
  else
    log "[$label]   negative control (force=99): run still passes — the hook fails only the control it names"
  fi

  [ "$bad" -eq 0 ]
}

# LANE 2 — live value-path mutations against the export writer.
#
# Each entry: id | mutation file (repo-relative) | target (repo-relative) | precondition SQL.
#
# The DIFFS are pre-declared files in the same format the mutation registry uses, and the first
# of them IS a registry entry — the same bytes `composer test:mutations` already applies against
# the fixture gate. Running the identical diff against the LIVE equality is what makes the two
# gates comparable: one proves the six-row fixture sees the defect, the other proves the 30k-row
# MySQL-to-SQLite round trip does. A second, separately-written diff would have proven only that
# two different defects are both catchable.
#
# The PRECONDITION is measured before the mutation is applied and its result printed. A mutation
# whose corpus cannot express the defect is not a passing gate, it is an unfired one, and this
# programme has already shipped three surfaces that compared empty to empty and called it
# agreement (PITFALLS 44).
#
# It must measure the property the mutation actually depends on, in the units the mutation works
# in. The byte-flip precondition first read `resource REGEXP '[a-z]'`, which MySQL evaluates
# under the column's case-INSENSITIVE collation: it matches an all-uppercase corpus and would
# have reported "precondition satisfied" for data on which strtoupper() is a no-op — a
# precondition that cannot fail is the same defect as a control that cannot fail, one layer out.
# `CAST(... AS BINARY) <> CAST(UPPER(...) AS BINARY)` is literally the predicate "strtoupper
# would change this value".
FP_MUTATIONS=(
  "null-collapse|tests/mutations/S3-export-collapses-null-to-empty-01.mutation|tests/bench/lib/export-snapshot.php|SELECT COUNT(*) FROM wp_slim_stats WHERE ip IS NULL OR referer IS NULL OR searchterms IS NULL OR country IS NULL OR city IS NULL"
  "byte-flip|tests/docker/mutations/S3-live-export-byte-flip-01.mutation|tests/bench/lib/export-snapshot.php|SELECT COUNT(*) FROM wp_slim_stats WHERE CAST(resource AS BINARY) <> CAST(UPPER(resource) AS BINARY)"
)

# LANE 3 — the live half of the differential reachability protocol.
#
# tests/docker/reachability/ carries two pre-declared mutations against the export gate itself:
# M1 severs CONTROL 4's call chain, M2 leaves CONTROL 5 executing while routing it through a
# renderer that records nothing the terminal exit is guarded on. Two ANALYSERS are asked to
# detect them — a token-stream one and an LLM one — and neither is trusted, because both only
# READ. This lane is the third answer, and the only one that RUNS the mutated file:
#
#   M1 applied, force control 4  →  no `[!!] 4` line appears at all. The call site is gone.
#   M2 applied, force control 5  →  `[!!] 5` appears AND the run still exits 0. The control
#                                   executed; its failure reached nothing.
#
# Without this the protocol is two readings of one file agreeing with each other, which is the
# shape it exists to reject. The digests are recorded so compute-verdict.php can tie this
# observation to the exact state the analysers described.
fp_reachability_mutations() { # <label> <art>
  local label="$1" art="$2" bad=0 entries=""
  local plug; plug="$(cell_plugin_dir)"
  local subject="tests/docker/verify-export-fingerprint.php"
  local reach="$PLUGIN_SRC/tests/docker/reachability"

  log "[$label] reachability mutations: the live half of the differential protocol"
  cp "$plug/$subject" "$art/reach-pristine.php"

  # id | spec | control | relation | force | expectation
  #
  # An ARRAY, and the loop below is `for`, not `while read`. The first version fed a two-line
  # string to `while IFS='|' read`, and `docker compose exec` — which run_export_gate calls
  # inside the loop body — READS STDIN. It consumed the rest of that heredoc on the first
  # iteration, so M2 never ran, the loop exited with bad=0, and the lane logged success having
  # tested half of what it names. The verdict computation caught it (M2.live_evidence_present
  # was absent), which is the only reason this is a recorded pitfall rather than a shipped one.
  local specs=(
    "M1|M1-sever-call-chain.mutation|4|reachability|4|absent"
    "M2|M2-disconnect-exit.mutation|5|exit-effect|5|present-and-exit-0"
  )

  local entry id spec ctl rel force expect rc sha observed broken
  for entry in "${specs[@]}"; do
    IFS='|' read -r id spec ctl rel force expect <<< "$entry"
    [ -n "$id" ] || continue
    if [ ! -f "$reach/$spec" ]; then
      err "[$label] reachability $id: no spec at $reach/$spec"
      bad=1
      continue
    fi
    awk 'f{print} /^---$/{f=1}' "$reach/$spec" > "$art/reach-$id.diff"
    # The same two guards fp_live_mutations carries, and they were missing here. An empty or
    # no-op diff applies cleanly, `patch` exits 0, the forced run then produces no `[!!] n` line
    # because nothing was severed - and the lane would record relationship_broken:true for a
    # mutation that changed nothing.
    if [ ! -s "$art/reach-$id.diff" ]; then
      err "[$label] reachability $id: no diff body in $spec - a mutation that does not apply looks exactly like a gate that works"
      bad=1
      continue
    fi
    if ! patch -p1 -s -d "$plug" < "$art/reach-$id.diff" > "$art/reach-$id.patch.log" 2>&1; then
      err "[$label] reachability $id did not apply to the cell copy — the diff is stale"
      sed 's/^/      /' "$art/reach-$id.patch.log" | head -10
      cp "$art/reach-pristine.php" "$plug/$subject"
      bad=1
      continue
    fi
    if cmp -s "$art/reach-pristine.php" "$plug/$subject"; then
      err "[$label] reachability $id reported success and changed nothing"
      bad=1
      continue
    fi
    sha=$(shasum -a 256 "$plug/$subject" | awk '{print $1}')

    run_export_gate "$art" "reach-$id-forced-$force" "$force"
    rc=$?

    if [ "$expect" = "absent" ]; then
      if grep -q "^  \[!!\] $force " "$art/reach-$id-forced-$force.txt"; then
        observed="forcing control $force STILL produced a [!!] $force line — the call chain was not severed"
        broken=false
      elif [ "$rc" -ne 0 ]; then
        observed="the run exited $rc for some other reason, so nothing can be attributed to the severed control"
        broken=false
      else
        observed="forcing control $force produced no [!!] $force line and the run exited 0 — the call site does not execute"
        broken=true
      fi
    else
      if ! grep -q "^  \[!!\] $force " "$art/reach-$id-forced-$force.txt"; then
        observed="forcing control $force produced no [!!] $force line, so the control did not execute — this mutation was meant to leave it executing"
        broken=false
      elif [ "$rc" -ne 0 ]; then
        observed="the run exited $rc, so the control's failure still reaches the exit status"
        broken=false
      else
        observed="forcing control $force printed [!!] $force and the run still exited 0 — the control executed and its failure reached nothing"
        broken=true
      fi
    fi
    [ "$broken" = "true" ] || bad=1
    log "[$label]   $id (control $ctl, $rel): $observed"

    entries="$entries{\"id\":\"$id\",\"control\":$ctl,\"relation\":\"$rel\",\"forced\":$force,\"exit_code\":$rc,\"subject_sha256\":\"$sha\",\"observed\":\"$(printf '%s' "$observed" | sed 's/"/\\"/g')\",\"relationship_broken\":$broken},"

    cp "$art/reach-pristine.php" "$plug/$subject"
    cmp -s "$art/reach-pristine.php" "$plug/$subject" \
      || { err "[$label] reachability $id: restore was not byte-identical"; bad=1; }
  done

  printf '{"cell":"%s","at":"%s","baseline_sha256":"%s","mutations":[%s]}\n' \
    "$label" "$(date -u +%FT%TZ)" \
    "$(shasum -a 256 "$art/reach-pristine.php" | awk '{print $1}')" \
    "${entries%,}" > "$art/live-evidence.json"
  log "[$label]   live evidence written to $art/live-evidence.json"

  # The count is asserted out of band, because "the loop finished without setting bad" is exactly
  # what a loop whose input was eaten also reports. One entry per declared spec, or the lane FAILS.
  local wrote
  wrote=$(grep -o '"id":"M[0-9][0-9]*"' "$art/live-evidence.json" | wc -l | tr -d ' ')
  if [ "$wrote" != "${#specs[@]}" ]; then
    err "[$label] the reachability lane declared ${#specs[@]} mutations and recorded $wrote — a mutation that did not run is not a mutation that passed"
    bad=1
  fi

  [ "$bad" -eq 0 ]
}

fp_live_mutations() { # <label> <art>
  local label="$1" art="$2" entry id mutfile target pre_sql pre pre_raw prc rc bad=0 killed=0
  local plug; plug="$(cell_plugin_dir)"

  log "[$label] live value-path mutations: the equality must catch a real export defect"
  for entry in "${FP_MUTATIONS[@]}"; do
    IFS='|' read -r id mutfile target pre_sql <<< "$entry"

    # Status and output captured separately. Piping straight into `tr` discards the exit code,
    # so a precondition query that ERRORED and a corpus that genuinely holds 0 such rows produced
    # the identical diagnostic — in the one lane whose whole purpose is telling those two apart.
    pre_raw=$(dc exec -T db mysql -uroot -proot -N wordpress -e "$pre_sql" 2>"$art/pre-$id.err" < /dev/null)
    prc=$?
    pre=$(printf '%s' "$pre_raw" | tr -dc '0-9')
    if [ "$prc" -ne 0 ] || [ -z "$pre" ]; then
      err "[$label] mutation $id: the precondition QUERY failed (rc=$prc) — this says nothing about the corpus"
      err "[$label]   precondition was: $pre_sql"
      sed 's/^/      /' "$art/pre-$id.err" | head -5
      bad=1
      continue
    fi
    if [ "$pre" = "0" ]; then
      err "[$label] mutation $id: its precondition matched 0 rows in this corpus — the defect could not fire, so a green gate would prove nothing"
      err "[$label]   precondition was: $pre_sql"
      bad=1
      continue
    fi
    log "[$label]   $id: precondition satisfied by $pre rows"

    # The diff body is everything after the first line that is exactly '---'. Read from the
    # REPOSITORY copy, applied to the CELL copy: the mutation spec and the tree it mutates are
    # deliberately different files, so a botched restore cannot quietly rewrite the spec too.
    awk 'f{print} /^---$/{f=1}' "$PLUGIN_SRC/$mutfile" > "$art/mut-$id.diff"
    [ -s "$art/mut-$id.diff" ] || { err "[$label] mutation $id: no diff body in $mutfile"; bad=1; continue; }

    cp "$plug/$target" "$art/mut-$id.orig"
    if ! patch -p1 -s -d "$plug" < "$art/mut-$id.diff" > "$art/mut-$id.patch.log" 2>&1; then
      err "[$label] mutation $id did not apply — see mut-$id.patch.log"
      sed 's/^/      /' "$art/mut-$id.patch.log" | head -10
      cp "$art/mut-$id.orig" "$plug/$target"
      bad=1
      continue
    fi
    if cmp -s "$art/mut-$id.orig" "$plug/$target"; then
      err "[$label] mutation $id reported success but the file is unchanged, so nothing was tested"
      bad=1
      continue
    fi

    run_export_gate "$art" "fp-mut-$id"
    rc=$?
    # Restore FIRST, byte-identically, before judging: a judgement that exits early leaves the
    # defect in the cell for every later lane to inherit.
    cp "$art/mut-$id.orig" "$plug/$target"
    cmp -s "$art/mut-$id.orig" "$plug/$target" || { err "[$label] mutation $id: restore did not return the file byte-identical"; bad=1; }

    if [ "$rc" -eq 0 ]; then
      err "[$label] mutation $id SURVIVED — the live equality passed with the defect in place"
      sed 's/^/      /' "$art/fp-mut-$id.txt" | head -30
      bad=1
      continue
    fi
    if ! grep -q 'CHAINED HASH differs' "$art/fp-mut-$id.txt"; then
      err "[$label] mutation $id: the gate failed, but not on the chained hash — it may have failed for an unrelated reason"
      sed 's/^/      /' "$art/fp-mut-$id.txt" | head -30
      bad=1
      continue
    fi
    log "[$label]   $id: KILLED (exit $rc, chained hash moved) — $(grep -h 'so the drift is in' "$art/fp-mut-$id.txt" | head -1 | sed 's/^ *//')"
    killed=$((killed + 1))
    rm -f "$art/mut-$id.orig"
  done

  # The same out-of-band count its sibling lane carries, and for the same reason: "the loop
  # finished without setting bad" is exactly what a loop whose input was eaten also reports.
  if [ "$killed" != "${#FP_MUTATIONS[@]}" ]; then
    err "[$label] the value-path lane declared ${#FP_MUTATIONS[@]} mutations and killed $killed — a mutation that did not run is not a mutation that passed"
    bad=1
  fi

  [ "$bad" -eq 0 ]
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
    # seed-profile-verify.json, an overlay on the I8 profile whose every knob is off in the
    # base — so a caller that does not pass it seeds the corpus this script always seeded, byte
    # for byte. It is passed here for one reason: without it `wp_slim_events` is EMPTY, and an
    # empty table makes the export gate report slim_events UNPROVEN forever (a chain over zero
    # rows is sha256(spec) on both sides and equal by construction). Half of what this gate is
    # meant to certify would have been certified by a table nobody could ask a question of —
    # PITFALLS 44, arriving in the machinery built to rule it out. It also brings NULL ips and
    # outbound resources, which the 23-report comparison below can only be stronger for.
    log "[$label] seeding $ROWS rows over $DAYS days (I8 + verify overlay) — the ONE corpus"
    wpc eval-file wp-content/plugins/wp-slimstat/tests/bench/lib/seed.php \
       "$ROWS" "$DAYS" seed-profile-verify.json \
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

  # The fingerprint gates run BEFORE the reports are read, on every cell. Both are read-only
  # against the corpus (the CRC32 probe above and these two are the only things that have
  # touched it), and running them first means a cell whose ENCODING_V1 semantics differ is
  # rejected before 23 reports are compared on it.
  fp_gates "$label" "$art" || return 1

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

  # The two extra lanes run LAST, after the report comparison has its answers: both of them
  # perturb something (an env var, then the cell's own copy of the export writer), and a lane
  # that perturbs must never run upstream of the measurement it could contaminate.
  case " $FP_LANES_CELLS " in
    *" $label "*)
      fp_control_selftest      "$label" "$art" || { err "[$label] the control self-test failed — the export gate's controls are not all wired"; return 1; }
      fp_live_mutations        "$label" "$art" || { err "[$label] a live value-path mutation was not killed by the export gate"; return 1; }
      fp_reachability_mutations "$label" "$art" || { err "[$label] a reachability mutation did not break the relationship it was pre-declared to break"; return 1; }
      # The cell's plugin copy must be exactly what it was before the lanes ran, or every later
      # assertion in this cell is about a tree nobody described. Both the bench lib (the live
      # value-path mutations' target) and the gate itself (the reachability mutations' target).
      diff -q "$PLUGIN_SRC/tests/docker/verify-export-fingerprint.php" "$(cell_plugin_dir)/tests/docker/verify-export-fingerprint.php" >> "$art/mut-restore.txt" 2>&1
      diff -r -q "$PLUGIN_SRC/tests/bench/lib" "$(cell_plugin_dir)/tests/bench/lib" >> "$art/mut-restore.txt" 2>&1
      [ -s "$art/mut-restore.txt" ] \
        && { err "[$label] after the mutation lanes the cell's copy differs from the repository"; sed 's/^/      /' "$art/mut-restore.txt"; return 1; }
      log "[$label] mutation lanes restored: the cell's gate and bench lib are byte-identical to the repository"
      ;;
    *) log "[$label] control self-test and live mutations: not run on this cell (FP_LANES_CELLS=$FP_LANES_CELLS)" ;;
  esac

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
