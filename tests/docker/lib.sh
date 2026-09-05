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
die()  { err "$*"; exit 1; }

# Two primitives every drill and measurement script here had privately. canary/run-canary.sh
# sourced this file and then redefined `say` byte-identically to `log` above and `die` as `err`
# plus an exit — paying for the source and then shadowing what it bought, so a later edit to `log`
# would appear to have no effect there.
#
# reachability/run-gate.sh has its own copies and keeps them: it deliberately does NOT source this
# file, and its recorded verdict binds to a subject digest under a container-backed gate this
# session cannot execute. Converting a gate on the strength of reading it is the thing PITFALLS
# keeps recording; it is filed as debt instead.
digest() { shasum -a 256 "$1" | awk '{print $1}'; }
now()    { date -u +%FT%TZ; }

# WHERE AN ARM'S WORKTREE LIVES — one definition, because two scripts now write to it.
# compare-answers.sh creates and reuses these; canary/run-canary.sh patches one in place before
# handing the run to verify-change.sh. The path was composed by hand in both, which meant a layout
# change in the owner would have silently sent the drill's `patch -p1 -d` somewhere else.
arm_worktree_dir() { printf '%s/%s/%s/arms/%s' "$WORK_ROOT" "${2:-answers}" "${2:-answers}" "$1"; }

# ── Vintage arms: the ZIP wordpress.org serves, not a ZIP we built ──────────
# H1. Cells 7a-7d upgrade FROM 4.8.1 / 5.1.5 / 5.2.13 / 5.4.12, and build-free.sh cannot produce
# those arms: it hard-requires `.distignore` at the ref, which the 4.8 line predates by years.
# The wp.org ZIP is therefore not merely a more faithful arm, it is the ONLY arm those vintages
# can have — so `use_ref` accepts `wp.org:<version>` and a bare `.zip` path beside a git ref.
#
# Bytes we did not build must be pinned, hence arms.sha256. The failure this refuses is quiet:
# a truncated download, a re-rolled ZIP, a proxy error page saved under the right name — each
# gives a cell that runs to completion and reports on code no site is running. An unpinned
# version is refused BEFORE the download, so "it worked, add the hash after" cannot become the
# habit.
ARMS_DIR="${ARMS_DIR:-$HOME/slimstat-v6-baselines/arms}"
ARMS_MANIFEST="${ARMS_MANIFEST:-$HARNESS_DIR/arms.sha256}"
ARMS_BASE_URL="${ARMS_BASE_URL:-https://downloads.wordpress.org/plugin}"

# The version an arm ref names, or empty for a git ref. Split out because the container-side H1
# control compares it against what WordPress reports, and a second hand-rolled `${ref#wp.org:}`
# there is how the two would drift.
arm_ref_version() { # <ref>
  case "$1" in
    wp.org:*) printf '%s\n' "${1#wp.org:}" ;;
    *)        : ;;
  esac
}

# The pinned digest for a version, or exit 1 if this version is not in the manifest.
arm_manifest_sha() { # <version>
  awk -v want="wp-slimstat.$1.zip" '$2 == want { print $1; hit = 1 } END { exit hit ? 0 : 1 }' \
    "$ARMS_MANIFEST"
}

