#!/usr/bin/env bash
# tests/docker/run-topology.sh <topology> [http_port] [db_port]
#
# Provisions ONE install shape from jaan-to/outputs/dev/v6-performance/TOPOLOGIES.md as a
# disposable container, loads the golden fixture, and probes the Network View. Topologies:
#
#   A            single site, local database — the control arm
#   C-subdir     one network, subdirectory addressing, 4 subsites (one archived)
#   C-subdomain  one network, subdomain addressing
#   C-mainonly   one network with no subsites but the multisite constants defined
#   E            several networks (ratified 2026-08-02) — two real networks, each with subsites
#
# Deliberately NOT here: topologies B/D (external database) need Pro's custom-DB addon and a
# second db service, and topology F is REFUSED by P5 — a harness that provisioned it would be
# building the thing the product declines to support.
#
# Why a separate script rather than a flag on run-cell.sh: run-cell.sh answers "does this
# PHP x WP combination work", one axis, and its verdict is keyed on php/wp. Topology is an
# orthogonal axis with a different failure vocabulary (blog enumeration, per-blog tables, the
# cross-network leak). The shared container bring-up now lives in lib.sh so neither script owns
# a private copy of it.
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
[ -f "$HARNESS_DIR/matrix.env" ] && source "$HARNESS_DIR/matrix.env"

TOPOLOGY="${1:?topology (A|C-subdir|C-subdomain|C-mainonly|E)}"
HTTP_PORT="${2:-18900}"
DB_PORT="${3:-13900}"
PHP="${TOPOLOGY_PHP:-8.2}"
WP="${TOPOLOGY_WP:-6.7}"

CELL="topology-${TOPOLOGY}"
CELL_DIR="$WORK_ROOT/topologies/$CELL"
WP_DIR="$CELL_DIR/wp"
ART="$CELL_DIR/artifacts"
# Lowercased as well as stripped: Compose rejects a project name containing an uppercase letter
# outright, and every topology label here has one.
PROJECT="sstopo_$(echo "$TOPOLOGY" | tr '[:upper:]' '[:lower:]' | tr -cd '[:alnum:]')"
BASE_URL="http://127.0.0.1:${HTTP_PORT}"
status="PASS"; reason=""

export COMPOSE_PROJECT_NAME="$PROJECT" PHP_VERSION="$PHP" HTTP_PORT DB_PORT
export MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.0}"
export CELL_WP_DIR="$WP_DIR"
rm -rf "$WP_DIR"
mkdir -p "$WP_DIR" "$ART"

fail(){ status="FAIL"; reason="${reason:-$1}"; err "$1"; }

finish() {
  # A plugin fatal raised while provisioning four blogs across two networks used to land in a
  # log nobody read: WP_DEBUG_LOG was set and never inspected.
  if scan_debug_log "$WP_DIR" "$ART"; then
    fail "wp-slimstat fatal in debug.log (see artifacts/debug.log)"
  fi
  write_verdict "$ART" "$CELL" "$PHP" "$WP" "$status" "$reason"
  dc down -v --remove-orphans >/dev/null 2>&1 || true
  log "$CELL → $status ${reason:+($reason)}"
}
trap finish EXIT

log "[$CELL] build + up (PHP $PHP, WP $WP, http $HTTP_PORT, db $DB_PORT)"
boot_stack "$ART" "$PHP" || { fail "stack did not come up (see build.log/up.log)"; exit 1; }

# ── core ────────────────────────────────────────────────────────────────────
wpc core download --version="$WP" --force > "$ART/install.log" 2>&1 || { fail "core download failed"; exit 1; }
wp_config_debug "$ART/install.log"

is_network=1
subdomains=""
case "$TOPOLOGY" in
  A)           is_network=0 ;;
  C-subdomain) subdomains="--subdomains" ;;
esac

if [ "$is_network" -eq 0 ]; then
  wpc core install --url="$BASE_URL" --title="SS $CELL" --admin_user=admin \
      --admin_password=admin --admin_email=qa@example.com --skip-email >>"$ART/install.log" 2>&1 \
      || { fail "core install failed"; exit 1; }
else
  # shellcheck disable=SC2086
  wpc core multisite-install --url="$BASE_URL" --title="SS $CELL" --admin_user=admin \
      --admin_password=admin --admin_email=qa@example.com --skip-email $subdomains >>"$ART/install.log" 2>&1 \
      || { fail "multisite-install failed"; exit 1; }
fi

# ── subsites ────────────────────────────────────────────────────────────────
# The golden fixture describes blogs 1-4 with blog 4 ARCHIVED. Provision exactly that, so the
# fixture's counts are meetable and the exclusion assertion runs against a real `archived` flag.
# Note archiving leaves `public = 1`, which is precisely why NetworkViewAddon still counts it.
if [ "$is_network" -eq 1 ] && [ "$TOPOLOGY" != "C-mainonly" ]; then
  for slug in two three four; do
    wpc site create --slug="$slug" >>"$ART/install.log" 2>&1 || fail "site create $slug failed"
  done
  wpc site archive 4 >>"$ART/install.log" 2>&1 || fail "could not archive blog 4"
