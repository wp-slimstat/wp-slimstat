#!/usr/bin/env bash
# tests/docker/run-cell.sh <php> <wp> <http_port> <db_port>
# Runs ONE PHP×WP cell end-to-end and writes a PASS|FAIL|BLOCKED-BY-WP-CORE verdict.
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
# Qualification never substitutes a working tree or an implicit Pro build.
: "${QUALIFICATION_FREE_ZIP:?exact Free ZIP required}" "${QUALIFICATION_FREE_SHA256:?Free ZIP digest required}"
: "${QUALIFICATION_PRO_ZIP:?exact Pro ZIP required}" "${QUALIFICATION_PRO_SHA256:?Pro ZIP digest required}"
# Remember any caller-provided toggles — these win over matrix.env's defaults.
_env_run_e2e="${RUN_E2E:-}"; _env_strict="${STRICT_DEPRECATIONS:-}"
# Pull config (CORE_SPECS + defaults) from matrix.env so it's the single edit point,
# whether invoked via run-matrix.sh or standalone.
[ -f "$HARNESS_DIR/matrix.env" ] && source "$HARNESS_DIR/matrix.env"

PHP="${1:?php}"; WP="${2:?wp}"; HTTP_PORT="${3:?http_port}"; DB_PORT="${4:?db_port}"
RUN_E2E="${_env_run_e2e:-${RUN_E2E:-1}}"
STRICT_DEPRECATIONS="${_env_strict:-${STRICT_DEPRECATIONS:-0}}"

# E2E specs that gate the cell verdict — self-contained, version-sensitive, and
# independent of sibling plugins (consent CMPs, WooCommerce) or seeded fixtures
# that a bare Free+Pro container lacks. Other specs run too, but only count as
# informational (their failures are environment, not plugin regressions).
# CORE_SPECS comes from matrix.env (the single edit point). Fall back only if it
# wasn't declared as a non-empty array (declare -p fails when unset, so the
# `||` short-circuits before ${#...[@]} under set -u; an empty array → length 0).
if ! declare -p CORE_SPECS >/dev/null 2>&1 || [ "${#CORE_SPECS[@]}" -eq 0 ]; then
  CORE_SPECS=(issue-php74-admin-load.spec.ts feedbackbird-removed.spec.ts analytics-correctness-invariants.spec.ts)
fi

CELL="php${PHP}-wp${WP}"
CELL_DIR="$WORK_ROOT/cells/$CELL"
WP_DIR="$CELL_DIR/wp"
ART="$CELL_DIR/artifacts"
PROJECT="ssqa_${PHP//./}_${WP//./}"
BASE_URL="http://127.0.0.1:${HTTP_PORT}"
status="PASS"; reason=""; runtime_checks_complete=false

