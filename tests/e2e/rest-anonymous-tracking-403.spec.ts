/**
 * E2E tests: REST anonymous tracking — 403 nonce regression + adblock fallback URL
 *
 * Validates issue #238: anonymous visitors should NOT get a 403 from nonce
 * validation, and adblock fallback URL should only be served when the rewrite
 * rule is active.
 *
 * @see https://github.com/wp-slimstat/wp-slimstat/issues/238
 */
import { test, expect } from '@playwright/test';
import * as mysql from 'mysql2/promise';
import {
  clearStatsTable,
  waitForPageviewRow,
  closeDb,
} from './helpers/setup';
import { BASE_URL, MYSQL_CONFIG } from './helpers/env';

let pool: mysql.Pool | null = null;

function getPool(): mysql.Pool {
  if (!pool) {
    pool = mysql.createPool(MYSQL_CONFIG);
  }
  return pool;
}

/** Snapshot and restore slimstat_options directly via DB. */
let savedOptions: string | null = null;

async function snapshotOptions(): Promise<void> {
  const [rows] = (await getPool().execute(
    "SELECT option_value FROM wp_options WHERE option_name = 'slimstat_options'",
  )) as any;
  savedOptions = rows.length > 0 ? rows[0].option_value : null;
}

async function restoreOptions(): Promise<void> {
  if (savedOptions !== null) {
    await getPool().execute(
      "UPDATE wp_options SET option_value = ? WHERE option_name = 'slimstat_options'",
      [savedOptions],
    );
  }
}

/**
 * Set a slimstat option directly in the DB by parsing PHP serialized data.
 *
 * Limitations: Only supports simple string keys and string values.
 * Does NOT handle escaped quotes, non-string types (int/bool/array), or
 * nested structures. Intended only for this test's limited option keys
 * (tracking_request_method, gdpr_enabled, javascript_mode, ignore_wp_users).
 */
