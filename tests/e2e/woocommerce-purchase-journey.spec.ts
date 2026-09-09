import { test, expect } from '@playwright/test';
import { readFileSync } from 'fs';
import { runWordPressFixture } from './helpers/chart';
import { getCorrelatedRows, shopperJourney } from './helpers/journeys';
import { clearStatsTable, closeDb, installMuPluginByName, uninstallMuPluginByName, snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOptions } from './helpers/setup';

const fixtureSource = readFileSync(new URL('./helpers/woocommerce-store.php', import.meta.url), 'utf8').replace(/^<\?php\s*/, '');
function fixture(mode: 'seed' | 'cleanup', runId: string): any {
  return JSON.parse(runWordPressFixture(`<?php\n$fixture_mode = '${mode}'; $fixture_run = '${runId}';\n${fixtureSource}`));
}

test('WooCommerce purchase preserves session, attribution and excludes checkout PII from analytics', async ({ page, browser }, testInfo) => {
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
    expect(savedOrder).toEqual([{ status: 'on-hold', total: '1.00' }]);
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
