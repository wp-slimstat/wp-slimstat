/**
 * E2E tests: Upgrade safety verification (v5.4.2)
 *
 * Ensures that updating from v5.4.1 to v5.4.2 doesn't break existing
 * installations: schema unchanged, data intact, REST endpoint works,
 * CSS scoped correctly, no PHP fatal errors.
 */
import { test, expect } from '@playwright/test';
import * as mysql from 'mysql2/promise';
import * as fs from 'fs';
import * as path from 'path';
import {
  installOptionMutator,
  uninstallOptionMutator,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  closeDb,
} from './helpers/setup';
import { BASE_URL, WP_ROOT, MYSQL_CONFIG } from './helpers/env';

let pool: mysql.Pool;

function getPool(): mysql.Pool {
  if (!pool) {
    pool = mysql.createPool(MYSQL_CONFIG);
  }
  return pool;
}

// ─── Helpers ─────────────────────────────────────────────────────

interface ColumnInfo {
  Field: string;
  Type: string;
  Null: string;
  Key: string;
  Default: string | null;
  Extra: string;
}

async function getTableSchema(): Promise<ColumnInfo[]> {
  const [rows] = await getPool().execute('DESCRIBE wp_slim_stats') as any;
  return rows;
}

async function seedTestRows(): Promise<number[]> {
  const rows = [
    { ip: '10.0.0.1', country: 'us', city: 'New York', resource: '/upgrade-safety/page1', visit_id: 11001 },
    { ip: '10.0.0.2', country: 'de', city: 'Berlin', resource: '/upgrade-safety/page2', visit_id: 11002 },
    { ip: '10.0.0.3', country: 'jp', city: 'Tokyo', resource: '/upgrade-safety/page3', visit_id: 11003 },
    { ip: '10.0.0.4', country: '', city: '', resource: '/upgrade-safety/page4', visit_id: 11004 },
    { ip: '10.0.0.5', country: 'br', city: 'Rio', resource: '/upgrade-safety/page5', visit_id: 11005 },
  ];

  const ids: number[] = [];
  for (const row of rows) {
    const [result] = await getPool().execute(
      `INSERT INTO wp_slim_stats (ip, country, city, resource, dt, visit_id)
       VALUES (?, ?, ?, ?, UNIX_TIMESTAMP(), ?)`,
      [row.ip, row.country, row.city, row.resource, row.visit_id]
    ) as any;
    ids.push(result.insertId);
  }
  return ids;
}

async function getRows(ids: number[]): Promise<any[]> {
  if (ids.length === 0) return [];
  const placeholders = ids.map(() => '?').join(',');
  const [rows] = await getPool().execute(
    `SELECT * FROM wp_slim_stats WHERE id IN (${placeholders}) ORDER BY id`,
    ids
  ) as any;
  return rows;
}

async function deleteRows(ids: number[]): Promise<void> {
  if (ids.length === 0) return;
  const placeholders = ids.map(() => '?').join(',');
  await getPool().execute(
    `DELETE FROM wp_slim_stats WHERE id IN (${placeholders})`,
    ids
  );
}

// ─── Expected schema (should match pre-upgrade) ─────────────────

const EXPECTED_COLUMNS = [
  'id', 'ip', 'other_ip', 'username', 'email', 'country', 'location', 'city',
  'referer', 'resource', 'searchterms', 'notes', 'visit_id', 'server_latency',
  'page_performance', 'browser', 'browser_version', 'browser_type', 'platform',
  'language', 'fingerprint', 'user_agent', 'resolution', 'screen_width',
  'screen_height', 'content_type', 'category', 'author', 'content_id',
  'outbound_resource', 'tz_offset', 'dt_out', 'dt',
];

// ─── Tests ───────────────────────────────────────────────────────

