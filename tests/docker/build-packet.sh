#!/usr/bin/env bash
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
source "$HERE/lib.sh"
source "$HERE/seal-lib.sh"
run="$1"; artifacts="$2"; ref_a="$3"; ref_b="$4"
python3 "$HERE/build-packet.py" "$run" "$artifacts" "$ref_a" "$ref_b" || exit $?
echo "PASS: blind packet built and scrubbed at $run/packet"
