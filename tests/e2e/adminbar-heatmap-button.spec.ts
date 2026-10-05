/**
 * Heatmaps get their own admin bar button beside "Online", instead of a small link in the
 * Online dropdown's footer: an outlined pill in the bar's own colours, with only the icon in
 * the brand colour. On the site it opens this page's heatmap.
 *
 * Read-only: navigation only, no rows written.
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';
import { rgb } from './helpers/setup';

const button = '#wp-admin-bar-slimstat-heatmap > .ab-item';

test('wp-admin: a quiet Heatmap button opens the Heatmaps list, and the dropdown no longer repeats it', async ({ page }) => {
  await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`);
  const link = page.locator(button);
  await expect(link).toHaveText('Heatmap');
  await expect(link).toHaveAttribute('href', /admin\.php\?page=slimheatmap$/);

  const style = () => link.evaluate((a) => {
    const s = getComputedStyle(a);
    return { fill: s.backgroundColor, border: s.borderTopWidth, label: getComputedStyle(a.querySelector('.ab-label')!).color, icon: getComputedStyle(a.querySelector('.ab-icon')!, '::before').color };
  });
  const rest = await style();
  expect(rest.fill, 'no fill at rest: the bar shows through').toBe('rgba(0, 0, 0, 0)');
  expect(rest.border, 'an outline marks it as a button').toBe('1px');
  const [lr, lg, lb] = rgb(rest.label);
  expect(Math.max(lr, lg, lb) - Math.min(lr, lg, lb), 'the label is the bar\'s own text colour, not red').toBeLessThan(30);
  const [ir, ig, ib] = rgb(rest.icon);
  expect(ir - Math.max(ig, ib), 'only the icon carries the brand colour').toBeGreaterThan(80);

  await link.hover();
  const hover = await style();
  expect(hover.label, 'hover uses the admin bar\'s own state colour').not.toBe(rest.label);
  await expect.poll(async () => (await style()).icon, { message: 'the icon follows the label on hover (after core\'s 0.1s colour transition)' }).toBe(hover.label);

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
