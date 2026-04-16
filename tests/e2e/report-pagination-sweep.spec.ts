/**
 * E2E regression test: pagination sweep across ALL slimview pages.
 *
 * Seeds diverse analytics data, then visits every slimview page and clicks
 * the next-page arrow on every multi-page report. Verifies:
 *   - No "No data to display" after pagination
 *   - "Showing X-Y" range advances
 *   - Access Log last-page works
 *
 * Runs in CI as part of Tier 2 E2E. Uses a wide custom date range so seeded
 * data is always visible regardless of when CI runs.
 */
import { test, expect } from '@playwright/test';
import { BASE_URL, MYSQL_CONFIG } from './helpers/env';
import { closeDb, clearStatsTable } from './helpers/setup';
import * as mysql from 'mysql2/promise';

// ─── Seed helpers ───────────────────────────────────────────────

async function seedAll(): Promise<void> {
  const pool = mysql.createPool(MYSQL_CONFIG);
  const baseDt = Math.floor(Date.now() / 1000);
  const placeholders: string[] = [];
  const values: any[] = [];

  // 100 generic pageviews (unique resources → Top Web Pages, Entry/Exit/Bounce)
  for (let i = 0; i < 100; i++) {
    placeholders.push('(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    values.push(
      `/sweep-page-${i}`, baseDt - i, '127.0.0.1', 1,
      'Chrome', 'Windows', 'page', '', '', null,
    );
  }

  // 50 user-tagged pageviews (unique usernames → Recent/Top Users)
  for (let i = 0; i < 50; i++) {
    const user = `sweep-user-${i}`;
    placeholders.push('(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    values.push(
      `/user-sweep-${i}`, baseDt - 100 - i, '127.0.0.1', 1,
      'Chrome', 'Windows', 'page', user, `[user:${user}]`, null,
    );
  }

  // 50 outbound links (unique URLs → Recent/Top Outbound Links)
  for (let i = 0; i < 50; i++) {
    placeholders.push('(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    values.push(
      `/outbound-sweep-${i}`, baseDt - 200 - i, '127.0.0.1', 1,
      'Chrome', 'Windows', 'page', '', '', `https://example.com/sweep-${i}`,
    );
  }

  // 30 posts (content_type=post → Recent/Top Posts)
  for (let i = 0; i < 30; i++) {
    placeholders.push('(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    values.push(
      `/post-sweep-${i}`, baseDt - 300 - i, '127.0.0.1', 1,
      'Chrome', 'Windows', 'post', '', '', null,
    );
  }

  // 30 404 pages → Recent/Top 404s
  for (let i = 0; i < 30; i++) {
    placeholders.push('(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    values.push(
      `[404]/missing-sweep-${i}`, baseDt - 400 - i, '127.0.0.1', 1,
      'Chrome', 'Windows', '404', '', '', null,
    );
  }

  await pool.query(
    `INSERT INTO wp_slim_stats
       (resource, dt, ip, visit_id, browser, platform, content_type, username, notes, outbound_resource)
     VALUES ${placeholders.join(', ')}`,
    values,
  );
  await pool.end();
}

// ─── Test helpers ───────────────────────────────────────────────

/** Wide date range that always includes seeded data */
const DATE_SUFFIX = '&from=2020-01-01&to=2030-12-31&type=custom';

interface PaginatedReport {
  id: string;
  showingText: string;
}

/**
 * Find all reports on the current page that have a next-page arrow,
 * meaning they have more than one page of results.
 */
async function findPaginatedReports(page: any): Promise<PaginatedReport[]> {
  return page.evaluate(() => {
    const results: { id: string; showingText: string }[] = [];
    jQuery('.postbox').each(function () {
      const $box = jQuery(this);
      const id = $box.attr('id') || '';
      const $nextArrow = $box.find('.pagination a.refresh.slimstat-font-angle-right, .pagination a.refresh.slimstat-font-angle-left');
      if ($nextArrow.length > 0) {
        const showingText = $box.find('.pagination').text().trim();
        results.push({ id, showingText });
      }
    });
    return results;
  });
}

/**
 * Click next-page on a specific report and verify it doesn't break.
 */
async function testReportPagination(
  page: any,
  reportId: string,
): Promise<{ passed: boolean; error?: string }> {
  // Get page-1 showing text
  const p1Text = await page.evaluate((id: string) => {
    const m = jQuery(`#${id} .pagination`).text().match(/Showing\s+([\d,]+)\s*-/);
    return m ? m[1] : null;
  }, reportId);

  // Click next page
  await page.locator(`#${reportId} .pagination a.refresh.slimstat-font-angle-right`).first().click();
  await page.waitForTimeout(4000);

  // Check for "No data to display"
  const hasNoData = await page.evaluate((id: string) =>
    jQuery(`#${id} .inside .nodata`).length > 0, reportId);
  if (hasNoData) {
    return { passed: false, error: 'Got "No data to display" on page 2' };
  }

  // Check that "Showing" range advanced
  const p2Start = await page.evaluate((id: string) => {
    const m = jQuery(`#${id} .pagination`).text().match(/Showing\s+([\d,]+)\s*-/);
    return m ? m[1] : null;
  }, reportId);

  if (p1Text && p2Start && parseInt(p2Start.replace(/,/g, '')) <= parseInt(p1Text.replace(/,/g, ''))) {
    return { passed: false, error: `Range didn't advance: page1 started at ${p1Text}, page2 at ${p2Start}` };
  }

  return { passed: true };
}

// ─── Setup / Teardown ───────────────────────────────────────────

test.beforeAll(async () => {
  await clearStatsTable();
  await seedAll();
});

test.afterAll(async () => {
  await clearStatsTable();
  await closeDb();
});

// ─── Tests ──────────────────────────────────────────────────────

test.describe('Pagination sweep — all slimview pages', () => {
  test.setTimeout(120_000);

  for (const view of [1, 2, 3, 4, 5]) {
    test(`slimview${view}: all paginated reports advance without "No data"`, async ({ page }) => {
      const url = `${BASE_URL}/wp-admin/admin.php?page=slimview${view}${DATE_SUFFIX}`;
      await page.goto(url);
      await page.waitForTimeout(3000);

      const reports = await findPaginatedReports(page);

      for (const report of reports) {
        // Navigate fresh each time so start_from resets
        await page.goto(url);
        await page.waitForTimeout(2000);

        const result = await testReportPagination(page, report.id);
        expect(
          result.passed,
          `${report.id} on slimview${view}: ${result.error || 'OK'}`,
        ).toBe(true);
      }
    });
  }

  test('Access Log last-page arrow works', async ({ page }) => {
    const url = `${BASE_URL}/wp-admin/admin.php?page=slimview1${DATE_SUFFIX}`;
    await page.goto(url);
    await page.waitForTimeout(3000);

    // Check if last-page arrow exists on Access Log
    const hasLastPage = await page.evaluate(() =>
      jQuery('#slim_p7_02 .pagination a.refresh.slimstat-font-angle-double-right').length > 0
    );
    if (!hasLastPage) {
      test.skip();
      return;
    }

    await page.locator('#slim_p7_02 .pagination a.refresh.slimstat-font-angle-double-right').first().click();
    await page.waitForTimeout(4000);

    const hasNoData = await page.evaluate(() =>
      jQuery('#slim_p7_02 .inside .nodata').length > 0
    );
    expect(hasNoData, 'Access Log last page should show data').toBe(false);
  });
});
