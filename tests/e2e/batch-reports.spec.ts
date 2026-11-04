import { test, expect } from '@playwright/test';
import { fileURLToPath } from 'url';
import { clearStatsTable, closeDb, seedPageviews, setSlimstatOptions, snapshotSlimstatOptions, restoreSlimstatOptions } from './helpers/setup';
import { runWordPressFixture } from './helpers/chart';
import { getProState } from './helpers/pro-state';

let plugins: string;
let range: { from: string; to: string; dt: number };
test.beforeAll(async () => {
  await snapshotSlimstatOptions();
  plugins = runWordPressFixture('<?php echo wp_json_encode(get_option("active_plugins", []));');
  range = JSON.parse(runWordPressFixture('<?php $now = wp_slimstat::now(); echo wp_json_encode(["from" => gmdate("Y-m-d", $now - 3 * 86400), "to" => gmdate("Y-m-d", $now - 86400), "dt" => $now - 86400]);'));
  await clearStatsTable();
  await seedPageviews({ count: 30, resourcePrefix: '/batch-page-', baseDt: range.dt });
  await setSlimstatOptions({ async_load: 'on', refresh_interval: '0', rows_to_show: '5', limit_results: '50', can_view: '', capability_can_view: 'manage_options' });
});
test.afterEach(() => {
  runWordPressFixture(`<?php update_option('active_plugins', json_decode(base64_decode('${Buffer.from(plugins).toString('base64')}'), true));`);
});
test.afterAll(async () => { await restoreSlimstatOptions(); await closeDb(); });

for (const pro of [true, false]) for (const failBatch of [false, true]) {
  test(`visible widgets load with Pro ${pro ? 'active' : 'inactive'} and batch ${failBatch ? 'failure' : 'success'}`, async ({ page }) => {
    test.setTimeout(180_000);
    if (!pro) runWordPressFixture('<?php deactivate_plugins("wp-slimstat-pro/wp-slimstat-pro.php", true);');
    const batches: string[][] = [];
    const singles = new Set<string>();
    const errors: string[] = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('request', request => {
      const data = new URLSearchParams(request.postData() || '');
      if (data.get('action') === 'slimstat_load_reports') batches.push(data.getAll('report_ids[]'));
      if (data.get('action') === 'slimstat_load_report') singles.add(data.get('report_id')!);
    });
    if (failBatch) await page.route('**/admin-ajax.php', async route => {
      const data = new URLSearchParams(route.request().postData() || '');
      if (data.get('action') === 'slimstat_load_reports') await route.fulfill({ status: 500, body: 'fixture batch failure' });
      else await route.continue();
    });
    for (const screen of ['slimview2', 'slimview3']) {
      await page.goto(`/wp-admin/admin.php?page=${screen}&type=custom&from=${range.from}&to=${range.to}`);
      const state = await getProState(page);
      if (state.pro_installed) expect(state.pro_booted).toBe(pro);
      const ids: string[] = await page.evaluate(() => {
        const free = (window as any).SlimStatAdminParams.batch_report_ids;
        return Array.from(document.querySelectorAll<HTMLElement>('div[id^="slim_p"]'))
          .filter(el => el.getClientRects().length && free.includes(el.id) && !el.querySelector('.chart-placeholder, .refresh-timer')).map(el => el.id);
      });
      expect(ids.length).toBeGreaterThan(3);
      for (const id of ids) {
        await page.locator('#' + id).scrollIntoViewIfNeeded();
        await expect.poll(() => batches.flat().includes(id), { timeout: 30_000 }).toBe(true);
        await expect.poll(() => page.evaluate(id => {
          const body = (window as any).SlimStatReportBody(id);
          return body.html().trim().length > 0 && body.find('.loading').length === 0;
        }, id), { timeout: 30_000 }).toBe(true);
        if (failBatch) expect(singles.has(id), `fallback for ${id}`).toBe(true);
      }
      if (pro && state.pro_installed && screen === 'slimview3') {
        await page.locator('#slim_p8_01').scrollIntoViewIfNeeded();
        await expect.poll(() => singles.has('slim_p8_01')).toBe(true);
        expect(batches.flat()).not.toContain('slim_p8_01');
      }
    }
    expect(batches.some(ids => ids.length > 1), 'nearby widgets must share a request').toBe(true);
    expect(errors).toEqual([]);
  });
}

