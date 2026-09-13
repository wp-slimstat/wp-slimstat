#!/usr/bin/env bash
# One A1 matrix cell: HANDLE=wpdb|analytics and MYSQL_IMAGE select the 3x2 subject.
# Deliberately separate from the 5M recovery rehearsal; this uses only disposable control tables.
set -euo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
: "${QUALIFICATION_FREE_ZIP:?exact Free ZIP required}" "${QUALIFICATION_FREE_SHA256:?Free ZIP digest required}"
: "${QUALIFICATION_PRO_ZIP:?exact Pro ZIP required}" "${QUALIFICATION_PRO_SHA256:?Pro ZIP digest required}"
: "${REHEARSAL_RUNS_DIR:?fresh durable evidence directory required}"

HANDLE="${A1_HANDLE:-wpdb}"
case "$HANDLE" in wpdb|analytics) ;; *) die 'A1_HANDLE must be wpdb or analytics';; esac
OWNER_SECONDS="${A1_OWNER_SECONDS:-930}"
[[ "$OWNER_SECONDS" =~ ^[0-9]+$ ]] && [ "$OWNER_SECONDS" -gt 900 ] || die 'A1_OWNER_SECONDS must be greater than the retired 900-second lease'

mkdir -p "$WORK_ROOT" "$REHEARSAL_RUNS_DIR"
CELL_DIR=$(mktemp -d "$WORK_ROOT/a1-$HANDLE.XXXXXXXX")
ART="$CELL_DIR/artifacts"; mkdir -p "$ART" "$CELL_DIR/wp"
export CELL_WP_DIR="$CELL_DIR/wp" PHP_VERSION="${TOPOLOGY_PHP:-8.2}" HTTP_PORT=0 DB_PORT=0
export COMPOSE_PROJECT_NAME="ssa1$(basename "$CELL_DIR" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9')"
export MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:5.7}"
export A1_DB_PLATFORM="${A1_DB_PLATFORM:-$(docker image inspect "$MYSQL_IMAGE" --format '{{.Os}}/{{.Architecture}}')}"
export DC_EXTRA_FILE="$HARNESS_DIR/docker-compose.a1.yml"
STARTED=$(now); status=FAIL; reason='setup incomplete'; FINISHED=0

finish() {
  local rc=$?
  trap - EXIT
  [ "$FINISHED" = 1 ] || write_verdict "$ART" "$COMPOSE_PROJECT_NAME" "$PHP_VERSION" "${TOPOLOGY_WP:-6.7}" FAIL "$reason"
  dc down -v --remove-orphans >"$ART/cleanup.log" 2>&1 || true
  python3 - "$ART" "$PLUGIN_SRC" <<'PY'
import hashlib,json,pathlib,subprocess,sys
p=pathlib.Path(sys.argv[1]); source=pathlib.Path(sys.argv[2]); names=['run-migration-lock-controls.sh','probe-migration-lock.php','docker-compose.yml','docker-compose.a1.yml','Dockerfile.wp','lib.sh']
json.dump(dict(harness_sha=subprocess.check_output(['git','-C',source,'rev-parse','HEAD'],text=True).strip(),source_hashes={n:hashlib.sha256((source/'tests/docker'/n).read_bytes()).hexdigest() for n in names}),open(p/'manifest.json','w'),indent=2)
json.dump({str(f.relative_to(p)):hashlib.sha256(f.read_bytes()).hexdigest() for f in p.rglob('*') if f.is_file() and f.name!='artifacts.sha256.json'},open(p/'artifacts.sha256.json','w'),indent=2)
PY
  mkdir -p "$REHEARSAL_RUNS_DIR/$COMPOSE_PROJECT_NAME"
  cp -R "$ART/." "$REHEARSAL_RUNS_DIR/$COMPOSE_PROJECT_NAME/"
  [ "$status" = PASS ] || rc=1
  exit "$rc"
}
trap finish EXIT

