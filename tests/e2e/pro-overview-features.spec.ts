/**
 * The Pro overview (slimpro) presents AI Analysis and City-Level Insights once each, with what
 * they need and the next step each access state calls for: a buyer gets the attributed upgrade
 * links, a locked Pro gets its license step and no purchase CTA, a licensed Pro opens the feature
 * or its setup. Opening the page never changes a setting.
 */
import { test, expect } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';
import { runWordPressFixture } from './helpers/chart';
import { getProState, requireProBooted, setProLicense } from './helpers/pro-state';
import { closeDb, getOptionValue, restoreOption, restoreSlimstatOptions, setSlimstatOptions, snapshotOption, snapshotSlimstatOptions } from './helpers/setup';

const PAGE = '/wp-admin/admin.php?page=slimpro';
const feature = (page: Page, name: string): Locator => page.locator('.ss-pro-feature').filter({ has: page.getByRole('heading', { name, exact: true }) });

/** Both entries appear once, say what they need, and nothing on the page is a `*` placeholder. */
async function expectEntries(page: Page, licensed: boolean): Promise<void> {
  await page.goto(PAGE);
  const ai = feature(page, 'AI Analysis');
  const city = feature(page, 'City-Level Insights');
  await expect(ai).toHaveCount(1);
  await expect(city).toHaveCount(1);
  await expect(page.locator('.ss-pro')).not.toContainText('*');
  await expect(ai).toContainText('next steps to consider');
  await expect(ai).toContainText('WordPress 7.0 or newer with its AI features enabled, an AI provider connected in WordPress, and AI Analysis turned on in its settings. Your provider may charge for API use.');
  await expect(city).toContainText('open Top Cities for it');
  await expect(city).toContainText('earlier pageviews keep their country only, and the provider may not place every visitor.');
  // The comparison for anyone without a working license, "Included" once it works.
  if (licensed) {
    for (const item of [ai, city]) await expect(item.locator('.ss-pro-included')).toHaveText('Included');
    await expect(page.locator('.ss-pro-delta')).toHaveCount(0);
  } else {
    await expect(ai.locator('.ss-pro-delta')).toHaveText(/Free\s+Copy Prompt, to paste into your own AI tool\s*Pro\s+Analysis beside the report, saved to revisit/);
    await expect(city.locator('.ss-pro-delta')).toHaveText(/Free\s+The top city in each country\s*Pro\s+Every recorded city in the country/);
  }
  // The table of contents counts the new group and the grown one.
  await expect(page.locator('.ss-pro-toc li').filter({ hasText: 'Read your reports with AI' })).toContainText('1 feature');
  await expect(page.locator('.ss-pro-toc li').filter({ hasText: 'Know who is behind the numbers' })).toContainText('3 features');
}

