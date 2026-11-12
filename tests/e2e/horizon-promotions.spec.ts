import { test, expect } from '@playwright/test';
import { runWordPressFixture } from './helpers/chart';
import { closeDb, snapshotOption, restoreOption, snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOptions } from './helpers/setup';

const keys = ['wp_slimstat_notifications', 'wp_slimstat_marketing_campaigns', 'slimstat_notification_dismissed'];
const seed = () => runWordPressFixture(`<?php
$now = time();
$item = ['id'=>902601,'title'=>'Black Friday: 30% off SlimStat Pro','icon'=>'"🏷️"','description'=>'"<p>Save 30% on your first payment.</p>"','background_color'=>'promo','extra_data'=>['badge'=>'🔥 -30%','code'=>'BFCM2026'],'primary_button'=>['title'=>'Get the deal','url'=>'https://example.test/pricing/?utm_campaign=bfcm2026'],'secondary_button'=>['title'=>'Must stay hidden','url'=>'https://example.test/'],'tags'=>['is-admin'],'starts_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$now-3600),'ends_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$now+3600)];
update_option('wp_slimstat_notifications',['data'=>[$item,array_merge($item,['id'=>902602,'title'=>'Upcoming hidden','starts_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$now+86400)]),array_merge($item,['id'=>902603,'title'=>'Expired hidden','ends_at'=>gmdate('Y-m-d\\TH:i:s\\Z',$now-1)])]],false);
update_option('wp_slimstat_marketing_campaigns',['data'=>[['id'=>902604,'type'=>'promo_banner','starts_at'=>$item['starts_at'],'ends_at'=>$item['ends_at'],'tags'=>['is-admin'],'data'=>['title'=>'Black Friday: 30% off','url'=>'https://example.test/pricing/?utm_campaign=bfcm2026','icon'=>'"🏷️"','tooltip'=>'30% off the first payment','background_color'=>'danger','text_color'=>'light']]]],false);
delete_option('slimstat_notification_dismissed');
`);

test.beforeEach(async () => {
  await snapshotSlimstatOptions();
  for (const key of keys) await snapshotOption(key);
  await setSlimstatOptions({ display_notifications: 'on' });
  seed();
});
test.afterEach(async () => {
  for (const key of keys) await restoreOption(key);
  await restoreSlimstatOptions();
});
test.afterAll(closeDb);

test('website links carry attribution while feed campaigns and other destinations stay intact', async ({page}) => {
  runWordPressFixture(`<?php
  $feed=get_option('wp_slimstat_marketing_campaigns');
  $feed['data'][0]['data']['url']='https://wp-slimstat.com/black-friday/?coupon=BFCM2026&utm_campaign=bfcm2026#plans';
  update_option('wp_slimstat_marketing_campaigns',$feed,false);
  $feed=get_option('wp_slimstat_notifications');
  $feed['data'][0]['primary_button']['url']='https://wp-slimstat.com/black-friday/?utm_campaign=bfcm2026';
  update_option('wp_slimstat_notifications',$feed,false);
  `);
  await page.goto('/wp-admin/admin.php?page=slimview1');
  const banner=page.locator('.slimstat-header__promo');
  if (await banner.count()) {
    const url=new URL((await banner.getAttribute('href'))!);
    expect(Object.fromEntries(url.searchParams)).toMatchObject({coupon:'BFCM2026',utm_campaign:'bfcm2026',utm_source:'wp-slimstat',utm_medium:'link',utm_content:'header-banner-902604'});
    expect(url.pathname).toBe('/black-friday/');
    expect(url.hash).toBe('#plans');
  }
  const notification=new URL((await page.locator('#tab-1 .slimstat-notification-sidebar__button').getAttribute('href'))!);
  expect(Object.fromEntries(notification.searchParams)).toMatchObject({utm_source:'wp-slimstat',utm_medium:'link',utm_campaign:'bfcm2026',utm_content:'primary-902601'});
  expect(notification.pathname).toBe('/black-friday/');
  await page.evaluate(()=>{
    const root=document.querySelector('.wrap-slimstat')!;
    for (const [id,href] of Object.entries({docs:'https://wp-slimstat.com/resources/?q=report#help',existing:'https://wp-slimstat.com/videos/?utm_source=horizon&utm_medium=email&utm_campaign=existing&utm_content=video#watch',other:'https://wp-slimstat.com.example.test/',local:'/wp-admin/admin.php?page=slimconfig'})) {
      const a=document.createElement('a');a.id='utm-test-'+id;a.href=href;a.textContent=id;root.append(a);
    }
  });
  await expect(page.locator('#utm-test-docs')).toHaveAttribute('href',/utm_content=content-link/);
  const docs=new URL((await page.locator('#utm-test-docs').getAttribute('href'))!);
  expect(docs.searchParams.get('q')).toBe('report');expect(docs.hash).toBe('#help');
  await expect(page.locator('#utm-test-existing')).toHaveAttribute('href','https://wp-slimstat.com/videos/?utm_source=horizon&utm_medium=email&utm_campaign=existing&utm_content=video#watch');
  await expect(page.locator('#utm-test-other')).toHaveAttribute('href','https://wp-slimstat.com.example.test/');
  await expect(page.locator('#utm-test-local')).toHaveAttribute('href','/wp-admin/admin.php?page=slimconfig');
  const missing=await page.locator('.wrap-slimstat a[href]').evaluateAll(links=>links.filter(link=>{
    const url=new URL((link as HTMLAnchorElement).href);
    return ['wp-slimstat.com','www.wp-slimstat.com','my.wp-slimstat.com'].includes(url.hostname) && ['utm_source','utm_medium','utm_campaign','utm_content'].some(key=>!url.searchParams.get(key));
  }).map(link=>(link as HTMLAnchorElement).href));
  expect(missing).toEqual([]);
});

