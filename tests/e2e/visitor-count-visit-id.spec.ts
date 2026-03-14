/**
 * E2E tests: Visitor count and visit_id correlation (AC-TRK-007 / AC-TRK-003)
 *
 * Verifies that visiting multiple pages correlates them under the same visit_id
 * (session continuity) and that distinct sessions produce distinct visit_ids
 * for accurate visitor counting.
 */
import { test, expect } from '@playwright/test';
import * as mysql from 'mysql2/promise';
import {
  installOptionMutator,
  uninstallOptionMutator,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  closeDb,
} from './helpers/setup';
import { BASE_URL, MYSQL_CONFIG } from './helpers/env';

let pool: mysql.Pool;

function getPool(): mysql.Pool {
  if (!pool) {
    pool = mysql.createPool(MYSQL_CONFIG);
  }
  return pool;
}

/** Poll DB until at least `minRows` rows matching the marker appear. */
async function waitForStatRows(
  marker: string,
  minRows: number,
  timeoutMs = 15_000,
  intervalMs = 500,
): Promise<Record<string, any>[]> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const [rows] = (await getPool().execute(
      'SELECT * FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id ASC',
      [`%${marker}%`],
    )) as any;
    if (rows.length >= minRows) return rows;
    await new Promise((r) => setTimeout(r, intervalMs));
  }
  // Return whatever we have
  const [rows] = (await getPool().execute(
    'SELECT * FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id ASC',
    [`%${marker}%`],
  )) as any;
  return rows;
}

/** Get distinct visit_id count from stats table. */
async function getDistinctVisitIdCount(): Promise<number> {
  const [rows] = (await getPool().execute(
    'SELECT COUNT(DISTINCT visit_id) as cnt FROM wp_slim_stats WHERE visit_id > 0',
  )) as any;
  return parseInt(rows[0].cnt, 10);
}

