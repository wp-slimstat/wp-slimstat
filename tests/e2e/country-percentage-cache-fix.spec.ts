/**
 * E2E: Country percentage cache fix — regression tests for #270
 *
 * Validates the fix for Query::getVar() cache asymmetry with getAll().
 * Previously, getVar() cached the full date range (including today) while
 * getAll() split into cached historical + fresh live data, causing
 * $pageviews to be stale while per-country counts were fresh → percentages
 * could exceed 100% in the Audience Location map (slim_p6_01).
 *
 * Fix: getVar()/getRow()/getCol() now skip cache when the date range
 * includes today, consistent with getAll() behavior.
 *
 * Source: https://github.com/wp-slimstat/wp-slimstat/issues/270
 */
import { test, expect, Page } from '@playwright/test';
import {
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  getPool,
  closeDb,
  installMuPluginByName,
  uninstallMuPluginByName,
} from './helpers/setup';
import { clearTestData } from './helpers/chart';
import { BASE_URL } from './helpers/env';

// ─── Test-specific DB helpers ────────────────────────────────────────────────

const TEST_UA = 'country-pct-e2e';

/**
 * Insert rows with a specific country code at a given timestamp.
 * Rows are spaced 1 second apart to avoid exact duplicates.
 */
async function insertCountryRows(
  timestamp: number,
  count: number,
  country: string,
  browser = 'Chrome',
): Promise<void> {
  const pool = getPool();
  for (let i = 0; i < count; i++) {
    await pool.execute(
      `INSERT INTO wp_slim_stats
       (dt, ip, resource, browser, browser_version, platform, language, country, visit_id, user_agent)
       VALUES (?, '127.0.0.1', ?, ?, '1', 'test', 'en', ?, 1, ?)`,
      [timestamp + i, `/country-pct-${country}-${i}`, browser, country, TEST_UA],
    );
  }
}

/** Clear all wp_slimstat_cache transients to prevent stale data */
async function clearTransients(): Promise<void> {
  await getPool().execute(
    "DELETE FROM wp_options WHERE option_name LIKE '_transient_wp_slimstat_%' OR option_name LIKE '_transient_timeout_wp_slimstat_%'",
  );
}

/** Count transients that were SET (evidence of caching) */
async function countCacheTransients(): Promise<number> {
  const [rows] = (await getPool().execute(
    "SELECT COUNT(*) as cnt FROM wp_options WHERE option_name LIKE '_transient_wp_slimstat_cache_%'",
  )) as any;
  return parseInt(rows[0].cnt, 10);
}

/** Extract percentage values from the Audience Location map's Top Countries */
async function extractMapPercentages(page: Page): Promise<{ name: string; pct: number }[]> {
  return page.$$eval('.country-bar', (bars) =>
    bars.map((bar) => {
      const name = bar.querySelector('strong')?.textContent?.trim() || '';
      const pctText = bar.querySelector('span')?.textContent?.trim() || '0';
      return { name, pct: parseFloat(pctText.replace('%', '')) };
    }),
  );
}

/** Extract percentage values from the Top Countries list report (slim_p1_13) */
async function extractListPercentages(page: Page): Promise<{ name: string; pct: number }[]> {
  // slim_p1_13 uses the standard report format with slimstat-tooltip-trigger paragraphs
  return page.$$eval('#slim_p1_13 p.slimstat-tooltip-trigger', (items) =>
    items.map((item) => {
      const text = item.textContent || '';
      const pctMatch = text.match(/([\d.]+)%/);
      // Country name is in the filter link text
      const nameEl = item.querySelector('a.slimstat-filter-link');
      const name = nameEl?.textContent?.trim() || '';
      return { name, pct: pctMatch ? parseFloat(pctMatch[1]) : 0 };
    }),
  );
}

/** Read the sidebar Pageviews metric from slimview1 */
async function readSidebarPageviews(page: Page): Promise<number> {
  const text = await page.$eval(
    '#slim_p2_01 p:first-child span',
    (el) => el.textContent?.trim() || '0',
  );
  return parseInt(text.replace(/,/g, ''), 10);
}

// ─── Test lifecycle ──────────────────────────────────────────────────────────

