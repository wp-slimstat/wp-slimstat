/**
 * Heatmaps get their own admin bar button beside "Online", in the brand red, instead of a
 * small link in the Online dropdown's footer. On the site it opens this page's heatmap.
 *
 * Read-only: navigation only, no rows written.
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';

const button = '#wp-admin-bar-slimstat-heatmap > .ab-item';
const rgb = (c: string) => (c.match(/[\d.]+/g) || []).slice(0, 3).map(Number);

test('wp-admin: a red Heatmap button opens the Heatmaps list, and the dropdown no longer repeats it', async ({ page }) => {
  await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`);
  const link = page.locator(button);
  await expect(link).toHaveText('Heatmap');
  await expect(link).toHaveAttribute('href', /admin\.php\?page=slimheatmap$/);

  const [r, g, b] = rgb(await link.evaluate((a) => getComputedStyle(a).backgroundColor));
  expect(r - Math.max(g, b), 'a red fill, not the bar\'s grey').toBeGreaterThan(120);
  await link.hover();
  const [hr, hg, hb] = rgb(await link.evaluate((a) => getComputedStyle(a).backgroundColor));
  expect(hr - Math.max(hg, hb), 'hover keeps the fill').toBeGreaterThan(100);

  await expect(page.locator('#wp-admin-bar-slimstat-header a[href*="slimheatmap"]')).toHaveCount(0);
});

test('site: the button opens this page\'s heatmap', async ({ page }) => {
  await page.goto(`${BASE_URL}/`);
  // Pro opens the viewer on the page; Free highlights the page in the list.
  await expect(page.locator(button)).toHaveAttribute('href', /page=slimheatmap&(heatmap|highlight)=%2F$/);
});

test('phone: the button stays on the bar as an icon', async ({ page }) => {
  // Core hides admin bar nodes below 782px except its own, and shows only their icons.
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`);
  const node = page.locator('#wp-admin-bar-slimstat-heatmap');
  await expect(node).toBeVisible();
  expect(await node.locator('.ab-icon').evaluate((icon) => getComputedStyle(icon, '::before').content)).not.toMatch(/^(none|normal|"")$/);
});
