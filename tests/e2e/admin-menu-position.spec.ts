/**
 * The SlimStat menu sits directly above Appearance. add_menu_page() gets position 59, the
 * separator's slot, so core moves SlimStat to a free 59.xxxxx between the separator and
 * Appearance (60). Without a position it lands below Settings; at 58 it shared WooCommerce's
 * Marketing slot and could land before the separator.
 *
 * Read-only: no options or stats rows are touched.
 */
import { test, expect } from '@playwright/test';

test('SlimStat sits between the separator and Appearance', async ({ page }) => {
  await page.goto('/wp-admin/index.php');
  const order = await page.locator('#adminmenu > li').evaluateAll((items) =>
    items.map((li) => (li.classList.contains('wp-menu-separator') ? '---' : li.id)),
  );
  const slimstat = order.findIndex((id) => id.startsWith('toplevel_page_slim'));

  expect(slimstat, order.join(' > ')).toBeGreaterThan(0);
  expect(order[slimstat - 1], order.join(' > ')).toBe('---');
  expect(order[slimstat + 1], order.join(' > ')).toBe('menu-appearance');
});