test('promo renders one CTA, keeps full panel width in light, dark, mobile and RTL, and copies the coupon', async ({page}, info) => {
  await page.addInitScript(() => Object.defineProperty(navigator, 'clipboard', {configurable:true,value:{writeText: async (value: string) => { (window as any).copiedCoupon = value; }}}));
  await page.goto('/wp-admin/admin.php?page=slimview1');
  await expect(page.locator('.slimstat-header__notification-tag')).toHaveCount(0);
  await expect(page.locator('.slimstat-header__badge')).toHaveText('1');
  const proActive = runWordPressFixture('<?php echo wp_slimstat::pro_plugin_active() ? "yes" : "no";').trim() === 'yes';
  await expect(page.locator('.slimstat-header__promo')).toHaveCount(proActive ? 0 : 1);
  if (!proActive) {
    const pill = page.locator('.slimstat-header__promo');
    const color = await pill.evaluate(el => getComputedStyle(el).backgroundColor);
    await pill.hover();
    expect(await pill.evaluate(el => getComputedStyle(el).backgroundColor)).toBe(color);
    await expect(page.locator('.slimstat-header__account .slimstat-header__cta')).toBeVisible();
    await expect(page.getByRole('link', {name:'Have a license?',exact:true})).toBeVisible();
  }
  await page.locator('.js-slimstat-open-notification').click();
  await expect(page.locator('.slimstat-notification-sidebar__close')).toBeFocused();
  const card = page.locator('#tab-1 .slimstat-notification-sidebar__promo');
  await expect(card).toHaveCount(1);
  await expect(page.getByText('Upcoming hidden', {exact:true})).toHaveCount(0);
  await expect(page.getByText('Expired hidden', {exact:true})).toHaveCount(0);
  await expect(card.locator('.slimstat-notification-sidebar__button')).toHaveCount(1);
  await expect(card.locator('.slimstat-notification-sidebar__countdown')).toContainText('Ends in');
  await card.locator('.slimstat-notification-sidebar__copy').click();
  await expect.poll(() => page.evaluate(() => (window as any).copiedCoupon)).toBe('BFCM2026');
  await expect(card.getByRole('status')).toHaveText('Copied');
  for (const width of [1280, 390]) {
    await page.setViewportSize({width,height:900});
    for (const colorScheme of ['light','dark'] as const) {
      await page.emulateMedia({colorScheme});
      for (const dir of ['ltr','rtl']) {
        await page.evaluate(dir => {document.documentElement.dir=dir;document.body.classList.toggle('rtl',dir==='rtl');},dir);
        await expect(card).toBeVisible();
        const geometry = await card.evaluate(el => {
          const card=el.getBoundingClientRect(),panel=document.querySelector('.slimstat-notification-sidebar__menu')!.getBoundingClientRect();
          return {left:card.left-panel.left,width:card.width-panel.width,overflow:el.scrollWidth-el.clientWidth,background:getComputedStyle(el).backgroundImage};
        });
        expect(Math.abs(geometry.left)).toBeLessThan(1);
        expect(Math.abs(geometry.width)).toBeLessThan(1);
        expect(geometry.overflow).toBeLessThanOrEqual(1);
        expect(geometry.background).toContain('linear-gradient');
        await expect(page.locator('.slimstat-header__badge')).toHaveAttribute('dir','ltr');
        await card.screenshot({path:info.outputPath(`promo-${width}-${colorScheme}-${dir}.png`)});
      }
    }
  }
  await page.keyboard.press('Escape');
  await expect(page.locator('.js-slimstat-open-notification')).toBeFocused();
});

