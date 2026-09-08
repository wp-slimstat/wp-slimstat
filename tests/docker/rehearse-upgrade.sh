#!/usr/bin/env bash
# tests/docker/rehearse-upgrade.sh <old-ref> <new-ref> [dump.sql.gz] [http_port] [db_port]
#
#   SCENARIO=U1|U4    default U1. U4 = Pro installed alongside, and needs PRO_REF.
#                     U2/U3/U5/U6 are named in the plan and REFUSED here — see the refusal
#                     below. Each scenario derives its own cell directory, compose project and
#                     default ports, so two no longer share a cell directory or a compose
#                     project. (NOT a concurrency claim: the Docker engine and cached artifacts
#                     remain shared. Untested, so unclaimed.) Before this, CELL and
#                     COMPOSE_PROJECT_NAME were constants and
#                     a second run silently overwrote the first's artifacts. The names come from
#                     jaan-to/outputs/dev/v6-performance/NEXT-SESSION.md (B1/B5); note that
#                     record says "one of seven" while enumerating six.
#   PRO_REF=<ref|->   required by U4: the wp-slimstat-pro ref to build and install ('-' = the
#                     sibling checkout's committed HEAD). Free moves OLD -> NEW across the run, so a U4 run
#                     observes the mixed window at R1 (old free, this Pro) and the matched pair
#                     from R2 on.
#
#                     WHAT ONE U4 RUN DOES NOT COVER, stated because the first draft of this
#                     block claimed otherwise: R7 is old free on a MIGRATED schema, i.e. a
#                     rollback, not a mixed window — a real site in the window has an UNmigrated
#                     one, because old free never migrates — and nothing Pro-side is read there
#                     at all. A second run with an older PRO_REF reaches the new-free/old-Pro
#                     corner. Its own report output must consume the real tracked row through
#                     the new Free report API; absence of the newer floor method earns no credit.
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
#   R7  code downgrade          the OLD code on the migrated schema still tracks, and the v5
#                               values are still intact
#   R8  backup recovery          exact pre-migration database/schema restored; later writes lost
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
# scale beyond 443k, multisite, and an external analytics database. Each is a separate scenario
# and none of them is claimed by a green run of this one.
#
# Pro alongside IS covered, by SCENARIO=U4, and only what U4 asserts: that Pro activates, that
# the data-safety legs still hold with it loaded, and that Pro 3.0.0's author-scoped email
# reports go BLOCKED -> PERMITTED across the free upgrade. It makes no claim about Pro's own
# reports, its performance figures, or any Pro surface these legs do not touch.
#
# The dump defaults to the newest under ~/slimstat-v6-baselines/. `slimstat-db.sh dump` writes
# there, so the default is "this workspace's live site as it stands", which is a genuine
# deferred-window install: v6 code, v5 schema, 443,543 rows, no vid_hash and no ua_id.
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
source "$HARNESS_DIR/backup-recovery.sh"
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
  U1)
    # The mirror of U4's guard, and the same defect in the other direction: PRO_REF with U1
    # would install Pro, run the scope assertions and file the verdict under U1's name -- a U4
    # wearing U1's. One flag, set here, is what every leg below branches on.
    [ -z "${PRO_REF:-}" ] || { err "PRO_REF is set but SCENARIO=U1 is the free-only scenario; use SCENARIO=U4"; exit 2; }
    WITH_PRO=0 ;;
  U4)
    # U4 IS "Pro alongside", so a U4 with no Pro is U1 wearing U4's name -- the very thing the
    # arm below refuses. Required, not defaulted.
    [ -n "${PRO_REF:-}" ] || {
      err "SCENARIO=U4 needs PRO_REF (a wp-slimstat-pro ref, or '-' for committed HEAD)."
      err "  Without it this would run U1's legs and file the verdict under U4's name."
      exit 2; }
    WITH_PRO=1 ;;
  U2|U3|U5|U6)
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
# H8 · the topology is a PIN, not a default. rehearsal-cells.tsv states which WordPress and which
# PHP each cell runs on and why — 4.8.1 belongs on a MODERN core, because a site stuck on a 2019
# plugin is a site whose core kept auto-updating while its plugins did not. Environment still
# wins, so "does it also fail on 8.2?" is one variable away; a cell with no row keeps the
# defaults below, which is what an uncharacterised cell should do.
CELL_KEY="${REHEARSAL_CELL:-$(cell_for_ref "$OLD_REF")}"
CELL_WP=$(cell_field "$CELL_KEY" 4)
CELL_PHP=$(cell_field "$CELL_KEY" 5)
PHP="${TOPOLOGY_PHP:-${CELL_PHP:-8.2}}"
WP="${TOPOLOGY_WP:-${CELL_WP:-6.7}}"

# lib.sh owns "newest dump in the baselines directory", and the caveat that goes with it: as of
# 2026-09-05 the newest one is a MIGRATED dump carrying vid_hash, so the convenient default now
# selects a corpus C1 refuses outright. It fails loudly rather than quietly, which is correct,
# but the message has to say what to pass instead — otherwise the default reads as a broken
# harness rather than as a dump that is the wrong subject.
if [ -z "$DUMP" ]; then
  DUMP=$(latest_baseline_dump)
fi
[ -n "$DUMP" ] && [ -f "$DUMP" ] || { err "no dump: pass one, or run jaan-to/bin/slimstat-db.sh dump"; exit 1; }

CELL="upgrade-$SCEN_SLUG"
mkdir -p "$WORK_ROOT/rehearse"
CELL_DIR=$(mktemp -d "$WORK_ROOT/rehearse/$CELL.XXXXXXXX")
WP_DIR="$CELL_DIR/wp"
ART="$CELL_DIR/artifacts"
BASE_URL="http://127.0.0.1:${HTTP_PORT}"

COMPOSE_PROJECT_NAME="${REHEARSAL_PROJECT_NAME:-ssrehearse$SCEN_SLUG}"
printf '%s' "$COMPOSE_PROJECT_NAME" | grep -qE '^[a-z0-9][a-z0-9_-]*$' \
  || { err "invalid rehearsal project name: '$COMPOSE_PROJECT_NAME'"; exit 2; }
export COMPOSE_PROJECT_NAME PHP_VERSION="$PHP" HTTP_PORT DB_PORT
export MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.0}"
export CELL_WP_DIR="$WP_DIR"

