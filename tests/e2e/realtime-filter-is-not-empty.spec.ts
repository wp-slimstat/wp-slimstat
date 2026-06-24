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

    // Modification 8: the JS form-builder (add_url_filters_to_form) rebuilds the hidden
    // filter inputs on every in-session navigation (pagination, date change, filter-link).
    // Before the fix it treated a single-token value (is_not_empty) as "remove this filter"
    // and dropped it. Invoke it directly with the current URL and assert the value-less
    // filter's hidden input survives the rebuild.
    const survived = await page.evaluate(() => {
      // @ts-expect-error SlimStatAdmin is a global defined by admin.js
      SlimStatAdmin.add_url_filters_to_form(window.location.href);
      const input = document.querySelector(
        '#slimstat-filters-form input[name="fs[email]"]',
      ) as HTMLInputElement | null;
      return input ? input.value : null;
    });
    expect(survived, 'value-less filter input must survive the form rebuild').not.toBeNull();
    expect(String(survived)).toContain('is_not_empty');
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