test('clipboard denial keeps a selectable code and gives honest feedback', async ({page}) => {
  await page.addInitScript(() => {
    Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async()=>{throw new Error('Denied');}}});
    document.execCommand=()=>false;
  });
  await page.goto('/wp-admin/admin.php?page=slimview1');
  await page.locator('.js-slimstat-open-notification').click();
  await page.locator('.slimstat-notification-sidebar__copy').click();
  await expect(page.locator('.slimstat-notification-sidebar__code-row [role="status"]')).toHaveText('Could not copy. Select the code and copy it manually.');
  expect(await page.evaluate(()=>window.getSelection()?.toString())).toBe('BFCM2026');
});

test('expiry removes the offer and counts while open, and preserves keyboard focus', async ({page}) => {
  await page.clock.install();
  await page.goto('/wp-admin/admin.php?page=slimview1');
  await page.locator('.js-slimstat-open-notification').click();
  const card = page.locator('#tab-1 .slimstat-notification-sidebar__promo');
  const end=await card.getAttribute('data-ends-at');
  const hasBanner = await page.locator('.slimstat-header__promo').count();
  if (hasBanner) {
    await page.keyboard.press('Escape');
    await page.locator('.slimstat-header__promo').focus();
  }
  await page.clock.pauseAt(new Date(Date.parse(end!)-1000));
  await page.evaluate(()=>document.dispatchEvent(new Event('visibilitychange')));
  await page.clock.runFor(1100);
  await expect(card).toHaveCount(0);
  await expect(page.locator('.slimstat-header__notification-tag')).toHaveCount(0);
  await expect(page.locator('.slimstat-header__badge')).toHaveCount(0);
  await expect(page.locator('.slimstat-menu-notification-count')).toHaveCount(0);
  await expect(page.locator('.slimstat-header__promo')).toHaveCount(0);
  if (hasBanner) {
    await expect(page.locator('.slimstat-header__cta')).toBeFocused();
    await page.locator('.js-slimstat-open-notification').click();
  }
  await expect(page.locator('#tab-1')).toContainText('No notifications in your inbox.');
});

test('restored pages and delayed timers cannot revive expired campaign links', async ({page}) => {
  const now = new Date();
  await page.clock.install({ time: now });
  for (const mode of ['pageshow', 'header', 'notification']) {
    await page.clock.setSystemTime(now);
    await page.goto('/wp-admin/admin.php?page=slimview1');
    const card = page.locator('#tab-1 .slimstat-notification-sidebar__promo');
    const banner = page.locator('.slimstat-header__promo');
    if (mode === 'header' && !await banner.count()) continue; // Active Pro has no header offer.
    await expect(card).toHaveCount(1);
    const end = await card.getAttribute('data-ends-at');
    await page.clock.setSystemTime(new Date(end!));
    if (mode === 'pageshow') {
      await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
    } else {
      const link = mode === 'header' ? banner : card.locator('.slimstat-notification-sidebar__button');
      expect(await link.evaluate(el => !el.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }))), 'expired navigation is canceled').toBe(true);
    }
    await expect(card).toHaveCount(0);
    await expect(banner).toHaveCount(0);
    await expect(page.locator('.slimstat-header__badge')).toHaveCount(0);
  }
});

test('dismiss all leaves future offers available and keyboard tabs work', async ({page}) => {
  await page.goto('/wp-admin/admin.php?page=slimview1');
  await page.locator('.js-slimstat-open-notification').click();
  await page.locator('.slimstat-notification-sidebar__dismiss-all').click();
  await expect(page.locator('.slimstat-header__badge')).toHaveCount(0);
  const stored=JSON.parse(runWordPressFixture('<?php echo wp_json_encode(get_option("slimstat_notification_dismissed"));'));
  expect(stored['902601']).toBe(true);
  expect(stored['902602']).toBeUndefined();
  expect(stored['902603']).toBeUndefined();
  await page.getByRole('tab',{name:'Inbox',exact:true}).focus();
  await page.keyboard.press('ArrowRight');
  await expect(page.getByRole('tab',{name:'Dismissed',exact:true})).toBeFocused();
  await expect(page.locator('#tab-2 .slimstat-notification-sidebar__promo')).toBeVisible();
});

