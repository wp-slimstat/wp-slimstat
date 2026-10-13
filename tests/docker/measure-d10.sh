#!/usr/bin/env bash
# tests/docker/measure-d10.sh <before-ref|-> [after-ref|-]
#
# D10's before/after measurement: per-blog Slimstat TABLE COUNTS on a real subdirectory
# network, exercised through the two code paths the defect lives on —
#
#   1. `wp plugin activate wp-slimstat --network` over WP-CLI, with subsites already
#      present. Before the fix, on_activate() ignored $network_wide: only the current blog
#      got tables.
#   2. `wp site create` over WP-CLI, after activation. Before the fix, the only
#      registration hung off deprecated `wpmu_new_blog` inside the is_admin()-only admin
#      bundle, so core's compat shim found no callback on the CLI path: the new subsite
#      got NOTHING.
#
# One arm per invocation-ref: '-' means the working tree; anything else is resolved with
# `git worktree` so the arm is byte-exactly that commit. Both arms run the identical
# procedure in a fresh container each, and the numbers are DETERMINISTIC COUNTS (tables in
# information_schema), not milliseconds — no interleaving or null control is owed
# (VERIFICATION-PROTOCOL: counts are claims, timings need controls).
#
# CONTROLS, printed before any result:
#   - the arm's plugin dir really is the requested ref (git rev-parse in the worktree)
#   - the network really has the expected blogs before activation
#   - the expected table count per fully-provisioned blog comes from the MANIFEST
#     (Schema::tables()), not a literal, so a Phase G table cannot silently pass
#   - debug.log is scanned; a deprecation notice naming wpmu_new_blog is a FAILURE of the
#     after arm, not noise
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

REF="${1:?ref to measure ('-' for working tree)}"
HTTP_PORT="${2:-18920}"
DB_PORT="${3:-13920}"
PHP="${D10_PHP:-8.2}"
WP="${D10_WP:-6.7}"

CELL="d10-$(echo "$REF" | tr -cd '[:alnum:]' | cut -c1-12)"
[ "$REF" = "-" ] && CELL="d10-worktree"
CELL_DIR="$WORK_ROOT/d10/$CELL"
WP_DIR="$CELL_DIR/wp"
ART="$CELL_DIR/artifacts"
PROJECT="ssd10$(echo "$CELL" | tr -cd '[:alnum:]' | tr '[:upper:]' '[:lower:]')"
BASE_URL="http://127.0.0.1:${HTTP_PORT}"
status="PASS"; reason=""

export COMPOSE_PROJECT_NAME="$PROJECT" PHP_VERSION="$PHP" HTTP_PORT DB_PORT
export MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.0}"
export CELL_WP_DIR="$WP_DIR"
rm -rf "$WP_DIR"
mkdir -p "$WP_DIR" "$ART"

fail(){ status="FAIL"; reason="${reason:-$1}"; err "$1"; }

ARM_SRC="$PLUGIN_SRC"
WORKTREE=""
if [ "$REF" != "-" ]; then
  WORKTREE="$CELL_DIR/arm-src"
  rm -rf "$WORKTREE"
  git -C "$PLUGIN_SRC" worktree add --detach "$WORKTREE" "$REF" >/dev/null 2>&1 \
    || { err "cannot create worktree at $REF"; exit 1; }
  ARM_SRC="$WORKTREE"
fi

finish() {
  if scan_debug_log "$WP_DIR" "$ART"; then
    fail "wp-slimstat fatal in debug.log (see artifacts/debug.log)"
  fi
  # The after arm must not have swapped one defect for a pile of deprecation notices.
  if grep -q "wpmu_new_blog" "$ART/debug.log" 2>/dev/null; then
    fail "debug.log mentions wpmu_new_blog — the deprecated hook is still being exercised"
  fi
  write_verdict "$ART" "$CELL" "$PHP" "$WP" "$status" "$reason"
  dc down -v --remove-orphans >/dev/null 2>&1 || true
  [ -n "$WORKTREE" ] && git -C "$PLUGIN_SRC" worktree remove --force "$WORKTREE" >/dev/null 2>&1
  log "$CELL → $status ${reason:+($reason)}"
}
trap finish EXIT

