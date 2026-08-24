#!/usr/bin/env bash
# tests/docker/verify-change.sh <before-ref> <after-ref> [rows] [days]
#
# THE single entry point for "did this change break anything, and what did it cost".
#
# One command, because the alternative is a procedure someone remembers — and twice this week a
# remembered procedure produced a confident wrong answer: a denominator scoped without its
# numerator, and an ~8% speedup that was a warm buffer pool.
#
# It runs three things that answer DIFFERENT questions, in an order that matters:
#
#   1. ANSWERS   — the gate. Same corpus, both arms, byte-for-byte diff. A change that halves
#                  the query count and shifts a total is worse than the code it replaced.
#   2. COUNTERS  — the claim. Handler_read_*, Created_tmp_disk_tables, Sort_rows. Deterministic:
#                  they do not vary with machine load, so they can carry a conclusion.
#   3. LATENCY   — context only, printed under the counters. Never a headline.
#
# The capture remains directional internally. seal-lib.sh randomises the packet labels, stores the
# mapping privately, and build-packet.sh removes every identity field before adjudication.
#
#   verify-change.sh 52ffe631 HEAD               # normal use
#   SLIMSTAT_NULL_CONTROL=1 verify-change.sh HEAD HEAD   # noise floor of the harness itself
#
# Exit 0 answers identical · 2 answers differ (defect or an EXPECTED-DIFFS entry) · 1 aborted.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HERE/lib.sh"
source "$HERE/seal-lib.sh"

BEFORE="${1:?before ref}"
AFTER="${2:?after ref}"
ROWS="${3:-150000}"
DAYS="${4:-180}"
: "${SLIMSTAT_RUNS_ROOT:=}"
: "${SLIMSTAT_NULL_CONTROL:=0}"
: "${SLIMSTAT_SEAL_DRYRUN:=0}"

# Resolved to concrete SHAs up front. A ref like HEAD means something different once a commit
# lands mid-run, and a run that cannot name what it compared is not evidence.
BEFORE_SHA=$(git -C "$PLUGIN_SRC" rev-parse "$BEFORE" 2>/dev/null) || { err "unknown ref: $BEFORE"; exit 1; }
AFTER_SHA=$(git -C "$PLUGIN_SRC" rev-parse "$AFTER" 2>/dev/null)  || { err "unknown ref: $AFTER"; exit 1; }

RUNS_ROOT="$SLIMSTAT_RUNS_ROOT"
[ -n "$RUNS_ROOT" ] || RUNS_ROOT="$PLUGIN_SRC/../jaan-to/outputs/dev/v6-performance/runs"
RUNS=$(seal_new_run_dir "$RUNS_ROOT") || exit 3
seal_flip "$RUNS" "$BEFORE_SHA" "$AFTER_SHA" "$ROWS" "$DAYS" "$SLIMSTAT_NULL_CONTROL" || exit 3
if [ "$SLIMSTAT_SEAL_DRYRUN" = 1 ]; then
  echo "SEAL DRYRUN: $RUNS"
  exit 0
fi

log "verify-change: sealed run $(basename "$RUNS")  ($ROWS rows over $DAYS days)"
[ "${SLIMSTAT_NULL_CONTROL:-0}" = "1" ] && warn "NULL CONTROL mode: both arms are the same code; any delta is the harness's noise floor"

# The dirty-tree refusal is the same one the mutation runner makes, for the same reason: an arm
# built from a worktree at a ref is not the tree you are looking at, and a difference between
# them is invisible in the output.
if [ -n "$(git -C "$PLUGIN_SRC" status --porcelain --untracked-files=no)" ]; then
  warn "the working tree has uncommitted changes — they are NOT in either arm"
  warn "arms are built from git worktrees at $BEFORE_SHA and $AFTER_SHA"
fi

# This transcript is directional by design: it names before/after and prints both values. Keep it
# behind the same private directory as the mapping so the packet cannot be unsealed by a sibling
# log (PITFALLS 77). The public record receives only the scrubbed packet and post-adjudication
# reveal.
OUT="$RUNS/.sealed/verify.log"
SLIMSTAT_TIMING_REPS="${SLIMSTAT_TIMING_REPS:-5}" \
SLIMSTAT_BLOCKS="${SLIMSTAT_BLOCKS:-4}" \
  "$HERE/compare-answers.sh" "$BEFORE_SHA" "$AFTER_SHA" "$ROWS" "$DAYS" \
  "${SLIMSTAT_HTTP_PORT:-18990}" "${SLIMSTAT_DB_PORT:-13990}" 2>&1 | tee "$OUT"
rc=${PIPESTATUS[0]}
chmod 600 "$OUT" || exit 3
seal_assert_private "$OUT" 600 || exit 3

# ── build the scrubbed packet from the directional capture ──────────────────
ART="$WORK_ROOT/answers/answers/artifacts"
if [ -d "$ART" ]; then
  "$HERE/build-packet.sh" "$RUNS" "$ART" "$BEFORE_SHA" "$AFTER_SHA" || exit $?
fi

echo
case $rc in
  0) log "ANSWERS IDENTICAL. Artifacts: $RUNS" ;;
  2) err "ANSWERS DIFFER — each difference is a defect or an EXPECTED-DIFFS entry, never a shrug." ;;
  *) err "ABORTED — a control failed; the comparison would not mean what it says." ;;
esac

cat <<NOTE

  Adjudicate only the scrubbed packet:

    arm extracts   $RUNS/packet/arm-{1,2}/answers.json
    contract       $RUNS/packet/contract.md
    mapping        $RUNS/.sealed/mapping.json   <- private until unseal

  One isolated agent per arm, told nothing about which arm it holds or what changed;
  a third compares their reports without the mapping. Then:

    tests/docker/seal.sh --unseal "$RUNS"

NOTE

exit $rc
