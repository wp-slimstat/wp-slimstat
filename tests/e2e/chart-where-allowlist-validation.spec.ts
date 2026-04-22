/**
 * E2E: chart_data.where allowlist validation — Patchstack SQLi (CVSS 8.5).
 *
 * Validates the registry-based allowlist in Chart::sqlFor() that compares the
 * client-supplied `chart_data.where` (after whitespace normalization) against
 * every WHERE clause registered in wp_slimstat_reports::$reports. Anything
 * not in the allowlist throws \Exception, caught by ajaxFetchChartData() and
 * returned as { success: false, data: { message } }.
 *
 * Tests:
 *   1. Legit where (slim_p1_19_01 Search Terms clause) → success: true
 *   2. Legit where with extra whitespace             → success: true (normalization)
 *   3. Patchstack PoC: IF(1=1,SLEEP(2),0)            → success: false AND elapsed < 1500ms
 *   4. Stacked-statement injection                    → success: false
 *   5. No `where` provided (slim_p1_01-style report)  → success: true
 *   6. Empty `where` string                           → success: true (early return)
 *
 * Source: Patchstack disclosure 2026-04 / fix shipped 5.4.13.
 */
import { test, expect } from '@playwright/test';
import {
  closeDb,
  installMuPluginByName,
  uninstallMuPluginByName,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';
import { CHART_TEST_RANGE, getChartNonce } from './helpers/chart';

// Whitespace-equivalent of the registered slim_p1_19_01 (Search Terms) where clause.
const LEGIT_WHERE = 'searchterms <> "_" AND searchterms IS NOT NULL AND searchterms <> ""';

async function callChartWithWhere(
  page: import('@playwright/test').Page,
  nonce: string,
  where: string | null
): Promise<{ status: number; body: any; elapsedMs: number }> {
  const chartData: Record<string, string> = {
    data1: 'COUNT(id)',
    data2: 'COUNT( DISTINCT ip )',
  };
  if (where !== null) {
    chartData.where = where;
  }

  const args = JSON.stringify({
    ...CHART_TEST_RANGE,
    chart_data: chartData,
  });

  const t0 = Date.now();
  const res = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
    form: { action: 'slimstat_fetch_chart_data', nonce, args, granularity: 'monthly' },
  });
  const elapsedMs = Date.now() - t0;

  return { status: res.status(), body: res.ok() ? await res.json() : null, elapsedMs };
}

// ─── Suite ────────────────────────────────────────────────────────────────────

test.describe('Chart where-clause allowlist — Patchstack SQLi regression', () => {
  test.setTimeout(60_000);

  let sharedNonce: string;

  test.beforeAll(async ({ browser }) => {
    installMuPluginByName('nonce-helper-mu-plugin.php');
    const ctx  = await browser.newContext();
    const page = await ctx.newPage();
    sharedNonce = await getChartNonce(page);
    await ctx.close();
  });

  test.afterAll(async () => {
    uninstallMuPluginByName('nonce-helper-mu-plugin.php');
    await closeDb();
  });

  test('legit where (slim_p1_19_01 Search Terms) is accepted', async ({ page }) => {
    const { status, body } = await callChartWithWhere(page, sharedNonce, LEGIT_WHERE);

    expect(status).toBe(200);
    expect(body?.success, `Expected success:true, got: ${JSON.stringify(body?.data)}`).toBe(true);
  });

  test('legit where with extra whitespace is accepted (normalization)', async ({ page }) => {
    const padded = '   searchterms   <>   "_"   AND  searchterms\tIS NOT NULL   AND searchterms <> ""   ';
    const { status, body } = await callChartWithWhere(page, sharedNonce, padded);

    expect(status).toBe(200);
    expect(body?.success, `Expected success:true, got: ${JSON.stringify(body?.data)}`).toBe(true);
  });

  test('Patchstack PoC: IF(1=1,SLEEP(2),0) is rejected before SQL executes', async ({ page }) => {
    const { status, body, elapsedMs } = await callChartWithWhere(page, sharedNonce, 'IF(1=1,SLEEP(2),0)');

    expect(status).toBe(200);
    expect(body?.success, 'SQLi payload must be rejected').toBe(false);
    expect(typeof body?.data?.message).toBe('string');
    // SLEEP(2) would push response well past 2000ms. Asserting < 1500ms proves
    // the payload was rejected before the query layer.
    expect(elapsedMs).toBeLessThan(1500);
  });

  test('stacked-statement injection is rejected', async ({ page }) => {
    const { status, body } = await callChartWithWhere(page, sharedNonce, '1=1; DROP TABLE wp_slim_stats--');

    expect(status).toBe(200);
    expect(body?.success, 'Stacked-statement payload must be rejected').toBe(false);
    expect(typeof body?.data?.message).toBe('string');
  });

  test('no `where` provided (slim_p1_01-style report) still succeeds', async ({ page }) => {
    const { status, body } = await callChartWithWhere(page, sharedNonce, null);

    expect(status).toBe(200);
    expect(body?.success, `Expected success:true, got: ${JSON.stringify(body?.data)}`).toBe(true);
  });

  test('empty `where` string still succeeds (early-return guard)', async ({ page }) => {
    const { status, body } = await callChartWithWhere(page, sharedNonce, '');

    expect(status).toBe(200);
    expect(body?.success, `Expected success:true, got: ${JSON.stringify(body?.data)}`).toBe(true);
  });
});
