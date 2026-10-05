/**
 * Ecommerce layout from the 2026-10-04 screenshot audit. The page once failed to parse
 * (a `//` comment inside a one-line object), so nothing below ran; the first test
 * pins that. Seeds the shared Ecommerce fixture (its cleanup removes only its own rows);
 * the fixture refuses a non-disposable database, so against a real store run it read-only
 * on that store's orders with ECOMMERCE_LIVE_DATA=1.
 */
import { test, expect, type Page } from '@playwright/test';
import { readFileSync } from 'fs';
import { runWordPressFixture } from './helpers/chart';

const source = readFileSync(new URL('./helpers/ecommerce-data.php', import.meta.url), 'utf8').replace(/^<\?php\s*/, '');
const fixture = (mode: string) => JSON.parse(runWordPressFixture(`<?php\n$fixture_mode = '${mode}';\n${source}`));
const rgb = (c: string) => (c.match(/[\d.]+/g) || []).slice(0, 3).map(Number);
const live = process.env.ECOMMERCE_LIVE_DATA === '1';
let url = '/wp-admin/admin.php?page=slimview7';

test.describe.configure({ mode: 'serial' });
test.beforeAll(() => {
  if (live) return;
  fixture('cleanup');
  const day = new Date(fixture('seed').start * 1000).toISOString().slice(0, 10);
  url = '/wp-admin/admin.php?' + new URLSearchParams({ page: 'slimview7', type: 'custom', from: day, to: day, 'fs[addon_ecommerce_currency]': 'equals USD' });
});
test.afterAll(() => { if (!live) fixture('cleanup'); });

async function open(page: Page, errors: string[] = []) {
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto(url);
  await expect(page.locator('[data-ecommerce] [data-metric=net]')).toContainText(live ? /\d/ : '115.00', { timeout: 30_000 });
}

test('@woocommerce the dashboard scripts run and the shared filter bar is used as is', async ({ page }) => {
  const errors: string[] = [];
  await open(page, errors);
  expect(errors).toEqual([]);
  await expect(page.locator('.ss-ec-controls, .ss-ec-refresh-status')).toHaveCount(0);
  await expect(page.getByLabel('Filter dimension')).toBeVisible();
  await expect(page.locator('.ss-ec-updated')).toHaveText(/^Updated /);
  await expect(page.getByRole('link', { name: 'Refresh Ecommerce reports' })).toBeVisible();
});

test('@woocommerce the selected KPI is a neutral tint, and the currency control says what it is', async ({ page }) => {
  await open(page);
  const [r, g, b] = rgb(await page.locator('.ss-ec-kpi[aria-pressed=true]').evaluate((k) => getComputedStyle(k).backgroundColor));
  expect(Math.max(r, g, b) - Math.min(r, g, b)).toBeLessThanOrEqual(6);
  await expect(page.locator('.ss-ec-currency > summary')).toContainText('Currency:');
});

test('@woocommerce the selected value is not red, cards claim no fixed count, funnels match the UI (QA D8)', async ({ page }) => {
  await open(page);
  // Brand red on a sales figure reads as a loss.
  const [r, g, b] = rgb(await page.locator('.ss-ec-kpi[aria-pressed=true] > strong').evaluate((v) => getComputedStyle(v).color));
  expect(r, 'the selected value is not red').toBeLessThanOrEqual(Math.max(g, b));
  // "Top 10" sat over tabs of 5 or 3 rows; each panel already says "Top by net sales".
  await expect(page.locator('.ss-ec-card-heading')).not.toContainText(['Top 10']);
  // The pointer resting on the selected tab left a grey box that read as stale focus.
  const selected = page.locator('.ss-ec-tabs [aria-selected=true]').first();
  await selected.hover();
  expect(await selected.evaluate((t) => getComputedStyle(t).backgroundColor)).toBe('rgba(0, 0, 0, 0)');
  // WordPress link blue on a red UI.
  const [fr, fg, fb] = rgb(await page.locator('.ss-ec-row-filter').first().evaluate((a) => getComputedStyle(a).color));
  expect(fr, 'filter funnels take the UI accent').toBeGreaterThan(Math.max(fg, fb));
});

test('@woocommerce orders without a campaign say so, not "Unassigned" (QA U1)', async ({ page }) => {
  await open(page);
  // Channels use "Unassigned" for pageviews that predate report setup; here it meant no campaign tag.
  const names = await page.locator('#ss-ec-panel-campaign .ss-ec-rank-name').allTextContents();
  expect(names).not.toContain('Unassigned');
});

test('@woocommerce the sync banner names the failed order and counts this report only (QA U2)', async ({ page }) => {
  await open(page);
  // "Some orders could not be imported" beside "58 of 58 orders linked" read as a contradiction.
  const summary = page.locator('#ss-ec-quality > summary');
  await expect(summary).not.toContainText('Some orders could not be imported');
  await expect(summary).toContainText(/orders in this report linked|No orders in this report/);
  await expect(page.locator('.ss-ec-steps')).not.toContainText('included order');
});

test('@woocommerce cards in a row match, a lone tab is hidden, sort shows its direction', async ({ page }) => {
  await open(page);
  const rows = await page.locator('.ss-ec-card').evaluateAll((cards) => {
    const byTop: Record<number, number[]> = {};
    cards.forEach((c) => { const r = c.getBoundingClientRect(); (byTop[Math.round(r.top)] ||= []).push(Math.round(r.height)); });
    return Object.values(byTop);
  });
  rows.forEach((heights) => expect(new Set(heights).size).toBe(1));

  for (const tabs of await page.locator('.ss-ec-tabs').all()) {
    if ((await tabs.locator('[role=tab]').count()) === 1) await expect(tabs).toBeHidden();
  }

  const sort = page.locator('[data-sort]').first();
  const icon = () => sort.locator('.dashicons').evaluate((i) => getComputedStyle(i, '::before').content);
  const before = await icon();
  await sort.click();
  expect(await icon()).not.toBe(before);
});

test('@woocommerce the page sits on the WordPress admin grey, and journey bars stay inside', async ({ page }) => {
  await page.setViewportSize({ width: 1100, height: 900 });
  await open(page);
  const [body, behind] = await page.evaluate(() => [getComputedStyle(document.body).backgroundColor, getComputedStyle(document.querySelector('.backdrop-container')!).backgroundColor]);
  expect(['rgba(0, 0, 0, 0)', body]).toContain(behind);

  const edge = await page.locator('.ss-ec-journey').evaluate((j) => j.getBoundingClientRect().right);
  for (const meter of await page.locator('.ss-ec-steps meter').all()) {
    expect((await meter.boundingBox())!.x + (await meter.boundingBox())!.width).toBeLessThanOrEqual(edge + 0.5);
  }
});
