/**
 * Country → City drilldown: the Audience Location map, its country list and Top Countries
 * open one dialog. Free receives one real city and an upsell; Pro receives every city;
 * City precision off shows how to turn it on. The Free boundary is enforced server-side,
 * so the direct-request tests read the AJAX response, not the DOM.
 */
import { test, expect, type Page, type Locator } from '@playwright/test';
import mysql from 'mysql2/promise';
import { BASE_URL, MYSQL_CONFIG, assertSafeTestDatabase } from './helpers/env';
import { snapshotSlimstatOptions, restoreSlimstatOptions, snapshotOption, restoreOption, setSlimstatOption, clearStatsTable, closeDb } from './helpers/setup';
import { requireProBooted } from './helpers/pro-state';
import { runWordPressFixture } from './helpers/chart';

let db: mysql.Pool;

// [country, city, pageviews, browser]. 62 US pageviews, 6 of them without a city.
const FIXTURE: Array<[string, string | null, number, string?]> = [
  ['us', 'New York (NY)', 25], ['us', 'Los Angeles (CA)', 14], ['us', 'Chicago (IL)', 4],
  ['us', 'Chicago (IL)', 3, 'Firefox'], ['us', 'Houston (TX)', 5], ['us', 'Phoenix (AZ)', 3],
  ['us', 'Seattle (WA)', 2], ['us', '', 4], ['us', null, 2],
  ['de', 'Berlin (BE)', 9], ['de', 'Munich (BY)', 4], ['fr', 'Paris (IDF)', 6], ['it', null, 3],
];
const HIDDEN_FROM_FREE = ['Los Angeles', 'Chicago', 'Houston', 'Phoenix', 'Seattle'];

async function now(): Promise<number> {
  const [options] = await db.query<mysql.RowDataPacket[]>("SELECT option_value FROM wp_options WHERE option_name='gmt_offset'");
  return Math.floor(Date.now() / 1000) + Math.round(Number(options[0]?.option_value ?? 0) * 3600);
}

async function seed(): Promise<void> {
  const dt = await now();
  const rows: unknown[][] = [];
  let i = 0;
  for (const [country, city, count, browser = 'Chrome'] of FIXTURE) {
    for (let k = 0; k < count; k++) {
      rows.push([dt - ++i * 30, `/city-drilldown-${i}`, country, city, `203.0.113.${i % 250}`, i, browser, 'p-lin']);
    }
  }
  // Outside the default date range: must never be counted.
  rows.push([dt - 400 * 86400, '/city-drilldown-old', 'us', 'Seattle (WA)', '203.0.113.1', 9999, 'Chrome', 'p-lin']);
  await db.query('INSERT INTO wp_slim_stats (dt, resource, country, city, ip, visit_id, browser, platform) VALUES ?', [rows]);
}

const dialog = (page: Page): Locator => page.locator('#slimstat-country-cities');

async function loaded(page: Page): Promise<Locator> {
  const d = dialog(page);
  await expect(d).toHaveJSProperty('open', true);
  await expect(d.locator('.ss-cc__body > :not(.ss-cc__loading)').first()).toBeVisible({ timeout: 15_000 });
  return d;
}

async function openReport(page: Page, screen: string, id: string): Promise<Locator> {
  await page.goto(`${BASE_URL}/wp-admin/admin.php?page=${screen}`);
  const report = page.locator(`#${id}`);
  // Reports load when scrolled into view.
  await report.scrollIntoViewIfNeeded();
  return report;
}

async function drilldown(page: Page, country: string, start = 0, extra: Record<string, string> = {}) {
  const security = await page.locator('#meta-box-order-nonce').inputValue();
  return page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
    form: { action: 'slimstat_country_cities', security, page: 'slimview3', country, start: String(start), ...extra },
  });
}

async function withoutPro<T>(fn: () => Promise<T>): Promise<T> {
  await snapshotOption('active_plugins');
  try {
    runWordPressFixture("<?php deactivate_plugins('wp-slimstat-pro/wp-slimstat-pro.php', true);");
    return await fn();
  } finally {
    await restoreOption('active_plugins');
  }
}

