/**
 * E2E (Fix 2): the dashboard Funnels widget shows EVERY funnel with switchable
 * tabs — not just the first.
 *
 * Before the fix, show_funnels_compact() rendered only $funnels[0], so a site
 * with multiple funnels saw one in the dashboard widget with no way to switch.
 * Now it renders one .slimstat-funnel-chart panel per funnel inside
 * .slimstat-funnel-widget, with a .slimstat-funnel-wtab tab strip (a class
 * distinct from the main page's .slimstat-gf-tab so the handlers never collide).
 *
 * The exact markup + data-source contract is covered by the PHP unit/integration
 * tests; this verifies the rendered dashboard behaviour: all funnels present,
 * one panel visible at a time, tab clicks switch panels.
 */
import { test, expect, Page } from '@playwright/test';
import { BASE_URL, WP_ROOT } from './helpers/env';
import * as path from 'path';
import { closeDb } from './helpers/setup';
import { seedFunnels, clearAll, forceLimits, restoreDefaultLimits, pinReportToDashboard } from './helpers/goals-funnels';

const WP_CONTENT = path.join(WP_ROOT, 'wp-content');
const STEP = (name: string, value: string) => ({ name, dimension: 'resource', operator: 'contains', value });

async function openDashboard(page: Page): Promise<void> {
  await page.goto(`${BASE_URL}/wp-admin/index.php`, { waitUntil: 'domcontentloaded' });
}

test.describe('Dashboard Funnels widget — all funnels with tabs (Fix 2)', () => {
  test.setTimeout(60_000);

  test.beforeEach(async () => {
    await clearAll();
    await forceLimits(5, 3, WP_CONTENT); // Pro: funnels unlocked
    await pinReportToDashboard('slim_p9_02');
  });

  test.afterAll(async () => {
    await restoreDefaultLimits(WP_CONTENT);
    await clearAll();
    await closeDb();
  });

  test('widget renders one tab + panel per funnel and switches on click', async ({ page }) => {
    await seedFunnels([
      { name: 'Checkout flow', steps: [STEP('Home', '/'), STEP('Pricing', '/pricing')] },
      { name: 'Signup flow', steps: [STEP('Home', '/'), STEP('Trial', '/trial')] },
    ]);

    await openDashboard(page);

    const widget = page.locator('#slim_p9_02 .slimstat-funnel-widget');
    await expect(widget).toBeVisible({ timeout: 30_000 });

    // Both funnels get a tab and a panel; the main-page tab class is NOT reused.
    await expect(widget.locator('.slimstat-funnel-wtab')).toHaveCount(2);
    await expect(widget.locator('.slimstat-gf-tab')).toHaveCount(0);
    await expect(widget.locator('.slimstat-funnel-chart')).toHaveCount(2);

    const panel0 = widget.locator('.slimstat-funnel-chart[data-funnel-index="0"]');
    const panel1 = widget.locator('.slimstat-funnel-chart[data-funnel-index="1"]');

    // JS-enhanced: first panel visible, the rest hidden.
    await expect(panel0).toBeVisible();
    await expect(panel1).toBeHidden();

    // Switch to the second funnel.
    await widget.locator('.slimstat-funnel-wtab[data-funnel-index="1"]').click();
    await expect(panel1).toBeVisible();
    await expect(panel0).toBeHidden();
    await expect(widget.locator('.slimstat-funnel-wtab[data-funnel-index="1"]')).toHaveClass(/is-active/);
  });

  test('de-cramped: bar track and tab strip render at the polished sizes', async ({ page }) => {
    await seedFunnels([
      { name: 'A flow', steps: [STEP('Home', '/'), STEP('Pricing', '/pricing')] },
      { name: 'B flow', steps: [STEP('Home', '/'), STEP('Trial', '/trial')] },
    ]);

    await openDashboard(page);
    const widget = page.locator('#slim_p9_02 .slimstat-funnel-widget');
    await expect(widget).toBeVisible({ timeout: 30_000 });

    // Funnel bar track is the de-cramped 32px (was 28px) — matches the main page.
    const trackH = await widget
      .locator('.slimstat-funnel-chart[data-funnel-index="0"] .slimstat-funnel-bar-track')
      .first()
      .evaluate((el) => getComputedStyle(el).height);
    expect(trackH).toBe('32px');

    // The tab strip is a pill (rounded), not a default browser button row.
    const radius = await widget
      .locator('.slimstat-funnel-wtab.is-active')
      .first()
      .evaluate((el) => getComputedStyle(el).borderTopLeftRadius);
    expect(parseFloat(radius)).toBeGreaterThan(8); // pill radius, not square
  });

  test('single funnel renders no tab strip (just the one panel)', async ({ page }) => {
    await seedFunnels([{ name: 'Solo flow', steps: [STEP('Home', '/'), STEP('Pricing', '/pricing')] }]);

    await openDashboard(page);

    const widget = page.locator('#slim_p9_02 .slimstat-funnel-widget');
    await expect(widget).toBeVisible({ timeout: 30_000 });
    await expect(widget.locator('.slimstat-funnel-wtab')).toHaveCount(0);
    await expect(widget.locator('.slimstat-funnel-chart')).toHaveCount(1);
    await expect(widget).toContainText('Solo flow');
  });

  test('many + long funnels: tabs stay on one row and scroll (no wrap) in a narrow widget', async ({ page }) => {
    await seedFunnels([
      { name: 'Landing to contact', steps: [STEP('Home', '/'), STEP('Contact', '/contact')] },
      { name: 'Landing to thank-you page', steps: [STEP('Home', '/'), STEP('Thanks', '/thank-you')] },
      { name: 'Checkout completion', steps: [STEP('Cart', '/cart'), STEP('Checkout', '/checkout')] },
      { name: 'Homepage to pricing to checkout flow', steps: [STEP('Home', '/'), STEP('Pricing', '/pricing')] },
    ]);

    await openDashboard(page);
    // Replicate a narrow 2-column dashboard widget so the tabs overflow.
    await page.addStyleTag({ content: '#slim_p9_02{max-width:400px !important}' });

    const widget = page.locator('#slim_p9_02 .slimstat-funnel-widget');
    await expect(widget).toBeVisible({ timeout: 30_000 });
    await expect(widget.locator('.slimstat-funnel-wtab')).toHaveCount(4);

    // All tabs share one row (no wrap)…
    const rowCount = await widget
      .locator('.slimstat-funnel-wtab')
      .evaluateAll((els) => new Set(els.map((e) => (e as HTMLElement).offsetTop)).size);
    expect(rowCount).toBe(1);

    // …and the strip is horizontally scrollable when they overflow.
    const scrollable = await widget
      .locator('.slimstat-funnel-wtabs')
      .evaluate((e) => e.scrollWidth > e.clientWidth + 1);
    expect(scrollable).toBe(true);

    // Switching still works for a tab reached by scrolling.
    await widget.locator('.slimstat-funnel-wtab[data-funnel-index="3"]').click();
    await expect(widget.locator('.slimstat-funnel-chart[data-funnel-index="3"]')).toBeVisible();
  });
});
