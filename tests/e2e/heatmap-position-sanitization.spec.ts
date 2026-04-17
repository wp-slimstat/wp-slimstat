import { test, expect } from '@playwright/test';
import {
  clearHeaderOverrides,
  clearStatsTable,
  closeDb,
  getPool,
  installHeaderInjector,
  installOptionMutator,
  restoreSlimstatOptions,
  seedEventRow,
  seedPageviews,
  setSlimstatOptions,
  snapshotSlimstatOptions,
  uninstallHeaderInjector,
  uninstallOptionMutator,
  waitForEventRow,
  waitForPageviewRow,
  waitForTrackerId,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

const EMPTY_STORAGE_STATE = { cookies: [], origins: [] };

function encodeBase64(value: string): string {
  return Buffer.from(value, 'utf8').toString('base64');
}

function isTrackingRequest(req: import('@playwright/test').Request): boolean {
  if (req.method() !== 'POST') return false;
  return (
    req.url().includes('/wp-json/slimstat/v1/hit') ||
    req.url().includes('rest_route=/slimstat/v1/hit') ||
    req.url().includes('admin-ajax.php')
  );
}

function captureTrackingPayloads(page: import('@playwright/test').Page): { payloads: string[]; reset(): void } {
  const payloads: string[] = [];
  page.on('request', (req) => {
    if (!isTrackingRequest(req)) return;
    payloads.push(req.postData() || '');
  });

  return {
    payloads,
    reset() {
      payloads.length = 0;
    },
  };
}

async function isProActive(page: import('@playwright/test').Page): Promise<boolean> {
  const res = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
    form: { action: 'e2e_get_slimstat_version' },
  });
  if (!res.ok()) return false;
  const json = await res.json();
  return json.data?.pro_active === true;
}

async function getHeatmapNonce(page: import('@playwright/test').Page): Promise<string> {
  const response = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
    form: { action: 'test_create_nonce', nonce_action: 'slimstat_heatmap_nonce' },
  });
  expect(response.ok(), 'Nonce helper mu-plugin should respond OK').toBeTruthy();
  const json = await response.json();
  return json.data.nonce;
}

async function getLatestStatSummary(resourcePrefix: string): Promise<{ id: number; resource: string } | null> {
  const [rows] = await getPool().execute(
    'SELECT id, resource FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id DESC LIMIT 1',
    [`${resourcePrefix}%`],
  ) as any;
  return rows.length > 0 ? rows[0] : null;
}

async function getEventCount(): Promise<number> {
  const [rows] = await getPool().execute('SELECT COUNT(*) AS cnt FROM wp_slim_events') as any;
  return rows[0].cnt;
}

async function injectClickTarget(page: import('@playwright/test').Page): Promise<void> {
  await page.evaluate(() => {
    const existing = document.getElementById('slimstat-e2e-click-target');
    if (existing) {
      existing.remove();
    }

    const button = document.createElement('button');
    button.id = 'slimstat-e2e-click-target';
    button.type = 'button';
    button.textContent = 'Track click';
    button.style.position = 'fixed';
    button.style.top = '120px';
    button.style.left = '120px';
    button.style.width = '48px';
    button.style.height = '48px';
    button.style.zIndex = '2147483647';
    document.body.appendChild(button);
  });
}

async function createAnonymousPage(
  browser: import('@playwright/test').Browser,
  initScript?: () => void,
): Promise<{ context: import('@playwright/test').BrowserContext; page: import('@playwright/test').Page }> {
  const context = await browser.newContext({ storageState: EMPTY_STORAGE_STATE });
  if (initScript) {
    await context.addInitScript(initScript);
  }

  const page = await context.newPage();
  return { context, page };
}

async function callHeatmapEndpoint(
  page: import('@playwright/test').Page,
  nonce: string,
  filters = '',
): Promise<{ max: number; data: Array<{ x: string; y: string; value: number }> }> {
  const response = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
    form: {
      action: 'slimstat_heatmap',
      security: nonce,
      fs: encodeBase64(filters),
    },
  });

  expect(response.ok(), 'Heatmap endpoint should respond OK').toBeTruthy();
  return response.json();
}