test.describe('Country → City drilldown', () => {
  test.beforeAll(() => {
    assertSafeTestDatabase();
    db = mysql.createPool(MYSQL_CONFIG);
  });
  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    for (const [key, value] of Object.entries({
      geolocation_country: 'no', geolocation_provider: 'dbip', async_load: 'on', rows_to_show: '20',
      limit_results: '200', gdpr_enabled: 'off', ignore_wp_users: 'off',
    })) {
      await setSlimstatOption(page, key, value);
    }
    await seed();
  });
  test.afterEach(async () => { await restoreSlimstatOptions(); });
  test.afterAll(async () => { await clearStatsTable(); await db.end(); await closeDb(); });

  test('Free: map, map list and Top Countries open one dialog with exactly one real city', async ({ page }) => {
    await withoutPro(async () => {
      const map = await openReport(page, 'slimview1', 'slim_p6_01');

      // B. The country list beside the map.
      await map.locator('.top-countries [data-slimstat-country="us"]').click();
      let d = await loaded(page);
      await expect(d.locator('h2')).toHaveText('Top Cities in United States');
      await expect(d.locator('tbody tr')).toHaveCount(1);
      await expect(d.locator('tbody tr')).toContainText(['New York (NY)']);
      await expect(d.locator('tbody tr td').first()).toHaveText('25');
      await expect(d.locator('tbody tr td').nth(1)).toHaveText('40.3%');
      await expect(d.locator('.slimstat-locked-rows')).toBeVisible();
      await expect(d.locator('.ss-cc__locked h3')).toHaveText('Unlock City-Level Insights');
      const cta = d.getByRole('link', { name: 'Unlock All Cities with Pro' });
      await expect(cta).toHaveAttribute('href', /wp-slimstat\.com\/pricing\/.*utm_campaign=country-cities/);
      for (const city of HIDDEN_FROM_FREE) {
        await expect(d).not.toContainText(city);
      }
      await page.keyboard.press('Escape');
      await expect(d).toHaveJSProperty('open', false);

      // A. The world map itself.
      await map.locator('#map_slim_p6_01 path[data-code="DE"]').click({ force: true });
      d = await loaded(page);
      await expect(d.locator('h2')).toHaveText('Top Cities in Germany');
      await expect(d.locator('tbody tr')).toHaveCount(1);
      await expect(d.locator('tbody th')).toContainText('Berlin (BE)');
      await expect(d).not.toContainText('Munich');
      await page.keyboard.press('Escape');

      // C. The standalone Top Countries report: any part of the row, and no filter navigation.
      const report = await openReport(page, 'slimview3', 'slim_p1_13');
      const url = page.url();
      await report.locator('[data-slimstat-country="us"] .slimstat-count-pct').click();
      d = await loaded(page);
      await expect(d.locator('h2')).toHaveText('Top Cities in United States');
      await expect(d.locator('tbody tr')).toHaveCount(1);
      expect(page.url()).toBe(url);
    });
  });

  test('Free: a direct request never returns a second city', async ({ page }) => {
    await withoutPro(async () => {
      await openReport(page, 'slimview3', 'slim_p1_13');
      for (const start of [0, 1, 3]) {
        const response = await drilldown(page, 'us', start, { limit_results: '500', rows_to_show: '500' });
        expect(response.status()).toBe(200);
        const body = await response.text();
        expect(body).toContain('New York (NY)');
        for (const city of HIDDEN_FROM_FREE) {
          expect(body, `start=${start} leaked ${city}`).not.toContain(city);
        }
      }
    });
  });

  test('the endpoint validates the country, the nonce and the session', async ({ page, browser }) => {
    await openReport(page, 'slimview3', 'slim_p1_13');
    expect((await drilldown(page, 'usa')).status()).toBe(400);
    expect((await drilldown(page, "u'")).status()).toBe(400);

    const forged = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
      form: { action: 'slimstat_country_cities', security: 'forged', country: 'us' },
    });
    expect(forged.status()).toBe(403);

    const anonymous = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    try {
      const response = await anonymous.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, { form: { action: 'slimstat_country_cities', country: 'us' } });
      expect(await response.text()).not.toContain('New York');
    } finally {
      await anonymous.close();
    }
  });

  test('City precision off shows how to enable it, Free or Pro, and reads no cities', async ({ page }) => {
    await setSlimstatOption(page, 'geolocation_country', 'on');
    await openReport(page, 'slimview3', 'slim_p1_13');
    await page.locator('#slim_p1_13 [data-slimstat-country="us"] .slimstat-filter-link').click();
    const d = await loaded(page);
    await expect(d.locator('.ss-cc__setup h3')).toHaveText('Enable City-Level Tracking');
    await expect(d).toContainText('City-level statistics require City geolocation precision.');
    await expect(d.getByRole('link', { name: 'Enable City Tracking' })).toHaveAttribute('href', /page=slimconfig&(amp;|#038;)?tab=2#geolocation_country-label$/);
    await expect(d.locator('table, .slimstat-pro-adds')).toHaveCount(0);
    await expect(d).not.toContainText('New York');
  });

  test('empty states: no cities recorded, and no pageviews at all', async ({ page }) => {
    await openReport(page, 'slimview3', 'slim_p1_13');
    let response = await (await drilldown(page, 'it')).json();
    expect(response.success).toBe(true);
    expect(response.data.html).toContain('No city data for Italy in this period.');
    expect(response.data.html).toContain('3 pageviews from Italy have no city');

    response = await (await drilldown(page, 'jp')).json();
    expect(response.data.title).toBe('Top Cities in Japan');
    expect(response.data.html).toContain('No pageviews from Japan in this period.');
  });

  test('counts and shares match Top Cities and respect the date range and filters', async ({ page }) => {
    // Top Cities (slim_p2_23) is the existing report the drilldown must agree with.
    const report = await openReport(page, 'slimview3', 'slim_p2_23');
    await expect(report.locator('p.slimstat-tooltip-trigger', { hasText: 'New York (NY)' }).locator('.slimstat-count-pct')).toHaveText(/^25\s*\(/);
    // The 400-day-old Seattle row is outside the range in both.
    await expect(report.locator('p.slimstat-tooltip-trigger', { hasText: 'Seattle (WA)' }).locator('.slimstat-count-pct')).toHaveText(/^2\s*\(/);

    const all = await (await drilldown(page, 'us')).json();
    expect(all.data.html).toMatch(/New York \(NY\)<\/span>.*?<td class="ss-cc__number">25<\/td><td class="ss-cc__number">40\.3%<\/td>/s);
    expect(all.data.html).toContain('6 pageviews from United States have no city');

    // A global report filter narrows the drilldown exactly as it narrows every other report.
    const filtered = await (await drilldown(page, 'us', 0, { 'fs[browser]': 'equals Firefox' })).json();
    expect(filtered.data.html).toMatch(/Chicago \(IL\)<\/span>.*?<td class="ss-cc__number">3<\/td><td class="ss-cc__number">100\.0%<\/td>/s);
    expect(filtered.data.html).not.toContain('New York');
  });

  test('keyboard: Enter and Space open from the map list, Escape returns focus', async ({ page }) => {
    const map = await openReport(page, 'slimview1', 'slim_p6_01');
    const row = map.locator('.top-countries [data-slimstat-country="de"]');
    await row.focus();
    await page.keyboard.press('Enter');
    await loaded(page);
    await page.keyboard.press('Escape');
    await expect(row).toBeFocused();
    await page.keyboard.press('Space');
    await expect(dialog(page).locator('h2')).toHaveText('Top Cities in Germany');
    await page.keyboard.press('Escape');
    await expect(row).toBeFocused();
  });

  test('Refresh and modified clicks keep their existing behaviour', async ({ page }) => {
    const report = await openReport(page, 'slimview3', 'slim_p1_13');
    await expect(report.locator('[data-slimstat-country="us"]')).toBeVisible();
    await report.locator('.slimstat-header-buttons .refresh').click();
    await expect(report.locator('[data-slimstat-country="us"]')).toBeVisible({ timeout: 15_000 });
    // One delegated listener: still one dialog after the report re-rendered.
    await report.locator('[data-slimstat-country="us"] .slimstat-filter-link').click();
    await loaded(page);
    await expect(page.locator('dialog#slimstat-country-cities')).toHaveCount(1);
    await page.keyboard.press('Escape');

    // The filter link is the no-JS and modified-click path: it still filters by country.
    const href = await report.locator('[data-slimstat-country="us"] .slimstat-filter-link').getAttribute('href');
    expect(href).toContain('fs%5Bcountry%5D=equals+us');
  });

  test('Pro: every city, no upsell, and Show more pages the list', async ({ page }) => {
    await requireProBooted(page);
    await setSlimstatOption(page, 'rows_to_show', '3');
    await openReport(page, 'slimview3', 'slim_p1_13');
    await page.locator('#slim_p1_13 [data-slimstat-country="us"] .slimstat-filter-link').click();
    const d = await loaded(page);
    await expect(d.locator('tbody tr')).toHaveCount(3);
    await expect(d.locator('.slimstat-locked-rows, .slimstat-pro-adds')).toHaveCount(0);
    await d.getByRole('button', { name: 'Show more cities' }).click();
    await expect(d.locator('tbody tr')).toHaveCount(6);
    await expect(d.locator('tbody th').nth(3)).toBeFocused();
    await expect(d.getByRole('button', { name: 'Show more cities' })).toHaveCount(0);
    await expect(d.locator('tbody th')).toContainText(['New York (NY)', 'Los Angeles (CA)', 'Chicago (IL)', 'Houston (TX)', 'Phoenix (AZ)', 'Seattle (WA)']);
  });
});
