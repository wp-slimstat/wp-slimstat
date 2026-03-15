/**
 * AC-CNS-003 / AC-CON-003: No dependency error without WP Consent API
 *
 * Verifies that WP SlimStat tracking works normally when the
 * WP Consent API plugin is NOT installed. No consent banner is needed;
 * visits should be recorded in wp_slim_stats without errors.
 */
import { test, expect } from '@playwright/test';
import {
  closeDb,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  installOptionMutator,
  uninstallOptionMutator,
  setSlimstatOption,
} from './helpers/setup';
import { BASE_URL, MYSQL_CONFIG } from './helpers/env';
import * as mysql from 'mysql2/promise';

let db: mysql.Pool;

test.beforeAll(async () => {
  db = mysql.createPool({ ...MYSQL_CONFIG, connectionLimit: 3 });
});

test.afterAll(async () => {
  if (db) await db.end();
  await closeDb();
});

test.describe('AC-CON-003: Tracking Without WP Consent API', () => {
  test.setTimeout(60_000);

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    installOptionMutator();

    // Ensure GDPR/consent integration is off (no WP Consent API dependency)
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
    await setSlimstatOption(page, 'consent_integration', '');
  });

  test.afterEach(async () => {
    uninstallOptionMutator();
    await restoreSlimstatOptions();
  });

  test('site loads without PHP fatal when WP Consent API is absent', async ({ page }) => {
    const response = await page.goto(BASE_URL, { waitUntil: 'domcontentloaded' });
    expect(response).not.toBeNull();
    expect(response!.status()).toBe(200);

    const html = await page.content();
    expect(html).not.toContain('Fatal error');
    expect(html).not.toContain('Call to undefined function wp_has_consent');
    expect(html).not.toContain('Call to undefined function wp_get_consent_type');
  });

  test('admin pages load without WP Consent API dependency error', async ({ page }) => {
    const response = await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    expect(response).not.toBeNull();
    expect(response!.status()).toBe(200);

    const html = await page.content();
    expect(html).not.toContain('Fatal error');
    expect(html).not.toContain('undefined function');
  });

  test('tracking records visit in DB without consent plugin', async ({ page }) => {
    // Record the current max ID
    const [before] = await db.execute(
      'SELECT MAX(id) AS max_id FROM wp_slim_stats'
    ) as any;
    const maxBefore = before[0]?.max_id ?? 0;

    // Visit a uniquely-marked page as admin
    const marker = `e2e-no-consent-dep-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e_marker=${marker}`, {
      waitUntil: 'domcontentloaded',
    });

    // Wait for the JS tracker to fire
    await page.waitForTimeout(3000);

    // Check that a new row appeared in wp_slim_stats
    const [after] = await db.execute(
      'SELECT MAX(id) AS max_id FROM wp_slim_stats'
    ) as any;
    const maxAfter = after[0]?.max_id ?? 0;

    expect(maxAfter).toBeGreaterThan(maxBefore);
  });

  test('function_exists guards prevent fatal for wp_has_consent', async () => {
    // Verify the PHP source uses function_exists checks
    const fs = await import('fs');
    const path = await import('path');
    const { PLUGIN_DIR } = await import('./helpers/env');

    const consentPath = path.join(PLUGIN_DIR, 'src/Utils/Consent.php');
    if (!fs.existsSync(consentPath)) {
      // Consent class may not exist in older versions; skip gracefully
      test.skip();
      return;
    }

    const content = fs.readFileSync(consentPath, 'utf8');
    expect(content).toContain("function_exists('wp_has_consent')");
  });
});
