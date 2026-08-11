#!/usr/bin/env bash
# tests/docker/measure-f6-external.sh <B|D> <free-ref|-> <pro-ref|-> [http_port] [db_port] [db2_port]
#
# F6's measurement: what an install with an EXTERNAL analytics database actually does,
# per arm, on a real second MySQL service.
#
#   Topology B — external DB REACHABLE (service `db2` runs). Probes where the data lands
#     and which reports can still see which database: tracked hits land in db2 and NOT in
#     the WP database; "Your Blog" post/comment counts (queries the defect map shows run
#     on the ANALYTICS handle) versus the real core numbers; the MaxMind panel's
#     slim_ queries (which the defect map shows run on the CORE handle); the UserOverview
#     cross-database join shape against a genuinely separate server.
#
#   Topology D — external DB UNREACHABLE (`db2` never started; the hostname does not
#     resolve). The two security defects sub-seam 1/P9 closed are only exhibitable here:
#       before (pro pre-F6): the analytics schema is CREATED IN THE WORDPRESS DATABASE
#         (the silent fork), and with WP_DEBUG_DISPLAY the page body carries the external
#         HOSTNAME; the site may die entirely on the bail path.
#       after (pro >= a30e765): no slim_ tables in the WP DB, no hostname in any body,
#         HTTP 200 on the front page, a recorded degradation — and hits during the outage
#         are dropped, which P9 prices in the open (they must appear NOWHERE, not in a fork).
#
#   WP_DEBUG_DISPLAY is set TRUE in D cells on purpose: the bail-path disclosure needs it,
#   a real subset of production sites run with it, and the after arm must stay clean even
#   under it. Stated here so the arm difference cannot read as an accident.
#
# One arm per invocation; '-' = working tree. Free ref via git worktree (as measure-d10);
# Pro ref via a worktree handed to build-pro.sh (PRO_SRC_OVERRIDE) so the cell installs
# the real scoped zip of that ref, not source. Counts and presence/absence only — no
# milliseconds.
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

TOPO="${1:?topology (B|D)}"
FREE_REF="${2:--}"
PRO_REF="${3:--}"
HTTP_PORT="${4:-18940}"
DB_PORT="${5:-13940}"
DB2_PORT="${6:-13941}"
PHP="${F6_PHP:-8.2}"
WP="${F6_WP:-6.7}"

CELL="f6${TOPO}-$(echo "$FREE_REF$PRO_REF" | tr -cd '[:alnum:]' | cut -c1-16)"
CELL_DIR="$WORK_ROOT/f6/$CELL"
WP_DIR="$CELL_DIR/wp"
ART="$CELL_DIR/artifacts"
PROJECT="ssf6$(echo "$CELL" | tr -cd '[:alnum:]' | tr '[:upper:]' '[:lower:]')"
BASE_URL="http://127.0.0.1:${HTTP_PORT}"
status="PASS"; reason=""

export COMPOSE_PROJECT_NAME="$PROJECT" PHP_VERSION="$PHP" HTTP_PORT DB_PORT DB2_PORT
export MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.0}"
export CELL_WP_DIR="$WP_DIR"
# B needs the overlay so db2 EXISTS; D must run WITHOUT the overlay so `db2` does not
# resolve at all — an unreachable host, not a stopped container on a known IP.
if [ "$TOPO" = "B" ]; then
  export DC_EXTRA_FILE="$HARNESS_DIR/docker-compose.db2.yml"
fi
rm -rf "$WP_DIR"
mkdir -p "$WP_DIR" "$ART"

fail(){ status="FAIL"; reason="${reason:-$1}"; err "$1"; }

# ── per-ref arms ────────────────────────────────────────────────────────────
FREE_SRC="$PLUGIN_SRC"; FREE_WT=""
if [ "$FREE_REF" != "-" ]; then
  FREE_WT="$CELL_DIR/free-src"; rm -rf "$FREE_WT"
  git -C "$PLUGIN_SRC" worktree add --detach "$FREE_WT" "$FREE_REF" >/dev/null 2>&1 \
    || { err "cannot create free worktree at $FREE_REF"; exit 1; }
  FREE_SRC="$FREE_WT"
