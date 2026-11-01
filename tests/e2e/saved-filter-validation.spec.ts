import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { BASE_URL, WP_ROOT } from './helpers/env';
import { runWordPressFixture } from './helpers/chart';
import { snapshotOption, restoreOption, closeDb } from './helpers/setup';

test('saved filters validate the whole entry and preserve valid save/list/apply values', async ({ page }) => {
  await page.setViewportSize({ width: 1600, height: 900 });
  const extension = path.join(WP_ROOT, 'wp-content/mu-plugins/saved-filter-column-test.php');
  await snapshotOption('slimstat_filters');
  fs.writeFileSync(extension, `<?php
    if (!defined('SLIMSTAT_E2E_TESTING') || !SLIMSTAT_E2E_TESTING) return;
    add_filter('slimstat_column_names', function($columns) {
      $columns['id'] = ['Registered row ID', 'int'];
      return $columns;
    });`);
  const stored = () => JSON.parse(runWordPressFixture(`<?php echo wp_json_encode(get_option('slimstat_filters', []));`));
  try {
    runWordPressFixture(`<?php update_option('slimstat_filters', []);`);
    await page.goto('/wp-admin/admin.php?page=slimview5');
    const security = await page.locator('#meta-box-order-nonce').inputValue();
    const manage = (form: Record<string, string>) => page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
      form: { action: 'slimstat_manage_filters', security, page: 'slimview5', ...form },
    });
    for (const invalid of [
      { unknown: ['equals', 'x'] }, { '<img src=x>': ['equals', 'x'] },
      { addon_bad: ['unknown', 'x'] }, { no_filter_selected_2: ['equals', 'x'] },
      { resource: ['equals', []] }, { resource: ['equals', ''] }, { interval: ['is_empty', ''] },
      ...['%20', '+', '\\', '&&&', '%2520', '\nX', '%0AX'].map(value => ({ resource: ['equals', value] })),
      { utm_campaign: ['equals', '\nX'] }, { strtotime: ['equals', 'not-a-date'] },
    ]) {
      const response = await manage({ type: 'save', filter_array: JSON.stringify(invalid) });
      expect(response.status()).toBe(400);
    }
    expect(stored()).toEqual([]);

    const dateFilter = { strtotime: ['equals', '2026-01-15'] };
    expect((await manage({ type: 'save', filter_array: JSON.stringify(dateFilter) })).ok()).toBe(true);
    expect(await (await manage({ type: 'list' })).text()).toContain('fs%5Bday%5D=equals+15');
    expect((await manage({ type: 'delete', filter_id: '0' })).ok()).toBe(true);
    expect(stored()).toEqual([]);

    const literal = 'A&B + %20 "quoted" &amp; \\ literal';
    const valid = {
      utm_campaign: ['equals', literal], traffic_source: ['contains', literal],
      content_id: ['equals', 0], resource: ['is_not_empty', ''],
      interval: ['equals', -7], addon_ecommerce_currency: ['equals', 'USD'],
      id: ['is_greater_than', 0],
    };
    const saved = await manage({ type: 'save', filter_array: JSON.stringify(valid) });
    expect(saved.ok()).toBe(true);
    expect(await saved.text()).toContain('Saved');
    expect(stored()).toEqual([valid]);
    await page.reload(); // The button is only rendered when saved filters exist.
    const overlay = page.locator('#slim_filters_overlay');
    await page.locator('#slimstat-load-saved-filters').click();
    const link = overlay.locator('.slimstat-filter-link');
    await expect(link).toHaveCount(1);
    await expect(link).toContainText(literal);
    const href = new URL((await link.getAttribute('href'))!, BASE_URL);
    for (const [key, [operator, value]] of Object.entries(valid)) {
      expect(href.searchParams.get(`fs[${key}]`)).toBe(`${operator} ${value}`);
    }
    await link.click();
    for (const [key, [operator, value]] of Object.entries(valid)) {
      if (key === 'interval') continue; // date filters are re-rendered by the date normaliser, not from the applied fs[] value
      await expect(page.locator(`#slimstat-filters-form input[name="fs[${key}]"]`)).toHaveValue(`${operator} ${value}`);
    }

    const remaining = {
      23: { unknown: ['equals', 'x'] }, 29: [], 31: 'malformed', 37: valid,
      41: { resource: ['equals', '%20'] },
      43: { resource: ['equals', '/restricted'], country: ['equals', '+'] },
      47: { resource: ['equals', '&&&'] },
      53: { resource: ['equals', '\nX'] },
      59: { utm_campaign: ['equals', '\nX'] },
      61: { strtotime: ['equals', 'not-a-date'] },
    };
    const legacy = { 17: { resource: ['equals', '/restricted'], unknown: ['equals', 'x'] }, ...remaining };
    const encoded = Buffer.from(JSON.stringify(legacy)).toString('base64');
    runWordPressFixture(`<?php update_option('slimstat_filters', json_decode(base64_decode('${encoded}'), true));`);
    await page.locator('#slimstat-load-saved-filters').click();
    const deleteButton = (id: number) => overlay.locator(`.slimstat-delete-filter[data-filter-id="${id}"]`);
    for (const id of [17, 23, 29, 31, 41, 43, 47, 53, 59, 61]) {
      const row = deleteButton(id).locator('..');
      await expect(row).toContainText('Invalid filter data.');
      await expect(row.locator('.slimstat-filter-link')).toHaveCount(0);
    }
    await expect(overlay.locator('.slimstat-filter-link')).toHaveCount(1);
    expect(stored()).toEqual(legacy);
    await deleteButton(17).click();
    await expect(deleteButton(17)).toHaveCount(0);
    expect(stored()).toEqual(remaining);
  } finally {
    fs.rmSync(extension, { force: true });
    await restoreOption('slimstat_filters');
    await closeDb();
  }
});
