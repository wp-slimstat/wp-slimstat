#!/usr/bin/env bash
# tests/docker/rehearse-upgrade.sh <old-ref> <new-ref> [dump.sql.gz] [http_port] [db_port]
#
#   SCENARIO=U1       default U1, and U1 is the only one ACCEPTED — see the refusal below.
#                     Each scenario derives its own cell directory, compose project and default
#                     ports, so two no longer share a cell directory or a compose project.
#                     (NOT a concurrency claim: two rehearsals still share one git worktree
#                     registry in $PLUGIN_SRC, and use_ref() prunes it. Untested, so unclaimed.)
#                     Before this, CELL and COMPOSE_PROJECT_NAME were constants and a second
#                     run silently overwrote the first's artifacts. The scenario names come from
#                     jaan-to/outputs/dev/v6-performance/NEXT-SESSION.md (B1/B5); note that
#                     record says "one of seven" while enumerating six.
#
# WHAT HAPPENS TO A REAL SITE'S DATA WHEN IT UPDATES, rehearsed on that site's real data.
#
# ── Why this exists ─────────────────────────────────────────────────────────────────────────
#
# VERIFICATION-PROTOCOL.md has said "the v5-schema docker cell owed since Run 45 remains owed"
# since Run 45. Every campaign cell so far seeds a synthetic corpus and starts at the NEW schema,
# so the one path 70,000 installs will actually take — v5 tables, full of v5 rows, meeting v6
# code — has never been executed. The two E2E specs named "upgrade" test 5.4.1 -> 5.4.2 and a
# geolocation refactor.
#
# The distinction that makes this a different cell rather than a flag on an existing one: every
# other cell asks whether the NEW code is right. This asks whether the TRANSITION is safe, which
# is a property of two versions and a migration, not of one version.
#
# ── What it asserts, in the order a site experiences it ─────────────────────────────────────
#
#   R1  OLD code, real data     a fingerprint over the v5 columns, scoped to the rows present now
#   R2  NEW code, no migration  the DEFERRED WINDOW: a tracked pageview must still land, exactly
#                               once, with no per-hit Unknown-column error
#   R3  migrate                 every required migration true, the added column present, no row
#                               lost, and the v5 fingerprint UNCHANGED — the migration adds,
#                               it does not rewrite
#   R4  idempotence             a second run issues no ALTER
#   R5  kill switch             SLIMSTAT_DISABLE_MIGRATIONS refuses, and tracking still lands
#   R7  rollback                the OLD code on the migrated schema still tracks, and the v5
#                               values are still intact
#
# CONTROLS come first and are printed before any result, per the programme's standing rule.
#
# ── What C4 does and does NOT establish, stated because the difference matters ───────────────
#
# C4 drops the column the migration added and re-asserts the two checks that should notice. That
# proves the CHECKS are live — the column probe reports absent when the column is absent, and the
# fingerprint does NOT move, which is what its v5 scoping requires. It is the answer to "would
# these assertions have noticed anything at all".
#
# It is NOT the stronger control, which would be a deliberately broken MIGRATION — one that drops
# a row, truncates a value or half-applies — driven through runAll() and required to turn the
# cell red. That belongs in the mutation registry, against this script as a target, and it is not
# here yet. Recorded rather than implied.
#
# ── Not covered by this cell ─────────────────────────────────────────────────────────────────
#
# Report ANSWERS before and after the migration are not diffed here; that is what the sealed
# comparison does, over a corpus built to discriminate. This cell is about the data surviving,
# not about what the reports say afterwards. Also absent: older schema vintages (4.8.x, 5.2.x),
# scale beyond 443k, Pro alongside, multisite, and an external analytics database. Each is a
# separate scenario and none of them is claimed by a green run of this one.
#
# The dump defaults to the newest under ~/slimstat-v6-baselines/. `slimstat-db.sh dump` writes
# there, so the default is "this workspace's live site as it stands", which is a genuine
# deferred-window install: v6 code, v5 schema, 443,543 rows, no vid_hash and no ua_id.
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
[ -f "$HARNESS_DIR/matrix.env" ] && source "$HARNESS_DIR/matrix.env"

