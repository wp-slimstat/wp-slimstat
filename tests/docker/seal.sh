#!/usr/bin/env bash
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
source "$HERE/lib.sh"
source "$HERE/seal-lib.sh"

unseal() {
  local run="$1"
  # Success deliberately makes mapping.json public, so the idempotency marker must be checked
  # before P1's private-mode assertion on a repeated invocation.
  [ ! -f "$run/unseal.json" ] ||
    { seal_refuse 7 "this run was already unsealed at $(seal_json_get "$run/unseal.json" unsealed_at)"; return 4; }
  [ -f "$run/.sealed/mapping.json" ] || { seal_refuse 1 ".sealed/mapping.json is absent"; return 4; }
  seal_assert_private "$run/.sealed" 700 >/dev/null 2>&1 ||
    { seal_refuse 1 ".sealed is not private to the invoking uid"; return 4; }
  seal_assert_private "$run/.sealed/mapping.json" 600 >/dev/null 2>&1 ||
    { seal_refuse 1 ".sealed/mapping.json is not private to the invoking uid"; return 4; }
  [ -f "$run/packet/MANIFEST.sha256" ] || { seal_refuse 3 "packet/MANIFEST.sha256 is absent"; return 4; }
  # The SAME audit build-packet.py ran, with the same literals, or this check is a weaker rule
  # wearing the stronger one's name — and a leak planted after the build is precisely what it
  # exists to catch. seal_assert_private above has already proven .sealed is ours to read.
  # The name rule lives in seal-lib.sh and is the stronger of the two in the tree: it resolves
  # candidate hex tokens against the real repositories rather than rejecting anything that looks
  # like hexadecimal, which is why it neither fires on a Unix timestamp nor misses a full SHA.
  # build-packet.py runs it at build time as the `names_neutral` control; without it here, a
  # directory renamed after the build — the exact window this whole block exists to cover — was
  # checked only by scrub-audit's coarser path scan.
  PLUGIN_SRC="$PLUGIN_SRC" seal_assert_neutral_names "$run" >/dev/null 2>&1 ||
    { seal_refuse 3 "packet names stopped being neutral after the packet was built"; return 4; }
  # REFUSED when absent, not fallen back on. Every packet build writes this file, so a run
  # without one is a run whose provenance is unknown — and the fallback made that state
  # indistinguishable from a good one while quietly running the weaker rule. T14 catches a
  # WEAKENED rule; nothing caught a MISSING file, which is the same shape one step further out.
  [ -f "$run/.sealed/literals.json" ] ||
    { seal_refuse 3 ".sealed/literals.json is absent, so the packet cannot be re-audited against this run's own era markers"; return 4; }
  "$HERE/scrub-audit.sh" "$run/packet" --literals "$run/.sealed/literals.json" >/dev/null 2>&1 ||
    { seal_refuse 3 "scrub audit found hits in packet/ after the packet was built"; return 4; }
  (cd "$run" && shasum -a 256 -c packet/MANIFEST.sha256 >/dev/null 2>&1) ||
    { seal_refuse 3 "packet manifest does not verify"; return 4; }
  python3 "$HERE/seal-tool.py" validate "$run" || return 4
  python3 "$HERE/seal-tool.py" reveal "$run"
}

selftest() {
  local fair=00101101001110100101100110100110110010110010101101
  python3 "$HERE/seal-tool.py" fairness "$fair" >/dev/null ||
    { seal_err "SEAL SELFTEST: fair fixture rejected"; return 6; }
  python3 "$HERE/seal-tool.py" fairness 00000000000000000000000000000000000000000000000000 >/dev/null 2>&1 &&
    { seal_err "SEAL SELFTEST: stuck fixture did not fail"; return 6; }
  python3 "$HERE/seal-tool.py" fairness 01010101010101010101010101010101010101010101010101 >/dev/null 2>&1 &&
    { seal_err "SEAL SELFTEST: alternating fixture did not fail"; return 6; }
  echo "PASS: seal selftest — fair control accepted; stuck and alternating fixtures refused"
}

case "$1" in
  flip)
    root="$2"; shift 2; run=$(seal_new_run_dir "$root") || exit 3
    seal_flip "$run" "$1" "$2" "$3" "$4" "$5" || exit 3; echo "$run"
    ;;
  --unseal) unseal "$2" || exit $? ;;
  audit-flips)
    shift; bits=
    for run in "$@"; do
      if [ "$(seal_json_get "$run/.sealed/mapping.json" arm-1)" = "$(seal_json_get "$run/.sealed/mapping.json" ref_a)" ]; then
        bits="$bits""0"
      else
        bits="$bits""1"
      fi
    done
    python3 "$HERE/seal-tool.py" fairness "$bits"
    ;;
  --fairness) python3 "$HERE/seal-tool.py" fairness "$2" ;;
  --selftest) selftest ;;
  *) echo "usage: seal.sh flip <root> <ref-a> <ref-b> <rows> <days> <null> | --unseal <run> | audit-flips <runs...> | --selftest" >&2; exit 2 ;;
esac
