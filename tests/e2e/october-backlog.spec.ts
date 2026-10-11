import { getProState, requireProBooted } from './helpers/pro-state';
import { test, expect } from '@playwright/test';
import { runWordPressFixture } from './helpers/chart';
import { closeDb, snapshotSlimstatOptions, restoreSlimstatOptions, snapshotOption, restoreOption, setSlimstatOptions } from './helpers/setup';

test.afterAll(closeDb);

test('Tasks 1–4, 25: report dialogs keep focus, scroll and reading order', async ({ page }, info) => {
  // Emulate browsers that support dialog but do not dispatch beforetoggle.
  await page.addInitScript(() => window.addEventListener('beforetoggle', event => event.stopImmediatePropagation(), true));
  await page.goto('/wp-admin/admin.php?page=slimview5');
  await expect(page.locator('#slim_p3_03 .slimstat-acquisition__intro')).toContainText(/channels? - .*pageviews?/);
  const order = await page.locator('.meta-box-sortables > .postbox').evaluateAll(nodes => nodes.map(n => n.id));
  expect(order.slice(0, 2)).toEqual(['slim_p3_03', 'slim_p3_04']);
  const trigger = page.getByRole('button', { name: 'What are channels?', exact: true });
  await trigger.scrollIntoViewIfNeeded();
  const before = await page.evaluate(() => window.scrollY);
  await trigger.click();
  const dialog = page.locator('dialog[id^=ss-acquisition-help-channels-]');
  await expect(dialog).toBeVisible();
  await expect(page.locator('html')).toHaveClass(/ss-report-dialog-open/);
  for (let i = 0; i < 4; i++) await page.keyboard.press('Tab');
  expect(await dialog.evaluate(el => el.contains(document.activeElement))).toBeTruthy();
  await page.keyboard.press('Escape');
  await expect(trigger).toBeFocused();
  expect(await page.evaluate(() => window.scrollY)).toBe(before);
  await page.screenshot({ path: info.outputPath('traffic-sources.png'), fullPage: true });
});

test('Tasks 12–16: Heatmaps boundaries, device denominator, polling, date alignment and settings', async ({ page }, info) => {
  let requests = 0;
  await page.route(/heatmap(%2F|\/)pages/, async route => {
    requests++;
    const row = { page: '/', title: '', clicks: 29, devices: [10, 5, 10], pageviews: 50, full: true, dead: 0, rage: 0, scroll: 40, last: 1, lastText: 'Today', url: '/wp-admin/admin.php?page=slimheatmap&heatmap=/' };
    await route.fulfill({ json: { rows: [row, { ...row, page: '/pricing/', clicks: 30 }], ever: true, updated: Math.floor(Date.now() / 1000) - 61 } });
  });
  await page.goto('/wp-admin/admin.php?page=slimheatmap');
  await expect(page.locator('.ss-hm-table tbody tr')).toHaveCount(2);
  await expect(page.locator('.ss-hm-low-data')).toHaveCount(1);
  await expect(page.locator('.ss-hm-table')).toContainText('Homepage');
  await expect(page.locator('.ss-hm-table')).toContainText('Unknown');
  await expect(page.locator('.ss-hm-heat')).toHaveCount(0);
  const count = requests;
  await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
  await expect.poll(() => requests).toBe(count + 1);
  await page.locator('[name=q]').fill('pricing');
  await page.locator('.ss-hm-refresh').click();
  await expect(page.locator('[name=q]')).toHaveValue('pricing');
  await expect(page.locator('.ss-hm-table tbody tr')).toHaveCount(1);
  for (const width of [1280, 390]) {
    await page.setViewportSize({ width, height: 900 });
    for (const dir of ['ltr', 'rtl']) {
      await page.evaluate(dir => document.documentElement.dir = dir, dir);
      await page.locator('.slimstat-date-range-btn').click();
      const menu = page.locator('.daterangepicker:visible');
      await expect(menu).toBeVisible();
      // Two calendars make the mobile popup taller than the viewport before clamping.
      await menu.locator('.ranges li').last().click();
      await expect(menu).toHaveClass(/custom-range-active/);
      await page.waitForTimeout(50);
      const box = await menu.boundingBox();
      expect(box!.x).toBeGreaterThanOrEqual(0);
      expect(box!.x + box!.width).toBeLessThanOrEqual(width + 1);
      const button = await page.locator('.slimstat-date-range-btn').boundingBox();
      const desired = dir === 'rtl' ? button!.x + button!.width - box!.width : button!.x;
      expect(Math.abs(box!.x - Math.max(8, Math.min(desired, width - box!.width - 8)))).toBeLessThanOrEqual(1);
      const toolbar = await page.locator('#wpadminbar').boundingBox();
      expect(box!.y).toBeGreaterThanOrEqual(Math.max(8, toolbar!.y + toolbar!.height + 8));
      expect(box!.y + box!.height).toBeLessThanOrEqual(page.viewportSize()!.height - 7);
      expect(await menu.locator('.ranges li').first().evaluate(first => {
        const rect = first.getBoundingClientRect();
        return document.elementFromPoint(rect.x + rect.width / 2, rect.y + rect.height / 2)?.closest('.ranges li') === first;
      })).toBe(true);
      await page.screenshot({ path: info.outputPath(`heatmaps-${width}-${dir}.png`) });
      // A clamped popup may cover its toggle; Cancel must stay reachable inside it.
      await menu.getByRole('button', { name: 'Cancel', exact: true }).click();
      await expect(page.locator('.daterangepicker')).toBeHidden();
      await expect(page.locator('.slimstat-date-range-btn')).toHaveAttribute('aria-expanded', 'false');
    }
  }
  if ((await getProState(page)).pro_installed) {
    await page.getByRole('link', { name: 'Heatmap settings', exact: true }).click();
    await expect(page.locator('#heatmap_capture')).toBeFocused();
  } else {
    await expect(page.getByRole('link', { name: 'Heatmap settings', exact: true })).toHaveCount(0);
  }
});

