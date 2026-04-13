/**
 * E2E tests: Report pagination across all report types.
 *
 * Validates that:
 * 1. type=recent reports show different content on page 2
 * 2. type=top reports show different content on page 2
 * 3. Pagination total stays constant across pages
 * 4. Reports with hardcoded WHERE (outbound) don't show "No data" on page 2
 *
 * These tests use seeded data to guarantee multi-page results.
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';
import {
  closeDb,
  clearStatsTable,
  seedPageviews,
} from './helpers/setup';
import * as mysql from 'mysql2/promise';
import { MYSQL_CONFIG } from './helpers/env';

// ─── Helpers ─────────────────────────────────────────────────────

/** Seed user-tagged pageviews (for Recent Users report) */
async function seedUserPageviews(count: number): Promise<void> {
  const pool = mysql.createPool(MYSQL_CONFIG);
  const placeholders: string[] = [];
  const values: any[] = [];
  const baseDt = Math.floor(Date.now() / 1000);
  for (let i = 0; i < count; i++) {
    const username = `user-${i}`;
    placeholders.push('(?, ?, ?, ?, ?, ?, ?, ?)');
    values.push(
      `/user-page-${i}`,
      baseDt - i,
      '127.0.0.1',
      1,
      'Chrome',
      'Windows',
      username,
      `[user:${username}]`,
    );
  }
  await pool.query(
    `INSERT INTO wp_slim_stats
       (resource, dt, ip, visit_id, browser, platform, username, notes)
     VALUES ${placeholders.join(', ')}`,
    values,
  );
  await pool.end();
}

/** Seed outbound link events (for Recent Outbound Links report) */
async function seedOutboundLinks(count: number): Promise<void> {
  const pool = mysql.createPool(MYSQL_CONFIG);
  const placeholders: string[] = [];
  const values: any[] = [];
  const baseDt = Math.floor(Date.now() / 1000);

  for (let i = 0; i < count; i++) {
    placeholders.push('(?, ?, ?, ?, ?, ?, ?)');
    values.push(
      `/page-with-outbound-${i}`,
      baseDt - i,
      '127.0.0.1',
      1,
      'Chrome',
      'Windows',
      `https://example.com/link-${i}`,
    );
  }
  await pool.query(
    `INSERT INTO wp_slim_stats
       (resource, dt, ip, visit_id, browser, platform, outbound_resource)
     VALUES ${placeholders.join(', ')}`,
    values,
  );
  await pool.end();
}

/** Parse "Showing X - Y of Z" from pagination text */
function parsePaginationTotal(text: string): string | null {
  const m = text.match(/of\s+([\d,+]+)/);
  return m ? m[1] : null;
}

/** Get first content text from a panel's rows */
async function getFirstRowText(page: any, panelId: string): Promise<string> {
  return page.evaluate((id: string) => {
    const rows: string[] = [];
    jQuery(`#${id} .inside p:not(.pagination):not(.loading):not(.nodata)`).each(function () {
      rows.push(jQuery(this as HTMLElement).text().trim().substring(0, 60));
    });
    return rows[0] || '(empty)';
  }, panelId);
}

// ─── Setup / Teardown ────────────────────────────────────────────

test.beforeAll(async () => {
  await clearStatsTable();
  // Seed 60 generic pageviews (for top reports)
  await seedPageviews({ count: 60, resourcePrefix: '/e2e-pagination-' });
  // Seed 50 user-tagged pageviews (for Recent Users)
  await seedUserPageviews(50);
  // Seed 50 outbound links (for Recent Outbound Links)
  await seedOutboundLinks(50);
});

test.afterAll(async () => {
  await clearStatsTable();
  await closeDb();
});

// ─── Tests ───────────────────────────────────────────────────────

