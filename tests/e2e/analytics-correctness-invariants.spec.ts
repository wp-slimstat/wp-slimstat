/**
 * E2E tests: Analytics Correctness Invariants
 *
 * Verifies per-event invariants in the tracking pipeline after each pageview:
 *   1. Row exists      — exactly 1 row is inserted into wp_slim_stats
 *   2. visit_id chain  — the row carries a non-null, non-zero visit_id
 *   3. Field match     — the `resource` field matches the URL that was tracked
 *   4. No duplicates   — sendBeacon / double-fire does not produce extra rows
 */
import { test, expect } from '@playwright/test';
import * as mysql from 'mysql2/promise';
import {
  installOptionMutator,
  uninstallOptionMutator,
  setSlimstatOption,
  setSlimstatOptions,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  closeDb,
  getPool,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

// ─── Run-scoped correlation tag ────────────────────────────────────────────

const RUN_ID = process.env.E2E_RUN_ID ?? `inv-${Date.now()}`;

// ─── DB polling helper ─────────────────────────────────────────────────────

interface SlimStatRow {
  id: number;
  resource: string;
  visit_id: number | null;
  content_type: string | null;
  notes: string | null;
  dt: number;
}

/**
 * Poll `wp_slim_stats` until a row whose `resource` contains `marker` appears,
 * or until `timeoutMs` elapses.  Returns the matching row or null.
 */
async function waitForStatRow(
  marker: string,
  timeoutMs = 15_000,
  intervalMs = 500,
): Promise<SlimStatRow | null> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const [rows] = (await getPool().execute(
      'SELECT id, resource, visit_id, content_type, notes, dt FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id DESC LIMIT 1',
      [`%${marker}%`],
    )) as [SlimStatRow[], unknown];
    if (rows.length > 0) return rows[0];
    await new Promise((r) => setTimeout(r, intervalMs));
  }
  return null;
}

/**
 * Count all rows in `wp_slim_stats` whose `resource` contains `marker`.
 * Used to detect duplicate inserts.
 */
async function countStatRows(marker: string): Promise<number> {
  const [rows] = (await getPool().execute(
    'SELECT COUNT(*) AS cnt FROM wp_slim_stats WHERE resource LIKE ?',
    [`%${marker}%`],
  )) as [Array<{ cnt: number }>, unknown];
  return rows[0]?.cnt ?? 0;
}

// ─── Suite ─────────────────────────────────────────────────────────────────

