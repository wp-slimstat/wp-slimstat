#!/usr/bin/env bash
# H9-H11: 5.5.0 synthetic per-blog data, 100 subsites, real boundary SIGKILL, HTTP resume.
# Usage: PRO_REPO=/repo/pro REHEARSAL_RUNS_DIR=/durable/network-upgrade-proof bash ... wp.org:5.5.0 FREE_SHA PRO_SHA fixture.json
# NETWORK_MUTATION=no-resume is required-red. Pro is committed source, not a release ZIP.
# This proves interruption BETWEEN completed sites, not recovery from a kill inside DDL.
set -euo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
OLD_REF="${1:?old ref}"; FREE_REF="${2:?Free commit}"; PRO_REF="${3:?Pro commit}"; FIXTURE="${4:?synthetic fixture}"
: "${REHEARSAL_RUNS_DIR:?Use outputs/dev/v6-finalization/network-upgrade-proof}"
[ "$OLD_REF" = wp.org:5.5.0 ] || die 'Only pinned 5.5.0 is characterized; other vintages require their own projection'
PRO_REPO="${PRO_REPO:-$PLUGIN_SRC/../wp-slimstat-pro}"
FREE_SHA=$(git -C "$PLUGIN_SRC" rev-parse "$FREE_REF^{commit}")
PRO_SHA=$(git -C "$PRO_REPO" rev-parse "$PRO_REF^{commit}")
OLD_ZIP=$(resolve_arm_zip "$OLD_REF"); OLD_HASH=$(digest "$OLD_ZIP"); FIXTURE_HASH=$(digest "$FIXTURE")
python3 - "$FIXTURE" <<'PY'
import json,sys
f=json.load(open(sys.argv[1])); assert f['format']=='slimstat-network-v1' and f['subsites']==100 and f['kill_after_completed']==3 and f['archived_blog']==4
PY
MUTATION="${NETWORK_MUTATION:-none}"
case "$MUTATION" in none|no-resume) ;; *) die 'Unknown mutation';; esac
mkdir -p "$WORK_ROOT" "$REHEARSAL_RUNS_DIR"
CELL_DIR=$(mktemp -d "$WORK_ROOT/network.XXXXXXXX")
export COMPOSE_PROJECT_NAME="ssnetwork$(basename "$CELL_DIR" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9')"
[ -z "$(docker ps -aq --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME")" ] || die 'Project collision'
export CELL_WP_DIR="$CELL_DIR/wp" PHP_VERSION="${TOPOLOGY_PHP:-8.2}" HTTP_PORT=0 DB_PORT=0
unset DC_EXTRA_FILE
ART="$CELL_DIR/artifacts"; mkdir -p "$ART" "$CELL_WP_DIR" "$CELL_DIR/pro-src"
STARTED=$(now); reason='setup incomplete'; status=FAIL; reached=0
finish() {
  local rc=$?
  trap - EXIT
  [ "$rc" = 0 ] || status=FAIL
  dc cp wp:/tmp/network-progress.jsonl "$ART/progress.jsonl" >/dev/null 2>&1 || true
  dc cp wp:/tmp/network-flushes.jsonl "$ART/flushes.jsonl" >/dev/null 2>&1 || true
  dc cp wp:/tmp/network-htaccess.jsonl "$ART/htaccess.jsonl" >/dev/null 2>&1 || true
  write_verdict "$ART" "$COMPOSE_PROJECT_NAME" "$PHP_VERSION" "${TOPOLOGY_WP:-6.7}" "$status" "$reason" \
    "\"free_sha\":\"$FREE_SHA\",\"pro_sha\":\"$PRO_SHA\",\"old_zip_sha256\":\"$OLD_HASH\",\"fixture_sha256\":\"$FIXTURE_HASH\",\"mutation\":\"$MUTATION\",\"started\":\"$STARTED\",\"required_legs\":3,\"reached_legs\":$reached"
  publish_verdict "$ART" "$COMPOSE_PROJECT_NAME" manifest.json >/dev/null || true
  dc down -v --remove-orphans >"$ART/cleanup.log" 2>&1 || true
  if [ -n "${CURL_PID:-}" ]; then wait "$CURL_PID" 2>/dev/null || true; fi
  cleanup_free_arm
  python3 - "$ART" <<'PY'
import pathlib,hashlib,json,sys
p=pathlib.Path(sys.argv[1]); json.dump({str(f.relative_to(p)):hashlib.sha256(f.read_bytes()).hexdigest() for f in p.rglob('*') if f.is_file() and f.name!='artifacts.sha256.json'},open(p/'artifacts.sha256.json','w'),indent=2)
PY
  cp -R "$ART/." "$REHEARSAL_RUNS_DIR/$COMPOSE_PROJECT_NAME/"
  log "$status $REHEARSAL_RUNS_DIR/$COMPOSE_PROJECT_NAME/cell.json"
  exit "$rc"
}
trap finish EXIT
build_free_arm "$FREE_SHA" "$CELL_DIR"
git -C "$PRO_REPO" archive "$PRO_SHA" | tar -xf - -C "$CELL_DIR/pro-src"
cp "$FIXTURE" "$ART/fixture.json"
python3 - "$ART/manifest.json" "$FREE_SHA" "$PRO_SHA" "$OLD_HASH" "$FIXTURE_HASH" "$MUTATION" "$HARNESS_DIR" <<'PY'
import json,hashlib,pathlib,sys
out,free,pro,old,fixture,mutation,h=sys.argv[1:]
files=['rehearse-upgrade-network.sh','probe-network-rehearsal.php','network-rehearsal-observer.php','network-rehearsal-oracle.php','watch-network-htaccess.py','lib.sh','Dockerfile.wp','docker-compose.yml']
json.dump(dict(free_sha=free,pro_sha=pro,old_zip_sha256=old,fixture_sha256=fixture,mutation=mutation,artifact_kind='committed-source',interruption='SIGKILL after durable site completion; interior DDL not covered',source_hashes={f:hashlib.sha256((pathlib.Path(h)/f).read_bytes()).hexdigest() for f in files}),open(out,'w'),indent=2)
PY
reason='stack boot failed'; boot_stack "$ART" "$PHP_VERSION"
BASE_URL="http://localhost:$(dc port wp 80 | sed 's/.*://')"
reason='WordPress provisioning failed'
wpc core download --version="${TOPOLOGY_WP:-6.7}" --force >"$ART/install.log" 2>&1
wpc config create --dbname=wordpress --dbuser=root --dbpass=root --dbhost=db:3306 --dbprefix=ssnw_ --skip-check >>"$ART/install.log" 2>&1
wpc config set SLIMSTAT_NETWORK_REHEARSAL disposable >>"$ART/install.log" 2>&1
wpc config set DISABLE_WP_CRON true --raw >>"$ART/install.log" 2>&1
wpc core multisite-install --url="$BASE_URL" --title=NetworkRehearsal --admin_user=admin --admin_password=disposable --admin_email=qa@example.invalid --skip-email >>"$ART/install.log" 2>&1
for map in "$HARNESS_DIR/probe-network-rehearsal.php:network-probe.php" "$FREE_SRC/src/Schema/Schema.php:network-schema.php" "$FIXTURE:network-fixture.json" "$OLD_ZIP:old.zip" "$HARNESS_DIR/watch-network-htaccess.py:watch-htaccess.py"; do dc cp "${map%:*}" "wp:/tmp/${map##*:}" >/dev/null; done
wpc --skip-plugins eval-file /tmp/network-probe.php create >>"$ART/install.log" 2>&1
grep -q '^NETWORK-CREATED$' "$ART/install.log"
for image_id in $(dc images -q | sort -u); do docker image inspect "$image_id" --format '{{json .}}'; done >"$ART/images.jsonl"
wpc core version >"$ART/wp-version.txt"; dc exec -T wp php -r 'echo PHP_VERSION;' >"$ART/php-version.txt"; mysql_q 'SELECT VERSION()' >"$ART/database-version.txt"

