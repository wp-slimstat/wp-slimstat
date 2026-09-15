#!/usr/bin/env bash
# tests/docker/reachability/run-gate.sh — the differential reachability protocol, driven.
#
# The question. verify-export-fingerprint.php prints a block of controls and claims each one can
# fail the run on its own. A 50KB file is exactly where that claim goes wrong quietly: a control
# can be present, correct, commented, counted — and never reached, or reached and wired to
# nothing. Reading the file cannot settle it, because reading is what produced every previous
# version of that mistake here (PITFALLS 31, 61, 63, 64).
#
# The protocol. Two ANALYSERS answer the question — a deterministic one (analyse-controls.php,
# which follows the token stream) and an LLM one (analyse-brief.md, run in an isolated agent).
# Neither is trusted. Both are put through two PRE-DECLARED differential mutations:
#
#   M1  severs one control's top-level call chain    → the analysis must name that control
#                                                       and call it UNREACHABLE
#   M2  leaves a control executing but routes it      → the analysis must name that control
#       through a renderer that records nothing         and call it EXIT-INEFFECTIVE
#       the terminal exit is guarded on
#
# and a detection counts ONLY if it names the pre-declared control and the pre-declared defect.
# A generic error, a refusal, a timeout, an unrelated finding, or a finding that was already true
# of the baseline is a MISS. A miss REFUSES the wire: the verdict is `wired: false` with an exact
# `refused_because`, and the gate does not go into run-rollup-floor.sh on the strength of an
# analysis that could not tell the difference.
#
# What this script does NOT do is decide. It applies, measures, restores, and writes atoms;
# compute-verdict.php recomputes `wired` from those atoms. An agent-authored summary boolean is
# the one input this protocol must not accept, because it is the thing under test.
#
# Restoration is checked by DIGEST, not by `git checkout`: the subject may be untracked while it
# is being written, and a restore that silently did nothing looks exactly like a clean one.
#
#   ./run-gate.sh baseline            record digest + static analysis of the untouched subject
#   ./run-gate.sh apply M1            apply a mutation, record digest + static analysis
#   ./run-gate.sh restore             restore from the pristine copy, verify byte-identical
#   ./run-gate.sh verdict             assemble every atom and recompute `wired`
#
# Between `apply` and `restore` is where an LLM analysis is run; drop its JSON into
# $OUT/llm-<state>.json and `verdict` will read it. The digest each analysis reports is what ties
# it to the state it analysed — an analysis whose digest does not match the state it claims is
# discarded, which is what stops a cached or stale reading from being counted as a detection.

set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$HERE/../../.." && pwd)"
SUBJECT="${SUBJECT:-$PLUGIN_ROOT/tests/docker/verify-export-fingerprint.php}"
OUT="${OUT:-/tmp/php-matrix/reachability}"
PRISTINE="$OUT/pristine.php"

mkdir -p "$OUT"

digest() { shasum -a 256 "$1" | awk '{print $1}'; }
now()    { date -u +%FT%TZ; }