[ -z "$(docker ps -aq --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME")" ] || die 'compose project collision'
[ -z "$(docker volume ls -q --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME")" ] || die 'compose volume collision'
reason='stack boot failed'; boot_stack "$ART" "$PHP_VERSION"
wait_for 40 3 dc exec -T analytics-db mysqladmin ping -h127.0.0.1 -uroot -proot --silent
BASE_URL="http://localhost:$(dc port wp 80 | sed 's/.*://')"
ARM_FREE_ZIP="$QUALIFICATION_FREE_ZIP" ARM_PRO_ZIP="$QUALIFICATION_PRO_ZIP"
reason='WordPress provisioning failed'; provision_wp_cell "$ART" "${TOPOLOGY_WP:-6.7}" "$BASE_URL" "$PLUGIN_SRC"
verify_qualification_artifact "$QUALIFICATION_FREE_ZIP" "$QUALIFICATION_FREE_SHA256" wp-slimstat "$CELL_WP_DIR/wp-content/plugins" >"$ART/free-installed.json"
verify_qualification_artifact "$QUALIFICATION_PRO_ZIP" "$QUALIFICATION_PRO_SHA256" wp-slimstat-pro "$CELL_WP_DIR/wp-content/plugins" >"$ART/pro-installed.json"

SERVICE=db; SCHEMA=wordpress
if [ "$HANDLE" = analytics ]; then
  enable_custom_db_addon analytics-db analytics "$ART"
  init_analytics_env "$ART"
  SERVICE=analytics-db; SCHEMA=analytics
fi
dc cp "$HARNESS_DIR/probe-migration-lock.php" wp:/tmp/probe-migration-lock.php >/dev/null
dbq() { dc exec -T "$SERVICE" mysql -uroot -proot -N "$SCHEMA" -e "$1" 2>/dev/null; }
dbx() { dc exec -T "$SERVICE" mysql -uroot -proot "$SCHEMA" -e "$1" >"$2" 2>&1; }
reason='control-table setup failed'
dbx 'DROP TABLE IF EXISTS wp_a1_lock_control; CREATE TABLE wp_a1_lock_control (id INT PRIMARY KEY) ENGINE=InnoDB; INSERT INTO wp_a1_lock_control VALUES (1);' "$ART/table-setup.log"
wpc eval '$s=(array)get_option("slimstat_migration_status",[]); unset($s["a1-age-control"],$s["a1-disconnect-control"],$s["a1-replacement-control"]); update_option("slimstat_migration_status",$s,false);' >/dev/null

# Active ownership remains authoritative after the retired 900-second timestamp window.
reason='long-lived owner control failed'; dc exec -T wp rm -f /tmp/a1-owner.json
wpc eval-file /tmp/probe-migration-lock.php hold a1-age-control "$OWNER_SECONDS" >"$ART/age-owner.log" 2>&1 & age_worker=$!
for ((n=0;n<120;n++)); do dc cp wp:/tmp/a1-owner.json "$ART/age-owner.json" >/dev/null 2>&1 && break; kill -0 "$age_worker" 2>/dev/null || break; sleep .5; done
read -r age_thread lock_name age_started <<<"$(python3 - "$ART/age-owner.json" "$HANDLE" <<'PY'
import json,sys
v=json.load(open(sys.argv[1])); assert isinstance(v['connection'],int) and v['connection']>0; assert v['lock'].startswith('wpss_migrate_')
assert (v['handle']=='wpdb') == (sys.argv[2]=='wpdb'), v
assert (v['handle'].endswith('AnalyticsWpdb')) == (sys.argv[2]=='analytics'), v
print(v['connection'],v['lock'],v['started'])
PY
)"
while [ $(( $(date +%s) - age_started )) -le 900 ]; do sleep 5; done
[ "$(dbq "SELECT IS_USED_LOCK('$lock_name');")" = "$age_thread" ]
wpc eval-file /tmp/probe-migration-lock.php refused a1-age-control >"$ART/age-refused.log" 2>&1
grep -q '^A1-CLAIM-REFUSED$' "$ART/age-refused.log"
wait "$age_worker"
for ((n=0;n<120;n++)); do [ "$(dbq "SELECT IS_FREE_LOCK('$lock_name');")" = 1 ] && break; sleep .5; done
wpc eval-file /tmp/probe-migration-lock.php recover a1-age-control >"$ART/age-recovered.log" 2>&1

# MySQL itself refuses a non-owner's stale cleanup; it cannot release a replacement owner.
dc exec -T wp rm -f /tmp/a1-owner.json
wpc eval-file /tmp/probe-migration-lock.php hold a1-replacement-control 10 >"$ART/replacement-owner.log" 2>&1 & replacement_worker=$!
for ((n=0;n<60;n++)); do dc cp wp:/tmp/a1-owner.json "$ART/replacement-owner.json" >/dev/null 2>&1 && break; kill -0 "$replacement_worker" 2>/dev/null || break; sleep .25; done
replacement_thread=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["connection"])' "$ART/replacement-owner.json")
[ "$(dbq "SELECT RELEASE_LOCK('$lock_name');")" = 0 ]
[ "$(dbq "SELECT IS_USED_LOCK('$lock_name');")" = "$replacement_thread" ]
wait "$replacement_worker"