OLD_REF="${1:?old ref (the version a site is updating FROM)}"
NEW_REF="${2:?new ref (the version it is updating TO)}"
DUMP="${3:-}"

# ── Scenario ────────────────────────────────────────────────────────────────────────────────
# The plan has named U1..U6 since it was written; the script had ONE linear scenario and two
# hard-coded constants (CELL, COMPOSE_PROJECT_NAME), so "run U4" was never a thing you could
# type. Validated against a closed set rather than accepted as free text: an unknown value used
# to derive a directory and a port would otherwise produce a cell that runs and means nothing.
# A name is not a scenario. SCENARIO reaches exactly three things -- CELL, the compose project
# and the two default ports -- and NO leg, assertion or overlay branches on it. So U2..U6 would
# have run U1's R1-R7 verbatim and written {"cell":"upgrade-u5","status":"PASS"} to cell.json
# under the name of a scenario that never executed. That is PITFALLS 86 one altitude up, in the
# file PITFALLS 86 is about. Each name moves to the accepting arm when it has legs of its own
# AND an assertion only it can fail -- the bar run-topology.sh:382 already sets for its shapes.
SCENARIO="${SCENARIO:-U1}"
case "$SCENARIO" in
  U1) ;;
  U2|U3|U4|U5|U6)
    err "SCENARIO $SCENARIO is named in the plan but NOT implemented here: this script runs U1's"
    err "  legs only, so it would print a PASS and file a verdict under $SCENARIO's name."
    exit 2 ;;
  *) err "unknown SCENARIO '$SCENARIO' — expected one of U1 U2 U3 U4 U5 U6"; exit 2 ;;
esac
# exit 2 = unusable input, matching seal.sh:94, canary/run-canary.sh:256 and
# reachability/run-gate.sh:171; exit 1 in this file means a leg failed.
SCEN_N=$(( ${SCENARIO#U} - 1 ))
# Pure expansion, not `tr`: the slug is the number in different clothes, and this makes that
# visible. Lowercase because Compose REJECTS an uppercase project name outright
# (run-topology.sh:36-38 records the same reason).
SCEN_SLUG="u${SCENARIO#U}"

# U1 keeps the ports the recorded run used, so its reproduction is not confounded by a changed
# environment; the others step off it. Explicit args still win. Stride 1 is safe only because a
# second database port is allocated at DB_PORT+500, not DB_PORT+1 -- U6 (external analytics DB)
# needs one, and at +1 U1's second DB would land on U2's first. run-topology.sh:50 owns that
# convention; reuse it there rather than adding a third.
HTTP_PORT="${4:-$(( 18980 + SCEN_N ))}"
DB_PORT="${5:-$(( 13980 + SCEN_N ))}"
PHP="${TOPOLOGY_PHP:-8.2}"
WP="${TOPOLOGY_WP:-6.7}"

if [ -z "$DUMP" ]; then
  DUMP=$(ls -t "$HOME"/slimstat-v6-baselines/slim-analytics-*.sql.gz 2>/dev/null | head -1)
fi
[ -n "$DUMP" ] && [ -f "$DUMP" ] || { err "no dump: pass one, or run jaan-to/bin/slimstat-db.sh dump"; exit 1; }

CELL="upgrade-$SCEN_SLUG"
CELL_DIR="$WORK_ROOT/rehearse/$CELL"
WP_DIR="$CELL_DIR/wp"
ART="$CELL_DIR/artifacts"
BASE_URL="http://127.0.0.1:${HTTP_PORT}"

export COMPOSE_PROJECT_NAME="ssrehearse$SCEN_SLUG" PHP_VERSION="$PHP" HTTP_PORT DB_PORT
export MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.0}"
export CELL_WP_DIR="$WP_DIR"

status="PASS"; reason=""
cleanup() { [ "${KEEP_CELL:-0}" = "1" ] || dc down -v --remove-orphans >/dev/null 2>&1 || true; }
trap cleanup EXIT

rm -rf "$WP_DIR" "$ART"; mkdir -p "$WP_DIR" "$ART" "$CELL_DIR/arms"

note() { printf '  [%s] %s\n' "$1" "$2"; }
check() { # <label> <condition-exit> <detail>
  if [ "$2" -eq 0 ]; then note PASS "$1${3:+ — $3}"; else note FAIL "$1${3:+ — $3}"; fail "$1"; fi
}

# The v5 projection. Only columns that exist BEFORE the migration and that the migration must
# not touch — so the same expression is computable on both sides of it, which is the whole point.
# CRC32 over a NUL-joined tuple: order-independent via SUM, and NULL-safe via COALESCE, because
# CONCAT_WS skips NULLs and would make ('a',NULL,'b') and ('a','b',NULL) identical.
# Scoped to `id <= BASE_MAX_ID`, pinned once at R1, and the first run of this cell is why. The
# rehearsal tracks a pageview during the deferred window, so an unscoped fingerprint compares
# 443,543 rows before the migration with 443,544 after it and reports the migration changed the
# data — when what changed it was the test. The subject is "did the rows that were already there
# survive", so the row set has to be the rows that were already there.
FP_SQL_TEMPLATE="SELECT COUNT(*), SUM(CRC32(CONCAT_WS(CHAR(0),
          COALESCE(id,'~'), COALESCE(ip,'~'), COALESCE(resource,'~'), COALESCE(dt,'~'),
          COALESCE(visit_id,'~'), COALESCE(browser,'~'), COALESCE(country,'~'),
          COALESCE(referer,'~'), COALESCE(notes,'~')))) FROM wordpress.wp_slim_stats WHERE id <= %s"
