#!/usr/bin/env bash
# tests/docker/measure-f6-useroverview.sh <pro-ref|-> [http_port] [db_port] [db2_port]
#
# F6 sub-seam 2's owed measurement for the UserOverview cross-database join split: the
# SAME report probed in the three database layouts a real install can have, one Pro arm
# per invocation, answers dumped as keyed JSON by tests/docker/probe-user-overview.php.
#
#   state `local`    — add-on OFF. Everything in the WordPress database. The join works
#                      today; the split must return IDENTICAL rows (the parity claim for
#                      almost every real install).
#   state `samehost` — add-on ON, analytics in a SECOND DATABASE on the SAME MySQL
#                      instance. `{dbname}.wp_users INNER JOIN slim_stats` still resolves
#                      here, so this is the strongest parity arm: distinct handles,
#                      distinct schemas, answers must not move.
#   state `separate` — add-on ON, analytics on a SECOND SERVER (db2). The join cannot
#                      resolve: before the split the report is expected BROKEN (this run
#                      records that RED), after it must equal the hand-computed truth.
#
# The seeded corpus is FIXED, so the expected answer is hand-computable (the golden-
# fixture stance — an oracle independent of both arms):
#
#   user  admin — 2 pageviews (visit 101), 1 login note, last_login_ts 1700000000,
#                 time_on_site 300s → '5m'
#   user  alice — 3 pageviews: two rows 'alice' + one row 'Alice' (the ci-collation join
#                 merges case variants; the split must reproduce that), time_on_site
#                 200s (visit 201) → '3m 20s', never logged in
#   user  bob   — 1 pageview, no duration (single hit), never logged in
#   user  carol — a real user with ZERO pageviews (the report's no-pageviews branch)
#   'ghost'     — slim rows with NO matching wp_users row: excluded (the INNER JOIN
#                 semantics the split's PHP merge must keep)
#   NULL row    — one username-less hit: appears nowhere
#
# Counts and presence/absence only — no milliseconds. The script's own VERDICT covers
# environmental integrity (stack, activation, seeds, null controls); the report-level
# claims are adjudicated OUTSIDE it from the emitted JSON artifacts.
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

PRO_REF="${1:--}"
HTTP_PORT="${2:-18950}"
DB_PORT="${3:-13950}"
DB2_PORT="${4:-13951}"
PHP="${F6_PHP:-8.2}"
WP="${F6_WP:-6.7}"

CELL="f6uo-$(echo "$PRO_REF" | tr -cd '[:alnum:]' | cut -c1-12)"
CELL_DIR="$WORK_ROOT/f6/$CELL"
WP_DIR="$CELL_DIR/wp"
ART="$CELL_DIR/artifacts"
PROJECT="ssf6$(echo "$CELL" | tr -cd '[:alnum:]' | tr '[:upper:]' '[:lower:]')"
BASE_URL="http://127.0.0.1:${HTTP_PORT}"
status="PASS"; reason=""

export COMPOSE_PROJECT_NAME="$PROJECT" PHP_VERSION="$PHP" HTTP_PORT DB_PORT DB2_PORT
export MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.0}"
export CELL_WP_DIR="$WP_DIR"
# db2 always runs: state `separate` needs it, and its idle presence cannot affect the
# other two states (nothing points at it until the add-on settings do).
export DC_EXTRA_FILE="$HARNESS_DIR/docker-compose.db2.yml"
rm -rf "$WP_DIR"
mkdir -p "$WP_DIR" "$ART"

# ── the Pro arm (fail/build_pro_arm/cleanup_pro_arm: lib.sh) ────────────────
build_pro_arm "$PRO_REF" "$CELL_DIR" "$ART" || exit 1

finish() {
  scan_debug_log "$WP_DIR" "$ART" || true
  write_verdict "$ART" "$CELL" "$PHP" "$WP" "$status" "$reason"
  dc down -v --remove-orphans >/dev/null 2>&1 || true
  cleanup_pro_arm
  log "$CELL → $status ${reason:+($reason)}"
}
trap finish EXIT

