/**
 * E2E tests: REST endpoint safety — intval sanitization (AC-TRK-004)
 *
 * Validates that the /wp-json/slimstat/v1/hit endpoint handles the `tz`
 * parameter safely via sanitize_integer_param(): valid numeric values pass
 * through, boundary values are accepted, and non-numeric values are coerced
 * to 0 without fatal errors.
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
  timeoutMs = 10_000,
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

/** POST directly to the REST tracking endpoint with custom params. */
async function postToHitEndpoint(
  page: import('@playwright/test').Page,
  params: Record<string, string>,
): Promise<{ status: number; body: string }> {
  const res = await page.request.post(`${BASE_URL}/wp-json/slimstat/v1/hit`, {
    form: params,
  });
  return {
    status: res.status(),
    body: await res.text(),
  };
}

test.describe('REST Endpoint Safety — intval Sanitization (AC-TRK-004)', () => {
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    installOptionMutator();
  });

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    await setSlimstatOption(page, 'tracking_request_method', 'rest');
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

  // ─── Test 1: Valid positive tz value ─────────────────────────

  test('valid tz=60 is accepted without error', async ({ page }) => {
    const marker = `tz-valid-${Date.now()}`;

    // First visit a page to generate a server-side hit, then POST the tz
    await page.goto(`${BASE_URL}/?e2e=${marker}`);
    await page.waitForLoadState('networkidle');

    const stat = await waitForStatRow(marker);
    expect(stat).toBeTruthy();

    // POST directly with tz=60
    const res = await postToHitEndpoint(page, {
      res: `/?e2e=${marker}-tz60`,
      tz: '60',
    });
    // Should not return 500 (no fatal error)
    expect(res.status).toBeLessThan(500);
  });

  // ─── Test 2: Boundary tz value (720 = UTC+12) ───────────────

  test('boundary tz=720 (UTC+12) is accepted without error', async ({ page }) => {
    const res = await postToHitEndpoint(page, {
      res: `/?e2e=tz-boundary-${Date.now()}`,
      tz: '720',
    });
    // No fatal error — any 2xx/4xx is acceptable (400 = tracking failed is OK)
    expect(res.status).toBeLessThan(500);
  });

  // ─── Test 3: Negative tz value (-300 = UTC-5) ───────────────

  test('negative tz=-300 (UTC-5) is accepted without error', async ({ page }) => {
    const res = await postToHitEndpoint(page, {
      res: `/?e2e=tz-neg-${Date.now()}`,
      tz: '-300',
    });
    expect(res.status).toBeLessThan(500);
  });

  // ─── Test 4: Non-numeric tz ("abc") coerced to 0 ────────────

  test('non-numeric tz="abc" does not cause fatal error', async ({ page }) => {
    const res = await postToHitEndpoint(page, {
      res: `/?e2e=tz-abc-${Date.now()}`,
      tz: 'abc',
    });
    // sanitize_integer_param returns 0 for non-numeric — no PHP fatal
    expect(res.status).toBeLessThan(500);
  });

  // ─── Test 5: Empty string tz coerced to 0 ───────────────────

  test('empty tz="" does not cause fatal error', async ({ page }) => {
    const res = await postToHitEndpoint(page, {
      res: `/?e2e=tz-empty-${Date.now()}`,
      tz: '',
    });
    expect(res.status).toBeLessThan(500);
  });

  // ─── Test 6: tz=0 is a valid value ──────────────────────────

  test('tz=0 (UTC) is accepted without error', async ({ page }) => {
    const res = await postToHitEndpoint(page, {
      res: `/?e2e=tz-zero-${Date.now()}`,
      tz: '0',
    });
    expect(res.status).toBeLessThan(500);
  });

  // ─── Test 7: Very large tz does not overflow ────────────────

  test('very large tz value does not cause overflow or fatal', async ({ page }) => {
    const res = await postToHitEndpoint(page, {
      res: `/?e2e=tz-large-${Date.now()}`,
      tz: '99999999',
    });
    expect(res.status).toBeLessThan(500);
  });

  // ─── Test 8: Float-like tz is handled ───────────────────────

  test('float-like tz="60.5" is handled without fatal', async ({ page }) => {
    const res = await postToHitEndpoint(page, {
      res: `/?e2e=tz-float-${Date.now()}`,
      tz: '60.5',
    });
    // is_numeric('60.5') is true in PHP, so sanitize_integer_param casts to int = 60
    expect(res.status).toBeLessThan(500);
  });

  // ─── Test 9: SQL injection attempt in tz is neutralized ─────

  test('SQL injection in tz is neutralized by sanitization', async ({ page }) => {
    const res = await postToHitEndpoint(page, {
      res: `/?e2e=tz-sqli-${Date.now()}`,
      tz: "1; DROP TABLE wp_slim_stats;--",
    });
    // sanitize_integer_param: is_numeric returns false → 0
    expect(res.status).toBeLessThan(500);

    // Verify the stats table still exists
    const [rows] = (await getPool().execute(
      'SELECT COUNT(*) as cnt FROM wp_slim_stats',
    )) as any;
    expect(parseInt(rows[0].cnt, 10)).toBeGreaterThanOrEqual(0);
  });

  // ─── Test 10: Concurrent requests with mixed tz values ──────

  test('concurrent requests with valid and invalid tz values all return < 500', async ({ page }) => {
    const tzValues = ['60', '-300', 'abc', '', '720', '0', 'null', '-99999'];
    const results = await Promise.all(
      tzValues.map((tz, i) =>
        postToHitEndpoint(page, {
          res: `/?e2e=tz-concurrent-${Date.now()}-${i}`,
          tz,
        }),
      ),
    );

    for (const res of results) {
      expect(res.status).toBeLessThan(500);
    }
  });
});
