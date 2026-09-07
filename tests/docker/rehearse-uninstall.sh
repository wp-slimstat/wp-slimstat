#!/usr/bin/env bash
# Disposable X0/X1/X3/X4 lifecycle proof. No production dumps or existing stacks are accepted.
# Usage: REHEARSAL_RUNS_DIR=/durable/runs PRO_REPO=/path/to/pro bash rehearse-uninstall.sh FREE_SHA PRO_SHA fixture.json
# Pro is an immutable source archive (not a scoped release ZIP). Lifecycle/ownership only.
# Required-red reruns: UNINSTALL_MUTATION=credentials|drop-retained|retain-deleted.
set -euo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
FREE_REF="${1:?Free commit required}"; PRO_REF="${2:?Pro commit required}"; FIXTURE="${3:?Explicit synthetic fixture required}"
PRO_REPO="${PRO_REPO:-$PLUGIN_SRC/../wp-slimstat-pro}"
: "${REHEARSAL_RUNS_DIR:?Set a durable evidence directory}"
[ -f "$FIXTURE" ] || die 'fixture absent'
FREE_SHA=$(git -C "$PLUGIN_SRC" rev-parse "$FREE_REF^{commit}")
PRO_SHA=$(git -C "$PRO_REPO" rev-parse "$PRO_REF^{commit}")
FIXTURE_SHA=$(digest "$FIXTURE")
python3 - "$FIXTURE" <<'PY'
import json,sys
f=json.load(open(sys.argv[1])); assert f['format']=='slimstat-uninstall-v1'
assert all(isinstance(f[k],str) and f[k] for k in ['marker','resource','geo','cache'])
PY
MUTATION="${UNINSTALL_MUTATION:-none}"
case "$MUTATION" in none|credentials|drop-retained|retain-deleted) ;; *) die 'unknown mutation';; esac
mkdir -p "$WORK_ROOT" "$REHEARSAL_RUNS_DIR"
CELL_DIR=$(mktemp -d "$WORK_ROOT/uninstall.XXXXXXXX")
export COMPOSE_PROJECT_NAME="ssuninstall$(basename "$CELL_DIR" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9')"
[ -z "$(docker ps -aq --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME")" ] || die 'project collision refused'
export CELL_WP_DIR="$CELL_DIR/wp" PHP_VERSION="${TOPOLOGY_PHP:-8.2}" HTTP_PORT=0 DB_PORT=0
export DC_EXTRA_FILE="$CELL_DIR/compose-extra.yml"
ART="$CELL_DIR/artifacts"; mkdir -p "$ART" "$CELL_WP_DIR" "$CELL_DIR/pro-src"
STARTED=$(now); status=FAIL; reason='setup incomplete'; FINISHED=0
preserve_artifacts() {
  python3 - "$ART" <<'PYHASH'
import hashlib,json,pathlib,sys
p=pathlib.Path(sys.argv[1])
json.dump({str(f.relative_to(p)):hashlib.sha256(f.read_bytes()).hexdigest() for f in p.rglob("*") if f.is_file() and f.name!="artifacts.sha256.json"},open(p/"artifacts.sha256.json","w"),indent=2)
PYHASH
  mkdir -p "$REHEARSAL_RUNS_DIR/$COMPOSE_PROJECT_NAME"
  cp -R "$ART/." "$REHEARSAL_RUNS_DIR/$COMPOSE_PROJECT_NAME/"
}
finish() {
  local rc=$?
  trap - EXIT
  if [ "$FINISHED" -ne 1 ]; then
    write_verdict "$ART" "$COMPOSE_PROJECT_NAME" "$PHP_VERSION" "${TOPOLOGY_WP:-6.7}" FAIL "$reason"
    publish_verdict "$ART" "$COMPOSE_PROJECT_NAME" manifest.json install.log build.log up.log >/dev/null || true
  fi
  dc down -v --remove-orphans >"$ART/cleanup.log" 2>&1 || true
  preserve_artifacts
  cleanup_free_arm
  exit "$rc"
}
trap finish EXIT
cat > "$DC_EXTRA_FILE" <<'YAML'
services:
  analytics-db:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: analytics
    tmpfs:
      - /var/lib/mysql