# Kill the owning DB session while its ALTER waits on a metadata lock. No reconnect may replay it.
reason='mid-request disconnect control failed'
dbx 'SET GLOBAL log_output="TABLE"; SET GLOBAL general_log=ON; TRUNCATE mysql.general_log;' "$ART/general-log-enable.log"
(dbq 'LOCK TABLES wp_a1_lock_control READ; SELECT SLEEP(120);' >"$ART/metadata-owner.log") & metadata_worker=$!
for ((n=0;n<60;n++)); do blocker=$(dbq "SELECT ID FROM information_schema.PROCESSLIST WHERE DB='$SCHEMA' AND INFO='SELECT SLEEP(120)' LIMIT 1;" | tr -d '[:space:]'); [[ "$blocker" =~ ^[0-9]+$ ]] && break; sleep .5; done
[[ "$blocker" =~ ^[0-9]+$ ]]
rm -f "$ART/ddl-owner.json"; dc exec -T wp rm -f /tmp/a1-owner.json
wpc eval-file /tmp/probe-migration-lock.php ddl a1-disconnect-control >"$ART/ddl-worker.log" 2>&1 & ddl_worker=$!
for ((n=0;n<120;n++)); do dc cp wp:/tmp/a1-owner.json "$ART/ddl-owner.json" >/dev/null 2>&1 && break; kill -0 "$ddl_worker" 2>/dev/null || break; sleep .5; done
ddl_thread=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["connection"])' "$ART/ddl-owner.json")
for ((n=0;n<120;n++)); do [ "$(dbq "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$ddl_thread AND INFO LIKE 'ALTER TABLE %';")" = 1 ] && break; sleep .5; done
[ "$(dbq "SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID=$ddl_thread AND INFO LIKE 'ALTER TABLE %';")" = 1 ]
dbx "KILL CONNECTION $ddl_thread;" "$ART/ddl-disconnect.log"
set +e; wait "$ddl_worker"; ddl_rc=$?; set -e
printf '%s\n' "$ddl_rc" >"$ART/ddl-worker.exit"; [ "$ddl_rc" -ne 0 ]
dbx "KILL CONNECTION $blocker;" "$ART/metadata-release.log" || true
wait "$metadata_worker" 2>/dev/null || true
sleep 2
[ "$(dbq "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$SCHEMA' AND TABLE_NAME='wp_a1_lock_control' AND COLUMN_NAME='disconnected';")" = 0 ]
[ "$(dbq "SELECT COUNT(*) FROM mysql.general_log WHERE command_type='Query' AND argument LIKE 'ALTER TABLE %wp_a1_lock_control%ADD COLUMN%disconnected%';")" = 1 ]
wpc eval-file /tmp/probe-migration-lock.php status a1-disconnect-control >"$ART/ddl-status.log" 2>&1
wpc eval-file /tmp/probe-migration-lock.php recover a1-disconnect-control >"$ART/ddl-recovered.log" 2>&1

dbx 'SET GLOBAL general_log=OFF;' "$ART/general-log-disable.log"
dc images --format json >"$ART/images.json"
dc exec -T "$SERVICE" mysql -uroot -proot -N -e 'SELECT VERSION()' >"$ART/database-version.txt"
dc exec -T wp php -r 'echo PHP_VERSION;' >"$ART/php-version.txt"
wpc core version >"$ART/wp-version.txt"
python3 - "$ART/result.json" "$HANDLE" "$MYSQL_IMAGE" "$OWNER_SECONDS" "$QUALIFICATION_FREE_SHA256" "$QUALIFICATION_PRO_SHA256" "$STARTED" <<'PY'
import datetime,json,sys
out,handle,image,seconds,free,pro,started=sys.argv[1:]
json.dump(dict(status='PASS',handle=handle,image=image,owner_seconds=int(seconds),owner_refused_after_900=True,session_end_recovered=True,stale_cleanup_cannot_release_replacement=True,disconnect_no_replay=True,disconnect_no_status=True,free_zip_sha256=free,pro_zip_sha256=pro,started=started,finished=datetime.datetime.now(datetime.timezone.utc).isoformat()),open(out,'w'),indent=2)
PY
status=PASS; reason=''; FINISHED=1
write_verdict "$ART" "$COMPOSE_PROJECT_NAME" "$PHP_VERSION" "${TOPOLOGY_WP:-6.7}" PASS ''