BASE_MAX_ID=""

mysql_q() { dc exec -T db mysql -uroot -proot -N -e "$1" 2>/dev/null; }
fingerprint() {
  [ -n "$BASE_MAX_ID" ] || { echo "unpinned"; return; }
  mysql_q "$(printf "$FP_SQL_TEMPLATE" "$BASE_MAX_ID")" | tr -d '\r' | tr '\t' ':'
}
row_count() { mysql_q "SELECT COUNT(*) FROM wordpress.wp_slim_stats;" | tr -d '[:space:]'; }
has_column() { mysql_q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='wordpress' AND TABLE_NAME='wp_slim_stats' AND COLUMN_NAME='$1';" | tr -d '[:space:]'; }

use_ref() { # <ref>
  # Two statements, not one `local a= b=$a`: bash expands the whole assignment list before
  # binding any of it, so the second reference is unset under `set -u`.
  local ref="$1"
  local dir="$CELL_DIR/arms/$ref"
  # R7 switches BACK to the old ref, so this runs twice for the same path. `rm -rf` alone leaves
  # git's registration behind and the re-add then fails with "already exists" — which reads as
  # "cannot create a worktree at <ref>" and looks like a bad ref rather than a stale handle.
  git -C "$PLUGIN_SRC" worktree remove --force "$dir" >/dev/null 2>&1 || true
  rm -rf "$dir"
  git -C "$PLUGIN_SRC" worktree prune >/dev/null 2>&1 || true
  git -C "$PLUGIN_SRC" worktree add --detach "$dir" "$ref" >/dev/null 2>&1 \
    || { err "cannot create a worktree at $ref"; return 1; }
  ( cd "$dir" && composer run build:autoload >/dev/null 2>&1 ) \
    || { err "could not rebuild the autoloader at $ref — that arm would boot with the wrong classmap"; return 1; }
  sync_plugin_src "$WP_DIR" "$dir"
}
drop_ref() { git -C "$PLUGIN_SRC" worktree remove --force "$CELL_DIR/arms/$1" >/dev/null 2>&1 || true; }

# A tracked hit through the REAL path: the tracker's own entry point, not an INSERT.
track_hit() { # <marker>
  # REQUEST_URI, not an argument. `wp_slimstat::slimtrack()` declares ZERO parameters, so the
  # array this used to pass was discarded silently and all three hits were the same anonymous
  # request — the marker never reached the database and the assertions below could not tell
  # which row they had made. This repo ships tests/surplus-argument-scan-test.php for exactly
  # that defect class, and it tokenises admin/ and src/, so a call inside a shell string here is
  # outside its reach twice over.
  wpc eval "
    \$_SERVER['HTTP_USER_AGENT']='Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120 Safari/537.36';
    \$_SERVER['REMOTE_ADDR']='203.0.113.7';
    \$_SERVER['HTTP_REFERER']='https://example.com/from';
    \$_SERVER['REQUEST_URI']='/$1';
    \$id = wp_slimstat::slimtrack();
    echo is_numeric(\$id) ? \$id : 0;
  " 2>/dev/null | tr -d '[:space:]'
}

# The row a track_hit() claims to have written, read back by id.
hit_resource() { mysql_q "SELECT resource FROM wordpress.wp_slim_stats WHERE id=$1;" | tr -d '[:space:]'; }

log "[$CELL] build + up (PHP $PHP, WP $WP)"
boot_stack "$ART" "$PHP" || { err "stack did not come up"; exit 1; }

echo
echo "CONTROLS"
echo "  dump:        $(basename "$DUMP")"
echo "  old ref:     $OLD_REF"
echo "  new ref:     $NEW_REF"

# ── C1: the dump is a genuine v5 schema ─────────────────────────────────────
# Asserted on the FILE, before anything imports it. A dump that already carried the v6 columns
# would make every assertion below vacuous while looking identical in the output.
gz_has() { gzip -dc "$DUMP" | grep -qE "$1"; }
gz_has '`vid_hash`|`ua_id`' && { err "the dump already carries v6 columns — nothing here could fail"; exit 1; }
check "the dump is a pre-migration v5 schema" 0 "no vid_hash, no ua_id"

use_ref "$OLD_REF" || exit 1
provision_wp_cell "$ART" "$WP" "$BASE_URL" "$CELL_DIR/arms/$OLD_REF" || exit 1
wpc eval 'include_once(WP_PLUGIN_DIR."/wp-slimstat/admin/index.php"); wp_slimstat_admin::init_tables($GLOBALS["wpdb"]); echo "t";' >>"$ART/install.log" 2>&1

log "[$CELL] hydrating $(basename "$DUMP")"
gzip -dc "$DUMP" | dc exec -T db mysql -uroot -proot wordpress 2>"$ART/import.err" \
  || { err "hydration failed — see $ART/import.err"; exit 1; }

ROWS_0=$(row_count)
# C2: the corpus is the real one. COUNT(*), never information_schema.TABLE_ROWS, which is an
# estimate and reported 427,582 for this same table once.
[ "${ROWS_0:-0}" -gt 400000 ] && check "the corpus is the real dataset" 0 "$ROWS_0 rows" \
  || check "the corpus is the real dataset" 1 "only ${ROWS_0:-0} rows"

# C3: the columns the migration will add are absent NOW.
check "vid_hash is absent before the migration" "$([ "$(has_column vid_hash)" = 0 ] && echo 0 || echo 1)"
check "ua_id is absent before the migration"    "$([ "$(has_column ua_id)" = 0 ] && echo 0 || echo 1)"

echo
echo "── R1 · baseline under the OLD code ─────────────────────────────────────"
BASE_MAX_ID=$(mysql_q "SELECT MAX(id) FROM wordpress.wp_slim_stats;" | tr -d '[:space:]')
[ -n "$BASE_MAX_ID" ] || { err "could not pin the baseline row set"; exit 1; }
FP_0=$(fingerprint)
echo "  v5 fingerprint over id <= $BASE_MAX_ID: $FP_0"
scan_debug_log "$WP_DIR" "$ART" >/dev/null 2>&1 || true

echo
echo "── R2 · the DEFERRED WINDOW: v6 code, v5 schema, no migration yet ───────"
use_ref "$NEW_REF" || exit 1
HIT_1=$(track_hit "rehearse-deferred-window")
[ "${HIT_1:-0}" -gt 0 ] && check "a pageview still lands before any migration" 0 "row id $HIT_1" \
  || check "a pageview still lands before any migration" 1 "slimtrack returned ${HIT_1:-nothing}"
# THE ROW IT CLAIMS, read back. Without this the leg proves a row appeared, not that it is the
# row this cell asked for — and an id plus a COUNT delta of 1 is satisfied by any insert at all.
RES_1=$(hit_resource "${HIT_1:-0}")
[ "$RES_1" = "/rehearse-deferred-window" ] && check "the row it wrote is the row it asked for" 0 "$RES_1" \
  || check "the row it wrote is the row it asked for" 1 "resource is '${RES_1:-empty}'"

ROWS_1=$(row_count)
[ "$ROWS_1" -eq $((ROWS_0 + 1)) ] && check "it landed exactly once" 0 "$ROWS_0 -> $ROWS_1" \
  || check "it landed exactly once" 1 "$ROWS_0 -> $ROWS_1"

NEEDS=$(wpc eval '
  $a = SlimStat\Migration\MigrationService::analyticsConnection();
  $m = new SlimStat\Migration\MigrationManager();
  foreach ([new SlimStat\Migration\Migrations\AddVisitIdentity($a,$GLOBALS["wpdb"]),
            new SlimStat\Migration\Migrations\AddUserAgentDimension($a,$GLOBALS["wpdb"]),
            new SlimStat\Migration\Migrations\ConvertTablesToUtf8mb4($a,$GLOBALS["wpdb"])] as $x) { $m->register($x); }
  $m->forgetProbe();
  echo $m->needsMigration() ? "yes" : "no";
' 2>/dev/null | tr -d '[:space:]')
[ "$NEEDS" = "yes" ] && check "the site is offered a migration" 0 "needsMigration() is true" \
  || check "the site is offered a migration" 1 "needsMigration() is $NEEDS"

# NO `|| echo 0`. `grep -c` prints 0 AND exits 1 when a file has no matches, so the fallback
# fired on exactly the healthy case and made UNKNOWN the two-line string "0\n0"; `[ "0\n0" -eq 0 ]`
# then raised "integer expression expected", returned 2, and reported FAIL. The leg therefore
# passed only when debug.log did not EXIST — which is how it passed here — and would have gone
# red the first time any unrelated notice created it. `${UNKNOWN:-0}` already covers a missing
# file; the fallback was covering a case that did not need it and breaking the one that did.
DEBUG_LOG="$WP_DIR/wp-content/debug.log"
[ -f "$DEBUG_LOG" ] || note NOTE "no debug.log was written — the checks below are about an absent file"
UNKNOWN=$(grep -c 'Unknown column' "$DEBUG_LOG" 2>/dev/null)
[ "${UNKNOWN:-0}" -eq 0 ] && check "no per-hit Unknown-column error" 0 \
  || check "no per-hit Unknown-column error" 1 "$UNKNOWN in debug.log"

echo
echo "── R3 · the migration ───────────────────────────────────────────────────"
# EVERY migration in the tree, discovered from the directory rather than named here.
#
# The first version of this leg registered two by hand — AddVisitIdentity and
# AddUserAgentDimension — and `runAll()` skips optional ones, so exactly ONE ran: a metadata-only
# ADD COLUMN that is INSTANT on MySQL 8. The eight Create*Index migrations and
# RecoverCorruptedHeatmapPositions, which are the entire cost and the entire risk on a 443k-row
# v5 table, never executed, and the cell reported "every required migration reported true" in
# 4.2 seconds. A hand-written registry is also a THIRD copy of MigrationService::init()'s list,
# and it had already drifted from it on the day it was written.
#
# CreateGoalQueriesIndex is the one this matters most for: `resource(191), dt, fingerprint(20)`
# lands three bytes under the 767-byte prefix limit a legacy COMPACT/utf8 table imposes, so a
# real v5 table is where it would fail if it ever fails.
MIG=$(wpc eval '
  $a = SlimStat\Migration\MigrationService::analyticsConnection();
  $m = new SlimStat\Migration\MigrationManager();
  $n = 0;
  foreach (glob(WP_PLUGIN_DIR . "/wp-slimstat/src/Migration/Migrations/*.php") as $f) {
      $c = "SlimStat\\Migration\\Migrations\\" . basename($f, ".php");
      if (!class_exists($c)) { continue; }
      $m->register(new $c($a, $GLOBALS["wpdb"])); $n++;
  }
  $m->forgetProbe();
  $required = count($m->getRequiredMigrations());
  $t0 = microtime(true);
  $r  = $m->runAll();
  $ok = $r === [] ? "claim-refused" : (in_array(false, $r, true) ? "false" : "true");
  printf("%s %.1f %d %d %d", $ok, microtime(true) - $t0, $n, $required, count($r));
' 2>/dev/null)
MIG_OK=$(echo "$MIG" | awk '{print $1}'); MIG_S=$(echo "$MIG" | awk '{print $2}')
MIG_N=$(echo "$MIG" | awk '{print $3}'); MIG_REQ=$(echo "$MIG" | awk '{print $4}'); MIG_RAN=$(echo "$MIG" | awk '{print $5}')
echo "  registered $MIG_N migrations, $MIG_REQ of them owed; runAll() reported on $MIG_RAN"
[ "${MIG_N:-0}" -ge 12 ] && check "the whole migration set was registered" 0 "$MIG_N classes" \
  || check "the whole migration set was registered" 1 "only ${MIG_N:-0} classes — the set is not the tree's"
[ "$MIG_OK" = "true" ] && check "every required migration reported true" 0 "$MIG_RAN applied in ${MIG_S}s" \
  || check "every required migration reported true" 1 "$MIG_OK"

check "vid_hash exists after the migration" "$([ "$(has_column vid_hash)" = 1 ] && echo 0 || echo 1)"
# ua_id must still be ABSENT, and that is the point rather than a gap. AddUserAgentDimension is
# OFFERED, not owed, so "Apply All" deliberately walks past it — Run 9 measured that the star
# dimension buys nothing on the read path while the browser columns stay on the fact row, and its
# cost is a fact-table rebuild. This asserts the split holds on real data: the notice's button
# applies what is owed and does not quietly take a rebuild the owner did not ask for.
check "ua_id is NOT applied by Apply All — it is offered, not owed" "$([ "$(has_column ua_id)" = 0 ] && echo 0 || echo 1)"

# And offered must not mean unreachable: the same migration runs when asked for BY NAME, which is
# the path the Migration screen's per-row button takes.
# OPT-IN, behind REHEARSE_OFFERED=1, and the reason is the measurement itself: this leg ran past
# EIGHT MINUTES on the real 443k-row table before it was interrupted, against 19.6s for the
# entire required set. That is not a defect — it is the fact-table rebuild Run 9 measured as
# buying nothing on the read path today, and it is exactly why the migration is OFFERED rather
# than owed and why the charset rebuild beside it was moved to offered too. Left in the default
# path it would make this cell unusable in CI and unrunnable in a PR lane.
#
# LOOPED, because this migration is RESUMABLE by design: run() does one batch and reports
# whether it is finished, so a single call on a 443k-row table returns false meaning "more to
# do", not "failed". Asserting a single call returns true reads a working resumable migration as
# a broken one — which is what the first version of this leg did.
#
# The pass count and wall time are the number the release notes need: this is the fact-table
# rebuild's real cost on a real table, and it is the one an owner is choosing when to take.
if [ "${REHEARSE_OFFERED:-0}" = "1" ]; then
UA=$(wpc eval '
  $a = SlimStat\Migration\MigrationService::analyticsConnection();
  $g = new SlimStat\Migration\Migrations\AddUserAgentDimension($a,$GLOBALS["wpdb"]);
  $t0 = microtime(true); $passes = 0;
  while ($g->shouldRun() && $passes < 500) { $g->run(); $passes++; }
  printf("%s %.1f %d", $g->shouldRun() ? "unfinished" : "done", microtime(true) - $t0, $passes);
' 2>/dev/null)
UA_OK=$(echo "$UA" | awk '{print $1}'); UA_S=$(echo "$UA" | awk '{print $2}'); UA_P=$(echo "$UA" | awk '{print $3}')
[ "$UA_OK" = "done" ] && check "and it completes when asked for by name" 0 "${UA_P} pass(es), ${UA_S}s on 443k rows" \
  || check "and it completes when asked for by name" 1 "still $UA_OK after ${UA_P} passes"
check "ua_id exists once it has been asked for" "$([ "$(has_column ua_id)" = 1 ] && echo 0 || echo 1)"
else
  note NOTE "the offered fact-table rebuild is NOT exercised (REHEARSE_OFFERED=1 to include it); measured past 8 minutes on this dataset"
fi

ROWS_2=$(row_count)
[ "$ROWS_2" -eq "$ROWS_1" ] && check "not one row was lost or duplicated" 0 "$ROWS_2 rows" \
  || check "not one row was lost or duplicated" 1 "$ROWS_1 -> $ROWS_2"

FP_1=$(fingerprint)
[ "$FP_1" = "$FP_0" ] && check "every pre-existing v5 value is byte-identical" 0 "$FP_1" \
  || check "every pre-existing v5 value is byte-identical" 1 "$FP_0 -> $FP_1"

DEGRADED=$(wpc eval 'echo count((array) get_option("slimstat_degradations", []));' 2>/dev/null | tr -d '[:space:]')
[ "${DEGRADED:-0}" -eq 0 ] && check "no degradation was recorded" 0 \
  || check "no degradation was recorded" 1 "$DEGRADED recorded"

echo
echo "── R4 · idempotence ─────────────────────────────────────────────────────"
ALTERS_0=$(mysql_q "SHOW GLOBAL STATUS LIKE 'Com_alter_table';" | awk '{print $2}')
# The return value is captured, because "issued no ALTER" is also what a runner that REFUSED
# produces: a stale claim row from a killed run makes runAll() return [] immediately and take
# the takeover window to expire. Without this, a wedged runner reads as perfect idempotence.
RERUN=$(wpc eval '
  $a = SlimStat\Migration\MigrationService::analyticsConnection();
  $m = new SlimStat\Migration\MigrationManager();
  foreach (glob(WP_PLUGIN_DIR . "/wp-slimstat/src/Migration/Migrations/*.php") as $f) {
      $c = "SlimStat\\Migration\\Migrations\\" . basename($f, ".php");
      if (class_exists($c)) { $m->register(new $c($a, $GLOBALS["wpdb"])); }
  }
  $m->forgetProbe();
  $r = $m->runAll();
  echo $r === [] ? "refused" : (in_array(false, $r, true) ? "false" : "true");
' 2>/dev/null | tr -d '[:space:]')
[ "$RERUN" = "true" ] && check "the second run actually ran and reported true" 0 \
  || check "the second run actually ran and reported true" 1 "runAll() $RERUN"
ALTERS_1=$(mysql_q "SHOW GLOBAL STATUS LIKE 'Com_alter_table';" | awk '{print $2}')
[ "$ALTERS_1" = "$ALTERS_0" ] && check "a second run issues no ALTER" 0 "Com_alter_table steady at $ALTERS_1" \
  || check "a second run issues no ALTER" 1 "$ALTERS_0 -> $ALTERS_1"

echo
echo "── R5 · the kill switch ─────────────────────────────────────────────────"
wpc config set SLIMSTAT_DISABLE_MIGRATIONS true --raw --type=constant >/dev/null 2>&1
# Driven, not read. This used to call migrationsDisabled() — a two-token `defined() &&
# CONSTANT` — so nothing under the switch was ever executed and the leg passed unchanged if
# runAll() ignored the constant entirely. runAll() returns [] when it refuses, which is the
# discriminator R3 above already uses.
ALTERS_K0=$(mysql_q "SHOW GLOBAL STATUS LIKE 'Com_alter_table';" | awk '{print $2}')
KILLED=$(wpc eval '
  $a = SlimStat\Migration\MigrationService::analyticsConnection();
  $m = new SlimStat\Migration\MigrationManager();
  foreach (glob(WP_PLUGIN_DIR . "/wp-slimstat/src/Migration/Migrations/*.php") as $f) {
      $c = "SlimStat\\Migration\\Migrations\\" . basename($f, ".php");
      if (class_exists($c)) { $m->register(new $c($a, $GLOBALS["wpdb"])); }
  }
  $m->forgetProbe();
  echo $m->runAll() === [] ? "refused" : "ran";
' 2>/dev/null | tr -d '[:space:]')
ALTERS_K1=$(mysql_q "SHOW GLOBAL STATUS LIKE 'Com_alter_table';" | awk '{print $2}')
[ "$KILLED" = "refused" ] && check "the kill switch makes runAll() refuse" 0 \
  || check "the kill switch makes runAll() refuse" 1 "runAll() $KILLED"
[ "$ALTERS_K1" = "$ALTERS_K0" ] && check "and it issued no DDL while refusing" 0 "Com_alter_table steady at $ALTERS_K1" \
  || check "and it issued no DDL while refusing" 1 "$ALTERS_K0 -> $ALTERS_K1"
HIT_2=$(track_hit "rehearse-killswitch")
[ "${HIT_2:-0}" -gt 0 ] && check "tracking still lands with migrations disabled" 0 "row id $HIT_2" \
  || check "tracking still lands with migrations disabled" 1
wpc config delete SLIMSTAT_DISABLE_MIGRATIONS --type=constant >/dev/null 2>&1

echo
echo "── C4 · the control: a broken migration must turn this cell RED ──────────"
# Everything above is a PASS line, and a cell that only ever prints PASS lines has not been shown
# to be able to print anything else. This drops the column the migration adds and re-asserts the
# two checks that are supposed to notice — on the real table, so the control runs the same code
# path the assertions do. Then it puts the column back.
mysql_q "ALTER TABLE wordpress.wp_slim_stats DROP COLUMN vid_hash;" >/dev/null 2>&1
if [ "$(has_column vid_hash)" = 0 ]; then
  note PASS "the column check NOTICES a missing column (it reports absent when it is absent)"
else
  note FAIL "the column check cannot see a dropped column"; fail "C4 column check is blind"
fi
FP_BROKEN=$(fingerprint)
if [ "$FP_BROKEN" != "$FP_0" ]; then
  note FAIL "the fingerprint changed when only an ADDED column was dropped"; fail "C4 fingerprint is not v5-scoped"
else
  note PASS "the fingerprint ignores columns the migration added, as its scope requires"
fi
wpc eval '
  $a = SlimStat\Migration\MigrationService::analyticsConnection();
  (new SlimStat\Migration\Migrations\AddVisitIdentity($a,$GLOBALS["wpdb"]))->run(); echo "restored";
' >/dev/null 2>&1
[ "$(has_column vid_hash)" = 1 ] && note PASS "the column was restored for the rollback leg" \
  || { note FAIL "could not restore vid_hash"; fail "C4 restore"; }

echo
echo "── R7 · rollback to the OLD code on the migrated schema ─────────────────"
use_ref "$OLD_REF" || exit 1
HIT_3=$(track_hit "rehearse-rollback")
[ "${HIT_3:-0}" -gt 0 ] && check "the previous version still tracks after a migration" 0 "row id $HIT_3" \
  || check "the previous version still tracks after a migration" 1
FP_2=$(fingerprint)
[ "$FP_2" = "$FP_0" ] && check "rollback left every pre-existing v5 value intact" 0 \
  || check "rollback left every pre-existing v5 value intact" 1 "$FP_0 -> $FP_2"

# lib.sh's scan_debug_log owns what counts as a fatal — it returns 0 when it finds
# `PHP (Fatal|Parse) error.*wp-slimstat`. The hand-rolled grep that used to sit here was
# simultaneously broader (any plugin's fatal failed this cell) and narrower (it missed
# `Parse error`), which is two definitions of one thing and the weaker one deciding.
if scan_debug_log "$WP_DIR" "$ART" >/dev/null 2>&1; then
  check "no fatal in the debug log across the whole rehearsal" 1 "scan_debug_log found a wp-slimstat fatal"
else
  check "no fatal in the debug log across the whole rehearsal" 0
fi

drop_ref "$OLD_REF"; drop_ref "$NEW_REF"
git -C "$PLUGIN_SRC" worktree prune >/dev/null 2>&1

write_verdict "$ART" "$CELL" "$PHP" "$WP" "$status" "$reason" 2>/dev/null || true

echo
if [ "$status" = "PASS" ]; then
  echo "VERDICT: the upgrade is safe on this data — $ROWS_2 rows, v5 fingerprint unchanged across the migration"
  exit 0
fi
echo "VERDICT: FAILED — $reason"
exit 1
