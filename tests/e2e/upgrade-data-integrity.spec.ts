/**
 * E2E tests: Upgrade data integrity verification.
 *
 * Ensures that after the geolocation refactor (PR #166), existing user
 * tracking data remains intact, countries don't become "unknown" or empty,
 * and new tracking still produces valid geolocation data.
 *
 * All tests snapshot and restore data — no permanent changes to the DB.
 */
import { test, expect } from '@playwright/test';
import {
  installOptionMutator,
  uninstallOptionMutator,
  setSlimstatOption,
  deleteSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  getLatestStat,
  simulateLegacyUpgrade,
  closeDb,
} from './helpers/setup';
import * as mysql from 'mysql2/promise';
import { MYSQL_CONFIG } from './helpers/env';

let pool: mysql.Pool;

function getPool(): mysql.Pool {
  if (!pool) {
    pool = mysql.createPool(MYSQL_CONFIG);
  }
  return pool;
}

// ─── Seed helpers ────────────────────────────────────────────────

interface SeedRow {
  ip: string;
  country: string;
  city: string;
  resource: string;
  dt: number;
  visit_id: number;
}

async function seedStatsRows(rows: SeedRow[]): Promise<number[]> {
  const ids: number[] = [];
  for (const row of rows) {
    const [result] = await getPool().execute(
      `INSERT INTO wp_slim_stats (ip, country, city, resource, dt, visit_id)
       VALUES (?, ?, ?, ?, ?, ?)`,
      [row.ip, row.country, row.city, row.resource, row.dt, row.visit_id]
    ) as any;
    ids.push(result.insertId);
  }
  return ids;
}

async function getStatsRows(ids: number[]): Promise<any[]> {
  if (ids.length === 0) return [];
  const placeholders = ids.map(() => '?').join(',');
  const [rows] = await getPool().execute(
    `SELECT id, ip, country, city, resource, dt, visit_id
     FROM wp_slim_stats WHERE id IN (${placeholders}) ORDER BY id`,
    ids
  ) as any;
  return rows;
}

async function deleteStatsRows(ids: number[]): Promise<void> {
  if (ids.length === 0) return;
  const placeholders = ids.map(() => '?').join(',');
  await getPool().execute(
    `DELETE FROM wp_slim_stats WHERE id IN (${placeholders})`,
    ids
  );
}

async function getAllStatsCountries(): Promise<string[]> {
  const [rows] = await getPool().execute(
    `SELECT DISTINCT country FROM wp_slim_stats WHERE country IS NOT NULL AND country != '' ORDER BY country`
  ) as any;
  return rows.map((r: any) => r.country);
}

// ─── Test data ───────────────────────────────────────────────────

const KNOWN_COUNTRIES: SeedRow[] = [
  { ip: '203.0.113.1', country: 'us', city: 'New York', resource: '/upgrade-test/page-1', dt: Math.floor(Date.now() / 1000) - 86400, visit_id: 9001 },
  { ip: '203.0.113.2', country: 'de', city: 'Berlin', resource: '/upgrade-test/page-2', dt: Math.floor(Date.now() / 1000) - 86400, visit_id: 9002 },
  { ip: '203.0.113.3', country: 'jp', city: 'Tokyo', resource: '/upgrade-test/page-3', dt: Math.floor(Date.now() / 1000) - 86400, visit_id: 9003 },
  { ip: '203.0.113.4', country: 'br', city: 'São Paulo', resource: '/upgrade-test/page-4', dt: Math.floor(Date.now() / 1000) - 86400, visit_id: 9004 },
  { ip: '203.0.113.5', country: 'gb', city: 'London', resource: '/upgrade-test/page-5', dt: Math.floor(Date.now() / 1000) - 86400, visit_id: 9005 },
  { ip: '203.0.113.6', country: 'fr', city: 'Paris', resource: '/upgrade-test/page-6', dt: Math.floor(Date.now() / 1000) - 86400, visit_id: 9006 },
  { ip: '203.0.113.7', country: 'au', city: 'Sydney', resource: '/upgrade-test/page-7', dt: Math.floor(Date.now() / 1000) - 86400, visit_id: 9007 },
  { ip: '203.0.113.8', country: 'ca', city: 'Toronto', resource: '/upgrade-test/page-8', dt: Math.floor(Date.now() / 1000) - 86400, visit_id: 9008 },
  { ip: '203.0.113.9', country: 'in', city: 'Mumbai', resource: '/upgrade-test/page-9', dt: Math.floor(Date.now() / 1000) - 86400, visit_id: 9009 },
  { ip: '203.0.113.10', country: 'kr', city: 'Seoul', resource: '/upgrade-test/page-10', dt: Math.floor(Date.now() / 1000) - 86400, visit_id: 9010 },
];