YAML
build_free_arm "$FREE_SHA" "$CELL_DIR"
if [ -n "${QUALIFICATION_PRO_ZIP:-}" ]; then
  extract_qualification_artifact "$QUALIFICATION_PRO_ZIP" "${QUALIFICATION_PRO_SHA256:?Pro ZIP digest required}" wp-slimstat-pro "$CELL_DIR/pro-artifact"
  rmdir "$CELL_DIR/pro-src"
  mv "$CELL_DIR/pro-artifact/wp-slimstat-pro" "$CELL_DIR/pro-src"
else
  git -C "$PRO_REPO" archive "$PRO_SHA" | tar -xf - -C "$CELL_DIR/pro-src"
fi
[ -z "${QUALIFICATION_FREE_ZIP:-}${QUALIFICATION_PRO_ZIP:-}" ] || {
  : "${QUALIFICATION_FREE_ZIP:?Both packaged artifacts required}" "${QUALIFICATION_PRO_ZIP:?Both packaged artifacts required}"
}
cp "$FIXTURE" "$ART/fixture.json"
cp "$HARNESS_DIR/uninstall-oracle.php" "$ART/oracle.php"
python3 - "$ART/manifest.json" "$FREE_SHA" "$PRO_SHA" "$FIXTURE_SHA" "$STARTED" "$MUTATION" "$HARNESS_DIR" "${QUALIFICATION_FREE_SHA256:-}" "${QUALIFICATION_PRO_SHA256:-}" <<'PY'
import hashlib,json,pathlib,sys
out,free,pro,fixture,start,mutation,harness,fzip,pzip=sys.argv[1:]
files=['rehearse-uninstall.sh','probe-uninstall.php','uninstall-oracle.php','lib.sh','extract-artifact.py','docker-compose.yml','Dockerfile.wp']
json.dump(dict(free_sha=free,pro_sha=pro,fixture_sha256=fixture,started=start,mutation=mutation,
 old_zip_sha256=None,old_zip_reason='synthetic uninstall fixture; no vintage import',artifact_kind='checksummed-zip' if fzip and pzip else 'committed-source',free_zip_sha256=fzip or None,pro_zip_sha256=pzip or None,
 source_hashes={f:hashlib.sha256((pathlib.Path(harness)/f).read_bytes()).hexdigest() for f in files}),open(out,'w'),indent=2)
PY
reason='disposable stack boot failed'; boot_stack "$ART" "$PHP_VERSION"
wait_for 40 2 dc exec -T analytics-db mysqladmin ping -h127.0.0.1 -uroot -proot --silent
BASE_URL="http://localhost:$(dc port wp 80 | sed 's/.*://')"
reason='WordPress provisioning failed'
wpc core download --version="${TOPOLOGY_WP:-6.7}" --force >"$ART/install.log" 2>&1
wpc config create --dbname=wordpress --dbuser=root --dbpass=root --dbhost=db:3306 --dbprefix=ssun_ --skip-check >>"$ART/install.log" 2>&1
wpc config set SLIMSTAT_UNINSTALL_REHEARSAL disposable >>"$ART/install.log" 2>&1
wpc config set DISABLE_WP_CRON true --raw >>"$ART/install.log" 2>&1
wpc core multisite-install --url="$BASE_URL" --title=UninstallRehearsal --admin_user=admin --admin_password=disposable --admin_email=qa@example.invalid --skip-email >>"$ART/install.log" 2>&1
wpc site create --slug=second >>"$ART/install.log" 2>&1
for mapping in "$HARNESS_DIR/probe-uninstall.php:probe-uninstall.php" "$FREE_SRC/src/Schema/Schema.php:uninstall-schema.php" "$FREE_SRC/src/cron-hooks.php:uninstall-cron-hooks.php" "$CELL_DIR/pro-src/uninstall.php:uninstall-pro.php" "$ART/fixture.json:uninstall-fixture.json"; do
  dc cp "${mapping%:*}" "wp:/tmp/${mapping##*:}" >/dev/null
 done
