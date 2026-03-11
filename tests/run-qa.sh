#!/bin/bash
#
# Orchestrates the full QA simulation for PR #166 (GeoIP infinite AJAX loop fix).
# Manages DISABLE_WP_CRON, mu-plugin logger, DB state, and runs both Playwright and k6.
#
# Usage: bash tests/run-qa.sh [--e2e-only | --perf-only]
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
WP_ROOT="/Users/parhumm/Local Sites/test/app/public"
WP_CONFIG="$WP_ROOT/wp-config.php"
MU_PLUGINS="$WP_ROOT/wp-content/mu-plugins"
AJAX_LOG="$WP_ROOT/wp-content/geoip-ajax-calls.log"
MYSQL_SOCKET="/Users/parhumm/Library/Application Support/Local/run/X-JdmZXIa/mysql/mysqld.sock"

RUN_E2E=true
RUN_PERF=true
if [[ "${1:-}" == "--e2e-only" ]]; then RUN_PERF=false; fi
if [[ "${1:-}" == "--perf-only" ]]; then RUN_E2E=false; fi

# Colors
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[0;33m'; NC='\033[0m'

echo -e "${YELLOW}=== GeoIP AJAX Loop QA Simulation ===${NC}"
echo "Plugin: $PLUGIN_DIR"
echo "WP Root: $WP_ROOT"
echo ""

# ─── Phase 0: Verify site is running ─────────────────────────────────

echo -n "Checking site availability... "
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:10003/wp-login.php 2>/dev/null || echo "000")
if [[ "$HTTP_CODE" != "200" ]]; then
  echo -e "${RED}FAIL${NC} (HTTP $HTTP_CODE)"
  echo "Start Local by Flywheel first."
  exit 1
fi
echo -e "${GREEN}OK${NC} (HTTP $HTTP_CODE)"

# ─── Phase 1: Backup wp-config.php ───────────────────────────────────

echo -n "Backing up wp-config.php... "
cp "$WP_CONFIG" "$WP_CONFIG.qa-backup"
echo -e "${GREEN}OK${NC}"

# Cleanup trap: always restore on exit
cleanup() {
  echo ""
  echo -e "${YELLOW}=== Cleanup ===${NC}"
  if [[ -f "$WP_CONFIG.qa-backup" ]]; then
    mv "$WP_CONFIG.qa-backup" "$WP_CONFIG"
    echo "Restored wp-config.php"
  fi
  rm -f "$MU_PLUGINS/geoip-ajax-logger.php"
  echo "Removed mu-plugin logger"
  rm -f "$AJAX_LOG"
  echo "Cleared AJAX log"
}
trap cleanup EXIT

# ─── Phase 2: Run Playwright E2E tests ───────────────────────────────
# Playwright tests manage their own DISABLE_WP_CRON, mu-plugin, and DB state
# via beforeEach/afterEach, so we run them on a clean environment first.

E2E_EXIT=0
if $RUN_E2E; then
  echo ""
  echo -e "${YELLOW}=== Running Playwright E2E Tests ===${NC}"
  cd "$PLUGIN_DIR"
  npx playwright test --config=tests/e2e/playwright.config.ts || E2E_EXIT=$?

  if [[ $E2E_EXIT -eq 0 ]]; then
    echo -e "${GREEN}Playwright admin tests PASSED${NC}"
  else
    echo -e "${RED}Playwright admin tests FAILED (exit $E2E_EXIT)${NC}"
  fi

  # Print AJAX log state after E2E
  echo ""
  echo "AJAX calls after E2E:"
  if [[ -f "$AJAX_LOG" ]]; then
    AJAX_COUNT=$(wc -l < "$AJAX_LOG" | tr -d ' ')
    echo "  Logged invocations: $AJAX_COUNT"
    if [[ "$AJAX_COUNT" -gt 10 ]]; then
      echo -e "  ${RED}WARNING: Excessive AJAX calls!${NC}"
    fi
  else
    echo "  No AJAX log (0 calls)"
  fi
fi

# ─── Phase 3: Prepare environment for k6 ─────────────────────────────

echo -n "Adding DISABLE_WP_CRON to wp-config.php... "
if ! grep -q "DISABLE_WP_CRON" "$WP_CONFIG"; then
  sed -i '' "/That's all, stop editing/i\\
define('DISABLE_WP_CRON', true);
" "$WP_CONFIG"
fi
echo -e "${GREEN}OK${NC}"

echo -n "Installing mu-plugin AJAX logger... "
mkdir -p "$MU_PLUGINS"
cp "$SCRIPT_DIR/e2e/helpers/ajax-logger-mu-plugin.php" "$MU_PLUGINS/geoip-ajax-logger.php"
echo -e "${GREEN}OK${NC}"

echo -n "Clearing state for k6... "
rm -f "$AJAX_LOG"
mysql --socket="$MYSQL_SOCKET" -u root -proot local -e \
  "DELETE FROM wp_options WHERE option_name = 'slimstat_last_geoip_dl';" 2>/dev/null
echo -e "${GREEN}OK${NC}"

# ─── Phase 4: Run k6 load test ───────────────────────────────────────

K6_EXIT=0
if $RUN_PERF; then
  echo ""
  echo -e "${YELLOW}=== Running k6 Load Test ===${NC}"
  cd "$PLUGIN_DIR"
  k6 run tests/perf/geoip-load.js || K6_EXIT=$?

  if [[ $K6_EXIT -eq 0 ]]; then
    echo -e "${GREEN}k6 load test PASSED${NC}"
  else
    echo -e "${RED}k6 load test FAILED (exit $K6_EXIT)${NC}"
  fi
fi

# ─── Phase 7: Post-test DB verification ──────────────────────────────

echo ""
echo -e "${YELLOW}=== Post-Test Verification ===${NC}"

echo "slimstat_last_geoip_dl:"
mysql --socket="$MYSQL_SOCKET" -u root -proot local -e \
  "SELECT option_value FROM wp_options WHERE option_name = 'slimstat_last_geoip_dl';" 2>/dev/null || echo "  (not set)"

echo ""
echo "AJAX handler invocations:"
if [[ -f "$AJAX_LOG" ]]; then
  FINAL_COUNT=$(wc -l < "$AJAX_LOG" | tr -d ' ')
  echo "  Total: $FINAL_COUNT"
  if [[ "$FINAL_COUNT" -gt 10 ]]; then
    echo -e "  ${RED}FAIL: Excessive AJAX calls detected — possible infinite loop regression${NC}"
  else
    echo -e "  ${GREEN}PASS: AJAX count is bounded${NC}"
  fi
else
  echo -e "  Total: 0 — ${GREEN}PASS${NC}"
fi

# ─── Phase 8: Summary ────────────────────────────────────────────────

echo ""
echo -e "${YELLOW}=== Summary ===${NC}"
TOTAL_EXIT=$((E2E_EXIT + K6_EXIT))
if [[ $TOTAL_EXIT -eq 0 ]]; then
  echo -e "${GREEN}All QA tests PASSED${NC}"
else
  echo -e "${RED}Some tests FAILED (E2E=$E2E_EXIT, k6=$K6_EXIT)${NC}"
fi

exit $TOTAL_EXIT
