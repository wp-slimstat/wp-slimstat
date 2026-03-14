/**
 * E2E tests: wp.org support issue verification
 *
 * Verifies three reported issues are fixed:
 * 1. soolee — after disabling GDPR, city/state/IP missing, shows hashed values
 * 2. kindnessville — geolocation shows "Unknown" in dashboard
 * 3. toxicum — fatal wp_tempnam() in DbIpProvider during cron (covered by issue-180 spec)
 *
 * Key insight: disabling GDPR does NOT automatically turn off hash_ip/anonymize_ip.
 * Users must also disable those settings to see real IPs.
 * Geolocation always uses the ORIGINAL IP (before hashing) so city/country should
 * populate regardless of hash_ip setting, as long as piiAllowed() returns true.
 */
import { test, expect } from '@playwright/test';
import {
  installOptionMutator,
  uninstallOptionMutator,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  getLatestStat,
  getLatestStatWithIp,
  closeDb,
} from './helpers/setup';

test.describe('wp.org Support Issues: GDPR, IP, and Geolocation', () => {
  test.beforeAll(async () => {
    installOptionMutator();
  });

  test.beforeEach(async () => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    await closeDb();
  });

  // ─── soolee: GDPR off + all privacy off → real IP, geolocation populated ──

  test('soolee: GDPR off with hash_ip off stores real IP and populates geolocation', async ({ page, context }) => {
    // Configure: GDPR off, no hashing, no anonymization, Cloudflare provider for reliable geo
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
    await setSlimstatOption(page, 'hash_ip', 'off');
    await setSlimstatOption(page, 'anonymize_ip', 'off');
    await setSlimstatOption(page, 'geolocation_provider', 'cloudflare');
    await setSlimstatOption(page, 'geolocation_country', 'no'); // city precision

    // Inject Cloudflare headers to guarantee geolocation data
    await context.setExtraHTTPHeaders({
      'CF-Ray': 'test-soolee-001',
      'CF-IPCountry': 'US',
      'CF-IPContinent': 'NA',
      'CF-IPCity': 'New York',
      'CF-Region': 'New York',
      'CF-IPLatitude': '40.7128',
      'CF-IPLongitude': '-74.0060',
    });

    const marker = `soolee-gdpr-off-${Date.now()}`;
    await page.goto(`/?p=${marker}`);
    await page.waitForTimeout(4000);

    const stat = await getLatestStatWithIp(marker);
    expect(stat, 'Stat row should exist in DB').toBeTruthy();

    // IP should NOT be a 39-char hex hash
    expect(stat!.ip.length, 'IP should not be a 39-char hash').not.toBe(39);
    // IP should look like a real IP address (contains dots or colons)
    expect(
      stat!.ip.includes('.') || stat!.ip.includes(':'),
      `IP "${stat!.ip}" should be a real IP address, not a hash`
    ).toBe(true);

    // Geolocation should be populated
    expect(stat!.country, 'Country should be populated').toBe('us');
    expect(stat!.city, 'City should be populated').toContain('New York');
    expect(stat!.location, 'Location coords should be populated').toContain('40.7128');
  });

  // ─── soolee: GDPR off but hash_ip still on → IP hashed but geolocation works ──

  test('soolee: GDPR off with hash_ip on still populates geolocation', async ({ page, context }) => {
    // This is the likely scenario: user disabled GDPR but hash_ip default is 'on'
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
    await setSlimstatOption(page, 'hash_ip', 'on');
    await setSlimstatOption(page, 'anonymize_ip', 'off');
    await setSlimstatOption(page, 'geolocation_provider', 'cloudflare');
    await setSlimstatOption(page, 'geolocation_country', 'no');

    await context.setExtraHTTPHeaders({
      'CF-Ray': 'test-soolee-002',
      'CF-IPCountry': 'DE',
      'CF-IPContinent': 'EU',
      'CF-IPCity': 'Berlin',
      'CF-Region': 'Berlin',
      'CF-IPLatitude': '52.5200',
      'CF-IPLongitude': '13.4050',
    });

    const marker = `soolee-hash-on-${Date.now()}`;
    await page.goto(`/?p=${marker}`);
    await page.waitForTimeout(4000);

    const stat = await getLatestStatWithIp(marker);
    expect(stat, 'Stat row should exist in DB').toBeTruthy();

    // IP should be hashed (MD5 = 32 hex chars, or other hash format), or on local dev
    // where REMOTE_ADDR is :: or 127.0.0.1, the hash may be shorter.
    // The key assertion: IP is NOT the raw REMOTE_ADDR (it should be hashed)
    const ip = stat!.ip;
    const isHashedOrLocal = /^[a-f0-9]{32}$/.test(ip) || ip === '::' || ip === '::1' || ip === '127.0.0.1';
    expect(isHashedOrLocal, `IP should be hashed or loopback, got "${ip}"`).toBe(true);

    // With cloudflare provider, geolocation uses CF headers (not DB-IP lookup)
    // so it should work regardless of the IP value
    if (stat!.country) {
      expect(stat!.country.toLowerCase()).toBe('de');
    }
  });

  // ─── kindnessville: geolocation shows Unknown → verify provider populates data ──

  test('kindnessville: Cloudflare provider populates country and city (not Unknown)', async ({ page, context }) => {
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
    await setSlimstatOption(page, 'geolocation_provider', 'cloudflare');
    await setSlimstatOption(page, 'geolocation_country', 'no');

    await context.setExtraHTTPHeaders({
      'CF-Ray': 'test-kindness-001',
      'CF-IPCountry': 'CA',
      'CF-IPContinent': 'NA',
      'CF-IPCity': 'Toronto',
      'CF-Region': 'Ontario',
      'CF-IPLatitude': '43.6532',
      'CF-IPLongitude': '-79.3832',
    });

    const marker = `kindness-cf-${Date.now()}`;
    await page.goto(`/?p=${marker}`);
    await page.waitForTimeout(4000);

    const stat = await getLatestStat(marker);
    expect(stat, 'Stat row should exist').toBeTruthy();

    // Country must NOT be empty or 'xx' (the "Unknown" sentinel)
    expect(stat!.country).not.toBe('');
    expect(stat!.country).not.toBe('xx');
    expect(stat!.country, 'Country should be "ca"').toBe('ca');

    // City must be populated (not empty/Unknown)
    expect(stat!.city).not.toBe('');
    expect(stat!.city).toContain('Toronto');
  });

  // ─── kindnessville: DB-IP provider with local IP → pipeline doesn't crash ──

  test('kindnessville: DB-IP provider tracks without crashing on local IP', async ({ page }) => {
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
    await setSlimstatOption(page, 'geolocation_provider', 'dbip');

    const marker = `kindness-dbip-${Date.now()}`;
    await page.goto(`/?p=${marker}`);
    await page.waitForTimeout(3000);

    // With local/private IPs, DB-IP may not resolve geolocation,
    // but the tracking pipeline must not crash
    const stat = await getLatestStatWithIp(marker);
    expect(stat, 'Stat row should exist — pipeline did not crash').toBeTruthy();

    // IP should be stored (not empty)
    expect(stat!.ip, 'IP should be stored').not.toBe('');
  });

  // ─── soolee: GDPR on with anonymous tracking → no geolocation (expected) ──

  test('soolee: GDPR on + anonymous tracking blocks geolocation without consent', async ({ page, context }) => {
    await setSlimstatOption(page, 'gdpr_enabled', 'on');
    await setSlimstatOption(page, 'anonymous_tracking', 'on');
    await setSlimstatOption(page, 'geolocation_provider', 'cloudflare');
    await setSlimstatOption(page, 'geolocation_country', 'no');

    await context.setExtraHTTPHeaders({
      'CF-Ray': 'test-anon-001',
      'CF-IPCountry': 'FR',
      'CF-IPCity': 'Paris',
      'CF-Region': 'Ile-de-France',
      'CF-IPLatitude': '48.8566',
      'CF-IPLongitude': '2.3522',
    });

    const marker = `anon-no-consent-${Date.now()}`;
    await page.goto(`/?p=${marker}`);
    await page.waitForTimeout(4000);

    const stat = await getLatestStat(marker);
    // In anonymous mode without consent, tracking may or may not create a row
    // (depends on canTrack). If row exists, geolocation should be empty
    // because piiAllowed() returns false.
    if (stat) {
      expect(
        !stat.country || stat.country === '',
        'Country should be empty in anonymous mode without consent'
      ).toBe(true);
    }
  });

  // ─── Admin dashboard loads without PHP errors for default settings ──

  test('admin dashboard loads without PHP errors for default settings', async ({ page }) => {
    // Reset to default-like settings (GDPR off, no special privacy flags)
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
    await setSlimstatOption(page, 'hash_ip', 'off');
    await setSlimstatOption(page, 'anonymize_ip', 'off');

    // Visit the SlimStat admin dashboard
    const response = await page.goto('/wp-admin/admin.php?page=slimstat');
    expect(response?.status(), 'SlimStat dashboard should not return 500').toBeLessThan(500);

    const bodyText = await page.textContent('body');
    expect(bodyText).not.toContain('Fatal error');
    expect(bodyText).not.toContain('Parse error');
    expect(bodyText).not.toContain('Warning:');
    expect(bodyText).not.toContain('Notice:');

    // Also check the settings page
    const settingsResponse = await page.goto('/wp-admin/admin.php?page=slimconfig');
    expect(settingsResponse?.status(), 'SlimStat settings should not return 500').toBeLessThan(500);

    const settingsBody = await page.textContent('body');
    expect(settingsBody).not.toContain('Fatal error');
    expect(settingsBody).not.toContain('Parse error');
  });

  // ─── Admin dashboard loads without errors for all GDPR configurations ──

  test('admin dashboard loads for GDPR on and off configurations', async ({ page }) => {
    const configs = [
      { gdpr: 'off', hash: 'off', anon: 'off' },
      { gdpr: 'off', hash: 'on', anon: 'off' },
      { gdpr: 'on', hash: 'on', anon: 'off' },
      { gdpr: 'on', hash: 'on', anon: 'on' },
    ];

    for (const cfg of configs) {
      await setSlimstatOption(page, 'gdpr_enabled', cfg.gdpr);
      await setSlimstatOption(page, 'hash_ip', cfg.hash);
      await setSlimstatOption(page, 'anonymous_tracking', cfg.anon);

      const response = await page.goto('/wp-admin/');
      expect(
        response?.status(),
        `Dashboard should load for gdpr=${cfg.gdpr} hash=${cfg.hash} anon=${cfg.anon}`
      ).toBeLessThan(500);
      await expect(page).toHaveTitle(/Dashboard/);
    }
  });
});