# ── CONTROLS ────────────────────────────────────────────────────────────────
echo "CONTROLS:"
if [ -n "$WORKTREE" ]; then
  echo "  arm source: $(git -C "$WORKTREE" rev-parse --short HEAD) (requested: $REF)"
else
  echo "  arm source: WORKING TREE ($(git -C "$PLUGIN_SRC" rev-parse --short HEAD) + uncommitted)"
fi

log "[$CELL] build + up (PHP $PHP, WP $WP, http $HTTP_PORT, db $DB_PORT)"
boot_stack "$ART" "$PHP" || { fail "stack did not come up"; exit 1; }

wpc core download --version="$WP" --force > "$ART/install.log" 2>&1 || { fail "core download failed"; exit 1; }
wp_config_debug "$ART/install.log"
wpc core multisite-install --url="$BASE_URL" --title="D10 $CELL" --admin_user=admin \
    --admin_password=admin --admin_email=qa@example.com --skip-email >>"$ART/install.log" 2>&1 \
    || { fail "multisite-install failed"; exit 1; }

# Two subsites BEFORE activation — what the network-wide walk must reach.
for slug in pre-two pre-three; do
  wpc site create --slug="$slug" >>"$ART/install.log" 2>&1 || fail "site create $slug failed"
done

blogs=$(wpc eval 'echo count(get_sites(["number" => 0]));' 2>/dev/null | tr -dc '0-9')
echo "  blogs before activation: ${blogs:-?} (expect 3)"
[ "${blogs:-0}" = "3" ] || { fail "expected 3 blogs pre-activation, have ${blogs:-0}"; exit 1; }

# Free plugin from THIS ARM's source; no Pro — both defect paths are free-plugin paths.
sync_plugin_src "$WP_DIR" "$ARM_SRC"

# Expected tables per fully-provisioned blog — from the manifest, not a literal.
expected=$(wpc eval 'require WP_PLUGIN_DIR . "/wp-slimstat/vendor/autoload.php";
  echo count(SlimStat\Schema\Schema::tables());' 2>/dev/null | tr -dc '0-9')
echo "  manifest tables per blog: ${expected:-?}"
[ -n "${expected:-}" ] && [ "$expected" -ge 4 ] || { fail "cannot read manifest table count"; exit 1; }

# Slimstat tables present for one blog. Args: blog_id
count_tables() {
  wpc eval '
    global $wpdb;
    $prefix = $wpdb->get_blog_prefix((int) "'"$1"'");
    echo (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s",
      str_replace("_", "\\_", $prefix) . "slim\\_%"
    ));' 2>/dev/null | tr -dc '0-9'
}

# ── PATH 1: network activation over WP-CLI ──────────────────────────────────
wpc plugin activate wp-slimstat --network > "$ART/activate.log" 2>&1 || { fail "network activation failed"; exit 1; }

act1=$(count_tables 1); act2=$(count_tables 2); act3=$(count_tables 3)

# ── PATH 2: subsite created AFTER activation, over WP-CLI ───────────────────
wpc site create --slug=d10probe >>"$ART/install.log" 2>&1 || fail "post-activation site create failed"
new_id=$(wpc eval 'foreach (get_sites(["number" => 0]) as $s) { $m = max($m ?? 0, (int) $s->blog_id); } echo $m;' 2>/dev/null | tr -dc '0-9')
echo "  new subsite id: ${new_id:-?} (expect 4)"
act4=$(count_tables "${new_id:-4}")

# ── RESULT ──────────────────────────────────────────────────────────────────
cat > "$ART/d10.json" <<JSON
{"cell":"$CELL","ref":"$REF","expected_per_blog":$expected,
 "network_activation":{"blog_1":${act1:-0},"blog_2":${act2:-0},"blog_3":${act3:-0}},
 "cli_site_create":{"blog_${new_id:-4}":${act4:-0}}}
JSON

echo ""
echo "RESULT ($CELL):"
echo "  network activation (CLI):  blog 1: ${act1:-0}/$expected · blog 2: ${act2:-0}/$expected · blog 3: ${act3:-0}/$expected"
echo "  site create after (CLI):   blog ${new_id:-4}: ${act4:-0}/$expected"
echo "  artifacts: $ART/d10.json"
