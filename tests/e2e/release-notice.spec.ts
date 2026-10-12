/**
 * The 6.0 release notice. After an update from 5.x, administrators see one dismissible
 * notice: two lines on the Dashboard and Plugins screens for 7 days, one row per new
 * feature on SlimStat screens for 15 days, both counted from one anchor option written on
 * the first render. It waits while a database migration is owed, and one dismissal hides
 * it everywhere.
 *
 * The update is simulated by switching notice_latest_news on and deleting the anchor; the
 * migration probe transient decides whether a migration is owed. Touches only options and
 * restores them; no stats rows are read or written.
 */
import { test, expect, Page, Request } from '@playwright/test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { runWordPressFixture } from './helpers/chart';
import {
  closeDb, getOptionValue, restoreOption, setOptionValue, snapshotOption, restoreSlimstatOptions, snapshotSlimstatOptions, setSlimstatOption,
} from './helpers/setup';
import { requireProBooted } from './helpers/pro-state';

const here = path.dirname(fileURLToPath(import.meta.url));
const ANCHOR = 'slimstat_release_notice_since';
const PROBE = '_transient_slimstat_migration_probe';
const PROBE_TIMEOUT = '_transient_timeout_slimstat_migration_probe';
const DISMISSED = 'slimstat_migration_dismissed';
const DAY = 24 * 60 * 60;
const DASHBOARD = '/wp-admin/index.php';
const PLUGINS = '/wp-admin/plugins.php';
const OVERVIEW = '/wp-admin/admin.php?page=slimview2';

const notice = (page: Page) => page.locator('#slimstat-notice-latest-news');
const now = () => Math.floor(Date.now() / 1000);

const noticeSetting = () => runWordPressFixture("<?php echo get_option('slimstat_options')['notice_latest_news'] ?? '';").trim();

/** A site that just updated: the notice is on, its window not yet anchored. */
async function simulateUpdate(page: Page, { anchorDaysAgo = null as number | null, migrationOwed = false } = {}): Promise<void> {
  await setSlimstatOption(page, 'notice_latest_news', 'on');
  await setOptionValue(ANCHOR, anchorDaysAgo === null ? null : String(now() - anchorDaysAgo * DAY));
  await setOptionValue(PROBE, migrationOwed ? 'dirty' : 'clean');
  await setOptionValue(PROBE_TIMEOUT, String(now() + DAY));
  // A dismissed migration set is never owed, whatever the probe says.
  if (migrationOwed) await setOptionValue(DISMISSED, null);
}

// Exactly one POST: admin.js and the core-screen inline script must not both send it.
async function dismissIn(page: Page): Promise<void> {
  const isDismissal = (r: Request) => r.url().includes('admin-ajax.php') && (r.postData() ?? '').includes('slimstat_notice_latest_news');
  let posts = 0;
  page.on('request', (r) => { if (isDismissal(r)) posts++; });
  const posted = page.waitForResponse((r) => isDismissal(r.request()));
  await notice(page).locator('.notice-dismiss').click();
  expect((await posted).status()).toBe(200);
  await expect(notice(page)).toHaveCount(0);
  await page.waitForLoadState('networkidle');
  expect(posts, 'one dismissal POST').toBe(1);
}