existing=$(docker ps -aq --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME") || die 'Docker project inspection failed'
volumes=$(docker volume ls -q --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME") || die 'Docker volume inspection failed'
[ -z "$volumes" ] || die 'project volumes already exist; refuse an inherited database'
[ -z "$existing" ] || die 'rehearsal project already exists; serialize and clean its owner first'
STARTED=$(now)
status="PASS"; reason=""
cleanup() {
  local rc=$?
  cleanup_pro_arm
  [ "${KEEP_CELL:-0}" = "1" ] || dc down -v --remove-orphans >"$ART/cleanup.log" 2>&1 || true
  [ -f "$ART/cell.json" ] || write_verdict "$ART" "$CELL" "$PHP" "$WP" FAIL "${reason:-rehearsal aborted with exit $rc}"
  python3 - "$ART" "$PLUGIN_SRC" "$STARTED" "$rc" "$OLD_REF" "$NEW_REF" "${PRO_RESOLVED_REF:-}" "${CANDIDATE_ZIP_HASH:-}" "${PRO_ZIP_HASH:-}" "$(digest "$DUMP")" "${OLD_ZIP_HASH:-}" <<'PYARCHIVE'
import datetime,hashlib,json,os,pathlib,subprocess,sys
art,source,started,rc,old,new,pro,fzip,pzip,corpus,oldzip=sys.argv[1:]; p=pathlib.Path(art); src=pathlib.Path(source)
files=['lib.sh','backup-recovery.sh','rehearse-upgrade.sh','extract-artifact.py','interrupt-ddl.sh','probe-interrupt-ddl.php','probe-pro-mixed-window.php','Dockerfile.wp','docker-compose.yml']
free=subprocess.run(['git','-C',source,'rev-parse',new+'^{commit}'],capture_output=True,text=True)
json.dump(dict(started=started,finished=datetime.datetime.now(datetime.timezone.utc).isoformat(),exit_status=int(rc),old_zip_sha256=oldzip or None,ddl_interruption_requested=os.environ.get('REHEARSE_INTERRUPT_DDL','0')=='1',old_ref=old,new_ref=new,free_sha=free.stdout.strip() if free.returncode==0 else None,pro_sha=pro or None,free_zip_sha256=fzip or None,pro_zip_sha256=pzip or None,corpus_sha256=corpus,instrument_sha=subprocess.check_output(['git','-C',source,'rev-parse','HEAD'],text=True).strip(),instrument_hashes={f:hashlib.sha256((src/'tests/docker'/f).read_bytes()).hexdigest() for f in files}),open(p/'manifest.json','w'),indent=2)
json.dump({str(f.relative_to(p)):hashlib.sha256(f.read_bytes()).hexdigest() for f in p.rglob('*') if f.is_file() and f.name!='artifacts.sha256.json'},open(p/'artifacts.sha256.json','w'),indent=2)
PYARCHIVE
  local durable="${REHEARSAL_RUNS_DIR:-$PLUGIN_SRC/../jaan-to/outputs/dev/v6-performance/runs/run65-rehearsal}/$(basename "$CELL_DIR")"
  mkdir -p "$durable"
  cp -R "$ART/." "$durable/"
}
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
#
# ── H5 · the one column the upgrade is ALLOWED to rewrite ───────────────────────────────────
#
# Eight of the nine columns below are vintage-portable: no block in any upgrade path touches
# them, so "unchanged" is the right assertion on every arm. `notes` is not. Coming from below
# 4.8.8, the 4.8.8 block runs convert_notes_to_brackets() and rewrites every unconverted value
# from `a:1;b:2` to `[a:1][b:2]` — legitimately, deliberately, and by design. A fingerprint that
# demanded `notes` be byte-identical would turn a correct upgrade red on exactly the four cells
# that exist to rehearse it.
#
# The obvious repair is to drop `notes` from the fingerprint on pre-4.8.8 arms. That is the
# repair this cell must NOT make: it would also pass a conversion that emptied the column,
# truncated it at the first `;`, or wrote the same value into all 443,543 rows. The column the
# upgrade is most likely to damage would become the one column nobody checks.
#
# So the transform is the assertion. The BEFORE fingerprint projects `notes` through the
# plugin's own forward expression — rendered from lib.sh's single copy of it, so it cannot drift
# from admin/index.php — for exactly the rows the migration's own predicate will select, and
# leaves every other row alone. The AFTER fingerprint reads `notes` raw. If those two numbers
# agree, then for every row in the baseline set the migration's output IS forward(input) where
# it converted and IS the input where it did not. Equality now MEANS the transform was applied
# correctly, rather than meaning nothing.
#
# Non-vacuity is asserted separately (R1 counts the pending rows, R3 requires that count to
# reach zero), because a corpus with nothing to convert would satisfy the equality trivially.
FP_NOTES_EXPR="notes"

FP_SQL_TEMPLATE="SELECT COUNT(*), SUM(CRC32(CONCAT_WS(CHAR(0),
          COALESCE(id,'~'), COALESCE(ip,'~'), COALESCE(resource,'~'), COALESCE(dt,'~'),
          COALESCE(visit_id,'~'), COALESCE(browser,'~'), COALESCE(country,'~'),
          COALESCE(referer,'~'), COALESCE(%s,'~')))) FROM wordpress.wp_slim_stats WHERE id <= %s"

# The same tuple minus `notes`, never projected on any arm. It answers the half of the question
# that is identical across every vintage — "did the eight columns nobody may touch survive" —
# and it answers it without depending on the projection above being right. Two numbers, two
# claims: this one cannot be talked out of failing by an argument about the notes expression.
FP_CORE_TEMPLATE="SELECT COUNT(*), SUM(CRC32(CONCAT_WS(CHAR(0),
          COALESCE(id,'~'), COALESCE(ip,'~'), COALESCE(resource,'~'), COALESCE(dt,'~'),
          COALESCE(visit_id,'~'), COALESCE(browser,'~'), COALESCE(country,'~'),
          COALESCE(referer,'~')))) FROM wordpress.wp_slim_stats WHERE id <= %s"
BASE_MAX_ID=""

fingerprint() {
  [ -n "$BASE_MAX_ID" ] || { echo "unpinned"; return; }
  mysql_q "$(printf "$FP_SQL_TEMPLATE" "$FP_NOTES_EXPR" "$BASE_MAX_ID")" | tr -d '\r' | tr '\t' ':'
}
fingerprint_core() {
  [ -n "$BASE_MAX_ID" ] || { echo "unpinned"; return; }
  mysql_q "$(printf "$FP_CORE_TEMPLATE" "$BASE_MAX_ID")" | tr -d '\r' | tr '\t' ':'
}
# How many rows are still in the pre-4.8.8 form, within the pinned baseline set. lib.sh renders
# the predicate from the same template the migration's WHERE clause uses.
notes_pending_rows() {
  [ -n "$BASE_MAX_ID" ] || { echo ""; return; }
  scalar_q "SELECT COUNT(*) FROM wordpress.wp_slim_stats
              WHERE id <= $BASE_MAX_ID AND $(notes_pending notes);"
}

