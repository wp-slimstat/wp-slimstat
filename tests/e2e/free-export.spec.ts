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
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BASE_URL } from './helpers/env';
import { snapshotOption, restoreOption, closeDb, snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOption } from './helpers/setup';
import { runWordPressFixture } from './helpers/chart';

const here = path.dirname(fileURLToPath(import.meta.url));

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
      await expect(page.locator(exportIn(id))).toHaveAttribute('href', /admin\.php\?page=slimpro#ss-pro-share$/);
    }

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`);
    const link = page.locator(exportIn('#slim_p1_08'));
    await expect(link).toHaveText('Export');
    await expect(link).not.toHaveAttribute('target', /.+/);
    // A plain GET: admin.js turns filter-link clicks into a POST of the filters form.
    const nav = page.waitForRequest((r) => r.isNavigationRequest() && /page=slimpro/.test(r.url()));
    await link.click();
    expect((await nav).method()).toBe('GET');
    await expect(page).toHaveURL(/admin\.php\?page=slimpro#ss-pro-share$/);
    expect(external).toEqual([]);
  } finally {
    await restoreOption('active_plugins');
  }
});

// Someone who can read reports but not open Settings cannot buy Pro, so they get no Export button.
test('without Pro, a user without manage_options sees no Export button', async ({ page, browser }) => {
  await snapshotOption('active_plugins');
  await snapshotSlimstatOptions();
  const author = await browser.newContext({ storageState: path.join(here, '.auth/author.json') });
  try {
    runWordPressFixture("<?php deactivate_plugins('wp-slimstat-pro/wp-slimstat-pro.php', true);");
    await setSlimstatOption(page, 'capability_can_view', 'read');
    const authorPage = await author.newPage();
    await authorPage.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`);
    await expect(authorPage.locator('#slim_p1_08')).toBeVisible();
    await expect(authorPage.locator('.button-export-to-xls')).toHaveCount(0);
    // The header's "Upgrade to Pro" pill is shared chrome and stays; the reports carry none.
    await expect(authorPage.locator('.postbox a[href*="wp-slimstat.com/pricing"]')).toHaveCount(0);
  } finally {
    await author.close();
    await restoreSlimstatOptions();
    await restoreOption('active_plugins');
  }
});
