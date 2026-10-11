import { requireProBooted } from './helpers/pro-state';
import {test,expect} from '@playwright/test';
import {runWordPressFixture} from './helpers/chart';
import {snapshotOption,restoreOption,snapshotSlimstatOptions,restoreSlimstatOptions,setSlimstatOptions,closeDb} from './helpers/setup';

test('Task 18: missing-theme explanation, fallback context and retry into standard-theme click/scroll previews',async({page},info)=>{
 test.setTimeout(90000);
 await requireProBooted(page);
 await snapshotOption('template'); await snapshotOption('stylesheet'); await snapshotSlimstatOptions();
 await setSlimstatOptions({addon_heatmap_enable:'on'});
 await page.context().route(url=>decodeURIComponent(url.href).includes('/slimstat/v1/heatmap/view'),async route=>{
  const response=await route.fetch(); const json=await response.json();
  json.views={desktop:1,tablet:1,mobile:1}; json.clicks.bins=[{s:'body a',l:'Recorded link',n:1,d:0,r:0,f:0,rx:5000,ry:5000,x:10,y:10,vw:1280}]; json.clicks.legacy=[];
  json.scroll={...json.scroll,views:1,fold:30,half:60};
  await route.fulfill({response,json});
 });
 try {
  runWordPressFixture("<?php update_option('template','slimstats'); update_option('stylesheet','slimstats');");
  const direct=await page.request.get('/'); expect(await direct.text()).toContain('does not exist');
  await page.goto('/wp-admin/admin.php?page=slimheatmap&heatmap=%2F&from=2026-10-01&to=2026-10-07');
  await expect(page.locator('.ss-hmv-state')).toContainText('configured theme is missing',{timeout:15000});
  const fallback=page.getByRole('link',{name:'Open heatmap on the page',exact:true});
  await expect(fallback).toHaveAttribute('href',/2026-10-01/);
  const fallbackUrl=(await fallback.getAttribute('href'))!;
  const context=new URLSearchParams(new URL(fallbackUrl).searchParams.get('slimstat_heatmap_view')!);
  expect(context.get('from')).toBe('2026-10-01'); expect(context.get('to')).toBe('2026-10-07'); expect(context.get('device')).toBe('desktop');
  await page.screenshot({path:info.outputPath('missing-theme-recovery.png'),fullPage:true});
  runWordPressFixture("<?php switch_theme('twentytwentyfive');");
  expect((await page.request.get('/')).ok()).toBeTruthy();
  const outside=await page.context().newPage(); await outside.goto(fallbackUrl);
  await expect(outside.locator('.ss-hmo')).toBeVisible();
  await outside.screenshot({path:info.outputPath('fallback-overlay.png')}); await outside.close();
  await page.getByRole('button',{name:'Retry preview',exact:true}).click();
  for (const device of ['desktop','tablet','mobile']) {
    await page.locator('.ss-hmv-devices button[data-device='+device+']').click();
    await expect(page.locator('.ss-hmv-frame')).not.toHaveClass(/is-loading/,{timeout:15000});
    await expect(page.frameLocator('.ss-hmv-frame iframe').locator('.ss-hmo')).toBeVisible({timeout:15000});
    await page.locator('.ss-hmv-show input[value=scroll]').check({force:true});
    await expect(page.frameLocator('.ss-hmv-frame iframe').getByText('Average fold',{exact:true})).toBeVisible();
    await page.screenshot({path:info.outputPath('preview-'+device+'.png')});
    await page.locator('.ss-hmv-show input[value=clicks]').check({force:true});
  }
 } finally {await restoreOption('template'); await restoreOption('stylesheet'); await restoreSlimstatOptions(); await closeDb();}
});
