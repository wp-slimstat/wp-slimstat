/**
 * #402 — the Audience Location map draws one dot per city, sized by its pageviews.
 * Dots must sit inside their country, follow the date picker and the active filters,
 * honour Map data points, render city names as text, open the city drilldown, and give
 * way to a Settings hint at Country precision.
 */
import { test, expect, type Page, type Locator } from '@playwright/test';
import mysql from 'mysql2/promise';
import { BASE_URL, MYSQL_CONFIG, assertSafeTestDatabase } from './helpers/env';
import { snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOption, clearStatsTable, closeDb } from './helpers/setup';

let db: mysql.Pool;

const EVIL = '<img src=x onerror="window.__dotXss=1">';

/** Seeds pageviews dated in site time: repeated, unlocated, country-only and out-of-range rows included. */
async function seed(): Promise<void> {
  const [options] = await db.query<mysql.RowDataPacket[]>("SELECT option_value FROM wp_options WHERE option_name='gmt_offset'");
  const dt = Math.floor(Date.now() / 1000) + Math.round(Number(options[0]?.option_value ?? 0) * 3600);
  // [seconds ago, country, city, location, browser]
  const rows: Array<[number, string, string, string, string]> = [
    [10, 'de', 'Berlin (BE)', '52.52,13.40', 'Firefox'],
    [20, 'jp', 'Tokyo (13)', '35.68,139.69', 'Chrome'],
    [25, 'jp', 'Tokyo (13)', '35.68,139.69', 'Chrome'], // same city: one bigger dot
    [30, 'us', EVIL, '40.71,-74.00', 'Chrome'],
    [40, 'fr', 'Lyon (ARA)', '', 'Chrome'], // city without coordinates: no dot
    [50, 'it', '', '', 'Chrome'], // country only: no dot
    [3 * 86400, 'fr', 'Paris (IDF)', '48.85,2.35', 'Chrome'], // outside "today"
  ];
  await db.query('INSERT INTO wp_slim_stats (dt, resource, country, city, location, ip, visit_id, browser, platform) VALUES ?', [
    rows.map(([ago, country, city, location, browser], i) => [dt - ago, `/dots-${i}`, country, city, location, `203.0.113.${i + 1}`, i + 1, browser, 'p-lin']),
  ]);
}