test.describe('Country percentage cache fix (#270)', () => {
  test.beforeEach(async () => {
    await clearTestData();
    await clearTransients();
  });

  test.afterAll(async () => {
    await clearTestData();
    await closeDb();
  });

  // ── Scenario 1: Basic regression — percentages never exceed 100% ───────

  test('country percentages never exceed 100% on Audience Location map', async ({ page }) => {
    const now = Math.floor(Date.now() / 1000);
    const oneHourAgo = now - 3600;

    await insertCountryRows(oneHourAgo, 10, 'gb');
    await insertCountryRows(oneHourAgo + 100, 5, 'de');
    await insertCountryRows(oneHourAgo + 200, 3, 'us');

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'networkidle',
    });

    // Wait for map report to render
    await page.waitForSelector('.country-bar', { timeout: 15000 });

    const percentages = await extractMapPercentages(page);
    expect(percentages.length).toBeGreaterThan(0);

    for (const { name, pct } of percentages) {
      expect(pct, `${name} percentage should be ≤ 100%`).toBeLessThanOrEqual(100);
      expect(pct, `${name} percentage should be > 0`).toBeGreaterThan(0);
    }

    const totalPct = percentages.reduce((sum, { pct }) => sum + pct, 0);
    expect(totalPct, 'Sum of all percentages should be ≤ 100%').toBeLessThanOrEqual(100.01);
  });

  // ── Scenario 2: Percentages stay correct after new data (cache freshness) ──

  test('percentages remain correct after inserting new data', async ({ page }) => {
    const now = Math.floor(Date.now() / 1000);
    const twoHoursAgo = now - 7200;

    // Phase 1: Insert initial data
    await insertCountryRows(twoHoursAgo, 5, 'gb');
    await insertCountryRows(twoHoursAgo + 50, 5, 'de');

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'networkidle',
    });
    await page.waitForSelector('.country-bar', { timeout: 15000 });

    const initialPcts = await extractMapPercentages(page);
    const ukInitial = initialPcts.find((c) => c.name.includes('United Kingdom'));
    const deInitial = initialPcts.find((c) => c.name.includes('Germany'));

    // Both should be ~50%
    if (ukInitial) expect(ukInitial.pct).toBeGreaterThanOrEqual(40);
    if (deInitial) expect(deInitial.pct).toBeGreaterThanOrEqual(40);

    // Phase 2: Insert more UK data (simulates new traffic)
    const fiveMinAgo = now - 300;
    await insertCountryRows(fiveMinAgo, 10, 'gb');
    await clearTransients(); // ensure fresh queries

    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForSelector('.country-bar', { timeout: 15000 });

    const updatedPcts = await extractMapPercentages(page);

    for (const { name, pct } of updatedPcts) {
      expect(pct, `${name} percentage must NOT exceed 100% after new data`).toBeLessThanOrEqual(100);
    }

    // UK should now be ~75% (15/20), NOT >100%
    const ukUpdated = updatedPcts.find((c) => c.name.includes('United Kingdom'));
    if (ukUpdated) {
      expect(ukUpdated.pct).toBeLessThanOrEqual(100);
      expect(ukUpdated.pct).toBeGreaterThanOrEqual(60); // ~75%
    }
  });

  // ── Scenario 3: Historical-only ranges still use cache ─────────────────

  test('historical-only date ranges still use transient cache', async ({ page }) => {
    const now = Math.floor(Date.now() / 1000);
    const threeDaysAgo = now - 3 * 86400;

    await insertCountryRows(threeDaysAgo, 10, 'gb');
    await clearTransients();

    // Navigate with a past-only date filter (3 days ago, interval = -1 day)
    const dateThreeDaysAgo = new Date((threeDaysAgo) * 1000);
    const day = dateThreeDaysAgo.getUTCDate();
    const month = dateThreeDaysAgo.getUTCMonth() + 1;
    const year = dateThreeDaysAgo.getUTCFullYear();

    await page.goto(
      `${BASE_URL}/wp-admin/admin.php?page=slimview1&day=${day}&month=${month}&year=${year}&interval=-1`,
      { waitUntil: 'networkidle' },
    );

    // Wait for page to fully load
    await page.waitForTimeout(2000);

    // Check that transients were created (caching happened)
    const transientCount = await countCacheTransients();
    expect(transientCount, 'Historical queries should create cache transients').toBeGreaterThan(0);
  });

  // ── Scenario 4: Map vs List report consistency ─────────────────────────

  test('map percentages match list report percentages', async ({ page }) => {
    const now = Math.floor(Date.now() / 1000);
    const oneHourAgo = now - 3600;

    // Known distribution: 10 UK, 7 DE, 3 US = 20 total
    await insertCountryRows(oneHourAgo, 10, 'gb');
    await insertCountryRows(oneHourAgo + 100, 7, 'de');
    await insertCountryRows(oneHourAgo + 200, 3, 'us');

    // Get map percentages from slimview1
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'networkidle',
    });
    await page.waitForSelector('.country-bar', { timeout: 15000 });
    const mapPcts = await extractMapPercentages(page);

    // Get list percentages from slimview3 (which has slim_p1_13)
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview3`, {
      waitUntil: 'networkidle',
    });

    // Wait for Top Countries report
    await page.waitForSelector('#slim_p1_13', { timeout: 15000 });
    const listPcts = await extractListPercentages(page);

    // Compare: both should show similar percentages for UK
    if (mapPcts.length > 0 && listPcts.length > 0) {
      const mapUk = mapPcts.find((c) => c.name.includes('United Kingdom'));
      const listUk = listPcts.find((c) => c.name.includes('United Kingdom'));

      if (mapUk && listUk) {
        // Allow 1% tolerance for rounding differences
        expect(Math.abs(mapUk.pct - listUk.pct)).toBeLessThanOrEqual(1);
      }
    }
  });

  // ── Scenario 5: Other "top" reports unaffected ─────────────────────────

  test('browser percentages in Top Browsers are correct', async ({ page }) => {
    const now = Math.floor(Date.now() / 1000);
    const oneHourAgo = now - 3600;

    // 6 Chrome + 4 Firefox = 10 total
    await insertCountryRows(oneHourAgo, 6, 'gb', 'Chrome');
    await insertCountryRows(oneHourAgo + 100, 4, 'gb', 'Firefox');

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview3`, {
      waitUntil: 'networkidle',
    });

    // Find Top Browsers report (slim_p3_01 or similar)
    const browserPcts = await page.$$eval(
      '#slim_p3_01 p.slimstat-tooltip-trigger, #slim_p3_02 p.slimstat-tooltip-trigger',
      (items) =>
        items.map((item) => {
          const text = item.textContent || '';
          const pctMatch = text.match(/([\d.]+)%/);
          return pctMatch ? parseFloat(pctMatch[1]) : 0;
        }),
    );

    for (const pct of browserPcts) {
      expect(pct, 'No browser percentage should exceed 100%').toBeLessThanOrEqual(100);
    }
  });

  // ── Scenario 6: Dashboard sidebar pageviews matches data ───────────────

  test('sidebar pageviews count is consistent with country data', async ({ page }) => {
    const now = Math.floor(Date.now() / 1000);
    const oneHourAgo = now - 3600;

    await insertCountryRows(oneHourAgo, 10, 'gb');
    await insertCountryRows(oneHourAgo + 100, 5, 'de');

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'networkidle',
    });
    await page.waitForSelector('.country-bar', { timeout: 15000 });

    const sidebarPv = await readSidebarPageviews(page);
    const mapPcts = await extractMapPercentages(page);
    const totalPct = mapPcts.reduce((sum, { pct }) => sum + pct, 0);

    // Sidebar pageviews should be ≥ 15 (our inserted rows)
    expect(sidebarPv).toBeGreaterThanOrEqual(15);

    // Total percentage should be ≤ 100% (sidebar = total, map = visible top N)
    expect(totalPct).toBeLessThanOrEqual(100.01);
  });

  // ── Scenario 7: Async-loaded reports ───────────────────────────────────

  test('async_load mode shows correct percentages', async ({ page }) => {
    await snapshotSlimstatOptions();

    try {
      await setSlimstatOption(page, 'async_load', 'on');

      const now = Math.floor(Date.now() / 1000);
      const oneHourAgo = now - 3600;

      await insertCountryRows(oneHourAgo, 8, 'gb');
      await insertCountryRows(oneHourAgo + 100, 4, 'de');
      await insertCountryRows(oneHourAgo + 200, 2, 'us');

      await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
        waitUntil: 'networkidle',
      });

      // Wait for AJAX-loaded map (async reports load after page)
      await page.waitForSelector('.country-bar', { timeout: 20000 });

      const percentages = await extractMapPercentages(page);

      for (const { name, pct } of percentages) {
        expect(pct, `${name} (async) percentage should be ≤ 100%`).toBeLessThanOrEqual(100);
      }
    } finally {
      await restoreSlimstatOptions();
    }
  });

  // ── Scenario 8: Custom database prefix ─────────────────────────────────

  test('custom database prefix shows correct percentages', async ({ page }) => {
    installMuPluginByName('custom-db-simulator-mu-plugin.php');

    try {
      // Activate custom DB simulator
      await getPool().execute(
        "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('slimstat_test_use_custom_db', 'yes', 'yes') ON DUPLICATE KEY UPDATE option_value = 'yes'",
      );

      const now = Math.floor(Date.now() / 1000);
      const oneHourAgo = now - 3600;

      await insertCountryRows(oneHourAgo, 8, 'gb');
      await insertCountryRows(oneHourAgo + 100, 4, 'de');
      await insertCountryRows(oneHourAgo + 200, 2, 'us');

      await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
        waitUntil: 'networkidle',
      });

      await page.waitForSelector('.country-bar', { timeout: 15000 });

      const percentages = await extractMapPercentages(page);

      for (const { name, pct } of percentages) {
        expect(pct, `${name} (customDB) percentage should be ≤ 100%`).toBeLessThanOrEqual(100);
      }
    } finally {
      await getPool().execute(
        "DELETE FROM wp_options WHERE option_name = 'slimstat_test_use_custom_db'",
      );
      uninstallMuPluginByName('custom-db-simulator-mu-plugin.php');
    }
  });
});
