#!/usr/bin/env bash
# tests/docker/lib.sh — shared helpers for the PHP×WP matrix harness.
# Sourced by run-cell.sh and run-matrix.sh.

# Resolve paths relative to this harness dir.
HARNESS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_SRC="$(cd "$HARNESS_DIR/../.." && pwd)"   # wp-slimstat repo root
WORK_ROOT="${WORK_ROOT:-/tmp/php-matrix}"
PRO_ZIP="${PRO_ZIP:-$HARNESS_DIR/build/wp-slimstat-pro.zip}"

# Signatures that mean WordPress *core* couldn't boot on this PHP (not a plugin bug).
WP_CORE_FATAL_PATTERNS='Parse error|Fatal error|Uncaught|requires PHP|unsupported|syntax error'
# True if $1 (a log file) contains a WP-core fatal signature.
has_wp_core_fatal() { grep -qiE "$WP_CORE_FATAL_PATTERNS" "$1" 2>/dev/null; }

log()  { printf '\033[1;34m[%s]\033[0m %s\n' "$(date +%H:%M:%S)" "$*"; }
warn() { printf '\033[1;33m[%s] WARN:\033[0m %s\n' "$(date +%H:%M:%S)" "$*"; }
err()  { printf '\033[1;31m[%s] ERROR:\033[0m %s\n' "$(date +%H:%M:%S)" "$*"; }

# Write a cell's verdict JSON. Args: art_dir cell php wp status reason
write_verdict() {
  local art="$1" cell="$2" php="$3" wp="$4" status="$5" reason="$6"
  reason="${reason//\"/\'}"
  printf '{"cell":"%s","php":"%s","wp":"%s","status":"%s","reason":"%s","ts":"%s"}\n' \
    "$cell" "$php" "$wp" "$status" "$reason" "$(date -u +%FT%TZ)" > "$art/cell.json"
}

# Wait until a command succeeds or times out. Args: tries sleep cmd...
wait_for() {
  local tries="$1" nap="$2"; shift 2
  local i
  for ((i=1; i<=tries; i++)); do "$@" >/dev/null 2>&1 && return 0; sleep "$nap"; done
  return 1
}
