/**
 * E2E (Fix 5): SlimStat's admin CSS styles WP-standard / bundled-library
 * selectors (.form-table, .ui-datepicker, .qtip) ONLY under body.slimstat-admin-page.
 *
 * admin.css also loads on the WP Dashboard and the post-list screen (neither
 * carries that body class), so an unscoped rule would restyle other plugins'
 * UI there. This proves the scoping deterministically on a single SlimStat page
 * (where admin.css is loaded): the same injected element is styled by SlimStat
 * WHEN the body class is present (no self-break on SlimStat's own pages) and is
 * NOT styled when the class is removed (no leak onto other admin screens).
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';

test.describe('Admin CSS selector scoping — no leak / no self-break (Fix 5)', () => {
  test('WP-standard + bundled selectors are styled only under body.slimstat-admin-page', async ({ page }) => {
    // A SlimStat report page: admin.css is enqueued and body.slimstat-admin-page is set.
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('body.slimstat-admin-page')).toHaveCount(1);

    const probe = await page.evaluate(() => {
      const had = document.body.classList.contains('slimstat-admin-page');

      // Measure a slimstat-specific property on a freshly-injected element with
      // the body scope on, then off. SlimStat sets: .ui-datepicker{border-radius:8px},
      // .form-table{overflow:hidden}, .qtip{ a non-default max-width via the lib }.
      function measure(withScope: boolean) {
        document.body.classList.toggle('slimstat-admin-page', withScope);

        const dp = document.createElement('div');
        dp.className = 'ui-datepicker';
        const tbl = document.createElement('table');
        tbl.className = 'form-table';
        document.body.append(dp, tbl);

        const out = {
          dpRadius: getComputedStyle(dp).borderTopLeftRadius,
          tableOverflow: getComputedStyle(tbl).overflowX,
        };
        dp.remove();
        tbl.remove();
        return out;
      }

      const on = measure(true);
      const off = measure(false);
      document.body.classList.toggle('slimstat-admin-page', had); // restore
      return { on, off };
    });

    // No self-break: SlimStat styles its own widgets when the body class is present.
    expect(parseFloat(probe.on.dpRadius)).toBeGreaterThan(0); // .ui-datepicker → 8px
    expect(probe.on.tableOverflow).toBe('hidden'); // .form-table → overflow:hidden

    // No leak: the SAME selectors get no SlimStat styling without the body class
    // (i.e. on the dashboard / post-list, where the class is absent).
    expect(parseFloat(probe.off.dpRadius)).toBe(0); // jQuery UI default, not 8px
    expect(probe.off.tableOverflow).not.toBe('hidden');
  });
});
