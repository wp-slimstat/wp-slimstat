#!/usr/bin/env bash
# E2E batch runner for WP SlimStat — runs Playwright batches + k6 load tests
# Resolve paths: SCRIPT_DIR → e2e/, PLUGIN_ROOT → wp-slimstat/, PLUGINS_DIR → plugins/
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
PLUGINS_DIR="$(cd "$PLUGIN_ROOT/.." && pwd)"
cd "$PLUGIN_ROOT" || { echo "ERROR: Cannot cd to $PLUGIN_ROOT"; exit 1; }

# Env vars — inherited from caller exports; fallbacks match helpers/env.ts defaults
: "${WP_ROOT:=/Users/parhumm/Local Sites/test/app/public}"
: "${TEST_BASE_URL:=http://localhost:10003}"
: "${RUN_ID:=$(date +%Y%m%d-%H%M%S)}"
: "${ARTIFACTS:=$SCRIPT_DIR/run-artifacts/$RUN_ID}"
BLOB_MERGE_DIR="$ARTIFACTS/blob-merge"
mkdir -p "$ARTIFACTS" "$BLOB_MERGE_DIR"

BATCH_EXIT=0

run_batch() {
  local name="$1"; shift
  local batch_blob_dir="$ARTIFACTS/blob-tmp-$name"
  echo "=== Batch $name ==="
  PLAYWRIGHT_JSON_OUTPUT_FILE="$ARTIFACTS/batch-$name.json" \
  PLAYWRIGHT_BLOB_OUTPUT_DIR="$batch_blob_dir" \
  PLAYWRIGHT_BLOB_OUTPUT_NAME="batch-$name.zip" \
  PLAYWRIGHT_HTML_OUTPUT_DIR="$ARTIFACTS/html/batch-$name" \
  npx playwright test "$@" \
    --config=tests/e2e/playwright.config.ts \
    --project admin \
    2>&1 | tee "$ARTIFACTS/batch-$name.log"
  local rc="${PIPESTATUS[0]}"
  echo "=== Batch $name exit: $rc ==="
  [ "$rc" -ne 0 ] && BATCH_EXIT=1
  # Move the single known blob zip to flat merge dir (Playwright nukes blob dir per batch)
  mv "$batch_blob_dir/batch-$name.zip" "$BLOB_MERGE_DIR/" 2>/dev/null
  rm -rf "$batch_blob_dir"
  wp cache flush --path="$WP_ROOT" 2>/dev/null || true
}

# Batches are sequential — specs share DB state (wp_slim_stats, slimstat_options)
run_batch A data-collection-rest data-collection-ajax data-collection-adblock \
  sendbeacon-interaction visit-id-performance visitor-count-visit-id rest-endpoint-safety
run_batch B geolocation-provider geolocation-dbip-precision geolocation-maxmind-precision \
  cloudflare-ip-regression geolocation-ip-detection geolocation-provider-sanitization \
  geolocation-download-retry geoip-ajax-loop issue-180-dbip-cron-tempnam
run_batch C pr178-consent-fixes pr178-consent-reject-integration \
  consent-no-dependency consent-banner-e2e
run_batch D overview-charts-accuracy css-reduced-motion css-wrap-slimstat \
  slimemail-page-structure i18n-catalog-sync textdomain-timing textdomain-edge-cases
run_batch E upgrade-safety upgrade-data-integrity
run_batch F pro-maxmind-details-addon pro-version-floor-check \
  pro-coordinates-display pro-dbip-whois-data
run_batch G pr184-server-side-tracking-api wporg-support-issues
run_batch H server-side-tracking-js-disabled adblock-bypass-fallback plugin-health-checks

# ─── k6 (Suite 05) — sequential: load/stress invalidate metrics if overlapped
K6_EXIT=0
if command -v k6 &>/dev/null; then
  for scenario in smoke load stress; do
    echo "=== k6 $scenario ==="
    k6 run --env BASE_URL="$TEST_BASE_URL" --env SCENARIO="$scenario" \
      "$PLUGINS_DIR/jaan-to/outputs/qa/cases/05-intval-rest-fix-k6/05-k6-intval-rest-fix.js" \
      2>&1 | tee "$ARTIFACTS/k6-$scenario.log"
    [ "${PIPESTATUS[0]}" -ne 0 ] && K6_EXIT=1
  done
else
  echo "WARNING: k6 not found, skipping load tests"
fi

# ─── Merge Playwright blob reports ────────────────────────────────
echo "=== Merging ==="
PLAYWRIGHT_JSON_OUTPUT_FILE="$ARTIFACTS/merged-results.json" \
npx playwright merge-reports "$BLOB_MERGE_DIR" --reporter=json \
  2>&1 | tee "$ARTIFACTS/merge.log"
MERGE_EXIT="${PIPESTATUS[0]}"

echo "=== Run $RUN_ID | batch=$BATCH_EXIT k6=$K6_EXIT merge=$MERGE_EXIT ==="
echo "Artifacts: $ARTIFACTS"
if [ "$BATCH_EXIT" -eq 0 ] && [ "$K6_EXIT" -eq 0 ] && [ "$MERGE_EXIT" -eq 0 ]; then
  exit 0
else
  exit 1
fi
