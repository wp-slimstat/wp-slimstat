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

TOPOLOGY="${1:?topology (A|C-subdir|C-subdomain|C-mainonly|D|E)}"
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
# Topology D is C's subdirectory network with every blog's analytics tables on a SECOND
# MySQL SERVICE (TOPOLOGIES.md: "C plus B"). The overlay must be exported before
# boot_stack so dc() composes db2 in for every later exec too.
if [ "$TOPOLOGY" = "D" ]; then
  export DC_EXTRA_FILE="$HARNESS_DIR/docker-compose.db2.yml"
  export DB2_PORT="$((DB_PORT + 500))"
fi
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
if [ "$TOPOLOGY" = "D" ]; then
  wait_for 40 3 dc exec -T db2 mysqladmin ping -h127.0.0.1 -uroot -proot --silent \
    || { fail "db2 did not come up"; exit 1; }
fi

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

# ── topology D: analytics moves to db2, and the local copies stop existing ──────────────
if [ "$TOPOLOGY" = "D" ]; then
  # The option row is PER BLOG under per-site activation, so the add-on is enabled per
  # blog — deliberately NOT lib.sh's enable_custom_db_addon, which writes ONE blog and
  # flips tracking flags this cell must not touch (D differs from C only in placement) — archived blog 4 included, since its table must exist for the exclusion claim
  # to be about the FILTER and not about a missing table.
  wpc eval '
    foreach (get_sites(["number" => 0, "archived" => null, "deleted" => null, "spam" => null]) as $s) {
      switch_to_blog($s->blog_id);
      $o = get_option("slimstat_options", []);
      $o["addon_custom_db_enable"] = "on";
      $o["addon_custom_db_dbhost"] = "db2";
      $o["addon_custom_db_dbname"] = "slimstat_ext";
      $o["addon_custom_db_dbuser"] = "root";
      $o["addon_custom_db_dbpass"] = "root";
      update_option("slimstat_options", $o);
      restore_current_blog();
    }
    echo "custom-db add-on enabled per blog";
  ' >"$ART/customdb.log" 2>&1 || fail "could not enable the custom-db add-on per blog"

  # Fresh process, so the memoised filter answers the EXTERNAL handle: every blog's tables
  # ensured on db2. Table names come from the blog-switched core prefix; the handle is one
  # connection for all blogs — the plugin's own F6 shape.
  wpc eval '
    include_once(WP_PLUGIN_DIR . "/wp-slimstat/admin/index.php");
    $h = apply_filters("slimstat_custom_wpdb", $GLOBALS["wpdb"]);
    if ($h === $GLOBALS["wpdb"]) { echo "FILTER DID NOT ENGAGE"; exit(1); }
    foreach (get_sites(["number" => 0, "archived" => null, "deleted" => null, "spam" => null]) as $s) {
      switch_to_blog($s->blog_id);
      wp_slimstat_admin::init_tables($h);
      restore_current_blog();
    }
    echo "tables ensured on the external DB for every blog";
  ' >>"$ART/customdb.log" 2>&1 || fail "could not ensure tables on db2"

  # Per-site activation above already created every blog's tables in the WORDPRESS
  # database (the add-on was off) — identical in any arm, so a count there measures
  # activation, not routing (PITFALLS 49). Worse, a leftover local table lets a
  # wrong-handle read answer PLAUSIBLY instead of failing. Drop them all: from here,
  # any slim read on the core handle errors loudly, which is the trap this cell sets.
  drop_local_slim_tables db wordpress || exit 1
  ext_tables=$(count_slim_tables db2 slimstat_ext)
  log "[$CELL] D: local slim_ tables 0 (verified by the drop) · external slim_ tables ${ext_tables:-?} (expect 24: 6 per blog x 4)"
  [ "${ext_tables:-0}" -ge 20 ] || fail "external tables missing — db2 holds ${ext_tables:-0}"