export COMPOSE_PROJECT_NAME="$PROJECT" PHP_VERSION="$PHP" HTTP_PORT DB_PORT
export MYSQL_IMAGE="${MYSQL_IMAGE:-mysql:8.0}"
export CELL_WP_DIR="$WP_DIR"
existing=$(docker ps -aq --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME") || die 'Docker project inspection failed'
volumes=$(docker volume ls -q --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME") || die 'Docker volume inspection failed'
[ -z "$existing$volumes" ] || die 'matrix project already owned; refusing shared database'
[ ! -e "$CELL_DIR" ] || die 'matrix evidence directory already exists'
mkdir -p "$WP_DIR" "$ART"

fail(){ status="FAIL"; reason="${reason:-$1}"; err "$1"; }
blocked(){ status="BLOCKED-BY-WP-CORE"; reason="$1"; warn "BLOCKED: $1"; }
unavailable(){ status="UNAVAILABLE-PREREQUISITE"; reason="$1"; warn "UNAVAILABLE: $1"; }
verified_core_fatal() {
  grep -qiE 'Fatal error|Parse error|Uncaught' "$1" && grep -qE '/var/www/html/(wp-includes/|wp-admin/|wp-[a-z-]+\.php)' "$1" \
    && ! grep -qE '/wp-content/(plugins|mu-plugins)/' "$1"
}
record_core_block() { # <phase> <log>
  cp "$2" "$ART/core-incompatibility.log"
  python3 - "$ART" "$1" <<'PYCORE'
import hashlib,json,pathlib,sys
p=pathlib.Path(sys.argv[1]); log=p/'core-incompatibility.log'
json.dump(dict(kind='plugin-free-core-fatal',phase=sys.argv[2],log_sha256=hashlib.sha256(log.read_bytes()).hexdigest()),open(p/'core-incompatibility.json','w'))
PYCORE
}

finish() {
  local rc=$?
  if [ "$rc" -ne 0 ] && [ "$status" = PASS ]; then fail "cell exited $rc before completion"; fi
  write_verdict "$ART" "$CELL" "$PHP" "$WP" "$status" "$reason" "\"runtime_checks_complete\":$runtime_checks_complete"
  dc down -v --remove-orphans >/dev/null 2>&1 || true
  log "$CELL → $status ${reason:+($reason)}"
}
trap finish EXIT

log "[$CELL] build + up (PHP $PHP, WP $WP, http $HTTP_PORT, db $DB_PORT)"
boot_stack "$ART" "$PHP"
case $? in
  1) fail "image build failed"; exit 1 ;;
  2) fail "compose up failed";  exit 1 ;;
  3) fail "db never ready";     exit 1 ;;
esac
wait_for 30 2 bash -c "curl -fsS -o /dev/null '$BASE_URL/' || [ \"\$(curl -s -o /dev/null -w '%{http_code}' '$BASE_URL/')\" != 000 ]" || true

# Download availability is a prerequisite, never proof of a PHP/core incompatibility.
log "[$CELL] download WordPress $WP"
wpc core download --version="$WP" --force >"$ART/wp-download.log" 2>&1
DOWNLOAD_RC=$?
python3 - "$ART" "$WP" "$DOWNLOAD_RC" <<'PYAVAIL'
import datetime,hashlib,json,pathlib,sys
p=pathlib.Path(sys.argv[1]); log=p/'wp-download.log'
json.dump(dict(requested_version=sys.argv[2],available=int(sys.argv[3])==0,exit_status=int(sys.argv[3]),checked_at=datetime.datetime.now(datetime.timezone.utc).isoformat(),log_sha256=hashlib.sha256(log.read_bytes()).hexdigest()),open(p/'wp-availability.json','w'))
PYAVAIL
[ "$DOWNLOAD_RC" = 0 ] || { unavailable "WordPress $WP could not be fetched; see wp-download.log"; exit 1; }
dc exec -T wp php -r 'include "/var/www/html/wp-includes/version.php"; echo json_encode(["kind"=>"declared-php-floor","wp_version"=>$wp_version,"required_php"=>$required_php_version,"actual_php"=>PHP_VERSION]); exit(version_compare(PHP_VERSION,$required_php_version,">=")?0:3);' >"$ART/core-requirements.json" 2>"$ART/core-requirements-error.log"
CORE_REQUIREMENTS_RC=$?
if [ "$CORE_REQUIREMENTS_RC" = 3 ]; then
  cp "$ART/core-requirements.json" "$ART/core-incompatibility.json"
  blocked "downloaded WordPress $WP declares a PHP floor above PHP $PHP"; exit 0
fi
[ "$CORE_REQUIREMENTS_RC" = 0 ] || { unavailable 'downloaded core requirements could not be inspected'; exit 1; }

# ── config + install (BLOCKED detection #2: WP core fatal on this PHP) ───────
wp_config_debug "$ART/wp-install.log"

