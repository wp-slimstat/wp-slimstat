/**
 * Issue #242: Finalization requests return 400 — stat['id'] lost before ensureVisitId()
 *
 * Tests that finalization/update requests (carrying an existing pageview id)
 * return 200 instead of 400. The bug: ensureVisitId() overwrites global stat,
 * losing the id assigned from the client payload.
 *
 * @see https://github.com/wp-slimstat/wp-slimstat/issues/242
 */
import { test, expect, Page, BrowserContext } from '@playwright/test';
import {
  getPool,
  clearStatsTable,
  waitForPageviewRow,
  closeDb,
  setSlimstatSetting,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

// Alias shared helpers for brevity within this spec
const snapshotOptions = snapshotSlimstatOptions;
const restoreOptions = restoreSlimstatOptions;
const setOption = setSlimstatSetting;

async function anonContext(browser: any): Promise<{ ctx: BrowserContext; page: Page }> {
  const ctx = await browser.newContext();
  return { ctx, page: await ctx.newPage() };
}

test.describe('Issue #242: Finalization 400 regression', () => {
  test.setTimeout(90_000);

  test.beforeEach(async () => {
    await snapshotOptions();
    await clearStatsTable();
    await setOption('tracking_request_method', 'rest');
    await setOption('gdpr_enabled', 'off');
    await setOption('javascript_mode', 'on');
    await setOption('ignore_wp_users', 'no');
  });
  test.afterEach(async () => { await restoreOptions(); });

  test('finalization request (visibilitychange) returns 200, not 400', async ({ browser }) => {
    const { ctx, page } = await anonContext(browser);

    // Collect ALL REST responses (initial hit + finalization)
    const restResponses: Array<{ status: number; url: string; body: string }> = [];
    page.on('response', async (res) => {
      if (res.url().includes('/slimstat/v1/hit') || res.url().includes('rest_route=/slimstat/v1/hit')) {
        let body = '';
        try { body = await res.text(); } catch { /* beacon */ }
        restResponses.push({ status: res.status(), url: res.url(), body });
      }
    });

    // Step 1: Visit page to create initial pageview (get an id back)
    const marker = `fin-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(3000);

    // Verify initial pageview was created
    expect(restResponses.length, 'Initial tracking request should fire').toBeGreaterThan(0);
    const initialResponse = restResponses.find((r) => r.status === 200);
    expect(initialResponse, 'Initial hit should return 200').toBeTruthy();

    // Step 2: Trigger finalization via visibilitychange
    // This causes the JS tracker to send a finalization request with the existing id
    await page.evaluate(() => {
      Object.defineProperty(document, 'visibilityState', { value: 'hidden', writable: true });
      document.dispatchEvent(new Event('visibilitychange'));
    });
    await page.waitForTimeout(3000);

    // Step 3: Check that finalization did NOT return 400
    // The finalization is the SECOND request (after the initial 200)
    const postInitialResponses = restResponses.slice(1);
    const has400 = postInitialResponses.some((r) => r.status === 400);
    expect(
      has400,
      `Finalization should not return 400. Responses after initial: ${JSON.stringify(postInitialResponses.map(r => ({ status: r.status, body: r.body?.substring(0, 80) })))}`,
    ).toBe(false);

    await ctx.close();
  });

  test('finalization update preserves existing pageview row (row still exists by id)', async ({ browser }) => {
    const { ctx, page } = await anonContext(browser);

    const marker = `nodupe-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(3000);

    const stat = await waitForPageviewRow(marker, 15_000);
    expect(stat, 'Initial pageview must exist').toBeTruthy();
    const rowId = stat!.id;

    // Total row count before finalization
    const [beforeRows] = (await getPool().execute(
      'SELECT COUNT(*) as cnt FROM wp_slim_stats',
    )) as any;
    const countBefore = Number(beforeRows[0].cnt);

    // Trigger finalization
    await page.evaluate(() => {
      Object.defineProperty(document, 'visibilityState', { value: 'hidden', writable: true });
      document.dispatchEvent(new Event('visibilitychange'));
    });
    await page.waitForTimeout(3000);

    // The original row should still exist (by id)
    const [rowCheck] = (await getPool().execute(
      'SELECT id FROM wp_slim_stats WHERE id = ?',
      [rowId],
    )) as any;
    expect(rowCheck.length, `Row ${rowId} should still exist after finalization`).toBe(1);

    // Total row count should not grow significantly. Finalization primarily updates
    // the existing row (dt_out), but the JS tracker may also fire a navigation hit
    // concurrently, which can create one additional row. Allow +1 for this race.
    const [afterRows] = (await getPool().execute(
      'SELECT COUNT(*) as cnt FROM wp_slim_stats',
    )) as any;
    const countAfter = Number(afterRows[0].cnt);
    expect(countAfter, `Row count grew too much. Before: ${countBefore}, After: ${countAfter}`).toBeLessThanOrEqual(countBefore + 1);

    await ctx.close();
  });

  test('update request with existing id returns 200 (server-side direct)', async ({ browser }) => {
    const { ctx, page } = await anonContext(browser);

    // Step 1: Create a pageview to get a valid id+checksum
    await page.goto(`${BASE_URL}/?e2e=direct-update-${Date.now()}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(3000);

    // Extract the id from SlimStatParams (set by the tracker after initial hit)
    const pageviewId = await page.evaluate(() => {
      const p = (window as any).SlimStatParams;
      return p ? p.id : null;
    });
    expect(pageviewId, 'Pageview id must be assigned after initial hit').toBeTruthy();

    // Step 2: Send an update request directly (simulates finalization)
    const res = await page.request.post(`${BASE_URL}/wp-json/slimstat/v1/hit`, {
      form: { action: 'slimtrack', id: pageviewId },
    });

    const status = res.status();
    const body = await res.text();
    expect(
      status,
      `Update with existing id should return 200, got ${status}: ${body}`,
    ).toBe(200);

    await ctx.close();
  });
});

test.afterAll(async () => {
  await closeDb();
});