test.describe.serial('Pro overview: AI Analysis and City-Level Insights', () => {
  let proInstalled = false;

  test.beforeAll(async ({ browser }) => {
    const page = await browser.newPage();
    proInstalled = (await getProState(page)).pro_installed;
    await page.close();
    await snapshotSlimstatOptions();
    await snapshotOption('active_plugins');
  });

  test.afterAll(async () => {
    await restoreOption('active_plugins');
    await restoreSlimstatOptions();
    await closeDb();
  });

  test('Free: both entries compare Free with Pro and the actions are the attributed purchase links', async ({ page }, testInfo) => {
    if (proInstalled) runWordPressFixture("<?php deactivate_plugins('wp-slimstat-pro/wp-slimstat-pro.php');");
    try {
      await expectEntries(page, false);
      for (const name of ['AI Analysis', 'City-Level Insights']) await expect(feature(page, name).getByRole('link')).toHaveCount(0);
      const ctas = page.locator('.ss-pro-cta');
      await expect(ctas).toHaveCount(2);
      for (const link of await ctas.all()) {
        const url = new URL((await link.getAttribute('href'))!);
        expect(url.origin + url.pathname).toBe('https://my.wp-slimstat.com/checkout/wp-slimstat-pro');
        expect(url.searchParams.get('utm_source')).toBe('wp-slimstat');
        expect(url.searchParams.get('utm_campaign')).toBe('pro-overview');
      }
      await expect(page.getByText('14-day money-back guarantee')).toBeVisible();

      // The table of contents reaches the new group by keyboard.
      await page.getByRole('link', { name: 'Read your reports with AI' }).focus();
      await page.keyboard.press('Enter');
      await expect(page).toHaveURL(/#ss-pro-ai$/);
      await expect(page.locator('#ss-pro-ai')).toBeInViewport();

      for (const width of [1440, 782, 390]) {
        await page.setViewportSize({ width, height: 900 });
        for (const dir of ['ltr', 'rtl']) {
          await page.evaluate(d => { document.documentElement.dir = d; }, dir);
          expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `no sideways scroll at ${width}px ${dir}`).toBe(true);
          for (const name of ['AI Analysis', 'City-Level Insights']) {
            const box = (await feature(page, name).boundingBox())!;
            expect(box.x, `${name} inside the page at ${width}px ${dir}`).toBeGreaterThanOrEqual(0);
            expect(box.x + box.width).toBeLessThanOrEqual(width + 1);
          }
        }
        await page.evaluate(() => { document.documentElement.dir = 'ltr'; });
        await feature(page, 'City-Level Insights').screenshot({ path: testInfo.outputPath(`free-city-${width}.png`) });
      }
    } finally {
      await restoreOption('active_plugins');
    }
  });

  test('Pro awaiting activation: the license step, not a purchase, and no setup steps it has already done', async ({ page }) => {
    await requireProBooted(page);
    try {
      expect(await setProLicense(page, 'none')).toBe('none');
      await expectEntries(page, false);
      for (const name of ['AI Analysis', 'City-Level Insights']) await expect(feature(page, name).getByRole('link')).toHaveCount(0);
      const cta = page.locator('.ss-pro-hero .ss-pro-cta');
      await expect(page.locator('.ss-pro-cta')).toHaveCount(1);
      await expect(cta).toHaveText('Activate license');
      await expect(cta).toHaveAttribute('href', /\/wp-admin\/admin\.php\?page=slimconfig&tab=8$/);
      await expect(cta).not.toHaveAttribute('target');
      for (const name of ['Upgrade to Pro', 'Compare all plans', 'Upload Pro in WordPress', 'Download Pro from your account']) {
        await expect(page.locator('.ss-pro').getByRole('link', { name })).toHaveCount(0);
      }
      await expect(page.getByText('14-day money-back guarantee')).toHaveCount(0);
      await page.locator('.ss-pro-setup summary').click();
      await expect(page.locator('.ss-pro-setup li')).toHaveText([/Enter your license in SlimStat Settings/]);
      await cta.click();
      await expect(page).toHaveURL(/page=slimconfig&tab=8/);
      await expect(page.getByLabel('License key', { exact: true })).toBeVisible();
    } finally {
      expect(await setProLicense(page, 'valid')).toBe('valid');
    }
  });

  test('licensed Pro: each entry opens its feature, or its setup when city tracking is off, and nothing is saved', async ({ page }) => {
    await requireProBooted(page);
    try {
      expect(await setProLicense(page, 'valid')).toBe('valid');
      const setups: [Record<string, string>, string, RegExp][] = [
        [{ geolocation_country: 'on', geolocation_provider: 'dbip' }, 'Enable City Tracking', /\/wp-admin\/admin\.php\?page=slimconfig&tab=2#geolocation_country-label$/],
        [{ geolocation_country: 'no', geolocation_provider: 'disable' }, 'Enable City Tracking', /\/wp-admin\/admin\.php\?page=slimconfig&tab=2#wp-slimstat-third-party-libraries$/],
        [{ geolocation_country: 'no', geolocation_provider: 'dbip' }, 'Open Audience', /\/wp-admin\/admin\.php\?page=slimview3$/],
      ];
      for (const [settings, label, href] of setups) {
        await setSlimstatOptions(settings);
        const before = await getOptionValue('slimstat_options');
        await expectEntries(page, true);
        const action = feature(page, 'City-Level Insights').getByRole('link');
        await expect(action).toHaveText(label);
        await expect(action).toHaveAttribute('href', href);
        expect(await getOptionValue('slimstat_options'), 'viewing the overview must not change a setting').toBe(before);
      }
      await expect(page.locator('.ss-pro-hero .ss-pro-cta')).toHaveText('Manage your license');
      await feature(page, 'City-Level Insights').getByRole('link').click();
      await expect(page).toHaveURL(/page=slimview3$/);
      await expect(page.locator('#slim_p1_13')).toBeVisible();

      await page.goto(PAGE);
      const open = feature(page, 'AI Analysis').getByRole('link');
      await expect(open).toHaveText('Open AI Analysis');
      await expect(open).toHaveAttribute('href', /\/wp-admin\/admin\.php\?page=slimai$/);
      await open.focus();
      await page.keyboard.press('Enter');
      await expect(page).toHaveURL(/page=slimai$/);
    } finally {
      await restoreSlimstatOptions();
    }
  });
});
