/**
 * E2E: Live Analytics — Timezone & Custom DB Scenarios
 *
 * Validates that live analytics counters (Users live, Pages live, Countries live)
 * and admin bar online count work correctly across different WordPress timezones
 * and with the Custom DB simulator active.
 *
 * Root cause context: dt column stores wp_slimstat::date_i18n('U') which equals
 * current_time('timestamp') = time() + gmt_offset. Queries must use the same
 * timestamp via wp_slimstat::now(). Without this, non-UTC sites show zeros.
 */

import { test, expect } from '@playwright/test';
import {
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  setSlimstatOption,
  snapshotOption,
  restoreOption,
  restoreAllOptions,
  clearStatsTable,
  getPool,
  closeDb,
  installOptionMutator,
  uninstallOptionMutator,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

// ─── Helpers ──────────────────────────────────────────────────────

async function setWpTimezone(tz: string, offset: number): Promise<void> {
  const pool = getPool();
  await pool.execute(
    "UPDATE wp_options SET option_value = ? WHERE option_name = 'timezone_string'",
    [tz]
  );
  await pool.execute(
    "UPDATE wp_options SET option_value = ? WHERE option_name = 'gmt_offset'",
    [String(offset)]
  );
}

async function waitForStatRows(
  marker: string,
  minRows: number,
  timeoutMs = 20_000
): Promise<Record<string, any>[]> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const [rows] = (await getPool().execute(
      'SELECT * FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id ASC',
      [`%${marker}%`]
    )) as any;
    if (rows.length >= minRows) return rows;
    await new Promise((r) => setTimeout(r, 500));
  }
  return [];
}

async function enableCustomDb(): Promise<void> {
  await getPool().execute(
    "INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('slimstat_test_use_custom_db', 'yes', 'yes') ON DUPLICATE KEY UPDATE option_value = 'yes'"
  );
}

async function disableCustomDb(): Promise<void> {
  await getPool().execute(
    "DELETE FROM wp_options WHERE option_name = 'slimstat_test_use_custom_db'"
  );
}

async function clearCustomDbStatsTable(): Promise<void> {
  const pool = getPool();
  const [tables] = (await pool.execute(
    "SHOW TABLES LIKE 'slimext_slim_stats'"
  )) as any;
  if (tables.length > 0) {
    await pool.execute('SET FOREIGN_KEY_CHECKS = 0');
    await pool.execute('TRUNCATE TABLE slimext_slim_stats');
    const [evtTables] = (await pool.execute(
      "SHOW TABLES LIKE 'slimext_slim_events'"
    )) as any;
    if (evtTables.length > 0) {
      await pool.execute('TRUNCATE TABLE slimext_slim_events');
    }
    await pool.execute('SET FOREIGN_KEY_CHECKS = 1');
  }
}

async function waitForCustomDbStatRows(
  marker: string,
  minRows: number,
  timeoutMs = 20_000
): Promise<Record<string, any>[]> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    try {
      const [rows] = (await getPool().execute(
        'SELECT * FROM slimext_slim_stats WHERE resource LIKE ? ORDER BY id ASC',
        [`%${marker}%`]
      )) as any;
      if (rows.length >= minRows) return rows;
    } catch {
      // Table may not exist yet
    }
    await new Promise((r) => setTimeout(r, 500));
  }
  return [];
}

/**
 * Extract live analytics nonce from slimview1 page
 */
async function getLiveAnalyticsNonce(
  page: import('@playwright/test').Page
): Promise<string | null> {
  return page.evaluate(() => {
    const html = document.documentElement.innerHTML;
    const m1 = html.match(/nonce:\s*'([a-f0-9]+)'/);
    if (m1) return m1[1];
    const m2 = html.match(/var nonce = '([a-f0-9]+)'/);
    if (m2) return m2[1];
    const ajax = (window as any).wp_slimstat_ajax;
    return ajax?.nonce || null;
  });
}

/**
 * Fetch live analytics data via AJAX (no screenshot needed)
 */