echo "CONTROLS:"
echo "  free: WORKING TREE ($(git -C "$PLUGIN_SRC" rev-parse --short HEAD)) — identical in both arms by construction"
if [ -n "$PRO_WT" ]; then echo "  pro arm: $(git -C "$PRO_WT" rev-parse --short HEAD) (requested $PRO_REF), zip rebuilt for this arm"; else echo "  pro arm: sibling checkout ($(git -C "$PRO_CHECKOUT" rev-parse --short HEAD)${PRO_REF:+, working tree})"; fi
echo "  states: local (addon off) · samehost (2nd DB, same instance) · separate (db2, 2nd server)"

log "[$CELL] build + up"
boot_stack "$ART" "$PHP" || { fail "stack did not come up"; exit 1; }
wait_for 40 3 dc exec -T db2 mysqladmin ping -h127.0.0.1 -uroot -proot --silent \
  || { fail "db2 did not come up"; exit 1; }

wpc core download --version="$WP" --force > "$ART/install.log" 2>&1 || { fail "core download failed"; exit 1; }
wp_config_debug "$ART/install.log"
wpc core install --url="$BASE_URL" --title="F6 $CELL" --admin_user=admin \
    --admin_password=admin --admin_email=qa@example.com --skip-email >>"$ART/install.log" 2>&1 \
    || { fail "core install failed"; exit 1; }

# The report's subjects: three more real users, one of whom will have no pageviews.
for u in alice bob carol; do
  wpc user create "$u" "$u@example.com" --role=subscriber >>"$ART/install.log" 2>&1 \
    || fail "user create $u failed"
done

sync_plugin_src "$WP_DIR"
mkdir -p "$WP_DIR/wp-content/plugins/.pro"
cp "$ARM_PRO_ZIP" "$WP_DIR/wp-content/plugins/.pro/wp-slimstat-pro.zip"
chmod -R a+rwX "$WP_DIR/wp-content" 2>/dev/null || true

wpc plugin activate wp-slimstat >>"$ART/install.log" 2>&1 || { fail "free activation failed"; exit 1; }
wpc plugin install /var/www/html/wp-content/plugins/.pro/wp-slimstat-pro.zip --activate --force \
    >>"$ART/activate.log" 2>&1 || { fail "pro install failed"; exit 1; }

# ── fixed corpus, applied to whichever database is the analytics one per state ──────
SEED_SQL="INSERT INTO wp_slim_stats (ip, username, dt, dt_out, visit_id, notes, resource) VALUES
('1.1.1.1','admin',1700000000,1700000300,101,'[loggedin:admin]','/a'),
('1.1.1.1','admin',1700000300,0,101,NULL,'/b'),
('2.2.2.2','alice',1700010000,0,201,NULL,'/a'),
('2.2.2.2','alice',1700010060,1700010200,201,NULL,'/c'),
('2.2.2.3','Alice',1700020000,0,202,NULL,'/a'),
('3.3.3.3','bob',1700030000,0,301,NULL,'/d'),
('4.4.4.4','ghost',1700040000,0,401,NULL,'/e'),
('5.5.5.5',NULL,1700050000,0,501,NULL,'/f');"

seed_analytics() { # svc dbname
  local svc="$1" db="$2" n
  dc exec -T "$svc" mysql -uroot -proot "$db" -e "$SEED_SQL" >/dev/null 2>&1 \
    || { fail "seed into $svc/$db failed"; return 1; }
  n=$(dc exec -T "$svc" mysql -uroot -proot "$db" -N -e \
      "SELECT COUNT(*) FROM wp_slim_stats;" 2>/dev/null | tr -dc '0-9')
  echo "  seeded $svc/$db: wp_slim_stats holds ${n:-?} rows (expect 8)"
  [ "${n:-0}" = "8" ] || fail "seed row count ${n:-?} != 8 in $svc/$db"
}