/** Opens Real-time with an optional query string and waits until the map has drawn its countries. */
async function openMap(page: Page, query = ''): Promise<Locator> {
  await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1${query}`);
  await page.locator('#slim_p6_01').scrollIntoViewIfNeeded();
  const map = page.locator('#map_slim_p6_01');
  await expect(map.locator('path.jvm-region[data-code="DE"]')).toBeAttached({ timeout: 30_000 });
  return map;
}

async function centre(l: Locator) {
  const b = (await l.boundingBox())!;
  return { x: b.x + b.width / 2, y: b.y + b.height / 2 };
}

/** Whether the dot's centre falls in the country's box; fails if the country has no size. */
async function inside(dot: Locator, country: Locator): Promise<boolean> {
  const p = await centre(dot);
  const b = (await country.boundingBox())!;
  // A map drawn into a hidden box collapses every shape onto one point, where "inside" is trivially true.
  expect(b.width).toBeGreaterThan(5);
  return p.x >= b.x && p.x <= b.x + b.width && p.y >= b.y && p.y <= b.y + b.height;
}

test.describe('Audience Location city dots (#402)', () => {
  test.beforeAll(() => {
    assertSafeTestDatabase();
    db = mysql.createPool(MYSQL_CONFIG);
  });
  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    for (const [key, value] of Object.entries({
      geolocation_country: 'no', geolocation_provider: 'dbip', async_load: 'on', max_dots_on_map: '50',
      gdpr_enabled: 'off', ignore_wp_users: 'off',
    })) {
      await setSlimstatOption(page, key, value);
    }
    await seed();
  });
  test.afterEach(async () => { await restoreSlimstatOptions(); });
  test.afterAll(async () => { await clearStatsTable(); await db.end(); await closeDb(); });

  test('dots sit inside their country, follow the date range and render names as text', async ({ page }) => {
    await page.addInitScript(() => { (window as any).__dotXss = 0; });
    let map = await openMap(page);
    const dots = map.locator('circle.jvm-marker');
    await expect(dots).toHaveCount(4);

    map = await openMap(page, '&type=today');
    // Busiest first: Tokyo (2 pageviews), then Berlin and the hostile name (1 each).
    await expect(dots).toHaveCount(3);
    expect(await inside(dots.nth(0), map.locator('path[data-code="JP"]'))).toBe(true);
    expect(await inside(dots.nth(1), map.locator('path[data-code="DE"]'))).toBe(true);
    expect(await inside(dots.nth(1), map.locator('path[data-code="JP"]'))).toBe(false);
    const radius = async (dot: Locator) => Number(await dot.getAttribute('r'));
    expect(await radius(dots.nth(0))).toBeGreaterThan(await radius(dots.nth(1)));

    // Six pageviews today: Tokyo holds two of them.
    const tooltip = page.locator('.jvm-tooltip.active');
    await dots.nth(0).hover({ force: true });
    await expect(tooltip).toBeVisible();
    await expect(tooltip).toHaveCSS('background-color', 'rgb(43, 43, 43)');
    await expect(tooltip).toContainText('Tokyo (13)');
    await expect(tooltip).toContainText('2 pageviews · 33.3%');
    await dots.nth(1).hover({ force: true });
    await expect(tooltip).toContainText('Berlin (BE)');
    await expect(tooltip).toContainText('1 pageview · 16.7%');
    await expect(page.locator('#slim_p6_01 .slimstat-map-legend')).toHaveText(/Cities: bigger dot, more pageviews\s*Countries: darker, more pageviews/);

    await dots.nth(2).hover({ force: true });
    await expect(tooltip).toContainText(EVIL);
    await expect(tooltip.locator('img')).toHaveCount(0);
    expect(await page.evaluate(() => (window as any).__dotXss)).toBe(0);
    await page.mouse.move(0, 0);
    await expect(page.locator('.jvm-tooltip')).toBeHidden();

    // Refresh swaps the report body while it is hidden: the new map draws once it is shown again,
    // and the old map's tooltip goes with it.
    await map.locator('svg').evaluate(svg => svg.setAttribute('data-old', '1'));
    await page.locator('#slim_p6_01 .slimstat-header-buttons .refresh').click();
    await expect(map.locator('svg[data-old]')).toHaveCount(0, { timeout: 15_000 });
    await expect(map.locator('svg')).toHaveCount(1);
    await expect(dots).toHaveCount(3);
    expect(await inside(dots.nth(0), map.locator('path[data-code="JP"]'))).toBe(true);
    await expect(page.locator('.jvm-tooltip')).toHaveCount(1);

    // A dot opens its country's city drilldown, like the country itself.
    await dots.nth(0).click({ force: true });
    await expect(page.locator('#slimstat-country-cities h2')).toHaveText('Top Cities in Japan');
  });

  test('dots follow the active filters and the Map data points limit', async ({ page }) => {
    const map = await openMap(page, '&type=today&fs%5Bbrowser%5D=equals+Firefox');
    await expect(map.locator('circle.jvm-marker')).toHaveCount(1);

    await setSlimstatOption(page, 'max_dots_on_map', '2');
    await openMap(page, '&type=today');
    await expect(map.locator('circle.jvm-marker')).toHaveCount(2);
    await expect(page.locator('#slim_p6_01 .slimstat-map-legend')).toContainText('Top 2 cities: bigger dot, more pageviews');
  });

  test('Country precision draws the map without dots and points to the setting', async ({ page }) => {
    await setSlimstatOption(page, 'geolocation_country', 'on');
    const map = await openMap(page);
    await expect(map.locator('path.jvm-region').first()).toBeAttached();
    await expect(map.locator('circle.jvm-marker')).toHaveCount(0);
    const report = page.locator('#slim_p6_01');
    await expect(report.locator('.slimstat-map-legend')).toHaveText(/^\s*Countries: darker, more pageviews\s*$/);
    const hint = report.locator('.slimstat-map-hint');
    await expect(hint).toHaveText('To see which cities your visitors come from, set Geolocation precision to City in Settings.');
    await expect(hint.getByRole('link', { name: 'Settings' })).toHaveAttribute('href', /page=slimconfig&tab=2#geolocation_country$/);
  });

  test('City precision without located visits says when dots will appear', async ({ page }) => {
    // Visits recorded before city coordinates were stored: countries and city names, no location.
    await db.query("DELETE FROM wp_slim_stats WHERE location <> ''");
    const map = await openMap(page);
    await expect(map.locator('circle.jvm-marker')).toHaveCount(0);
    await expect(page.locator('#slim_p6_01 .slimstat-map-hint')).toHaveText('City dots appear as new visits are recorded.');
  });
});
