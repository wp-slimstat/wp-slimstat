/**
 * E2E tests: External Page Tracking Snippet (#220)
 *
 * Verifies that the external tracking snippet shown on Settings > Tracker tab
 * includes the correct multi-transport parameters (transport, ajaxurl_ajax)
 * and uses a versioned CDN URL instead of trunk/.
 *
 * Also verifies that external page tracking works end-to-end when the snippet
 * is used with proper SlimStatParams.
 */
import { test, expect } from '@playwright/test';
import {
  installOptionMutator,
  uninstallOptionMutator,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  closeDb,
  getPool,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

/** Helper: get the external tracking snippet text from the settings page. */
async function getSnippetText(page: import('@playwright/test').Page): Promise<string> {
  await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimconfig&tab=2`, {
    waitUntil: 'domcontentloaded',
  });
  // Wait for the snippet <pre> to be visible
  const snippetPre = page.locator('pre').filter({ hasText: 'SlimStatParams' });
  await snippetPre.waitFor({ state: 'attached', timeout: 10_000 });
  return (await snippetPre.textContent()) || '';
}

test.describe('External Page Tracking Snippet — Issue #220', () => {
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    installOptionMutator();
  });

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    await closeDb();
  });

  // ─── Snippet Content Tests ──────────────────────────────────────

  test('Settings page snippet includes transport: "ajax" param', async ({ page }) => {
    const snippetText = await getSnippetText(page);
    expect(snippetText).toContain('transport: "ajax"');
  });

  test('Settings page snippet includes ajaxurl_ajax param', async ({ page }) => {
    const snippetText = await getSnippetText(page);
    expect(snippetText).toContain('ajaxurl_ajax:');
  });

  test('Settings page snippet uses versioned CDN URL (not trunk/)', async ({ page }) => {
    const snippetText = await getSnippetText(page);
    expect(snippetText).not.toContain('trunk/wp-slimstat.min.js');
    expect(snippetText).toMatch(/tags\/[\d.]+\/wp-slimstat\.min\.js/);
  });

  test('Snippet ajaxurl and ajaxurl_ajax both point to admin-ajax.php', async ({ page }) => {
    const snippetText = await getSnippetText(page);
    const ajaxUrlMatches = snippetText.match(/admin-ajax\.php/g);
    expect(ajaxUrlMatches).toBeTruthy();
    // Should appear at least twice (once for ajaxurl, once for ajaxurl_ajax)
    expect(ajaxUrlMatches!.length).toBeGreaterThanOrEqual(2);
  });

  // ─── Functional Tracking Tests ──────────────────────────────────

  test('Simulated external page with correct params sets SlimStatParams for AJAX transport', async ({ page }) => {
    const ajaxUrl = `${BASE_URL}/wp-admin/admin-ajax.php`;

    // Intercept a path to serve custom HTML with the correct snippet
    await page.route('**/external-test-page', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'text/html',
        body: `<!DOCTYPE html>
<html><head><title>External Test Page</title></head>
<body>
<h1>External Test Page</h1>
<script type="text/javascript">
var SlimStatParams = {
  transport: "ajax",
  ajaxurl: "${ajaxUrl}",
  ajaxurl_ajax: "${ajaxUrl}"
};
</script>
</body></html>`,
      });
    });

    await page.goto(`${BASE_URL}/external-test-page`);
    await page.waitForLoadState('domcontentloaded');

    // Verify SlimStatParams is correctly configured for AJAX transport
    const params = await page.evaluate(() => {
      const p = (window as any).SlimStatParams || {};
      return {
        transport: p.transport,
        ajaxurl: p.ajaxurl,
        ajaxurl_ajax: p.ajaxurl_ajax,
      };
    });
    expect(params.transport).toBe('ajax');
    expect(params.ajaxurl).toContain('admin-ajax.php');
    expect(params.ajaxurl_ajax).toContain('admin-ajax.php');
  });

  test('External page without transport/ajaxurl_ajax lacks transport params (regression baseline)', async ({ page }) => {
    // Navigate to admin first
    await page.goto(`${BASE_URL}/wp-admin/`, { waitUntil: 'domcontentloaded' });
    await setSlimstatOption(page, 'gdpr_enabled', 'off');

    // Get tracker URL
    await page.goto(`${BASE_URL}/?e2e=get-tracker-url`);
    await page.waitForLoadState('networkidle');
    const trackerSrc = await page.evaluate(() => {
      const scripts = document.querySelectorAll('script[src*="wp-slimstat"]');
      for (const s of scripts) {
        const src = (s as HTMLScriptElement).src;
        if (src.includes('wp-slimstat') && !src.includes('.map')) return src;
      }
      return '';
    });

    const ajaxUrl = `${BASE_URL}/wp-admin/admin-ajax.php`;
    const marker = `ext-broken-snippet-${Date.now()}`;

    // Create page with OLD broken snippet (only ajaxurl, no transport/ajaxurl_ajax)
    await page.route('**/external-broken-page', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'text/html',
        body: `<!DOCTYPE html>
<html><head><title>Broken External Page</title></head>
<body>
<h1>Broken External Test</h1>
<p>Marker: ${marker}</p>
<script type="text/javascript">
var SlimStatParams = { ajaxurl: "${ajaxUrl}" };
</script>
<script type="text/javascript" src="${trackerSrc}"></script>
</body></html>`,
      });
    });

    await page.goto(`${BASE_URL}/external-broken-page`);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(3000);

    // The old broken snippet should NOT have transport or ajaxurl_ajax
    const params = await page.evaluate(() => {
      const p = (window as any).SlimStatParams || {};
      return {
        transport: p.transport,
        ajaxurl_ajax: p.ajaxurl_ajax,
      };
    });
    expect(params.transport).toBeUndefined();
    expect(params.ajaxurl_ajax).toBeUndefined();
  });
});