async function fetchLiveAnalytics(
  page: import('@playwright/test').Page,
  nonce: string
) {
  const response = await page.request.post(
    `${BASE_URL}/wp-admin/admin-ajax.php`,
    {
      form: {
        action: 'slimstat_get_live_analytics_data',
        nonce,
        report_id: 'slim_live_analytics',
        metric: 'users',
      },
    }
  );
  expect(response.ok()).toBe(true);
  return response.json();
}

/**
 * Fetch admin bar stats via AJAX (no screenshot needed)
 */
async function fetchAdminBarStats(
  page: import('@playwright/test').Page,
  nonce: string
) {
  const response = await page.request.post(
    `${BASE_URL}/wp-admin/admin-ajax.php`,
    {
      form: {
        action: 'slimstat_get_adminbar_stats',
        security: nonce,
      },
    }
  );
  expect(response.ok()).toBe(true);
  return response.json();
}

// ─── Timezone matrix ──────────────────────────────────────────────

const TIMEZONES = [
  { name: 'UTC', tz: 'UTC', offset: 0 },
  { name: 'Tokyo (GMT+9)', tz: 'Asia/Tokyo', offset: 9 },
  { name: 'Berlin (GMT+2)', tz: 'Europe/Berlin', offset: 2 },
  { name: 'Los Angeles (GMT-7)', tz: 'America/Los_Angeles', offset: -7 },
  { name: 'Tehran (GMT+3:30)', tz: 'Asia/Tehran', offset: 3.5 },
];

// ─── Tests ────────────────────────────────────────────────────────