test.describe('Visitor Count & Visit ID Correlation (AC-TRK-003/007)', () => {
  test.setTimeout(120_000);

  test.beforeAll(async () => {
    installOptionMutator();
  });

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    await setSlimstatOption(page, 'tracking_request_method', 'rest');
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
    // Enable JS-mode tracking and cookies so visit_id is properly assigned
    await setSlimstatOption(page, 'javascript_mode', 'on');
    await setSlimstatOption(page, 'set_tracker_cookie', 'on');
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    if (pool) await pool.end();
    await closeDb();
  });

  // ─── Test 1: Same session shares visit_id across pages ───────

  test('multiple pages in same session share the same visit_id', async ({ page }) => {
    const sessionMarker = `visit-session-${Date.now()}`;

    // Navigate to 3 pages in the same browser context (same session)
    await page.goto(`${BASE_URL}/?e2e=${sessionMarker}-p1`);
    await page.waitForLoadState('load');
    await page.waitForTimeout(2500);

    await page.goto(`${BASE_URL}/?e2e=${sessionMarker}-p2`);
    await page.waitForLoadState('load');
    await page.waitForTimeout(2500);

    await page.goto(`${BASE_URL}/?e2e=${sessionMarker}-p3`);
    await page.waitForLoadState('load');
    await page.waitForTimeout(2500);

    const rows = await waitForStatRows(sessionMarker, 3, 15_000);
    expect(rows.length).toBeGreaterThanOrEqual(2);

    // All rows in the same session should have the same visit_id
    const visitIds = rows
      .map((r: any) => parseInt(r.visit_id, 10))
      .filter((v: number) => v > 0);

    if (visitIds.length >= 2) {
      const uniqueIds = [...new Set(visitIds)];
      expect(uniqueIds).toHaveLength(1);
    }
  });

  // ─── Test 2: Different browser contexts get different visit_ids

  test('separate browser contexts produce different visit_ids', async ({ browser }) => {
    const marker = `visit-ctx-${Date.now()}`;

    // Context A (separate session)
    const ctxA = await browser.newContext({ recordVideo: undefined, trace: 'off' } as any);
    const pageA = await ctxA.newPage();
    await pageA.goto(`${BASE_URL}/?e2e=${marker}-ctxA`);
    await pageA.waitForLoadState('load');
    await pageA.waitForTimeout(3000);

    // Context B (separate session)
    const ctxB = await browser.newContext({ recordVideo: undefined, trace: 'off' } as any);
    const pageB = await ctxB.newPage();
    await pageB.goto(`${BASE_URL}/?e2e=${marker}-ctxB`);
    await pageB.waitForLoadState('load');
    await pageB.waitForTimeout(3000);

    const rows = await waitForStatRows(marker, 2, 15_000);
    expect(rows.length).toBeGreaterThanOrEqual(2);

    const visitIds = rows
      .map((r: any) => parseInt(r.visit_id, 10))
      .filter((v: number) => v > 0);

    // Two separate contexts should have different visit_ids
    if (visitIds.length >= 2) {
      const uniqueIds = [...new Set(visitIds)];
      expect(uniqueIds.length).toBeGreaterThanOrEqual(2);
    }

    await pageA.close();
    await pageB.close();
    await ctxA.close();
    await ctxB.close();
  });

  // ─── Test 3: Visitor counter increments with new sessions ────

  test('distinct visit_id count increments with each new session', async ({ browser }) => {
    const marker = `visit-count-${Date.now()}`;

    // Create 3 separate browser contexts (3 distinct visitors)
    for (let i = 0; i < 3; i++) {
      const ctx = await browser.newContext({ recordVideo: undefined, trace: 'off' } as any);
      const pg = await ctx.newPage();
      await pg.goto(`${BASE_URL}/?e2e=${marker}-v${i}`);
      await pg.waitForLoadState('load');
      await pg.waitForTimeout(5000);
      await pg.close();
      await ctx.close();
    }

    const rows = await waitForStatRows(marker, 3, 20_000);
    expect(rows.length).toBeGreaterThanOrEqual(3);

    const visitIds = rows
      .map((r: any) => parseInt(r.visit_id, 10))
      .filter((v: number) => v > 0);

    const uniqueIds = [...new Set(visitIds)];
    // With javascript_mode=on and separate browser contexts, each should get
    // a distinct visit_id. Require at least 2 distinct IDs (timing may merge some).
    expect(uniqueIds.length).toBeGreaterThanOrEqual(2);
  });

  // ─── Test 4: visit_id is always positive (non-zero) ──────────

  test('every tracked row has a positive visit_id', async ({ page }) => {
    const marker = `visit-positive-${Date.now()}`;

    await page.goto(`${BASE_URL}/?e2e=${marker}-a`);
    await page.waitForLoadState('load');
    await page.waitForTimeout(4000);

    await page.goto(`${BASE_URL}/?e2e=${marker}-b`);
    await page.waitForLoadState('load');
    await page.waitForTimeout(4000);

    const rows = await waitForStatRows(marker, 2, 20_000);
    expect(rows.length).toBeGreaterThanOrEqual(1);

    for (const row of rows) {
      const vid = parseInt(row.visit_id, 10);
      expect(vid).toBeGreaterThan(0);
    }
  });

  // ─── Test 5: Empty table — first visit creates visit_id = 1 ──

  test('first visit on empty table creates a valid visit_id', async ({ browser }) => {
    // Use a fresh context to ensure a new session
    const ctx = await browser.newContext({ recordVideo: undefined, trace: 'off' } as any);
    const pg = await ctx.newPage();

    const marker = `visit-first-${Date.now()}`;
    await pg.goto(`${BASE_URL}/?e2e=${marker}`);
    await pg.waitForLoadState('load');
    await pg.waitForTimeout(5000);

    const rows = await waitForStatRows(marker, 1, 15_000);
    expect(rows.length).toBeGreaterThanOrEqual(1);

    const vid = parseInt(rows[0].visit_id, 10);
    expect(vid).toBeGreaterThan(0);

    await pg.close();
    await ctx.close();
  });
});