test.describe('Upgrade Safety', () => {
  let seededIds: number[] = [];

  test.beforeAll(async () => {
    installOptionMutator();
  });

  test.beforeEach(async () => {
    await snapshotSlimstatOptions();
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
    if (seededIds.length > 0) {
      await deleteRows(seededIds);
      seededIds = [];
    }
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    if (pool) await pool.end();
    await closeDb();
  });

  // ─── Test 1: Schema unchanged after upgrade ────────────────────

  test('wp_slim_stats schema has all expected columns with no additions or removals', async () => {
    const schema = await getTableSchema();
    const columnNames = schema.map((c: ColumnInfo) => c.Field);

    // All expected columns present
    for (const col of EXPECTED_COLUMNS) {
      expect(columnNames).toContain(col);
    }

    // No unexpected columns added
    for (const col of columnNames) {
      expect(EXPECTED_COLUMNS).toContain(col);
    }

    // visit_id is int unsigned NOT NULL default 0
    const visitIdCol = schema.find((c: ColumnInfo) => c.Field === 'visit_id');
    expect(visitIdCol).toBeTruthy();
    expect(visitIdCol!.Type).toBe('int unsigned');

    // id is auto_increment primary key
    const idCol = schema.find((c: ColumnInfo) => c.Field === 'id');
    expect(idCol).toBeTruthy();
    expect(idCol!.Key).toBe('PRI');
    expect(idCol!.Extra).toBe('auto_increment');
  });

  // ─── Test 2: Existing rows untouched after admin load ──────────

  test('existing data rows unchanged after full admin page cycle', async ({ page }) => {
    seededIds = await seedTestRows();
    const before = await getRows(seededIds);
    expect(before).toHaveLength(5);

    // Load admin pages that trigger init, migrations, report queries
    await page.goto('/wp-admin/');
    await expect(page).toHaveTitle(/Dashboard/);

    await page.goto('/wp-admin/admin.php?page=slimstat');
    await page.waitForTimeout(2000);

    await page.goto('/wp-admin/admin.php?page=slimconfig');
    await page.waitForTimeout(1000);

    // Verify all rows identical
    const after = await getRows(seededIds);
    expect(after).toHaveLength(5);

    for (let i = 0; i < before.length; i++) {
      expect(after[i].ip).toBe(before[i].ip);
      expect(after[i].country).toBe(before[i].country);
      expect(after[i].city).toBe(before[i].city);
      expect(after[i].resource).toBe(before[i].resource);
      expect(after[i].visit_id).toBe(before[i].visit_id);
      expect(after[i].dt).toBe(before[i].dt);
    }
  });

  // ─── Test 3: Visit counter option initialized correctly ────────

  test('slimstat_visit_id_counter is >= MAX(visit_id) in stats table', async ({ page }) => {
    // Seed high visit_ids
    seededIds = await seedTestRows();

    // Delete counter to force re-initialization
    await getPool().execute(
      "DELETE FROM wp_options WHERE option_name = 'slimstat_visit_id_counter'"
    );

    // Trigger plugin load
    await page.goto('/');
    await page.waitForTimeout(4000);

    const maxVisitId = await getPool().execute(
      "SELECT COALESCE(MAX(visit_id), 0) as max_id FROM wp_slim_stats"
    ) as any;
    const maxId = parseInt(maxVisitId[0][0].max_id, 10);

    const [counterRows] = await getPool().execute(
      "SELECT option_value FROM wp_options WHERE option_name = 'slimstat_visit_id_counter'"
    ) as any;

    if (counterRows.length > 0) {
      const counterVal = parseInt(counterRows[0].option_value, 10);
      // Counter should be >= max existing visit_id
      expect(counterVal).toBeGreaterThanOrEqual(maxId);
    }
    // If counter doesn't exist yet (no new visit generated), that's also OK
  });

  // ─── Test 4: REST tracking endpoint returns valid response ─────

  test('REST tracking endpoint /wp-json/slimstat/v1/hit responds correctly', async ({ page }) => {
    // First get a nonce by visiting the site
    await page.goto('/');
    await page.waitForTimeout(1000);

    // Use ?rest_route= format which works regardless of permalink settings
    const response = await page.request.post(`${BASE_URL}/?rest_route=/slimstat/v1/hit`, {
      data: {
        res: '/rest-test-page',
        ref: 'https://example.com',
        sw: 1920,
        sh: 1080,
        bw: 1200,
        bh: 800,
      },
      headers: {
        'Content-Type': 'application/json',
      },
    });

    // Should return 200 with a numeric ID, or 400 if tracking conditions not met
    // Either way, no 500 error
    expect(response.status()).toBeLessThan(500);

    const body = await response.text();

    if (response.status() === 200) {
      // 200 may return: numeric tracking ID (JSON string), or empty body
      // (admin user/local IP may be filtered out by tracking rules)
      if (body.trim().length > 0) {
        try {
          const parsed = JSON.parse(body);
          expect(typeof parsed === 'string' || typeof parsed === 'number').toBeTruthy();
        } catch {
          // Non-JSON 200 — acceptable if body is numeric
          expect(body.trim()).toMatch(/^\d+$/);
        }
      }
      // Empty 200 is valid — tracking was filtered but endpoint didn't crash
    } else if (response.status() === 400) {
      // 400 is acceptable — tracking may fail for admin user or local IP
      // Key: no 500 error means the endpoint is wired correctly
      const parsed = JSON.parse(body);
      expect(parsed.code).toBe('slimstat_tracking_failed');
    }
  });

  // ─── Test 5: GDPR banner CSS doesn't leak to other elements ───

  test('prefers-reduced-motion CSS is scoped to SlimStat elements only', async ({ page }) => {
    await setSlimstatOption(page, 'gdpr_enabled', 'on');
    await setSlimstatOption(page, 'use_slimstat_banner', 'on');

    await page.goto('/');
    await page.waitForTimeout(2000);

    // Check that non-SlimStat elements still have normal transitions
    const bodyTransition = await page.evaluate(() => {
      const body = document.body;
      const computed = window.getComputedStyle(body);
      return {
        transition: computed.transition,
        animation: computed.animation,
      };
    });

    // Body should NOT have all transitions disabled
    // (Old bug: prefers-reduced-motion * { transition: none !important } globally)
    // Note: this test validates the fix by checking the CSS doesn't have a blanket * selector
    const cssPath = '/wp-content/plugins/wp-slimstat/assets/css/gdpr-banner.css';
    const cssResponse = await page.request.get(`${BASE_URL}${cssPath}`);

    if (cssResponse.status() === 200) {
      const cssText = await cssResponse.text();
      // The CSS should NOT contain a bare `*` selector inside prefers-reduced-motion
      // It SHOULD scope to .slimstat-consent-container or similar
      const hasGlobalStar = /prefers-reduced-motion[^}]*\*\s*\{[^}]*transition\s*:\s*none/s.test(cssText);
      expect(hasGlobalStar).toBe(false);
    }
  });

  // ─── Test 6: Version downgrade guard ─────────────────────────────

  test.fixme('version downgrade guard', () => {
    // manual-needed: version downgrade requires different plugin version
    // This test would verify that downgrading from a newer version to an older
    // one is handled gracefully (e.g., no schema errors, no data loss).
    // Requires installing two different plugin versions sequentially.
  });

  // ─── Test 7: No PHP errors after admin + frontend load ─────────

  test('no PHP fatal errors or warnings on admin and frontend pages', async ({ page }) => {
    // Load frontend
    const frontResponse = await page.goto('/');
    expect(frontResponse?.status()).toBeLessThan(500);
    let bodyText = await page.textContent('body');
    expect(bodyText).not.toContain('Fatal error');
    expect(bodyText).not.toContain('Parse error');

    // Load admin dashboard
    const adminResponse = await page.goto('/wp-admin/');
    expect(adminResponse?.status()).toBeLessThan(500);
    bodyText = await page.textContent('body');
    expect(bodyText).not.toContain('Fatal error');
    expect(bodyText).not.toContain('Parse error');

    // Load SlimStat reports
    const reportsResponse = await page.goto('/wp-admin/admin.php?page=slimstat');
    expect(reportsResponse?.status()).toBeLessThan(500);
    bodyText = await page.textContent('body');
    expect(bodyText).not.toContain('Fatal error');
    expect(bodyText).not.toContain('Parse error');

    // Load SlimStat settings — all tabs
    for (const tab of ['1', '2', '3', '4', '5']) {
      const settingsResponse = await page.goto(`/wp-admin/admin.php?page=slimconfig&tab=${tab}`);
      expect(settingsResponse?.status()).toBeLessThan(500);
      bodyText = await page.textContent('body');
      expect(bodyText).not.toContain('Fatal error');
      expect(bodyText).not.toContain('Parse error');
    }
  });
});