fi

PRO_CHECKOUT="$(cd "$PLUGIN_SRC/.." && pwd)/wp-slimstat-pro"
PRO_WT=""
ARM_PRO_ZIP="$HARNESS_DIR/build/wp-slimstat-pro.zip"
if [ "$PRO_REF" != "-" ]; then
  PRO_WT="$CELL_DIR/pro-src"; rm -rf "$PRO_WT"
  git -C "$PRO_CHECKOUT" worktree add --detach "$PRO_WT" "$PRO_REF" >/dev/null 2>&1 \
    || { err "cannot create pro worktree at $PRO_REF"; exit 1; }
  ARM_PRO_ZIP="$HARNESS_DIR/build/wp-slimstat-pro-$PRO_REF.zip"
  PRO_SRC_OVERRIDE="$PRO_WT" PRO_ZIP_OUT="$ARM_PRO_ZIP" bash "$HARNESS_DIR/build-pro.sh" \
    > "$ART/build-pro.log" 2>&1 || { err "pro build at $PRO_REF failed (see build-pro.log)"; exit 1; }
else
  bash "$HARNESS_DIR/build-pro.sh" > "$ART/build-pro.log" 2>&1 \
    || { err "pro build failed (see build-pro.log)"; exit 1; }
fi

finish() {
  scan_debug_log "$WP_DIR" "$ART" || true   # captured for the report; D's before arm MAY hold errors by design
  write_verdict "$ART" "$CELL" "$PHP" "$WP" "$status" "$reason"
  dc down -v --remove-orphans >/dev/null 2>&1 || true
  [ -n "$FREE_WT" ] && git -C "$PLUGIN_SRC" worktree remove --force "$FREE_WT" >/dev/null 2>&1
  [ -n "$PRO_WT" ] && git -C "$PRO_CHECKOUT" worktree remove --force "$PRO_WT" >/dev/null 2>&1
  log "$CELL → $status ${reason:+($reason)}"
}
trap finish EXIT

echo "CONTROLS:"
echo "  topology: $TOPO (db2 $( [ "$TOPO" = "B" ] && echo 'RUNNING' || echo 'ABSENT — hostname must not resolve'))"
if [ -n "$FREE_WT" ]; then echo "  free arm: $(git -C "$FREE_WT" rev-parse --short HEAD) (requested $FREE_REF)"; else echo "  free arm: WORKING TREE ($(git -C "$PLUGIN_SRC" rev-parse --short HEAD)+dirty?)"; fi
if [ -n "$PRO_WT" ]; then echo "  pro arm:  $(git -C "$PRO_WT" rev-parse --short HEAD) (requested $PRO_REF), zip rebuilt for this arm"; else echo "  pro arm:  sibling checkout ($(git -C "$PRO_CHECKOUT" rev-parse --short HEAD))"; fi

log "[$CELL] build + up"
boot_stack "$ART" "$PHP" || { fail "stack did not come up"; exit 1; }
if [ "$TOPO" = "B" ]; then
  wait_for 40 3 dc exec -T db2 mysqladmin ping -h127.0.0.1 -uroot -proot --silent \
    || { fail "db2 did not come up"; exit 1; }
fi

wpc core download --version="$WP" --force > "$ART/install.log" 2>&1 || { fail "core download failed"; exit 1; }
wp_config_debug "$ART/install.log"
if [ "$TOPO" = "D" ]; then
  # The disclosure path needs display; a real subset of sites run with it. Stated above.
  wpc config set WP_DEBUG_DISPLAY true --raw --type=constant >>"$ART/install.log" 2>&1
fi
wpc core install --url="$BASE_URL" --title="F6 $CELL" --admin_user=admin \
    --admin_password=admin --admin_email=qa@example.com --skip-email >>"$ART/install.log" 2>&1 \
    || { fail "core install failed"; exit 1; }

# Real core content for the "Your Blog" numbers.
for i in 1 2 3; do
  wpc post create --post_title="post-$i" --post_status=publish >>"$ART/install.log" 2>&1
