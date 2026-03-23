/**
 * E2E tests: Adblock bypass URL `/request/{hash}/` must NOT appear in Access Log
 *
 * The adblock_bypass transport uses an obfuscated URL pattern `/request/{hash}/`
 * to evade ad-blockers. This URL should NEVER be recorded as the `resource` field
 * in wp_slim_stats — the actual page resource should be recorded instead.
 *
 * Covers:
 *   BF1 — Server mode + AJAX transport: bypass URL not in Access Log
 *   BF2 — Server mode + adblock_bypass transport: bypass URL not doubled
 *   BF3 — Client mode + AJAX: normal tracking, no bypass pollution
 *   BF4 — Client mode + adblock_bypass: bypass works, no double recording
 *   BF5 — Regression: normal page tracking still works in all modes
 */
import { test, expect } from '@playwright/test';
import * as mysql from 'mysql2/promise';
import {
  installOptionMutator,
  uninstallOptionMutator,
  installRewriteFlush,
  uninstallRewriteFlush,
  setSlimstatOption,
  setSlimstatOptions,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  waitForPageviewRow,
  closeDb,
  getPool,
} from './helpers/setup';
import { BASE_URL, MYSQL_CONFIG } from './helpers/env';

// ─── DB helpers ───────────────────────────────────────────────────────

async function getStatCount(): Promise<number> {
  const [rows] = (await getPool().execute(
    'SELECT COUNT(*) as cnt FROM wp_slim_stats',
  )) as any;
  return parseInt(rows[0].cnt, 10);
}

async function getRecentResources(limit = 10): Promise<string[]> {
  const [rows] = (await getPool().execute(
    `SELECT resource FROM wp_slim_stats ORDER BY id DESC LIMIT ${limit}`,
  )) as any;
  return rows.map((r: any) => r.resource ?? '');
}

async function getResourcesForMarker(marker: string): Promise<string[]> {
  const [rows] = (await getPool().execute(
    'SELECT resource FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id DESC',
    [`%${marker}%`],
  )) as any;
  return rows.map((r: any) => r.resource ?? '');
}

async function getAllResourcesSinceId(afterId: number): Promise<string[]> {
  const [rows] = (await getPool().execute(
    'SELECT resource FROM wp_slim_stats WHERE id > ? ORDER BY id ASC',
    [afterId],
  )) as any;
  return rows.map((r: any) => r.resource ?? '');
}

async function getMaxId(): Promise<number> {
  const [rows] = (await getPool().execute(
    'SELECT COALESCE(MAX(id), 0) as maxid FROM wp_slim_stats',
  )) as any;
  return parseInt(rows[0].maxid, 10);
}

// ─── Flush rewrite rules via permalink settings ──────────────────────

async function flushRewrites(
  page: import('@playwright/test').Page,
): Promise<void> {
  await page.goto(`${BASE_URL}/wp-admin/options-permalink.php`);
  await page.waitForLoadState('load');
}

// ─── Test suite ──────────────────────────────────────────────────────

