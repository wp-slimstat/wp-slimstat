/**
 * QA C1: without Pro, Email Report was one line of upsell on an empty page.
 * It now shows the weekly email built from the site's own last 7 days, and sends it on request.
 *
 * Seeds 400 pageviews this week and 500 older ones on pages of their own, and removes them afterwards.
 */
import * as fs from 'fs';
import * as path from 'path';
import { test, expect } from '@playwright/test';
import { runWordPressFixture } from './helpers/chart';
import { closeDb, getPool, installMuPluginByName, restoreOption, snapshotOption, uninstallMuPluginByName } from './helpers/setup';
import { WP_ROOT } from './helpers/env';

const PAGE = '/e2e-email-sample';
const OLD = '/e2e-email-sample-old';
const CAPTURE_FILE = path.join(WP_ROOT, 'wp-content', 'e2e-captured-mail.json');

test('Free: Email Report shows and sends a sample from the last 7 days', async ({ page }, testInfo) => {
  const pool = getPool();
  const now = Math.floor(Date.now() / 1000);
  const row = (resource: string, dt: number) => [resource, dt, '127.0.0.1', 1, 'Chrome', 'Windows', 'page', 'https://e2e-source.example/post'];
  const rows = [...Array(400).fill(0).map(() => row(PAGE, now)), ...Array(500).fill(0).map(() => row(OLD, now - 10 * 86400))];
  await snapshotOption('active_plugins');
  if (fs.existsSync(CAPTURE_FILE)) fs.unlinkSync(CAPTURE_FILE);
  installMuPluginByName('mail-sink-mu-plugin.php');
  await pool.query('INSERT INTO wp_slim_stats (resource, dt, ip, visit_id, browser, platform, content_type, referer) VALUES ?', [rows]);
  try {
    runWordPressFixture("<?php deactivate_plugins('wp-slimstat-pro/wp-slimstat-pro.php');");

    // Forged: no nonce, nothing sent.
    const forged = await page.request.post('/wp-admin/admin-post.php', { form: { action: 'slimstat_email_sample' } });
    expect(forged.status()).toBe(403);
    expect(fs.existsSync(CAPTURE_FILE)).toBe(false);

    await page.goto('/wp-admin/admin.php?page=slimemail');
    const sample = page.getByRole('region', { name: 'Sample email' });
    await expect(sample.getByRole('row', { name: new RegExp(`^${PAGE}\\s+400$`) })).toBeVisible();
    await expect(sample.getByRole('row', { name: /^e2e-source\.example\s+400$/ })).toBeVisible();
    await expect(sample).not.toContainText(OLD);
    await page.locator('.wrap-slimstat').screenshot({ path: testInfo.outputPath('free-sample.png') });

    await page.getByRole('button', { name: 'Send me a sample' }).click();
    await expect(page.locator('.notice-success')).toContainText('Sample sent to');
    const mail = JSON.parse(fs.readFileSync(CAPTURE_FILE, 'utf8'));
    expect(mail).toHaveLength(1);
    expect(mail[0].message).toContain(PAGE);
    expect(mail[0].message).toContain('e2e-source.example');
    expect(mail[0].message).not.toContain(OLD);
  } finally {
    await pool.query('DELETE FROM wp_slim_stats WHERE resource IN (?, ?)', [PAGE, OLD]);
    uninstallMuPluginByName('mail-sink-mu-plugin.php');
    if (fs.existsSync(CAPTURE_FILE)) fs.unlinkSync(CAPTURE_FILE);
    await restoreOption('active_plugins');
    await closeDb();
  }
});