done
wpc comment create --comment_post_ID=1 --comment_content="c1" --comment_approved=1 >>"$ART/install.log" 2>&1

sync_plugin_src "$WP_DIR" "$FREE_SRC"
mkdir -p "$WP_DIR/wp-content/plugins/.pro"
cp "$ARM_PRO_ZIP" "$WP_DIR/wp-content/plugins/.pro/wp-slimstat-pro.zip"
chmod -R a+rwX "$WP_DIR/wp-content" 2>/dev/null || true

wpc plugin activate wp-slimstat >>"$ART/install.log" 2>&1 || { fail "free activation failed"; exit 1; }
wpc plugin install /var/www/html/wp-content/plugins/.pro/wp-slimstat-pro.zip --activate --force \
    >>"$ART/activate.log" 2>&1 || { fail "pro install failed"; exit 1; }

# Point the addon at db2 AFTER activation (activation itself must run on the local DB so
# the cell reaches a known state), then server-side tracking so a cookieless curl is a hit.
wpc eval '
  $s = get_option("slimstat_options", []);
  $s["addon_custom_db_enable"] = "on";
  $s["addon_custom_db_dbhost"] = "db2";
  $s["addon_custom_db_dbname"] = "slimstat_ext";
  $s["addon_custom_db_dbuser"] = "root";
  $s["addon_custom_db_dbpass"] = "root";
  $s["javascript_mode"] = "no";
  $s["is_tracking"] = "on";
  update_option("slimstat_options", $s);
  echo "settings: host=db2 name=slimstat_ext topo='"$TOPO"'";
' > "$ART/settings.log" 2>&1 || fail "settings update failed"
cat "$ART/settings.log"; echo ""

# The external tables exist only if something creates them there: run the activation-path
# repair once ON PURPOSE (B only) — this is the admin path that legitimately owns DDL.
if [ "$TOPO" = "B" ]; then
  wpc eval 'include_once WP_PLUGIN_DIR . "/wp-slimstat/admin/index.php"; wp_slimstat_admin::init_environment(); echo "env init ran";' \
    >> "$ART/settings.log" 2>&1 || fail "init_environment failed"
fi

