#!/usr/bin/env bash
# tests/docker/canary/run-canary.sh — S7, the canary drill, driven.
#
# The question. Run 58 proved the blind seal RUNS. It proved nothing about whether blind
# adjudication WORKS, because nothing was hidden in that packet for it to find, and an
# adjudication protocol that has never been shown a defect is an unfalsified procedure.
# The subject, the defect and what counts as a catch are pre-declared in CANARY-C1.md.
#
# The protocol is reachability/run-gate.sh's, one layer up: pre-declared and digest-pinned before
# anything runs; the poison goes into an ARM WORKTREE, never onto a branch; restoration checked by
# DIGEST, because a restore that silently did nothing looks exactly like a clean one; and this
# script does NOT decide — it applies, measures, restores and writes atoms, and
# compute-canary-verdict.py recomputes the verdict from them. An agent-authored summary boolean is
# the one input this protocol must not accept, because it is the thing under test.
#
# Why an arm worktree rather than a commit. verify-change.sh's arms ARE git worktrees keyed by
# ref, and compare-answers.sh reuses one that already carries a composer.json rather than
# recreating it. So a worktree prepared here IS the arm that runs, the sealed entry point is
# driven unmodified with no bypass, and nothing deliberately broken enters any history. The cost
# is stated rather than hidden: the run's record names that arm by a ref whose tree it no longer
# exactly is. compare-answers.sh now prints each arm's ACTUAL tree state beside the ref for
# exactly this reason, and the arm's `_arm_fingerprint` moves, which is the harness's own
# cross-check firing on purpose.
#
#   ./run-canary.sh drill <before> <after> [id] [rows] [days]   the whole thing, restore by trap
#   ./run-canary.sh verdict <sealed-run> [drill]   recompute the verdict from the atoms
#
# and the individual steps, for recovery and for driving the negative cases by hand:
#
#   ./run-canary.sh predeclare [id]        pin the criteria and the patch, before anything runs
#   ./run-canary.sh baseline   [id]        assert the arm worktree is pristine at its ref; digest it
#   ./run-canary.sh apply      [id]        apply the canary; assert the digest MOVED; php -l
#   ./run-canary.sh restore    [id]        restore from the pristine copy; verify byte-identical
#
# `drill` is the supported path and the reason is worth stating: with apply and restore as
# separate hand-ordered verbs, anything dying between them leaves the arm worktree POISONED — and
# compare-answers.sh reuses it on sight, so the next ordinary campaign run would silently measure
# the canary build. `baseline` refuses a dirty arm, but `baseline` only runs at the start of a
# drill, never at the start of a normal run. `drill` closes that window with an EXIT trap and, in
# the same move, derives the poisoned arm from the very ref it hands to verify-change.sh, so
# "poison arm A, compare B against C" stops being expressible.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HERE/../lib.sh"   # log/warn/err/die/digest/now/arm_worktree_dir

RUNS="${SLIMSTAT_RUNS_ROOT:-$PLUGIN_SRC/../jaan-to/outputs/dev/v6-performance/runs}"

# Everything below is derived from the canary id, so a second canary cannot land on the first
# one's atoms. Under failure protocol 3 the record of a FAILED drill is the most consequential
# artifact this programme can hold; overwriting it by forgetting an env var is not an acceptable
# way to lose it. The Run-numbered evidence directory a Run record cites is passed via OUT.
ID_DEFAULT=C1
spec_for() { # <id> -> path, via a glob because this harness lives under a path with a space in it
  local candidate
  for candidate in "$HERE/$1"-*.canary; do
    [ -f "$candidate" ] && { printf '%s' "$candidate"; return 0; }
  done
  return 1
}
field()  { sed -n "s/^$2: *//p" "$1" | head -1; }   # first value of a spec header

setup() { # <id> — resolves SPEC/OUT/REL and, if an arm ref is known, ARM/SUBJECT
  ID="${1:-$ID_DEFAULT}"
  SPEC="$(spec_for "$ID")" || die "no canary spec matching $ID in $HERE"
  OUT="${OUT:-$RUNS/canary-drill-$ID}"
  PRISTINE="$OUT/pristine.php"
  # From the SPEC, not from a constant here. CANARY-C1.md's failure protocol says the retry uses a
  # DIFFERENT patch, and the next one may well touch a different file; a hardcoded subject path
  # means that retry needs a source edit to this script.
  REL="$(field "$SPEC" file)"
  [ -n "$REL" ] || die "$SPEC declares no 'file:' header — the subject path is not derivable"
  ARM_REF="${SLIMSTAT_CANARY_ARM_REF:-${ARM_REF:-}}"
  if [ -n "$ARM_REF" ]; then
    ARM="$(arm_worktree_dir "$ARM_REF")"
    SUBJECT="$ARM/$REL"
  fi
  mkdir -p "$OUT"
}

