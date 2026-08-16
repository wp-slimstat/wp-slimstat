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

# Record a cell failure without aborting: downgrade status, keep the FIRST reason.
# Callers declare `status="PASS"; reason=""` before use; write_verdict reads both.
fail() { status="FAIL"; reason="${reason:-$1}"; err "$1"; }

# ── Pro measurement arm ─────────────────────────────────────────────────────
# Resolve which wp-slimstat-pro build a two-arm measurement installs: '-' = the sibling
# checkout as it stands (working tree), a ref = a detached worktree and a zip rebuilt for
# exactly that ref. Sets PRO_CHECKOUT, PRO_WT ('' for working tree) and ARM_PRO_ZIP;
# cleanup_pro_arm undoes the worktree from the caller's exit trap. Extracted when
# measure-f6-useroverview.sh needed the second copy of measure-f6-external.sh's block:
# arm provenance is the part a sealed measurement's credibility rests on, so it gets one
# owner, like the bring-up helpers above.
build_pro_arm() { # <pro_ref|-> <cell_dir> <art_dir>
  local ref="$1" cell_dir="$2" art="$3"
  PRO_CHECKOUT="$(cd "$PLUGIN_SRC/.." && pwd)/wp-slimstat-pro"
  PRO_WT=""
  ARM_PRO_ZIP="$HARNESS_DIR/build/wp-slimstat-pro.zip"
  if [ "$ref" != "-" ]; then
    PRO_WT="$cell_dir/pro-src"; rm -rf "$PRO_WT"
    git -C "$PRO_CHECKOUT" worktree add --detach "$PRO_WT" "$ref" >/dev/null 2>&1 \
      || { err "cannot create pro worktree at $ref"; return 1; }
    ARM_PRO_ZIP="$HARNESS_DIR/build/wp-slimstat-pro-$ref.zip"
    PRO_SRC_OVERRIDE="$PRO_WT" PRO_ZIP_OUT="$ARM_PRO_ZIP" bash "$HARNESS_DIR/build-pro.sh" \
      > "$art/build-pro.log" 2>&1 || { err "pro build at $ref failed (see build-pro.log)"; return 1; }
  else
    bash "$HARNESS_DIR/build-pro.sh" > "$art/build-pro.log" 2>&1 \
      || { err "pro build failed (see build-pro.log)"; return 1; }
  fi
}

# Remove build_pro_arm's worktree, if one was made. Safe to call unconditionally.
cleanup_pro_arm() {
  [ -n "${PRO_WT:-}" ] && git -C "$PRO_CHECKOUT" worktree remove --force "$PRO_WT" >/dev/null 2>&1
  return 0
}

# The free half of the same provenance rule: '-' = the working tree as it stands, a ref =
# a detached worktree of exactly that ref. Sets FREE_SRC and FREE_WT ('' = working tree).
build_free_arm() { # <free_ref|-> <cell_dir>
  FREE_SRC="$PLUGIN_SRC"; FREE_WT=""
  if [ "$1" != "-" ]; then
    FREE_WT="$2/free-src"; rm -rf "$FREE_WT"
    git -C "$PLUGIN_SRC" worktree add --detach "$FREE_WT" "$1" >/dev/null 2>&1 \
      || { err "cannot create free worktree at $1"; return 1; }
    FREE_SRC="$FREE_WT"
  fi
}

cleanup_free_arm() {
  [ -n "${FREE_WT:-}" ] && git -C "$PLUGIN_SRC" worktree remove --force "$FREE_WT" >/dev/null 2>&1
  return 0
}

# Run a probe twice and byte-compare its fenced JSON — the same-arm null control every
# measure script performs. Extracted at the FOURTH private copy, which was also the first
# to drift (no diff evidence on mismatch, wpc hand-expanded). Args: <probe container path>
# <marker prefix (e.g. UO-NET-JSON)> <artifact basename> ; artifacts land as
# $ART/<base>-1.json, -2.json and, when identical, $ART/<base>.json. Uses fail(), so the
# caller's cell FAILs (without exiting) on any probe error or a mismatch — and a mismatch
# prints the diff head, because "two different answers" with no evidence forces a container
# re-run to learn what differed.
probe_null_control() { # <probe_path> <marker> <base>
  local probe="$1" marker="$2" base="$3" run
  for run in 1 2; do
    wpc eval-file "$probe" > "$ART/$base-run$run.out" 2>&1 || fail "$base probe run $run errored"
    awk -v m="$marker" '$0 == m "-BEGIN" {f=1; next} $0 == m "-END" {f=0} f' \
      "$ART/$base-run$run.out" > "$ART/$base-$run.json"
    [ -s "$ART/$base-$run.json" ] || fail "$base probe run $run produced no JSON"
  done
  if cmp -s "$ART/$base-1.json" "$ART/$base-2.json"; then
    log "[$CELL] $base null control: two runs byte-identical ($(wc -c < "$ART/$base-1.json" | tr -d ' ') bytes)"
    cp "$ART/$base-1.json" "$ART/$base.json"
  else
    fail "$base null control FAILED — same arm, two different answers"
    diff "$ART/$base-1.json" "$ART/$base-2.json" | head -20
  fi
}