wpc plugin install /tmp/old.zip --force >>"$ART/install.log" 2>&1
[ "$(wpc plugin get wp-slimstat --field=version)" = 5.5.0 ] || die 'Vintage header mismatch'
wpc plugin activate wp-slimstat --network >>"$ART/install.log" 2>&1
wpc --skip-plugins eval-file /tmp/network-probe.php urls >"$ART/site-urls.txt" 2>"$ART/urls-error.log"
reason='per-blog vintage installer failed'
installed_sites=0
while IFS= read -r site_url; do
  result=$(run_vintage_installer "$site_url" </dev/null 2>>"$ART/vintage-errors.log")
  [ "$result" = admin/index.php ] || die 'Vintage installer did not reach its marker'
  installed_sites=$((installed_sites+1))
done <"$ART/site-urls.txt"
[ "$installed_sites" -eq 101 ] || die "Expected 101 vintage installers, reached $installed_sites"
wpc --skip-plugins eval-file /tmp/network-probe.php seed >"$ART/seed.log" 2>&1
grep -q '^NETWORK-SEEDED$' "$ART/seed.log"
snapshot() {
  wpc --skip-plugins --skip-themes eval-file /tmp/network-probe.php "${2:-snapshot}" >"$ART/$1.log" 2>&1
  python3 - "$ART/$1.log" "$ART/$1.json" <<'PY'
import json,sys
v=[s[len('NETWORK-JSON:'):] for s in open(sys.argv[1]) if s.startswith('NETWORK-JSON:')]; assert len(v)==1
json.dump(json.loads(v[0]),open(sys.argv[2],'w'),sort_keys=True)
PY
}
snapshot before baseline
python3 - "$ART/before.json" <<'PY'
import json,sys
s=json.load(open(sys.argv[1])); assert len(s['blogs'])==101 and s['blogs']['4']['archived']; assert len({b['fingerprint'] for b in s['blogs'].values()})==101
assert all(b['missing'] for b in s['blogs'].values()), 'Every vintage site must owe current manifest tables'
PY
reached=1
wpc plugin deactivate wp-slimstat --network >"$ART/deactivate.log" 2>&1
sync_plugin_src "$CELL_WP_DIR" "$FREE_SRC"
mkdir -p "$CELL_WP_DIR/wp-content/mu-plugins"
cp "$HARNESS_DIR/network-rehearsal-observer.php" "$CELL_WP_DIR/wp-content/mu-plugins/network-rehearsal-observer.php"
printf '%s\n' '# network rehearsal sentinel' >"$CELL_WP_DIR/.htaccess"
chmod -R a+rwX "$CELL_WP_DIR/wp-content"; chmod a+rw "$CELL_WP_DIR/.htaccess"
dc exec -d wp python3 /tmp/watch-htaccess.py
wait_for 20 1 dc exec -T wp test -f /tmp/network-watch-ready
# Prove the kernel observer notices a real write before requiring zero writes from the walk.
dc exec -T wp sh -c 'printf "%s\n" "# observer control" >> /var/www/html/.htaccess'
wait_for 20 1 dc exec -T wp test -s /tmp/network-htaccess.jsonl
dc cp wp:/tmp/network-htaccess.jsonl "$ART/htaccess-control.jsonl" >/dev/null
dc exec -T wp truncate -s 0 /tmp/network-htaccess.jsonl
reason='initial bounded activation failed'
TIMEFORMAT='%3R'
{ time wpc plugin activate wp-slimstat --network >"$ART/activation.log" 2>&1; } 2>"$ART/activation-seconds.txt"
wpc plugin is-active wp-slimstat --network >>"$ART/activation.log" 2>&1
snapshot initial
python3 - "$ART/before.json" "$ART/initial.json" "$ART/kill-target.json" <<'PY'
import json,sys
b=json.load(open(sys.argv[1])); s=json.load(open(sys.argv[2])); assert s['network_active'] and 3<len(s['pending'])<101
assert {str(i) for i in s['pending']}=={i for i,x in s['blogs'].items() if x['missing']}
assert all(x['fingerprint']==s['blogs'][i]['fingerprint'] for i,x in b['blogs'].items())
json.dump({'remaining':len(s['pending'])-3},open(sys.argv[3],'w'))
PY
cp -R "$CELL_DIR/pro-src" "$CELL_WP_DIR/wp-content/plugins/wp-slimstat-pro"
chmod -R a+rwX "$CELL_WP_DIR/wp-content/plugins/wp-slimstat-pro"
wpc plugin activate wp-slimstat-pro --network >"$ART/pro-activation.log" 2>&1
wpc plugin is-active wp-slimstat-pro --network >>"$ART/pro-activation.log" 2>&1
# Authenticate before starting the request that will be killed; do not follow login redirects.
curl --noproxy '*' -fsS -c "$CELL_DIR/cookies" "$BASE_URL/wp-login.php" -o "$ART/login-form.html"
curl --noproxy '*' -sS -b "$CELL_DIR/cookies" -c "$CELL_DIR/cookies" --data 'log=admin&pwd=disposable&testcookie=1' "$BASE_URL/wp-login.php" -o "$ART/login.html"
dc cp "$ART/kill-target.json" wp:/tmp/network-kill-target.json >/dev/null
reason='HTTP worker did not pause after completed sites'
curl --noproxy '*' -sS --max-time 45 -b "$CELL_DIR/cookies" "$BASE_URL/wp-admin/network/" -o "$ART/interrupted-http.html" -w '%{http_code} %{time_total}\n' >"$ART/interrupted-http.metrics" 2>"$ART/interrupted-http.error" &
CURL_PID=$!
wait_for 25 1 dc exec -T wp test -f /tmp/network-paused.json
dc cp wp:/tmp/network-paused.json "$ART/paused.json" >/dev/null
WORKER_PID=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["pid"])' "$ART/paused.json")
[[ "$WORKER_PID" =~ ^[0-9]+$ ]] && [ "$WORKER_PID" -gt 1 ] || die 'Invalid owned worker PID'
dc exec -T wp kill -9 "$WORKER_PID"
set +e
wait "$CURL_PID"; HTTP_RC=$?
set -e
printf '%s\n' "$HTTP_RC" >"$ART/interrupted-http.exit"
[ "$HTTP_RC" -ne 0 ] || die 'Killed HTTP request unexpectedly completed normally'
wait_for 10 1 dc exec -T wp sh -c 'test ! -d "/proc/$1"' sh "$WORKER_PID" || die 'Worker was not reaped after SIGKILL'
printf '%s\n' "$WORKER_PID reaped after SIGKILL" >"$ART/worker-exit.txt"
dc exec -T wp rm /tmp/network-kill-target.json
snapshot interrupted
python3 - "$ART/before.json" "$ART/initial.json" "$ART/interrupted.json" "$ART/paused.json" <<'PY'
import json,sys
b,i,s,p=[json.load(open(f)) for f in sys.argv[1:]]
assert s['pending']==i['pending'][3:]==p['pending'] and s['pending']
assert {str(x) for x in s['pending']}=={x for x,v in s['blogs'].items() if v['missing']}
assert all(v['fingerprint']==s['blogs'][k]['fingerprint'] and v['archived']==s['blogs'][k]['archived'] for k,v in b['blogs'].items())
PY
reached=2
if [ "$MUTATION" = no-resume ]; then
  python3 - "$CELL_WP_DIR/wp-content/plugins/wp-slimstat/wp-slimstat.php" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1]); s=p.read_text(); anchor="add_action('admin_init', ['wp_slimstat', 'continue_network_activation']);"