fi

# ── a SECOND REAL network, for topology E ───────────────────────────────────
# Ratified 2026-08-02: E is a multi-network install. Built with populate_network() and
# wp_insert_site() rather than raw INSERTs into wp_site/wp_blogs.
#
# The raw-INSERT version this replaces produced a row, not a network: no wp_sitemeta (so
# get_network_option, main_site_id and the network's own name resolve to nothing), and no tables
# or options for its blog, because wp_initialize_site() never ran. That matters more than
# fidelity for its own sake — a blog with no slim_stats table holds no rows, so including it
# wrongly and excluding it correctly produce the IDENTICAL number, and the leak the topology
# exists to expose stays unreachable. It is given its own rows below for the same reason.
if [ "$TOPOLOGY" = "E" ]; then
  wpc eval '
    require_once ABSPATH . "wp-admin/includes/schema.php";
    require_once ABSPATH . "wp-admin/includes/upgrade.php";
    global $wpdb;
    $network_id = (int) $wpdb->get_var("SELECT MAX(id) FROM {$wpdb->site}") + 1;
    populate_network($network_id, "second.test", "qa@example.com", "Second Network", "/", false);
    $blog = wp_insert_site([
      "domain" => "second.test", "path" => "/", "network_id" => $network_id,
      "title" => "Second Network Main", "user_id" => 1, "public" => 1,
    ]);
    if (is_wp_error($blog)) { WP_CLI::error($blog->get_error_message()); }
    echo "network={$network_id} blog={$blog}";
  ' >>"$ART/install.log" 2>&1 || fail "second network creation failed"

  networks_now=$(wpc eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->site}");' 2>/dev/null | tr -dc '0-9')
  [ "${networks_now:-0}" -ge 2 ] || fail "expected >=2 networks, wp_site holds ${networks_now:-0}"
fi

# ── plugins: free from the working tree, Pro from the built zip ─────────────
# Pro is not optional here. Network aggregation lives in Pro's NetworkViewAddon, so a topology
# container without it exercises none of the code these shapes exist to test — the harness would
# be provisioning a network and then measuring a single-site query.
sync_plugin_src "$WP_DIR"
mkdir -p "$WP_DIR/wp-content/plugins/.pro"
if [ -f "$PRO_ZIP" ]; then
  cp "$PRO_ZIP" "$WP_DIR/wp-content/plugins/.pro/wp-slimstat-pro.zip"
else
  fail "PRO_ZIP not found at $PRO_ZIP — run tests/docker/build-pro.sh first"
fi
chmod -R a+rwX "$WP_DIR/wp-content" 2>/dev/null || true

# Per-site activation, NOT network-wide: D10's defect is that a per-site-activated network
# records nothing, so activating network-wide would conceal exactly what this tests. One eval
# over every blog rather than one exec per blog — the loop it replaces cost 9 container round
# trips per 4-blog topology, each a full WP-CLI bootstrap, and asked `site url` twice per blog
# for a value that never changed.
#
# `get_sites()` is multisite-only, so the loop is guarded rather than assumed — topology A is
# the control arm precisely because it is NOT a network, and an unguarded call fatals there.
wpc eval '
  include_once(WP_PLUGIN_DIR . "/wp-slimstat/admin/index.php");

  if (!is_multisite()) {
    activate_plugin("wp-slimstat/wp-slimstat.php");
    wp_slimstat_admin::init_tables($GLOBALS["wpdb"]);
    echo "activated (single site)";
    return;
  }

  foreach (get_sites(["number" => 0, "archived" => null, "deleted" => null, "spam" => null]) as $s) {
    switch_to_blog($s->blog_id);
    activate_plugin("wp-slimstat/wp-slimstat.php");
    wp_slimstat_admin::init_tables($GLOBALS["wpdb"]);
    restore_current_blog();
  }
  echo "activated (network, per-site)";
' >"$ART/activate.log" 2>&1 || fail "per-site activation / init_tables failed"

wpc plugin install /var/www/html/wp-content/plugins/.pro/wp-slimstat-pro.zip --activate-network --force \
  >>"$ART/activate.log" 2>&1 || wpc plugin install /var/www/html/wp-content/plugins/.pro/wp-slimstat-pro.zip --activate --force \
  >>"$ART/activate.log" 2>&1 || fail "pro install failed"