# Resolve an arm ref to a ZIP on disk, downloading and verifying when it names a wp.org version.
# THREE exit codes, because two would lose the distinction that matters:
#   0  a vintage arm; the path is on stdout
#   2  not a vintage ref at all — the caller should build it from git, as it always did
#   1  a vintage ref that could not be honoured — unpinned, undownloadable, or wrong bytes
# Returning 2 as "failure" would send a typo'd `wp.org:5.5` down the build path, where
# `git rev-parse` fails with a message about a commit that has nothing to do with the mistake.
# Every diagnostic goes to stderr EXPLICITLY. `err` writes to stdout like `log` does, and this
# function's return value IS its stdout — so an unredirected message is captured by
# `zip=$(resolve_arm_zip ...)` and becomes a path-shaped string naming no file. Found by the
# test below, which asserts stdout is empty on every refusal. PITFALLS 130.
resolve_arm_zip() { # <ref>
  local ref="$1" ver zip want got
  case "$ref" in
    wp.org:*) ver=$(arm_ref_version "$ref"); zip="$ARMS_DIR/wp-slimstat.$ver.zip" ;;
    *.zip)    ver=""; zip="$ref" ;;
    *)        return 2 ;;
  esac

  if [ -n "$ver" ]; then
    # Validated before it reaches a path or a URL: `wp.org:../../etc/passwd` is a version string
    # only in the sense that nothing had looked at it.
    printf '%s' "$ver" | grep -qE '^[0-9]+(\.[0-9]+){1,3}$' \
      || { err "not a plugin version: '$ver'" >&2; return 1; }
    want=$(arm_manifest_sha "$ver") \
      || { err "no pinned sha256 for $ver in $ARMS_MANIFEST — add the line in the same commit as the cell that needs it" >&2; return 1; }
    if [ ! -s "$zip" ]; then
      mkdir -p "$ARMS_DIR"
      curl -fsS -o "$zip.part" "$ARMS_BASE_URL/wp-slimstat.$ver.zip" \
        || { rm -f "$zip.part"; err "could not fetch wp-slimstat.$ver.zip from $ARMS_BASE_URL" >&2; return 1; }
      mv "$zip.part" "$zip"
    fi
  fi

  [ -s "$zip" ] || { err "arm ZIP not found or empty: $zip" >&2; return 1; }

  if [ -n "$ver" ]; then
    got=$(digest "$zip")
    [ "$got" = "$want" ] || {
      err "arm ZIP for $ver is not the pinned bytes: expected $want, got $got" >&2
      return 1
    }
  fi

  printf '%s\n' "$zip"
}

# ── What shape is this table, and what shape is that corpus? ────────────────
# H3. A vintage cell is only a vintage cell if the CORPUS matches the arm. The existing C1 asks
# the dump for `vid_hash`/`ua_id` and stops there, which a 5.5-shaped dump passes under a 4.8.1
# arm — and then the cell is 4.8.1 code on 5.5 tables: every ADD COLUMN in the 4.8 blocks is a
# no-op against a column that is already there, every DROP finds nothing to drop, and the cell
# reports the DDL path green having executed none of it. That is the failure this pair of
# functions exists to make impossible, and it is invisible in every other signal a cell emits.
#
# The comparison is column SET against column SET: what the arm's own DDL just built (read from
# information_schema, so it is the schema that exists rather than the schema we believe in) and
# what the dump file declares (read from the FILE, before the import — afterwards the table IS
# the dump's shape and the question can no longer be asked).

# The live column set of a table, sorted, comma-joined. Empty when the table does not exist.
table_columns() { # <table>
  mysql_q "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA='wordpress' AND TABLE_NAME='$1' ORDER BY COLUMN_NAME;" \
    | tr -d '\r' | sed '/^$/d' | sort | tr '\n' ',' | sed 's/,$//'
}

# The column set a gzipped dump declares for a table, in the same shape. Reads only that CREATE
# TABLE block: index lines are `KEY` / `PRIMARY KEY` / `UNIQUE KEY`, which carry a backtick but
# not in first position, and the range ends at the closing `)` so a second table in the same
# file cannot leak in.
dump_columns() { # <dump.gz> <table>
  gzip -dc "$1" \
    | sed -n "/^CREATE TABLE \`$2\` (/,/^)/p" \
    | sed -n 's/^  `\([^`]*\)`.*/\1/p' \
    | sort | tr '\n' ',' | sed 's/,$//'
}

