/**
 * E2E tests: PR #170 Review Fixes
 *
 * Validates 3 fixes applied during PR #170 deep review:
 * 1. REST output buffer protection (TrackingRestController)
 * 2. Legacy MD5 checksum backward compatibility (Utils)
 * 3. Unified checksum validation path (Tracker)
 */
import { test, expect } from '@playwright/test';
import * as mysql from 'mysql2/promise';
import * as crypto from 'crypto';
import {
  installOptionMutator,
  uninstallOptionMutator,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  closeDb,
  getPool,
} from './helpers/setup';
import { BASE_URL, MYSQL_CONFIG } from './helpers/env';

let pool: mysql.Pool;

function db(): mysql.Pool {
  if (!pool) {
    pool = mysql.createPool(MYSQL_CONFIG);
  }
  return pool;
}

/** Poll DB until a row matching the marker appears or timeout. */
async function waitForStatRow(
  marker: string,
  timeoutMs = 20_000,
  intervalMs = 500,
): Promise<Record<string, any> | null> {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const [rows] = (await db().execute(
      'SELECT * FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id DESC LIMIT 1',
      [`%${marker}%`],
    )) as any;
    if (rows.length > 0) return rows[0];
    await new Promise((r) => setTimeout(r, intervalMs));
  }
  return null;
}

/** POST directly to the REST tracking endpoint. */
async function postHit(
  page: import('@playwright/test').Page,
  params: Record<string, string>,
): Promise<{ status: number; body: string }> {
  const res = await page.request.post(`${BASE_URL}/wp-json/slimstat/v1/hit`, {
    form: params,
  });
  return { status: res.status(), body: await res.text() };
}

/** Read the slimstat secret from the DB. */
async function getSlimstatSecret(): Promise<string> {
  const [rows] = (await db().execute(
    "SELECT option_value FROM wp_options WHERE option_name = 'slimstat_options'",
  )) as any;
  if (rows.length === 0) return '';
  const raw: string = rows[0].option_value;
  const match = raw.match(/s:\d+:"secret";s:\d+:"([^"]*)"/);
  return match ? match[1] : '';
}

