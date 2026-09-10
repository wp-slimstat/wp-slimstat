/**
 * The only automated proof that checkout PII -- buyer name and billing email --
 * never reaches wp_slim_stats, alongside session continuity and the cart ->
 * checkout -> order-received referer chain.
 *
 * Tagged @woocommerce because it needs a prerequisite most lanes cannot have:
 * WooCommerce 10.6.2 declares `Requires at least: 6.8`, and the lane that runs
 * the FULL suite is the committed baseline, WordPress 6.4. So the tag routes it,
 * in .github/workflows/ci.yml: the 6.4 lane runs the suite with
 * --grep-invert @woocommerce, and the lane named by WOO_E2E_LANE (7.1 / PHP 8.3,
 * blocking) installs a pinned WooCommerce and greps it back in. The isolated
 * census driver carves it out for the same reason -- it too boots 6.4.
 *
 * The alternative was to let it self-skip on a missing prerequisite. That would
 * have removed the PII proof on every lane while the suite stayed green, which is
 * why helpers/woocommerce-store.php raises instead of skipping. Do not soften that
 * into a skip; move the lane instead.
 */
import { test, expect } from '@playwright/test';
import { readFileSync } from 'fs';
import { runWordPressFixture } from './helpers/chart';
import { getCorrelatedRows, shopperJourney } from './helpers/journeys';
import { clearStatsTable, closeDb, installMuPluginByName, uninstallMuPluginByName, snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOptions } from './helpers/setup';

const fixtureSource = readFileSync(new URL('./helpers/woocommerce-store.php', import.meta.url), 'utf8').replace(/^<\?php\s*/, '');
function fixture(mode: 'seed' | 'cleanup', runId: string): any {
  return JSON.parse(runWordPressFixture(`<?php\n$fixture_mode = '${mode}'; $fixture_run = '${runId}';\n${fixtureSource}`));
}

test('WooCommerce purchase preserves session, attribution and excludes checkout PII from analytics @woocommerce', async ({ page, browser }, testInfo) => {
  const runId = `woo-journey-${Date.now()}`;
  await snapshotSlimstatOptions();
  installMuPluginByName('mail-sink-mu-plugin.php');
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  try {
    // Missing WooCommerce is an explicit prerequisite failure, never excluded shipping coverage.
    const store = fixture('seed', runId);
    await testInfo.attach('woocommerce-version', { body: store.version, contentType: 'text/plain' });
    await clearStatsTable();
    await setSlimstatOptions({ gdpr_enabled: 'off', javascript_mode: 'on', set_tracker_cookie: 'on', tracking_request_method: 'ajax', track_same_domain_referers: 'on', ignore_wp_users: 'no', ignore_bots: 'off' });
    const shopper = await context.newPage();
    const result = await shopperJourney(shopper, runId, store);
    const rows = await getCorrelatedRows(runId);
    await testInfo.attach('purchase-evidence', { body: JSON.stringify({ result, rows }, null, 2), contentType: 'application/json' });
    expect(rows.length).toBeGreaterThanOrEqual(4);
    const visits = new Set(rows.map(row => Number(row.visit_id)));
    expect(visits.size).toBe(1);
    expect([...visits][0]).toBeGreaterThan(0);
    const cart = rows.find(row => row.resource.includes(`${runId}-cart`));
    const checkout = rows.find(row => row.resource.includes(`${runId}-checkout`) && !row.resource.includes('order-received'));
    const order = rows.find(row => row.resource.includes('order-received'));
    expect(cart?.referer).toContain(new URL(store.product).pathname);
    expect(checkout?.referer).toContain(`${runId}-cart`);
    expect(order?.referer).toContain(`${runId}-checkout`);
    expect(JSON.stringify(rows)).not.toContain('E2EPurchaseBuyer');
    expect(JSON.stringify(rows)).not.toContain(store.email);
    const savedOrder = JSON.parse(runWordPressFixture(`<?php $orders = wc_get_orders(['billing_email' => '${runId}@example.test', 'limit' => -1]); echo wp_json_encode(array_map(static function ($order) { return ['status' => $order->get_status(), 'total' => $order->get_total()]; }, $orders));`));
    // The control assertion: the journey really transacted, and it is unpaid (BACS
    // leaves the order on-hold), so the analytics rows above describe a real purchase.
    // Compared NUMERICALLY. WC_Abstract_Order::get_total() returns the stored prop
    // through wc_format_decimal(), which trims trailing zeros -- a store that saved
    // "1" and one that saved "1.00" are the same order, and this test is not about
    // WooCommerce's decimal formatting. A string compare here failed on WooCommerce
    // 10.6.2 with `Received "1"`, which reads as a broken purchase and is not one.
    expect(savedOrder).toHaveLength(1);
    expect(savedOrder[0].status).toBe('on-hold');
    expect(Number(savedOrder[0].total)).toBe(1);
  } finally {
    await context.close();
    // The fixture saves cleanup state before its first write, including partial failures.
    try {
      fixture('cleanup', runId);
    } finally {
      await restoreSlimstatOptions();
      uninstallMuPluginByName('mail-sink-mu-plugin.php');
      await closeDb();
    }
  }
});