async function setOption(key: string, value: string): Promise<void> {
  const [rows] = (await getPool().execute(
    "SELECT option_value FROM wp_options WHERE option_name = 'slimstat_options'",
  )) as any;
  if (rows.length === 0) return;

  let serialized: string = rows[0].option_value;

  // PHP serialized format: s:N:"key";s:N:"value";
  // We need to find and replace the value for the given key
  const keyPattern = `s:${key.length}:"${key}";`;
  const keyIdx = serialized.indexOf(keyPattern);

  if (keyIdx === -1) {
    // Key not found — append it before the closing }
    // Find the array count at the beginning: a:N:{...}
    const match = serialized.match(/^a:(\d+):\{/);
    if (match) {
      const oldCount = parseInt(match[1], 10);
      const newCount = oldCount + 1;
      serialized = serialized.replace(`a:${oldCount}:{`, `a:${newCount}:{`);
      // Insert before the final }
      const lastBrace = serialized.lastIndexOf('}');
      const entry = `s:${key.length}:"${key}";s:${value.length}:"${value}";`;
      serialized = serialized.substring(0, lastBrace) + entry + '}';
    }
  } else {
    // Key found — replace the value that follows it
    const valueStart = keyIdx + keyPattern.length;
    // The value is: s:N:"..."; — find the next semicolon-terminated string
    const valueMatch = serialized.substring(valueStart).match(/^s:\d+:"[^"]*";/);
    if (valueMatch) {
      const oldValue = valueMatch[0];
      const newValue = `s:${value.length}:"${value}";`;
      serialized = serialized.substring(0, valueStart) + newValue + serialized.substring(valueStart + oldValue.length);
    }
  }

  await getPool().execute(
    "UPDATE wp_options SET option_value = ? WHERE option_name = 'slimstat_options'",
    [serialized],
  );
}

test.describe('REST Anonymous Tracking — Issue #238', () => {
  test.setTimeout(60_000);

  test.beforeEach(async () => {
    await snapshotOptions();
    await clearStatsTable();
    // Set options via DB
    await setOption('tracking_request_method', 'rest');
    await setOption('gdpr_enabled', 'off');
    await setOption('javascript_mode', 'on');
    await setOption('ignore_wp_users', 'no');
  });

  test.afterEach(async () => {
    await restoreOptions();
  });

  test.afterAll(async () => {
    if (pool) {
      await pool.end();
      pool = null;
    }
    await closeDb();
  });

  test('anonymous REST tracking should succeed with exactly one request (no nonce retry)', async ({ browser }) => {
    // Use a fresh browser context with no cookies (anonymous visitor)
    const ctx = await browser.newContext();
    const anonPage = await ctx.newPage();

    const restRequests: Array<{ url: string; headers: Record<string, string> }> = [];
    const restResponses: Array<{ status: number; url: string }> = [];

    anonPage.on('request', (req) => {
      if (
        req.url().includes('/wp-json/slimstat/v1/hit') ||
        req.url().includes('rest_route=/slimstat/v1/hit')
      ) {
        restRequests.push({ url: req.url(), headers: req.headers() });
      }
    });

    anonPage.on('response', async (res) => {
      if (
        res.url().includes('/wp-json/slimstat/v1/hit') ||
        res.url().includes('rest_route=/slimstat/v1/hit')
      ) {
        restResponses.push({ status: res.status(), url: res.url() });
      }
    });

    const marker = `anon-nonce-${Date.now()}`;
    await anonPage.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'networkidle' });
    await anonPage.waitForTimeout(3000);

    // There should be at least one REST request
    expect(restRequests.length).toBeGreaterThan(0);

    // CRITICAL: Anonymous visitors should NOT send X-WP-Nonce header.
    // When nonce is sent, it causes a 403 on cached pages (stale nonce),
    // triggering an unnecessary retry. Without nonce, the request succeeds
    // on the first try since permission_callback is __return_true.
    const requestsWithNonce = restRequests.filter(
      (r) => r.headers['x-wp-nonce'] !== undefined,
    );
    expect(
      requestsWithNonce.length,
      `Expected no requests with X-WP-Nonce header for anonymous users. Found ${requestsWithNonce.length} requests with nonce.`,
    ).toBe(0);

    // Exactly one REST hit request (no retry needed)
    const hitRequests = restRequests.filter(
      (r) => r.url.includes('/slimstat/v1/hit'),
    );
    expect(hitRequests.length, 'Should make exactly one hit request (no retry)').toBe(1);

    await ctx.close();
  });

  test('adblock fallback URL should not be injected when transport is REST', async ({ browser }) => {
    const ctx = await browser.newContext();
    const anonPage = await ctx.newPage();

    const marker = `adblock-url-${Date.now()}`;
    await anonPage.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });

    // Extract SlimStatParams from the page
    const params = await anonPage.evaluate(() => {
      return (window as any).SlimStatParams || null;
    });

    expect(params).toBeTruthy();

    // When transport is 'rest', ajaxurl_adblock should NOT be set
    expect(
      params.ajaxurl_adblock,
      'ajaxurl_adblock should not be set when transport is rest — the rewrite rule is not active',
    ).toBeFalsy();

    await ctx.close();
  });

  test('anonymous REST tracking should record a pageview in the database', async ({ browser }) => {
    const ctx = await browser.newContext();
    const anonPage = await ctx.newPage();

    const marker = `anon-pv-${Date.now()}`;
    await anonPage.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'networkidle' });
    await anonPage.waitForTimeout(3000);

    const stat = await waitForPageviewRow(marker, 15_000);
    expect(stat, 'Anonymous visitor pageview should be recorded when GDPR is off').toBeTruthy();

    await ctx.close();
  });

  test('anonymous: nonce present (for consent) but is_logged_in is 0 (no header)', async ({ browser }) => {
    const ctx = await browser.newContext();
    const anonPage = await ctx.newPage();
    await anonPage.goto(`${BASE_URL}/?e2e=nonce-check-anon`, { waitUntil: 'domcontentloaded' });
    const anonParams = await anonPage.evaluate(() => (window as any).SlimStatParams || null);
    expect(anonParams).toBeTruthy();
    // Nonce IS present (needed for consent banner CSRF protection)
    expect(anonParams.wp_rest_nonce, 'wp_rest_nonce must be present for consent operations').toBeTruthy();
    // But is_logged_in is '0' so JS won't send it as X-WP-Nonce header
    expect(anonParams.is_logged_in, 'is_logged_in must be 0 for anonymous').toBe('0');

    await ctx.close();
  });
});
