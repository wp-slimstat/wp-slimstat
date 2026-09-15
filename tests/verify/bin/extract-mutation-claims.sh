#!/usr/bin/env bash
#
# Turn every prose "mutation" claim in the v6 records into a work list.
#
# WHY. Ten of the per-defect documents assert that a mutation was run and killed; none of
# those runs is reproducible, and this programme has already had a whole mutation run turn
# out to be false — three Processor.php mutations reported "KILLED by unit" while the
# runner was filtered to a test that does not touch Processor.php, so every gate returned
# non-zero regardless of the mutation. A claim in prose and a claim in a file that a runner
# can re-execute are not the same kind of evidence.
#
# This does not verify anything. It produces the BACKLOG: every place a mutation is
# claimed, so each can be re-expressed as tests/mutations/<id>.mutation and actually re-run.
# The plan's B2 is "re-run EVERY mutation claim across all documents"; this is how you find
# out how many that is.
#
# Usage: tests/verify/bin/extract-mutation-claims.sh [outputs-dir]

set -euo pipefail

PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"

# The records live beside the plugin in the primary checkout, but Lane I runs from a git
# worktree parked outside it, so a single hardcoded relative path is wrong half the time.
# Resolved rather than assumed; SLIMSTAT_V6_DOCS or an argument overrides.
DOCS="${1:-${SLIMSTAT_V6_DOCS:-}}"
if [ -z "${DOCS}" ]; then
    for candidate in \
        "${PLUGIN_ROOT}/../jaan-to/outputs/dev/v6-performance" \
        "$(git -C "${PLUGIN_ROOT}" rev-parse --show-toplevel 2>/dev/null)/../jaan-to/outputs/dev/v6-performance"
    do
        [ -d "${candidate}" ] && { DOCS="${candidate}"; break; }
    done
fi

[ -n "${DOCS}" ] && [ -d "${DOCS}" ] || {
    printf 'extract-mutation-claims: cannot locate the v6 records.\n' >&2
    printf '  pass the directory as an argument, or set SLIMSTAT_V6_DOCS.\n' >&2
    exit 1
}

printf 'CONTROLS\n'
doc_count=$(find "${DOCS}" -maxdepth 1 -name '*.md' | wc -l | tr -d ' ')
# Positive control: the corpus must be non-empty, or "0 claims" reads as "all verified"
# when it actually means "nothing was read".
if [ "${doc_count}" -gt 0 ]; then
    printf '  [PASS] %s document(s) in the corpus\n' "${doc_count}"
else
    printf '  [FAIL] corpus is empty — 0 claims would be indistinguishable from 0 documents\n'
    printf 'VERDICT: ABORTED\n'
    exit 1
fi

registered=$(find "${PLUGIN_ROOT}/tests/mutations" -name '*.mutation' 2>/dev/null | wc -l | tr -d ' ')
printf '  %s mutation(s) already registered as files\n\n' "${registered}"

printf 'Documents claiming a mutation was run:\n\n'

total=0
while IFS= read -r doc; do
    # -i because the documents write "Mutation", "mutation-tested", "mutations".
    hits=$(grep -ci 'mutation' "${doc}" || true)
    [ "${hits}" -gt 0 ] || continue
    total=$((total + hits))
    printf '  %-46s %3s mention(s)\n' "$(basename "${doc}")" "${hits}"
done < <(find "${DOCS}" -maxdepth 1 -name '*.md' | sort)

printf '\n%s prose mention(s) across the corpus · %s reproducible mutation file(s)\n' "${total}" "${registered}"
printf '\nEach claim that names a specific assertion should become tests/mutations/<id>.mutation\n'
printf 'so the runner can re-execute it. Until then it is an assertion about an assertion.\n'
printf 'VERDICT: REPORTED (backlog only — this verifies nothing)\n'
