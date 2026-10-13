#!/usr/bin/env bash
# tests/docker/seed-bench.sh [rows] [days] [http_port] [db_port]
#
# Builds the I8 reshaped fixture in a disposable container and PROVES it is usable before
# anything measures against it.
#
# WHY. The 443,535-row reference dataset was made by duplicating a smaller dump 20x. That
# compressed the time axis to ~33 days and froze cardinality, so every scan reported
# examined ~= 401,240 at BOTH -30 and -90 days: a report that ignores the date filter entirely
# returns the same number as one that honours it. Row count was never the problem. Shape was.
#
# So this asserts the two properties that make a conclusion possible, and FAILS rather than
# seeding quietly:
#
#   1. distinct resources > 2048 — past A4's MEMORY temp-table cliff, so a GROUP BY resource
#      can actually spill to disk and the whole class of defect becomes reachable.
#   2. rows(30d) < rows(90d) < rows(all), strictly — so a range-filtered report and an
#      unfiltered one cannot return the same number by construction.
#
# A fixture that fails either is not a smaller fixture; it is one that cannot answer the
# questions it would be used for, which is worse than none because it looks like evidence.
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
[ -f "$HARNESS_DIR/matrix.env" ] && source "$HARNESS_DIR/matrix.env"

ROWS="${1:-150000}"
DAYS="${2:-180}"
HTTP_PORT="${3:-18960}"
DB_PORT="${4:-13960}"
VINTAGE_REF="${SEED_VINTAGE_REF:-}"
# The I8 writer uses the 5.5 column/notes contract. Earlier corpora use downgrade-corpus.sh.
[ -z "$VINTAGE_REF" ] || [ "$VINTAGE_REF" = wp.org:5.5.0 ] || die 'I8 vintage seeding currently requires pinned wp.org:5.5.0'
[[ "$ROWS" =~ ^[1-9][0-9]*$ ]] && [[ "$DAYS" =~ ^[1-9][0-9]*$ ]] || die 'rows and days must be positive integers'
[ "$ROWS" -lt 5000000 ] || [ -n "$VINTAGE_REF" ] || die '5M qualification corpus requires SEED_VINTAGE_REF'
VINTAGE_ZIP=""
[ -z "$VINTAGE_REF" ] || VINTAGE_ZIP=$(resolve_arm_zip "$VINTAGE_REF") || exit 1
PHP="${TOPOLOGY_PHP:-8.2}"
WP="${TOPOLOGY_WP:-6.7}"

CELL="bench-fixture"
mkdir -p "$WORK_ROOT/bench"
CELL_DIR=$(mktemp -d "$WORK_ROOT/bench/seed.XXXXXXXX")
WP_DIR="$CELL_DIR/wp"
ART="$CELL_DIR/artifacts"

export COMPOSE_PROJECT_NAME="ssbench" PHP_VERSION="$PHP" HTTP_PORT DB_PORT
export MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.0}"
export CELL_WP_DIR="$WP_DIR"

# A synthetic SQL-dump corpus has no replication/PITR claim. Binary logs otherwise fill
# Docker's bounded tmpfs before 5M rows; explicit engine overlays remain caller-owned.
if [ -z "${DC_EXTRA_FILE:-}" ]; then
  export DC_EXTRA_FILE="$CELL_DIR/compose-seed.yml"
  cat >"$DC_EXTRA_FILE" <<'YAML'
services:
  db:
    command:
      - --max_allowed_packet=64M
      - --skip-log-bin
YAML
fi

