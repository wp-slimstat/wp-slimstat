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
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';
import {
  closeDb,
  clearStatsTable,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  seedPageviews,
  captureAdminAjax,
} from './helpers/setup';

test.describe('Access Log auto-refresh — #258', () => {
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    await snapshotSlimstatOptions();
  });

  test.beforeEach(async () => {
    await clearStatsTable();
    // Seed 50 current-date pageviews so the access log has rows + the
    // refresh-timer is mounted (utime['end'] >= now-300).
    const now = Math.floor(Date.now() / 1000);
    await seedPageviews({
      count: 50,
      resourcePrefix: '/e2e-258-row-',
      baseDt: now - 49,
      stepSeconds: 1,
    });
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

    const cap = captureAdminAjax(
      page,
      (b) => b.includes('slimstat_load_report') && b.includes('slim_p7_02'),
    );

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
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
    cap.reset();
    await page.mouse.move(0, 0);
    await page.waitForTimeout(8_000);

    expect(
      cap.payloads.length,
      'with refresh_interval=5, at least one auto-refresh should fire within 8s',
    ).toBeGreaterThan(0);
  });

  test('refresh_interval=0 disables the access-log refresh-timer', async ({ page }) => {
    await setSlimstatOption(page, 'refresh_interval', '0');

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    await expect(page.locator('#slim_p7_02')).toBeVisible({ timeout: 30_000 });

    // PHP gate at wp-slimstat-reports.php:1058 should suppress the
    // <i class="refresh-timer"> element entirely when refresh_interval = 0.
    // This is a server-side render assertion — no need to wait for AJAX.
    const timerCount = await page.locator('.pagination .refresh-timer').count();
    expect(timerCount, 'refresh-timer element should not render when interval=0').toBe(0);
  });

  test('hover pauses the refresh tick', async ({ page }) => {
    // Use a short interval to keep the test runtime manageable.
    await setSlimstatOption(page, 'refresh_interval', '5');

    const cap = captureAdminAjax(
      page,
      (b) => b.includes('slimstat_load_report') && b.includes('slim_p7_02'),
    );

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    await expect(page.locator('#slim_p7_02')).toBeVisible({ timeout: 30_000 });
    // Hard-assert the refresh-timer is mounted. With refresh_interval=5
    // and 50 current-date rows seeded in beforeEach, the PHP gate at
    // wp-slimstat-reports.php:1058 (`refresh_interval > 0 && utime['end']
    // >= now-300`) is satisfied — if the timer is missing, the seed setup
    // or the gate broke and the test should fail loudly, not skip.
    const timer = page.locator('.pagination .refresh-timer');
    await expect(timer, 'refresh-timer must be mounted').toHaveCount(1, { timeout: 5_000 });

    // Hard-assert the .inside element has a bounding box so the mouse can
    // be positioned over it. Since #slim_p7_02 is visible (asserted above),
    // .inside must have a layout box.
    const inside = page.locator('#slim_p7_02 .inside');
    const box = await inside.boundingBox();
    expect(box, '.inside must have a bounding box').not.toBeNull();
    cap.reset();
    await page.mouse.move(box!.x + box!.width / 2, box!.y + box!.height / 2);
    await page.waitForTimeout(12_000);

    // No auto-refresh should have fired during the hover.
    expect(cap.payloads.length, 'refresh fired while hovered').toBe(0);

    // Move pointer away and wait for the next interval — refresh should resume.
    await page.mouse.move(0, 0);
    await page.waitForTimeout(8_000);
    expect(cap.payloads.length, 'refresh should resume after mouseleave').toBeGreaterThan(0);
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

    const cap = captureAdminAjax(page, (b) => b.includes('slimstat_get_adminbar_stats'));

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    // Wait for SlimStatAdmin to hydrate so the minute_pulse listener is bound.
    await page.waitForFunction(
      () => typeof (window as any).SlimStatAdmin === 'object',
      { timeout: 15_000 },
    );

    cap.reset();

    await page.evaluate(() => {
      window.dispatchEvent(new CustomEvent('slimstat:minute_pulse'));
    });
    // Wait for the AJAX response triggered by the listener.
    await page.waitForResponse(
      (resp) =>
        resp.url().includes('admin-ajax.php') &&
        (resp.request().postData() || '').includes('slimstat_get_adminbar_stats'),
      { timeout: 15_000 },
    );

    expect(
      cap.payloads.length,
      'admin-bar listener should respond to slimstat:minute_pulse',
    ).toBeGreaterThan(0);
  });

  test('scroll position survives a refresh', async ({ page }) => {
    await setSlimstatOption(page, 'refresh_interval', '5');

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    await expect(page.locator('#slim_p7_02')).toBeVisible({ timeout: 30_000 });
    // Wait for SlimStatAdmin to hydrate before invoking refresh_report.
    await page.waitForFunction(
      () => typeof (window as any).SlimStatAdmin?.refresh_report === 'function',
      { timeout: 15_000 },
    );

    // Hard-assert .inside is present. Since #slim_p7_02 is visible
    // (asserted above), .inside must exist as well.
    const inside = page.locator('#slim_p7_02 .inside');
    await expect(inside, '.inside must be present').toHaveCount(1);

    // Hard-assert the panel actually overflows. With 50 seeded rows and a
    // 465px tall postbox (.postbox.tall .inside), the content must
    // overflow by hundreds of pixels — if not, either the seed dropped
    // rows or the SCSS height regressed.
    const scrollHeight = await inside.evaluate((el) => el.scrollHeight);
    const clientHeight = await inside.evaluate((el) => el.clientHeight);
    expect(
      scrollHeight - clientHeight,
      'access log panel must overflow with 50 seeded rows',
    ).toBeGreaterThanOrEqual(100);

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