test('small screens retain touch targets and contain long translated labels and coupon codes', async ({page}) => {
  await page.setViewportSize({width:320,height:900});
  await page.goto('/wp-admin/admin.php?page=slimview1');
  await page.evaluate(() => {
    const title = document.querySelector('.slimstat-header__promo > span:last-child');
    if (title) title.textContent = 'BlackFridayDiscount'.repeat(10);
    document.querySelector('.slimstat-notification-sidebar__copy')!.textContent = 'Copy this promotional discount coupon to the clipboard';
    document.querySelector('.slimstat-notification-sidebar__code-row code')!.textContent = 'LONGPROMOCODE'.repeat(5);
  });
  await page.locator('.js-slimstat-open-notification').click();
  for (const dir of ['ltr','rtl']) {
    await page.evaluate(dir => {document.documentElement.dir=dir;document.body.classList.toggle('rtl',dir==='rtl');},dir);
    const header = (await page.locator('.slimstat-header').boundingBox())!;
    expect(header.x, `${dir} header left edge`).toBeGreaterThanOrEqual(0);
    expect(header.x + header.width, `${dir} header right edge`).toBeLessThanOrEqual(320);
    for (const selector of ['.slimstat-header__actions','.slimstat-notification-sidebar__promo']) {
      const overflow = await page.locator(selector).evaluate(el => el.scrollWidth-el.clientWidth);
      expect(overflow, `${selector} ${dir} fits`).toBeLessThanOrEqual(1);
    }
    for (const selector of ['.slimstat-notification-sidebar__reload','.slimstat-notification-sidebar__close','.slimstat-notification-sidebar__copy','.slimstat-notification-sidebar__button','.slimstat-notification-sidebar__dismiss']) {
      const box = (await page.locator(selector).boundingBox())!;
      expect(Math.round(box.height * 100) / 100, `${selector} touch height`).toBeGreaterThanOrEqual(44);
      expect(Math.round(box.width * 100) / 100, `${selector} touch width`).toBeGreaterThanOrEqual(44);
    }
  }
  await page.emulateMedia({reducedMotion:'reduce',forcedColors:'active'});
  await page.keyboard.press('Tab');
  await page.locator('.slimstat-notification-sidebar__copy').focus();
  const focus = await page.locator('.slimstat-notification-sidebar__copy').evaluate(el => getComputedStyle(el).outlineStyle);
  expect(focus).toBe('solid');
  expect(await page.locator('.slimstat-notification-sidebar__menu').evaluate(el => getComputedStyle(el).transitionDuration)).toBe('0s');
});

test('an initially empty inbox exposes dismiss all when a notice becomes visible', async ({page}) => {
  runWordPressFixture('<?php update_option("wp_slimstat_notifications",["data"=>[]],false);');
  await page.clock.install();
  await page.goto('/wp-admin/admin.php?page=slimview1');
  await page.locator('.js-slimstat-open-notification').click();
  await expect(page.locator('.slimstat-notification-sidebar__dismiss-all')).toBeHidden();
  seed();
  await page.clock.fastForward(61000);
  await expect(page.locator('.slimstat-notification-sidebar__dismiss-all')).toBeVisible();
  await page.locator('.slimstat-notification-sidebar__dismiss-all').click();
  await expect(page.locator('#tab-1')).toContainText('No notifications in your inbox.');
});

test('a dismissal from another tab returns focus to a visible control', async ({page}) => {
  await page.clock.install();
  await page.goto('/wp-admin/admin.php?page=slimview1');
  await page.locator('.js-slimstat-open-notification').click();
  const copy = page.locator('#tab-1 .slimstat-notification-sidebar__copy');
  await copy.focus();
  await page.clock.fastForward(61000);
  await expect(copy).toBeFocused();
  runWordPressFixture('<?php update_option("slimstat_notification_dismissed", [902601 => true], false);');
  await page.clock.fastForward(61000);
  await expect(copy).toHaveCount(0);
  await expect(page.locator('#tab-2 .slimstat-notification-sidebar__copy')).toHaveCount(1);
  await expect(page.locator('.slimstat-notification-sidebar__close')).toBeFocused();
});

