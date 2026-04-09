/**
 * E2E regression: Access Log auto-refresh interval, pause-on-interaction,
 * and scroll preservation (#258).
 *
 * Before the fix, `access_log_count_down()` hardcoded a 60-second wall-clock
 * cycle and ignored `SlimStatAdminParams.refresh_interval` (only checking
 * its existence). The refresh also fired mid-scroll, wiping the user's
 * reading position by replacing the panel HTML.
 *
 * After the fix:
 *   - The countdown is driven by a self-scheduling setTimeout chain anchored
 *     to the configured `refresh_interval` (in seconds).
 *   - The refresh pauses while the user is hovering or actively scrolling
 *     the panel, and while the tab is hidden.
 *   - Scroll position is captured before and restored after each refresh,
 *     and the spinner injection is skipped for slim_p7_02 to avoid the
 *     intermediate scrollTop=0 flash.
 *   - The admin-bar online-visitors update keeps firing every 60s on its
 *     own scheduler, regardless of the access-log refresh cadence.
 *
 * Source: customer support ticket #15082 (sanitized).
 */
import { test, expect, Page } from '@playwright/test';
import { BASE_URL } from './helpers/env';
import {
  closeDb,
  clearStatsTable,
  getPool,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
} from './helpers/setup';

async function ensureLoggedIn(page: Page): Promise<void> {
  if (page.url().includes('wp-login.php')) {
    await page.fill('#user_login', 'parhumm');
    await page.fill('#user_pass', 'testpass123');
    await page.click('#wp-submit');
    await page.waitForURL('**/wp-admin/**', { timeout: 30_000 });
  }
}

/** Seed enough current-date pageviews so the access log has rows + a refresh-timer. */
async function seedCurrentPageviews(count: number): Promise<void> {
  const now = Math.floor(Date.now() / 1000);
  for (let i = 0; i < count; i++) {
    await getPool().execute(
      `INSERT INTO wp_slim_stats
         (resource, dt, ip, visit_id, browser, platform, content_type)
       VALUES (?, ?, '127.0.0.1', 1, 'Chrome', 'Windows', 'post')`,
      [`/e2e-258-row-${i}/`, now - i],
    );
  }
}

