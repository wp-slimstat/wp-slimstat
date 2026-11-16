import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { WP_ROOT, ADMIN_USER } from './helpers/env';
import { runWordPressFixture } from './helpers/chart';

const fixture = path.join(WP_ROOT, 'wp-content/mu-plugins/slimstat-custom-db-notice-test.php');
const notice = '[data-notice-key="qa-custom-db"]';
let previous: string;

test.beforeAll(() => {
  previous = runWordPressFixture(`<?php
    $key = 'slimstat_notice_dismissals_' . get_current_blog_id();
    $user = get_user_by('login', base64_decode('${Buffer.from(ADMIN_USER).toString('base64')}'));
    $records = get_user_meta($user->ID, $key, true);
    echo base64_encode(serialize([$user->ID, is_array($records) ? ($records['qa-custom-db'] ?? null) : null]));
    if (is_array($records)) { unset($records['qa-custom-db']); update_user_meta($user->ID, $key, $records); }
  `).trim();
  fs.writeFileSync(fixture, `<?php
    add_action('admin_notices', static function () {
      if (current_user_can('manage_options')) wp_slimstat_admin::show_dismissible_notice(
        'qa-custom-db', hash('sha256', 'occurrence-one'), 'Custom database notice test.', [], 'error', DAY_IN_SECONDS
      );
    });
  `);
});

test.afterAll(() => {
  fs.rmSync(fixture, { force: true });
  if (previous) runWordPressFixture(`<?php
    [$user, $previous] = unserialize(base64_decode('${previous}'));
    $key = 'slimstat_notice_dismissals_' . get_current_blog_id();
    $records = get_user_meta($user, $key, true);
    $records = is_array($records) ? $records : [];
    if ($previous === null) unset($records['qa-custom-db']); else $records['qa-custom-db'] = $previous;
    update_user_meta($user, $key, $records);
  `);
});

test('database notice dismisses by keyboard without reload and persists for this occurrence', async ({ page }, info) => {
  await page.goto('/wp-admin/index.php');
  const banner = page.locator(notice);
  await expect(banner).toBeVisible();
  await expect(banner).toHaveAttribute('role', 'status');
  await banner.screenshot({ path: info.outputPath('custom-db-notice.png') });
  const dismiss = banner.getByRole('button', { name: 'Dismiss this notice.' });
  await dismiss.focus();
  const marker = await page.evaluate(() => (window as any).__noticePageMarker = Math.random());
  const response = page.waitForResponse(r => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes('slimstat_dismiss_notice'));
  await page.keyboard.press('Enter');
  expect((await response).status()).toBe(200);
  await expect(banner).toHaveCount(0);
  expect(await page.evaluate(() => (window as any).__noticePageMarker)).toBe(marker);
  await page.reload();
  await expect(banner).toHaveCount(0);
  fs.writeFileSync(fixture, fs.readFileSync(fixture, 'utf8').replace('occurrence-one', 'occurrence-two'));
  await page.reload();
  await expect(banner).toBeVisible();
  const bad = await page.request.post('/wp-admin/admin-ajax.php', { form: {
    action: 'slimstat_dismiss_notice', key: 'qa-custom-db',
    stamp: (await banner.getAttribute('data-notice-stamp'))!, snooze: '86400', nonce: 'invalid',
  } });
  expect(bad.status()).toBe(403);
});