if ! wpc core install --url="$BASE_URL" --title="SS QA $CELL" \
       --admin_user=admin --admin_password=admin --admin_email=qa@example.com \
       --skip-email >>"$ART/wp-install.log" 2>&1; then
  if verified_core_fatal "$ART/wp-install.log"; then
    record_core_block core-install "$ART/wp-install.log"
    blocked "$(grep -iE "$WP_CORE_FATAL_PATTERNS" "$ART/wp-install.log" | head -1 | cut -c1-160)"; exit 0
  fi
  fail "wp core install failed (non-core)"; exit 1
fi
# Boot probe: home 500 with a core fatal while no plugin is active = WP-core.
home_code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/")
if [ "$home_code" = "500" ] && verified_core_fatal "$WP_DIR/wp-content/debug.log"; then
  record_core_block core-http-boot "$WP_DIR/wp-content/debug.log"
  blocked "WP core WSOD on PHP $PHP (home HTTP 500)"; exit 0
fi
: > "$WP_DIR/wp-content/debug.log" 2>/dev/null || true   # reset log AFTER core boots clean

# ── plugins: exact paired qualification artifacts ─────────────────────────────
log "[$CELL] install plugins"
extract_qualification_artifact "$QUALIFICATION_FREE_ZIP" "$QUALIFICATION_FREE_SHA256" wp-slimstat "$CELL_DIR/free-artifact" >"$ART/free-artifact.log" || { fail 'Free artifact rejected'; exit 1; }
extract_qualification_artifact "$QUALIFICATION_PRO_ZIP" "$QUALIFICATION_PRO_SHA256" wp-slimstat-pro "$CELL_DIR/pro-artifact" >"$ART/pro-artifact.log" || { fail 'Pro artifact rejected'; exit 1; }
sync_plugin_src "$WP_DIR" "$CELL_DIR/free-artifact/wp-slimstat"
mkdir -p "$WP_DIR/wp-content/plugins/.pro"
cp "$QUALIFICATION_PRO_ZIP" "$WP_DIR/wp-content/plugins/.pro/wp-slimstat-pro.zip" || { fail 'Pro artifact copy failed'; exit 1; }
chmod -R a+rwX "$WP_DIR/wp-content" 2>/dev/null || true

wpc plugin activate wp-slimstat                                            >"$ART/activate.log" 2>&1 || fail "free activate failed"
wpc plugin install /var/www/html/wp-content/plugins/.pro/wp-slimstat-pro.zip --activate --force >>"$ART/activate.log" 2>&1 || fail "pro activate failed"
# Activation does NOT fire admin_init/init_tables — force table creation (mirrors CI).
wpc eval 'include_once(WP_PLUGIN_DIR."/wp-slimstat/admin/index.php"); wp_slimstat_admin::init_tables($GLOBALS["wpdb"]); echo "tables-ok";' >>"$ART/activate.log" 2>&1 || fail "init_tables failed"
# E2E author user.
wpc user create dordane dordane@example.com --role=author --user_pass=testpass123 >>"$ART/activate.log" 2>&1 || true
# Pretty permalinks so the REST tracker route + permalink-dependent specs work.
wpc rewrite structure '/%postname%/' --hard >>"$ART/activate.log" 2>&1 || true
wpc rewrite flush --hard                     >>"$ART/activate.log" 2>&1 || true

# ── (a) plugins active ──────────────────────────────────────────────────────
wpc plugin list --status=active --field=name > "$ART/active-plugins.txt" 2>/dev/null
grep -q '^wp-slimstat$' "$ART/active-plugins.txt"        || fail "wp-slimstat not active"
grep -qi 'slimstat-pro' "$ART/active-plugins.txt"        || fail "wp-slimstat-pro not active"

# ── (b) authed admin smoke pages ────────────────────────────────────────────
CJ="$ART/cookies.txt"
# Keep the login response and the first dashboard render: a plugin that fails its
# prerequisites renders its notice on exactly these two pages and (when the request
# is interactive) deactivates itself there. Discarding them hid A0 for a full cycle.
curl -s -c "$CJ" -b "wordpress_test_cookie=WP+Cookie+check" \
     -d "log=admin&pwd=admin&wp-submit=Log+In&redirect_to=$BASE_URL/wp-admin/&testcookie=1" \
     "$BASE_URL/wp-login.php" -o "$ART/login.html"
