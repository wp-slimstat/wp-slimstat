/**
 * E2E: Chart SQL expression validation — validateSqlExpression() whitelist
 *
 * Validates the strict whitelist introduced in Chart.php:436–508 (PR #232).
 * The whitelist accepts only:
 *   Pattern 1: FUNC(*)            e.g. COUNT(*)
 *   Pattern 2: FUNC(column)       e.g. COUNT(id), SUM(page_performance)
 *   Pattern 3: FUNC(DISTINCT col) e.g. COUNT(DISTINCT ip)
 *
 * On rejection, ajaxFetchChartData() catches the \Exception and calls
 * wp_send_json_error() — HTTP 200 with { success: false, data: { message } }.
 *
 * Tests:
 *   1. COUNT(id) — default free-plugin expression → success: true
 *   2. COUNT( DISTINCT ip ) — default free-plugin expression → success: true
 *   3. COUNT(*) + 1 — arithmetic, no whitelist pattern matches → success: false
 *   4. '; DROP TABLE wp_slim_stats-- — SQL injection attempt → success: false
 *
 * Source: PR #232 impact analysis / finding 2
 */
import { test, expect } from '@playwright/test';
import {
  closeDb,
  installMuPluginByName,
  uninstallMuPluginByName,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';
import { CHART_TEST_RANGE, getChartNonce } from './helpers/chart';

/**
 * Call slimstat_fetch_chart_data AJAX with a custom data1 expression.
 * Uses a fixed past range — no DB rows needed because
 * validateSqlExpression() runs before the SQL query.
 */
async function callChartWithExpression(
  page: import('@playwright/test').Page,
  nonce: string,
  data1Expression: string
): Promise<{ status: number; body: any }> {
  const args = JSON.stringify({
    ...CHART_TEST_RANGE,
    chart_data: {
      data1: data1Expression,
      data2: 'COUNT( DISTINCT ip )',
    },
  });

  const res = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
    form: { action: 'slimstat_fetch_chart_data', nonce, args, granularity: 'monthly' },
  });

  return { status: res.status(), body: res.ok() ? await res.json() : null };
}

// ─── Suite ────────────────────────────────────────────────────────────────────

test.describe('Chart SQL expression validation — validateSqlExpression() whitelist', () => {
  test.setTimeout(60_000);

  let sharedNonce: string;

  test.beforeAll(async ({ browser }) => {
    installMuPluginByName('nonce-helper-mu-plugin.php');
    // Obtain nonce once for all 4 tests — nonces are valid for several hours
    const ctx  = await browser.newContext();
    const page = await ctx.newPage();
    sharedNonce = await getChartNonce(page);
    await ctx.close();
  });

  test.afterAll(async () => {
    uninstallMuPluginByName('nonce-helper-mu-plugin.php');
    await closeDb();
  });

  // ─── Test 1: Default expression COUNT(id) passes whitelist ───────────────

  test('COUNT(id) — default free-plugin expression passes whitelist', async ({ page }) => {
    const { status, body } = await callChartWithExpression(page, sharedNonce, 'COUNT(id)');

    expect(status).toBe(200);
    expect(body?.success, `Expected success:true, got: ${JSON.stringify(body?.data)}`).toBe(true);

    console.log('Test 1 PASS — COUNT(id) accepted by whitelist');
  });

  // ─── Test 2: Default expression COUNT( DISTINCT ip ) passes whitelist ────

  test('COUNT( DISTINCT ip ) — default free-plugin expression passes whitelist', async ({ page }) => {
    const { status, body } = await callChartWithExpression(page, sharedNonce, 'COUNT( DISTINCT ip )');

    expect(status).toBe(200);
    expect(body?.success, `Expected success:true, got: ${JSON.stringify(body?.data)}`).toBe(true);

    console.log('Test 2 PASS — COUNT( DISTINCT ip ) accepted by whitelist');
  });

  // ─── Test 3: Arithmetic expression rejected ───────────────────────────────

  test("COUNT(*) + 1 — arithmetic expression rejected by whitelist → success: false", async ({ page }) => {
    /**
     * The whitelist patterns only allow FUNC(*), FUNC(col), FUNC(DISTINCT col).
     * Arithmetic operators are not part of any pattern — the expression is
     * rejected before reaching the SQL layer, returning:
     *   { success: false, data: { message: "Invalid SQL expression..." } }
     */
    const { status, body } = await callChartWithExpression(page, sharedNonce, 'COUNT(*) + 1');

    expect(status).toBe(200); // always HTTP 200; success flag carries the error
    expect(body?.success, 'Arithmetic expression should be rejected').toBe(false);
    expect(typeof body?.data?.message).toBe('string');
    expect(body?.data?.message.length).toBeGreaterThan(0);

    console.log(`Test 3 PASS — COUNT(*)+1 rejected: "${body?.data?.message}"`);
  });

  // ─── Test 4: SQL injection attempt rejected ───────────────────────────────

  test("SQL injection attempt rejected by whitelist → success: false", async ({ page }) => {
    /**
     * Classic SQL injection payload. The whitelist regex anchors (^ and $)
     * with strict character classes prevent any bypass.
     * Expression is rejected; no SQL query is executed.
     */
    const injection = "'; DROP TABLE wp_slim_stats--";
    const { status, body } = await callChartWithExpression(page, sharedNonce, injection);

    expect(status).toBe(200);
    expect(body?.success, 'SQL injection should be rejected').toBe(false);
    expect(typeof body?.data?.message).toBe('string');
    expect(body?.data?.message.length).toBeGreaterThan(0);

    console.log(`Test 4 PASS — injection rejected: "${body?.data?.message}"`);
  });
});
