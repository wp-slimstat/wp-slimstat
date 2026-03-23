/**
 * E2E tests: Outbound link report display — verifies that tracked outbound
 * clicks actually appear in the admin report widgets.
 *
 * This spec complements outbound-link-tracking.spec.ts (which tests storage)
 * by testing the display/query path: get_recent_outbound(), get_top_outbound(),
 * and the chart widget slim_p4_26_01.
 *
 * Reports tested:
 *   - slim_p4_01: Recent Outbound Links  (slimview4)
 *   - slim_p4_21: Top Outbound Links     (slimview4)
 *   - slim_p4_26_01: Pages with Outbound Links chart (slimview4)
 *   - slim_p7_02: Access Log             (slimview1)
 *
 * Strategy: seed outbound data directly into the DB, then navigate to admin
 * report pages and assert the data is visible. No tracking settings needed.
 */
import { test, expect, Page } from '@playwright/test';
import { BASE_URL } from './helpers/env';
import { getPool, closeDb, clearStatsTable } from './helpers/setup';

/** Login if the page was redirected to wp-login.php */
async function ensureLoggedIn(page: Page): Promise<void> {
  if (page.url().includes('wp-login.php')) {
    await page.fill('#user_login', 'parhumm');
    await page.fill('#user_pass', 'testpass123');
    await page.click('#wp-submit');
    await page.waitForURL('**/wp-admin/**', { timeout: 30_000 });
  }
}

const OUTBOUND_URL_1 = 'https://example.com/e2e-outbound-report-test';
const OUTBOUND_URL_2 = 'https://test-external.org/e2e-second-link';

/**
 * Seed a pageview row with a known outbound_resource directly in the DB.
 */
async function seedOutboundPageview(
  resource: string,
  outboundResource: string,
): Promise<number> {
  const now = Math.floor(Date.now() / 1000);
  const [result] = (await getPool().execute(
    `INSERT INTO wp_slim_stats
       (resource, outbound_resource, dt, dt_out, ip, visit_id, browser, platform, content_type)
     VALUES (?, ?, ?, ?, '127.0.0.1', 1, 'Chrome', 'Windows', 'post')`,
    [resource, outboundResource, now, now],
  )) as any;
  return result.insertId;
}

/**
 * Seed regular pageviews without outbound links to verify they don't
 * crowd out outbound records in reports (the original bug).
 */
async function seedRegularPageviews(count: number): Promise<void> {
  const now = Math.floor(Date.now() / 1000);
  for (let i = 0; i < count; i++) {
    await getPool().execute(
      `INSERT INTO wp_slim_stats
         (resource, dt, ip, visit_id, browser, platform, content_type)
       VALUES (?, ?, '127.0.0.1', 1, 'Chrome', 'Windows', 'post')`,
      [`/regular-page-${i}/`, now + i],
    );
  }
}

test.describe('Outbound Link Report Display', () => {
  test.setTimeout(90_000);

  test.beforeEach(async () => {
    await clearStatsTable();
    // Seed: 30 regular pageviews + 2 with outbound links.
    // Before the fix, the 30 regulars would crowd out the 2 outbound records
    // in get_recent_outbound() because SQL LIMIT filled with NULL rows.
    await seedRegularPageviews(30);
    await seedOutboundPageview('/test-page/', OUTBOUND_URL_1);
    await seedOutboundPageview('/another-page/', OUTBOUND_URL_2);
  });

  test.afterAll(async () => {
    await closeDb();
  });

  // ─── Test 1: slim_p4_01 — Recent Outbound Links ──────────────────

  test('slim_p4_01: Recent Outbound Links shows tracked outbound URLs', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview4`, {
      waitUntil: 'domcontentloaded',
    });
    await ensureLoggedIn(page);

    const widget = page.locator('#slim_p4_01');
    await expect(widget).toBeVisible({ timeout: 30_000 });

    // Wait for async-loaded content (if async_load is on)
    await page.waitForTimeout(6_000);

    const widgetText = await widget.textContent();
    expect(widgetText, 'Recent Outbound Links should not be empty').not.toContain('No data to display');
    expect(widgetText, 'Should contain seeded outbound URL').toContain('example.com');
  });

  // ─── Test 2: slim_p4_21 — Top Outbound Links ─────────────────────

  test('slim_p4_21: Top Outbound Links shows tracked outbound URLs', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview4`, {
      waitUntil: 'domcontentloaded',
    });
    await ensureLoggedIn(page);

    const widget = page.locator('#slim_p4_21');
    await expect(widget).toBeVisible({ timeout: 30_000 });

    await page.waitForTimeout(6_000);

    const widgetText = await widget.textContent();
    expect(widgetText, 'Top Outbound Links should not be empty').not.toContain('No data to display');
    expect(widgetText, 'Should contain seeded outbound URL').toContain('example.com');
  });

  // ─── Test 3: slim_p4_26_01 — Pages with Outbound Links chart ─────

  test('slim_p4_26_01: Pages with Outbound Links chart renders with data', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview4`, {
      waitUntil: 'domcontentloaded',
    });
    await ensureLoggedIn(page);

    const widget = page.locator('#slim_p4_26_01');
    await expect(widget).toBeVisible({ timeout: 30_000 });

    await page.waitForTimeout(6_000);

    const widgetText = await widget.textContent();
    expect(widgetText, 'Chart should not show no data').not.toContain('No data to display');
  });

  // ─── Test 4: slim_p7_02 — Access Log shows outbound_resource ─────

  test('slim_p7_02: Access Log displays outbound_resource for tracked rows', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    await ensureLoggedIn(page);

    const widget = page.locator('#slim_p7_02');
    await expect(widget).toBeVisible({ timeout: 30_000 });

    await page.waitForTimeout(6_000);

    const widgetHtml = await widget.innerHTML();
    // The access log should contain at least one of our outbound URLs
    expect(widgetHtml, 'Access log should show outbound URL').toContain('example.com');
  });

  // ─── Test 5: Reports don't show empty entries ─────────────────────

  test('slim_p4_01: does not show empty entries from non-outbound pageviews', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview4`, {
      waitUntil: 'domcontentloaded',
    });
    await ensureLoggedIn(page);

    const widget = page.locator('#slim_p4_01');
    await expect(widget).toBeVisible({ timeout: 30_000 });

    await page.waitForTimeout(6_000);

    // Count visible <p> items inside the widget (each outbound link is a <p>)
    const items = widget.locator('p');
    const count = await items.count();

    // We seeded 2 outbound links + 30 regulars.
    // Before the fix: report showed empty entries from the 30 regulars.
    // After the fix: should show only the 2 actual outbound links.
    let emptyCount = 0;
    for (let i = 0; i < count; i++) {
      const text = (await items.nth(i).textContent())?.trim() ?? '';
      if (text === '') emptyCount++;
    }
    expect(emptyCount, 'There should be no empty <p> entries in the outbound report').toBe(0);
  });
});
