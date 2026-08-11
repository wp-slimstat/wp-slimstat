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

# Read a cell's verdict status back. Args: cell.json path
#
# The counterpart to write_verdict, and it lives here for the reason PITFALLS #5 gives: one
# writer with two independently-written readers is a disagreement waiting to happen, and it
# stays invisible until one of them produces a confident wrong answer. run-matrix.sh and
# run-topologies.sh both call this, so write_verdict's quoting can change without breaking one
# of them silently.
read_verdict_status() {
  [ -f "$1" ] || { printf 'MISSING'; return; }
  sed -n 's/.*"status":"\([^"]*\)".*/\1/p' "$1" | head -1
}

# ── Shared container bring-up ───────────────────────────────────────────────
# run-cell.sh (PHP×WP axis) and run-topology.sh (install-shape axis) ask different questions but
# stand up the identical stack to ask them. These four helpers are the part that was byte-for-byte
# duplicated; keeping them here means the image args, the debug constants and the rsync excludes
# have ONE owner and the two arms cannot drift.

# docker compose for the current COMPOSE_PROJECT_NAME. DC_EXTRA_FILE lets a cell
# overlay a second compose file (topology B/D add an external-db service) without
# every other cell paying for services it does not use.
dc() { docker compose -f "$HARNESS_DIR/docker-compose.yml" ${DC_EXTRA_FILE:+-f "$DC_EXTRA_FILE"} "$@"; }

# WP-CLI inside the cell's wp container.
wpc() { dc exec -T -u www-data wp wp --path=/var/www/html "$@"; }

# Build the image and bring the stack up, waiting for MySQL. Args: art_dir php
boot_stack() {
  local art="$1" php="$2"
  dc build --build-arg PHP_VERSION="$php" wp > "$art/build.log" 2>&1 || return 1
  dc up -d                                   > "$art/up.log"    2>&1 || return 2
  wait_for 40 3 dc exec -T db mysqladmin ping -h127.0.0.1 -uroot -proot --silent || return 3
}

# wp-config.php with the debug constants both arms rely on. Args: log_file
wp_config_debug() {
  local log="$1"
  wpc config create --dbname=wordpress --dbuser=root --dbpass=root --dbhost=db:3306 \
      --force --skip-check >>"$log" 2>&1
  wpc config set WP_DEBUG         true  --raw --type=constant >>"$log" 2>&1
  wpc config set WP_DEBUG_LOG     true  --raw --type=constant >>"$log" 2>&1
  wpc config set WP_DEBUG_DISPLAY false --raw --type=constant >>"$log" 2>&1
}

# Copy the working tree's free plugin into the cell. Args: wp_dir
sync_plugin_src() {
  # Args: wp_dir [src_dir=$PLUGIN_SRC]. The optional source is what lets a two-arm
  # measurement (measure-d10.sh) copy from a git worktree of a specific ref through the
  # SAME excludes as every other cell — its first version carried a third private copy of
  # this rsync, which is exactly the drift this helper was extracted to prevent.
  local wp_dir="$1" src="${2:-$PLUGIN_SRC}"
  rm -rf "$wp_dir/wp-content/plugins/wp-slimstat"
  rsync -a --delete --exclude '.git' --exclude 'node_modules' --exclude 'tests/e2e/node_modules' \
        "$src/" "$wp_dir/wp-content/plugins/wp-slimstat/" >/dev/null 2>&1
  chmod -R a+rwX "$wp_dir/wp-content" 2>/dev/null || true
}

# Preserve the debug log and report whether it holds a wp-slimstat fatal. Args: wp_dir art_dir
#
# Setting WP_DEBUG_LOG without ever reading the log is the shape where a plugin fatal raised
# while provisioning four blogs across two networks lands in a file nobody opens.
scan_debug_log() {
  # Declared on separate lines: under `set -u`, a single `local a=$1 b="$a/x"` does not reliably
  # see `a` while the same declaration is still being processed, and it failed exactly that way
  # on first use.
  local wp_dir="$1"
  local art="$2"
  local log="$wp_dir/wp-content/debug.log"

  [ -f "$log" ] || return 1
  cp "$log" "$art/debug.log" 2>/dev/null || true
  grep -qiE 'PHP (Fatal|Parse) error.*wp-slimstat' "$log" 2>/dev/null
}
