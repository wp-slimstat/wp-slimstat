#!/usr/bin/env bash
# Run the fp-negative suite on the shell CI actually uses — PITFALLS 93 rule 2.
#
# `composer test:fp-negative` runs on the host. On macOS that is bash, and the one property
# in this suite that depends on the shell — CONTROL 3's "the raw answer reached the detail
# line" — is worded differently by bash and dash. A green host run therefore says nothing
# about the six Linux lanes. This wrapper runs the identical suite under dash.
#
# It builds its own image (tests/fp-negative/Dockerfile.linux) rather than reusing one of the
# ss*-wp images, because those are docker-compose artefacts of a rehearsal that a clean clone
# has never run: naming one here would make this gate unrunnable on a fresh checkout.
#
# Usage: tests/fp-negative/run-linux.sh [scenario ...]
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
IMAGE="slimstat-fp-negative-linux"

if ! command -v docker >/dev/null 2>&1; then
    echo "REFUSED: docker is not on PATH, and this gate is only meaningful in a Linux container." >&2
    echo "  Its whole purpose is to run the suite under dash rather than the host's bash." >&2
    echo "  A skip here would be indistinguishable from a pass, which is the defect it exists for." >&2
    exit 2
fi

# Quiet build; cached after the first run. Failure here is fatal on purpose — the image's own
# RUN asserts python3 and a dash /bin/sh, so a build that cannot satisfy them must not degrade
# into running the suite somewhere the verdict would not mean anything.
docker build -q -t "$IMAGE" -f "$HERE/Dockerfile.linux" "$HERE" >/dev/null

# The mount is READ-WRITE, and that is not a convenience. Measured: with :ro the suite dies in
# fake-server.php:102 — it builds its SQLite corpus inside the checkout, and the mutate_lib /
# mutate_subject scenarios rewrite tests/docker/verify-export-fingerprint.php in place and
# restore it. So this wrapper carries the same standing hazard as the host run (PITFALLS 67):
# never run it concurrently with tests/docker/reachability/, and read the closing
# "subject unchanged across the sweep" line, which is the suite's own restoration proof.
exec docker run --rm -v "$ROOT:/w" -w /w "$IMAGE" \
    php tests/fp-negative/run-negatives.php "$@"