# lib.sh owns row_count <schema> <table>; this cell only ever asks about one table.
stats_rows() { row_count wordpress wp_slim_stats; }
has_column() { mysql_q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='wordpress' AND TABLE_NAME='wp_slim_stats' AND COLUMN_NAME='$1';" | tr -d '[:space:]'; }

# H1's control, and the reason the vintage arms are trustworthy at all. `resolve_arm_zip` proves
# the BYTES are the ones wp.org served; this proves those bytes are what is now installed and
# running. The two are different claims: `wp plugin install --force` can succeed on a ZIP whose
# folder name collides with an existing plugin dir, leaving the previous arm in place, and every
# assertion downstream would then describe the wrong vintage while the cell reported PASS. A
# vintage cell whose arm silently did not change is the one failure that makes all the others
# meaningless, so it is asserted after every install, not once at the start.
assert_arm_vintage() { # <ref>
  [ -n "${ARM_FREE_VERSION:-}" ] || return 0   # git arms: the sha is the identity, not a version
  local got
  got=$(arm_installed_version)
  [ "$got" = "$ARM_FREE_VERSION" ] \
    && check "the installed arm is the vintage requested ($1)" 0 "WordPress reports $got" \
    || check "the installed arm is the vintage requested ($1)" 1 \
             "asked for $ARM_FREE_VERSION, WordPress reports ${got:-nothing}"
}

use_ref() { # <ref>
  local ref="$1"
  local full sha zip rc
  # wp.org:<version> and *.zip resolve to bytes; anything else is a git ref and gets built.
  if [ "$ref" = "$NEW_REF" ] && [ -n "${QUALIFICATION_FREE_ZIP:-}" ]; then
    if [ ! -d "$CELL_DIR/candidate-artifact" ]; then
      extract_qualification_artifact "$QUALIFICATION_FREE_ZIP" "${QUALIFICATION_FREE_SHA256:?Free ZIP digest required}" wp-slimstat "$CELL_DIR/candidate-artifact" || return 1
    fi
    [ "$(digest "$QUALIFICATION_FREE_ZIP")" = "$QUALIFICATION_FREE_SHA256" ] || return 1
    zip="$QUALIFICATION_FREE_ZIP"; rc=0
  else
    zip=$(resolve_arm_zip "$ref"); rc=$?
  fi
  if [ "$rc" = 1 ]; then
    return 1
  elif [ "$rc" = 0 ]; then
    ARM_FREE_ZIP="$zip"
    ARM_FREE_VERSION=$(arm_ref_version "$ref")
    log "[$CELL] arm $ref -> $(basename "$zip") ($(digest "$zip" | cut -c1-12), pinned)"
  else
    ARM_FREE_VERSION=""
    full=$(git -C "$PLUGIN_SRC" rev-parse "$ref^{commit}") || { err "cannot resolve Free ref $ref"; return 1; }
    sha=${full:0:8}
    ARM_FREE_ZIP="$HARNESS_DIR/build/wp-slimstat-$sha.zip"
    FREE_ZIP_OUT="$ARM_FREE_ZIP" bash "$HARNESS_DIR/build-free.sh" "$full" \
      > "$ART/build-free-$sha.log" 2>&1 || { err "Free ZIP build at $ref failed"; return 1; }
  fi
  [ "$ref" != "$NEW_REF" ] || CANDIDATE_ZIP_HASH=$(digest "$ARM_FREE_ZIP")
  [ "$ref" != "$OLD_REF" ] || OLD_ZIP_HASH=$(digest "$ARM_FREE_ZIP")
  # The first arm is selected before WordPress exists; provision_wp_cell installs it. Every
  # later transition goes through WordPress's upgrader, never through a source-tree rsync.
  if [ -f "$WP_DIR/wp-config.php" ]; then
    mkdir -p "$WP_DIR/wp-content/plugins/.free"
    cp "$ARM_FREE_ZIP" "$WP_DIR/wp-content/plugins/.free/wp-slimstat.zip"
    chmod -R a+rwX "$WP_DIR/wp-content" 2>/dev/null || true
    wpc plugin install /var/www/html/wp-content/plugins/.free/wp-slimstat.zip --force \
      >> "$ART/install.log" 2>&1 || { err "Free ZIP upgrade to $ref failed"; return 1; }
    assert_arm_vintage "$ref"
  fi
}
drop_ref() { :; }

# A tracked hit through the REAL path: the tracker's own entry point, not an INSERT.
track_hit() { # <marker>
  # REQUEST_URI, not an argument. `wp_slimstat::slimtrack()` declares ZERO parameters, so the
  # array this used to pass was discarded silently and all three hits were the same anonymous
  # request — the marker never reached the database and the assertions below could not tell
  # which row they had made. This repo ships tests/surplus-argument-scan-test.php for exactly
  # that defect class, and it tokenises admin/ and src/, so a call inside a shell string here is
  # outside its reach twice over.
  #
  # AND NOT THROUGH slimtrack()'s RETURN VALUE, which is the older half of this note. 4.8.1's
  # `slimtrack($_argument = '')` is a FILTER callback: every one of its twelve returns hands back
  # $_argument, never an id, so `is_numeric($id) ? $id : 0` could not report success on that arm
  # under any circumstances. R7 asked "does the previous version still track after a migration",
  # measured the NEW code's return convention against the OLD code, and reported a rollback
  # defect. What both arms share is the ROW, so that is what this reads: the greatest id before,
  # the greatest id after, and the delta. Not `WHERE resource = '/$1'` — hit_resource() below
  # asserts the marker separately, and a lookup keyed on the marker would make that check assert
  # its own SELECT. Run 65 cell 7a, PITFALLS 136.
  _th_before=$(scalar_q "SELECT COALESCE(MAX(id),0) FROM wordpress.wp_slim_stats;")
  wpc eval "
    \$_SERVER['HTTP_USER_AGENT']='Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120 Safari/537.36';
    \$_SERVER['REMOTE_ADDR']='203.0.113.7';
    \$_SERVER['HTTP_REFERER']='https://example.com/from';
    \$_SERVER['REQUEST_URI']='/$1';
    wp_slimstat::slimtrack();
  " >/dev/null 2>&1
  _th_after=$(scalar_q "SELECT COALESCE(MAX(id),0) FROM wordpress.wp_slim_stats;")
  if [ "${_th_after:-0}" -gt "${_th_before:-0}" ]; then echo "$_th_after"; else echo 0; fi
}

# The row a track_hit() claims to have written, read back by id.
hit_resource() { mysql_q "SELECT resource FROM wordpress.wp_slim_stats WHERE id=$1;" | tr -d '[:space:]'; }

log "[$CELL] build + up (PHP $PHP, WP $WP)"
boot_stack "$ART" "$PHP" || { err "stack did not come up"; exit 1; }

for image_id in $(dc images -q | sort -u); do docker image inspect "$image_id" --format '{{json .}}'; done >"$ART/images.jsonl"
dc exec -T wp php -r 'echo PHP_VERSION;' >"$ART/php-version.txt"
mysql_q 'SELECT VERSION()' >"$ART/database-version.txt"

echo
# ── U4's own instrument ─────────────────────────────────────────────────────────────────────
# Pro 3.0.0's author-scoped email reports need free >= 6.0.0 and are supposed to BLOCK on older
# free (EmailReportsAddon::AUTHOR_SCOPED_MIN_FREE). This drives the real decision method through
# reflection -- the same way Pro's own email-author-free-floor-test.php does -- because the
# alternative, reading the constant, would assert the floor is WRITTEN rather than CONSULTED.
# Four answers, not two: a Pro that predates the floor has no method, and that is not "permitted".
# stderr goes to a log rather than /dev/null: when a probe returns empty the operator otherwise
# gets "expected BLOCKED, got ''" and no way to find out why.
pro_author_scope() {
  wpc eval '
    $c = "\\WpSlimstatPro\\Addon\\Addons\\EmailReportsAddon";
    if (!class_exists($c)) { echo "NOCLASS"; return; }
    if (!method_exists($c, "authorScopedReportBlocked")) { echo "NOMETHOD"; return; }
    $m = new ReflectionMethod($c, "authorScopedReportBlocked");
    if (PHP_VERSION_ID < 80100) { $m->setAccessible(true); }
    echo $m->invoke(null, ["wp_slimstat_db", "get_goals_raw"]) ? "BLOCKED" : "PERMITTED";
  ' 2>>"$ART/pro-probe.log" | tr -d '[:space:]'
}

# What a site OWNER would see. The reflection probe above proves the decision method flips; this
# proves the consequence reaches a screen. Both, not either: the reflection probe distinguishes
# NOCLASS from NOMETHOD, which a notice cannot, and the notice catches a floor that decides
# correctly and then tells nobody.
pro_floor_notice() {
  wpc eval '
    wp_set_current_user(1);
    if (class_exists("wp_slimstat")) { \wp_slimstat::$settings["addon_email_report_send_authors"] = "on"; }
    ob_start(); do_action("admin_notices"); $o = (string) ob_get_clean();
    echo (false !== strpos($o, "6.0.0") && false !== stripos($o, "per-author")) ? "NOTICE" : "NONOTICE";
  ' 2>>"$ART/pro-probe.log" | tr -d '[:space:]'
}

# Pro boots every addon FAIL-SOFT (ServiceProvider.php:51-68 catches Throwable per addon and
# routes it to wp_slimstat_pro_record_degradation, which writes 'pro_<step>' into FREE's
# slimstat_degradations). So all nine addons can fail to construct while the plugin still reports
# active AND class_exists() still answers yes -- neither instrument above can see it. This one
# can. Keys, not a count: 'how many' cannot say whether the failure was Pro's or the migration's.
pro_degradation_keys() {
  wpc eval 'echo implode(",", array_keys((array) get_option("slimstat_degradations", [])));' \
    2>>"$ART/pro-probe.log" | tr -d '[:space:]'
}

echo "CONTROLS"
echo "  dump:        $(basename "$DUMP")"
echo "  old ref:     $OLD_REF"
echo "  new ref:     $NEW_REF"
# H8 · say where the topology came from. "WP 6.7 / PHP 7.4" printed alone is indistinguishable
# from a default that happens to match, and the difference is exactly what a recorded run needs.
if [ -n "${CELL_KEY:-}" ]; then
  echo "  topology:    WP $WP / PHP $PHP (cell $CELL_KEY, pinned in rehearsal-cells.tsv)"
  echo "               $(cell_field "$CELL_KEY" 6)"
else
  echo "  topology:    WP $WP / PHP $PHP (no row in rehearsal-cells.tsv for $OLD_REF — defaults)"
fi

# ── C1: the dump is a genuine v5 schema ─────────────────────────────────────
# Asserted on the FILE, before anything imports it. A dump that already carried the v6 columns
# would make every assertion below vacuous while looking identical in the output.
if dump_has_v6_columns "$DUMP"; then
  err "$(basename "$DUMP") already carries v6 columns — nothing below this line could fail."
  err "  It is a post-migration dump, not a v5 corpus. Pass an earlier one explicitly:"
  err "  ls -t ~/slimstat-v6-baselines/slim-analytics-*.sql.gz"
  exit 1
fi
check "the dump is a pre-migration v5 schema" 0 "no vid_hash, no ua_id"

use_ref "$OLD_REF" || exit 1

# Pro rides in as the real ZIP or not at all: the container mounts only the WP install, so the
# sibling checkout is unreachable from inside it and build_pro_arm's zip is the only path in.
# provision_wp_cell installs it when ARM_PRO_ZIP is set (lib.sh:158) and skips it when it is not,
# which is what keeps U1 free-only.
if [ "$WITH_PRO" = 1 ]; then
  log "[$CELL] building the Pro arm at ${PRO_REF}"
  build_pro_arm "$PRO_REF" "$CELL_DIR" "$ART" || exit 1
  PRO_ZIP_HASH=$(digest "$ARM_PRO_ZIP")
fi
provision_wp_cell "$ART" "$WP" "$BASE_URL" "$PLUGIN_SRC" || exit 1
wpc core version >"$ART/wp-version.txt"
assert_arm_vintage "$OLD_REF"   # the first arm is installed by provision_wp_cell, not by use_ref

# C5 (U4 only). A post-provision property, so it cannot sit in the CONTROLS block above, whose
# subjects are all properties of the DUMP.
#
# It asks whether Pro's CODE LOADED, not whether WordPress lists it. The first draft read
# active_plugins -- and provision_wp_cell (lib.sh:158-164) already hard-fails when
# `wp plugin install --activate` returns non-zero, so that predicate is true of every run that
# reaches this line. The one failure C5 exists to catch is the silent one: plugin row present,
# autoloader dead. That is not hypothetical here -- the harness's own Pro build emits
# class_alias() calls for prefixed names it never declares, and four of them warn at activation
# while WP still reports the plugin active.
# Every previous cell in this programme ran free-only (STATE.json, _arm_pro.__unsupported), so a
# U4 that quietly failed to load Pro would repeat PITFALLS 21 exactly: five topologies reporting
# PASS having exercised none of the code they exist to test.
if [ "$WITH_PRO" = 1 ]; then
  SCOPE_OLD=$(pro_author_scope)
  [ "$SCOPE_OLD" != "NOCLASS" ] \
    && check "Pro's code loaded alongside free" 0 "arm $(pro_arm_desc)" \
    || check "Pro's code loaded alongside free" 1 "EmailReportsAddon did not load under $(pro_arm_desc)"
fi
# ── H2 · the arm's OWN installer builds the arm's OWN tables ────────────────
# lib.sh owns run_vintage_installer(): 4.8.1 ships no admin/index.php, and include_once on a
# missing path warns rather than fatals, so a hard-coded path would leave this cell with no
# wp_slim_stats of its own and the hydration below would silently supply one from the dump.
# downgrade-corpus.sh runs the identical helper, which is why it is there and not here.
INSTALLER=$(run_vintage_installer 2>>"$ART/install.log" | tr -d '[:space:]')
case "$INSTALLER" in
  admin/*) check "the arm's own installer ran" 0 "$INSTALLER" ;;
  *)       check "the arm's own installer ran" 1 "${INSTALLER:-no output} under arm $OLD_REF" ;;
esac

ARM_COLS=$(table_columns wp_slim_stats)
ARM_COL_N=$(printf '%s' "$ARM_COLS" | tr ',' '\n' | grep -c '[^[:space:]]' || true)
[ -n "$ARM_COLS" ] \
  && check "the arm built its own tables before hydration" 0 "$ARM_COL_N columns" \
  || check "the arm built its own tables before hydration" 1 "wp_slim_stats does not exist"

# ── C1b (H3) · the CORPUS is the arm's own vintage ──────────────────────────
# C1 above asks the dump for vid_hash/ua_id and stops. A 5.5-shaped dump passes that under a
# 4.8.1 arm, and the cell is then 4.8.1 code on 5.5 tables: every ADD COLUMN in the 4.8 blocks
# is a no-op against a column already present, every DROP finds nothing, and the DDL path reports
# green having executed none of itself. Nothing else a cell emits can see that.
# Set against set, and it has to happen HERE: after the arm's installer, before the import. One
# line later the table IS the dump's shape and the question is no longer answerable.
DUMP_COLS=$(dump_columns "$DUMP" wp_slim_stats)
[ -n "$DUMP_COLS" ] \
  || { err "the dump declares no CREATE TABLE for wp_slim_stats — $(basename "$DUMP") is not a corpus"; exit 1; }
ONLY_ARM=$(columns_missing_from "$ARM_COLS" "$DUMP_COLS")
ONLY_DUMP=$(columns_missing_from "$DUMP_COLS" "$ARM_COLS")
if [ -z "$ONLY_ARM" ] && [ -z "$ONLY_DUMP" ]; then
  check "the corpus is the arm's own vintage" 0 "$ARM_COL_N columns, identical sets"
else
  must "the corpus is the arm's own vintage" 1 \
        "arm-only: ${ONLY_ARM:-none}; dump-only: ${ONLY_DUMP:-none}"
fi

log "[$CELL] hydrating $(basename "$DUMP")"
gzip -dc "$DUMP" | dc exec -T db mysql -uroot -proot wordpress 2>"$ART/import.err" \
  || { err "hydration failed — see $ART/import.err"; exit 1; }

ROWS_0=$(stats_rows)
# C2: the corpus is the real one. COUNT(*), never information_schema.TABLE_ROWS, which is an
# estimate and reported 427,582 for this same table once.
[ "${ROWS_0:-0}" -gt 400000 ] && check "the corpus is the real dataset" 0 "$ROWS_0 rows" \
  || check "the corpus is the real dataset" 1 "only ${ROWS_0:-0} rows"

# C3: the columns the migration will add are absent NOW.
check "vid_hash is absent before the migration" "$([ "$(has_column vid_hash)" = 0 ] && echo 0 || echo 1)"
check "ua_id is absent before the migration"    "$([ "$(has_column ua_id)" = 0 ] && echo 0 || echo 1)"

echo
echo "── R1 · baseline under the OLD code ─────────────────────────────────────"
BASE_MAX_ID=$(scalar_q "SELECT MAX(id) FROM wordpress.wp_slim_stats;")
[ -n "$BASE_MAX_ID" ] || { err "could not pin the baseline row set"; exit 1; }

# H5 · arm the notes projection, if and only if this arm predates the block that rewrites the
# column. `version_lt` is component-wise and numeric, because 4.8.10 is above 4.8.9 and a string
# compare says otherwise — and the vintages this cell installs contain that case.
# ARM_FREE_VERSION is empty for git arms: those are builds of the current tree, the conversion
# ran long ago in their history, and `notes` is byte-stable across their upgrade. Empty therefore
# means "do not project", which is also the safe default if the version is ever unreadable.
NOTES_PENDING_0=""
if [ -n "${ARM_FREE_VERSION:-}" ] && version_lt "$ARM_FREE_VERSION" "4.8.8"; then
  FP_NOTES_EXPR="IF( $(notes_pending notes), $(notes_forward notes), notes )"
  NOTES_PENDING_0=$(notes_pending_rows)
  echo "  arm $ARM_FREE_VERSION predates 4.8.8: the fingerprint projects notes through the"
  echo "    plugin's own forward transform, so the 4.8.8 conversion is asserted, not excused"
  # A corpus with nothing to convert makes the equality unfalsifiable — green, and evidence of
  # nothing. downgrade-corpus.sh asserts the same count on the way out; this is the receiving end.
  if [ "${NOTES_PENDING_0:-0}" -gt 0 ]; then _r=0; else _r=1; fi
  check "the corpus gives the 4.8.8 conversion something to convert" "$_r" \
        "${NOTES_PENDING_0:-0} rows in the pre-4.8.8 form"
fi

FP_0=$(fingerprint)
FP_CORE_0=$(fingerprint_core)
echo "  v5 fingerprint over id <= $BASE_MAX_ID: $FP_0"
echo "  the eight columns nothing may touch:    $FP_CORE_0"

# R7 closes by asking whether the OLD version still tracks on the migrated schema. Until this
# control existed, no leg had ever seen the old arm track AT ALL, so a red R7 was equally
# consistent with "the rollback broke tracking" and "this arm never tracked in this container" —
# and the cell asserted the first. A leg that claims a property SURVIVED must hold a reading of
# that property from before. The hit lands ABOVE BASE_MAX_ID, so no fingerprint in this cell
# sees it, exactly as R2's and R5's do. PITFALLS 136.
HIT_0=$(track_hit "rehearse-old-code-baseline")
[ "${HIT_0:-0}" -gt 0 ] && check "the OLD version tracks before anything is migrated" 0 "row id $HIT_0" \
  || check "the OLD version tracks before anything is migrated" 1 "no row appeared"
# R2 counts its single row from HERE. The control above has just written one, and a delta still
# anchored to the hydrated count would absorb it — which is a delta that would absorb a duplicate.
ROWS_0=$(stats_rows)
capture_recovery_backup || { fail "pre-migration recovery backup failed"; exit 1; }

# U4's mixed window, first half. SCOPE_OLD was already READ by C5 above, which needed it to
# prove Pro loaded; reported here so the two halves of the transition sit beside their values.
if [ "$WITH_PRO" = 1 ]; then
  echo "  author-scoped email reports under OLD free: $SCOPE_OLD"
  NOTICE_OLD=$(pro_floor_notice)
  # Read the fail-soft store HERE, not only after the migration. The option PERSISTS, so an
  # addon that died during this window surfaces three legs later looking like migration damage.
  DEG_OLD=$(pro_degradation_keys)
  case "$DEG_OLD" in
    *pro_*) check "no Pro addon failed to boot in the mixed window" 1 "degradations: $DEG_OLD" ;;
    *)      check "no Pro addon failed to boot in the mixed window" 0 "${DEG_OLD:-none}" ;;
  esac
fi
scan_debug_log "$WP_DIR" "$ART" >/dev/null 2>&1 || true

echo
echo "── R2 · the DEFERRED WINDOW: v6 code, v5 schema, no migration yet ───────"
use_ref "$NEW_REF" || exit 1

# An old-arm request may save its settings again after installer setup. Establish and
# READ BACK this fixture after the file replacement, before NEW code first boots.
if [ "${REHEARSE_ARM_OPTIONS:-present}" = absent ]; then
  wpc --skip-plugins eval 'delete_option("slimstat_options");' >> "$ART/install.log" 2>&1 || exit 1
  OPTIONS_BEFORE_NEW=$(wpc --skip-plugins eval 'echo false === get_option("slimstat_options", false) ? "ABSENT" : "PRESENT";' 2>/dev/null)
  [ "$OPTIONS_BEFORE_NEW" = ABSENT ] || { err "missing-settings fixture was not established"; exit 2; }
  check "the options row is absent before NEW code first boots" 0 "$OPTIONS_BEFORE_NEW"
fi

# U4's mixed window, second half. WordPress updates plugins ONE AT A TIME, so on day one a real
# site runs new free beside old Pro, or old free beside new Pro, for as long as it takes the
# second updater to run. Pro 3.0.0's author-scoped reports require free 6.0.0 and must degrade to
# BLOCKED below it -- failing towards less data, never wrong data. The assertion is the
# transition BLOCKED -> PERMITTED across the same free upgrade this cell is already performing,
# which is a thing no other scenario can fail.
if [ "$WITH_PRO" = 1 ]; then
  SCOPE_NEW=$(pro_author_scope)
  echo "  author-scoped email reports under NEW free: $SCOPE_NEW"
  # No NOCLASS arm: C5 above already failed the run on it, before a row was hydrated.
  case "$SCOPE_OLD" in
    NOMETHOD)
      note NOTE "Pro $PRO_REF predates the floor method; boot, exact tracking and real report/CSV assertions below are required" ;;
    BLOCKED)
      [ "$SCOPE_NEW" = "PERMITTED" ] \
        && check "author-scoped reports: BLOCKED on old free, PERMITTED on new" 0 "$SCOPE_OLD -> $SCOPE_NEW" \
        || check "author-scoped reports: BLOCKED on old free, PERMITTED on new" 1 "$SCOPE_OLD -> $SCOPE_NEW"
      # The decision is only half of it: a floor that decides correctly and renders nothing
      # leaves the site owner with silently thinner reports and no reason given.
      NOTICE_NEW=$(pro_floor_notice)
      [ "$NOTICE_OLD" = "NOTICE" ] && [ "$NOTICE_NEW" = "NONOTICE" ] \
        && check "and the site owner is TOLD, on old free only" 0 "$NOTICE_OLD -> $NOTICE_NEW" \
        || check "and the site owner is TOLD, on old free only" 1 "$NOTICE_OLD -> $NOTICE_NEW (expected NOTICE -> NONOTICE)" ;;
    *)
      check "author-scoped reports are BLOCKED below the free floor" 1 "OLD free reported '$SCOPE_OLD', expected BLOCKED" ;;
  esac
fi
HIT_1=$(track_hit "rehearse-deferred-window")
[ "${HIT_1:-0}" -gt 0 ] && check "a pageview still lands before any migration" 0 "row id $HIT_1" \
  || check "a pageview still lands before any migration" 1 "slimtrack returned ${HIT_1:-nothing}"
# THE ROW IT CLAIMS, read back. Without this the leg proves a row appeared, not that it is the
# row this cell asked for — and an id plus a COUNT delta of 1 is satisfied by any insert at all.
RES_1=$(hit_resource "${HIT_1:-0}")
[ "$RES_1" = "/rehearse-deferred-window" ] && check "the row it wrote is the row it asked for" 0 "$RES_1" \
  || check "the row it wrote is the row it asked for" 1 "resource is '${RES_1:-empty}'"

ROWS_1=$(stats_rows)
[ "$ROWS_1" -eq $((ROWS_0 + 1)) ] && check "it landed exactly once" 0 "$ROWS_0 -> $ROWS_1" \
  || check "it landed exactly once" 1 "$ROWS_0 -> $ROWS_1"

if [ "$WITH_PRO" = 1 ]; then
  dc cp "$HARNESS_DIR/probe-pro-mixed-window.php" wp:/tmp/probe-pro-mixed-window.php >/dev/null || exit 1
  wpc eval-file /tmp/probe-pro-mixed-window.php >"$ART/pro-mixed-window.log" 2>&1
  PRO_FEATURE_RC=$?
  [ "$PRO_FEATURE_RC" = 0 ] && grep -q '^PRO-MIXED-WINDOW:' "$ART/pro-mixed-window.log"
  check "Pro renders the exactly-once tracked hit through the new Free report API" "$?" "see pro-mixed-window.log"
fi

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

# ── H5 · the LEGACY upgrade, which is what a 4.8 site actually runs first ───
#
# Everything below this block drives MigrationManager. MigrationManager is not the code path a
# site coming from 4.8.1 takes first, and for four of this programme's cells it is not even the
# code path that does most of the work: the 4.8.2 / 4.8.4 / 4.8.4.1 / 4.8.8 / 5.4.0 / 5.4.1
# blocks live in wp_slimstat_admin::update_tables_and_options(), reached from admin_init on the
# first wp-admin page load after the plugin files change. ADD COLUMN email, DROP COLUMN plugins,
# ADD fingerprint / tz_offset, and the whole notes conversion are there, not in src/Migration.
#
# So a vintage cell that only ran MigrationManager rehearsed the second half of an upgrade whose
# first half had not happened — and the H5 fingerprint projection armed at R1 would then compare
# a projected BEFORE against an unconverted AFTER and report the plugin had corrupted a column it
# had not touched yet. The projection is only meaningful if the code that does the conversion is
# actually driven, which is why this leg exists and why it comes first.
#
# `wp_set_current_user(1)` because may_run_schema_ddl() requires manage_options and WP-CLI runs
# as nobody. The other three refusals it can make — ajax, cron, REST — are all false under
# `wp eval`, so this is the one that has to be arranged, and arranging it is honest: an admin
# loading wp-admin is exactly the request the real path gates on.
echo "  the legacy upgrade path (wp_slimstat_admin::update_tables_and_options)"
# READ THE OPTIONS ROW, NOT THE MERGED SETTINGS. `wp_slimstat::$settings` is
# array_merge(init_options(), $stored) with stored winning, and init_options() carries
# `version => SLIMSTAT_ANALYTICS_VERSION`. A row with no version key therefore reads back as the
# version of the code doing the reading -- 6.0.0 -- so every `version_compare($v, "4.8.8", "<")`
# block in update_tables_and_options() decides it has already run, and the cell prints "the
# legacy upgrade path completed -- 1 pass(es), 3.1s" having executed none of it. The `-z`
# fallback immediately below was written for precisely the unstamped case and could never fire,
# because the value it tested is never empty. Run 65 cell 7a, PITFALLS 133.
LEGACY_FROM=$(stored_plugin_version)
if [ -z "$LEGACY_FROM" ] && [ -n "${ARM_FREE_VERSION:-}" ]; then
  # The arm's own installer was invoked directly (H2) rather than through its activation hook, so
  # the stored version may never have been stamped. Unstamped reads as "older than everything"
  # and every block runs — which is nearly right but leaves the cell unable to SAY what it
  # upgraded from. Stating it is the difference between a rehearsal and an anecdote.
  wpc eval "wp_slimstat::\$settings['version'] = '$ARM_FREE_VERSION'; wp_slimstat::update_option('version', '$ARM_FREE_VERSION');" >/dev/null 2>&1 \
    || wpc eval "\$o = get_option('slimstat_options', []); \$o['version'] = '$ARM_FREE_VERSION'; update_option('slimstat_options', \$o);" >/dev/null 2>&1
  LEGACY_FROM=$(stored_plugin_version)
fi
echo "    stored version before: ${LEGACY_FROM:-unstamped}"
echo "    settings condition at Free upgrade: ${REHEARSE_ARM_OPTIONS:-present} (REHEARSE_ARM_OPTIONS)"

# THE CONTROL THAT WAS MISSING. Every assertion this leg makes is conditional on the leg having
# work to do, and "has work to do" is exactly `stored < the version of the code now installed`.
# When it is not, update_tables_and_options() returns true on its first pass without entering a
# single block, and the timing line reads like a fast upgrade instead of an absent one. It is
# checked BEFORE the run so that its reason -- not the missed conversion downstream of it, and
# not the five further symptoms downstream of that -- is the reason the verdict carries.
#
# version_compare is asked of PHP rather than of sort -V, because PHP is what the plugin uses and
# a vintage like 4.8.4.1 is exactly where the two disagree.
NEW_VERSION=$(wpc eval 'echo defined("SLIMSTAT_ANALYTICS_VERSION") ? SLIMSTAT_ANALYTICS_VERSION : "";' 2>/dev/null | tr -d '[:space:]')
VER_LT=$(wpc eval "echo version_compare('${LEGACY_FROM:-0}', '${NEW_VERSION:-0}', '<') ? 'yes' : 'no';" 2>/dev/null | tr -d '[:space:]')
if [ -n "$LEGACY_FROM" ] && [ "$VER_LT" = "yes" ]; then _r=0; else _r=1; fi
check "the legacy upgrade path has something to upgrade FROM" "$_r" \
      "stored ${LEGACY_FROM:-unstamped}, code ${NEW_VERSION:-unknown}"

# LOOPED, for the same reason the offered fact-table rebuild below is looped: the 4.8.8 block
# converts `notes` in batches and returns FALSE without stamping the version when there is more
# to do. One call on a 443k-row table therefore reports false meaning "resume me", and asserting
# a single call returns true reads a working resumable upgrade as a broken one.
LEGACY=$(wpc eval '
  wp_set_current_user(1);
  require_once WP_PLUGIN_DIR . "/wp-slimstat/admin/index.php";
  $t0 = microtime(true); $passes = 0; $last = null;
  do { $last = wp_slimstat_admin::update_tables_and_options(); $passes++; }
  while ($last === false && $passes < 500);
  printf("%s %.1f %d", $last === false ? "unfinished" : "done", microtime(true) - $t0, $passes);
' 2>>"$ART/legacy-upgrade.log")
LEG_OK=$(echo "$LEGACY" | awk '{print $1}'); LEG_S=$(echo "$LEGACY" | awk '{print $2}'); LEG_P=$(echo "$LEGACY" | awk '{print $3}')
if [ "$LEG_OK" = "done" ]; then
  check "the legacy upgrade path completed" 0 "${LEG_P} pass(es), ${LEG_S}s from ${LEGACY_FROM:-unstamped}"
else
  # 500 passes without finishing is a finding about the batch size on a real table, not a
  # harness bug — and it is the kind of finding this cell exists to produce. It is still red.
  check "the legacy upgrade path completed" 1 "still ${LEG_OK:-no output} after ${LEG_P:-0} passes"
fi

# H5's non-vacuity, receiving end. R1 counted the rows in the pre-4.8.8 form; the conversion's
# whole job is to leave none of them. Zero here plus a nonzero count at R1 is what makes the
# fingerprint equality below a statement about a transform that ran, rather than about one that
# had nothing to do.
if [ -n "$NOTES_PENDING_0" ]; then
  NOTES_PENDING_1=$(notes_pending_rows)
  if [ "${NOTES_PENDING_1:-1}" -eq 0 ]; then _r=0; else _r=1; fi
  check "the 4.8.8 conversion left no row in the old form" "$_r" \
        "$NOTES_PENDING_0 -> ${NOTES_PENDING_1:-unknown}"
fi

echo
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
if [ "${REHEARSE_INTERRUPT_DDL:-0}" = 1 ]; then
  source "$HARNESS_DIR/interrupt-ddl.sh"
  interrupt_migration_ddl
  check "an executing migration DDL lost its worker and remained incomplete" "$?" "see ddl-interruption.json; normal migration run below must resume"
fi

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

if [ -n "${QUALIFICATION_FREE_ZIP:-}" ]; then
  verify_qualification_artifact "$QUALIFICATION_FREE_ZIP" "$QUALIFICATION_FREE_SHA256" wp-slimstat "$WP_DIR/wp-content/plugins" >"$ART/free-installed.json"
  check "installed Free shipping files remain the checksummed candidate" "$?" "see free-installed.json"
fi
if [ -n "${QUALIFICATION_PRO_ZIP:-}" ]; then
  verify_qualification_artifact "$QUALIFICATION_PRO_ZIP" "$QUALIFICATION_PRO_SHA256" wp-slimstat-pro "$WP_DIR/wp-content/plugins" >"$ART/pro-installed.json"
  check "installed Pro shipping files remain the checksummed candidate" "$?" "see pro-installed.json"
fi

ROWS_2=$(stats_rows)
[ "$ROWS_2" -eq "$ROWS_1" ] && check "not one row was lost or duplicated" 0 "$ROWS_2 rows" \
  || check "not one row was lost or duplicated" 1 "$ROWS_1 -> $ROWS_2"

# ── H6 · the column is not the claim; the INDEX is ─────────────────────────
# `vid_hash exists` above is satisfied by a column full of NULLs with nothing on it. What the P1
# read path needs is the composite `(vid_hash, dt)`, in that order — Schema.php:280 declares
# `idx_vid_hash_dt => 'vid_hash, dt'`, and the whole point of the identity column is that a
# visit lookup becomes a ref on it instead of a scan. An index built with the columns reversed
# still answers, still reports "index present", and does not serve the range on `dt` at all.
#
# So the assertion is the column LIST in SEQ order, not existence. This is also where a legacy
# COMPACT/utf8 table would fail if it ever fails, which is why it is asserted on a real one.
IDX_COLS=$(index_columns wp_slim_stats idx_vid_hash_dt)
if [ "$IDX_COLS" = "vid_hash,dt" ]; then _r=0; else _r=1; fi
check "idx_vid_hash_dt is built on (vid_hash, dt), in that order" "$_r" \
      "${IDX_COLS:-the index does not exist}"

# Explicit optional-repair arm. The ordinary upgrade never opts a site into DDL.
# Existing fingerprints below still compare every historical value after this leg.
if [ "${REHEARSE_WIDTH_REPAIR:-0}" = 1 ]; then
  WIDTH_REPAIR=$(wpc eval '
    $a = SlimStat\Migration\MigrationService::analyticsConnection();
    $m = new SlimStat\Migration\MigrationManager();
    $r = new SlimStat\Migration\Migrations\RepairLegacyColumnWidths($a, $GLOBALS["wpdb"]);
    $m->register($r);
    $offered = $r->shouldRun();
    $first = $m->runOne($r->getId());
    $second = $m->runOne($r->getId());
    echo json_encode(["offered" => $offered, "first" => $first, "second" => $second]);
  ' 2>"$ART/width-repair.stderr")
  printf '%s\n' "$WIDTH_REPAIR" > "$ART/width-repair.json"
  if [ "$WIDTH_REPAIR" = '{"offered":true,"first":true,"second":true}' ]; then
    check "explicit legacy width repair is offered, succeeds, and is idempotent" 0
  else
    check "explicit legacy width repair is offered, succeeds, and is idempotent" 1 "$WIDTH_REPAIR"
  fi
fi

# ── H5 · disarm the projection ─────────────────────────────────────────────
# From here on `notes` is read raw. FP_0 was computed with the pre-4.8.8 rows projected THROUGH
# the plugin's own forward transform; FP_1 reads what the plugin actually wrote. Equality is
# therefore the transform equality — `notes_after = forward(notes_before)` for every converted
# row and `notes_after = notes_before` for every other — expressed as one comparison rather than
# as a weakening. On arms at or above 4.8.8 the projection was never armed and this is a no-op.
FP_NOTES_EXPR="notes"

FP_1=$(fingerprint)
[ "$FP_1" = "$FP_0" ] && check "every pre-existing v5 value is byte-identical" 0 "$FP_1" \
  || check "every pre-existing v5 value is byte-identical" 1 "$FP_0 -> $FP_1"

# The eight columns nothing in any upgrade path is permitted to touch, compared without any
# projection on either side. It is deliberately redundant with the line above on modern arms —
# and it is the only line that stays meaningful if the notes projection is ever wrong, because
# no argument about the notes expression can make this one pass.
FP_CORE_1=$(fingerprint_core)
[ "$FP_CORE_1" = "$FP_CORE_0" ] && check "the eight columns nothing may touch are unchanged" 0 "$FP_CORE_1" \
  || check "the eight columns nothing may touch are unchanged" 1 "$FP_CORE_0 -> $FP_CORE_1"

# THE DRIFT RECORD IS DURABLE, AND NOTHING HAD RE-OBSERVED IT SINCE BEFORE THE MIGRATION.
# `slimstat_schema_column_drift` is written by init_tables() and deliberately CANNOT age out, so
# the copy this leg read was the one the DEFERRED WINDOW wrote -- v6 code on a v5 schema. It still
# said `vid_hash (absent)` about a column R3 had just added: a snapshot of the world BEFORE the leg
# under test, reported as a statement about the world after it. The product does not have that
# defect. refresh_column_drift_notice() re-observes on admin_init and persist_column_drift([])
# clears the option when the drift is gone -- "the durable fact is the thing that is true, and the
# notice is synthesised from it". The cell simply never gave it an admin_init. Drive one, exactly
# where an admin's next page load would. PITFALLS 137.
#
# REQUIRED, and the eval REPORTS. Every `wpc eval` is its own process and `admin/index.php` is
# loaded on admin requests only, so the first draft named `wp_slimstat_admin::` in a process where
# that class did not exist: a fatal, swallowed by `2>/dev/null`, leaving the refresh undone and the
# option unread. It failed the leg below with `stored 'none'` — the right colour for the wrong
# reason, which is the failure mode this whole cell keeps finding. The sentinel is what tells the
# two apart. Run 65 cell 7a pass 4, PITFALLS 137.
DRIFT_REFRESHED=$(wpc eval '
  require_once WP_PLUGIN_DIR . "/wp-slimstat/admin/index.php";
  delete_transient(wp_slimstat_admin::COLUMN_DRIFT_CHECK_TRANSIENT);
  wp_slimstat_admin::refresh_column_drift_notice();
  echo "refreshed";' 2>/dev/null | tr -d '[:space:]')
[ "$DRIFT_REFRESHED" = "refreshed" ] && check "the drift record was re-derived, as an admin_init would" 0 \
  || check "the drift record was re-derived, as an admin_init would" 1 "the refresh did not run — ${DRIFT_REFRESHED:-no output at all}"

# Observed HERE, not read back from the option, and the difference is the whole point: on an arm
# that never drifted there is no option at all, and refresh_column_drift_notice() returns early
# without looking at a single column. Deciding from the option would print PASS on a cell where
# nothing had ever been measured. columnDrift() always looks, and repairs nothing while it does.
DRIFT_NOW=$(wpc eval '
  $d   = SlimStat\Schema\Schema::columnDrift(
      SlimStat\Migration\MigrationService::analyticsConnection(), $GLOBALS["wpdb"]->prefix);
  $d = SlimStat\Schema\Schema::requiredColumnDrift($d, SlimStat\Migration\MigrationManager::completedMigrationIds());
  $out = [];
  foreach ($d["missing"] as $c)        { $out[] = $c . " (absent)"; }
  foreach ($d["narrow"] as $c => $w)   { $out[] = $c . " (" . $w . ")"; }
  sort($out);
  echo implode(", ", $out);' 2>/dev/null | tr -d '\n')
[ -z "$DRIFT_NOW" ] && check "the upgrade left no column drift behind" 0 \
  || check "the upgrade left no column drift behind" 1 "$DRIFT_NOW"

# And the durable record agrees with required drift; unrun optional columns are expected. This is the check that would
# have caught the stale snapshot on its own: it compares what is STORED against what is TRUE,
# so a record written before the migration and never re-derived fails here whether or not the
# drift it describes has healed.
# Read by the option's LITERAL name, not through the class constant: this is the leg that decides
# whether the record is stale, and a read that can fatal returns the empty string — which is
# exactly what a healed record looks like. The gate pins the literal against the constant.
DRIFT_STORED=$(wpc eval '
  echo implode(", ", (array) get_option("slimstat_schema_column_drift", []));' 2>/dev/null | tr -d '\n')
[ "$DRIFT_STORED" = "$DRIFT_NOW" ] && check "and the durable record was re-derived, not replayed" 0 "${DRIFT_NOW:-none}" \
  || check "and the durable record was re-derived, not replayed" 1 "stored '${DRIFT_STORED:-none}' vs on disk '${DRIFT_NOW:-none}'"

# The KEYS and their messages, not a count. "1 recorded" names no step, so the only way to learn
# which one degraded was to change this line and run the whole cell again — 90 seconds of docker
# to ask a question the failing run already had the answer to. A check reports the finding, not
# the cardinality of the findings. PITFALLS 136.
DEGRADED=$(wpc eval 'echo count((array) get_option("slimstat_degradations", []));' 2>/dev/null | tr -d '[:space:]')
DEG_DETAIL=$(wpc eval '
  $out = [];
  foreach ((array) get_option("slimstat_degradations", []) as $k => $v) {
      $out[] = $k . ": " . (is_array($v) && isset($v["message"]) ? $v["message"] : "(no message)");
  }
  echo implode(" | ", $out);' 2>/dev/null | tr -d '\n')
[ "${DEGRADED:-0}" -eq 0 ] && check "no degradation was recorded" 0 \
  || check "no degradation was recorded" 1 "$DEGRADED recorded — ${DEG_DETAIL:-unreadable}"

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
# The baseline is taken HERE, not borrowed from R1. FP_0 is R1's reading, and on any cell whose
# migration legs have already gone red the current fingerprint differs from FP_0 for reasons that
# have nothing to do with vid_hash -- so this control announced "C4 fingerprint is not v5-scoped",
# a diagnosis about a property it had not tested, on a run where the real fault was two legs
# earlier. A control that can only be believed when the cell is already green is not a control.
# Run 65 cell 7a, PITFALLS 133.
FP_PRE_C4=$(fingerprint)
mysql_q "ALTER TABLE wordpress.wp_slim_stats DROP COLUMN vid_hash;" >/dev/null 2>&1
if [ "$(has_column vid_hash)" = 0 ]; then
  note PASS "the column check NOTICES a missing column (it reports absent when it is absent)"
else
  note FAIL "the column check cannot see a dropped column"; fail "C4 column check is blind"
fi
FP_BROKEN=$(fingerprint)
if [ "$FP_BROKEN" != "$FP_PRE_C4" ]; then
  note FAIL "the fingerprint changed when only an ADDED column was dropped"; fail "C4 fingerprint is not v5-scoped"
else
  note PASS "the fingerprint ignores columns the migration added, as its scope requires"
fi
# DROP COLUMN is not a scalpel. MySQL removes the column from every index it takes part in, so
# the statement above also rewrote idx_vid_hash_dt down to (dt) — and re-adding the COLUMN does
# not rebuild the INDEX. Schema::indexState() now reports the wrong definition as malformed,
# but reconciliation deliberately does not DROP user indexes automatically. Historically
# this control handed R7 a schema THIS CONTROL had broken, and R7 reported "idx_vid_hash_dt
# survived the rollback intact — dt" about a rollback that had touched nothing. A control that
# does not put the world back does not test the next leg, it writes the next leg's verdict.
# Dropping the mutilated index by name is what makes the migration's own probe see work to do.
# Run 65 cell 7a, PITFALLS 135.
mysql_q "DROP INDEX idx_vid_hash_dt ON wordpress.wp_slim_stats;" >/dev/null 2>&1
wpc eval '
  $a = SlimStat\Migration\MigrationService::analyticsConnection();
  (new SlimStat\Migration\Migrations\AddVisitIdentity($a,$GLOBALS["wpdb"]))->run(); echo "restored";
' >/dev/null 2>&1
[ "$(has_column vid_hash)" = 1 ] && note PASS "the column was restored for the rollback leg" \
  || { note FAIL "could not restore vid_hash"; fail "C4 restore"; }
IDX_COLS_C4=$(index_columns wp_slim_stats idx_vid_hash_dt)
[ "$IDX_COLS_C4" = "vid_hash,dt" ] \
  && note PASS "and so was the index the drop took with it — vid_hash,dt" \
  || { note FAIL "the control left idx_vid_hash_dt as '${IDX_COLS_C4:-gone}'"; fail "C4 left the index it broke"; }

echo
echo "── R7 · code downgrade to OLD on the migrated schema ─────────────────"
use_ref "$OLD_REF" || exit 1
HIT_3=$(track_hit "rehearse-rollback")
[ "${HIT_3:-0}" -gt 0 ] && check "the previous version still tracks after a migration" 0 "row id $HIT_3" \
  || check "the previous version still tracks after a migration" 1
FP_2=$(fingerprint)
[ "$FP_2" = "$FP_0" ] && check "rollback left every pre-existing v5 value intact" 0 \
  || check "rollback left every pre-existing v5 value intact" 1 "$FP_0 -> $FP_2"
FP_CORE_2=$(fingerprint_core)
[ "$FP_CORE_2" = "$FP_CORE_0" ] && check "and the eight columns nothing may touch are still unchanged" 0 \
  || check "and the eight columns nothing may touch are still unchanged" 1 "$FP_CORE_0 -> $FP_CORE_2"

# H6, after the rollback. The index the P1 read path needs must survive the OLD code being put
# back — a rollback that leaves the column but loses the composite gives a downgraded site a
# scan where it had a ref, which is a performance regression nobody would attribute to the
# rollback. Asserted, because "the schema is additive" is a claim about indexes too.
IDX_COLS_2=$(index_columns wp_slim_stats idx_vid_hash_dt)
if [ "$IDX_COLS_2" = "vid_hash,dt" ]; then _r=0; else _r=1; fi
check "idx_vid_hash_dt survived the code downgrade intact" "$_r" "${IDX_COLS_2:-the index is gone}"

echo
echo "── R8 · restore the exact pre-migration database backup ─────────────────"
prove_backup_recovery && check "backup restores schema/data and explicitly loses later writes" 0 \
  || { check "backup restores schema/data and explicitly loses later writes" 1; exit 1; }

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

# ── H7/H8 · a verdict that outlives the container ──────────────────────────
# The extra fragment carries the four facts that make a recorded PASS re-readable a year from
# now: which arms, on which topology, over which corpus. A cell.json that says only
# {"cell":"upgrade-u1","status":"PASS"} is a claim with no subject — Run 63's two verdicts said
# exactly that, and they are gone anyway, which is the other half of what this fixes.
write_verdict "$ART" "$CELL" "$PHP" "$WP" "$status" "$reason" \
  "\"ws4_cell\":\"${CELL_KEY:-unpinned}\",\"old_ref\":\"$OLD_REF\",\"new_ref\":\"$NEW_REF\",\"arm_version\":\"${ARM_FREE_VERSION:-git}\",\"arm_options\":\"${REHEARSE_ARM_OPTIONS:-present}\",\"width_repair_opt_in\":\"${REHEARSE_WIDTH_REPAIR:-0}\",\"corpus\":\"$(basename "$DUMP")\",\"corpus_sha256\":\"$(digest "$DUMP")\",\"rows\":${ROWS_2:-0},\"base_max_id\":${BASE_MAX_ID:-0},\"notes_pending\":${NOTES_PENDING_0:-0},\"fp\":\"$FP_0\",\"fp_core\":\"$FP_CORE_0\"" \
  2>/dev/null || true

# $ART is under /tmp, and /tmp is why Run 63's verdicts do not exist to be read. This copies the
# verdict into the tracked programme directory, where the next session finds it without having
# to rerun a 90-minute cell to learn what the last one concluded.
DEST=$(publish_verdict "$ART" "$CELL" legacy-upgrade.log import.err 2>/dev/null || true)
[ -n "${DEST:-}" ] && echo "  verdict: $DEST/cell.json"

echo
if [ "$status" = "PASS" ]; then
  echo "VERDICT: required assertions passed on this data — $ROWS_2 rows, v5 fingerprint unchanged across the migration"
  echo "  from ${ARM_FREE_VERSION:-$OLD_REF} to $NEW_REF on WP $WP / PHP $PHP, over $(basename "$DUMP")"
  exit 0
fi
echo "VERDICT: FAILED — $reason"
exit 1
