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
  const navigation = page.waitForRequest(r => r.isNavigationRequest() && r.url().includes('page=slimheatmap'));
  await page.locator('#toplevel_page_slimview1 a[href*="page=slimheatmap"]').click();
  expect(new URL((await navigation).url()).searchParams.get('fs[country]')).toBe('equals us');
  expect(new URL((await request).url()).searchParams.get('fs')).toContain('country equals us');
  await expect(country).toHaveValue('equals us');
  await expect(source).toHaveValue('contains newsletter+50% & sale');
  await page.reload();
  await expect(country).toHaveValue('equals us');
  await expect(source).toHaveValue('contains newsletter+50% & sale');
  await page.locator('#toplevel_page_slimview1 a[href*="page=slimview2"]').click();
  await expect(country).toHaveValue('equals us');
  await expect(source).toHaveValue('contains newsletter+50% & sale');
  await page.goBack();
  await expect(page).toHaveURL(/page=slimheatmap/);
  await expect(country).toHaveValue('equals us');
  await expect(source).toHaveValue('contains newsletter+50% & sale');
  await page.goForward();
  await expect(page).toHaveURL(/page=slimview2/);
  await expect(country).toHaveValue('equals us');
  await expect(source).toHaveValue('contains newsletter+50% & sale');
});

test('Heatmaps keep the reports date range through tab navigation @compat', async ({ page }, testInfo) => {
  const label = page.locator('.slimstat-date-range-btn .date-label');
  const window = page.locator('.slimstat-date-range-input');
  const browser = page.locator('#slimstat-filters-form input[name="fs[browser]"]');
  for (const [query, text] of [[{ type: 'last_6_months' }, 'Last 6 months'], [{ type: 'custom', from: '2026-06-01', to: '2026-07-15' }, null]] as const) {
    await page.goto('/wp-admin/admin.php?' + new URLSearchParams({ page: 'slimview2', ...query, 'fs[browser]': 'equals Firefox' }));
    const [start, end] = [await window.getAttribute('data-start'), await window.getAttribute('data-end')];
    const shown = (await label.textContent())!.replace(/\s+/g, ' ').trim();
    if (text) expect(shown).toContain(text);
    if ('from' in query) expect([start, end]).toEqual([query.from, query.to]);
    await page.locator('#toplevel_page_slimview1 a[href*="page=slimview3"]').click();
    await expect(label).toHaveText(shown);
    const request = page.waitForRequest(r => decodeURIComponent(r.url()).includes('/slimstat/v1/heatmap/pages'));
    await page.locator('#toplevel_page_slimview1 a[href*="page=slimheatmap"]').click();
    const sent = new URL((await request).url()).searchParams;
    expect([sent.get('from'), sent.get('to')], 'Heatmaps query the reports window').toEqual([start, end]);
    expect(sent.get('fs')).toContain('browser equals Firefox');
    await expect(label).toHaveText(shown);
    await expect(browser).toHaveValue('equals Firefox');
    if (text) await page.screenshot({ path: process.env.E2E_SHOTS_DIR ? `${process.env.E2E_SHOTS_DIR}/heatmaps-six-months.png` : testInfo.outputPath('heatmaps-six-months.png') });
    await page.reload();
    await expect(label).toHaveText(shown);
    await page.locator('#toplevel_page_slimview1 a[href*="page=slimview2"]').click();
    await expect(label).toHaveText(shown);
    await expect(browser).toHaveValue('equals Firefox');
  }
});

test('AI Analysis, Ecommerce and filter suggestions keep the reports date range and filters @compat', async ({ page }) => {
  const window = page.locator('.slimstat-date-range-input');
  const browser = page.locator('#slimstat-filters-form input[name="fs[browser]"]');
  const menu = page.locator('#toplevel_page_slimview1');
  await page.goto('/wp-admin/admin.php?' + new URLSearchParams({ page: 'slimview2', type: 'last_6_months', 'fs[browser]': 'equals Firefox' }));
  const range = [await window.getAttribute('data-start'), await window.getAttribute('data-end')];
  // Only the rendered window may decide; a remembered picker choice must not mask a lost one.
  await page.evaluate(() => sessionStorage.removeItem('slimstat_date_range'));
  await menu.locator('a[href*="page=slimai"]').click();
  await expect(page).toHaveURL(/page=slimai/);
  // Ecommerce is listed only with WooCommerce; any report proves the hand-off.
  const target = (await menu.locator('a[href*="page=slimview7"]').count()) ? 'slimview7' : 'slimview3';
  await menu.locator(`a[href*="page=${target}"]`).click();
  await expect(page).toHaveURL(new RegExp('page=' + target));
  await expect(browser).toHaveValue('equals Firefox');
  expect([await window.getAttribute('data-start'), await window.getAttribute('data-end')], 'leaving a page without the filter bar keeps the window').toEqual(range);
  const options = page.waitForRequest(r => (r.postData() || '').includes('slimstat_get_filter_options'));
  await page.locator('#slimstat-filter-name').selectOption('browser');
  const sent = new URLSearchParams((await options).postData()!);
  expect([sent.get('time_range_from'), sent.get('time_range_to')], 'filter suggestions use the window the reports show').toEqual(range);
});

test('unsupported Heatmaps filters remain selected and are disclosed @compat', async ({ page }) => {
  const query = new URLSearchParams({ page: 'slimheatmap', 'fs[addon_ecommerce_currency]': 'equals USD' });
  await page.goto('/wp-admin/admin.php?' + query);
  await expect(page.locator('input[name="fs[addon_ecommerce_currency]"]')).toHaveValue('equals USD');
  await expect(page.locator('.ss-hm-filter-notice')).toContainText('not applied here');
});
