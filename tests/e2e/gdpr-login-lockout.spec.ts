/**
 * E2E regression (issue #325): with SlimStat's GDPR consent banner enabled, the
 * front-end AND the wp-login page must render without a PHP fatal.
 *
 * The banner renders on `wp_footer` and `login_footer`, so a fatal in that path
 * white-screens the login screen and locks the admin out. This is a standing
 * lockout guard — it is green on a healthy install both before and after the
 * fail-soft change; its true red→green is exercised by the RestApiFailSoft
 * integration test and the manual missing-class repro.
 *
 * Tagged @compat so the WordPress-version matrix lanes run it via `--grep @compat`.
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';
import {
  setSlimstatOptions,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  visitAsAnonymous,
} from './helpers/setup';

const FATAL = /Fatal error|Parse error|Uncaught (Error|TypeError|Exception)/;

test.describe('GDPR banner must not white-screen front-end or login @compat', () => {
  test.beforeAll(async () => {
    await snapshotSlimstatOptions();
    // Enable SlimStat's own banner. `consent_integration:''` keeps the boot-time
    // consent-sync from forcing use_slimstat_banner back off.
    await setSlimstatOptions(null as any, {
      consent_integration: '',
      gdpr_enabled: 'on',
      use_slimstat_banner: 'on',
    });
  });

  test.afterAll(async () => {
    await restoreSlimstatOptions();
  });

  test('front-end page renders with the banner and no PHP fatal @compat', async ({ page }) => {
    const resp = await page.goto(`${BASE_URL}/`, { waitUntil: 'domcontentloaded' });
    expect(resp, 'front-page response').toBeTruthy();
    expect(resp!.status(), 'front-page HTTP status').toBeLessThan(500);

    const body = await page.locator('body').innerText();
    expect(body).not.toContain('There has been a critical error');
    expect(body).not.toMatch(FATAL);
  });

  test('wp-login.php (anonymous) renders with no PHP fatal @compat', async ({ browser }) => {
    const page = await visitAsAnonymous(browser, `${BASE_URL}/wp-login.php`);
    // visitAsAnonymous navigates but returns only the Page; re-goto to capture the response.
    const resp = await page.goto(`${BASE_URL}/wp-login.php`, { waitUntil: 'domcontentloaded' });
    expect(resp, 'wp-login response').toBeTruthy();
    expect(resp!.status(), 'wp-login HTTP status').toBeLessThan(500);

    const body = await page.locator('body').innerText();
    expect(body).not.toContain('There has been a critical error');
    expect(body).not.toMatch(FATAL);

    // The real login form must be present (proves it rendered, not a WSOD).
    await expect(page.locator('#loginform')).toBeVisible();

    await page.close();
  });
});
