/**
 * E2E: Filter dropdown correctness for multi-value columns
 *
 * Three columns store multiple values in a single DB field:
 *   - outbound_resource: URLs separated by ';;;'
 *   - notes: bracket-separated tags like '[tag1][tag2]'
 *   - category: comma-separated term IDs like '1,5,12'
 *
 * The filter dropdown (AJAX: slimstat_get_filter_options) should split these
 * into individual values. The filter operator should auto-upgrade 'equals'
 * to 'contains' (or 'includes_in_set' for category) so matches actually work.
 */
import { test, expect, Page } from '@playwright/test';
import {
  clearStatsTable,
  getPool,
  closeDb,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

// ─── Helpers ──────────────────────────────────────────────────────────────────

const NOW = Math.floor(Date.now() / 1000);

async function seedRow(overrides: Record<string, string | number>): Promise<void> {
  const defaults: Record<string, string | number> = {
    dt: NOW,
    ip: '127.0.0.1',
    resource: '/test-page',
    browser: 'TestBrowser',
    browser_version: '1',
    platform: 'TestOS',
    language: 'en-us',
    visit_id: 1,
    user_agent: 'filter-multivalue-e2e',
  };
  const row = { ...defaults, ...overrides };
  const cols = Object.keys(row);
  const placeholders = cols.map(() => '?').join(', ');
  const sql = `INSERT INTO wp_slim_stats (${cols.join(', ')}) VALUES (${placeholders})`;
  await getPool().execute(sql, Object.values(row));
}

async function clearTestData(): Promise<void> {
  await clearStatsTable();
  // Clear transients that might cache filter results
  await getPool().execute(
    "DELETE FROM wp_options WHERE option_name LIKE '_transient_slimstat_%' OR option_name LIKE '_transient_timeout_slimstat_%'"
  );
}

/**
 * Get a nonce for the given action via the nonce-helper MU plugin.
 */
async function getNonce(page: Page, action: string): Promise<string> {
  const res = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
    form: { action: 'test_create_nonce', nonce_action: action },
  });
  const json = await res.json();
  return json.data.nonce;
}

/**
 * Call the filter options AJAX endpoint and return the parsed response.
 */
async function getFilterOptions(
  page: Page,
  dimension: string,
  nonce: string
): Promise<{ success: boolean; data: Array<{ value: string; label?: string }> }> {
  const res = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
    form: {
      action: 'slimstat_get_filter_options',
      security: nonce,
      dimension,
      time_range_type: 'last_28_days',
    },
  });
  return res.json();
}

/**
 * Extract option values from the AJAX response.
 * Non-icon dimensions return plain strings; icon dimensions return {value, label, icon}.
 */
function extractValues(
  response: { success: boolean; data: Array<string | { value: string }> }
): string[] {
  if (!response.success || !Array.isArray(response.data)) return [];
  return response.data.map((item) =>
    typeof item === 'string' ? item : item.value
  );
}

// ─── Setup / Teardown ─────────────────────────────────────────────────────────

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

// ─── Dropdown Splitting Tests ─────────────────────────────────────────────────