test('Tasks 19, 23: email search retains hidden selections and header notifications stay closed', async ({ page }, info) => {
  await requireProBooted(page);
  test.setTimeout(120000);
  await snapshotSlimstatOptions(); await snapshotOption('wp_slimstat_notifications');
  await setSlimstatOptions({ display_notifications: 'on' });
  try {
  await page.goto('/wp-admin/admin.php?page=slimemail');
  const picks = page.locator('[name="email_report_pick[]"]');
  expect(await picks.count()).toBeGreaterThan(10);
  await picks.first().check();
  const id = await picks.first().getAttribute('value');
  await page.locator('#ss-email-search').fill('no-such-report');
  await expect(page.locator('#ss-email-no-results')).toBeVisible();
  await expect(page.locator(`[name="email_report_pick[]"][value="${id}"]`)).toBeChecked();
  await page.getByRole('button', { name: 'Save Changes', exact: true }).click();
  await expect(page.locator(`[name="email_report_pick[]"][value="${id}"]`)).toBeChecked();
  await page.locator('#ss-email-search').fill('');
  for (const group of await page.locator('[data-email-group]').all()) {
    const names = (await group.locator('label').allTextContents()).map(s => s.trim());
    expect(names).toEqual([...names].sort(new Intl.Collator('en-US').compare));
  }
  await page.screenshot({ path: info.outputPath('email-reports.png'), fullPage: true });
  for (const populated of [false, true]) {
    runWordPressFixture(`<?php update_option('wp_slimstat_notifications', ['data' => ${populated ? "[['id'=>98765,'title'=>'Backlog test notice','description'=>'\"Test only\"','icon'=>'null','background_color'=>'info','activated_at'=>'2026-10-01','dismiss'=>false]]" : '[]'}]);`);
  for (const screen of ['slimview1', 'slimview2', 'slimview3', 'slimview4', 'slimview5', 'slimview6', 'slimview7', 'slimheatmap', 'slimemail', 'slimshortcodes', 'slimpro', 'slimconfig', 'slimstat_migration']) {
    await page.goto('/wp-admin/admin.php?page=' + screen);
    await expect(page.locator('.slimstat-notification-sidebar')).toHaveCount(1);
    await expect(page.locator('.slimstat-notification-sidebar')).toBeHidden();
    await page.locator('.js-slimstat-open-notification').first().click();
    await expect(page.locator('#tab-1')).toBeVisible();
    await expect(page.locator('#tab-2')).toBeHidden();
    await page.locator('[data-tab=tab-2]').click();
    await expect(page.locator('#tab-2')).toBeVisible();
    await expect(page.locator('#tab-1')).toBeHidden();
    await page.getByRole('button', { name: 'Close notifications', exact: true }).click();
    if (screen === 'slimstat_migration') {
      await expect(page.locator('#slimstat-status-note')).toContainText('Database is up to date');
      await page.screenshot({ path: info.outputPath('migration.png'), fullPage: true });
    }
  }
  }
  await page.locator('.js-slimstat-open-notification').first().click();
  await page.locator('[data-tab=tab-1]').click();
  await page.locator('.slimstat-notification-sidebar__dismiss').first().click();
  await page.locator('[data-tab=tab-2]').click();
  await expect(page.locator('#tab-2')).toContainText('Backlog test notice');
  } finally { await restoreOption('wp_slimstat_notifications'); await restoreSlimstatOptions(); }
});

