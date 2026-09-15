import { test, expect } from '@playwright/test';
import path from 'path';
import { fileURLToPath } from 'url';

// Run the shipped bundle against a response-controlled endpoint. No WordPress data is changed.
const bundle = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../wp-slimstat.min.js');
const origin = 'https://slimstat-transport.test';

for (const initialId of ['', 'invalid-cache-id']) {
  test(`initial pageview obtains its ID despite beacon preference (${initialId || 'absent'})`, async ({ page }) => {
    let posts = 0;
    await page.route(`${origin}/**`, async route => {
      if (route.request().method() === 'POST') {
        posts++;
        await route.fulfill({ contentType: 'text/plain', body: '901.abcdef' });
      } else {
        await route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>Transport fixture</title>' });
      }
    });
    await page.goto(origin);
    await page.evaluate(({ origin }) => {
      const w = window as any;
      w.SlimStatParams = { id: '999.abcdef', ajaxurl_rest: `${origin}/hit`, transport: 'rest', gdpr_enabled: 'off', is_logged_in: '0' };
      w.beaconCalls = 0;
      Object.defineProperty(navigator, 'sendBeacon', { configurable: true, value: () => { w.beaconCalls++; return true; } });
    }, { origin });
    await page.addScriptTag({ path: bundle });
    await page.evaluate(id => {
      const w = window as any;
      w.SlimStatParams.id = id;
      w.slimstatPageviewTracked = false;
      w.SlimStat.send_to_server('action=slimtrack&ci=fixture', true);
    }, initialId);
    await expect.poll(() => page.evaluate(() => (window as any).SlimStatParams.id)).toBe('901.abcdef');
    expect(posts).toBe(1);
    expect(await page.evaluate(() => (window as any).beaconCalls)).toBe(0);

    // Once the ID was confirmed, follow-up updates can still use beacon.
    await page.evaluate(() => {
      const w = window as any;
      w.SlimStat.send_to_server(`action=slimtrack&id=${w.SlimStatParams.id}&fv=1`, true);
    });
    await expect.poll(() => page.evaluate(() => (window as any).beaconCalls)).toBe(1);
    expect(posts).toBe(1);
  });
}
