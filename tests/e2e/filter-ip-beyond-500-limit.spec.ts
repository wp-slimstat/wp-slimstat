/**
 * E2E: Issue #298 — Access Log filter dropdown rejects pasted/typed values
 * beyond the server's 500-row DISTINCT slice.
 *
 * Three layers under test:
 *  - Layer 1 (client): SlimStatSearchableSelect syncs the embedded search
 *    input into #slimstat-filter-value on every keystroke, so the form posts
 *    the typed value even when no option in the dropdown matches.
 *  - Layer 2 (server): slimstat_get_filter_options accepts an optional
 *    `search` POST param and does a prepared LIKE query (left-anchored for
 *    IP-like columns, substring for notes/user_agent/etc).
 *  - Layer 3 (cache): response payload is cached by composite key.
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

const NOW = Math.floor(Date.now() / 1000);
// 255.* sorts lexicographically after every "10.*" IP, so with LIMIT 500 on an
// ASC DISTINCT slice seeded with 500 "10.*" IPs, this target is guaranteed
// to be outside the pre-fetched payload.
const TARGET_IP = '255.255.255.255';

async function clearTestData(): Promise<void> {
  await clearStatsTable();
  await getPool().execute(
    "DELETE FROM wp_options WHERE option_name LIKE '_transient_slimstat_%' OR option_name LIKE '_transient_timeout_slimstat_%'"
  );
}

/**
 * Seed `count` distinct IPs. The first `count - 1` are `10.0.<a>.<b>`
 * (contiguous), and the last row is TARGET_IP — lexicographically beyond the
 * 500-slice of any ascending DISTINCT query over this data.
 */
async function seedDistinctIps(count: number): Promise<void> {
  if (count <= 0) return;
  const placeholders: string[] = [];
  const values: any[] = [];
  for (let i = 0; i < count - 1; i++) {
    const a = (i >> 8) & 0xff;
    const b = i & 0xff;
    const ip = `10.0.${a}.${b}`;
    placeholders.push('(?, ?, ?, ?, ?, ?, ?)');
    values.push(`/page-${i}`, NOW - 3600 + i, ip, 1, 'Chrome', 'Windows', 'post');
  }
  placeholders.push('(?, ?, ?, ?, ?, ?, ?)');
  values.push('/target-page', NOW, TARGET_IP, 1, 'Chrome', 'Windows', 'post');
  await getPool().query(
    `INSERT INTO wp_slim_stats (resource, dt, ip, visit_id, browser, platform, content_type) VALUES ${placeholders.join(', ')}`,
    values,
  );
}

async function getNonce(page: Page, action: string): Promise<string> {
  const res = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
    form: { action: 'test_create_nonce', nonce_action: action },
  });
  const json = await res.json();
  return json.data.nonce;
}

type FilterResponse = { success: boolean; data: Array<string | { value: string }> };

async function getFilterOptions(
  page: Page,
  dimension: string,
  nonce: string,
  extra: Record<string, string> = {},
): Promise<FilterResponse> {
  const res = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
    form: {
      action: 'slimstat_get_filter_options',
      security: nonce,
      dimension,
      time_range_type: 'last_28_days',
      ...extra,
    },
  });
  return res.json();
}

function extractValues(response: FilterResponse): string[] {
  if (!response.success || !Array.isArray(response.data)) return [];
  return response.data.map((item) => (typeof item === 'string' ? item : item.value));
}

// ─── Setup ────────────────────────────────────────────────────────────────────

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

// ─── Endpoint-level tests ─────────────────────────────────────────────────────

