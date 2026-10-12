/**
 * Without Pro, every report Pro can export shows the same Export button, and it opens the
 * Pro page in wp-admin. It used to carry .slimstat-filter-link, so admin.js POSTed the
 * filters form (date range included) to the pricing site in the same tab. Goals and
 * Funnels showed no Export until a goal or funnel existed.
 *
 * Pro is switched off for the test and active_plugins restored after. Requests to
 * wp-slimstat.com are aborted and recorded.
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';
import { snapshotOption, restoreOption, closeDb } from './helpers/setup';
import { runWordPressFixture } from './helpers/chart';

const exportIn = (id: string) => `${id} > .slimstat-header-buttons .button-export-to-xls`;

test.afterAll(async () => { await closeDb(); });

test('without Pro, report Export opens the Pro page in wp-admin', async ({ page }) => {
  const external: string[] = [];
  await page.context().route(/wp-slimstat\.com/, (route) => { external.push(route.request().url()); return route.abort(); });
  await snapshotOption('active_plugins');
  try {
    runWordPressFixture("<?php deactivate_plugins('wp-slimstat-pro/wp-slimstat-pro.php', true);");

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview6`);
    for (const id of ['#slim_p9_01', '#slim_p9_02']) {
      await expect(page.locator(exportIn(id))).toHaveAttribute('href', /admin\.php\?page=slimpro$/);
    }

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`);
    const link = page.locator(exportIn('#slim_p1_08'));
    await expect(link).toHaveText('Export');
    await expect(link).not.toHaveAttribute('target', /.+/);
    // A plain GET: admin.js turns filter-link clicks into a POST of the filters form.
    const nav = page.waitForRequest((r) => r.isNavigationRequest() && /page=slimpro/.test(r.url()));
    await link.click();
    expect((await nav).method()).toBe('GET');
    await expect(page).toHaveURL(/admin\.php\?page=slimpro$/);
    expect(external).toEqual([]);
  } finally {
    await restoreOption('active_plugins');
  }
});
