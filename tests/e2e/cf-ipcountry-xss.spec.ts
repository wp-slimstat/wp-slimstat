/**
 * E2E for the CF-IPCountry stored-XSS fix (#5).
 *
 * Two-pronged proof:
 *   A) INPUT  — a quote-bearing CF-IPCountry header (Cloudflare provider) is
 *      rejected by the provider's `^[A-Z0-9]{2}$` validation and never stored,
 *      while a legitimate code (US) still resolves.
 *   B) OUTPUT — a country value that was already poisoned in the DB (simulating a
 *      pre-fix row) renders INERT in the Audience world map: esc_attr() keeps the
 *      payload as the literal `alt` VALUE (data), so the browser never parses an
 *      injected onload attribute and no script fires.
 *
 * Models tests/e2e/cloudflare-ip-regression.spec.ts (CF header injection + stat
 * read) and tests/e2e/cve-2026-7634-user-agent-xss.spec.ts (admin login + sentinel).
 */
import { test, expect, Page } from '@playwright/test';
import { BASE_URL, ADMIN_USER, ADMIN_PASS } from './helpers/env';
import {
  installOptionMutator,
  uninstallOptionMutator,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  getLatestStat,
  getPool,
  closeDb,
} from './helpers/setup';

declare global {
  interface Window {
    __xss_fired?: boolean;
  }
}

// 15 chars — fits country VARCHAR(16). In a double-quoted alt="" sink this would
// break out and add an onload handler if the value were not escaped.
const POISON = '"onload=alert(1)';

async function waitForStat(marker: string, timeoutMs = 10_000, intervalMs = 250) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const stat = await getLatestStat(marker);
    if (stat) return stat;
    await new Promise((r) => setTimeout(r, intervalMs));
  }
  return null;
}

async function ensureLoggedIn(page: Page): Promise<void> {
  if (!page.url().includes('wp-login.php')) {
    await page.goto(`${BASE_URL}/wp-login.php`);
  }
  if (page.url().includes('wp-login.php')) {
    await page.fill('#user_login', ADMIN_USER);
    await page.fill('#user_pass', ADMIN_PASS);
    await page.click('#wp-submit');
    await page.waitForURL('**/wp-admin/**', { timeout: 30_000 });
  }
}

test.describe('CF-IPCountry stored XSS (#5)', () => {
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    installOptionMutator();
  });

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    await setSlimstatOption(page, 'geolocation_provider', 'cloudflare');
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    await closeDb();
  });

  // ─── A: input validation rejects the payload ──────────────────────
  test('A1: quote-bearing CF-IPCountry header is never stored', async ({ page, context }) => {
    await context.setExtraHTTPHeaders({
      'CF-Ray': 'xss-e2e-reject',
      'CF-Connecting-IP': '8.8.8.8',
      'CF-IPCountry': POISON,
    });
    const marker = `cf-xss-${Date.now()}`;
    await page.goto(`/?p=${marker}`);

    const stat = await waitForStat(marker);
    expect(stat).toBeTruthy();
    // The provider rejected the payload → stored country is empty/safe, never the payload.
    expect(String(stat!.country ?? '')).not.toMatch(/onload|["'<>]/);
  });

  test('A2: a legitimate CF-IPCountry (US) still resolves and is stored', async ({ page, context }) => {
    await context.setExtraHTTPHeaders({
      'CF-Ray': 'xss-e2e-ok',
      'CF-Connecting-IP': '8.8.8.8',
      'CF-IPCountry': 'US',
    });
    const marker = `cf-ok-${Date.now()}`;
    await page.goto(`/?p=${marker}`);

    const stat = await waitForStat(marker);
    expect(stat).toBeTruthy();
    expect(stat!.country).toBe('us');
  });

  // ─── B: output escaping neutralizes a pre-existing poisoned row ────
  test('B: a poisoned stored country renders inert in the Audience world map', async ({ page }) => {
    // Simulate a row stored before the input fix existed.
    await getPool().execute(
      'INSERT INTO wp_slim_stats (resource, country, ip, dt) VALUES (?, ?, ?, UNIX_TIMESTAMP())',
      ['/xss-poison', POISON, '8.8.8.8'],
    );

    await ensureLoggedIn(page);
    // Sentinel: any injected onload/onerror that runs would flip this to true.
    await page.addInitScript(() => {
      window.__xss_fired = false;
    });
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, { waitUntil: 'domcontentloaded' });

    // World-map flags carry class country-flag; wait for the map to populate.
    await page.locator('img.country-flag').first().waitFor({ state: 'attached', timeout: 30_000 });

    // esc_attr() keeps the payload as the literal alt VALUE (data). If the sink were
    // unescaped, the browser would parse a bogus onload attribute and alt would be "".
    const altValues = await page
      .locator('img.country-flag')
      .evaluateAll((els) => els.map((e) => e.getAttribute('alt')));
    expect(altValues).toContain(POISON);

    // And nothing executed.
    expect(await page.evaluate(() => window.__xss_fired === true)).toBe(false);
  });
});
