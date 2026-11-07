import { test, expect } from '@playwright/test';

test('shared filters survive reports → Heatmaps → reports and reload @compat', async ({ page }) => {
  const query = new URLSearchParams({ page: 'slimview2', type: 'last_7_days', 'fs[country]': 'equals us', 'fs[traffic_source]': 'contains newsletter+50% & sale' });
  await page.goto('/wp-admin/admin.php?' + query);
  const country = page.locator('#slimstat-filters-form input[name="fs[country]"]');
  const source = page.locator('#slimstat-filters-form input[name="fs[traffic_source]"]');
  await expect(country).toHaveValue('equals us');
  await page.locator('#toplevel_page_slimview1 a[href*="page=slimview3"]').click();
  await expect(country).toHaveValue('equals us');
  const request = page.waitForRequest(r => decodeURIComponent(r.url()).includes('/slimstat/v1/heatmap/pages'));
  await page.locator('#toplevel_page_slimview1 a[href*="page=slimheatmap"]').click();
  expect(new URL((await request).url()).searchParams.get('fs')).toContain('country equals us');
  await expect(country).toHaveValue('equals us');
  await expect(source).toHaveValue('contains newsletter+50% & sale');
  await page.reload();
  await expect(country).toHaveValue('equals us');
  await expect(source).toHaveValue('contains newsletter+50% & sale');
  await page.locator('#toplevel_page_slimview1 a[href*="page=slimview2"]').click();
  await expect(country).toHaveValue('equals us');
  await expect(source).toHaveValue('contains newsletter+50% & sale');
});

test('unsupported Heatmaps filters remain selected and are disclosed @compat', async ({ page }) => {
  const query = new URLSearchParams({ page: 'slimheatmap', 'fs[addon_ecommerce_currency]': 'equals USD' });
  await page.goto('/wp-admin/admin.php?' + query);
  await expect(page.locator('input[name="fs[addon_ecommerce_currency]"]')).toHaveValue('equals USD');
  await expect(page.locator('.ss-hm-filter-notice')).toContainText('not applied here');
});
