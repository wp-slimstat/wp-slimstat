import { test, expect } from '@playwright/test';
import { runWordPressFixture } from './helpers/chart';
import { closeDb, snapshotOption, restoreOption, snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOptions } from './helpers/setup';
import { seedFunnels, seedGoals, forceLimits, restoreDefaultLimits, clearAll } from './helpers/goals-funnels';
import { getProState } from './helpers/pro-state';
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

test('goals box keeps inner margins, a flat upsell and no footer gap on desktop, mobile and RTL', async ({page}, info) => {
  await snapshotSlimstatOptions();
  await snapshotOption('slimstat_goals');
  const content=path.join(WP_ROOT,'wp-content');
  try {
    await setSlimstatOptions({async_load:'no'});
    await restoreDefaultLimits(content);
    await seedGoals([{name:'Long goal title '.repeat(6),dimension:'resource',operator:'contains',value:'/?utm_source=facebook&utm_medium=paid_social&utm_campaign=fall-sale-2026&utm_content=video-15s'}]);
    const pro=await getProState(page);
    for(const width of [1440,390]) {
      await page.setViewportSize({width,height:900});
      await page.goto('/wp-admin/admin.php?page=slimview6');
      const box=page.locator('#slim_p9_01');
      await expect(box.locator('.slimstat-gf-goal')).toBeVisible();
      // Free at its one-goal cap shows the upsell; Pro has room for five.
      await expect(box.locator('.slimstat-gf-upsell')).toHaveCount(pro.pro_activated?0:1);
      for(const dir of ['ltr','rtl']) {
        await page.evaluate(dir=>document.documentElement.dir=dir,dir);
        const m=await box.evaluate((box,rtl)=>{
          const card=box.querySelector('.slimstat-gf-goals').getBoundingClientRect();
          const inset=el=>{const r=el.getBoundingClientRect();return {left:r.left-card.left,right:card.right-r.right};};
          const blocks=[...box.querySelectorAll('.slimstat-gf-goal, .slimstat-gf-upsell')];
          const title=box.querySelector('h3 .slimstat-nowrap').getBoundingClientRect();
          const range=document.createRange();range.selectNodeContents(box.querySelector('.slimstat-gf-postbox-subtitle'));
          const sub=range.getBoundingClientRect();
          const nested=box.querySelector('.slimstat-gf-upsell .slimstat-pro-adds');
          const style=nested&&getComputedStyle(nested);
          return {
            insets:blocks.map(inset),
            subtitleOffset:rtl?title.right-sub.right:sub.left-title.left,
            footerGap:box.getBoundingClientRect().bottom-blocks[blocks.length-1].getBoundingClientRect().bottom,
            nestedCard:!!style&&(style.borderTopWidth!=='0px'||style.backgroundColor!=='rgba(0, 0, 0, 0)'),
            overflow:box.scrollWidth>box.clientWidth+1,
          };
        },dir==='rtl');
        for(const b of m.insets){expect(b.left).toBeGreaterThanOrEqual(15);expect(b.right).toBeGreaterThanOrEqual(15);}
        expect(Math.abs(m.subtitleOffset)).toBeLessThanOrEqual(2);
        expect(m.footerGap).toBeLessThanOrEqual(24);
        expect(m.nestedCard).toBe(false);
        expect(m.overflow).toBe(false);
        await box.screenshot({path:info.outputPath(`goals-${width}-${dir}.png`)});
      }
    }
  } finally {
    await restoreOption('slimstat_goals');
    await restoreSlimstatOptions();
  }
});

test('locked funnels preview shows the funnel shape and one flat, unclipped pitch on desktop, mobile and RTL', async ({page}, info) => {
  await snapshotSlimstatOptions();
  const content=path.join(WP_ROOT,'wp-content');
  try {
    await setSlimstatOptions({async_load:'no'});
    await forceLimits(1,0,content);
    for(const width of [1440,390]) {
      await page.setViewportSize({width,height:900});
      await page.goto('/wp-admin/admin.php?page=slimview6');
      const box=page.locator('#slim_p9_02');
      await expect(box.locator('.slimstat-gf-funnels--locked')).toBeVisible();
      await expect(box).not.toContainText('Example funnel');
      for(const dir of ['ltr','rtl']) {
        await page.evaluate(dir=>document.documentElement.dir=dir,dir);
        const m=await box.evaluate(box=>{
          const lock=box.querySelector('.slimstat-gf-funnel-lock');
          const mock=box.querySelector('.slimstat-gf-funnel-mock');
          const adds=box.querySelector('.slimstat-gf-funnel-lock__overlay .slimstat-pro-adds');
          const pitch=adds.querySelector('p').getBoundingClientRect();
          const last=[...lock.querySelectorAll('.slimstat-gf-funnel-lock__overlay *')].pop().getBoundingClientRect();
          const s=getComputedStyle(adds);
          return {
            blur:parseFloat((getComputedStyle(mock).filter.match(/blur\(([\d.]+)px\)/)||[0,99])[1]),
            paragraphs:box.querySelectorAll('.slimstat-gf-funnel-lock__overlay p').length,
            nestedCard:s.borderTopWidth!=='0px'||s.backgroundColor!=='rgba(0, 0, 0, 0)',
            pitchOnBars:pitch.top<mock.getBoundingClientRect().bottom-1,
            clipped:last.bottom>lock.getBoundingClientRect().bottom+1||lock.scrollHeight>lock.clientHeight+1,
            overflow:box.scrollWidth>box.clientWidth+1,
          };
        });
        expect(m.blur).toBeLessThanOrEqual(1.5);
        expect(m.paragraphs).toBe(1);
        expect(m.nestedCard).toBe(false);
        expect(m.pitchOnBars).toBe(false);
        expect(m.clipped).toBe(false);
        expect(m.overflow).toBe(false);
        await box.screenshot({path:info.outputPath(`locked-funnels-${width}-${dir}.png`)});
      }
    }
  } finally {
    await restoreDefaultLimits(content);
    await restoreSlimstatOptions();
  }
});