# Image IDs bind the actual runtime, not mutable tags. Capture versions from running services.
dc images --format json >"$ART/images.json"
for image_id in $(dc images -q | sort -u); do docker image inspect "$image_id" --format '{{json .}}'; done >"$ART/image-inspect.jsonl"
wpc core version >"$ART/wp-version.txt"
dc exec -T wp php -r 'echo PHP_VERSION;' >"$ART/php-version.txt"
mysql_q 'SELECT VERSION()' >"$ART/database-version.txt"
dc exec -T analytics-db mysql -uroot -proot -N -e 'SELECT VERSION()' >"$ART/external-version.txt" 2>/dev/null
snapshot() {
  wpc --skip-plugins --skip-themes eval-file /tmp/probe-uninstall.php snapshot "$mode" "$owner" >"$case_art/$1.log" 2>&1
  python3 - "$case_art/$1.log" "$case_art/$1.json" <<'PY'
import json,sys
lines=[x.removeprefix('UNINSTALL-JSON:') for x in open(sys.argv[1]) if x.startswith('UNINSTALL-JSON:')]
assert len(lines)==1, 'missing or duplicate snapshot marker'
json.dump(json.loads(lines[0]),open(sys.argv[2],'w'),sort_keys=True)
PY
}
status=PASS; reason=''; total=0; failed=0
for spec in keep:local:0 keep-no:local:0 keep:external:1 delete:local:0 delete:external:1 delete-path-refused:local:0 pro:external:1 pro-then-free:external:1 cli-delete:local:0; do
  IFS=: read -r mode owner paired <<<"$spec"
  case_started=$(now)
  name="$mode-$owner-p$paired"; case_art="$ART/$name"; mkdir -p "$case_art"
  reason="case $name did not reach its verdict"
  rm -f "$CELL_WP_DIR/wp-content/mu-plugins/uninstall-refuse-path.php"
  if [ "$mode" = delete-path-refused ]; then
    mkdir -p "$CELL_WP_DIR/wp-content/mu-plugins"
    printf '%s\n' '<?php add_filter("slimstat_maxmind_path", static function () { return WP_CONTENT_DIR . "/uploads"; });' > "$CELL_WP_DIR/wp-content/mu-plugins/uninstall-refuse-path.php"
  fi
  sync_plugin_src "$CELL_WP_DIR" "$FREE_SRC"
  rm -rf "$CELL_WP_DIR/wp-content/plugins/wp-slimstat-pro"
  if [ "$paired" = 1 ]; then
    cp -R "$CELL_DIR/pro-src" "$CELL_WP_DIR/wp-content/plugins/wp-slimstat-pro"
    chmod -R a+rwX "$CELL_WP_DIR/wp-content/plugins/wp-slimstat-pro"
  fi
  # Boot/activation is a separate recorded leg. Uninstall then follows the normal inactive path.
  wpc plugin activate wp-slimstat --network >"$case_art/activation.log" 2>&1
  wpc plugin is-active wp-slimstat --network >>"$case_art/activation.log" 2>&1
  if [ "$paired" = 1 ]; then
    wpc plugin activate wp-slimstat-pro --network >>"$case_art/activation.log" 2>&1
    wpc plugin is-active wp-slimstat-pro --network >>"$case_art/activation.log" 2>&1
  fi
  wpc plugin deactivate --all --network >>"$case_art/activation.log" 2>&1
  wpc --skip-plugins --skip-themes eval-file /tmp/probe-uninstall.php seed "$mode" "$owner" >"$case_art/seed.log" 2>&1
  grep -q '^UNINSTALL-SEEDED$' "$case_art/seed.log"
  snapshot before
  # X0 non-vacuity is checked independently of the comparator and before a destructive call.
  python3 - "$case_art/before.json" <<'PY'
import json,sys
s=json.load(open(sys.argv[1])); assert len(s['blogs'])==2
assert all(v and v['rows']>0 for tables in s['tables'].values() for v in tables.values())
assert all(v for v in s['sentinels'].values()) and all(s['files'].values())
assert s['network_credentials'] and all(b['credentials'] and all(b['free_cron'].values()) and all(b['pro_cron'].values()) for b in s['blogs'].values())
PY
  python3 - "$CELL_WP_DIR/wp-content/plugins/wp-slimstat/uninstall.php" "$MUTATION" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1]); s=p.read_text(); kind=sys.argv[2]
if kind=='credentials':
 old='$stripped = array_diff_key($options, array_flip(slimstat_uninstall_credential_keys()));'; new='$stripped = $options;'
elif kind in ['drop-retained','retain-deleted']:
 old="$slimstat_delete_data = ('on' === ($slimstat_options['delete_data_on_uninstall'] ?? 'no'));"
 new='$slimstat_delete_data = '+('true' if kind=='drop-retained' else 'false')+';'
