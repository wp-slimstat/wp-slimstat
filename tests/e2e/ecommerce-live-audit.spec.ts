/** Real requests and WC checkout, isolated fixture. No synthetic pageview/event writes. */
import { test, expect, type Page } from '@playwright/test';
import { readFileSync } from 'fs';
import { runWordPressFixture } from './helpers/chart';
import { shopperJourney, getCorrelatedRows, waitForTracking } from './helpers/journeys';
import { BASE_URL } from './helpers/env';
import { clearStatsTable, closeDb, snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOptions } from './helpers/setup';

const source = readFileSync(new URL('./helpers/woocommerce-store.php', import.meta.url), 'utf8').replace(/^<\?php\s*/, '');
const wp = (code: string) => JSON.parse(runWordPressFixture(`<?php ${code}`));
function fixture(mode: string, run: string, customer = false): any {
  return wp(`$fixture_mode='${mode}'; $fixture_run='${run}'; $fixture_customer=${customer ? 'true' : 'false'}; ${source}`);
}
function evidence(run: string): any {
  return wp(`
    require_once WP_PLUGIN_DIR . '/wp-slimstat/admin/view/wp-slimstat-db.php';
    $orders = wc_get_orders(['billing_email'=>'${run}@example.test','limit'=>20,'orderby'=>'ID','order'=>'ASC']);
    $saved=[]; foreach($orders as $o){
      do_action('slimstat_ecommerce_sync', $o->get_id());
      $row=SlimStat\\Ecommerce\\Integration::db()->get_row(SlimStat\\Ecommerce\\Integration::db()->prepare('SELECT * FROM '.SlimStat\\Ecommerce\\Integration::table().' WHERE order_id=%d AND kind=0',$o->get_id()),ARRAY_A);
      $saved[]=['id'=>$o->get_id(),'status'=>$o->get_status(),'total'=>$o->get_total(),'customer'=>$o->get_customer_id(),'projection'=>$row];
    }
    wp_slimstat_db::init(); wp_slimstat_db::$filters_normalized['columns']=['utm_campaign'=>['equals','${run}']];
    wp_slimstat_db::$filters_normalized['utime']=['start'=>wp_slimstat::now()-3600,'end'=>wp_slimstat::now()];
    SlimStat\\Ecommerce\\Integration::invalidate(); $report=(new SlimStat\\Ecommerce\\Report())->data();
    $events=SlimStat\\Ecommerce\\Integration::db()->get_results("SELECT e.id,e.dt,e.notes,s.visit_id FROM {$GLOBALS['wpdb']->prefix}slim_events e JOIN {$GLOBALS['wpdb']->prefix}slim_stats s ON s.id=e.id WHERE e.notes='[ec:cart]'",ARRAY_A);
    wp_slimstat_db::$filters_normalized['columns']=[];
    $commerce=(new SlimStat\\Ecommerce\\Report())->data('auto',false)['current'];
    $timeline=SlimStat\\Ecommerce\\Integration::db()->get_results("SELECT id,dt,notes FROM {$GLOBALS['wpdb']->prefix}slim_stats WHERE notes LIKE '%[ec:%' ORDER BY dt,id",ARRAY_A);
    echo wp_json_encode(compact('saved','report','events','commerce','timeline'));
  `);
}
async function correlation(page: Page, run: string) {
  await page.addInitScript(id => { const u = new URL(location.href); u.searchParams.set('e2e_run', id); history.replaceState(null, '', u.href); }, run);
}
const modes = ['guest', 'account-repeat', 'cart-only', 'checkout-only', 'declined', 'excluded', 'blocked'] as const;
for (const mode of modes) test(`Ecommerce live ${mode}: expected observations reconcile with orders @woocommerce`, async ({ browser, page: admin }, testInfo) => {
  test.setTimeout(150_000);
  const run = `ec-live-${mode}-${Date.now()}`;
  const noTracking = ['declined', 'excluded', 'blocked'].includes(mode);
  const purchase = !['cart-only', 'checkout-only'].includes(mode);
  const expected = { orders: purchase ? mode === 'account-repeat' ? 2 : 1 : 0, tracked: !noTracking,
    products: noTracking ? 0 : 1, carts: noTracking ? 0 : 1, checkouts: noTracking || mode === 'cart-only' ? 0 : 1 };
  await testInfo.attach('expected-before-execution', { body: JSON.stringify(expected), contentType: 'application/json' });
  await snapshotSlimstatOptions();
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  let store: any;
  try {
    store = fixture('seed', run, mode === 'account-repeat');
    wp(`$f=get_option('slimstat_e2e_woo_store');$f['audit_gateway']=true;update_option('slimstat_e2e_woo_store',$f,false); SlimStat\\Ecommerce\\Integration::setup(); SlimStat\\Ecommerce\\Integration::import(); echo '{}';`);
    await clearStatsTable();
    await setSlimstatOptions({ tracking: 'on', gdpr_enabled: mode === 'declined' ? 'on' : 'off', consent_integration: 'slimstat_banner', use_slimstat_banner: 'on', anonymous_tracking: 'off',
      javascript_mode: 'on', set_tracker_cookie: 'on', tracking_request_method: 'ajax', track_same_domain_referers: 'on', ignore_wp_users: 'no', ignore_capabilities: '', ignore_bots: 'off', ignore_resources: mode === 'excluded' ? '*' : '' });
    await context.addCookies(['viewed_cookie_policy','CookieLawInfoConsent','cookielawinfo-checkbox-necessary','cookielawinfo-checkbox-analytics'].map(name => ({name,value:name==='CookieLawInfoConsent'?'true':'yes',domain:new URL(BASE_URL).hostname,path:'/'})));
    const shopper = await context.newPage();
    const requests: { url: string; status?: number; elapsed?: number; blocked?: boolean }[] = [];
    const times = new Map<any, number>();
    shopper.on('request', req => { if ((req.postData() || '').includes('action=slimtrack')) times.set(req, Date.now()); });
    shopper.on('response', res => { if (times.has(res.request())) requests.push({url:res.url(),status:res.status(),elapsed:Date.now()-times.get(res.request())!}); });
    if (mode === 'blocked') await shopper.route('**/*', route => {
      if ((route.request().postData() || '').includes('action=slimtrack')) { requests.push({url:route.request().url(),blocked:true}); return route.abort('blockedbyclient'); }
      return route.continue();
    });
    if (mode === 'account-repeat') {
      await shopper.goto('/wp-login.php'); await shopper.locator('#user_login').fill(run); await shopper.locator('#user_pass').fill('fixture-only-password');
      await Promise.all([shopper.waitForURL(url => !url.pathname.includes('wp-login.php')), shopper.locator('#wp-submit').click()]);
    }
    const tagged = new URL(store.product); tagged.searchParams.set('utm_source', 'google'); tagged.searchParams.set('utm_medium', 'cpc'); tagged.searchParams.set('utm_campaign', run);
    store.product = tagged.href;
    if (mode === 'declined') {
      await shopper.goto(store.product, {waitUntil:'networkidle'});
      await shopper.locator('[data-consent="denied"]').click();
      await expect(shopper.locator('#slimstat-gdpr-banner')).not.toBeVisible();
      expect((await context.cookies()).find(c=>c.name==='slimstat_gdpr_consent')?.value).toBe('denied');
    }
    if (purchase) {
      const receipt = await shopperJourney(shopper, run, store, false, !noTracking);
      await shopper.reload({waitUntil:'networkidle'});
      await shopper.goto(receipt.orderUrl, {waitUntil:'networkidle'});
      if (mode === 'account-repeat') {
        // Same browser returns from the confirmation page; repeat order, one retained visit.
        await shopperJourney(shopper, run, store);
      }
    } else {
      await correlation(shopper, run); await shopper.goto(store.product, {waitUntil:'networkidle'});
      await waitForTracking(shopper, run, 1);
      await shopper.locator('button.single_add_to_cart_button').click();
      await expect(shopper.locator('.woocommerce-cart-form')).toBeVisible();
      await waitForTracking(shopper, run, 2);
      if (mode === 'checkout-only') {
        await shopper.locator('a.checkout-button').click(); await expect(shopper.locator('form.checkout')).toBeVisible(); await waitForTracking(shopper, run, 3);
      }
    }
    const rows = await getCorrelatedRows(run);
    const actual = evidence(run);
    await testInfo.attach('actual-observations', {body:JSON.stringify({expected,actual,rows,requests},null,2),contentType:'application/json'});
    expect(actual.saved).toHaveLength(expected.orders);
    // Consent controls analytics association, never the authoritative commerce total.
    expect(Number(actual.commerce.orders)).toBe(expected.orders);
    expect(Number(actual.commerce.net)).toBe(expected.orders);
    for (const order of actual.saved) {
      expect(order.status).toBe('completed'); expect(Number(order.total)).toBe(1);
      expect(Number(order.projection.stat_id) > 0).toBe(!noTracking);
      expect(Number(order.customer) > 0).toBe(mode === 'account-repeat');
    }
    if (noTracking) {
      expect(rows).toHaveLength(0); expect(actual.events).toHaveLength(0);
      expect(actual.report.journey.visits).toBe(0); expect(Number(actual.report.current.orders)).toBe(0);
      if (mode === 'blocked') expect(requests.some(r=>r.blocked)).toBe(true);
    } else {
      expect(new Set(rows.map(r=>r.visit_id)).size).toBe(1);
      expect(actual.report.journey).toMatchObject({products:expected.products,carts:expected.carts,checkouts:expected.checkouts,buyers:purchase?1:0,completed:purchase?1:0});
      expect(Number(actual.report.current.net)).toBe(expected.orders);
      expect(Number(actual.report.current.orders)).toBe(expected.orders);
      expect(actual.events).toHaveLength(mode==='account-repeat'?2:1);
      for (const event of actual.events) {
        const origin = actual.timeline.find((row: any) => Number(row.id) === Number(event.id));
        expect(origin.notes).toContain('[ec:product]');
        expect(Number(event.dt)).toBeGreaterThanOrEqual(Number(origin.dt));
      }
      if (expected.checkouts) {
        const checkout = actual.timeline.find((row: any) => row.notes.includes('[ec:checkout]'));
        expect(Number(checkout.dt)).toBeGreaterThanOrEqual(Number(actual.events[0].dt));
        if (purchase) expect(Number(actual.saved[0].projection.dt)).toBeGreaterThanOrEqual(Number(checkout.dt));
      }
      expect(JSON.stringify(rows)).not.toContain('E2EPurchaseBuyer'); if (mode !== 'account-repeat') expect(JSON.stringify(rows)).not.toContain(store.email);
      expect(JSON.stringify(rows)).not.toContain('wc_order_');
      // Confirm the same live activity is visible through the authenticated dashboard request.
      await admin.goto(`/wp-admin/admin.php?${new URLSearchParams({page:'slimview7','fs[utm_campaign]':`equals ${run}`})}`);
      await expect(admin.locator('[data-ecommerce] [data-metric=net]')).toContainText(`${expected.orders}.00`);
      await admin.screenshot({path:testInfo.outputPath('live-dashboard.png'),fullPage:true});
    }
    if (mode === 'guest') {
      wp(`add_filter('pre_wp_mail','__return_true');$r=wc_create_refund(['order_id'=>${actual.saved[0].id},'amount'=>'0.40','reason'=>'Local audit','refund_payment'=>false]); if(is_wp_error($r))throw new RuntimeException($r->get_error_message()); echo '{}';`);
      const refunded = evidence(run); expect(Number(refunded.report.current.net)).toBe(0.6);
      wp(`add_filter('pre_wp_mail','__return_true');wc_get_order(${actual.saved[0].id})->update_status('cancelled');echo '{}';`);
      const cancelled = evidence(run); expect(Number(cancelled.report.current.orders)).toBe(0); expect(Number(cancelled.report.current.net)).toBe(0);
      await testInfo.attach('refund-and-cancellation', {body:JSON.stringify({refunded,cancelled}),contentType:'application/json'});
    }
  } finally {
    try { fixture('cleanup', run); } finally { await restoreSlimstatOptions(); await closeDb(); await context.close().catch(()=>{}); }
  }
});
