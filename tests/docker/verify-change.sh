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

# THE CORPUS, chosen HERE rather than inherited from compare-answers.sh's default.
#
# compare-answers.sh defaults to seed-profile-i8.json so that every run ever recorded against it
# keeps meaning what it meant, and its header says "the campaign passes seed-profile-verify.json".
# No caller did. R20260824-a51bf2 therefore seeded I8, and `get_recent_events`, `get_top_events`
# and `get_top_outbound` were EMPTY ON BOTH ARMS — three surfaces comparing equal about a question
# neither arm was asked. The verify overlay is what turns events, outbound links, searchterms,
# `loggedin:` notes and NULL ips on, and its NULL-ip rate is the only thing that makes register
# entry R16 falsifiable rather than merely predicted.
#
# Printed in the dry-run line below so this is a fact a gate can read, not a variable a reviewer
# has to trust: SLIMSTAT_SEAL_DRYRUN=1 names the corpus without booting a container.
: "${SLIMSTAT_SEED_PROFILE:=seed-profile-verify.json}"

# The comparison driver, overridable so the negative suite can drive this script's own abort
# handling without a four-minute container boot.
#
# It is the most dangerous seam in this file and it is stated plainly rather than reassured
# about: it replaces the entire measurement with an arbitrary program, and the run still
# produces a sealed, manifest-signed, nine-control-PASS packet. T11b asserts exactly that. So
# the guard is not a promise in this comment — it is the dry-run line below naming the driver,
# and the seal gate refusing any run that reports a substituted one.
: "${SLIMSTAT_COMPARE_CMD:=$HERE/compare-answers.sh}"

# Resolved to concrete SHAs up front. A ref like HEAD means something different once a commit
# lands mid-run, and a run that cannot name what it compared is not evidence.
BEFORE_SHA=$(git -C "$PLUGIN_SRC" rev-parse "$BEFORE" 2>/dev/null) || { err "unknown ref: $BEFORE"; exit 1; }
AFTER_SHA=$(git -C "$PLUGIN_SRC" rev-parse "$AFTER" 2>/dev/null)  || { err "unknown ref: $AFTER"; exit 1; }

RUNS_ROOT="$SLIMSTAT_RUNS_ROOT"
[ -n "$RUNS_ROOT" ] || RUNS_ROOT="$PLUGIN_SRC/../jaan-to/outputs/dev/v6-performance/runs"
RUNS=$(seal_new_run_dir "$RUNS_ROOT") || exit 3
seal_flip "$RUNS" "$BEFORE_SHA" "$AFTER_SHA" "$ROWS" "$DAYS" "$SLIMSTAT_NULL_CONTROL" || exit 3
if [ "$SLIMSTAT_SEAL_DRYRUN" = 1 ]; then
  echo "SEAL DRYRUN: $RUNS  corpus=$SLIMSTAT_SEED_PROFILE  driver=$(basename "$SLIMSTAT_COMPARE_CMD")"
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
SLIMSTAT_SEED_PROFILE="$SLIMSTAT_SEED_PROFILE" \
  "$SLIMSTAT_COMPARE_CMD" "$BEFORE_SHA" "$AFTER_SHA" "$ROWS" "$DAYS" \
  "${SLIMSTAT_HTTP_PORT:-18990}" "${SLIMSTAT_DB_PORT:-13990}" 2>&1 | tee "$OUT"
rc=${PIPESTATUS[0]}
chmod 600 "$OUT" || exit 3
seal_assert_private "$OUT" 600 || exit 3

# ── build the scrubbed packet from the directional capture ──────────────────
# ONLY from a comparison that reached a verdict. rc 0 is IDENTICAL and rc 2 is DIFFERENCES —
# both are answers. Anything else is an abort, and the captures on disk are then from a run the
# harness has just refused: compare-answers.sh writes before.json/after.json before it evaluates
# a single control, so on a vacuity abort $ART is fully populated and self-consistent. Building
# from it produced a sealed, manifest-signed, control-passing packet for a run that said ABORTED,
# and nothing downstream could tell the difference — seal-tool.py validates the packet, not the
# verdict that produced it.
ART="$WORK_ROOT/answers/answers/artifacts"
if [ "$rc" != 0 ] && [ "$rc" != 2 ]; then
  err "no packet built — the comparison aborted (exit $rc), so its captures are not evidence"
elif [ -d "$ART" ]; then
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