need_arm() { [ -n "${ARM:-}" ] || die "no arm ref — pass it as SLIMSTAT_CANARY_ARM_REF, or use \`drill <before> <after>\`"; }

do_predeclare() {
  # BEFORE anything is measured. compute-canary-verdict.py re-derives both digests and refuses a
  # verdict if either moved, so criteria cannot be edited into agreement with the reports.
  local criteria="$HERE/CANARY-C1.md"
  [ -f "$criteria" ] || die "no $criteria — the pre-declaration must exist before the run"
  [ ! -f "$OUT/canary-verdict.json" ] || die "$OUT already holds a COMPLETED drill; \
point OUT elsewhere rather than erasing it — under failure protocol 3 a failed drill's atoms are the record"
  cat > "$OUT/predeclaration.json" <<JSON
{
  "at": "$(now)",
  "canary_id": "$ID",
  "subject_report": "$(field "$SPEC" subject_report)",
  "relation": "$(field "$SPEC" relation)",
  "subject_file": "$REL",
  "criteria_file": "tests/docker/canary/CANARY-C1.md",
  "criteria_sha256": "$(digest "$criteria")",
  "patch_file": "tests/docker/canary/$(basename "$SPEC")",
  "patch_sha256": "$(digest "$SPEC")",
  "baseline_packet_run": "$(grep -oE 'R[0-9]{8}-[0-9a-f]{6}' "$criteria" | head -1)"
}
JSON
  # baseline_packet_run is the DENOMINATOR of canary_observable: observable() subtracts that run's
  # difference set from this one's. Read out of the pinned criteria rather than written here as a
  # shell literal, because a literal here is a control input that escapes the pin — re-running
  # predeclare with a different baseline leaves both sha256 fields unchanged, so
  # predeclaration_pinned still PASSes while the control has been made easier.
  local base; base="$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["baseline_packet_run"])' "$OUT/predeclaration.json")"
  [ -n "$base" ] || die "could not read the baseline packet run out of CANARY-C1.md — the control has no denominator"
  log "pre-declaration pinned: $(digest "$criteria")  (baseline packet $base)"
}

do_baseline() {
  need_arm
  [ -d "$ARM" ] || die "no arm worktree at $ARM — run verify-change.sh once, or git worktree add it"
  # Pristine AT ITS REF, not merely "not dirty right now": a worktree left patched by an
  # interrupted run is exactly the state this gate is blind to if it is trusted as a baseline.
  local head_sha; head_sha="$(git -C "$ARM" rev-parse HEAD 2>/dev/null)"
  [ "$head_sha" = "$ARM_REF" ] || die "arm worktree is at $head_sha, expected $ARM_REF"
  local dirty; dirty="$(git -C "$ARM" status --porcelain --untracked-files=no)"
  [ -z "$dirty" ] || die "arm worktree is not clean at its ref; refusing to baseline a modified arm:
$dirty"
  rm -f "$OUT"/state-*.json "$OUT"/restore-*.json
  cp "$SUBJECT" "$PRISTINE"
  cat > "$OUT/state-baseline.json" <<JSON
{
  "state": "baseline",
  "at": "$(now)",
  "canary_id": "$ID",
  "arm_ref": "$ARM_REF",
  "arm_worktree": "$ARM",
  "subject": "$REL",
  "sha256": "$(digest "$SUBJECT")",
  "bytes": $(wc -c < "$SUBJECT" | tr -d ' ')
}
JSON
  log "baseline recorded: $(digest "$SUBJECT")"
}

do_apply() {
  need_arm
  [ -f "$PRISTINE" ] || die "no pristine copy — run 'baseline' first"
  cmp -s "$PRISTINE" "$SUBJECT" || die "the subject is not in its baseline state; run restore first"

  awk 'f{print} /^---$/{f=1}' "$SPEC" > "$OUT/$ID.diff"
  [ -s "$OUT/$ID.diff" ] || die "no diff body in $SPEC"

  if ! patch -p1 -s -d "$ARM" < "$OUT/$ID.diff" > "$OUT/$ID.patch.log" 2>&1; then
    cat "$OUT/$ID.patch.log" >&2
    cp "$PRISTINE" "$SUBJECT"
    die "$ID did not apply — the diff is stale against this arm. Regenerate it; do NOT fuzz it in by hand"
  fi
  local after; after="$(digest "$SUBJECT")"
  [ "$after" != "$(digest "$PRISTINE")" ] \
    || die "$ID reported success and changed nothing — a canary that does not apply looks exactly like a gate that works"
  local lint; lint="$(php -l "$SUBJECT" 2>&1 | head -1)"
  case "$lint" in *"No syntax errors"*) ;; *) cp "$PRISTINE" "$SUBJECT"; die "$ID does not lint: $lint" ;; esac

  cat > "$OUT/state-$ID.json" <<JSON
{
  "state": "$ID",
  "at": "$(now)",
  "spec": "$(basename "$SPEC")",
  "baseline_sha256": "$(digest "$PRISTINE")",
  "sha256": "$after",
  "php_lint": "$(printf '%s' "$lint" | sed 's/"/\\"/g')"
}
JSON
  log "$ID applied to arm $ARM_REF: $after"
}