test('all batch-owned reports preserve single-route HTML and reject unauthorized requests', async ({ page, browser }) => {
  test.setTimeout(180_000);
  await page.goto(`/wp-admin/admin.php?page=slimview2&type=custom&from=${range.from}&to=${range.to}`);
  const context = await page.evaluate(() => {
    const params: Record<string, string> = { page: 'slimview2', security: (document.querySelector('#meta-box-order-nonce') as HTMLInputElement).value };
    document.querySelectorAll<HTMLInputElement>('#slimstat-filters-form .slimstat-post-filter').forEach(el => { params[el.name] = el.value; });
    return { params, ids: (window as any).SlimStatAdminParams.batch_report_ids as string[] };
  });
  expect(context.ids.length).toBeGreaterThan(25);
  const requestBatch = async (ids: string[], security = context.params.security) => {
    const data = new URLSearchParams({ ...context.params, action: 'slimstat_load_reports', security });
    ids.forEach(id => { data.append('report_ids[]', id); data.set(`granularities[${id}]`, 'daily'); });
    return page.request.post('/wp-admin/admin-ajax.php', { headers: { 'content-type': 'application/x-www-form-urlencoded' }, data: data.toString() });
  };
  expect((await requestBatch([context.ids[0]], 'invalid')).status()).toBe(403);
  const anonymous = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  try {
    const response = await anonymous.request.post(page.url().split('/wp-admin/')[0] + '/wp-admin/admin-ajax.php', {
      form: { action: 'slimstat_load_reports', 'report_ids[]': context.ids[0], security: context.params.security },
    });
    expect(response.status()).toBeGreaterThanOrEqual(400);
  } finally { await anonymous.close(); }
  const author = await browser.newContext({ storageState: fileURLToPath(new URL('./.auth/author.json', import.meta.url)) });
  try {
    const authorPage = await author.newPage();
    await authorPage.goto(page.url().split('/wp-admin/')[0] + '/wp-admin/index.php');
    const nonce = await authorPage.locator('#meta-box-order-nonce').inputValue();
    const response = await author.request.post(page.url().split('/wp-admin/')[0] + '/wp-admin/admin-ajax.php', {
      form: { action: 'slimstat_load_reports', 'report_ids[]': context.ids[0], security: nonce },
    });
    const denied = await response.text();
    // The admin bootstrap may deny the route before the per-item guard runs.
    expect(denied === '0' || (response.ok() && Object.keys(JSON.parse(denied).data).length === 0)).toBe(true);
  } finally { await author.close(); }
  const invalid = await (await requestBatch(['unknown', 'slim_p8_01', context.ids[0], context.ids[0]])).json();
  expect(Object.keys(invalid.data)).toEqual([context.ids[0]]);
  const capped = await (await requestBatch(context.ids)).json();
  expect(Object.keys(capped.data)).toHaveLength(25);
  // Help-dialog identifiers must stay unique when several widgets share a PHP request.
  const normalize = (html: string) => html.replace(/ss-acquisition-help-(channels|utm)-\d+/g, 'ss-acquisition-help-$1-ID');
  for (let start = 0; start < context.ids.length; start += 25) {
    const ids = context.ids.slice(start, start + 25);
    const response = await requestBatch(ids);
    expect(response.ok()).toBe(true);
    const raw = await response.text();
    expect(raw, `JSON batch for ${ids.join(',')}`).toMatch(/^\{/);
    const body = JSON.parse(raw);
    expect(Object.keys(body.data)).toEqual(ids);
    for (const id of ids) {
      const single = await page.request.post('/wp-admin/admin-ajax.php', { form: { ...context.params, action: 'slimstat_load_report', report_id: id, granularity: 'daily' } });
      expect(single.ok()).toBe(true);
      const html = await single.text();
      expect(html, id).not.toMatch(/Fatal error|Warning:|nonce_failure/);
      expect(normalize(body.data[id]), id).toBe(normalize(html));
    }
  }
});

for (const partial of [true, false]) {
  test(partial ? 'a missing batch item retries only that report' : 'late batch HTML cannot overwrite manual pagination', async ({ page }) => {
    const id = 'slim_p1_08';
    await page.goto(`/wp-admin/admin.php?page=slimview2&type=custom&from=${range.from}&to=${range.to}`);
    const panel = page.locator('#' + id);
    await panel.scrollIntoViewIfNeeded();
    await expect(panel.locator('.pagination')).toContainText('1–5 of 30');
    await expect(panel.locator('.inside .loading')).toHaveCount(0);
    const singles: string[] = [];
    page.on('request', request => {
      const data = new URLSearchParams(request.postData() || '');
      if (data.get('action') === 'slimstat_load_report') singles.push(data.get('report_id')!);
    });
    let received!: () => void;
    const started = new Promise<void>(resolve => { received = resolve; });
    let release!: () => void;
    const held = new Promise<void>(resolve => { release = resolve; });
    await page.route('**/admin-ajax.php', async route => {
      const data = new URLSearchParams(route.request().postData() || '');
      if (data.get('action') !== 'slimstat_load_reports' || !data.getAll('report_ids[]').includes(id)) return route.continue();
      const response = await route.fetch();
      const json = await response.json();
      received();
      if (partial) delete json.data[id];
      else await held;
      await route.fulfill({ response, json });
    });
    try {
      await page.evaluate(id => { (window as any).batchTestDone = false; (window as any).SlimStatAdmin.refresh_reports([id]).always(() => { (window as any).batchTestDone = true; }); }, id);
      await started;
      if (partial) {
        await expect.poll(() => singles.filter(item => item === id).length).toBe(1);
        await expect(panel.locator('.pagination')).toContainText('1–5 of 30');
      } else {
        await panel.locator('.pagination a.slimstat-font-angle-right').click();
        await expect(panel.locator('.pagination')).toContainText('6–10 of 30');
        await expect(panel.locator('.inside .loading')).toHaveCount(0);
        release();
      }
      await expect.poll(() => page.evaluate(() => (window as any).batchTestDone)).toBe(true);
      await expect(panel.locator('.inside .loading')).toHaveCount(0);
      await expect(panel.locator('.pagination')).toContainText(partial ? '1–5 of 30' : '6–10 of 30');
    } finally { release(); }
  });
}