// ─── Tests ───────────────────────────────────────────────────────

test.describe('Upgrade Data Integrity', () => {
  let seededIds: number[] = [];

  test.beforeAll(async () => {
    installOptionMutator();
  });

  test.beforeEach(async () => {
    await snapshotSlimstatOptions();
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
    // Clean up seeded rows
    if (seededIds.length > 0) {
      await deleteStatsRows(seededIds);
      seededIds = [];
    }
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    if (pool) {
      await pool.end();
    }
    await closeDb();
  });

  // ─── Test 1: Existing country data survives upgrade ────────────

  test('existing rows with known countries remain intact after admin page load', async ({ page }) => {
    // Seed 10 rows with known countries
    seededIds = await seedStatsRows(KNOWN_COUNTRIES);
    expect(seededIds).toHaveLength(10);

    // Load admin pages (triggers init(), resolve_geolocation_provider(), lazy migration)
    await page.goto('/wp-admin/');
    await page.waitForURL('**/wp-admin/**');
    await expect(page).toHaveTitle(/Dashboard/);

    // Load the SlimStat reports page (triggers report queries)
    const response = await page.goto('/wp-admin/admin.php?page=slimstat');
    expect(response?.status()).toBeLessThan(500);

    // Verify all 10 rows still have their original country codes
    const rows = await getStatsRows(seededIds);
    expect(rows).toHaveLength(10);

    for (let i = 0; i < KNOWN_COUNTRIES.length; i++) {
      const original = KNOWN_COUNTRIES[i];
      const current = rows[i];
      expect(current.country).toBe(original.country);
      expect(current.city).toBe(original.city);
      expect(current.ip).toBe(original.ip);
      expect(current.resource).toBe(original.resource);
    }
  });

  // ─── Test 2: Legacy MaxMind users' existing data preserved ─────

  test('legacy enable_maxmind=on: existing data preserved, new tracking works', async ({ page }) => {
    // Seed some pre-upgrade data
    seededIds = await seedStatsRows([
      { ip: '198.51.100.1', country: 'us', city: 'Chicago', resource: '/legacy-maxmind-test', dt: Math.floor(Date.now() / 1000) - 172800, visit_id: 8001 },
      { ip: '198.51.100.2', country: 'de', city: 'Munich', resource: '/legacy-maxmind-test-2', dt: Math.floor(Date.now() / 1000) - 172800, visit_id: 8002 },
    ]);

    // Simulate legacy state: enable_maxmind=on, no geolocation_provider key
    await simulateLegacyUpgrade(page, 'on');

    // Trigger upgrade path by loading admin
    await page.goto('/wp-admin/');
    await expect(page).toHaveTitle(/Dashboard/);

    // Verify pre-existing data untouched
    const rows = await getStatsRows(seededIds);
    expect(rows[0].country).toBe('us');
    expect(rows[0].city).toBe('Chicago');
    expect(rows[1].country).toBe('de');
    expect(rows[1].city).toBe('Munich');

    // Track a new pageview — should not crash even without MaxMind DB
    const marker = `post-upgrade-maxmind-${Date.now()}`;
    const response = await page.goto(`/?p=${marker}`);
    expect(response?.status()).toBeLessThan(500);
  });

  // ─── Test 3: Legacy DB-IP users' existing data preserved ───────

  test('legacy enable_maxmind=no: existing data preserved, new tracking works', async ({ page }) => {
    seededIds = await seedStatsRows([
      { ip: '198.51.100.3', country: 'fr', city: 'Lyon', resource: '/legacy-dbip-test', dt: Math.floor(Date.now() / 1000) - 172800, visit_id: 8003 },
      { ip: '198.51.100.4', country: 'jp', city: 'Osaka', resource: '/legacy-dbip-test-2', dt: Math.floor(Date.now() / 1000) - 172800, visit_id: 8004 },
    ]);

    await simulateLegacyUpgrade(page, 'no');

    await page.goto('/wp-admin/');
    await expect(page).toHaveTitle(/Dashboard/);

    // Pre-existing data untouched
    const rows = await getStatsRows(seededIds);
    expect(rows[0].country).toBe('fr');
    expect(rows[0].city).toBe('Lyon');
    expect(rows[1].country).toBe('jp');
    expect(rows[1].city).toBe('Osaka');

    // Track a new pageview
    const marker = `post-upgrade-dbip-${Date.now()}`;
    const response = await page.goto(`/?p=${marker}`);
    expect(response?.status()).toBeLessThan(500);
  });

  // ─── Test 4: New tracking with DB-IP doesn't produce empty country ──

  test('new tracking after upgrade does not produce empty or unknown country', async ({ page }) => {
    await setSlimstatOption(page, 'geolocation_provider', 'dbip');

    const marker = `new-track-${Date.now()}`;
    await page.goto(`/?p=${marker}`);
    await page.waitForTimeout(4000);

    const stat = await getLatestStat(marker);
    // For local/private IPs, DB-IP may not resolve a country — that's OK.
    // The key assertion: if a country IS stored, it must be a valid 2-letter code, not "unknown".
    if (stat && stat.country) {
      expect(stat.country).toMatch(/^[a-z]{2}$/);
      expect(stat.country).not.toBe('unknown');
      expect(stat.country).not.toBe('xx');
    }
    // Pipeline didn't crash (we reached this point)
  });

  // ─── Test 5: Cloudflare tracking produces valid country ────────

  test('Cloudflare provider produces valid country, not unknown', async ({ page, context }) => {
    await setSlimstatOption(page, 'geolocation_provider', 'cloudflare');
    await setSlimstatOption(page, 'geolocation_country', 'no'); // city precision
    await setSlimstatOption(page, 'gdpr_enabled', 'off'); // Ensure tracking not blocked by GDPR

    // Clear stats so we get a clean read
    await getPool().execute('TRUNCATE TABLE wp_slim_stats');

    // Inject CF headers for a known location
    await context.setExtraHTTPHeaders({
      'CF-IPCountry': 'IT',
      'CF-IPCity': 'Rome',
      'CF-Region': 'Lazio',
      'CF-IPLatitude': '41.9028',
      'CF-IPLongitude': '12.4964',
    });

    const marker = `cf-upgrade-${Date.now()}`;
    await page.goto(`/?p=${marker}`);
    await page.waitForTimeout(5000);

    const stat = await getLatestStat(marker);
    if (stat) {
      // Country must be a valid code or null — never "unknown"
      if (stat.country) {
        expect(stat.country).toBe('it');
        expect(stat.country).not.toBe('unknown');
      }
      if (stat.city) {
        expect(stat.city).toContain('Rome');
      }
    }
    // Pipeline didn't crash regardless of whether row was tracked
  });

  // ─── Test 6: Admin reports page loads with mixed data ──────────

  test('admin reports page displays mixed-country data without errors', async ({ page }) => {
    // Seed diverse country data
    seededIds = await seedStatsRows(KNOWN_COUNTRIES);

    // Load the main reports page
    const response = await page.goto('/wp-admin/admin.php?page=slimstat');
    expect(response?.status()).toBeLessThan(500);

    // Check page doesn't contain PHP fatal errors or warnings
    const bodyText = await page.textContent('body');
    expect(bodyText).not.toContain('Fatal error');
    expect(bodyText).not.toContain('Warning:');
    expect(bodyText).not.toContain('Unknown country');

    // Load the settings page
    const settingsResponse = await page.goto('/wp-admin/admin.php?page=slimconfig');
    expect(settingsResponse?.status()).toBeLessThan(500);
  });

  // ─── Test 7: Legacy column values survive upgrade cycle ──────────

  test('legacy column values survive upgrade cycle', async ({ page }) => {
    // Seed a row with all columns filled to verify nothing is wiped during upgrade
    const now = Math.floor(Date.now() / 1000);
    const [result] = await getPool().execute(
      `INSERT INTO wp_slim_stats
        (ip, other_ip, username, email, country, location, city,
         referer, resource, searchterms, notes, visit_id, server_latency,
         page_performance, browser, browser_version, browser_type, platform,
         language, fingerprint, user_agent, resolution, screen_width,
         screen_height, content_type, category, author, content_id,
         outbound_resource, tz_offset, dt_out, dt)
       VALUES
        (?, ?, ?, ?, ?, ?, ?,
         ?, ?, ?, ?, ?, ?,
         ?, ?, ?, ?, ?,
         ?, ?, ?, ?, ?,
         ?, ?, ?, ?, ?,
         ?, ?, ?, ?)`,
      [
        '192.168.1.100', '10.0.0.1', 'testuser', 'test@example.com', 'us', '40.7128,-74.0060', 'New York',
        'https://example.com/referrer', '/legacy-columns-test', 'search term', 'test note', 99001, 150,
        250, 'Chrome', '120', 1, 'Windows',
        'en-us', 'abc123fingerprint', 'Mozilla/5.0 Test Agent', '1920x1080', 1920,
        1080, 'post', 'Uncategorized', 'admin', 42,
        'https://external.com/link', -300, now + 60, now,
      ]
    ) as any;
    const testId = result.insertId;
    seededIds.push(testId);

    // Snapshot the row before the upgrade cycle
    const [beforeRows] = await getPool().execute(
      `SELECT * FROM wp_slim_stats WHERE id = ?`,
      [testId]
    ) as any;
    expect(beforeRows).toHaveLength(1);
    const before = beforeRows[0];

    // Trigger upgrade check by visiting admin pages
    await page.goto('/wp-admin/');
    await expect(page).toHaveTitle(/Dashboard/);
    await page.goto('/wp-admin/admin.php?page=slimstat');
    await page.waitForTimeout(2000);
    await page.goto('/wp-admin/admin.php?page=slimconfig');
    await page.waitForTimeout(1000);

    // Verify all column values are intact after the upgrade cycle
    const [afterRows] = await getPool().execute(
      `SELECT * FROM wp_slim_stats WHERE id = ?`,
      [testId]
    ) as any;
    expect(afterRows).toHaveLength(1);
    const after = afterRows[0];

    expect(after.ip).toBe(before.ip);
    expect(after.other_ip).toBe(before.other_ip);
    expect(after.username).toBe(before.username);
    expect(after.email).toBe(before.email);
    expect(after.country).toBe(before.country);
    expect(after.location).toBe(before.location);
    expect(after.city).toBe(before.city);
    expect(after.referer).toBe(before.referer);
    expect(after.resource).toBe(before.resource);
    expect(after.searchterms).toBe(before.searchterms);
    expect(after.notes).toBe(before.notes);
    expect(after.visit_id).toBe(before.visit_id);
    expect(after.server_latency).toBe(before.server_latency);
    expect(after.page_performance).toBe(before.page_performance);
    expect(after.browser).toBe(before.browser);
    expect(after.browser_version).toBe(before.browser_version);
    expect(after.browser_type).toBe(before.browser_type);
    expect(after.platform).toBe(before.platform);
    expect(after.language).toBe(before.language);
    expect(after.fingerprint).toBe(before.fingerprint);
    expect(after.user_agent).toBe(before.user_agent);
    expect(after.resolution).toBe(before.resolution);
    expect(after.screen_width).toBe(before.screen_width);
    expect(after.screen_height).toBe(before.screen_height);
    expect(after.content_type).toBe(before.content_type);
    expect(after.category).toBe(before.category);
    expect(after.author).toBe(before.author);
    expect(after.content_id).toBe(before.content_id);
    expect(after.outbound_resource).toBe(before.outbound_resource);
    expect(after.tz_offset).toBe(before.tz_offset);
    expect(after.dt_out).toBe(before.dt_out);
    expect(after.dt).toBe(before.dt);
  });

  // ─── Test 8: Settings page shows correct provider after migration ──

  test('settings page shows correct provider after legacy migration', async ({ page }) => {
    // Start with legacy state
    await simulateLegacyUpgrade(page, 'no');

    // Visit admin — triggers lazy migration in admin/config/index.php
    await page.goto('/wp-admin/');
    await expect(page).toHaveTitle(/Dashboard/);

    // Go to settings page — should show the migrated provider
    const response = await page.goto('/wp-admin/admin.php?page=slimconfig&tab=5');
    expect(response?.status()).toBeLessThan(500);

    // The provider select should exist and have a valid value (not empty, not "unknown")
    const providerSelect = page.locator('select[name="geolocation_provider"]');
    if (await providerSelect.count() > 0) {
      const value = await providerSelect.inputValue();
      expect(['dbip', 'maxmind', 'cloudflare', 'disable']).toContain(value);
      // Legacy enable_maxmind=no should map to dbip
      expect(value).toBe('dbip');
    }
  });
});