die() { printf '\033[1;31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }
say() { printf '\033[1;34m[%s]\033[0m %s\n' "$(date +%H:%M:%S)" "$*"; }

# Static analysis of the CURRENT subject into $OUT/static-<state>.json. Never fails the script:
# under a mutation the analyser is SUPPOSED to exit 1, and treating that as an error would make
# the protocol unable to observe its own positive case.
analyse() { # <state>
  local state="$1"
  php "$HERE/analyse-controls.php" "$SUBJECT" --json > "$OUT/static-$state.json" 2>"$OUT/static-$state.err"
  local rc=$?
  printf '%s' "$rc" > "$OUT/static-$state.rc"
  say "static analysis [$state]: exit $rc, $(python3 -c "
import json,sys
try:
    d=json.load(open('$OUT/static-$state.json'))
    print('%s controls=%d reachable=%d exit_effective=%d verdict=%s' % (
        d['sha256'][:16], d['summary']['declared'], d['summary']['reachable'],
        d['summary']['exit_effective'], d['verdict']))
except Exception as e:
    print('UNREADABLE:', e)
")"
}

case "${1:-}" in

  baseline)
    [ -f "$SUBJECT" ] || die "no subject at $SUBJECT"
    rm -f "$OUT"/state-*.json "$OUT"/static-*.json "$OUT"/static-*.rc "$OUT"/static-*.err "$OUT"/llm-*.json
    cp "$SUBJECT" "$PRISTINE"
    cat > "$OUT/state-baseline.json" <<JSON
{
  "state": "baseline",
  "at": "$(now)",
  "subject": "$(python3 -c "import os,sys;print(os.path.relpath('$SUBJECT','$PLUGIN_ROOT'))")",
  "sha256": "$(digest "$SUBJECT")",
  "bytes": $(wc -c < "$SUBJECT" | tr -d ' '),
  "git_revision": "$(git -C "$PLUGIN_ROOT" rev-parse HEAD 2>/dev/null || echo unknown)",
  "git_status_of_subject": "$(git -C "$PLUGIN_ROOT" status --porcelain -- "$SUBJECT" 2>/dev/null | head -1 | sed 's/"/\\"/g')",
  "php_lint": "$(php -l "$SUBJECT" 2>&1 | head -1 | sed 's/"/\\"/g')"
}
JSON
    analyse baseline
    say "baseline recorded: $(digest "$SUBJECT")"
    ;;

  apply)
    id="${2:?usage: run-gate.sh apply <M1|M2>}"
    # A glob, not `ls $var` — this harness lives under a path with a space in it, and the word
    # splitting that costs is the kind that reports "no such mutation" for a file sitting there.
    spec=""
    for candidate in "$HERE/$id"*.mutation; do
      [ -f "$candidate" ] && spec="$candidate" && break
    done
    [ -n "$spec" ] || die "no mutation spec matching $id in $HERE"
    [ -f "$PRISTINE" ] || die "no pristine copy — run `baseline` first"
    cmp -s "$PRISTINE" "$SUBJECT" || die "the subject is not in its baseline state; run restore first"

    awk 'f{print} /^---$/{f=1}' "$spec" > "$OUT/$id.diff"
    [ -s "$OUT/$id.diff" ] || die "no diff body in $spec"

    if ! patch -p1 -s -d "$PLUGIN_ROOT" < "$OUT/$id.diff" > "$OUT/$id.patch.log" 2>&1; then
      cat "$OUT/$id.patch.log" >&2
      # A failed patch may still have written a .rej or a partial file.
      cp "$PRISTINE" "$SUBJECT"
      die "$id did not apply — the diff is stale against this subject. Regenerate it; do NOT fuzz it in by hand"
    fi
    if cmp -s "$PRISTINE" "$SUBJECT"; then
      die "$id reported success and changed nothing — a mutation that does not apply looks exactly like a gate that works"
    fi

    control="$(sed -n 's/^control: *//p' "$spec" | head -1)"
    relation="$(sed -n 's/^relation: *//p' "$spec" | head -1)"
    cat > "$OUT/state-$id.json" <<JSON
{
  "state": "$id",
  "at": "$(now)",
  "spec": "$(basename "$spec")",
  "pre_declared_control": "$(printf '%s' "$control" | sed 's/"/\\"/g')",
  "pre_declared_relation": "$(printf '%s' "$relation" | sed 's/"/\\"/g')",
  "sha256": "$(digest "$SUBJECT")",
  "baseline_sha256": "$(digest "$PRISTINE")",
  "digest_changed": $(cmp -s "$PRISTINE" "$SUBJECT" && echo false || echo true),
  "php_lint": "$(php -l "$SUBJECT" 2>&1 | head -1 | sed 's/"/\\"/g')"
}
JSON
    analyse "$id"
    say "$id applied: $(digest "$SUBJECT") — control '$control', relation '$relation'"
    ;;

  restore)
    id="${2:-adhoc}"
    [ -f "$PRISTINE" ] || die "no pristine copy to restore from"
    cp "$PRISTINE" "$SUBJECT"
    rm -f "$SUBJECT.orig" "$SUBJECT.rej"
    a="$(digest "$PRISTINE")"; b="$(digest "$SUBJECT")"
    cat > "$OUT/restore-$id.json" <<JSON
{
  "state": "restore-$id",
  "at": "$(now)",
  "baseline_sha256": "$a",
  "restored_sha256": "$b",
  "byte_identical": $([ "$a" = "$b" ] && echo true || echo false)
}
JSON
    [ "$a" = "$b" ] || die "restore did not return the subject byte-identical ($a != $b)"
    say "restored byte-identical: $b"
    ;;

  verdict)
    php "$HERE/compute-verdict.php" "$OUT" | tee "$OUT/reachability-verdict.txt"
    exit "${PIPESTATUS[0]}"
    ;;

  *)
    sed -n '2,50p' "$0"
    exit 2
    ;;
esac