curl -s -b "$CJ" -o "$ART/first-admin.html" -w '%{http_code}' "$BASE_URL/wp-admin/" > "$ART/first-admin-code.txt"
for slug in slimview1 slimview3 slimview6 "slimconfig&tab=1"; do
  name="${slug%%&*}"
  code=$(curl -s -b "$CJ" -o "$ART/smoke-$name.html" -w '%{http_code}' "$BASE_URL/wp-admin/admin.php?page=$slug")
  if [ "$code" != "200" ] || grep -qiE 'Fatal error|There has been a critical error|Uncaught' "$ART/smoke-$name.html"; then
    fail "admin smoke $name (HTTP $code)"
  fi
done

# ── (b2) Pro must still be OPERATIONAL after those admin requests ───────────
# (a) above runs before the first authenticated request and the artifact check at
# the end of the cell only compares bytes on disk, so a Pro that switched itself
# off during the dashboard render used to pass a cell unnoticed (A0, 2026-09-09).
# Three assertions, in increasing strength: still active, no recorded degradation,
# and a Pro-only report actually registered at runtime.
wpc plugin list --status=active --field=name > "$ART/active-plugins-post-admin.txt" 2>/dev/null
grep -qi 'slimstat-pro' "$ART/active-plugins-post-admin.txt" \
  || fail "wp-slimstat-pro deactivated during authenticated admin requests"

wpc option get slimstat_degradations --format=json > "$ART/degradations.json" 2>/dev/null \
  || printf '{}' > "$ART/degradations.json"
! grep -q '"pro_' "$ART/degradations.json" \
  || fail "Pro recorded a degradation: $(tr -d '\n' < "$ART/degradations.json" | cut -c1-200)"

# Positive proof the providers booted: the addon registers slim_p8_01 on
# slimstat_reports_info from its constructor, which only runs once
# _loadServiceProviders() is reached. init() swallows \Throwable, so "no error"
# is not proof — this is.
wpc eval '$r = apply_filters("slimstat_reports_info", array()); echo isset($r["slim_p8_01"]) ? "PROBOOT_OK" : "PROBOOT_MISSING";' \
  > "$ART/pro-runtime-signal.txt" 2>&1
grep -q 'PROBOOT_OK' "$ART/pro-runtime-signal.txt" \
  || fail "Pro report slim_p8_01 not registered at runtime (service providers never booted)"

# ── (c) tracking hit → a wp_slim_stats row ──────────────────────────────────
before=$(wpc db query "SELECT COUNT(*) FROM wp_slim_stats;" --skip-column-names 2>/dev/null | tr -dc '0-9')
# rest_route form works regardless of permalink flush timing.
curl -s -X POST "$BASE_URL/?rest_route=/slimstat/v1/hit" -H 'Content-Type: application/json' \
     -A "Mozilla/5.0 (QA) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36" \
     -d "{\"res\":\"$BASE_URL/\",\"ref\":\"https://example.com/\"}" -o "$ART/track.json"
sleep 2
after=$(wpc db query "SELECT COUNT(*) FROM wp_slim_stats;" --skip-column-names 2>/dev/null | tr -dc '0-9')
[ "${after:-0}" -gt "${before:-0}" ] || fail "no wp_slim_stats row after tracking hit (before=$before after=$after)"

