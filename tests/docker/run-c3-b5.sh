#!/usr/bin/env bash
# Exact-artifact C3/B5 target. Reuses run-cell.sh for disposable provisioning and cleanup.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$HERE/../.." && pwd)"

if [ "${C3_B5_HOOK:-0}" = 1 ]; then
  [ "$#" -eq 1 ] || exit 2
  ART="$1"
  NODE_MODULES="${C3_B5_NODE_MODULES:-$PLUGIN_ROOT/node_modules}"
  PLAYWRIGHT="$NODE_MODULES/.bin/playwright"
  [ -x "$PLAYWRIGHT" ] || { echo "Missing Playwright executable: $PLAYWRIGHT" >&2; exit 1; }
  RUNNER="$CELL_WP_DIR/wp-content/plugins/wp-slimstat"
  rsync -a --exclude=.auth --exclude=run-artifacts --exclude=playwright-report \
    --exclude=test-results "$PLUGIN_ROOT/tests/" "$RUNNER/tests/"
  cp "$PLUGIN_ROOT/package.json" "$RUNNER/package.json"
  ln -s "$NODE_MODULES" "$RUNNER/node_modules"
  rm -f "$RUNNER/tests/e2e/.auth/admin.json" "$RUNNER/tests/e2e/.auth/author.json"
  unset CI ALLOW_LIVE_DB
  git -C "$PLUGIN_ROOT" rev-parse HEAD >"$ART/harness-sha.txt"
  cd "$RUNNER"
  PW_RETRIES=0 PW_MAX_FAILURES=0 \
  TEST_BASE_URL="http://127.0.0.1:$HTTP_PORT" WP_ROOT="$CELL_WP_DIR" \
  MYSQL_SOCKET= MYSQL_HOST=127.0.0.1 MYSQL_PORT="$DB_PORT" \
  MYSQL_USER=root MYSQL_PASSWORD=root MYSQL_DATABASE=wordpress \
  WP_ADMIN_USER=admin WP_ADMIN_PASS=admin \
  WP_AUTHOR_USER=dordane WP_AUTHOR_PASS=testpass123 \
  PLAYWRIGHT_JSON_OUTPUT_FILE="$ART/c3-b5-results.json" \
  "$PLAYWRIGHT" test \
    tests/e2e/visit-id-performance.spec.ts tests/e2e/data-collection-adblock.spec.ts \
    --config=tests/e2e/playwright.config.ts --project=admin --workers=1 --retries=0 \
    >"$ART/c3-b5.log" 2>&1
  exit
fi

[ "$#" -eq 9 ] || {
  echo "usage: $0 FREE_ZIP FREE_SHA256 PRO_ZIP PRO_SHA256 WORK_ROOT PHP WP HTTP_PORT DB_PORT" >&2
  exit 2
}
export QUALIFICATION_FREE_ZIP="$1" QUALIFICATION_FREE_SHA256="$2"
export QUALIFICATION_PRO_ZIP="$3" QUALIFICATION_PRO_SHA256="$4"
export WORK_ROOT="$5" PHP_VERSION="$6" HTTP_PORT="$8" DB_PORT="$9"
export RUN_E2E=0 PRE_CLEANUP_HOOK="$0" C3_B5_HOOK=1 PW_RETRIES=0 PW_MAX_FAILURES=0

[ ! -e "$WORK_ROOT" ] || { echo "Refusing reused work root: $WORK_ROOT" >&2; exit 1; }
[ -x "${C3_B5_NODE_MODULES:-$PLUGIN_ROOT/node_modules}/.bin/playwright" ] || {
  echo "Set C3_B5_NODE_MODULES to a compatible installed dependency tree" >&2
  exit 1
}
git -C "$PLUGIN_ROOT" diff --quiet && git -C "$PLUGIN_ROOT" diff --cached --quiet || {
  echo "Refusing dirty tracked harness checkout" >&2
  exit 1
}
mkdir -p "$WORK_ROOT"
bash "$HERE/run-cell.sh" "$6" "$7" "$8" "$9"
