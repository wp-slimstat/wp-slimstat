/**
 * E2E (compat matrix): admin + tracking boot smoke.
 *
 * Tagged @compat so the WordPress-version matrix lanes (5.6→7.0) can run this
 * focused set via `--grep @compat` without executing the full suite on every
 * lane. The lane (WP core + PHP) is selected by the CI job's
 * .wp-env.override.json, not by anything in this file — one spec, N lanes.
 *
 * Asserts the SlimStat admin surfaces load with no PHP fatal/critical-error on
 * whatever WP×PHP the lane booted. The strongest cross-version signal that the
 * plugin is compatible is simply: it boots and renders.
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';

const LANE = `WP ${process.env.WP_VERSION || 'local'} · PHP ${process.env.WP_ENV_PHP_VERSION || 'local'}`;

const ADMIN_PAGES: Record<string, string> = {
  'Real-Time':   `${BASE_URL}/wp-admin/admin.php?page=slimview1`,
  'Access Log':  `${BASE_URL}/wp-admin/admin.php?page=slimview2`,
  'Settings':    `${BASE_URL}/wp-admin/admin.php?page=slimconfig&tab=1`,
};

test.describe('Compat boot smoke @compat', () => {
  for (const [label, url] of Object.entries(ADMIN_PAGES)) {
    test(`${label} admin page loads without a PHP fatal — ${LANE} @compat`, async ({ page }) => {
      const resp = await page.goto(url, { waitUntil: 'domcontentloaded' });
      expect(resp, 'navigation response').toBeTruthy();
      expect(resp!.status(), 'HTTP status').toBeLessThan(500);

      const body = await page.locator('body').innerText();
      // WordPress renders these on an uncaught PHP fatal / WSOD.
      expect(body).not.toContain('There has been a critical error');
      expect(body).not.toMatch(/Fatal error|Parse error|Uncaught (Error|TypeError|Exception)/);
    });
  }

  test(`Tracking pageview is accepted — ${LANE} @compat`, async ({ page }) => {
    // A logged-out front-page hit must not 500; the tracker (admin-ajax or REST)
    // should not emit a server error on any supported WP×PHP lane.
    const resp = await page.goto(`${BASE_URL}/`, { waitUntil: 'domcontentloaded' });
    expect(resp, 'front-page response').toBeTruthy();
    expect(resp!.status(), 'front-page HTTP status').toBeLessThan(500);
    const body = await page.locator('body').innerText();
    expect(body).not.toContain('There has been a critical error');
  });
});