test('a failed dismissal preserves the offer, explains the failure and allows a retry', async ({page}) => {
  await page.goto('/wp-admin/admin.php?page=slimview1');
  await page.locator('.js-slimstat-open-notification').click();
  const dismiss = page.locator('#tab-1 .slimstat-notification-sidebar__dismiss');
  await page.route('**/admin-ajax.php', async route => {
    if (route.request().postData()?.includes('action=slimstat_dismiss_notification')) {
      await route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({success:false})});
    } else await route.continue();
  });
  await dismiss.click();
  await expect(page.locator('.slimstat-notification-sidebar__message')).toHaveText('Could not update notifications. Please try again.');
  await expect(dismiss).toBeVisible();
  await page.unroute('**/admin-ajax.php');
  await dismiss.click();
  await expect(dismiss).toHaveCount(0);
  await expect(page.locator('.slimstat-notification-sidebar__message')).toBeEmpty();
});


test('header follows the reference hierarchy and keeps campaign-only offers visible on phones', async ({page}) => {
  await page.setViewportSize({width:1280,height:900});
  await page.goto('/wp-admin/admin.php?page=slimview1');
  const pill = page.locator('.slimstat-header__promo');
  const proActive = runWordPressFixture('<?php echo wp_slimstat::pro_plugin_active() ? "yes" : "no";').trim() === 'yes';
  for (const dir of ['ltr','rtl']) {
    await page.evaluate(dir => {document.documentElement.dir=dir;document.body.classList.toggle('rtl',dir==='rtl');},dir);
    const account = await page.locator('.slimstat-header__account').boundingBox();
    const bell = await page.locator('.js-slimstat-open-notification').boundingBox();
    expect(dir==='ltr' ? account!.x < bell!.x : account!.x > bell!.x).toBe(true);
    const badge = (await page.locator('.slimstat-header__badge').boundingBox())!;
    expect(dir==='ltr' ? badge.x > bell!.x + bell!.width/2 : badge.x < bell!.x + bell!.width/2).toBe(true);
    if (!proActive) {
      await expect(pill).toBeVisible();
      const offer = await pill.boundingBox();
      expect(dir==='ltr' ? offer!.x < bell!.x : offer!.x > bell!.x).toBe(true);
    }
  }
  await page.setViewportSize({width:390,height:844});
  if (!proActive) await expect(pill).toBeHidden();
  await page.locator('.js-slimstat-open-notification').click();
  await expect(page.locator('#tab-1 .slimstat-notification-sidebar__promo')).toBeVisible();
  await page.locator('.slimstat-notification-sidebar__dismiss-all').click();
  await expect(page.locator('#tab-1 .slimstat-notification-sidebar__promo')).toHaveCount(0);
  await page.keyboard.press('Escape');
  if (!proActive) await expect(pill, 'dismissal must not move the same offer back into the mobile header').toBeHidden();
  // Independent feeds can contain a banner without a matching notification. Preserve that offer.
  runWordPressFixture('<?php update_option("wp_slimstat_notifications",["data"=>[]],false);');
  await page.reload();
  if (!proActive) await expect(pill).toBeVisible();
  await expect(page.locator('.slimstat-header__badge')).toHaveCount(0);
  expect(await page.locator('.slimstat-header').evaluate(el=>el.scrollWidth-el.clientWidth)).toBeLessThanOrEqual(1);
});


test('an unrelated or unidentified promo cannot hide a mobile header campaign', async ({page}) => {
  const proActive=runWordPressFixture('<?php echo wp_slimstat::pro_plugin_active() ? "yes" : "no";').trim() === 'yes';
  await page.setViewportSize({width:320,height:900});
  for (const campaign of ['different-offer','']) {
    seed();
    runWordPressFixture(`<?php $feed=get_option('wp_slimstat_notifications');$feed['data'][0]['primary_button']['url']='https://example.test/offer/?utm_campaign=${campaign}';update_option('wp_slimstat_notifications',$feed,false);`);
    await page.goto('/wp-admin/admin.php?page=slimview1');
    const pill=page.locator('.slimstat-header__promo');
    for (const dir of ['ltr','rtl']) {
      await page.evaluate(dir=>{document.documentElement.dir=dir;document.body.classList.toggle('rtl',dir==='rtl');},dir);
      if (proActive) await expect(pill).toHaveCount(0);
      else await expect(pill).toBeVisible();
      expect(await page.locator('.slimstat-header').evaluate(el=>el.scrollWidth-el.clientWidth)).toBeLessThanOrEqual(1);
    }
    await page.locator('.js-slimstat-open-notification').click();
    await page.locator('.slimstat-notification-sidebar__dismiss-all').click();
    await expect(page.locator('#tab-1 .slimstat-notification-sidebar__promo')).toHaveCount(0);
    await page.keyboard.press('Escape');
    if (proActive) await expect(pill).toHaveCount(0);
    else await expect(pill, 'AJAX refresh must preserve unrelated campaigns').toBeVisible();
  }
});
