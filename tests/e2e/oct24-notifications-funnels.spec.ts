import { test, expect } from '@playwright/test';
import { runWordPressFixture } from './helpers/chart';
import { closeDb, snapshotOption, restoreOption, snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOptions } from './helpers/setup';
import { seedFunnels, forceLimits, restoreDefaultLimits, clearAll } from './helpers/goals-funnels';
import { WP_ROOT } from './helpers/env';
import path from 'path';

test.afterAll(closeDb);

test('automatic delivery updates both badges once per minute, pauses hidden, and dismisses site-wide', async ({page}, info) => {
  await snapshotSlimstatOptions();
  const keys=['wp_slimstat_notifications','slimstat_notification_fetch_state','slimstat_notification_dismissed'];
  for (const key of keys) await snapshotOption(key);
  try {
    await setSlimstatOptions({display_notifications:'on'});
    runWordPressFixture(`<?php update_option('wp_slimstat_notifications',['data'=>[]]); update_option('slimstat_notification_fetch_state',['last_success'=>time(),'next_attempt'=>time()+DAY_IN_SECONDS]); delete_option('slimstat_notification_dismissed');`);
    await page.clock.install();
    let polls=0;
    page.on('request',r=>{if ((r.postData()||'').includes('action=slimstat_notification_status')) polls++;});
    await page.goto('/wp-admin/admin.php?page=slimview2');
    await expect(page.locator('.slimstat-header__badge')).toHaveCount(0);
    runWordPressFixture(`<?php delete_option('slimstat_notification_fetch_state'); add_filter('pre_http_request',static function($pre,$args,$url){ if(strpos($url,'/api/v1/notifications')===false)return $pre; return ['response'=>['code'=>200],'headers'=>[],'body'=>wp_json_encode(['data'=>[['id'=>987650,'title'=>'Automatically delivered fixture','description'=>'"Fixture body"','icon'=>'null','background_color'=>'info','activated_at'=>'2020-01-01']]])]; },10,3); do_action('slimstat_daily_cron_hook');`);
    await page.clock.fastForward(60000);
    await expect(page.locator('.slimstat-header__badge')).toHaveText('1');
    await expect(page.locator('.slimstat-menu-notification-count .plugin-count')).toHaveText('1');
    expect(polls).toBe(1);
    await page.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,value:true}); document.dispatchEvent(new Event('visibilitychange'));});
    await page.clock.fastForward(120000);
    expect(polls).toBe(1);
    await page.evaluate(()=>{Object.defineProperty(document,'hidden',{configurable:true,value:false}); document.dispatchEvent(new Event('visibilitychange'));});
    await page.clock.runFor(1);
    await expect.poll(()=>polls).toBe(2);
    await expect(page.locator('.slimstat-header__badge')).toHaveText('1');
    await page.clock.resume();
    await page.locator('.slimstat-header__icon--notifications').click();
    await expect(page.getByText('Automatically delivered fixture')).toBeVisible();
    await page.locator('.slimstat-notification-sidebar__menu').screenshot({path:info.outputPath('automatic-notification.png'),animations:'disabled',timeout:5000});
    await page.locator('.slimstat-notification-sidebar__dismiss').first().click();
    await expect(page.locator('.slimstat-header__badge')).toHaveCount(0);
    await expect(page.locator('.slimstat-menu-notification-count')).toHaveCount(0);
    await page.reload();
    await expect(page.locator('.slimstat-header__badge')).toHaveCount(0);
  } finally {
    for (const key of keys) await restoreOption(key);
    await restoreSlimstatOptions();
  }
});

test('funnel content retains inner margins with long titles, five steps, mobile and RTL', async ({page}, info) => {
  await snapshotSlimstatOptions();
  await snapshotOption('slimstat_funnels');
  const content=path.join(WP_ROOT,'wp-content');
  try {
    await setSlimstatOptions({async_load:'no'});
    await forceLimits(5,3,content);
    await seedFunnels([
      {name:'Long funnel title '.repeat(8),steps:Array.from({length:5},(_,i)=>({name:'Step '+i+' with a long label that must wrap',dimension:'resource',operator:'contains',value:'/oct24-step-'+i}))},
      {name:'Short funnel',steps:[{name:'Start',dimension:'resource',operator:'contains',value:'/oct24-start'},{name:'Finish',dimension:'resource',operator:'contains',value:'/oct24-finish'}]},
    ]);
    for(const width of [1440,390]) {
      await page.setViewportSize({width,height:900});
      await page.goto('/wp-admin/admin.php?page=slimview6');
      const card=page.locator('#slim_p9_02 .slimstat-gf-funnels');
      await expect(card).toBeVisible();
      for(const dir of ['ltr','rtl']) {
        await page.evaluate(dir=>document.documentElement.dir=dir,dir);
        const bounds=await card.evaluate(card=>{
          const box=card.getBoundingClientRect();
          return [...card.querySelectorAll('.slimstat-gf-funnel-panel:not([hidden]) .slimstat-gf-step__track')].map(node=>{const r=node.getBoundingClientRect();return {left:r.left-box.left,right:box.right-r.right};});
        });
        expect(bounds).toHaveLength(5);
        for(const b of bounds){expect(b.left).toBeGreaterThanOrEqual(15);expect(b.right).toBeGreaterThanOrEqual(15);}
        expect(await card.evaluate(el=>el.scrollWidth<=el.clientWidth+1)).toBe(true);
        await card.screenshot({path:info.outputPath(`funnels-${width}-${dir}.png`)});
      }
    }
  } finally {
    await restoreDefaultLimits(content);
    await restoreOption('slimstat_funnels');
    await restoreSlimstatOptions();
  }
});
