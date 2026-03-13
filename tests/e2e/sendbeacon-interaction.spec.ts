/**
 * E2E tests: sendBeacon interaction tracking (#174).
 *
 * Verifies that outbound link clicks tracked via sendBeacon (text/plain)
 * correctly populate outbound_resource and dt_out in the database.
 * Regression guard for the TrackingRestController merge fix and Query
 * builder SET/WHERE parameter ordering fix.
 */
import { test, expect } from '@playwright/test';
import {
  installOptionMutator,
  uninstallOptionMutator,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  getLatestStatFull,
  closeDb,
} from './helpers/setup';

test.describe('sendBeacon Interaction Tracking (#174)', () => {
  test.beforeAll(async () => {
    installOptionMutator();
  });

  test.beforeEach(async () => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    await closeDb();
  });

  test('outbound link click via sendBeacon populates outbound_resource and dt_out', async ({ page }) => {
    test.setTimeout(60000);
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
    // Force REST transport — this is the code path affected by #174
    await setSlimstatOption(page, 'tracking_request_method', 'rest');
    const marker = `outbound-test-${Date.now()}`;

    // Capture the pageview ID (with checksum) from the REST response
    let pageviewIdWithChecksum = '';
    page.on('response', async (res) => {
      if (res.url().includes('slimstat/v1/hit') || res.url().includes('rest_route=/slimstat')) {
        try {
          const body = await res.text();
          const cleaned = body.replace(/^"|"$/g, '').trim();
          if (/^\d+\./.test(cleaned)) {
            pageviewIdWithChecksum = cleaned;
          }
        } catch {}
      }
    });

    // 1. Visit a frontend page so the tracker initializes and creates a pageview
    await page.goto(`/?p=${marker}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(5000);

    // Verify pageview was tracked in DB
    const pageviewStat = await getLatestStatFull(marker);
    expect(pageviewStat).not.toBeNull();
    expect(pageviewStat!.id).toBeGreaterThan(0);
    expect(pageviewIdWithChecksum).toBeTruthy();

    // Get the REST endpoint URL from tracker params
    const restUrl = await page.evaluate(() => (window as any).SlimStatParams?.ajaxurl_rest || '');
    expect(restUrl).toBeTruthy();

    // Use the client-side ID if available, otherwise use the captured response ID
    const trackerClientId = await page.evaluate(() => (window as any).SlimStatParams?.id || '');
    const idToUse = trackerClientId || pageviewIdWithChecksum;

    // 2. Construct the interaction payload (same format the tracker sends for outbound clicks)
    const outboundUrl = 'https://example.com/test-outbound-174';
    const noteObj = JSON.stringify({ type: 'click', text: 'External Test Link', id: 'test-outbound-link' });

    // 3. Send as text/plain — this is what navigator.sendBeacon uses.
    //    Before the #174 fix, REST API couldn't parse text/plain, so the correctly
    //    init-parsed data was overwritten with empty params.
    const beaconRes = await page.request.post(restUrl, {
      headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
      data: await page.evaluate(({ id, outUrl, note }: { id: string; outUrl: string; note: string }) => {
        const b64 = (s: string) => btoa(unescape(encodeURIComponent(s)));
        return `action=slimtrack&id=${id}&res=${b64(outUrl)}&pos=100,200&no=${b64(note)}`;
      }, { id: idToUse, outUrl: outboundUrl, note: noteObj }),
    });
    expect(beaconRes.status()).toBe(200);
    await page.waitForTimeout(1000);

    // 4. Assert outbound_resource and dt_out in database
    const stat = await getLatestStatFull(marker);
    expect(stat).not.toBeNull();
    expect(stat!.outbound_resource).toBeTruthy();
    expect(stat!.outbound_resource).toContain('example.com/test-outbound-174');
    expect(stat!.dt_out).toBeGreaterThan(0);
  });

  test('pageview via XHR still works with REST transport (regression guard)', async ({ page }) => {
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
    await setSlimstatOption(page, 'tracking_request_method', 'rest');
    const marker = `pageview-regression-${Date.now()}`;

    await page.goto(`/?p=${marker}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(5000);

    const stat = await getLatestStatFull(marker);
    expect(stat).not.toBeNull();
    expect(stat!.id).toBeGreaterThan(0);
    expect(stat!.resource).toContain(marker);
  });

  // ─── AC-TRK-005: outbound click tracked with dt_out timestamp ───

  test('outbound click tracked with dt_out timestamp', async ({ page }) => {
    test.setTimeout(60000);
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
    await setSlimstatOption(page, 'tracking_request_method', 'rest');

    const marker = `outbound-click-${Date.now()}`;

    // Capture the pageview ID from the REST response
    let pageviewIdWithChecksum = '';
    page.on('response', async (res) => {
      if (res.url().includes('slimstat/v1/hit') || res.url().includes('rest_route=/slimstat')) {
        try {
          const body = await res.text();
          const cleaned = body.replace(/^"|"$/g, '').trim();
          if (/^\d+\./.test(cleaned)) {
            pageviewIdWithChecksum = cleaned;
          }
        } catch {}
      }
    });

    // Create a page with an external link
    await page.goto(`/?p=${marker}`, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(5000);

    expect(pageviewIdWithChecksum).toBeTruthy();

    // Get the REST endpoint URL
    const restUrl = await page.evaluate(() => (window as any).SlimStatParams?.ajaxurl_rest || '');
    expect(restUrl).toBeTruthy();

    const trackerClientId = await page.evaluate(() => (window as any).SlimStatParams?.id || '');
    const idToUse = trackerClientId || pageviewIdWithChecksum;

    // Simulate an outbound click via sendBeacon (text/plain)
    const outboundUrl = 'https://external-site.org/outbound-test';
    const noteObj = JSON.stringify({ type: 'click', text: 'External Link', id: 'outbound-link' });

    const beaconRes = await page.request.post(restUrl, {
      headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
      data: await page.evaluate(({ id, outUrl, note }: { id: string; outUrl: string; note: string }) => {
        const b64 = (s: string) => btoa(unescape(encodeURIComponent(s)));
        return `action=slimtrack&id=${id}&res=${b64(outUrl)}&pos=50,100&no=${b64(note)}`;
      }, { id: idToUse, outUrl: outboundUrl, note: noteObj }),
    });
    expect(beaconRes.status()).toBe(200);
    await page.waitForTimeout(1000);

    // Verify outbound_resource and dt_out are populated
    const stat = await getLatestStatFull(marker);
    expect(stat).not.toBeNull();
    expect(stat!.outbound_resource).toBeTruthy();
    expect(stat!.outbound_resource).toContain('external-site.org/outbound-test');
    expect(stat!.dt_out).toBeGreaterThan(0);
  });
});