existing=$(docker ps -aq --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME") || die 'Docker project inspection failed'
volumes=$(docker volume ls -q --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME") || die 'Docker volume inspection failed'
[ -z "$volumes" ] || die 'project volumes already exist; refuse an inherited database'
[ -z "$existing" ] || die 'ssbench project already exists; serialize and clean its owner first'

# KEEP_BENCH=1 leaves the container up so a measurement can run against the seeded database.
keep="${KEEP_BENCH:-0}"
STARTED=$(now)
cleanup() {
  local rc=$?
  [ "$keep" = "1" ] || dc down -v --remove-orphans >"$ART/cleanup.log" 2>&1 || true
  write_verdict "$ART" "$CELL" "$PHP" "$WP" "$([ "$rc" = 0 ] && echo PASS || echo FAIL)" "seed process exit $rc" "\"started\":\"$STARTED\",\"exit_status\":$rc"
  if [ -n "${REHEARSAL_RUNS_DIR:-}" ]; then
    mkdir -p "$REHEARSAL_RUNS_DIR/$(basename "$CELL_DIR")"
    cp -R "$ART/." "$REHEARSAL_RUNS_DIR/$(basename "$CELL_DIR")/"
  fi
}
trap cleanup EXIT

rm -rf "$WP_DIR"
mkdir -p "$WP_DIR" "$ART"

python3 - "$ART/source.json" "$PLUGIN_SRC" "$ROWS" "$DAYS" <<'PYSOURCE'
import hashlib,json,pathlib,subprocess,sys
out,source,rows,days=sys.argv[1:]; p=pathlib.Path(source)
files=['tests/docker/seed-bench.sh','tests/docker/lib.sh','tests/bench/lib/seeder.php','tests/bench/lib/seed.php','tests/bench/seed-profile-i8.json','tests/bench/seed-profile.json']
json.dump(dict(source_sha=subprocess.check_output(['git','-C',source,'rev-parse','HEAD'],text=True).strip(),rows=int(rows),days=int(days),instrument_hashes={f:hashlib.sha256((p/f).read_bytes()).hexdigest() for f in files}),open(out,'w'),indent=2)
PYSOURCE
log "[$CELL] build + up (PHP $PHP, WP $WP)"
boot_stack "$ART" "$PHP" || { err "stack did not come up"; exit 1; }

for image_id in $(dc images -q | sort -u); do docker image inspect "$image_id" --format '{{json .}}'; done >"$ART/images.jsonl"
dc exec -T wp php -r 'echo PHP_VERSION;' >"$ART/php-version.txt"
mysql_q 'SELECT VERSION()' >"$ART/database-version.txt"
mysql_q "SHOW VARIABLES LIKE 'log_bin';" >"$ART/binary-log-setting.txt"
dc config >"$ART/compose-resolved.yml"

wpc core download --version="$WP" --force > "$ART/install.log" 2>&1 || { err "core download failed"; exit 1; }
wp_config_debug "$ART/install.log"
wpc core install --url="http://127.0.0.1:${HTTP_PORT}" --title="SS bench" --admin_user=admin \
    --admin_password=admin --admin_email=qa@example.com --skip-email >>"$ART/install.log" 2>&1 \
    || { err "core install failed"; exit 1; }

if [ -n "$VINTAGE_ZIP" ]; then
  dc cp "$VINTAGE_ZIP" wp:/tmp/seed-vintage.zip >/dev/null
  wpc plugin install /tmp/seed-vintage.zip --activate --force >>"$ART/install.log" 2>&1 || die 'vintage install failed'
  installer=$(run_vintage_installer 2>>"$ART/install.log")
  [ "$installer" = admin/index.php ] || die 'vintage installer did not complete'
  [ "$(arm_installed_version)" = "${VINTAGE_REF#wp.org:}" ] || die 'installed vintage header mismatch'
  [ "$(stored_plugin_version)" = "${VINTAGE_REF#wp.org:}" ] || die 'stored vintage version mismatch'
  columns=$(table_columns wp_slim_stats)
  [ -n "$columns" ] || die 'vintage schema absent'
  case ",$columns," in *,vid_hash,*|*,ua_id,*) die 'vintage schema already migrated';; esac
  mysql_q 'SHOW CREATE TABLE wordpress.wp_slim_stats; SHOW CREATE TABLE wordpress.wp_slim_stats_archive;' >"$ART/vintage-schema.sql" || die 'vintage schema capture failed'
  python3 - "$ART/vintage.json" "$VINTAGE_REF" "$(digest "$VINTAGE_ZIP")" "$columns" "$(digest "$ART/vintage-schema.sql")" <<'PYVINTAGE'
import json,sys
out,ref,sha,columns,schema=sys.argv[1:]
json.dump(dict(ref=ref,zip_sha256=sha,installed_version=ref.split(':')[1],stored_version=ref.split(':')[1],columns=columns.split(','),schema_sha256=schema),open(out,'w'),indent=2)
PYVINTAGE
else
  sync_plugin_src "$WP_DIR"
  wpc plugin activate wp-slimstat >>"$ART/install.log" 2>&1 || die 'activate failed'
  installer=$(run_vintage_installer 2>>"$ART/install.log")
  [ "$installer" = admin/index.php ] || die 'init_tables failed'
fi
# Seeder is an instrument outside the installed plugin; historical/package bytes stay intact.
dc cp "$PLUGIN_SRC/tests/bench" wp:/tmp/qualification-bench >/dev/null || die 'seeder copy failed'

# ── EXERCISE_FRESH runs BEFORE seeding, on a virgin install ─────────────────
# Some properties only exist on a fresh site — that it is born with the right columns, and that
# it is NOT offered a migration. Neither can be checked after seeding, and the I8 corpus
# assertions below would reject a one-row table anyway. So this probe gets its own moment.
if [ -n "${EXERCISE_FRESH:-}" ]; then
  cp "$HARNESS_DIR/$EXERCISE_FRESH" "$WP_DIR/wp-content/plugins/wp-slimstat/tests/docker/" 2>/dev/null
  log "[$CELL] exercising $EXERCISE_FRESH on the fresh install"
  dc exec -T -u www-data wp wp --path=/var/www/html eval-file \
     "wp-content/plugins/wp-slimstat/tests/docker/$EXERCISE_FRESH" 2>&1 | tee "$ART/exercise-fresh.log"
  [ "${PIPESTATUS[0]}" -eq 0 ] || { err "the fresh-install probe failed"; exit 1; }
fi

log "[$CELL] seeding $ROWS rows over $DAYS days with the I8 overlay"
dc exec -T -u www-data wp wp --path=/var/www/html eval-file \
   /tmp/qualification-bench/lib/seed.php "$ROWS" "$DAYS" seed-profile-i8.json \
   2>&1 | tee "$ART/seed.log" | tail -6
[ "${PIPESTATUS[0]}" -eq 0 ] || die 'seeding failed'

# ── the two properties, asserted ────────────────────────────────────────────
read -r ROWS_ALL ROWS_90 ROWS_30 DISTINCT_RES DISTINCT_REF <<<"$(
  wpc eval '
    global $wpdb; $t = $wpdb->prefix . "slim_stats"; $now = time();
    printf("%d %d %d %d %d",
      (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}"),
      (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE dt >= " . ($now - 90*86400)),
      (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE dt >= " . ($now - 30*86400)),
      (int) $wpdb->get_var("SELECT COUNT(DISTINCT resource) FROM {$t}"),
      (int) $wpdb->get_var("SELECT COUNT(DISTINCT referer) FROM {$t}"));
  ' 2>/dev/null)"

printf '{"rows":%s,"rows_90d":%s,"rows_30d":%s,"distinct_resource":%s,"distinct_referer":%s,"days":%s}\n' \
  "${ROWS_ALL:-0}" "${ROWS_90:-0}" "${ROWS_30:-0}" "${DISTINCT_RES:-0}" "${DISTINCT_REF:-0}" "$DAYS" \
  > "$ART/fixture.json"

echo
echo "  I8 fixture shape"
printf '    rows            %s\n' "${ROWS_ALL:-0}"
printf '    last 90d        %s\n' "${ROWS_90:-0}"
printf '    last 30d        %s\n' "${ROWS_30:-0}"
printf '    distinct resource %s\n' "${DISTINCT_RES:-0}"
printf '    distinct referer  %s\n' "${DISTINCT_REF:-0}"
echo

fail=0
[ "${ROWS_ALL:-0}" -ge "$ROWS" ] || { err 'seeded row count below requested target'; fail=1; }
if [ "${DISTINCT_RES:-0}" -le 2048 ]; then
  err "distinct resources = ${DISTINCT_RES:-0}, not > 2048 — A4's MEMORY temp-table cliff stays unreachable"
  fail=1
fi
if [ "${ROWS_30:-0}" -ge "${ROWS_90:-0}" ] || [ "${ROWS_90:-0}" -ge "${ROWS_ALL:-0}" ]; then
  err "rows 30d=${ROWS_30:-0} 90d=${ROWS_90:-0} all=${ROWS_ALL:-0} — the ranges do not separate, so no date-range conclusion on this fixture is falsifiable (the exact defect I8 exists to remove)"
  fail=1
fi

[ "$fail" -eq 0 ] || exit 1

# ── exercise any migration handed to us, against the seeded table ───────────
# EXERCISE=<file> runs a probe inside the container after seeding. A migration that has only
# ever run against a mock is a claim: the ALTER's algorithm is the server's choice, INSERT
# IGNORE exists for what happens on a duplicate, and `<=>` matters only because the columns are
# nullable. None of that behaviour exists in a double.
if [ -n "${EXERCISE:-}" ]; then
  cp "$HARNESS_DIR/$EXERCISE" "$WP_DIR/wp-content/plugins/wp-slimstat/tests/docker/" 2>/dev/null
  log "[$CELL] exercising $EXERCISE"
  dc exec -T -u www-data wp wp --path=/var/www/html eval-file \
     "wp-content/plugins/wp-slimstat/tests/docker/$EXERCISE" 2>&1 | tee "$ART/exercise.log"
  ex_rc=${PIPESTATUS[0]}
  [ "$ex_rc" -eq 0 ] || { err "the exercised probe failed"; exit 1; }
fi

if [ -n "${SEED_DUMP_OUT:-}" ]; then
  [ ! -e "$SEED_DUMP_OUT" ] || die 'refusing to overwrite a corpus'
  dump_schema_gz wordpress "$SEED_DUMP_OUT" "$ART/dump.log" wp_slim_stats wp_slim_stats_archive || die 'corpus dump failed'
  digest "$SEED_DUMP_OUT" >"$ART/dump.sha256"
fi
log "[$CELL] fixture is usable: ranges separate, cardinality past the cliff"
[ "$keep" = "1" ] && log "[$CELL] container left up on http://127.0.0.1:${HTTP_PORT} (KEEP_BENCH=1)"
exit 0