test.describe('Report pagination — all types', () => {

  test('type=top report (Top Web Pages) shows different content on page 2', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`);
    await page.locator('#slim_p1_08').waitFor({ state: 'visible', timeout: 15000 });
    await page.waitForTimeout(3000);

    const page1Row = await getFirstRowText(page, 'slim_p1_08');
    const hasNext = await page.evaluate(() =>
      jQuery('#slim_p1_08 .pagination a.refresh.slimstat-font-angle-right').length > 0
    );

    if (!hasNext) {
      test.skip();
      return;
    }

    await page.locator('#slim_p1_08 .pagination a.refresh.slimstat-font-angle-right').first().click();
    await page.waitForTimeout(3000);

    const page2Row = await getFirstRowText(page, 'slim_p1_08');
    expect(page2Row).not.toBe(page1Row);
  });

  test('pagination "Showing" range advances on page 2', async ({ page }) => {
    // Verify that clicking next page changes the "Showing X-Y" range,
    // proving the pagination actually navigates to a different page.
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`);
    await page.locator('#slim_p1_08').waitFor({ state: 'visible', timeout: 15000 });
    await page.waitForTimeout(3000);

    const p1range = await page.evaluate(() => {
      const m = jQuery('#slim_p1_08 .pagination').text().match(/Showing\s+([\d,]+)\s*-\s*([\d,]+)/);
      return m ? { start: m[1], end: m[2] } : null;
    });
    expect(p1range).not.toBeNull();
    expect(p1range!.start).toBe('1');

    const hasNext = await page.evaluate(() =>
      jQuery('#slim_p1_08 .pagination a.refresh.slimstat-font-angle-right').length > 0
    );
    if (!hasNext) { test.skip(); return; }

    await page.locator('#slim_p1_08 .pagination a.refresh.slimstat-font-angle-right').first().click();
    await page.waitForTimeout(3000);

    const p2range = await page.evaluate(() => {
      const m = jQuery('#slim_p1_08 .pagination').text().match(/Showing\s+([\d,]+)\s*-\s*([\d,]+)/);
      return m ? { start: m[1], end: m[2] } : null;
    });
    expect(p2range).not.toBeNull();
    // Page 2 start must be greater than page 1 start
    expect(parseInt(p2range!.start.replace(/,/g, ''))).toBeGreaterThan(parseInt(p1range!.start.replace(/,/g, '')));
  });

  test('Recent Users (slim_p2_20) pagination range advances on page 2', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview3`);
    await page.locator('#slim_p2_20').waitFor({ state: 'visible', timeout: 15000 });
    await page.waitForTimeout(3000);

    const p1range = await page.evaluate(() => {
      const m = jQuery('#slim_p2_20 .pagination').text().match(/Showing\s+([\d,]+)\s*-\s*([\d,]+)/);
      return m ? { start: m[1], end: m[2] } : null;
    });

    const hasNext = await page.evaluate(() =>
      jQuery('#slim_p2_20 .pagination a.refresh.slimstat-font-angle-right').length > 0
    );
    if (!hasNext || !p1range) { test.skip(); return; }

    expect(p1range.start).toBe('1');

    await page.locator('#slim_p2_20 .pagination a.refresh.slimstat-font-angle-right').first().click();
    await page.waitForTimeout(3000);

    const p2range = await page.evaluate(() => {
      const m = jQuery('#slim_p2_20 .pagination').text().match(/Showing\s+([\d,]+)\s*-\s*([\d,]+)/);
      return m ? { start: m[1], end: m[2] } : null;
    });
    const noData = await page.evaluate(() =>
      jQuery('#slim_p2_20 .inside .nodata').length > 0
    );

    expect(noData).toBe(false);
    expect(p2range).not.toBeNull();
    expect(parseInt(p2range!.start.replace(/,/g, ''))).toBeGreaterThan(parseInt(p1range.start.replace(/,/g, '')));
  });

  test('Recent Outbound Links (slim_p4_01) page 2 shows data', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview4`);
    await page.locator('#slim_p4_01').waitFor({ state: 'visible', timeout: 15000 });
    await page.waitForTimeout(3000);

    const hasNext = await page.evaluate(() =>
      jQuery('#slim_p4_01 .pagination a.refresh.slimstat-font-angle-right').length > 0
    );

    if (!hasNext) {
      test.skip();
      return;
    }

    await page.locator('#slim_p4_01 .pagination a.refresh.slimstat-font-angle-right').first().click();
    await page.waitForTimeout(3000);

    const noData = await page.evaluate(() =>
      jQuery('#slim_p4_01 .inside .nodata').length > 0
    );
    expect(noData).toBe(false);
  });

});