test.describe('6.0 release notice', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeAll(async () => {
    await snapshotSlimstatOptions();
    for (const option of [ANCHOR, PROBE, PROBE_TIMEOUT, DISMISSED]) await snapshotOption(option);
  });

  test.afterAll(async () => {
    await restoreSlimstatOptions();
    for (const option of [ANCHOR, PROBE, PROBE_TIMEOUT, DISMISSED]) await restoreOption(option);
    await closeDb();
  });

  test('after an update: two lines on Dashboard and Plugins, feature rows on SlimStat screens, nothing elsewhere', async ({ page }, testInfo) => {
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await simulateUpdate(page);
    const before = now();

    for (const url of [DASHBOARD, PLUGINS]) {
      await page.goto(url);
      await expect(notice(page), url).toBeVisible();
      await expect(notice(page).getByRole('link', { name: 'Explore SlimStat 6' })).toHaveAttribute('href', /admin\.php\?page=slimview2$/);
      await expect(notice(page).getByRole('link', { name: 'Review migration' })).toHaveAttribute('href', /admin\.php\?page=slimstat_migration$/);
      await expect(notice(page).locator('.slimstat-whats-new')).toHaveCount(0);
    }
    expect(Number(await getOptionValue(ANCHOR)), 'the first render anchors the window').toBeGreaterThanOrEqual(before - 5);

    await page.goto('/wp-admin/edit.php');
    await expect(notice(page)).toHaveCount(0);

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(OVERVIEW);
    const rows = notice(page).locator('.slimstat-whats-new > li');
    const woocommerce = (await getOptionValue('active_plugins') ?? '').includes('woocommerce/');
    await expect(rows).toHaveCount(woocommerce ? 4 : 3);
    for (const row of await rows.all()) await expect(row.locator('a')).toHaveCount(1);
    await expect(notice(page).locator('.dashicons:not([aria-hidden="true"])')).toHaveCount(0);
    await expect(notice(page).getByRole('link', { name: 'Open Traffic Sources' })).toHaveAttribute('href', /page=slimview5$/);
    await expect(notice(page).getByRole('link', { name: 'Open Heatmaps' })).toHaveAttribute('href', /page=slimheatmap$/);
    await expect(notice(page).getByRole('link', { name: 'Open Shortcodes' })).toHaveAttribute('href', /page=slimshortcodes$/);
    await expect(notice(page).getByRole('link', { name: 'Open Ecommerce' })).toHaveCount(woocommerce ? 1 : 0);
    await expect(notice(page).getByRole('link', { name: 'Review migration' })).toBeVisible();
    await expect(notice(page).locator('a[href*="wp-slimstat.com"], a[href*="page=slimpro"]')).toHaveCount(0);

    const tracks = () => notice(page).locator('.slimstat-whats-new').evaluate((el) => getComputedStyle(el).gridTemplateColumns.split(' ').length);
    expect(await tracks(), 'two columns on a desktop').toBe(2);
    await notice(page).screenshot({ path: testInfo.outputPath('release-notice-desktop.png') });
    await page.setViewportSize({ width: 600, height: 900 });
    expect(await tracks(), 'one column on a phone').toBe(1);
    await notice(page).screenshot({ path: testInfo.outputPath('release-notice-mobile.png') });
    expect(errors).toEqual([]);
  });

  // Plugins has only the inline script; the Dashboard also loads admin.js for SlimStat's widgets.
  for (const [where, at, adminJs] of [['Plugins', PLUGINS, false], ['Dashboard', DASHBOARD, true]] as const) {
    test(`dismissed once on ${where}, it is gone everywhere`, async ({ page }) => {
      await setSlimstatOption(page, 'add_dashboard_widgets', 'on');
      await simulateUpdate(page);
      await page.goto(at);
      expect(await page.evaluate(() => 'SlimStatAdminParams' in window), 'admin.js loaded').toBe(adminJs);
      await dismissIn(page);
      expect(noticeSetting()).toBe('no');
      expect(await getOptionValue(ANCHOR), 'the anchor stays').not.toBeNull();
      for (const url of [DASHBOARD, PLUGINS, OVERVIEW]) {
        await page.goto(url);
        await expect(notice(page), url).toHaveCount(0);
      }
    });
  }

  test('dismissed once on a SlimStat screen, it is gone everywhere', async ({ page }) => {
    await simulateUpdate(page);
    await page.goto(OVERVIEW);
    await dismissIn(page);
    expect(noticeSetting()).toBe('no');
    await page.goto(DASHBOARD);
    await expect(notice(page)).toHaveCount(0);
  });

  test('it leaves the Dashboard after 7 days and SlimStat screens after 15', async ({ page }) => {
    await simulateUpdate(page, { anchorDaysAgo: 8 });
    for (const url of [DASHBOARD, PLUGINS]) {
      await page.goto(url);
      await expect(notice(page), url).toHaveCount(0);
    }
    await page.goto(OVERVIEW);
    await expect(notice(page).locator('.slimstat-whats-new')).toBeVisible();

    await simulateUpdate(page, { anchorDaysAgo: 16 });
    for (const url of [DASHBOARD, PLUGINS, OVERVIEW]) {
      await page.goto(url);
      await expect(notice(page), url).toHaveCount(0);
    }
  });

  test('it waits while a database migration is owed, and its window does not start', async ({ page }) => {
    await simulateUpdate(page, { migrationOwed: true });
    for (const url of [DASHBOARD, OVERVIEW]) {
      await page.goto(url);
      await expect(page.locator('.slimstat-migration-notice'), url).toBeVisible();
      await expect(notice(page), url).toHaveCount(0);
    }
    expect(await getOptionValue(ANCHOR)).toBeNull();
  });

  test('users without manage_options never see it', async ({ page, browser }) => {
    await simulateUpdate(page);
    await setSlimstatOption(page, 'capability_can_view', 'read');
    const author = await browser.newContext({ storageState: path.join(here, '.auth/author.json') });
    try {
      const authorPage = await author.newPage();
      for (const url of [DASHBOARD, OVERVIEW]) {
        await authorPage.goto(url);
        await expect(notice(authorPage), url).toHaveCount(0);
      }
    } finally { await author.close(); }
    expect(await getOptionValue(ANCHOR), 'a hidden notice never anchors').toBeNull();
  });

  test('new installs start with it off; upgrades still get it', async () => {
    const out = runWordPressFixture("<?php echo wp_slimstat::get_fresh_defaults()['notice_latest_news'], ',', wp_slimstat::init_options()['notice_latest_news'];").trim();
    expect(out).toBe('no,on');
  });

  test('with Pro active it says what Pro 3.0.0 adds and links to no purchase page', async ({ page }) => {
    await requireProBooted(page);
    await simulateUpdate(page);
    await page.goto(DASHBOARD);
    await expect(notice(page)).toContainText('SlimStat Pro 3.0.0 adds heatmap page previews and Ecommerce email reports.');
    await page.goto(OVERVIEW);
    await expect(notice(page)).toContainText('SlimStat Pro 3.0.0 adds heatmap page previews, Ecommerce email reports and the Heatmap insights email.');
    await expect(notice(page).getByRole('link', { name: 'Open Heatmaps' })).toBeVisible();
    await expect(notice(page).locator('a[href*="wp-slimstat.com"], a[href*="page=slimpro"]')).toHaveCount(0);
  });
});
