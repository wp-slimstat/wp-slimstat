import { test, expect } from '@playwright/test';
import {
  snapshotSlimstatOptions, restoreSlimstatOptions, setSlimstatOption, setSlimstatOptions,
  getPool, closeDb, waitForPageviewRow,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

async function readVisit(marker: string) {
  expect(await waitForPageviewRow(marker)).not.toBeNull();
  const [rows] = await getPool().query<any[]>('SELECT id, visit_id FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id DESC LIMIT 1', [`%${marker}%`]);
  return rows[0];
}

const browserUA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
test.use({ trace: 'retain-on-failure' });

test.describe('Cookieless server visits — #417 @compat', () => {
  let marker: string;
  test.beforeEach(async () => {
    marker = `issue417-${Date.now()}`;
    await snapshotSlimstatOptions();
    await setSlimstatOptions({
      javascript_mode: 'off', anonymous_tracking: 'off', gdpr_enabled: 'off',
      set_tracker_cookie: 'on', enable_browscap: 'off', ignore_bots: 'off',
      ignore_wp_users: 'no', session_duration: '1800', is_tracking: 'on',
      ignore_ips: '', ignore_users: '', do_not_track: 'off',
    });
  });
  test.afterEach(async () => {
    try {
      await getPool().execute('DELETE FROM wp_slim_stats WHERE resource LIKE ?', [`%${marker}%`]);
    } finally { await restoreSlimstatOptions(); }
  });
  test.afterAll(async () => { await closeDb(); });

  for (const scenario of ['cookies accepted', 'cookies not returned', 'tracking cookies disabled']) {
    test(`${scenario}: four pages share one human visit`, async ({ page, browser }) => {
      if (scenario === 'tracking cookies disabled') await setSlimstatOption(page, 'set_tracker_cookie', 'off');
      const context = await browser.newContext({
        userAgent: `${browserUA} ${marker}`,
        javaScriptEnabled: scenario === 'tracking cookies disabled',
        storageState: { cookies: [], origins: [] },
      });
      const visitor = await context.newPage();
      try {
        for (let i = 0; i < 4; i++) {
          if (scenario === 'cookies not returned') await context.clearCookies();
          await visitor.goto(`${BASE_URL}/?e2e=${marker}-${i}`, { waitUntil: 'networkidle' });
          expect(await waitForPageviewRow(`${marker}-${i}`)).not.toBeNull();
        }
        const [rows] = await getPool().query<any[]>(
          'SELECT id, visit_id, browser_type, dt, HEX(vid_hash) AS identity_hash, resource FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id', [`%${marker}%`],
        );
        expect(rows).toHaveLength(4);
        expect(rows.every(row => Number(row.visit_id) > 0 && Number(row.browser_type) !== 1)).toBe(true);
        expect(new Set(rows.map(row => Number(row.visit_id))).size, JSON.stringify(rows)).toBe(1);
        if (scenario === 'tracking cookies disabled') {
          expect((await context.cookies()).some(cookie => cookie.name === 'slimstat_tracking_code')).toBe(false);
        }
      } finally { await context.close(); }
    });
  }

  test('expired sessions and different user agents get new visits', async ({ page, browser }) => {
    await setSlimstatOption(page, 'set_tracker_cookie', 'off');
    const context = await browser.newContext({ userAgent: `${browserUA} ${marker}`, javaScriptEnabled: false });
    const visitor = await context.newPage();
    try {
      await visitor.goto(`${BASE_URL}/?e2e=${marker}-first`);
      const first = await readVisit(`${marker}-first`);
      expect(Number(first?.visit_id)).toBeGreaterThan(0);
      await getPool().execute('UPDATE wp_slim_stats SET dt = dt - 1801 WHERE id = ?', [first!.id]);
      await visitor.goto(`${BASE_URL}/?e2e=${marker}-expired`);
      const expired = await readVisit(`${marker}-expired`);
      expect(Number(expired?.visit_id)).toBeGreaterThan(0);
      expect(expired!.visit_id).not.toBe(first!.visit_id);
      const other = await browser.newContext({ userAgent: `${browserUA} ${marker}-other`, javaScriptEnabled: false });
      try {
        await (await other.newPage()).goto(`${BASE_URL}/?e2e=${marker}-other`);
        const separate = await readVisit(`${marker}-other`);
        expect(Number(separate?.visit_id)).toBeGreaterThan(0);
        expect(separate!.visit_id).not.toBe(expired!.visit_id);
      } finally { await other.close(); }
    } finally { await context.close(); }
  });

  for (const exclude of ['off', 'on']) {
    test(`Go HTTP client is ${exclude === 'on' ? 'excluded' : 'recorded as a bot'}`, async ({ page, playwright }) => {
      await setSlimstatOption(page, 'ignore_bots', exclude);
      const client = await playwright.request.newContext({ userAgent: 'Go-http-client/2.0' });
      try {
        expect((await client.get(`${BASE_URL}/?e2e=${marker}`)).ok()).toBe(true);
        const [rows] = await getPool().query<any[]>('SELECT browser_type FROM wp_slim_stats WHERE resource LIKE ?', [`%${marker}%`]);
        if (exclude === 'on') expect(rows).toHaveLength(0);
        else {
          expect(rows).toHaveLength(1);
          expect(Number(rows[0].browser_type)).toBe(1);
        }
      } finally { await client.dispose(); }
    });
  }

  for (const extend of ['off', 'on']) {
    test(`Extend duration ${extend}: a recovered cookie keeps the correct expiry`, async ({ browser }) => {
      await setSlimstatOptions({ set_tracker_cookie: 'off', extend_session: extend });
      const context = await browser.newContext({ userAgent: `${browserUA} ${marker}`, javaScriptEnabled: false });
      try {
        const visitor = await context.newPage();
        await visitor.goto(`${BASE_URL}/?e2e=${marker}-start`);
        const first = await readVisit(`${marker}-start`);
        expect((await context.cookies()).some(cookie => cookie.name === 'slimstat_tracking_code')).toBe(false);
        await getPool().execute('UPDATE wp_slim_stats SET dt = dt - 1200 WHERE id = ?', [first.id]);
        await setSlimstatOptions({ set_tracker_cookie: 'on' });
        await visitor.goto(`${BASE_URL}/?e2e=${marker}-recovered`);
        expect((await readVisit(`${marker}-recovered`)).visit_id).toBe(first.visit_id);
        const cookie = (await context.cookies()).find(cookie => cookie.name === 'slimstat_tracking_code');
        expect(cookie).toBeDefined();
        const remaining = cookie!.expires - Date.now() / 1000;
        const expected = extend === 'on' ? 1800 : 600;
        expect(remaining).toBeGreaterThan(expected - 60);
        expect(remaining).toBeLessThanOrEqual(expected + 5);
      } finally { await context.close(); }
    });

    test(`Extend duration ${extend}: an intermediate page ${extend === 'on' ? 'extends' : 'does not extend'} the visit`, async ({ browser }) => {
      await setSlimstatOptions({ set_tracker_cookie: 'off', extend_session: extend });
      const context = await browser.newContext({ userAgent: `${browserUA} ${marker}`, javaScriptEnabled: false });
      try {
        const visitor = await context.newPage();
        await visitor.goto(`${BASE_URL}/?e2e=${marker}-start`);
        const first = await readVisit(`${marker}-start`);
        await getPool().execute('UPDATE wp_slim_stats SET dt = dt - 1200 WHERE id = ?', [first.id]);
        await visitor.goto(`${BASE_URL}/?e2e=${marker}-middle`);
        const middle = await readVisit(`${marker}-middle`);
        expect(middle.visit_id).toBe(first.visit_id);
        await getPool().execute('UPDATE wp_slim_stats SET dt = dt - 1200 WHERE id IN (?, ?)', [first.id, middle.id]);
        await visitor.goto(`${BASE_URL}/?e2e=${marker}-end`);
        const last = await readVisit(`${marker}-end`);
        expect(Number(last.visit_id)).toBeGreaterThan(0);
        expect(last.visit_id === first.visit_id).toBe(extend === 'on');
      } finally { await context.close(); }
    });
  }
});