do_restore() {
  need_arm
  [ -f "$PRISTINE" ] || die "no pristine copy to restore from"
  cp "$PRISTINE" "$SUBJECT"
  rm -f "$SUBJECT.orig" "$SUBJECT.rej"
  local a b dirty
  a="$(digest "$PRISTINE")"; b="$(digest "$SUBJECT")"
  # git's opinion as well as the digest: they answer different questions, and a subject restored
  # byte-identically inside a worktree left dirty elsewhere is still a poisoned arm — which
  # compare-answers.sh would reuse on sight for the next ordinary run.
  dirty="$(git -C "$ARM" status --porcelain --untracked-files=no | tr '\n' ';')"
  cat > "$OUT/restore-$ID.json" <<JSON
{
  "state": "restore-$ID",
  "at": "$(now)",
  "baseline_sha256": "$a",
  "restored_sha256": "$b",
  "byte_identical": $([ "$a" = "$b" ] && echo true || echo false),
  "worktree_dirty_after_restore": "$(printf '%s' "$dirty" | sed 's/"/\\"/g')"
}
JSON
  # RETURNS rather than dies: this runs from an EXIT trap, where `exit` would re-enter the trap.
  [ "$a" = "$b" ] || { err "restore did not return the subject byte-identical ($a != $b)"; return 1; }
  [ -z "$dirty" ] || { err "subject restored but the arm worktree is still dirty: $dirty"; return 1; }
  log "restored byte-identical, arm clean: $b"
}

case "${1:-}" in

  drill)
    before="${2:?usage: run-canary.sh drill <before-ref> <after-ref> [id]}"
    after="${3:?usage: run-canary.sh drill <before-ref> <after-ref> [id]}"
    # The poisoned arm IS the after ref, resolved once. This is the whole point of the verb: with
    # apply and restore driven by hand there was nothing tying SLIMSTAT_CANARY_ARM_REF to what the
    # operator then typed into verify-change.sh, and poisoning one arm while comparing two others
    # yields a clean packet, a failed observable control, and a verdict nobody should act on.
    ARM_REF="$(git -C "$PLUGIN_SRC" rev-parse "$after" 2>/dev/null)" || die "unknown ref: $after"
    setup "${4:-$ID_DEFAULT}"
    do_predeclare
    do_baseline
    do_apply
    # From here the arm is poisoned. Restore on ANY exit, including a failure inside
    # verify-change.sh or a Ctrl-C: an arm left poisoned is silently reused by the next run.
    trap 'rc=$?; trap - EXIT INT TERM; do_restore || { err "RESTORE FAILED — the arm at $ARM may still be POISONED, and compare-answers.sh reuses it on sight"; rc=9; }; exit $rc' EXIT INT TERM
    log "handing off to verify-change.sh (unmodified, no bypass) — $before -> $after"
    "$HERE/../verify-change.sh" "$before" "$after" "${5:-150000}" "${6:-180}"
    rc=$?
    trap - EXIT INT TERM
    do_restore || die "RESTORE FAILED — the arm at $ARM may still be POISONED, and compare-answers.sh reuses it on sight"
    # 0 (answers identical) and 2 (answers differ) are both verdicts; a canary run EXPECTS 2.
    # Anything else is an abort, and its captures are not evidence.
    case $rc in
      0|2)
        log "drill complete — verify-change exit $rc, which is an answer"
        log "Adjudicate the packet, file the reports, then:"
        log "  OUT=$OUT $0 verdict <sealed-run-dir>"
        ;;
      *)
        # No next steps, because there is nothing to adjudicate: verify-change.sh does not build a
        # packet from an aborted comparison, and printing "file the reports" here would send the
        # operator looking for one. Its captures are on disk and are not evidence.
        err "drill ABORTED — verify-change exit $rc; no packet was built and its captures are not evidence"
        err "  the arm has been restored; fix the cause and re-run the drill"
        ;;
    esac
    exit $rc
    ;;

  predeclare) setup "${2:-}"; do_predeclare ;;
  baseline)   setup "${2:-}"; do_baseline ;;
  apply)      setup "${2:-}"; do_apply ;;
  restore)    setup "${2:-}"; do_restore ;;

  verdict)
    run="${2:?usage: run-canary.sh verdict <sealed-run-dir> [drill-dir]}"
    setup "${4:-}"
    drill="${3:-$OUT}"
    python3 "$HERE/compute-canary-verdict.py" "$drill" "$run" "$HERE" | tee "$drill/canary-verdict.txt"
    exit "${PIPESTATUS[0]}"
    ;;

  *)
    sed -n '2,40p' "$0"
    exit 2
    ;;
esac
