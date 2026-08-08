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
# Everything is SEALED: artifacts are labelled arm-1 / arm-2 and the mapping goes to seal.json,
# so the adjudicating agents cannot know which arm holds the change or which direction is
# welcome. The person running this wrote the change; the adjudicator is the only party without a
# preferred answer.
#
#   verify-change.sh 52ffe631 HEAD               # normal use
#   SLIMSTAT_NULL_CONTROL=1 verify-change.sh HEAD HEAD   # noise floor of the harness itself
#
# Exit 0 answers identical · 2 answers differ (defect or an EXPECTED-DIFFS entry) · 1 aborted.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HERE/lib.sh"

BEFORE="${1:?before ref}"
AFTER="${2:?after ref}"
ROWS="${3:-150000}"
DAYS="${4:-180}"

# Resolved to concrete SHAs up front. A ref like HEAD means something different once a commit
# lands mid-run, and a run that cannot name what it compared is not evidence.
BEFORE_SHA=$(git -C "$PLUGIN_SRC" rev-parse --short "$BEFORE" 2>/dev/null) || { err "unknown ref: $BEFORE"; exit 1; }
AFTER_SHA=$(git -C "$PLUGIN_SRC" rev-parse --short "$AFTER" 2>/dev/null)  || { err "unknown ref: $AFTER"; exit 1; }

STAMP="${SLIMSTAT_RUN_LABEL:-${BEFORE_SHA}-to-${AFTER_SHA}}"
RUNS="$PLUGIN_SRC/../jaan-to/outputs/dev/v6-performance/runs/$STAMP"
mkdir -p "$RUNS"

log "verify-change: $BEFORE_SHA -> $AFTER_SHA  (${ROWS} rows over ${DAYS} days)"
[ "${SLIMSTAT_NULL_CONTROL:-0}" = "1" ] && warn "NULL CONTROL mode: both arms are the same code; any delta is the harness's noise floor"

# The dirty-tree refusal is the same one the mutation runner makes, for the same reason: an arm
# built from a worktree at a ref is not the tree you are looking at, and a difference between
# them is invisible in the output.
if [ -n "$(git -C "$PLUGIN_SRC" status --porcelain --untracked-files=no)" ]; then
  warn "the working tree has uncommitted changes — they are NOT in either arm"
  warn "arms are built from git worktrees at $BEFORE_SHA and $AFTER_SHA"
fi

OUT="$RUNS/verify.log"
SLIMSTAT_TIMING_REPS="${SLIMSTAT_TIMING_REPS:-5}" \
SLIMSTAT_BLOCKS="${SLIMSTAT_BLOCKS:-4}" \
  "$HERE/compare-answers.sh" "$BEFORE_SHA" "$AFTER_SHA" "$ROWS" "$DAYS" \
  "${SLIMSTAT_HTTP_PORT:-18990}" "${SLIMSTAT_DB_PORT:-13990}" 2>&1 | tee "$OUT"
rc=${PIPESTATUS[0]}

# ── seal, and collect the artifacts an adjudicator reads ────────────────────
ART="$WORK_ROOT/answers/answers/artifacts"
if [ -d "$ART" ]; then
  # Arms are relabelled here, not by their ref, so nothing an agent opens names a direction.
  cp "$ART/before.json"        "$RUNS/arm-1-answers.json" 2>/dev/null
  cp "$ART/after.json"         "$RUNS/arm-2-answers.json" 2>/dev/null
  cp "$ART/before-timing.json" "$RUNS/arm-1-cost.json"    2>/dev/null
  cp "$ART/after-timing.json"  "$RUNS/arm-2-cost.json"    2>/dev/null

  printf '{"arm-1":"%s","arm-2":"%s","rows":%s,"days":%s,"null_control":%s}\n' \
    "$BEFORE_SHA" "$AFTER_SHA" "$ROWS" "$DAYS" "${SLIMSTAT_NULL_CONTROL:-0}" > "$RUNS/seal.json"
fi

echo
case $rc in
  0) log "ANSWERS IDENTICAL. Artifacts: $RUNS" ;;
  2) err "ANSWERS DIFFER — each difference is a defect or an EXPECTED-DIFFS entry, never a shrug." ;;
  *) err "ABORTED — a control failed; the comparison would not mean what it says." ;;
esac

cat <<NOTE

  Adjudicate BEFORE opening the seal:

    arm extracts   $RUNS/arm-{1,2}-answers.json
                   $RUNS/arm-{1,2}-cost.json
    mapping        $RUNS/seal.json   <- do not read until the agents have reported

  One isolated agent per arm, told nothing about which arm it holds or what changed;
  a third compares their reports without the mapping. Then unseal.

NOTE

exit $rc