# ── the golden fixture ──────────────────────────────────────────────────────
if [ "$is_network" -eq 1 ] && [ "$TOPOLOGY" != "C-mainonly" ]; then
  dc exec -T -u www-data -e SLIMSTAT_GOLDEN_ALLOW_DESTRUCTIVE=1 wp \
     wp --path=/var/www/html eval-file wp-content/plugins/wp-slimstat/tests/fixtures/golden/load.php \
     > "$ART/fixture.log" 2>&1 || fail "golden fixture load failed"

  # A LOAD check, and labelled as one. It sums the per-blog tables directly after applying the
  # archived filter itself, so it is 15+14+11 by construction and can only fail if the loader
  # failed. The earlier version of this script presented the same query as proof that the
  # archived blog was excluded — an outcome it was structurally incapable of observing.
  loaded=$(wpc eval '
    global $wpdb; $n = 0;
    foreach (get_sites(["number" => 0, "archived" => 0, "deleted" => 0, "spam" => 0]) as $s) {
      switch_to_blog($s->blog_id);
      $n += (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}slim_stats");
      restore_current_blog();
    }
    echo $n;' 2>/dev/null | tr -dc '0-9')

  if [ "${loaded:-0}" != "40" ]; then
    fail "fixture LOAD incomplete: non-archived blogs hold ${loaded:-0} rows, expected 40"
  else
    log "[$CELL] fixture loaded: 40 rows across the non-archived blogs"
  fi

  # Rows in the OTHER network's blog, for topology E only.
  #
  # Without them E cannot discriminate the defect it exists for: a foreign blog holding zero
  # rows contributes zero whether it is wrongly included or correctly excluded, so a leaking
  # implementation and a correct one return the identical number. Seven is chosen simply to be
  # distinguishable — a correct Network View reports 40, one that only leaks the archived blog
  # reports 46, and one that leaks across networks too reports 53. Three different answers for
  # three different defects.
  if [ "$TOPOLOGY" = "E" ]; then
    wpc eval '
      global $wpdb;
      $foreign = 0;
      foreach (get_sites(["number" => 0, "archived" => null, "deleted" => null, "spam" => null]) as $s) {
        if ((int) $s->site_id === 1) { continue; }
        switch_to_blog($s->blog_id);
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}slim_stats");
        for ($i = 0; $i < 7; $i++) {
          $wpdb->insert($wpdb->prefix . "slim_stats", [
            "ip" => "10.99.0.1", "resource" => "/foreign/", "visit_id" => 900,
            "dt" => strtotime("2026-01-15 09:00:00 UTC") + $i, "browser_type" => 0,
          ], ["%s", "%s", "%d", "%d", "%d"]);
          $foreign++;
        }
        restore_current_blog();
      }
      echo "FOREIGN_ROWS=" . $foreign;
    ' > "$ART/foreign-rows.log" 2>&1 || fail "could not seed the second network"

    # Marked token, not "digits anywhere in the log". The first version scraped every digit from
    # a file that also carries WP-CLI warnings and timestamps, and reported 413413584134133104481587.
    foreign=$(sed -n 's/.*FOREIGN_ROWS=\([0-9]*\).*/\1/p' "$ART/foreign-rows.log" | head -1)
    [ "${foreign:-0}" -ge 7 ] || fail "second network seeded ${foreign:-0} rows, expected 7"
    log "[$CELL] seeded ${foreign:-0} rows into the other network — a leak now changes the total"
  fi

  # What the PLUGIN says, through its own filter. Reports; does not fail the topology — the
  # Network View's membership test is F9's to fix, and failing here would fail the environment
  # for a defect in the product.
  dc exec -T -u www-data wp wp --path=/var/www/html \
     eval-file wp-content/plugins/wp-slimstat/tests/docker/probe-network-view.php \
     > "$ART/network-view.log" 2>&1 || true
  grep -h 'NETWORK-VIEW-PROBE' "$ART/network-view.log" 2>/dev/null | tee -a "$ART/probe.txt" || true
  grep -h 'Warning:' "$ART/network-view.log" 2>/dev/null | head -2 || true
fi

# ── shape assertions ────────────────────────────────────────────────────────
networks=$(wpc eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->site}");' 2>/dev/null | tr -dc '0-9' || echo 0)
blogs=$(wpc eval 'echo count(get_sites(["number" => 0, "archived" => null, "deleted" => null, "spam" => null]));' 2>/dev/null | tr -dc '0-9' || echo 1)

case "$TOPOLOGY" in
  A)           [ "${networks:-0}" = "0" ] || fail "topology A must not be a network (wp_site rows: $networks)" ;;
  C-mainonly)  [ "${blogs:-0}" = "1" ]    || fail "C-mainonly expects 1 blog, found $blogs" ;;
  C-subdir|C-subdomain)
               [ "${blogs:-0}" -ge 4 ] || fail "$TOPOLOGY expects >=4 blogs, found $blogs"
               [ "${networks:-0}" = "1" ] || fail "$TOPOLOGY expects exactly 1 network, found $networks" ;;
  E)           [ "${networks:-0}" -ge 2 ] || fail "E expects >=2 networks, found $networks"
               [ "${blogs:-0}" -ge 5 ] || fail "E expects >=5 blogs across both networks, found $blogs" ;;
esac

probe=$(grep -h 'NETWORK-VIEW-PROBE' "$ART/network-view.log" 2>/dev/null | sed 's/.*NETWORK-VIEW-PROBE //' | head -1)
printf '{"topology":"%s","networks":%s,"blogs":%s,"php":"%s","wp":"%s","network_view":%s}\n' \
  "$TOPOLOGY" "${networks:-0}" "${blogs:-0}" "$PHP" "$WP" "${probe:-null}" > "$ART/shape.json"

log "[$CELL] shape: $networks network(s), $blogs blog(s)"
exit 0