assert s.count(anchor)==1; p.write_text(s.replace(anchor,'/* required-red: continuation hook removed */'))
PY
  sleep 2
fi
digest "$CELL_WP_DIR/wp-content/plugins/wp-slimstat/wp-slimstat.php" >"$ART/executed-free.sha256"
reason='network-admin continuation left incomplete sites'
for request in $(seq 1 20); do
  curl --noproxy '*' -fsS --max-time 45 -b "$CELL_DIR/cookies" "$BASE_URL/wp-admin/network/" -o "$ART/resume-$request.html" -w '%{http_code} %{time_total}\n' >"$ART/resume-$request.metrics"
  grep -q 'NETWORK-ADMIN:' "$ART/resume-$request.html" || die 'Authenticated network-admin marker missing'
  snapshot "resume-$request"
  remaining=$(python3 -c 'import json,sys; print(len(json.load(open(sys.argv[1]))["pending"]))' "$ART/resume-$request.json")
  log "network request $request: $remaining pending"
  [ "$remaining" -ne 0 ] || break
  if [ "$MUTATION" = no-resume ] && [ "$request" -ge 2 ]; then break; fi
done
cp "$ART/resume-$request.json" "$ART/after.json"
reached=3
php -r 'require $argv[1]; $f=slimstat_network_rehearsal_compare(json_decode(file_get_contents($argv[2]),true),json_decode(file_get_contents($argv[3]),true)); echo json_encode($f); exit([]===$f?0:1);' "$HARNESS_DIR/network-rehearsal-oracle.php" "$ART/before.json" "$ART/after.json" >"$ART/failures.json"
[ "$MUTATION" = none ] || die 'Required-red mutation survived'
# On this multisite core hard flushes may be requested, but save_mod_rewrite_rules must not rewrite .htaccess.
dc cp wp:/tmp/network-flushes.jsonl "$ART/flushes.jsonl" >/dev/null
dc cp wp:/tmp/network-htaccess.jsonl "$ART/htaccess.jsonl" >/dev/null
python3 - "$ART/flushes.jsonl" "$ART/htaccess.jsonl" <<'PY'
import json,sys,collections
flush=[json.loads(s) for s in open(sys.argv[1])]; counts=collections.Counter(x['blog'] for x in flush)
assert set(counts)==set(range(1,102)) and all(n==1 for n in counts.values()), counts
assert not open(sys.argv[2]).read().strip(), 'Unexpected actual htaccess writes'
PY
[ "$(digest "$FIXTURE")" = "$FIXTURE_HASH" ] || die 'Source fixture changed'
status=PASS; reason='101 sites complete after actual boundary SIGKILL and authenticated HTTP continuation'