# One line of arm provenance for the CONTROLS block, same wording everywhere — the copies
# had already diverged ('+dirty?' vs '+staged') by the time this was extracted.
free_arm_desc() {
  if [ -n "${FREE_WT:-}" ]; then
    echo "$(git -C "$FREE_WT" rev-parse --short HEAD) (pinned ref)"
  else
    echo "WORKING TREE ($(git -C "$PLUGIN_SRC" rev-parse --short HEAD)+uncommitted)"
  fi
}

# ── Shared WP cell provisioning ─────────────────────────────────────────────
# download → config → install → free source → pro zip → both activations. The third
# script to need this block is what got it extracted, same as build_pro_arm. Cell-specific
# steps (posts, users, WP_DEBUG_DISPLAY) stay in the callers, after this returns.
provision_wp_cell() { # <art> <wp_version> <base_url> <free_src>
  local art="$1" wp="$2" base_url="$3" free_src="$4"
  wpc core download --version="$wp" --force > "$art/install.log" 2>&1 || { fail "core download failed"; return 1; }
  wp_config_debug "$art/install.log"
  wpc core install --url="$base_url" --title="$COMPOSE_PROJECT_NAME" --admin_user=admin \
      --admin_password=admin --admin_email=qa@example.com --skip-email >>"$art/install.log" 2>&1 \
      || { fail "core install failed"; return 1; }
  sync_plugin_src "$CELL_WP_DIR" "$free_src"
  wpc plugin activate wp-slimstat >>"$art/install.log" 2>&1 || { fail "free activation failed"; return 1; }
  # Pro rides only when the caller resolved an arm zip (build_pro_arm sets ARM_PRO_ZIP).
  # A free-only bench cell provisions without it rather than re-inlining this block.
  if [ -n "${ARM_PRO_ZIP:-}" ]; then
    mkdir -p "$CELL_WP_DIR/wp-content/plugins/.pro"
    cp "$ARM_PRO_ZIP" "$CELL_WP_DIR/wp-content/plugins/.pro/wp-slimstat-pro.zip"
    chmod -R a+rwX "$CELL_WP_DIR/wp-content" 2>/dev/null || true
    wpc plugin install /var/www/html/wp-content/plugins/.pro/wp-slimstat-pro.zip --activate --force \
        >>"$art/activate.log" 2>&1 || { fail "pro install failed"; return 1; }
  fi
}

# Point the custom-DB add-on at a host/db with server-side tracking on, root/root creds.
enable_custom_db_addon() { # <host> <dbname> <art>
  wpc eval '
    $s = get_option("slimstat_options", []);
    $s["addon_custom_db_enable"] = "on";
    $s["addon_custom_db_dbhost"] = "'"$1"'";
    $s["addon_custom_db_dbname"] = "'"$2"'";
    $s["addon_custom_db_dbuser"] = "root";
    $s["addon_custom_db_dbpass"] = "root";
    $s["javascript_mode"] = "no";
    $s["is_tracking"] = "on";
    update_option("slimstat_options", $s);
    echo "addon: on host='"$1"' db='"$2"'";
  ' >> "$3/settings.log" 2>&1 || fail "addon settings update failed"
}

# The admin path that owns DDL: creates the analytics tables (and, post-C48, identity)
# on whatever handle the add-on resolves.
init_analytics_env() { # <art>
  wpc eval 'include_once WP_PLUGIN_DIR . "/wp-slimstat/admin/index.php"; wp_slimstat_admin::init_environment(); echo "env init ran";' \
    >> "$1/settings.log" 2>&1 || fail "init_environment failed"
}

# How many slim_ tables a schema holds. One owner for the LIKE pattern and its escaping —
# two cells had already grown two different definitions of "local slim tables".
count_slim_tables() { # <db-service> <schema>
  dc exec -T "$1" mysql -uroot -proot "$2" -N -e \
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$2' AND TABLE_NAME LIKE '%slim\\_%';" 2>/dev/null | tr -dc '0-9'
}

# Drop every slim_ table in a schema, by ENUMERATION — a hardcoded name list cannot see
# multisite prefixes or a table added later, and GROUP_CONCAT truncates at 1024 bytes
# (24 multisite names crossed it: the last DROP arrived cut mid-name and one table
# survived). One DROP per name, composed in shell; FK checks off because slim_events
# references slim_stats and a naive order leaves the parent behind. Verifies to 0 and
# fails otherwise — a silently failed drop lets later checks fail with the wrong blame.
drop_local_slim_tables() { # <db-service> <schema>
  local svc="$1" schema="$2" drops="" t left
  while IFS= read -r t; do
    [ -n "$t" ] && drops="${drops}DROP TABLE IF EXISTS \`${t}\`; "
  done < <(dc exec -T "$svc" mysql -uroot -proot "$schema" -N -e \
    "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$schema' AND TABLE_NAME LIKE '%slim\\_%';" 2>/dev/null | tr -d '\r')
  if [ -n "$drops" ]; then
    dc exec -T "$svc" mysql -uroot -proot "$schema" -e \
      "SET FOREIGN_KEY_CHECKS=0; ${drops}SET FOREIGN_KEY_CHECKS=1;" >/dev/null 2>&1 \
      || { fail "could not drop the slim_ tables in $schema"; return 1; }
  fi
  left=$(count_slim_tables "$svc" "$schema")
  [ "${left:-1}" = "0" ] || { fail "slim_ tables remain in $schema after the drop"; return 1; }
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
