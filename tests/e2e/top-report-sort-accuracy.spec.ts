/**
 * E2E tests: Top report sort accuracy after split-query merge.
 *
 * Validates that "Top" reports (slim_p1_08, slim_p2_21) display rows in
 * correct descending hit-count order when data spans both historical (past)
 * and live (today) partitions — triggering the Query split-merge path.
 *
 * Seeding strategy: insert rows with KNOWN hit counts split across yesterday
 * and today so that mergeGroupResults() must sum them, and sortMergedResults()
 * must re-sort the merged result.
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';
import { clearStatsTable, closeDb, getPool } from './helpers/setup';

// ─── Helpers ─────────────────────────────────────────────────────

/** Seed N identical pageview rows for a given resource at a base timestamp. */
async function seedResource(resource: string, count: number, baseDt: number): Promise<void> {
  if (count <= 0) return;
  const pool = getPool();
  const placeholders: string[] = [];
  const values: any[] = [];
  for (let i = 0; i < count; i++) {
    placeholders.push('(?, ?, ?, ?, ?, ?, ?)');
    values.push(resource, baseDt + i, '127.0.0.1', 1, 'Chrome', 'Windows', 'post');
  }
  await pool.query(
    `INSERT INTO wp_slim_stats
       (resource, dt, ip, visit_id, browser, platform, content_type)
     VALUES ${placeholders.join(', ')}`,
    values,
  );
}

/** Seed N user pageview rows (with notes LIKE '%user:%') at a base timestamp. */
async function seedUser(username: string, count: number, baseDt: number): Promise<void> {
  if (count <= 0) return;
  const pool = getPool();
  const placeholders: string[] = [];
  const values: any[] = [];
  for (let i = 0; i < count; i++) {
    placeholders.push('(?, ?, ?, ?, ?, ?, ?, ?)');
    values.push(
      `/user-page-${username}-${i}`,
      baseDt + i,
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
}

/**
 * Extract report rows (label + count) from a Slimstat panel.
 * Returns [{label, count}] in displayed order.
 */
async function getReportRows(
  page: any,
  panelId: string,
): Promise<{ label: string; count: number }[]> {
  return page.evaluate((id: string) => {
    const rows: { label: string; count: number }[] = [];
    jQuery(`#${id} .inside p:not(.pagination):not(.loading):not(.nodata)`).each(function () {
      const $el = jQuery(this as HTMLElement);
      // Label: first <a> text, or first text node
      const label =
        $el.find('a').first().text().trim() ||
        $el
          .contents()
          .filter(function () {
            return this.nodeType === 3;
          })
          .first()
          .text()
          .trim();
      // Count: the <span> inside the row
      const countText = $el.find('span').first().text().trim();
      const count = parseInt(countText.replace(/,/g, ''), 10) || 0;
      rows.push({ label, count });
    });
    return rows;
  }, panelId);
}

// ─── Timestamps ──────────────────────────────────────────────────

// "Yesterday" = 24h ago; "today" = recent timestamps.
// This ensures the Query split-cache path is triggered (today vs historical).
const NOW = Math.floor(Date.now() / 1000);
const YESTERDAY = NOW - 86400;

// ─── Setup / Teardown ────────────────────────────────────────────

test.beforeAll(async () => {
  await clearStatsTable();

  await Promise.all([
    // --- Top Web Pages (slim_p1_08) ---
    // Page A: 20 yesterday + 30 today = 50 total (highest)
    seedResource('/top-sort-aaa', 20, YESTERDAY),
    seedResource('/top-sort-aaa', 30, NOW - 500),
    // Page B: 5 yesterday + 25 today = 30 total (middle)
    seedResource('/top-sort-bbb', 5, YESTERDAY + 100),
    seedResource('/top-sort-bbb', 25, NOW - 400),
    // Page C: 10 yesterday + 0 today = 10 total (lowest)
    seedResource('/top-sort-ccc', 10, YESTERDAY + 200),

    // --- Top Users (slim_p2_21) ---
    // user-alpha: 15 yesterday + 25 today = 40 total (highest)
    seedUser('user-alpha', 15, YESTERDAY + 300),
    seedUser('user-alpha', 25, NOW - 300),
    // user-beta: 10 yesterday + 10 today = 20 total (middle)
    seedUser('user-beta', 10, YESTERDAY + 400),
    seedUser('user-beta', 10, NOW - 200),
    // user-gamma: 5 yesterday + 0 today = 5 total (lowest)
    seedUser('user-gamma', 5, YESTERDAY + 500),
  ]);
});

test.afterAll(async () => {
  await clearStatsTable();
  await closeDb();
});

// ─── Tests ───────────────────────────────────────────────────────

test.describe('Top report sort accuracy after split-query merge', () => {
  test('slim_p1_08 (Top Web Pages) rows are sorted by hit count descending', async ({
    page,
  }) => {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`);
    await page.locator('#slim_p1_08').waitFor({ state: 'visible', timeout: 15000 });
    await page.waitForTimeout(3000);

    const rows = await getReportRows(page, 'slim_p1_08');

    // Filter to only our seeded rows (avoid interference from other data)
    const seeded = rows.filter((r) => r.label.includes('/top-sort-'));

    expect(seeded.length).toBeGreaterThanOrEqual(3);

    // Verify descending order
    expect(seeded[0].label).toContain('/top-sort-aaa');
    expect(seeded[0].count).toBe(50);

    expect(seeded[1].label).toContain('/top-sort-bbb');
    expect(seeded[1].count).toBe(30);

    expect(seeded[2].label).toContain('/top-sort-ccc');
    expect(seeded[2].count).toBe(10);
  });

  test('slim_p1_08 rows are monotonically decreasing', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`);
    await page.locator('#slim_p1_08').waitFor({ state: 'visible', timeout: 15000 });
    await page.waitForTimeout(3000);

    const rows = await getReportRows(page, 'slim_p1_08');

    for (let i = 1; i < rows.length; i++) {
      expect(rows[i].count).toBeLessThanOrEqual(rows[i - 1].count);
    }
  });

  test('slim_p2_21 (Top Users) rows are sorted by hit count descending', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview3`);
    await page.locator('#slim_p2_21').waitFor({ state: 'visible', timeout: 15000 });
    await page.waitForTimeout(3000);

    const rows = await getReportRows(page, 'slim_p2_21');

    // Filter to only our seeded users
    const seeded = rows.filter(
      (r) =>
        r.label.includes('user-alpha') ||
        r.label.includes('user-beta') ||
        r.label.includes('user-gamma'),
    );

    expect(seeded.length).toBeGreaterThanOrEqual(3);

    expect(seeded[0].label).toContain('user-alpha');
    expect(seeded[0].count).toBe(40);

    expect(seeded[1].label).toContain('user-beta');
    expect(seeded[1].count).toBe(20);

    expect(seeded[2].label).toContain('user-gamma');
    expect(seeded[2].count).toBe(5);
  });

  test('slim_p2_21 rows are monotonically decreasing', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview3`);
    await page.locator('#slim_p2_21').waitFor({ state: 'visible', timeout: 15000 });
    await page.waitForTimeout(3000);

    const rows = await getReportRows(page, 'slim_p2_21');

    for (let i = 1; i < rows.length; i++) {
      expect(rows[i].count).toBeLessThanOrEqual(rows[i - 1].count);
    }
  });
});