else: sys.exit(0)
assert s.count(old)==1, 'mutation anchor absent or ambiguous'; p.write_text(s.replace(old,new))
PY
  digest "$CELL_WP_DIR/wp-content/plugins/wp-slimstat/uninstall.php" >"$case_art/executed-free-uninstall.sha256"
  if [ "$mode" = pro-then-free ]; then
    wpc --skip-plugins --skip-themes plugin uninstall wp-slimstat-pro >"$case_art/pro-lifecycle.log" 2>&1
    snapshot after-pro
    php -r 'require $argv[1]; $b=json_decode(file_get_contents($argv[2]),true); $a=json_decode(file_get_contents($argv[3]),true); $f=slimstat_uninstall_compare($b,$a,"pro","external",true); echo json_encode($f); exit([]===$f?0:1);' \
      "$ART/oracle.php" "$case_art/before.json" "$case_art/after-pro.json" >"$case_art/pro-failures.json"
  fi
  if [ "$mode" = cli-delete ]; then
    wpc --skip-plugins --skip-themes plugin delete wp-slimstat >"$case_art/lifecycle.log" 2>&1
  else
    target=wp-slimstat; [ "$mode" != pro ] || target=wp-slimstat-pro
    wpc --skip-plugins --skip-themes plugin uninstall "$target" >"$case_art/lifecycle.log" 2>&1
  fi
  snapshot after
  compare_mode="$mode"; compare_paired="$paired"; compare_before="$case_art/before.json"
  if [ "$mode" = pro-then-free ]; then compare_mode=delete; compare_paired=0; compare_before="$case_art/after-pro.json"; fi
  php -r 'require $argv[1]; $b=json_decode(file_get_contents($argv[2]),true); $a=json_decode(file_get_contents($argv[3]),true); echo json_encode(slimstat_uninstall_compare($b,$a,$argv[4],$argv[5],"1"===$argv[6]));' \
    "$ART/oracle.php" "$compare_before" "$case_art/after.json" "$compare_mode" "$owner" "$compare_paired" >"$case_art/failures.json"
  case_status=PASS; case_reason=''
  if [ "$(cat "$case_art/failures.json")" != '[]' ]; then case_status=FAIL; case_reason='lifecycle invariant failed; see failures.json'; failed=$((failed+1)); fi
  total=$((total+1))
  write_verdict "$case_art" "$name" "$(cat "$ART/php-version.txt")" "$(cat "$ART/wp-version.txt")" "$case_status" "$case_reason" \
    "\"free_sha\":\"$FREE_SHA\",\"pro_sha\":\"$PRO_SHA\",\"fixture_sha256\":\"$FIXTURE_SHA\",\"mutation\":\"$MUTATION\",\"started\":\"$case_started\",\"required_legs\":4,\"reached_legs\":4"
  publish_verdict "$case_art" "$COMPOSE_PROJECT_NAME/$name" before.json after.json failures.json activation.log seed.log lifecycle.log >/dev/null
  log "$name $case_status"
done
[ "$failed" = 0 ] || status=FAIL
reason="$failed lifecycle cases failed"
[ "$total" -eq 9 ] || { status=FAIL; reason="missing lifecycle cases"; }
# Required-red means observed failure, never an inverted green verdict. Callers assert nonzero.
[ "$MUTATION" = none ] || { [ "$failed" -gt 0 ] || { status=FAIL; reason='required-red mutation survived'; }; }
[ "$(digest "$FIXTURE")" = "$FIXTURE_SHA" ] || { status=FAIL; reason='source fixture changed'; }
write_verdict "$ART" "$COMPOSE_PROJECT_NAME" "$(cat "$ART/php-version.txt")" "$(cat "$ART/wp-version.txt")" "$status" "$reason" \
  "\"free_sha\":\"$FREE_SHA\",\"pro_sha\":\"$PRO_SHA\",\"fixture_sha256\":\"$FIXTURE_SHA\",\"started\":\"$STARTED\",\"required_cases\":9,\"reached_cases\":$total,\"mutation\":\"$MUTATION\",\"pro_only_credentials\":\"retained; policy unresolved\""
publish_verdict "$ART" "$COMPOSE_PROJECT_NAME" manifest.json fixture.json images.json wp-version.txt php-version.txt database-version.txt external-version.txt >/dev/null
FINISHED=1
log "verdict $REHEARSAL_RUNS_DIR/$COMPOSE_PROJECT_NAME/cell.json"
[ "$status" = PASS ]