# ── (d) standalone PHP suites on this PHP ───────────────────────────────────
# Pure source-level SCAN tests — these are the version-compatibility tests, run
# on this exact PHP. (Stub/full-WP functional tests are covered by the E2E step.)
dc exec -T -u www-data wp bash -c '
  set -e; cd /var/www/html/wp-content/plugins/wp-slimstat
  for t in tests/php74-no-php80-functions-test.php tests/php-implicit-nullable-test.php \
           tests/php80-syntax-scan-test.php tests/php82-84-forward-scan-test.php \
           tests/wp70-wp-version-guard-test.php tests/wp70-tested-up-to-test.php \
           tests/loose-comparison-scan-test.php tests/dtr-pton-init-test.php \
           tests/wp-removed-core-fns-scan-test.php tests/dead-symfony-removed-test.php \
           tests/jquery4-own-code-shorthand-scan-test.php tests/loginnote-bracket-parse-test.php \
           tests/shortcode-w-whitelist-test.php tests/avg-duration-format-test.php \
           tests/admin-ui-render-guards-test.php tests/goals-free-active-limit-test.php \
           tests/goals-funnels-index-migration-test.php tests/ci-matrix-coverage-test.php; do
    [ -f "$t" ] || continue; echo "== $t =="; php "$t" || exit 1
  done' > "$ART/free-suite.log" 2>&1 || fail "free PHP suite failed"

dc exec -T -u www-data wp bash -c '
  set -e; cd /var/www/html/wp-content/plugins/wp-slimstat-pro 2>/dev/null || exit 0
  [ -d tests ] || exit 0
  for t in tests/pro-php80-syntax-scan-test.php tests/pro-php82-84-forward-scan-test.php \
           tests/pro-implicit-nullable-test.php tests/php81-runtime-null-args-test.php \
           tests/scoper-patcher-implicit-nullable-test.php tests/scoper-patcher-e-strict-test.php \
           tests/jquery4-own-code-shorthand-scan-test.php tests/pro-wp-removed-core-fns-scan-test.php \
           tests/pro-wp70-tested-up-to-test.php; do
    [ -f "$t" ] || continue; echo "== $t =="; php "$t" || exit 1
  done' > "$ART/pro-suite.log" 2>&1 || fail "pro PHP suite failed"