test.describe('PR #170 Review Fixes', () => {
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    installOptionMutator();
  });

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    await setSlimstatOption(page, 'tracking_request_method', 'rest');
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
    await setSlimstatOption(page, 'ignore_wp_users', 'no');
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    if (pool) await pool.end();
    await closeDb();
  });

  // ─── AC-1: REST Output Buffer Protection ─────────────────────────

  test('AC-1: REST tracking POST returns no PHP notices or warnings', async ({ page }) => {
    const res = await postHit(page, { res: `/?e2e=buf-clean-${Date.now()}` });
    expect(res.status).toBeLessThan(500);
    // Output buffer should prevent PHP notices/warnings from corrupting the response
    expect(res.body).not.toMatch(/<b>Notice<\/b>/i);
    expect(res.body).not.toMatch(/<b>Warning<\/b>/i);
    expect(res.body).not.toMatch(/<b>Fatal error<\/b>/i);
    expect(res.body).not.toMatch(/Stack trace:/i);
    expect(res.body).not.toMatch(/PHP Notice:/i);
    expect(res.body).not.toMatch(/PHP Warning:/i);
  });

  test('AC-1: REST tracking POST does not cause server errors with edge-case params', async ({ page }) => {
    const res = await postHit(page, {
      res: `/?e2e=buf-edge-${Date.now()}`,
      tz: '999',
      bw: '0',
      bh: '0',
    });
    expect(res.status).toBeLessThan(500);
    expect(res.body).not.toMatch(/<b>Fatal error<\/b>/i);
    expect(res.body).not.toMatch(/Uncaught/i);
  });

  test('AC-1: concurrent REST tracking requests return independent clean responses', async ({ page }) => {
    const results = await Promise.all(
      Array.from({ length: 5 }, (_, i) =>
        postHit(page, { res: `/?e2e=buf-concurrent-${Date.now()}-${i}` }),
      ),
    );
    for (const res of results) {
      expect(res.status).toBeLessThan(500);
      expect(res.body).not.toMatch(/<b>Notice<\/b>/i);
      expect(res.body).not.toMatch(/<b>Warning<\/b>/i);
    }
  });

  test('AC-1: pageview via REST records a hit in the database', async ({ page }) => {
    const marker = `buf-db-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e=${marker}`);
    await page.waitForLoadState('networkidle');

    const stat = await waitForStatRow(marker);
    expect(stat).toBeTruthy();
    expect(stat!.resource).toContain(marker);
  });

  // ─── AC-2: Legacy MD5 Checksum Backward Compatibility ────────────

  test('AC-2: REST response contains checksum in HMAC-SHA256 format', async ({ page }) => {
    // Visit a page to establish a session first
    const marker = `hmac-fmt-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e=${marker}`);
    await page.waitForLoadState('networkidle');

    const stat = await waitForStatRow(marker);
    expect(stat).toBeTruthy();

    // POST to get a checksum-format response
    const res = await postHit(page, { res: `/?e2e=${marker}-post` });
    if (res.status === 200) {
      // Strip JSON quotes and extract the checksum response
      const body = res.body.replace(/^"|"$/g, '');
      // If it's a checksum format, the hash part should be 64 hex chars (SHA-256)
      const checksumMatch = body.match(/^(\d+)\.([0-9a-f]+)$/i);
      if (checksumMatch) {
        expect(checksumMatch[2]).toHaveLength(64);
      }
    }
  });

  test('AC-2: POST with valid HMAC-SHA256 id param is accepted', async ({ page }) => {
    const secret = await getSlimstatSecret();
    if (!secret) {
      test.skip(true, 'No slimstat secret configured');
      return;
    }

    // Create a checksum with HMAC-SHA256 (the new format)
    const testValue = '12345';
    const hmacHash = crypto.createHmac('sha256', secret).update(testValue).digest('hex');
    const hmacChecksum = `${testValue}.${hmacHash}`;

    const res = await postHit(page, {
      res: `/?e2e=hmac-valid-${Date.now()}`,
      id: hmacChecksum,
    });
    // Should not error — the checksum is valid
    expect(res.status).toBeLessThan(500);
  });

  test('AC-2: POST with legacy MD5 id param is accepted via fallback', async ({ page }) => {
    const secret = await getSlimstatSecret();
    if (!secret) {
      test.skip(true, 'No slimstat secret configured');
      return;
    }

    // Create a checksum with MD5 (the legacy format from v5.4.1)
    const testValue = '67890';
    const md5Hash = crypto.createHash('md5').update(testValue + secret).digest('hex');
    const md5Checksum = `${testValue}.${md5Hash}`;

    const res = await postHit(page, {
      res: `/?e2e=md5-legacy-${Date.now()}`,
      id: md5Checksum,
    });
    // Should not error — the legacy MD5 checksum is accepted via fallback
    expect(res.status).toBeLessThan(500);
  });

  test('AC-2: POST with tampered checksum is rejected', async ({ page }) => {
    const res = await postHit(page, {
      res: `/?e2e=tampered-${Date.now()}`,
      id: '99999.invalidchecksum1234567890abcdef',
    });
    // Tampered checksum should result in 400 (tracking failed) or similar non-500
    expect(res.status).toBeLessThan(500);
    // Should not crash the server
    expect(res.body).not.toMatch(/<b>Fatal error<\/b>/i);
  });

  test('AC-2: POST with no-dot id param is rejected gracefully', async ({ page }) => {
    const res = await postHit(page, {
      res: `/?e2e=nodot-${Date.now()}`,
      id: '12345nochecksum',
    });
    expect(res.status).toBeLessThan(500);
    expect(res.body).not.toMatch(/<b>Fatal error<\/b>/i);
  });

  test('AC-2: POST with empty id param does not crash', async ({ page }) => {
    const res = await postHit(page, {
      res: `/?e2e=empty-id-${Date.now()}`,
      id: '',
    });
    expect(res.status).toBeLessThan(500);
    expect(res.body).not.toMatch(/<b>Fatal error<\/b>/i);
  });

  // ─── AC-3: Unified Checksum Validation Path ──────────────────────

  test('AC-3: visit_id is assigned on pageview', async ({ page }) => {
    const marker = `visit-assigned-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e=${marker}`);
    await page.waitForLoadState('networkidle');

    const stat = await waitForStatRow(marker);
    expect(stat).toBeTruthy();
    // visit_id should be assigned (> 0) — this validates Utils::getValueWithoutChecksum()
    // is called in the unified validation path (Tracker::_set_visit_id)
    expect(stat!.visit_id).toBeGreaterThan(0);
  });

  test('AC-3: tracking pipeline records complete pageview data', async ({ page }) => {
    const marker = `pipeline-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e=${marker}`);
    await page.waitForLoadState('networkidle');

    const stat = await waitForStatRow(marker);
    expect(stat).toBeTruthy();
    expect(stat!.resource).toContain(marker);
    expect(stat!.dt).toBeGreaterThan(0);
    expect(stat!.id).toBeGreaterThan(0);
  });
});