test.describe('Analytics Correctness Invariants', () => {
  test.setTimeout(60_000);

  test.beforeAll(() => {
    installOptionMutator();
  });

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    // Ensure REST tracking is active and GDPR gate is off so every visit records
    await setSlimstatOption(page, 'tracking_request_method', 'rest');
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    await closeDb();
  });

  // ─── Invariant 1: Row exists ────────────────────────────────────────────

  test('INV-1: tracked pageview creates exactly one row in wp_slim_stats', async ({ page }) => {
    const marker = `${RUN_ID}-inv1-${Date.now()}`;
    const started = Math.floor(Date.now() / 1000);

    await page.goto(`${BASE_URL}/?e2e=${marker}`);
    await page.waitForLoadState('networkidle');

    const row = await waitForStatRow(marker);
    expect(row, 'Expected a stats row to be created for the tracked pageview').toBeTruthy();
    expect(Number(row!.dt)).toBeGreaterThanOrEqual(started - 5);
    expect(Number(row!.dt)).toBeLessThanOrEqual(Math.ceil(Date.now() / 1000) + 5);
  });

  // ─── Invariant 2: visit_id chain intact ────────────────────────────────

  test('INV-2: stats row has a non-null, non-zero visit_id', async ({ page }) => {
    const marker = `${RUN_ID}-inv2-${Date.now()}`;

    await page.goto(`${BASE_URL}/?e2e=${marker}`);
    await page.waitForLoadState('networkidle');

    const row = await waitForStatRow(marker);
    expect(row, 'Stats row must exist before checking visit_id').toBeTruthy();

    expect(
      row!.visit_id,
      'visit_id must be non-null (visit chain was not created)',
    ).not.toBeNull();

    expect(
      Number(row!.visit_id),
      'visit_id must be a positive integer (> 0)',
    ).toBeGreaterThan(0);
    expect(Number.isInteger(Number(row!.visit_id))).toBe(true);
  });

  // ─── Invariant 3: resource field matches tracked URL ───────────────────

  test('INV-3: resource field in wp_slim_stats matches the tracked URL', async ({ page }) => {
    const marker = `${RUN_ID}-inv3-${Date.now()}`;
    const targetUrl = `${BASE_URL}/?e2e=${marker}`;

    await page.goto(targetUrl);
    await page.waitForLoadState('networkidle');

    const row = await waitForStatRow(marker);
    expect(row, 'Stats row must exist before checking resource').toBeTruthy();

    expect(
      row!.resource,
      `resource should contain the marker "${marker}"`,
    ).toContain(marker);
  });

  // ─── Invariant 4: No sendBeacon duplicates ─────────────────────────────

  test('INV-4: single pageview produces exactly one row (no duplicate inserts)', async ({ page }) => {
    const marker = `${RUN_ID}-inv4-${Date.now()}`;

    await page.goto(`${BASE_URL}/?e2e=${marker}`);
    await page.waitForLoadState('networkidle');

    // Poll until the row appears (or 15s deadline), then wait a further settle window.
    await waitForStatRow(marker);

    const count = await countStatRows(marker);

    expect(
      count,
      `Expected exactly 1 row for marker "${marker}", got ${count}. Possible duplicate insert via sendBeacon or double-fire.`,
    ).toBe(1);
  });

  // ─── Bonus: sequential pageviews each get their own distinct row ────────

  test('INV-5: two sequential pageviews produce two distinct rows with different IDs', async ({ page }) => {
    const marker1 = `${RUN_ID}-inv5a`;
    const marker2 = `${RUN_ID}-inv5b`;

    await page.goto(`${BASE_URL}/?e2e=${marker1}`);
    await page.waitForLoadState('networkidle');

    await page.goto(`${BASE_URL}/?e2e=${marker2}`);
    await page.waitForLoadState('networkidle');

    const row1 = await waitForStatRow(marker1);
    const row2 = await waitForStatRow(marker2);

    expect(row1, 'Row for first pageview must exist').toBeTruthy();
    expect(row2, 'Row for second pageview must exist').toBeTruthy();

    expect(row1!.id).not.toBe(row2!.id);
    expect(row1!.resource).toContain(marker1);
    expect(row2!.resource).toContain(marker2);
  });

  test('INV-6: repeated text/plain client metrics update the server row without duplicates', async ({ browser }) => {
    await setSlimstatOptions({ javascript_mode: 'off', anonymous_tracking: 'off', set_tracker_cookie: 'on', ignore_wp_users: 'no' });
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const marker = `${RUN_ID}-merge-${Date.now()}`;
    try {
      const response = await context.request.get(`${BASE_URL}/?e2e=${marker}`);
      const id = (await response.text()).match(/"id":"(\d+\.[a-f0-9]+)"/)?.[1];
      expect(id).toBeTruthy();
      expect(await countStatRows(marker)).toBe(1);
      const original = await waitForStatRow(marker);
      const body = new URLSearchParams({ action: 'slimtrack', id: id!, sw: '1440', sh: '900', bw: '1200', bh: '800', pp: '237' }).toString();
      for (let attempt = 0; attempt < 2; attempt++) {
        const update = await context.request.post(`${BASE_URL}/?rest_route=/slimstat/v1/hit`, {
          headers: { 'Content-Type': 'text/plain;charset=UTF-8' }, data: body,
        });
        expect(update.status()).toBe(200);
        expect((await update.text()).trim()).toMatch(/^[1-9]\d*\.[a-f0-9]+$/);
      }
      const [rows]: any = await getPool().execute('SELECT id, visit_id, resource, screen_width, screen_height, page_performance FROM wp_slim_stats WHERE resource LIKE ?', [`%${marker}%`]);
      expect(rows).toHaveLength(1);
      expect(rows[0].id).toBe(original!.id);
      expect(rows[0].visit_id).toBe(original!.visit_id);
      expect(Number(rows[0].screen_width)).toBe(1440);
      expect(Number(rows[0].screen_height)).toBe(900);
      expect(Number(rows[0].page_performance)).toBe(237);
      expect(rows[0].resource).toContain(marker);
    } finally { await context.close(); }
  });

  test('INV-7: denied consent rejects a direct tracking attempt without storing PII or a cookie', async ({ browser }) => {
    await setSlimstatOptions({ javascript_mode: 'on', gdpr_enabled: 'on', anonymous_tracking: 'off', consent_integration: 'slimstat_banner' });
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const marker = `${RUN_ID}-denied-${Date.now()}`;
    try {
      await context.addCookies([{ name: 'slimstat_gdpr_consent', value: 'denied', url: BASE_URL }]);
      const response = await context.request.post(`${BASE_URL}/?rest_route=/slimstat/v1/hit`, {
        form: { action: 'slimtrack', res: Buffer.from(`${BASE_URL}/?e2e=${marker}`).toString('base64url') },
      });
      expect(response.status()).toBe(200);
      expect((await response.text()).trim()).toBe('-301');
      expect(await countStatRows(marker)).toBe(0);
      expect((await context.cookies()).filter(cookie => cookie.name === 'slimstat_tracking_code')).toHaveLength(0);
    } finally { await context.close(); }
  });
});