test.describe('Heatmap position sanitization', () => {
  test.describe.configure({ mode: 'serial' });
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    installOptionMutator();
    installHeaderInjector();
  });

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    clearHeaderOverrides();
    await setSlimstatOptions(page, {
      is_tracking: 'on',
      javascript_mode: 'on',
      ignore_wp_users: 'off',
      ignore_capabilities: '',
      set_tracker_cookie: 'on',
      gdpr_enabled: 'off',
      consent_integration: 'wp_consent_api',
      tracking_request_method: 'rest',
      do_not_track: 'off',
      addon_heatmap_enable: 'off',
      anonymous_tracking: 'off',
    });
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
    clearHeaderOverrides();
  });

  test.afterAll(async () => {
    uninstallHeaderInjector();
    uninstallOptionMutator();
    await closeDb();
  });

  test('click event preserves comma-separated position via REST transport', async ({ page }) => {
    const marker = `heatmap-pos-rest-${Date.now()}`;
    await setSlimstatOptions(page, { gdpr_enabled: 'off', tracking_request_method: 'rest' });

    await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });

    const stat = await waitForPageviewRow(marker, 15_000);
    expect(stat).not.toBeNull();

    const trackerId = await waitForTrackerId(page);
    const restUrl = await page.evaluate(() => (window as any).SlimStatParams?.ajaxurl_rest || '');
    expect(restUrl).toBeTruthy();

    const response = await page.request.post(restUrl, {
      headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
      data: `action=slimtrack&id=${trackerId}&pos=320,480`,
    });
    expect(response.status()).toBe(200);

    const event = await waitForEventRow(stat!.id, 10_000);
    expect(event).not.toBeNull();
    expect(event!.position).toBe('320,480');
  });

  test('click event preserves comma-separated position via AJAX transport', async ({ page }) => {
    const marker = `heatmap-pos-ajax-${Date.now()}`;
    await setSlimstatOptions(page, { gdpr_enabled: 'off', tracking_request_method: 'ajax' });

    await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });

    const stat = await waitForPageviewRow(marker, 15_000);
    expect(stat).not.toBeNull();

    const trackerId = await waitForTrackerId(page);
    const response = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
      form: { action: 'slimtrack', id: trackerId, pos: '320,480' },
    });
    expect(response.ok()).toBeTruthy();

    const event = await waitForEventRow(stat!.id, 10_000);
    expect(event).not.toBeNull();
    expect(event!.position).toBe('320,480');
  });

  test('default position 0,0 is preserved', async ({ page }) => {
    const marker = `heatmap-pos-origin-${Date.now()}`;
    await setSlimstatOptions(page, { gdpr_enabled: 'off', tracking_request_method: 'rest' });

    await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });

    const stat = await waitForPageviewRow(marker, 15_000);
    expect(stat).not.toBeNull();

    const trackerId = await waitForTrackerId(page);
    const restUrl = await page.evaluate(() => (window as any).SlimStatParams?.ajaxurl_rest || '');
    expect(restUrl).toBeTruthy();

    const response = await page.request.post(restUrl, {
      headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
      data: `action=slimtrack&id=${trackerId}&pos=0,0`,
    });
    expect(response.status()).toBe(200);

    const event = await waitForEventRow(stat!.id, 10_000);
    expect(event).not.toBeNull();
    expect(event!.position).toBe('0,0');
  });

  test('heatmap endpoint excludes corrupted positions and returns x/y/value entries', async ({ page }) => {
    test.skip(!(await isProActive(page)), 'WP SlimStat Pro is not installed/active');

    await setSlimstatOptions(page, { addon_heatmap_enable: 'on' });

    const resourcePrefix = `/heatmap-test-${Date.now()}-`;
    await seedPageviews({ count: 1, resourcePrefix });
    const stat = await getLatestStatSummary(resourcePrefix);
    expect(stat).not.toBeNull();

    await seedEventRow(stat!.id, '320,480');
    await seedEventRow(stat!.id, '320480');
    await seedEventRow(stat!.id, '50,75');

    const nonce = await getHeatmapNonce(page);
    const payload = await callHeatmapEndpoint(page, nonce, `resource starts_with ${resourcePrefix}`);

    expect(payload.data).toHaveLength(2);
    expect(payload.data).toEqual(
      expect.arrayContaining([
        expect.objectContaining({ x: '320', y: '480', value: 1 }),
        expect.objectContaining({ x: '50', y: '75', value: 1 }),
      ]),
    );
  });

  test('multiple clicks aggregate correctly in heatmap data', async ({ page }) => {
    test.skip(!(await isProActive(page)), 'WP SlimStat Pro is not installed/active');

    await setSlimstatOptions(page, { addon_heatmap_enable: 'on' });

    const resourcePrefix = `/heatmap-aggregate-${Date.now()}-`;
    await seedPageviews({ count: 1, resourcePrefix });
    const stat = await getLatestStatSummary(resourcePrefix);
    expect(stat).not.toBeNull();

    await seedEventRow(stat!.id, '100,200');
    await seedEventRow(stat!.id, '100,200');
    await seedEventRow(stat!.id, '100,200');
    await seedEventRow(stat!.id, '300,400');
    await seedEventRow(stat!.id, '300,400');

    const nonce = await getHeatmapNonce(page);
    const payload = await callHeatmapEndpoint(page, nonce, `resource starts_with ${resourcePrefix}`);

    expect(payload.data).toHaveLength(2);
    expect(payload.data).toEqual(
      expect.arrayContaining([
        expect.objectContaining({ x: '100', y: '200', value: 3 }),
        expect.objectContaining({ x: '300', y: '400', value: 2 }),
      ]),
    );
    expect(payload.max).toBe(3);
  });

  test('GDPR enabled with granted consent records interaction position via browser click', async ({ browser, page }) => {
    await setSlimstatOptions(page, {
      gdpr_enabled: 'on',
      consent_integration: 'wp_consent_api',
      tracking_request_method: 'rest',
    });

    const { context, page: anonPage } = await createAnonymousPage(browser, () => {
      Object.defineProperty(window, 'wp_has_consent', {
        configurable: true,
        value: () => true,
      });
      Object.defineProperty(window, 'wp_consent_type', {
        configurable: true,
        value: 'optin',
      });
    });

    try {
      const marker = `heatmap-consent-allow-${Date.now()}`;
      const capture = captureTrackingPayloads(anonPage);

      await anonPage.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });

      const stat = await waitForPageviewRow(marker, 15_000);
      expect(stat).not.toBeNull();

      capture.reset();
      await injectClickTarget(anonPage);
      await anonPage.click('#slimstat-e2e-click-target');
      await anonPage.waitForTimeout(2_000);

      expect(capture.payloads.some((payload) => payload.includes('pos='))).toBe(true);

      const event = await waitForEventRow(stat!.id, 10_000);
      expect(event).not.toBeNull();
      expect(event!.position || '').toMatch(/^\d{1,5},\d{1,5}$/);
    } finally {
      await context.close();
    }
  });

  test('GDPR enabled with denied consent does not send interaction tracking', async ({ browser, page }) => {
    await setSlimstatOptions(page, {
      gdpr_enabled: 'on',
      consent_integration: 'wp_consent_api',
      tracking_request_method: 'rest',
    });

    const { context, page: anonPage } = await createAnonymousPage(browser, () => {
      Object.defineProperty(window, 'wp_has_consent', {
        configurable: true,
        value: () => false,
      });
      Object.defineProperty(window, 'wp_consent_type', {
        configurable: true,
        value: 'optin',
      });
    });

    try {
      const marker = `heatmap-consent-deny-${Date.now()}`;
      const capture = captureTrackingPayloads(anonPage);

      await anonPage.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });
      await anonPage.waitForTimeout(1_500);

      capture.reset();
      await injectClickTarget(anonPage);
      await anonPage.click('#slimstat-e2e-click-target');
      await anonPage.waitForTimeout(2_000);

      expect(capture.payloads.some((payload) => payload.includes('pos='))).toBe(false);
      expect(await getEventCount()).toBe(0);
    } finally {
      await context.close();
    }
  });

  test('browser DNT signal blocks interaction tracking requests', async ({ browser, page }) => {
    await setSlimstatOptions(page, {
      do_not_track: 'on',
      tracking_request_method: 'rest',
      gdpr_enabled: 'off',
    });

    const { context, page: anonPage } = await createAnonymousPage(browser, () => {
      Object.defineProperty(Navigator.prototype, 'doNotTrack', {
        configurable: true,
        get: () => '1',
      });
    });

    try {
      const marker = `heatmap-dnt-${Date.now()}`;
      const capture = captureTrackingPayloads(anonPage);

      await anonPage.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });
      await anonPage.waitForTimeout(1_500);

      capture.reset();
      await injectClickTarget(anonPage);
      await anonPage.click('#slimstat-e2e-click-target');
      await anonPage.waitForTimeout(2_000);

      expect(capture.payloads.some((payload) => payload.includes('pos='))).toBe(false);
      expect(await getEventCount()).toBe(0);
    } finally {
      await context.close();
    }
  });
});