test.describe('slimstat_get_filter_options endpoint — search + cache', () => {
  test('500-row cap applies when no search term is supplied (characterization)', async ({ page }) => {
    await seedDistinctIps(501);
    const nonce = await getNonce(page, 'meta-box-order');
    const response = await getFilterOptions(page, 'ip', nonce);
    const values = extractValues(response);

    expect(response.success).toBe(true);
    expect(values.length).toBeLessThanOrEqual(500);
    expect(values).not.toContain(TARGET_IP);
  });

  test('search returns IPs beyond the 500-slice (regression for #298)', async ({ page }) => {
    await seedDistinctIps(501);
    const nonce = await getNonce(page, 'meta-box-order');
    const response = await getFilterOptions(page, 'ip', nonce, { search: '255.' });
    const values = extractValues(response);

    expect(response.success).toBe(true);
    expect(values).toContain(TARGET_IP);
  });

  test('search shorter than 2 chars falls back to legacy DISTINCT', async ({ page }) => {
    await seedDistinctIps(10);
    const nonce = await getNonce(page, 'meta-box-order');
    const withShort = extractValues(await getFilterOptions(page, 'ip', nonce, { search: '1' }));
    const withNone = extractValues(await getFilterOptions(page, 'ip', nonce));

    expect(withShort.sort()).toEqual(withNone.sort());
  });

  test('search with no match returns empty array (not an error)', async ({ page }) => {
    await seedDistinctIps(10);
    const nonce = await getNonce(page, 'meta-box-order');
    const response = await getFilterOptions(page, 'ip', nonce, { search: 'zzz-no-such-ip' });

    expect(response.success).toBe(true);
    expect(extractValues(response)).toEqual([]);
  });

  test('LIKE metacharacters in the search term are escaped literally', async ({ page }) => {
    await seedDistinctIps(10);
    const nonce = await getNonce(page, 'meta-box-order');
    const response = await getFilterOptions(page, 'ip', nonce, { search: '%.%' });

    expect(response.success).toBe(true);
    // Literal '%.%' is not a substring of any "10.0.x.y" IP, so no matches.
    expect(extractValues(response)).toEqual([]);
  });

  test('ip uses left-anchored LIKE; mid-string prefix does not match', async ({ page }) => {
    await seedDistinctIps(10);
    const nonce = await getNonce(page, 'meta-box-order');
    // "10.0." is at the start of every seeded IP; left-anchored match finds all.
    const anchored = extractValues(await getFilterOptions(page, 'ip', nonce, { search: '10.0.' }));
    expect(anchored.length).toBeGreaterThan(0);
    // "0.0.0" appears mid-string in our IPs but isn't a left-anchor; no match.
    const midString = extractValues(await getFilterOptions(page, 'ip', nonce, { search: '0.0.0' }));
    expect(midString).toEqual([]);
  });

  test('user_agent uses substring LIKE (fragment matching)', async ({ page }) => {
    await getPool().execute(
      `INSERT INTO wp_slim_stats (resource, dt, ip, visit_id, browser, platform, user_agent, content_type)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
      ['/ua', NOW, '1.2.3.4', 1, 'Chrome', 'Windows', 'Mozilla/5.0 Firefox/120.0', 'post'],
    );
    const nonce = await getNonce(page, 'meta-box-order');
    const response = await getFilterOptions(page, 'user_agent', nonce, { search: 'Firefox' });
    const values = extractValues(response);

    expect(response.success).toBe(true);
    expect(values.some((v) => v.includes('Firefox'))).toBe(true);
  });

  test('response is cached: freshly inserted rows are not yet visible within TTL', async ({ page }) => {
    await seedDistinctIps(10);
    const nonce = await getNonce(page, 'meta-box-order');

    const first = extractValues(await getFilterOptions(page, 'ip', nonce));
    // Insert a new IP after the cache is primed. Cache TTL (300s) means the
    // second call returns the cached payload without the new row.
    await getPool().execute(
      `INSERT INTO wp_slim_stats (resource, dt, ip, visit_id, browser, platform, content_type)
       VALUES (?, ?, ?, ?, ?, ?, ?)`,
      ['/fresh', NOW, '20.20.20.20', 1, 'Chrome', 'Windows', 'post'],
    );
    const second = extractValues(await getFilterOptions(page, 'ip', nonce));

    expect(second.sort()).toEqual(first.sort());
    expect(second).not.toContain('20.20.20.20');
  });
});

// ─── UI-level tests ───────────────────────────────────────────────────────────

test.describe('Access Log filter UI — #298 typed-value regression', () => {
  test('typing an IP beyond the 500-slice syncs into the hidden form field', async ({ page }) => {
    await seedDistinctIps(501);
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`, {
      waitUntil: 'networkidle',
    });

    await page.selectOption('#slimstat-filter-name', 'ip');
    await page.waitForSelector('.slimstat-searchable-select');
    await page.selectOption('#slimstat-filter-operator', 'equals');

    await page.click('.slimstat-select-display');
    await page.fill('.slimstat-select-search input', TARGET_IP);

    const synced = await page.inputValue('#slimstat-filter-value');
    expect(synced).toBe(TARGET_IP);
  });

  test('no-match message invites the user to click Apply when they have typed', async ({ page }) => {
    await seedDistinctIps(10);
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`, {
      waitUntil: 'networkidle',
    });

    await page.selectOption('#slimstat-filter-name', 'ip');
    await page.waitForSelector('.slimstat-searchable-select');
    await page.click('.slimstat-select-display');
    await page.fill('.slimstat-select-search input', 'zzz-definitely-not-an-ip');

    const noResults = page.locator('[data-testid="slimstat-no-results"]');
    await expect(noResults).toBeVisible();
    await expect(noResults).toContainText(/Apply/i);
  });

  test('clearing the search after a server fetch restores the initial list', async ({ page }) => {
    await seedDistinctIps(501);
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`, {
      waitUntil: 'networkidle',
    });

    await page.selectOption('#slimstat-filter-name', 'ip');
    await page.waitForSelector('.slimstat-searchable-select');
    await page.click('.slimstat-select-display');

    // Capture the initial dropdown state (the 500-row DISTINCT slice).
    const initialCount = await page.locator('.slimstat-select-options .slimstat-select-option').count();
    expect(initialCount).toBeGreaterThan(1);

    // Trigger a server search for the late IP (not in the initial slice).
    await page.fill('.slimstat-select-search input', '255.');
    await expect(
      page.locator('.slimstat-select-options .slimstat-select-option .slimstat-option-label', { hasText: '255.' }).first(),
    ).toBeVisible({ timeout: 3000 });
    const narrowedCount = await page.locator('.slimstat-select-options .slimstat-select-option').count();
    expect(narrowedCount).toBeLessThan(initialCount);

    // Clear the search — the dropdown should return to the initial list.
    await page.fill('.slimstat-select-search input', '');
    const restoredCount = await page.locator('.slimstat-select-options .slimstat-select-option').count();
    expect(restoredCount).toBe(initialCount);
  });

  test('is_empty operator leaves the sync handler inert (readonly guard)', async ({ page }) => {
    await seedDistinctIps(10);
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`, {
      waitUntil: 'networkidle',
    });

    await page.selectOption('#slimstat-filter-name', 'ip');
    await page.waitForSelector('.slimstat-searchable-select');
    await page.selectOption('#slimstat-filter-operator', 'is_empty');

    const readonly = await page.locator('#slimstat-filter-value').getAttribute('readonly');
    expect(readonly).not.toBeNull();

    const disabledWrapperPointer = await page
      .locator('.slimstat-searchable-select .slimstat-select-wrapper')
      .evaluate((el) => (el as HTMLElement).style.pointerEvents);
    expect(disabledWrapperPointer).toBe('none');
  });
});