test.describe('Access Log auto-refresh — #258', () => {
  test.setTimeout(180_000);

  test.beforeAll(async () => {
    await snapshotSlimstatOptions();
  });

  test.beforeEach(async () => {
    await clearStatsTable();
    await seedCurrentPageviews(50);
  });

  test.afterAll(async () => {
    await restoreSlimstatOptions();
    await closeDb();
  });

  test('refresh_interval=5 — auto-refresh fires within 8 seconds', async ({ page }) => {
    // Deterministic interval-honored check: with refresh_interval=5, the
    // setting-driven scheduler MUST fire at least one slim_p7_02 refresh
    // within 8 seconds of page load. The pre-fix wall-clock implementation
    // ignores the setting and uses a 60-second cycle, so 0 refreshes fire
    // in 8 seconds and the test fails.
    await setSlimstatOption(page, 'refresh_interval', '5');

    let refreshCount = 0;
    page.on('request', (req) => {
      if (req.method() === 'POST' && req.url().includes('admin-ajax.php')) {
        const body = req.postData() || '';
        if (body.includes('slimstat_load_report') && body.includes('slim_p7_02')) {
          refreshCount++;
        }
      }
    });

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    await ensureLoggedIn(page);
    await expect(page.locator('#slim_p7_02')).toBeVisible({ timeout: 30_000 });

    // SlimStatAdminParams.refresh_interval should expose 5.
    const localizedInterval = await page.evaluate(
      () => (window as any).SlimStatAdminParams?.refresh_interval,
    );
    expect(parseInt(String(localizedInterval), 10)).toBe(5);

    // Reset counter to ignore the initial async page-load AJAX (if any),
    // then keep the cursor off the panel and wait through ~8 seconds —
    // enough for at least one 5-second tick to fire.
    await page.waitForTimeout(2_000);
    refreshCount = 0;
    await page.mouse.move(0, 0);
    await page.waitForTimeout(8_000);

    expect(
      refreshCount,
      'with refresh_interval=5, at least one auto-refresh should fire within 8s',
    ).toBeGreaterThan(0);
  });

  test('refresh_interval=0 disables the access-log refresh-timer', async ({ page }) => {
    await setSlimstatOption(page, 'refresh_interval', '0');

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    await ensureLoggedIn(page);
    await expect(page.locator('#slim_p7_02')).toBeVisible({ timeout: 30_000 });
    await page.waitForTimeout(6_000);

    // PHP gate at wp-slimstat-reports.php:1058 should suppress the
    // <i class="refresh-timer"> element entirely when refresh_interval = 0.
    const timerCount = await page.locator('.pagination .refresh-timer').count();
    expect(timerCount, 'refresh-timer element should not render when interval=0').toBe(0);
  });

  test('hover pauses the refresh tick', async ({ page }) => {
    // Use a short interval to keep the test runtime manageable.
    await setSlimstatOption(page, 'refresh_interval', '5');

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    await ensureLoggedIn(page);
    await expect(page.locator('#slim_p7_02')).toBeVisible({ timeout: 30_000 });
    await page.waitForTimeout(7_000);

    // Verify the refresh-timer is mounted.
    const timerCount = await page.locator('.pagination .refresh-timer').count();
    test.skip(timerCount === 0, 'refresh-timer not mounted; skipping hover test');

    // Start counting slim_p7_02 AJAX requests.
    let refreshCount = 0;
    page.on('request', (req) => {
      if (req.method() === 'POST' && req.url().includes('admin-ajax.php')) {
        const body = req.postData() || '';
        if (body.includes('slimstat_load_report') && body.includes('slim_p7_02')) {
          refreshCount++;
        }
      }
    });

    // Hover the panel and hold the cursor there for ~12s (well over 5s interval).
    const inside = page.locator('#slim_p7_02 .inside');
    const box = await inside.boundingBox();
    if (!box) test.skip(true, '.inside has no bounding box');
    await page.mouse.move(box!.x + box!.width / 2, box!.y + box!.height / 2);
    await page.waitForTimeout(12_000);

    // No auto-refresh should have fired during the hover.
    expect(refreshCount, 'refresh fired while hovered').toBe(0);

    // Move pointer away and wait for the next interval — refresh should resume.
    await page.mouse.move(0, 0);
    await page.waitForTimeout(8_000);
    expect(refreshCount, 'refresh should resume after mouseleave').toBeGreaterThan(0);
  });

  test('admin-bar minute_pulse listener is wired regardless of refresh_interval', async ({ page }) => {
    // NOTE on coverage scope: this test verifies that the slimstat:minute_pulse
    // LISTENER is correctly wired with refresh_interval=300 (i.e. it doesn't
    // get accidentally suppressed by the access-log scheduler refactor). It
    // does NOT verify that the wall-clock-anchored scheduleAdminBarPulse()
    // setTimeout fires at the natural 60-second cadence — that would require
    // either a 60-90s real-time wait (slow) or Playwright clock injection
    // (not currently set up in this suite). The cadence is enforced by the
    // implementation in admin.js:scheduleAdminBarPulse() and is verified
    // manually in the smoke checklist on the PR.
    await setSlimstatOption(page, 'refresh_interval', '300');

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    await ensureLoggedIn(page);
    await page.waitForTimeout(3_000);

    // Programmatically dispatch the admin-bar pulse and verify the listener
    // sends the get_adminbar_stats AJAX call.
    const adminBarCalls: string[] = [];
    page.on('request', (req) => {
      if (req.method() === 'POST' && req.url().includes('admin-ajax.php')) {
        const body = req.postData() || '';
        if (body.includes('slimstat_get_adminbar_stats')) adminBarCalls.push(body);
      }
    });

    await page.evaluate(() => {
      window.dispatchEvent(new CustomEvent('slimstat:minute_pulse'));
    });
    await page.waitForTimeout(2_000);

    expect(
      adminBarCalls.length,
      'admin-bar listener should respond to slimstat:minute_pulse',
    ).toBeGreaterThan(0);
  });

  test('scroll position survives a refresh', async ({ page }) => {
    await setSlimstatOption(page, 'refresh_interval', '5');

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    await ensureLoggedIn(page);
    await expect(page.locator('#slim_p7_02')).toBeVisible({ timeout: 30_000 });
    await page.waitForTimeout(7_000);

    const inside = page.locator('#slim_p7_02 .inside');
    test.skip(
      (await inside.count()) === 0,
      'no .inside container present',
    );

    // Force the panel to be scrollable enough to hold a position.
    const scrollHeight = await inside.evaluate((el) => el.scrollHeight);
    const clientHeight = await inside.evaluate((el) => el.clientHeight);
    test.skip(scrollHeight - clientHeight < 100, 'panel does not overflow enough to scroll');

    // Scroll inside the panel and capture the position.
    await inside.evaluate((el) => { el.scrollTop = 80; });
    const before = await inside.evaluate((el) => el.scrollTop);
    expect(before).toBeGreaterThanOrEqual(70);

    // Trigger the access log refresh by calling refresh_report directly with
    // forceRecent: true (matches what the auto-refresh listener does on the
    // fixed branch). On the buggy branch this same call goes through the
    // fadeOut→html→fadeIn path that resets scrollTop to 0. Await the
    // returned deferred to avoid timing-dependent waits.
    await page.evaluate(() => new Promise<void>((resolve) => {
      const SlimStatAdmin = (window as any).SlimStatAdmin;
      const refresh = SlimStatAdmin.refresh_report('slim_p7_02', { forceRecent: true });
      refresh().always(() => resolve());
    }));

    const after = await inside.evaluate((el) => el.scrollTop);
    // Allow a small drift; the key is that we did NOT reset to 0.
    expect(after, 'scrollTop should be preserved across refresh').toBeGreaterThanOrEqual(50);
  });
});