test('Task 20: chart previews render and redraw from the generated shortcode', async ({ page }, info) => {
  const errors: string[] = []; page.on('pageerror', e => errors.push(e.message));
  await page.goto('/wp-admin/admin.php?page=slimshortcodes');
  test.setTimeout(90000);
  const charts=['slim_p1_01','slim_p1_19_01','slim_p2_01','slim_p2_22_01','slim_p3_01','slim_p4_26_01'];
  for(const id of charts) {
    await page.locator('#ss-sc-code').fill(`[slimstat f="widget" w="${id}"]interval equals -7[/slimstat]`);
    await expect(page.locator('#ss-sc-preview canvas')).toBeVisible();
    await expect.poll(() => page.locator('#ss-sc-preview canvas').evaluate((el: HTMLCanvasElement) => !!(window as any).Chart.getChart(el))).toBeTruthy();
  }
  const code='[slimstat f="widget" w="slim_p1_01"]interval equals -30&&&country equals us[/slimstat]';
  await page.locator('#ss-sc-code').fill(code);
  await expect(page.locator('#ss-sc-preview #slimstat_chart_data_slim_p1_01')).toBeAttached();
  await expect.poll(() => page.locator('#ss-sc-preview canvas').evaluate((el: HTMLCanvasElement) => !!(window as any).Chart.getChart(el))).toBeTruthy();
  const data=await page.locator('#ss-sc-preview [data-data]').getAttribute('data-data');
  const id=Number(runWordPressFixture(`<?php echo wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'October shortcode fixture','post_content'=>'${code} [slimstat f="widget" w="slim_p3_03"][/slimstat] [slimstat f="widget" w="slim_p3_03"][/slimstat]']);`));
  try {
    const front=await page.context().newPage(); await front.goto('/?page_id='+id);
    await expect(front.locator('#slimstat_chart_slim_p1_01')).toBeVisible();
    await expect(front.locator('#slimstat_chart_data_slim_p1_01')).toHaveAttribute('data-data',data!);
    const dialogs=front.locator('dialog[id^=ss-acquisition-help-channels-]');
    await expect(dialogs).toHaveCount(2);
    const ids=await dialogs.evaluateAll(nodes=>nodes.map(node=>node.id));
    expect(new Set(ids).size).toBe(2);
    await front.getByRole('button',{name:'What are channels?',exact:true}).nth(1).click();
    await expect(dialogs.nth(1)).toBeVisible();
    await expect(dialogs.nth(0)).not.toBeVisible();
    await front.close();
  } finally {runWordPressFixture(`<?php wp_delete_post(${id},true);`);}
  await page.locator('#ss-sc-preview .slimstat-granularity-select').selectOption('weekly');
  await expect(page.locator('#ss-sc-preview [data-granularity]')).toHaveAttribute('data-granularity','weekly');
  expect(errors).toEqual([]);
  await page.screenshot({ path: info.outputPath('shortcode-chart.png'), fullPage: true });
});

test('Task 15: polling pauses when hidden, serializes requests and recovers from failures',async({page})=>{
 await page.clock.install();
 let requests=0, fail=false, release:(()=>void)|undefined;
 await page.route(/heatmap(%2F|\/)pages/,async route=>{
  requests++;
  if (requests===2) await new Promise<void>(resolve=>release=resolve);
  if(fail) return route.fulfill({status:503,json:{message:'Fixture service unavailable'}});
  await route.fulfill({json:{rows:[],ever:true,updated:Math.floor(Date.now()/1000)-120}});
 });
 await page.goto('/wp-admin/admin.php?page=slimheatmap');
 await expect.poll(()=>requests).toBe(1);
 await page.evaluate(()=>Object.defineProperty(document,'hidden',{configurable:true,get:()=>true}));
 await page.clock.fastForward(120000); expect(requests).toBe(1);
 await page.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,get:()=>false});document.dispatchEvent(new Event('visibilitychange'));});
 await expect.poll(()=>requests).toBe(2);
 await page.clock.fastForward(120000); expect(requests).toBe(2);
 release!(); await expect(page.locator('.ss-hm-table')).not.toHaveAttribute('aria-busy','true');
 fail=true; await page.locator('.ss-hm-refresh').click();
 await expect(page.getByRole('button',{name:'Retry',exact:true})).toBeVisible();
 fail=false; await page.getByRole('button',{name:'Retry',exact:true}).click();
 await expect(page.getByRole('button',{name:'Retry',exact:true})).toBeHidden();
 expect(requests).toBe(4);
});