# Which columns are in the first set and not in the second. Both take the comma-joined form
# above; the result is comma-joined too, and empty when the first set is contained in the second.
columns_missing_from() { # <set-a> <set-b>
  local haystack=",$2," c
  # `printf '%s\n'`, not `printf '%s'`: BSD sed does not add the trailing newline the input
  # lacks, and `read` returns false on a final unterminated line WITHOUT running the body, so the
  # LAST column silently dropped out of every difference. Caught by the empty-second-set case in
  # tests/rehearsal-column-control-test.php, which is why that case is there. PITFALLS 131.
  printf '%s\n' "$1" | tr ',' '\n' | sed '/^$/d' | while IFS= read -r c; do
    case "$haystack" in
      *",$c,"*) : ;;
      *)        printf '%s\n' "$c" ;;
    esac
  done | tr '\n' ',' | sed 's/,$//'
}

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
# Resolve which shipped wp-slimstat-pro build a two-arm measurement installs: '-' = the sibling
# checkout's committed HEAD, a ref = that exact commit. build/build-dist.sh owns the only scoper
# toolchain; this helper never manufactures a second artifact recipe. Sets PRO_CHECKOUT,
# PRO_RESOLVED_REF and ARM_PRO_ZIP. Extracted when
# measure-f6-useroverview.sh needed the second copy of measure-f6-external.sh's block:
# arm provenance is the part a sealed measurement's credibility rests on, so it gets one
# owner, like the bring-up helpers above.
build_pro_arm() { # <pro_ref|-> <cell_dir> <art_dir>
  local ref="$1" cell_dir="$2" art="$3"
  PRO_CHECKOUT="$(cd "$PLUGIN_SRC/.." && pwd)/wp-slimstat-pro"
  PRO_WT=""
  [ "$ref" = "-" ] && ref=HEAD
  PRO_RESOLVED_REF=$(git -C "$PRO_CHECKOUT" rev-parse "$ref^{commit}") \
    || { err "cannot resolve Pro ref $ref"; return 1; }
  ARM_PRO_ZIP="$HARNESS_DIR/build/wp-slimstat-pro-${PRO_RESOLVED_REF:0:8}.zip"
  PRO_REF_OVERRIDE="$PRO_RESOLVED_REF" PRO_ZIP_OUT="$ARM_PRO_ZIP" \
    PRO_BUILD_LOG="$art/build-pro.log" bash "$HARNESS_DIR/build-pro.sh" \
    || { err "Pro shipped build at $ref failed (see build-pro.log)"; return 1; }
}

# Retained for callers whose traps predate the shipped-artifact builder. Safe to call unconditionally.
cleanup_pro_arm() {
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

# The Pro half of the same provenance line. Records the RESOLVED sha, never the ref as typed:
# PRO_REF=development files a verdict naming a branch, and a branch does not identify a build.
# NOTE: four earlier callers still print their own hand-rolled version of this line
# (measure-f6-{external,topologyf,useroverview}.sh, run-email-author-counts.sh) and had already
# drifted apart on spacing before this was extracted -- exactly why free_arm_desc() exists.
# They are owed a migration onto this; rehearse-upgrade.sh is the first caller.
pro_arm_desc() {
  if [ -n "${PRO_RESOLVED_REF:-}" ]; then
    echo "${PRO_RESOLVED_REF:0:8} (shipped ZIP)"
  else
    echo "WORKING TREE ($(git -C "${PRO_CHECKOUT:-.}" rev-parse --short HEAD)+uncommitted)"
  fi
}

# ── Shared WP cell provisioning ─────────────────────────────────────────────
# download → config → install → free source → pro zip → both activations. The third
# script to need this block is what got it extracted, same as build_pro_arm. Cell-specific
# steps (posts, users, WP_DEBUG_DISPLAY) stay in the callers, after this returns.
provision_wp_cell() { # <art> <wp_version> <base_url> <free_src_fallback>
  local art="$1" wp="$2" base_url="$3" free_src="$4"
  wpc core download --version="$wp" --force > "$art/install.log" 2>&1 || { fail "core download failed"; return 1; }
  wp_config_debug "$art/install.log"
  wpc core install --url="$base_url" --title="$COMPOSE_PROJECT_NAME" --admin_user=admin \
      --admin_password=admin --admin_email=qa@example.com --skip-email >>"$art/install.log" 2>&1 \
      || { fail "core install failed"; return 1; }
  if [ -n "${ARM_FREE_ZIP:-}" ]; then
    mkdir -p "$CELL_WP_DIR/wp-content/plugins/.free"
    cp "$ARM_FREE_ZIP" "$CELL_WP_DIR/wp-content/plugins/.free/wp-slimstat.zip"
    chmod -R a+rwX "$CELL_WP_DIR/wp-content" 2>/dev/null || true
    wpc plugin install /var/www/html/wp-content/plugins/.free/wp-slimstat.zip --activate --force \
      >>"$art/install.log" 2>&1 || { fail "Free ZIP install failed"; return 1; }
  else
    sync_plugin_src "$CELL_WP_DIR" "$free_src"
    wpc plugin activate wp-slimstat >>"$art/install.log" 2>&1 || { fail "free activation failed"; return 1; }
  fi
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