test.describe('Filter dropdown: multi-value column splitting', () => {
  test('outbound_resource dropdown shows individual URLs', async ({ page }) => {
    await seedRow({
      outbound_resource: 'https://alpha.com;;;https://beta.com;;;https://gamma.com',
    });

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`, {
      waitUntil: 'domcontentloaded',
    });
    const nonce = await getNonce(page, 'meta-box-order');
    const response = await getFilterOptions(page, 'outbound_resource', nonce);
    const values = extractValues(response);

    expect(values).toContain('https://alpha.com');
    expect(values).toContain('https://beta.com');
    expect(values).toContain('https://gamma.com');
    // No raw concatenated string should appear
    for (const v of values) {
      expect(v).not.toContain(';;;');
    }
  });

  test('notes dropdown shows individual tags', async ({ page }) => {
    await seedRow({
      notes: '[user:999][spam:yes][new:yes]',
    });

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`, {
      waitUntil: 'domcontentloaded',
    });
    const nonce = await getNonce(page, 'meta-box-order');
    const response = await getFilterOptions(page, 'notes', nonce);
    const values = extractValues(response);

    expect(values).toContain('user:999');
    expect(values).toContain('spam:yes');
    expect(values).toContain('new:yes');
    // No brackets should appear in values
    for (const v of values) {
      expect(v).not.toContain('[');
      expect(v).not.toContain(']');
    }
  });

  test('category dropdown shows individual IDs', async ({ page }) => {
    await seedRow({
      category: '1,5,12',
    });

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`, {
      waitUntil: 'domcontentloaded',
    });
    const nonce = await getNonce(page, 'meta-box-order');
    const response = await getFilterOptions(page, 'category', nonce);
    const values = extractValues(response);

    expect(values).toContain('1');
    expect(values).toContain('5');
    expect(values).toContain('12');
    // Should not contain the raw comma-separated string
    expect(values).not.toContain('1,5,12');
  });

  test('split values are deduplicated', async ({ page }) => {
    // Three rows all containing https://alpha.com
    await seedRow({
      outbound_resource: 'https://alpha.com;;;https://beta.com',
      resource: '/page-1',
    });
    await seedRow({
      outbound_resource: 'https://alpha.com;;;https://gamma.com',
      resource: '/page-2',
      dt: NOW + 1,
    });
    await seedRow({
      outbound_resource: 'https://alpha.com',
      resource: '/page-3',
      dt: NOW + 2,
    });

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`, {
      waitUntil: 'domcontentloaded',
    });
    const nonce = await getNonce(page, 'meta-box-order');
    const response = await getFilterOptions(page, 'outbound_resource', nonce);
    const values = extractValues(response);

    // alpha.com should appear exactly once
    const alphaCount = values.filter((v) => v === 'https://alpha.com').length;
    expect(alphaCount).toBe(1);
  });

  test('normal dimensions still work unchanged', async ({ page }) => {
    await seedRow({ browser: 'UniqueTestBrowser42', country: 'US' });

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`, {
      waitUntil: 'domcontentloaded',
    });
    const nonce = await getNonce(page, 'meta-box-order');
    const response = await getFilterOptions(page, 'browser', nonce);
    const values = extractValues(response);

    expect(values).toContain('UniqueTestBrowser42');
  });
});

// ─── Operator Auto-Upgrade Tests ──────────────────────────────────────────────

test.describe('Filter operator: equals auto-upgrade for multi-value columns', () => {
  const MARKER = `mv-filter-${Date.now()}`;

  test('outbound_resource equals filter returns results', async ({ page }) => {
    await seedRow({
      outbound_resource: 'https://target.com;;;https://other.com',
      resource: `/test-${MARKER}-outbound`,
    });

    // Navigate with filter applied: outbound_resource contains https://target.com
    // Note: 'equals' is auto-upgraded to 'contains' server-side, but we test
    // the full round-trip by passing 'equals' in the URL.
    await page.goto(
      `${BASE_URL}/wp-admin/admin.php?page=slimview2&fs%5Boutbound_resource%5D=equals+https%3A%2F%2Ftarget.com`,
      { waitUntil: 'networkidle' }
    );

    // The page should show our seeded row
    const pageContent = await page.content();
    expect(pageContent).toContain(`${MARKER}-outbound`);
  });

  test('notes equals filter returns results', async ({ page }) => {
    await seedRow({
      notes: '[user:777][spam:yes]',
      resource: `/test-${MARKER}-notes`,
    });

    await page.goto(
      `${BASE_URL}/wp-admin/admin.php?page=slimview2&fs%5Bnotes%5D=equals+user%3A777`,
      { waitUntil: 'domcontentloaded' }
    );

    const pageContent = await page.content();
    expect(pageContent).toContain(`${MARKER}-notes`);
  });

  test('category equals filter returns results', async ({ page }) => {
    await seedRow({
      category: '3,7,15',
      resource: `/test-${MARKER}-cat`,
    });

    // category equals 7 should use FIND_IN_SET internally
    await page.goto(
      `${BASE_URL}/wp-admin/admin.php?page=slimview2&fs%5Bcategory%5D=equals+7`,
      { waitUntil: 'domcontentloaded' }
    );

    const pageContent = await page.content();
    expect(pageContent).toContain(`${MARKER}-cat`);
  });

  test('browser equals filter still works normally', async ({ page }) => {
    await seedRow({
      browser: 'ExactMatchBrowser',
      resource: `/test-${MARKER}-browser`,
    });

    await page.goto(
      `${BASE_URL}/wp-admin/admin.php?page=slimview2&fs%5Bbrowser%5D=equals+ExactMatchBrowser`,
      { waitUntil: 'domcontentloaded' }
    );

    const pageContent = await page.content();
    expect(pageContent).toContain(`${MARKER}-browser`);
  });
});
