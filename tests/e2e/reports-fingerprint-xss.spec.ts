/**
 * E2E tests: Reports fingerprint XSS escaping — verifies that malicious
 * fingerprint values stored in the database do not execute as JavaScript
 * when rendered in admin report pages.
 *
 * Covers: GitHub #243, #244 — Defense-in-depth output escaping
 * CVE: CVE-2026-1238 (historical, patched in 5.4.0, defense-in-depth hardened here)
 *
 * Strategy: seed malicious fingerprint data directly into wp_slim_stats
 * (bypassing input sanitization), then navigate to admin report pages.
 *
 * The fingerprint renders in right-now.php:196 inside #slim_p7_02 (Access Log)
 * as: <code><a class='slimstat-filter-link' href='...fingerprint...'>FIRST_8</a></code>
 *
 * Assertions:
 *   - Primary: No JS dialog fires (proves XSS did not execute)
 *   - Primary: Page loads without 5xx error
 *   - Secondary: Fingerprint link element exists with correct text (when visible)
 *
 * The integration unit test (reports-output-escaping-test.php) validates the real
 * raw_results_to_html() HTML output with entity-level assertions against the actual
 * renderer code. This E2E test validates the full browser execution context.
 */
import { test, expect } from '@playwright/test';
import * as mysql from 'mysql2/promise';
import { BASE_URL, MYSQL_CONFIG } from './helpers/env';

let pool: mysql.Pool;

function getPool(): mysql.Pool {
  if (!pool) pool = mysql.createPool(MYSQL_CONFIG);
  return pool;
}

async function clearStatsTable(): Promise<void> {
  await getPool().execute('TRUNCATE TABLE wp_slim_stats');
}

async function seedFingerprintPageview(fingerprint: string): Promise<number> {
  const now = Math.floor(Date.now() / 1000);
  const vid = Math.floor(Math.random() * 100000);
  const [result] = (await getPool().execute(
    `INSERT INTO wp_slim_stats
       (resource, fingerprint, dt, ip, visit_id, browser, platform, content_type)
     VALUES (?, ?, ?, '127.0.0.1', ?, 'Chrome', 'Windows', 'post')`,
    [`/xss-fp-${Date.now()}-${Math.random().toString(36).slice(2)}/`, fingerprint, now, vid],
  )) as any;
  return result.insertId;
}

test.describe('Reports Fingerprint XSS Escaping (#243, #244)', () => {
  test.setTimeout(60_000);

  test.beforeEach(async () => {
    await clearStatsTable();
  });

  test.afterAll(async () => {
    if (pool) await pool.end();
  });

  // ─── Test 1: Script tag fingerprint — no XSS execution ────────────────

  test('script tag fingerprint does not execute on slimview1', async ({ page }) => {
    await seedFingerprintPageview('<script>alert("xss")</script>');

    let dialogTriggered = false;
    page.on('dialog', async (dialog) => {
      dialogTriggered = true;
      await dialog.dismiss();
    });

    const response = await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`);
    expect(response?.status(), 'Page must load without server error').toBeLessThan(500);
    await page.waitForTimeout(8_000);
    expect(dialogTriggered, 'Script tag XSS must not execute').toBe(false);

    // If the Access Log widget rendered the fingerprint, verify the link exists
    const fpLink = page.locator("a[href*='fingerprint']");
    if ((await fpLink.count()) > 0) {
      // The link text should be the first 8 chars (right-now.php:196 esc_html(substr(fp,0,8)))
      // textContent decodes entities so '<script>' appears as '<script>' — safe in DOM, just decoded
      const text = await fpLink.first().textContent();
      expect(text?.length, 'Fingerprint text should be max 8 chars').toBeLessThanOrEqual(8);
    }
  });

  // ─── Test 2: IMG onerror — no XSS execution ───────────────────────────

  test('img onerror fingerprint does not execute on slimview1', async ({ page }) => {
    await seedFingerprintPageview('"><img src=x onerror=alert(1)>');

    let dialogTriggered = false;
    page.on('dialog', async (dialog) => {
      dialogTriggered = true;
      await dialog.dismiss();
    });

    const response = await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`);
    expect(response?.status()).toBeLessThan(500);
    await page.waitForTimeout(8_000);
    expect(dialogTriggered, 'IMG onerror must not execute').toBe(false);
  });

  // ─── Test 3: Valid fingerprint renders correctly ───────────────────────

  test('valid hex fingerprint displays first 8 chars when link is visible', async ({ page }) => {
    const validFp = 'a1b2c3d4e5f6a7b8c9d0';
    await seedFingerprintPageview(validFp);

    const response = await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`);
    expect(response?.status()).toBeLessThan(500);
    await page.waitForTimeout(8_000);

    const fpLink = page.locator("a[href*='fingerprint']");
    if ((await fpLink.count()) > 0) {
      // right-now.php:196: esc_html(substr(fingerprint, 0, 8)) → 'a1b2c3d4'
      const linkText = await fpLink.first().textContent();
      expect(linkText, 'Should display first 8 chars').toBe('a1b2c3d4');

      const title = await fpLink.first().getAttribute('title');
      expect(title, 'Title should contain full fingerprint').toBe(validFp);
    }
  });

  // ─── Test 4: Multiple XSS payloads — no execution on any view ─────────

  test('multiple XSS payloads do not execute on slimview1 or slimview3', async ({ page }) => {
    await seedFingerprintPageview('<script>alert("xss")</script>');
    await seedFingerprintPageview('"><img src=x onerror=alert(1)>');
    await seedFingerprintPageview('"><svg/onload=alert(1)>');
    await seedFingerprintPageview("' onclick='alert(1)'");

    let dialogTriggered = false;
    let dialogMsg = '';
    page.on('dialog', async (dialog) => {
      dialogTriggered = true;
      dialogMsg = dialog.message();
      await dialog.dismiss();
    });

    // Test slimview1 (Access Log / right-now.php)
    const r1 = await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`);
    expect(r1?.status()).toBeLessThan(500);
    await page.waitForTimeout(8_000);
    expect(dialogTriggered, `XSS on slimview1: ${dialogMsg}`).toBe(false);

    // Test slimview3 (Audience)
    const r3 = await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview3`);
    expect(r3?.status()).toBeLessThan(500);
    await page.waitForTimeout(8_000);
    expect(dialogTriggered, `XSS on slimview3: ${dialogMsg}`).toBe(false);
  });

  // ─── Test 5: Edge-case values don't crash admin ────────────────────────

  test('edge-case fingerprint values do not crash admin', async ({ page }) => {
    await seedFingerprintPageview('a'.repeat(256));
    await seedFingerprintPageview('日本語フィンガープリント');
    await seedFingerprintPageview('&lt;already&gt;escaped&lt;/already&gt;');
    await seedFingerprintPageview("Robert'); DROP TABLE wp_slim_stats;--");

    const response = await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`);
    expect(response?.status(), 'Edge cases must not crash admin').toBeLessThan(500);
  });
});
