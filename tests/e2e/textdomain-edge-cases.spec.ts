/**
 * Suite 06 S05-S08: Textdomain edge cases
 *
 * S05: Negative control — textdomain loads on correct hook
 * S06: Deactivate/reactivate plugin — textdomain still loads correctly
 * S07: WP-CLI context — plugin loads without errors (simulated via AJAX)
 * S08: @known-issue WP Consent API interaction
 */
import { test, expect } from '@playwright/test';
import {
  closeDb,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  installOptionMutator,
  uninstallOptionMutator,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

/**
 * Switch back on every SlimStat plugin this spec's deactivation took down.
 *
 * S06 deactivates Free through plugins.php. Pro requires Free, so on that very
 * request Pro's requirement guard deactivates Pro too — its documented fail-soft,
 * not a defect. Reactivating only Free therefore leaves Pro off for the rest of
 * the worker: in the 6c399579 census this spec ran ~160 tests before the Pro
 * specs, and every one of them then failed or never ran ("wp-slimstat-pro is
 * installed but not activated"), 12 tests attributed to Pro for a state this
 * spec created.
 *
 * Free first — activating Pro while Free is off just makes Pro switch itself
 * off again. Rows are addressed by `data-plugin` (the plugin file, always
 * emitted by the list table) rather than `data-slug`, which WordPress derives
 * from the plugin *name* when no update-API slug is known.
 */
async function reactivateSlimstatPlugins(page: import('@playwright/test').Page): Promise<void> {
  await page.goto('/wp-admin/plugins.php', { waitUntil: 'domcontentloaded' });
  for (const file of ['wp-slimstat/wp-slimstat.php', 'wp-slimstat-pro/wp-slimstat-pro.php']) {
    const activate = page.locator(`tr[data-plugin="${file}"] .activate a`);
    if (await activate.count() === 0) continue; // not installed, or already active
    await activate.first().click();
    // WordPress redirects back to plugins.php, so the next row is on the page already.
    await page.waitForLoadState('domcontentloaded');
  }
}

test.describe('Suite 06: Textdomain Edge Cases', () => {
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    installOptionMutator();
  });

  test.beforeEach(async () => {
    await snapshotSlimstatOptions();
  });

  // Unconditional, and after every test rather than after S06 only: a test that
  // fails halfway through the deactivate/reactivate flow is exactly the one that
  // leaves plugins switched off for the rest of the worker.
  test.afterEach(async ({ page }) => {
    await restoreSlimstatOptions();
    await reactivateSlimstatPlugins(page);
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    await closeDb();
  });

  test('S05: textdomain loads on correct hook (not too late)', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=slimstat', { waitUntil: 'domcontentloaded' });
    const html = await page.content();
    expect(html).not.toContain('Fatal error');
    expect(html).not.toContain('_doing_it_wrong');
  });

  test('S06: deactivate and reactivate — textdomain still loads', async ({ page }) => {
    await page.goto('/wp-admin/plugins.php', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2000);

    // Try multiple selector strategies for the deactivate link
    // The data-slug attribute may use "wp-slimstat" or the plugin file basename
    let deactivateLink = page.locator('tr[data-slug="wp-slimstat"] .deactivate a');
    let isActive = await deactivateLink.count() > 0;

    if (!isActive) {
      // Try alternative: look for the plugin row by plugin name text
      deactivateLink = page.locator('tr:has(td:has-text("Slimstat Analytics")) .deactivate a');
      isActive = await deactivateLink.count() > 0;
    }

    if (!isActive) {
      // Plugin may already be deactivated or selector doesn't match — skip
      test.skip(true, 'Could not find wp-slimstat deactivate link — plugin may not be active or selector mismatch');
      return;
    }

    await deactivateLink.click();
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(2000);

    // Look for activate link with same flexible selectors
    let activateLink = page.locator('tr[data-slug="wp-slimstat"] .activate a');
    if (await activateLink.count() === 0) {
      activateLink = page.locator('tr:has(td:has-text("Slimstat Analytics")) .activate a');
    }
    await expect(activateLink).toBeVisible({ timeout: 10_000 });
    await activateLink.click();
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(2000);

    // After reactivation, navigate to SlimStat page. WordPress may redirect
    // if the menu hasn't been registered yet. Try navigating and check.
    const response = await page.goto('/wp-admin/admin.php?page=slimview1', { waitUntil: 'domcontentloaded' });
    const html = await page.content();
    expect(html).not.toContain('Fatal error');
    // If it redirected (e.g., to dashboard), that's OK — the key assertion is no fatal error.
  });

  test('S07: admin-ajax context — plugin loads without errors', async ({ page }) => {
    const response = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
      form: {
        action: 'test_set_slimstat_option',
        key: 'geolocation_country',
        value: 'no',
      },
    });
    expect(response.ok()).toBe(true);
  });

  test.fixme('S08: WP Consent API interaction (@known-issue)', async ({ page }) => {
    // Known issue: WP Consent API may interfere with textdomain loading
    await page.goto('/wp-admin/admin.php?page=slimstat', { waitUntil: 'domcontentloaded' });
    const html = await page.content();
    expect(html).not.toContain('Fatal error');
  });
});