run_probe() { # state-name
  local name="$1" f1 f2
  f1="$ART/uo-$name-1.json"; f2="$ART/uo-$name-2.json"
  for run in 1 2; do
    wpc eval-file /var/www/html/wp-content/plugins/wp-slimstat/tests/docker/probe-user-overview.php \
      > "$ART/probe-$name-run$run.out" 2>&1 || fail "probe $name run $run errored"
    awk '/^UO-JSON-BEGIN$/{f=1;next} /^UO-JSON-END$/{f=0} f' "$ART/probe-$name-run$run.out" \
      > "$ART/uo-$name-$run.json"
    [ -s "$ART/uo-$name-$run.json" ] || fail "probe $name run $run produced no JSON"
  done
  # Null control: a deterministic probe answering twice must answer identically.
  if cmp -s "$f1" "$f2"; then
    echo "  [$name] null control: two runs byte-identical ($(wc -c < "$f1" | tr -d ' ') bytes)"
    cp "$f1" "$ART/uo-$name.json"
  else
    fail "[$name] null control FAILED — same arm, same state, two different answers"
    diff "$f1" "$f2" | head -20
  fi
  grep -E '^  (handles|corpus|window)' "$ART/probe-$name-run1.out" | sed "s/^/  [$name]/"
  php -r '$j=json_decode(file_get_contents($argv[1]),true); printf("  [%s] rows=%d error=%s analytics_err=%s\n  [%s] usernames: %s\n", $argv[2], $j["row_count"], var_export($j["error"],true), $j["analytics_last_err"]===""?"none":substr($j["analytics_last_err"],0,90), $argv[2], implode(",", array_keys($j["rows_by_username"])));' \
    "$f1" "$name" 2>/dev/null || true
}

set_addon() { # host dbname (empty host = disable)
  local host="$1" db="$2"
  wpc eval '
    $s = get_option("slimstat_options", []);
    if ("'"$host"'" === "") { $s["addon_custom_db_enable"] = "no"; }
    else {
      $s["addon_custom_db_enable"] = "on";
      $s["addon_custom_db_dbhost"] = "'"$host"'";
      $s["addon_custom_db_dbname"] = "'"$db"'";
      $s["addon_custom_db_dbuser"] = "root";
      $s["addon_custom_db_dbpass"] = "root";
    }
    update_option("slimstat_options", $s);
    echo "addon: " . $s["addon_custom_db_enable"] . " host=" . ($s["addon_custom_db_dbhost"] ?? "-") . " db=" . ($s["addon_custom_db_dbname"] ?? "-");
  ' >> "$ART/settings.log" 2>&1 || fail "addon settings update failed"
  tail -1 "$ART/settings.log" | sed 's/^/  /'
}

init_env() {
  wpc eval 'include_once WP_PLUGIN_DIR . "/wp-slimstat/admin/index.php"; wp_slimstat_admin::init_environment(); echo "env init ran";' \
    >> "$ART/settings.log" 2>&1 || fail "init_environment failed"
}

# ── state `local`: add-on OFF, everything in the WordPress database ─────────────────
log "[$CELL] state local"
echo "STATE local:"
seed_analytics db wordpress
run_probe local

# ── state `samehost`: second database, same MySQL instance ──────────────────────────
log "[$CELL] state samehost"
echo "STATE samehost:"
dc exec -T db mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS slimstat_same;" >/dev/null 2>&1 \
  || fail "cannot create slimstat_same"
set_addon db slimstat_same
init_env
seed_analytics db slimstat_same
run_probe samehost

# ── state `separate`: second server ─────────────────────────────────────────────────
log "[$CELL] state separate"
echo "STATE separate:"
set_addon db2 slimstat_ext
init_env
seed_analytics db2 slimstat_ext
run_probe separate

echo ""
echo "RESULT ($CELL): artifacts in $ART"
