/**
 * E2E tests: Reports fingerprint XSS escaping — verifies that malicious
 * fingerprint values stored in the database are rendered safely in admin
 * report pages (no script execution, proper HTML escaping).
 *
 * Covers: GitHub #244 — Defense-in-depth output escaping for reports default case
 * CVE: CVE-2026-1238 (historical, patched in 5.4.0, defense-in-depth hardened here)
 *
 * Strategy: seed malicious fingerprint data directly into wp_slim_stats,
 * then navigate to admin report pages and verify content is safely escaped.
 */
import { test, expect, Page } from '@playwright/test';
import * as mysql from 'mysql2/promise';
import { BASE_URL, MYSQL_CONFIG } from './helpers/env';

let pool: mysql.Pool;

function getPool(): mysql.Pool {
  if (!pool) pool = mysql.createPool(MYSQL_CONFIG);
  return pool;
}

async function ensureLoggedIn(page: Page): Promise<void> {
  if (page.url().includes('wp-login.php')) {
    await page.fill('#user_login', 'parhumm');
    await page.fill('#user_pass', 'testpass123');
    await page.click('#wp-submit');
    await page.waitForURL('**/wp-admin/**', { timeout: 30_000 });
  }
}

async function clearStatsTable(): Promise<void> {
  await getPool().execute('TRUNCATE TABLE wp_slim_stats');
}

/**
 * Seed a pageview with a known fingerprint value directly in the DB.
 * Bypasses input sanitization to simulate a defense-in-depth test scenario.
 */
async function seedFingerprintPageview(fingerprint: string): Promise<number> {
  const now = Math.floor(Date.now() / 1000);
  const [result] = (await getPool().execute(
    `INSERT INTO wp_slim_stats
       (resource, fingerprint, dt, ip, visit_id, browser, platform, content_type)
     VALUES (?, ?, ?, '127.0.0.1', 1, 'Chrome', 'Windows', 'post')`,
    [`/xss-test-${Date.now()}/`, fingerprint, now],
  )) as any;
  return result.insertId;
}

// XSS payloads to test (inserted directly into DB, bypassing sanitization)
// mustNotContain patterns are specific enough to avoid matching legitimate page elements
const XSS_PAYLOADS = [
  {
    name: 'script tag',
    value: '<script>alert("xss")</script>',
    mustNotContain: 'alert("xss")</script>',
  },
  {
    name: 'img onerror',
    value: '"><img src=x onerror=alert(1)>',
    mustNotContain: 'onerror=alert(1)',
  },
  {
    name: 'svg onload',
    value: '"><svg/onload=alert(1)>',
    mustNotContain: 'onload=alert(1)',
  },
  {
    name: 'onclick injection',
    value: "' onclick='alert(1)'",
    mustNotContain: "onclick='alert(1)'",
  },
];

test.describe('Reports Fingerprint XSS Escaping (#244)', () => {
  test.setTimeout(90_000);

  test.beforeEach(async () => {
    await clearStatsTable();
  });

  test.afterAll(async () => {
    if (pool) await pool.end();
  });

  // ─── Test 1: Admin reports page loads without errors with XSS fingerprints ─

  test('admin reports page loads safely with XSS fingerprints in DB', async ({ page }) => {
    // Seed all XSS payloads into the DB
    for (const payload of XSS_PAYLOADS) {
      await seedFingerprintPageview(payload.value);
    }

    // Collect console errors to detect any JS execution from XSS
    const consoleErrors: string[] = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error') consoleErrors.push(msg.text());
    });

    // Track dialog events (alert/confirm/prompt) — XSS would trigger these
    let dialogTriggered = false;
    page.on('dialog', async (dialog) => {
      dialogTriggered = true;
      await dialog.dismiss();
    });

    // Navigate to Access Log (slimview1) — shows fingerprint in Right Now widget
    const response = await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    await ensureLoggedIn(page);

    expect(response?.status(), 'Page should load without server error').toBeLessThan(500);

    // Wait for async-loaded report content
    await page.waitForTimeout(6_000);

    expect(dialogTriggered, 'No alert/confirm/prompt dialog should be triggered by XSS').toBe(false);
  });

  // ─── Test 2: Verify XSS payloads don't execute in report pages ──────────
  // Note: innerHTML decodes HTML entities (&#039; → '), making text-based HTML
  // checks unreliable. The definitive test is that no JS dialog fires.

  for (const payload of XSS_PAYLOADS) {
    test(`fingerprint "${payload.name}" does not execute in reports`, async ({ page }) => {
      await seedFingerprintPageview(payload.value);

      let dialogTriggered = false;
      let dialogMessage = '';
      page.on('dialog', async (dialog) => {
        dialogTriggered = true;
        dialogMessage = dialog.message();
        await dialog.dismiss();
      });

      // Test on Access Log (slimview1) — shows fingerprint in Right Now widget
      const response1 = await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
        waitUntil: 'domcontentloaded',
      });
      await ensureLoggedIn(page);
      await page.waitForTimeout(6_000);

      expect(response1?.status(), 'slimview1 should not crash').toBeLessThan(500);
      expect(dialogTriggered, `XSS "${payload.name}" must not fire on slimview1: ${dialogMessage}`).toBe(false);

      // Test on Audience tab (slimview3) — shows fingerprint in top reports
      const response3 = await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview3`, {
        waitUntil: 'domcontentloaded',
      });
      await page.waitForTimeout(6_000);

      expect(response3?.status(), 'slimview3 should not crash').toBeLessThan(500);
      expect(dialogTriggered, `XSS "${payload.name}" must not fire on slimview3: ${dialogMessage}`).toBe(false);
    });
  }

  // ─── Test 3: Top Fingerprints report (slimview3) also safe ─────────────

  test('top fingerprints report escapes XSS payloads', async ({ page }) => {
    // Seed multiple rows with same XSS fingerprint to appear in "top" reports
    for (let i = 0; i < 5; i++) {
      await seedFingerprintPageview('<script>alert("top")</script>');
    }

    let dialogTriggered = false;
    page.on('dialog', async (dialog) => {
      dialogTriggered = true;
      await dialog.dismiss();
    });

    // slimview3 = Audience tab (shows top browsers, platforms, fingerprints)
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview3`, {
      waitUntil: 'domcontentloaded',
    });
    await ensureLoggedIn(page);
    await page.waitForTimeout(6_000);

    // Check postbox widgets (scoped to report content, not the full page)
    const widgets = page.locator('.postbox .inside');
    const widgetCount = await widgets.count();
    for (let i = 0; i < widgetCount; i++) {
      const widgetHtml = await widgets.nth(i).innerHTML();
      expect(widgetHtml, `Widget ${i} must not contain raw script tag`).not.toContain('alert("top")</script>');
    }
    expect(dialogTriggered, 'No JS dialog from top reports').toBe(false);
  });

  // ─── Test 4: No fatal errors with edge-case fingerprint values ─────────

  test('edge-case fingerprint values do not crash admin', async ({ page }) => {
    const edgeCases = [
      '', // empty
      'a'.repeat(256), // max length
      '日本語フィンガープリント', // unicode
      '&lt;already&gt;escaped&lt;/already&gt;', // pre-escaped
      "Robert'); DROP TABLE wp_slim_stats;--", // SQL injection (should be safe in display)
    ];

    for (const value of edgeCases) {
      if (value) await seedFingerprintPageview(value);
    }

    const response = await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    await ensureLoggedIn(page);

    expect(response?.status(), 'Edge cases should not crash admin').toBeLessThan(500);
  });
});
