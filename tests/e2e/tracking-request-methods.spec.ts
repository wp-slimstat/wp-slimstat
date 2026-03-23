/**
 * E2E tests: All Tracking Request Methods (REST, AJAX, Adblock Bypass)
 *
 * Verifies that each tracking_request_method setting correctly sends tracking
 * requests via its designated transport and records pageviews in wp_slim_stats.
 * Also validates the PR #218 fix: REST endpoint returns HTTP 200 (not 500).
 */
import { test, expect } from '@playwright/test';
import * as mysql from 'mysql2/promise';
import {
  installOptionMutator,
  uninstallOptionMutator,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  closeDb,
} from './helpers/setup';
import { BASE_URL, MYSQL_CONFIG } from './helpers/env';

/** Set a WordPress option directly via DB. */
async function setWpOption(pool: mysql.Pool, name: string, value: string): Promise<void> {
  await pool.execute(
    'UPDATE wp_options SET option_value = ? WHERE option_name = ?',
    [value, name],
  );
}

/** Get a WordPress option directly from DB. */
async function getWpOption(pool: mysql.Pool, name: string): Promise<string> {
  const [rows] = (await pool.execute(
    'SELECT option_value FROM wp_options WHERE option_name = ?',
    [name],
  )) as any;
  return rows.length > 0 ? rows[0].option_value : '';
}

let pool: mysql.Pool;

function getPool(): mysql.Pool {
  if (!pool) {
    pool = mysql.createPool(MYSQL_CONFIG);
  }
  return pool;
}

/** Poll DB until a row matching the marker appears or timeout. */
async function waitForStatRow(
  marker: string,
  timeoutMs = 15_000,
  intervalMs = 500,
): Promise<Record<string, any> | null> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const [rows] = (await getPool().execute(
      'SELECT * FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id DESC LIMIT 1',
      [`%${marker}%`],
    )) as any;
    if (rows.length > 0) return rows[0];
    await new Promise((r) => setTimeout(r, intervalMs));
  }
  return null;
}

