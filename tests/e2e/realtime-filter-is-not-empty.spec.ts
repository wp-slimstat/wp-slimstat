/**
 * E2E regression tests for #305 — is_empty / is_not_empty filters.
 *
 * Before the fix, value-less operators were dropped by parse_filters() so the
 * Access Log returned every row regardless of the filter chip, and in-session
 * navigation stripped the filter from the URL entirely.
 *
 * Strategy: seed two pageviews (one with a non-empty email, one without),
 * navigate the Real-time tab with fs[email]=is_not_empty, and assert only the
 * email-bearing row's resource marker appears. The keystone test re-navigates
 * in-session (date-range change) to prove the filter survives modifications 5-8.
 */
import { test, expect } from '@playwright/test';
import {
  clearStatsTable,
  getPool,
  closeDb,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

const NOW = Math.floor(Date.now() / 1000);
const MARKER = `is-not-empty-${Date.now()}`;

async function seedRow(overrides: Record<string, string | number | null>): Promise<void> {
  const defaults: Record<string, string | number | null> = {
    dt: NOW,
    ip: '127.0.0.1',
    resource: '/test-page',
    browser: 'TestBrowser',
    browser_version: '1',
    platform: 'TestOS',
    language: 'en-us',
    visit_id: 1,
    user_agent: 'realtime-filter-e2e',
  };
  const row = { ...defaults, ...overrides };
  const cols = Object.keys(row);
  const placeholders = cols.map(() => '?').join(', ');
  await getPool().execute(
    `INSERT INTO wp_slim_stats (${cols.join(', ')}) VALUES (${placeholders})`,
    Object.values(row),
  );
}

async function clearTestData(): Promise<void> {
  await clearStatsTable();
  await getPool().execute(
    "DELETE FROM wp_options WHERE option_name LIKE '_transient_slimstat_%' OR option_name LIKE '_transient_timeout_slimstat_%'",
  );
}

async function seedMixed(): Promise<void> {
  // 3 anonymous (NULL email) + 1 member (email populated)
  for (let i = 0; i < 3; i++) {
    await seedRow({ resource: `/anon-${MARKER}-${i}`, ip: `10.0.0.${i + 1}`, email: null, dt: NOW - i });
  }
  await seedRow({ resource: `/member-${MARKER}`, ip: '10.0.1.1', email: 'alice@example.com', username: 'alice', dt: NOW - 10 });
}

test.describe('Real-time filter — is_empty / is_not_empty (#305)', () => {
  test.setTimeout(60_000);

  test.beforeEach(async () => {
    await snapshotSlimstatOptions();
    await clearTestData();
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    await clearTestData();
    await closeDb();
  });

  test('is_not_empty shows only rows with a non-empty email', async ({ page }) => {
    await seedMixed();

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1&fs%5Bemail%5D=is_not_empty+`, {
      waitUntil: 'networkidle',
    });

    const html = await page.content();
    expect(html).toContain(`member-${MARKER}`);
    expect(html).not.toContain(`anon-${MARKER}-0`);
    expect(html).not.toContain(`anon-${MARKER}-1`);
    expect(html).not.toContain(`anon-${MARKER}-2`);
  });

  test('is_empty shows only rows with an empty email (symmetric)', async ({ page }) => {
    await seedMixed();

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1&fs%5Bemail%5D=is_empty+`, {
      waitUntil: 'networkidle',
    });

    const html = await page.content();
    expect(html).toContain(`anon-${MARKER}-0`);
    expect(html).not.toContain(`member-${MARKER}`);
  });

  test('is_not_empty survives in-session navigation (keystone for modifications 5-8)', async ({ page }) => {
    await seedMixed();

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1&fs%5Bemail%5D=is_not_empty+`, {
      waitUntil: 'networkidle',
    });
    expect(await page.content()).toContain(`member-${MARKER}`);

    // In-session navigation that re-runs the JS filter form-builder: open the
    // date-range dropdown and pick a preset. Before the fix this stripped the
    // value-less filter from the rebuilt form.
    await page.click('#slimstat-date-filters > a');
    await page.click('text=Last 28 days');
    await page.waitForLoadState('networkidle');

    const url = page.url();
    expect(url).toContain('fs%5Bemail%5D=is_not_empty');
    const html = await page.content();
    expect(html).toContain(`member-${MARKER}`);
    expect(html).not.toContain(`anon-${MARKER}-0`);
  });

  test('switching operator to is_not_empty clears a stale typed value (modification 9)', async ({ page }) => {
    await seedRow({ resource: `/member-${MARKER}`, email: 'alice@example.com' });

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, { waitUntil: 'networkidle' });

    await page.selectOption('#slimstat-filter-name', 'email');
    await page.selectOption('#slimstat-filter-operator', 'equals');
    await page.fill('#slimstat-filter-value', 'stale_garbage_from_ui');

    await page.selectOption('#slimstat-filter-operator', 'is_not_empty');

    await expect(page.locator('#slimstat-filter-value')).toHaveValue('');
    await expect(page.locator('#slimstat-filter-value')).toHaveAttribute('readonly', /.*/);
  });

  test('clicking (x) on a value-bearing filter still removes it (62f0434b intent preserved)', async ({ page }) => {
    await seedRow({ resource: `/firefox-${MARKER}`, browser: 'Firefox' });

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1&fs%5Bbrowser%5D=contains+Fire`, {
      waitUntil: 'networkidle',
    });

    const cancel = page.locator('#slimstat-current-filters .slimstat-font-cancel').first();
    await expect(cancel).toBeVisible();
    await cancel.click();
    await page.waitForLoadState('networkidle');

    expect(page.url()).not.toContain('fs%5Bbrowser%5D');
  });
});