# CONFOUND REMOVED (D only). Plain `plugin activate` above created the slim_ tables in the
# WORDPRESS database while the add-on was still OFF — legitimate, and present in BOTH arms,
# so a raw table count there measures activation, not the fork. To make the FORK the only
# thing that can put tables back, drop them now, with the add-on ON and pointed at the dead
# host. Then exercise every path that could recreate them: an admin visit (the missing-
# tables reconciler) and tracked hits (the tracker's own repair). Any slim_ table in the WP
# DB afterwards is the fork — before: the whole schema returns; after (P9): nothing does.
if [ "$TOPO" = "D" ]; then
  # FK checks off: slim_events has a foreign key to slim_stats, so a naive drop order
  # leaves the parent behind (the guard below caught exactly that). Order-independent.
  dc exec -T db mysql -uroot -proot wordpress -e "SET FOREIGN_KEY_CHECKS=0;
    DROP TABLE IF EXISTS wp_slim_events, wp_slim_events_archive, wp_slim_stats, wp_slim_stats_archive, wp_slim_user_agents;
    SET FOREIGN_KEY_CHECKS=1;" >/dev/null 2>&1
  dropped=$(dc exec -T db mysql -uroot -proot wordpress -N -e \
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='wordpress' AND TABLE_NAME LIKE 'wp\\_slim\\_%';" 2>/dev/null | tr -dc '0-9')
  echo "  [D] dropped local slim_ tables; WP DB now holds ${dropped:-?} (expect 0) before the fork test"
  [ "${dropped:-1}" = "0" ] || fail "could not drop local slim_ tables — the fork test would be confounded"
  # The admin reconciler path: an authenticated wp-admin visit runs update_tables_and_options().
  curl -s -o /dev/null -A "F6-probe/1.0" "$BASE_URL/wp-admin/admin.php?page=slimview1" >> "$ART/headers.log" 2>&1 || true
fi

# ── the storm: three cookieless front-end hits ──────────────────────────────
for i in 1 2 3; do
  curl -s -D - -o /dev/null -A "F6-probe/1.0" "$BASE_URL/?p=1&hit=$i" >> "$ART/headers.log" 2>&1
  sleep 1
done
FRONT_BODY="$ART/front-body.html"
curl -s -A "F6-probe/1.0" "$BASE_URL/" > "$FRONT_BODY" 2>&1
FRONT_CODE=$(curl -s -o /dev/null -w '%{http_code}' -A "F6-probe/1.0" "$BASE_URL/")

# ── where did everything land? ──────────────────────────────────────────────
wp_slim_tables=$(dc exec -T db mysql -uroot -proot wordpress -N -e \
  "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='wordpress' AND TABLE_NAME LIKE 'wp\\_slim\\_%';" 2>/dev/null | tr -dc '0-9')
echo "  slim_ tables in the WORDPRESS database: ${wp_slim_tables:-?}"

if [ "$TOPO" = "B" ]; then
  ext_tables=$(dc exec -T db2 mysql -uroot -proot slimstat_ext -N -e \
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='slimstat_ext' AND TABLE_NAME LIKE 'wp\\_slim\\_%';" 2>/dev/null | tr -dc '0-9')
  ext_rows=$(dc exec -T db2 mysql -uroot -proot slimstat_ext -N -e \
    "SELECT COUNT(*) FROM wp_slim_stats;" 2>/dev/null | tr -dc '0-9')
  echo "  slim_ tables in the EXTERNAL database: ${ext_tables:-?} · tracked rows there: ${ext_rows:-?}"

  # "Your Blog": the report's own function, then the independent truth from the core handle.
  wpc eval '
    include_once WP_PLUGIN_DIR . "/wp-slimstat/wp-slimstat.php";
    include_once WP_PLUGIN_DIR . "/wp-slimstat/admin/view/wp-slimstat-db.php";
    wp_slimstat_db::init();
    $yb = wp_slimstat_db::get_your_blog();
    global $wpdb;
    $posts_truth    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=\"post\" AND post_status=\"publish\"");
    $comments_truth = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved=1");
    printf("  your_blog: %s\n  core truth: posts=%d comments=%d\n", json_encode($yb), $posts_truth, $comments_truth);
  ' 2>&1 | tee "$ART/yourblog.log" | head -6

  # The MaxMind panel mechanism: its slim_ queries run on the CORE handle.
  core_slim=$(wpc eval 'global $wpdb; $v = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}slim_stats"); echo (null === $v) ? "ERR:" . $wpdb->last_error : $v;' 2>/dev/null | head -c 120)
  echo "  MaxMind mechanism (slim_stats via CORE handle): ${core_slim}"

  # The UserOverview mechanism: one statement naming the core schema, run on the ANALYTICS handle.
  cross=$(wpc eval '
    include_once WP_PLUGIN_DIR . "/wp-slimstat/wp-slimstat.php";
    wp_slimstat::init();
    global $wpdb;
    $sql = "SELECT COUNT(*) FROM `{$wpdb->dbname}`.{$wpdb->users}";
    $v = wp_slimstat::$wpdb->get_var($sql);
    echo (null === $v) ? "ERR:" . wp_slimstat::$wpdb->last_error : "OK:" . $v;
  ' 2>/dev/null | head -c 160)
  echo "  UserOverview mechanism (core-schema table via ANALYTICS handle): ${cross}"
fi

# ── disclosure + liveness ───────────────────────────────────────────────────
echo "  front page HTTP: ${FRONT_CODE}"
if grep -qi "db2" "$FRONT_BODY"; then
  echo "  DISCLOSURE: the page body contains the external hostname 'db2'"
else
  echo "  disclosure check: hostname absent from the page body"
fi
grep -ci "error establishing" "$FRONT_BODY" | sed 's/^/  "error establishing" occurrences in body: /'

# ── degradations recorded ───────────────────────────────────────────────────
wpc eval '$d = get_option("slimstat_degradations", []); echo "  degradations: " . json_encode(array_keys(is_array($d) ? $d : []));' 2>/dev/null || true
echo ""
echo "RESULT ($CELL): artifacts in $ART"