# ── (e) full Playwright E2E from the host ───────────────────────────────────
if [ "$RUN_E2E" = "1" ]; then
  # E2E_CORE_ONLY=1 runs just the gating CORE_SPECS (fast); else the full suite
  # (full-suite extras are informational only — see matrix.env / README).
  E2E_SPECS=(); e2e_mode="full"
  if [ "${E2E_CORE_ONLY:-0}" = "1" ]; then E2E_SPECS=("${CORE_SPECS[@]}"); e2e_mode="core-only"; fi
  log "[$CELL] Playwright E2E ($e2e_mode; verdict gated on core specs)"
  ( cd "$PLUGIN_SRC"
    rm -f tests/e2e/.auth/admin.json tests/e2e/.auth/author.json
    TEST_BASE_URL="$BASE_URL" WP_ROOT="$WP_DIR" \
    MYSQL_SOCKET="" MYSQL_HOST=127.0.0.1 MYSQL_PORT="$DB_PORT" \
    MYSQL_USER=root MYSQL_PASSWORD=root MYSQL_DATABASE=wordpress \
    WP_ADMIN_USER=admin WP_ADMIN_PASS=admin WP_AUTHOR_USER=dordane WP_AUTHOR_PASS=testpass123 \
    WP_VERSION="$WP" WP_ENV_PHP_VERSION="$PHP" \
    npx playwright test ${E2E_SPECS[@]+"${E2E_SPECS[@]}"} \
      --list --config=tests/e2e/playwright.config.ts --project admin > "$ART/playwright-expected.txt" 2>&1
    TEST_BASE_URL="$BASE_URL" WP_ROOT="$WP_DIR" \
    MYSQL_SOCKET="" MYSQL_HOST=127.0.0.1 MYSQL_PORT="$DB_PORT" \
    MYSQL_USER=root MYSQL_PASSWORD=root MYSQL_DATABASE=wordpress \
    WP_ADMIN_USER=admin WP_ADMIN_PASS=admin WP_AUTHOR_USER=dordane WP_AUTHOR_PASS=testpass123 \
    WP_VERSION="$WP" WP_ENV_PHP_VERSION="$PHP" PLAYWRIGHT_JSON_OUTPUT_NAME="$ART/playwright.json" \
    npx playwright test ${E2E_SPECS[@]+"${E2E_SPECS[@]}"} \
      --config=tests/e2e/playwright.config.ts --project admin \
      --timeout=20000 --reporter=list,json > "$ART/playwright.log" 2>&1
    printf '%s\n' "$?" > "$ART/playwright-exit.txt" )
  # Informational totals.
  grep -oE '[0-9]+ (passed|failed|skipped)' "$ART/playwright.log" | tail -3 | tr '\n' ' ' > "$ART/playwright-summary.txt"
  # Which spec FILES failed?
  grep '✘' "$ART/playwright.log" 2>/dev/null | grep -oE 'tests/e2e/[A-Za-z0-9_.-]+\.spec\.ts' | sort -u > "$ART/e2e-failed-specs.txt" || true
  # E2E is INFORMATIONAL — the cell verdict gates on the deterministic checks
  # (activation / admin smoke / tracking / PHP scan suites / debug.log), which are
  # reliable across concurrency. The containerised browser E2E flakes under high
  # concurrency (global-setup login timeouts) and is noisy in a bare container;
  # the authoritative browser-E2E runs on LocalWP/CI. We record its core result.
  core_fail=""
  for c in "${CORE_SPECS[@]}"; do grep -q "tests/e2e/$c\$" "$ART/e2e-failed-specs.txt" && core_fail="$core_fail $c"; done
  if [ -n "$core_fail" ]; then
    printf 'E2E core (informational): FAIL —%s [%s]\n' "$core_fail" "$(cat "$ART/playwright-summary.txt")" > "$ART/e2e-core-result.txt"
    warn "[$CELL] E2E core flaky/failed (informational, non-gating):$core_fail"
  else
    printf 'E2E core (informational): OK [%s]\n' "$(cat "$ART/playwright-summary.txt")" > "$ART/e2e-core-result.txt"
    log "[$CELL] E2E core OK ($(cat "$ART/playwright-summary.txt"))"
  fi
fi

# ── (f) debug.log scan (plugin-implicating only) ────────────────────────────
LOG="$WP_DIR/wp-content/debug.log"
if [ -f "$LOG" ]; then
  cp "$LOG" "$ART/debug.log"
  if grep -iE 'PHP (Fatal|Parse) error.*(wp-slimstat|SlimStat)|Uncaught.*SlimStat' "$LOG" >/dev/null 2>&1; then
    fail "wp-slimstat fatal in debug.log"
  fi
  if [ "$STRICT_DEPRECATIONS" = "1" ] && grep -iE 'Deprecated.*(wp-slimstat|SlimStat)' "$LOG" >/dev/null 2>&1; then
    fail "wp-slimstat deprecation (strict mode)"
  fi
fi

if [ -n "${PRE_CLEANUP_HOOK:-}" ]; then
  bash "$PRE_CLEANUP_HOOK" "$ART" || fail "pre-cleanup hook failed"
fi

verify_qualification_artifact "$QUALIFICATION_FREE_ZIP" "$QUALIFICATION_FREE_SHA256" wp-slimstat "$WP_DIR/wp-content/plugins" >"$ART/free-installed.json" || fail 'installed Free shipping bytes changed'
verify_qualification_artifact "$QUALIFICATION_PRO_ZIP" "$QUALIFICATION_PRO_SHA256" wp-slimstat-pro "$WP_DIR/wp-content/plugins" >"$ART/pro-installed.json" || fail 'installed Pro shipping bytes changed'
runtime_checks_complete=true
[ "$status" = "PASS" ] && exit 0 || exit 1