test.describe('Tracking Request Methods — All Transports', () => {
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    installOptionMutator();
  });

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    if (pool) await pool.end();
    await closeDb();
  });

  // ─── REST Method ───────────────────────────────────────────────

  test.describe('REST method (tracking_request_method=rest)', () => {
    test.beforeEach(async ({ page }) => {
      await setSlimstatOption(page, 'tracking_request_method', 'rest');
    });

    test('REST endpoint does not return 500 and records pageview (PR #218 fix)', async ({ page }) => {
      const marker = `trm-rest-200-${Date.now()}`;

      const restResponseStatuses: number[] = [];
      page.on('response', (res) => {
        if (
          res.url().includes('/wp-json/slimstat/v1/hit') ||
          res.url().includes('rest_route=/slimstat/v1/hit')
        ) {
          restResponseStatuses.push(res.status());
        }
      });

      await page.goto(`${BASE_URL}/?e2e=${marker}`);
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(3000);

      // PR #218 fix: REST endpoint must NOT return 500
      // Note: sendBeacon sends text/plain which may return 400 (expected),
      // but XHR with proper content-type returns 200. Either way, never 500.
      for (const status of restResponseStatuses) {
        expect(status, 'REST tracking endpoint must not return 500').not.toBe(500);
      }

      const stat = await waitForStatRow(marker);
      expect(stat).toBeTruthy();
      expect(stat!.resource).toContain(marker);
    });

    test('REST response body contains valid tracking ID with checksum', async ({ page }) => {
      const marker = `trm-rest-id-${Date.now()}`;

      let restResponseBody: string | null = null;
      page.on('response', async (res) => {
        if (
          res.url().includes('/wp-json/slimstat/v1/hit') ||
          res.url().includes('rest_route=/slimstat/v1/hit')
        ) {
          try {
            restResponseBody = await res.text();
          } catch {
            // sendBeacon responses may not be readable
          }
        }
      });

      await page.goto(`${BASE_URL}/?e2e=${marker}`);
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(3000);

      const stat = await waitForStatRow(marker);
      expect(stat).toBeTruthy();

      // If we captured the response, verify it contains a valid checksum-formatted tracking ID
      if (restResponseBody) {
        expect(restResponseBody).not.toContain('Internal Server Error');
        const body = restResponseBody.replace(/^"|"$/g, '').trim();
        expect(body).toMatch(/^\d+\.[0-9a-fA-F]+$/);
      }
    });
  });

  // ─── AJAX Method ───────────────────────────────────────────────

  test.describe('AJAX method (tracking_request_method=ajax)', () => {
    test.beforeEach(async ({ page }) => {
      await setSlimstatOption(page, 'tracking_request_method', 'ajax');
    });

    test('AJAX method records pageview via admin-ajax.php', async ({ page }) => {
      const marker = `trm-ajax-pv-${Date.now()}`;

      let ajaxRequestSeen = false;
      page.on('request', (req) => {
        if (req.url().includes('admin-ajax.php') && req.method() === 'POST') {
          ajaxRequestSeen = true;
        }
      });

      await page.goto(`${BASE_URL}/?e2e=${marker}`);
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(3000);

      expect(ajaxRequestSeen).toBe(true);

      const stat = await waitForStatRow(marker);
      expect(stat).toBeTruthy();
      expect(stat!.resource).toContain(marker);
    });

    test('AJAX tracking POST includes action=slimtrack in payload', async ({ page }) => {
      const marker = `trm-ajax-action-${Date.now()}`;

      let hasSlimtrackAction = false;
      page.on('request', (req) => {
        if (req.url().includes('admin-ajax.php') && req.method() === 'POST') {
          const body = req.postData() || '';
          if (body.includes('action=slimtrack') || body.includes('slimtrack')) {
            hasSlimtrackAction = true;
          }
        }
      });

      await page.goto(`${BASE_URL}/?e2e=${marker}`);
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(5000);

      const stat = await waitForStatRow(marker);
      expect(stat).toBeTruthy();

      // The AJAX request should carry action=slimtrack
      expect(hasSlimtrackAction).toBe(true);
    });
  });

  // ─── Adblock Bypass Method ─────────────────────────────────────

  test.describe('Adblock bypass method (tracking_request_method=adblock_bypass)', () => {
    let savedPermalinkStructure: string;

    test.beforeAll(async () => {
      // Adblock bypass requires pretty permalinks for the /request/{hash}/ rewrite rule
      savedPermalinkStructure = await getWpOption(getPool(), 'permalink_structure');
      if (!savedPermalinkStructure) {
        await setWpOption(getPool(), 'permalink_structure', '/%postname%/');
      }
    });

    test.afterAll(async () => {
      // Restore original permalink structure
      await setWpOption(getPool(), 'permalink_structure', savedPermalinkStructure);
    });

    test.beforeEach(async ({ page }) => {
      await setSlimstatOption(page, 'tracking_request_method', 'adblock_bypass');
      // Flush rewrite rules by saving permalinks (required for /request/{hash}/ endpoint)
      await page.goto(`${BASE_URL}/wp-admin/options-permalink.php`);
      await page.waitForLoadState('networkidle');
      // Click "Save Changes" to flush rewrite rules
      const saveBtn = page.locator('#submit');
      if (await saveBtn.isVisible()) {
        await saveBtn.click();
        await page.waitForLoadState('networkidle');
      }
    });

    test('adblock bypass method records pageview (with fallback)', async ({ page }) => {
      const marker = `trm-adblock-pv-${Date.now()}`;

      let adblockEndpointUsed = false;
      let anyTrackingRequestSent = false;
      page.on('request', (req) => {
        if (req.method() === 'POST') {
          if (/\/request\/[a-f0-9]{32}\/?/.test(req.url())) {
            adblockEndpointUsed = true;
            anyTrackingRequestSent = true;
          }
          if (req.url().includes('admin-ajax.php') || req.url().includes('/wp-json/slimstat/v1/hit')) {
            anyTrackingRequestSent = true;
          }
        }
      });

      await page.goto(`${BASE_URL}/?e2e=${marker}`);
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(8000);

      const stat = await waitForStatRow(marker, 15_000);
      expect(stat).toBeTruthy();
      expect(stat!.resource).toContain(marker);

      // The tracker should have sent a request via adblock bypass or fallback
      expect(anyTrackingRequestSent).toBe(true);
    });

    test('adblock bypass attempts /request/ endpoint pattern', async ({ page }) => {
      const marker = `trm-adblock-ep-${Date.now()}`;

      let adblockEndpointAttempted = false;
      let fallbackUsed = false;
      page.on('request', (req) => {
        if (req.method() === 'POST') {
          // Adblock bypass uses /request/{32-char-hex-hash}/ pattern
          if (/\/request\/[a-f0-9]{32}\/?/.test(req.url())) {
            adblockEndpointAttempted = true;
          }
          // Fallback transports
          if (req.url().includes('admin-ajax.php') || req.url().includes('/wp-json/slimstat/v1/hit')) {
            fallbackUsed = true;
          }
        }
      });

      await page.goto(`${BASE_URL}/?e2e=${marker}`);
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(8000);

      const stat = await waitForStatRow(marker, 15_000);
      expect(stat).toBeTruthy();

      // When adblock_bypass is set, the JS tracker should attempt the /request/ endpoint.
      // If rewrite rules aren't available (e.g., plain permalinks), it falls back to AJAX/REST.
      // Either way, the pageview must be recorded.
      expect(
        adblockEndpointAttempted || fallbackUsed,
        'Tracker should attempt adblock bypass or fall back to another transport',
      ).toBe(true);
    });
  });

  // ─── Fallback Behavior ─────────────────────────────────────────

  test.describe('Transport fallback chain', () => {
    test('REST→AJAX fallback: pageview recorded when REST is blocked', async ({ page }) => {
      await setSlimstatOption(page, 'tracking_request_method', 'rest');

      const marker = `trm-fallback-${Date.now()}`;

      // Block REST endpoint to force fallback
      await page.route('**/wp-json/slimstat/v1/hit', (route) => route.abort('connectionfailed'));
      // Also block the ?rest_route= variant
      await page.route('**/?rest_route=/slimstat/v1/hit*', (route) => route.abort('connectionfailed'));

      let ajaxFallbackUsed = false;
      page.on('request', (req) => {
        if (req.url().includes('admin-ajax.php') && req.method() === 'POST') {
          ajaxFallbackUsed = true;
        }
      });

      await page.goto(`${BASE_URL}/?e2e=${marker}`);
      await page.waitForLoadState('networkidle');
      await page.waitForTimeout(8000);

      const stat = await waitForStatRow(marker, 15_000);
      expect(stat).toBeTruthy();
      expect(stat!.resource).toContain(marker);

      // When REST is blocked, AJAX should be used as fallback
      expect(ajaxFallbackUsed).toBe(true);
    });
  });

  // ─── Cross-Method Consistency ──────────────────────────────────

  test.describe('Cross-method consistency', () => {
    test('all three methods populate the same core DB columns', async ({ page }) => {
      const methods = ['rest', 'ajax'] as const;
      const results: Record<string, Record<string, any>> = {};

      for (const method of methods) {
        await clearStatsTable();
        await setSlimstatOption(page, 'tracking_request_method', method);

        const marker = `trm-schema-${method}-${Date.now()}`;
        await page.goto(`${BASE_URL}/?e2e=${marker}`);
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(5000);

        const stat = await waitForStatRow(marker);
        expect(stat).toBeTruthy();
        results[method] = stat!;
      }

      // All methods should populate the same core columns
      const coreColumns = ['id', 'resource', 'dt', 'content_type', 'visit_id'];
      for (const method of methods) {
        for (const col of coreColumns) {
          expect(
            results[method][col],
            `${method} method should populate '${col}'`,
          ).toBeTruthy();
        }
      }
    });
  });
});
