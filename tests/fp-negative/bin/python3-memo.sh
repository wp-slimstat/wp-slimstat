#!/bin/sh
# A python3 that answers the same argv with the answer it gave the first time.
#
# This is the world CONTROL 2 exists to refuse: two reads happen, both are logged, both return a
# fingerprint, and the second one is not a read at all. It is what a result cache, a memoising
# wrapper, or a reader that keyed on a path instead of on the bytes would do — the failure is
# invisible in every artifact except the one the control produces.
#
# Needs SLIMSTAT_REAL_PYTHON3 (absolute) and SLIMSTAT_SHIM_CACHE (an existing directory).
set -u

key=$(printf '%s\036' "$@" | shasum -a 256 | cut -d' ' -f1)
out="${SLIMSTAT_SHIM_CACHE}/${key}.out"
rc="${SLIMSTAT_SHIM_CACHE}/${key}.rc"

if [ -f "$rc" ]; then
    cat "$out"
    exit "$(cat "$rc")"
fi

"$SLIMSTAT_REAL_PYTHON3" "$@" > "$out" 2>&1
printf '%s\n' "$?" > "$rc"
cat "$out"
exit "$(cat "$rc")"