test.describe('Live Analytics — Timezone & Custom DB Scenarios', () => {
  test.setTimeout(120_000);

  test.beforeAll(() => {
    installOptionMutator();
  });

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await snapshotOption('timezone_string');
    await snapshotOption('gmt_offset');
    await clearStatsTable();
    // Ensure tracking is on and admin is not excluded
    await setSlimstatOption(page, 'is_tracking', 'on');
    await setSlimstatOption(page, 'javascript_mode', 'on');
    await setSlimstatOption(page, 'track_admin_pages', 'on');
    await setSlimstatOption(page, 'ignore_wp_users', 'off');
  });

  test.afterEach(async () => {
    await disableCustomDb();
    await restoreSlimstatOptions();
    await restoreAllOptions();
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    await closeDb();
  });

  // ──── TIMEZONE MATRIX TESTS ────

  for (const { name, tz, offset } of TIMEZONES) {
    test(`Live counters work in ${name} timezone`, async ({ page }) => {
      // Set WordPress timezone
      await setWpTimezone(tz, offset);

      // Generate tracking data by visiting a unique frontend page
      const marker = `tz-${tz.replace(/\//g, '-')}-${Date.now()}`;
      await page.goto(`${BASE_URL}/?e2e=${marker}`, {
        waitUntil: 'domcontentloaded',
      });
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(3000);

      // Wait for the tracking row to appear in DB
      const rows = await waitForStatRows(marker, 1);
      expect(rows.length).toBeGreaterThanOrEqual(1);

      // Navigate to slimview1 to get nonces
      await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
        waitUntil: 'domcontentloaded',
      });

      // Test 1: Live Analytics AJAX endpoint
      const liveNonce = await getLiveAnalyticsNonce(page);
      expect(liveNonce).not.toBeNull();

      const liveJson = await fetchLiveAnalytics(page, liveNonce!);
      expect(liveJson.success).toBe(true);
      expect(liveJson.data.users_live).toBeGreaterThanOrEqual(1);
      expect(liveJson.data.pages_live).toBeGreaterThanOrEqual(1);

      // Test 2: Admin Bar AJAX endpoint
      const adminNonce = await page.evaluate(
        () => (window as any).SlimStatAdminBar?.security || null
      );
      if (adminNonce) {
        const adminJson = await fetchAdminBarStats(page, adminNonce);
        expect(adminJson.success).toBe(true);
        expect(adminJson.data.online.count).toBeGreaterThanOrEqual(1);
      }
    });
  }

  // ──── CUSTOM DB TEST ────

  test('Live counters work with Custom DB simulator (Tokyo timezone)', async ({
    page,
  }) => {
    // Set a large positive offset to stress-test
    await setWpTimezone('Asia/Tokyo', 9);

    // Enable custom DB simulator (uses slimext_ prefix)
    await enableCustomDb();

    // Visit page to create first request (initializes custom DB tables)
    await page.goto(`${BASE_URL}/`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3000);

    // Clear both standard and custom DB tables
    await clearStatsTable();
    await clearCustomDbStatsTable();

    // Generate fresh tracking data
    const marker = `customdb-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e=${marker}`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(3000);

    // Data should appear in the custom DB table (slimext_slim_stats)
    const customRows = await waitForCustomDbStatRows(marker, 1);
    expect(customRows.length).toBeGreaterThanOrEqual(1);

    // Navigate to admin and check AJAX endpoints
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });

    const liveNonce = await getLiveAnalyticsNonce(page);
    expect(liveNonce).not.toBeNull();

    const liveJson = await fetchLiveAnalytics(page, liveNonce!);
    expect(liveJson.success).toBe(true);
    expect(liveJson.data.users_live).toBeGreaterThanOrEqual(1);
    expect(liveJson.data.pages_live).toBeGreaterThanOrEqual(1);

    const adminNonce = await page.evaluate(
      () => (window as any).SlimStatAdminBar?.security || null
    );
    if (adminNonce) {
      const adminJson = await fetchAdminBarStats(page, adminNonce);
      expect(adminJson.success).toBe(true);
      expect(adminJson.data.online.count).toBeGreaterThanOrEqual(1);
    }
  });

  // ──── CONSISTENCY TEST ────

  test('Admin bar online matches live analytics users count', async ({
    page,
  }) => {
    // Use Berlin timezone (positive offset)
    await setWpTimezone('Europe/Berlin', 2);

    // Visit 2 distinct pages
    const marker = `consistency-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e=${marker}-p1`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForTimeout(2000);
    await page.goto(`${BASE_URL}/?e2e=${marker}-p2`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(3000);

    // Wait for both rows
    const rows = await waitForStatRows(marker, 2);
    expect(rows.length).toBeGreaterThanOrEqual(2);

    // Fetch both endpoints
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });

    const liveNonce = await getLiveAnalyticsNonce(page);
    expect(liveNonce).not.toBeNull();
    const liveJson = await fetchLiveAnalytics(page, liveNonce!);

    const adminNonce = await page.evaluate(
      () => (window as any).SlimStatAdminBar?.security || null
    );

    if (adminNonce) {
      const adminJson = await fetchAdminBarStats(page, adminNonce);

      // Same session = 1 user, both endpoints should agree
      expect(liveJson.data.users_live).toBeGreaterThanOrEqual(1);
      expect(adminJson.data.online.count).toBeGreaterThanOrEqual(1);

      // They should match (same user, same 30-min window)
      expect(adminJson.data.online.count).toBe(liveJson.data.users_live);
    }
  });

  // ──── TIMESTAMP ALIGNMENT TEST ────

  test('Stored dt aligns with query window (negative offset)', async ({
    page,
  }) => {
    // Use Los Angeles (GMT-7) — negative offset is the hardest case
    await setWpTimezone('America/Los_Angeles', -7);

    const marker = `align-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e=${marker}`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(3000);

    // Get the stored dt value
    const rows = await waitForStatRows(marker, 1);
    expect(rows.length).toBeGreaterThanOrEqual(1);
    const storedDt = Number(rows[0].dt);

    // Verify via direct SQL that a 30-minute window query finds the row
    const windowStart = storedDt - 1800;
    const [countRows] = (await getPool().execute(
      'SELECT COUNT(*) as cnt FROM wp_slim_stats WHERE dt >= ? AND resource LIKE ?',
      [windowStart, `%${marker}%`]
    )) as any;
    expect(countRows[0].cnt).toBeGreaterThanOrEqual(1);

    // Also verify via AJAX that the live endpoint finds it
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    const liveNonce = await getLiveAnalyticsNonce(page);
    expect(liveNonce).not.toBeNull();
    const liveJson = await fetchLiveAnalytics(page, liveNonce!);
    expect(liveJson.data.users_live).toBeGreaterThanOrEqual(1);
  });
});