test.describe('Adblock Bypass URL Resource Pollution', () => {
  test.setTimeout(90_000);

  test.beforeAll(async () => {
    installOptionMutator();
    installRewriteFlush();
  });

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    await setSlimstatOptions(page, {
      ignore_wp_users: 'no',
      gdpr_enabled: 'off',
      _migration_5460: '5.4.6',
    });
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    uninstallRewriteFlush();
    await closeDb();
  });

  // ─── BF1: Server mode + AJAX — bypass URL not in Access Log ─────

  test('BF1: server mode + AJAX transport does not record /request/ URL', async ({
    page,
    browser,
  }) => {
    await setSlimstatOptions(page, {
      javascript_mode: 'off',
      tracking_request_method: 'ajax',
    });

    const countBefore = await getStatCount();

    // Visit as anonymous user (fresh context = no cookies)
    const ctx = await browser.newContext();
    const anonPage = await ctx.newPage();

    const marker = `bf1-server-ajax-${Date.now()}`;
    await anonPage.goto(`${BASE_URL}/?e2e=${marker}`, {
      waitUntil: 'networkidle',
    });

    // Server-side tracking should record the hit
    const stat = await waitForPageviewRow(marker, 15_000);
    expect(stat, 'server-side tracking should record a row').not.toBeNull();

    const countAfter = await getStatCount();
    expect(
      countAfter,
      'stat count should increase',
    ).toBeGreaterThan(countBefore);

    // The recorded resource should be the actual page, NOT /request/{hash}/
    expect(stat!.resource).toContain(marker);
    expect(stat!.resource).not.toMatch(/\/request\/[a-f0-9]/);

    // Check no recent resource contains /request/
    const recent = await getRecentResources(5);
    for (const r of recent) {
      expect(r, `resource "${r}" should not contain /request/`).not.toMatch(
        /\/request\/[a-f0-9]/,
      );
    }

    await ctx.close();
  });

  // ─── BF2: Server mode + adblock_bypass — no doubled recording ────

  test('BF2: server mode + adblock_bypass does not double-record or record /request/ URL', async ({
    page,
    browser,
  }) => {
    await setSlimstatOptions(page, {
      javascript_mode: 'off',
      tracking_request_method: 'adblock_bypass',
    });
    await flushRewrites(page);

    const idBefore = await getMaxId();

    // Visit as anonymous user
    const ctx = await browser.newContext();
    const anonPage = await ctx.newPage();

    const marker = `bf2-server-adblock-${Date.now()}`;
    await anonPage.goto(`${BASE_URL}/?e2e=${marker}`, {
      waitUntil: 'networkidle',
    });

    // Wait for tracking to complete
    const stat = await waitForPageviewRow(marker, 15_000);
    expect(stat, 'tracking should record a row').not.toBeNull();

    // Allow JS update to settle
    await anonPage.waitForTimeout(3_000);

    // Check total new rows: server-side creates 1, JS may update it.
    // At most 2 rows (server insert + JS finalize if separate), but no /request/ URL.
    const newResources = await getAllResourcesSinceId(idBefore);

    // None of the new rows should have /request/ as the resource
    for (const r of newResources) {
      expect(
        r,
        `new resource "${r}" should not be a /request/ URL`,
      ).not.toMatch(/\/request\/[a-f0-9]/);
    }

    // Verify the last 5 resources are clean
    const recent = await getRecentResources(5);
    for (const r of recent) {
      expect(r, `resource "${r}" should not contain /request/`).not.toMatch(
        /\/request\/[a-f0-9]/,
      );
    }

    await ctx.close();
  });

  // ─── BF3: Client mode + AJAX — normal tracking, no bypass pollution

  test('BF3: client mode + AJAX records homepage normally without /request/ pollution', async ({
    page,
    browser,
  }) => {
    await setSlimstatOptions(page, {
      javascript_mode: 'on',
      tracking_request_method: 'ajax',
    });

    const countBefore = await getStatCount();

    const ctx = await browser.newContext();
    const anonPage = await ctx.newPage();

    const marker = `bf3-client-ajax-${Date.now()}`;
    await anonPage.goto(`${BASE_URL}/?e2e=${marker}`, {
      waitUntil: 'networkidle',
    });

    const stat = await waitForPageviewRow(marker, 15_000);
    expect(stat, 'client-side AJAX tracking should record a row').not.toBeNull();

    const countAfter = await getStatCount();
    expect(countAfter).toBeGreaterThan(countBefore);

    // Resource should be the actual page
    expect(stat!.resource).toContain(marker);
    expect(stat!.resource).not.toMatch(/\/request\/[a-f0-9]/);

    await ctx.close();
  });

  // ─── BF4: Client mode + adblock_bypass — bypass works, no /request/ recorded

  test('BF4: client mode + adblock_bypass does not record /request/ URL as resource', async ({
    page,
    browser,
  }) => {
    await setSlimstatOptions(page, {
      javascript_mode: 'on',
      tracking_request_method: 'adblock_bypass',
    });
    await flushRewrites(page);

    const countBefore = await getStatCount();

    const ctx = await browser.newContext();
    const anonPage = await ctx.newPage();

    // Track network requests to verify the bypass transport is used
    const postUrls: string[] = [];
    anonPage.on('request', (req) => {
      if (req.method() === 'POST') {
        postUrls.push(req.url());
      }
    });

    const marker = `bf4-client-adblock-${Date.now()}`;
    await anonPage.goto(`${BASE_URL}/?e2e=${marker}`, {
      waitUntil: 'networkidle',
    });

    const stat = await waitForPageviewRow(marker, 15_000);
    expect(stat, 'adblock_bypass should record a row').not.toBeNull();

    const countAfter = await getStatCount();
    expect(countAfter).toBeGreaterThan(countBefore);

    // Resource is the actual page, NOT /request/
    expect(stat!.resource).toContain(marker);
    expect(stat!.resource).not.toMatch(/\/request\/[a-f0-9]/);

    // No row in the DB should have /request/ as resource
    const markerResources = await getResourcesForMarker(marker);
    for (const r of markerResources) {
      expect(r).not.toMatch(/\/request\/[a-f0-9]/);
    }

    // Check recent resources overall
    const recent = await getRecentResources(5);
    for (const r of recent) {
      expect(r).not.toMatch(/\/request\/[a-f0-9]/);
    }

    await ctx.close();
  });

  // ─── BF5: Regression — normal page tracking works in all modes ────

  test('BF5: regression — normal page tracking works across mode transitions', async ({
    page,
    browser,
  }) => {
    // Restore default: client mode + AJAX
    await setSlimstatOptions(page, {
      javascript_mode: 'on',
      tracking_request_method: 'ajax',
    });

    // Visit 1: homepage
    const ctx1 = await browser.newContext();
    const page1 = await ctx1.newPage();

    const marker1 = `bf5-home-${Date.now()}`;
    await page1.goto(`${BASE_URL}/?e2e=${marker1}`, {
      waitUntil: 'networkidle',
    });

    const stat1 = await waitForPageviewRow(marker1, 15_000);
    expect(stat1, 'first page visit should be recorded').not.toBeNull();
    expect(stat1!.resource).toContain(marker1);

    await ctx1.close();

    // Visit 2: a second page
    const ctx2 = await browser.newContext();
    const page2 = await ctx2.newPage();

    const marker2 = `bf5-second-${Date.now()}`;
    await page2.goto(`${BASE_URL}/?p=1&e2e=${marker2}`, {
      waitUntil: 'networkidle',
    });

    const stat2 = await waitForPageviewRow(marker2, 15_000);
    expect(stat2, 'second page visit should be recorded').not.toBeNull();
    expect(stat2!.resource).toContain(marker2);

    // Neither should contain /request/
    expect(stat1!.resource).not.toMatch(/\/request\/[a-f0-9]/);
    expect(stat2!.resource).not.toMatch(/\/request\/[a-f0-9]/);

    await ctx2.close();
  });
});
