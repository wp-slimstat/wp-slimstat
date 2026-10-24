#!/usr/bin/env bash
#
# EXPLAIN gate — fails the build when a SlimStat report query performs a full
# table scan against a table large enough for it to matter.
#
# ci.yml reserved this path for months while the step itself was
# `echo "[TODO]…"; exit 0` — a gate that reported success while asserting
# nothing. This is the real implementation.
#
# This script is deliberately thin: it resolves the container-side plugin path
# and hands off to tests/perf/lib/explain-run.php, which does the seeding,
# capture, rendering and EXPLAIN in one WordPress process. Keeping the logic on
# the PHP side avoids shuttling files into the container and means the harness
# can be run directly against any WP install:
#
#     wp eval-file tests/perf/lib/explain-run.php 100000
#
# Env:
#   EXPLAIN_ROW_THRESHOLD  rows above which a scan is a failure (default 100000)
#   WP_ENV_CONTAINER       wp-env container name          (default "tests-cli")
#
# Exit: 0 all clear · 1 at least one offending plan · 2 harness could not run
#       (never silently passes — an unusable harness is a failure, not a pass)

set -euo pipefail

THRESHOLD="${EXPLAIN_ROW_THRESHOLD:-100000}"
CONTAINER="${WP_ENV_CONTAINER:-tests-cli}"

log() { printf '[explain-gate] %s\n' "$*"; }
die() { printf '[explain-gate] ERROR: %s\n' "$*" >&2; exit 2; }

command -v npx >/dev/null 2>&1 || die "npx not found — cannot reach wp-env"

# `wp-env run` takes the container as a positional (cli = development :8888,
# tests-cli = tests :8889) and needs `--` before the command. There is no
# --env flag; passing one silently makes the container positional empty.
# Same form as ci.yml's other wp-env calls.
wp() { npx wp-env run "$CONTAINER" -- wp "$@"; }

log "threshold: type=ALL is a failure on tables over ${THRESHOLD} rows"

PLUGIN_DIR="$(wp eval 'echo defined("SLIMSTAT_ANALYTICS_DIR") ? SLIMSTAT_ANALYTICS_DIR : "";' 2>/dev/null | tr -d '\r\n')"

if [ -z "$PLUGIN_DIR" ]; then
  die "could not resolve SLIMSTAT_ANALYTICS_DIR inside the '${CONTAINER}' container.
       Either wp-env is not running, WordPress is not installed, or the plugin
       is not active. Start it with:  npx wp-env start"
fi

log "plugin resolved at ${PLUGIN_DIR} (container)"

set +e
RESULT="$(wp eval-file "${PLUGIN_DIR}tests/perf/lib/explain-run.php" "$THRESHOLD" 2>&1)"
set -e

printf '%s\n' "$RESULT"

# explain-run.php prints a final VERDICT line. Key off that, not the exit code
# of `wp eval-file`, which is 0 even when the payload reports failures.
case "$RESULT" in
  *"VERDICT: FAIL"*)
    log "FAIL — at least one report query full-scans a table over ${THRESHOLD} rows"
    exit 1
    ;;
  *"VERDICT: PASS"*)
    log "PASS — no full table scans over ${THRESHOLD} rows"
    exit 0
    ;;
  *)
    die "capture run produced no verdict — refusing to report success"
    ;;
esac