fi

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
    $h = apply_filters("slimstat_custom_wpdb", $wpdb); // same object unless topology D
    foreach (get_sites(["number" => 0, "archived" => 0, "deleted" => 0, "spam" => 0]) as $s) {
      switch_to_blog($s->blog_id);
      $n += (int) $h->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}slim_stats");
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
  # D's row-placement assertion, post-fixture: every fixture row lives on db2 (40 counted
  # + archived blog 4's 6), and the WordPress database still has no slim table — the
  # loader wrote through the analytics handle, not around it. (Re-checked again after
  # the probe below, for the read path.)
  if [ "$TOPOLOGY" = "D" ]; then
    ext_rows=$(dc exec -T db2 mysql -uroot -proot slimstat_ext -N -e \
      "SELECT (SELECT COUNT(*) FROM wp_slim_stats) + (SELECT COUNT(*) FROM wp_2_slim_stats)
            + (SELECT COUNT(*) FROM wp_3_slim_stats) + (SELECT COUNT(*) FROM wp_4_slim_stats);" 2>/dev/null | tr -dc '0-9')
    local_after=$(count_slim_tables db wordpress)
    log "[$CELL] D: rows on db2 ${ext_rows:-?} (expect 46) · local slim_ tables after fixture load ${local_after:-?} (expect 0)"
    [ "${ext_rows:-0}" = "46" ] || fail "db2 holds ${ext_rows:-0} fixture rows, expected 46"
    [ "${local_after:-1}" = "0" ] || fail "the LOADER recreated a slim_ table in the WordPress database"
  fi

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

  # D, re-checked AFTER the probe: the report render must not have recreated a slim table
  # in the WordPress database (the C46 fork shape, from the read path this time).
  if [ "$TOPOLOGY" = "D" ]; then
    local_after_probe=$(count_slim_tables db wordpress)
    log "[$CELL] D: local slim_ tables after probe ${local_after_probe:-?} (expect 0)"
    [ "${local_after_probe:-1}" = "0" ] || fail "the probe's render recreated a slim_ table in the WordPress database"
  fi

  # ── slim_p8_01 under network scope (the Run 27/28 harness debt) ───────────────────
  # Hand truths, seeded below on blogs 1 and 2 through the plugin's own handle:
  #   alice — blog 1: two rows, visit 901, 300s · blog 2: one row, visit 903, 200s
  #           → merged pageviews 3, time_on_site 500s = '8m 20s'
  #   bobby — blog 1: one row, visit 902, no duration → pageviews 1, time_on_site 0
  #           (four characters, not three: multisite refuses shorter usernames)
  # A single-blog answer (alice pv 2) cannot equal the merged one (3), so a silently
  # declined scope cannot masquerade as a pass. A divergent reading on an older Pro arm
  # is the recorded RED, not a cell failure — mechanism in probe-uo-network.php's header.
  #
  # Users created in the SAME eval as the rows: they are load-bearing (the report's
  # merge keeps only usernames with a wp_users row), and separate wp-cli round trips
  # cost a full multisite bootstrap each.
  wpc eval '
    foreach (["alice", "bobby"] as $u) {
      if (!get_user_by("login", $u)) { wp_create_user($u, wp_generate_password(), "$u@example.com"); }
    }
    $users = (int) count(array_filter(["alice", "bobby"], fn($u) => false !== get_user_by("login", $u)));
    $h = apply_filters("slimstat_custom_wpdb", $GLOBALS["wpdb"]);
    $seed = [
      1 => [["alice","10.0.9.1","/u1",1700000000,0,901],
            ["alice","10.0.9.1","/u2",1700000300,0,901],
            ["bobby","10.0.9.2","/u3",1700001000,0,902]],
      2 => [["alice","10.0.9.1","/u4",1700010000,1700010200,903]],
    ];
    $n = 0;
    foreach ($seed as $blog_id => $rows) {
      switch_to_blog($blog_id);
      foreach ($rows as $r) {
        $h->insert($GLOBALS["wpdb"]->prefix . "slim_stats",
          ["username" => $r[0], "ip" => $r[1], "resource" => $r[2], "dt" => $r[3], "dt_out" => $r[4], "visit_id" => $r[5]],
          ["%s","%s","%s","%d","%d","%d"]);
        $n++;
      }
      restore_current_blog();
    }
    echo "UO_SEEDED=" . $n . " UO_USERS=" . $users;
  ' > "$ART/uo-seed.log" 2>&1 || fail "uo seed failed"
  uo_seeded=$(sed -n 's/.*UO_SEEDED=\([0-9]*\).*/\1/p' "$ART/uo-seed.log" | head -1)
  uo_users=$(sed -n 's/.*UO_USERS=\([0-9]*\).*/\1/p' "$ART/uo-seed.log" | head -1)
  [ "${uo_seeded:-0}" = "4" ] || fail "uo seed wrote ${uo_seeded:-0} rows, expected 4"
  [ "${uo_users:-0}" = "2" ] || fail "uo seed has ${uo_users:-0} wp_users, expected 2"

  probe_null_control wp-content/plugins/wp-slimstat/tests/docker/probe-uo-network.php \
    "UO-NET-JSON" uo-net

  # D's fork trap, extended over this NEW read path too: the UO render must not have
  # recreated a slim table in the WordPress database (the same C46 re-check the
  # network-view probe gets above — a new probe without it would let a fork answer
  # plausibly).
  if [ "$TOPOLOGY" = "D" ]; then
    local_after_uo=$(count_slim_tables db wordpress)
    log "[$CELL] D: local slim_ tables after uo probe ${local_after_uo:-?} (expect 0)"
    [ "${local_after_uo:-1}" = "0" ] || fail "the uo probe's render recreated a slim_ table in the WordPress database"
  fi
fi

# ── shape assertions ────────────────────────────────────────────────────────
networks=$(wpc eval 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->site}");' 2>/dev/null | tr -dc '0-9' || echo 0)
blogs=$(wpc eval 'echo count(get_sites(["number" => 0, "archived" => null, "deleted" => null, "spam" => null]));' 2>/dev/null | tr -dc '0-9' || echo 1)

case "$TOPOLOGY" in
  A)           [ "${networks:-0}" = "0" ] || fail "topology A must not be a network (wp_site rows: $networks)" ;;
  C-mainonly)  [ "${blogs:-0}" = "1" ]    || fail "C-mainonly expects 1 blog, found $blogs" ;;
  C-subdir|C-subdomain|D)
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
