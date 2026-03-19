/**
 * E2E: Exclusion Filters — validates ignore_content_types, ignore_bots,
 * ignore_resources, and ignore_wp_users in the tracking pipeline.
 *
 * Covers GitHub issues #233 and #236 (CPT exclusion requires cpt: prefix,
 * including attachment pages).
 *
 * @see jaan-to/outputs/qa/cases/10-exclusion-filters/10-test-cases-exclusion-filters.md
 */
import { test, expect } from '@playwright/test';
import {
  getPool,
  closeDb,
  clearStatsTable,
  setSlimstatSetting,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  installHeaderInjector,
  uninstallHeaderInjector,
  setHeaderOverrides,
  enableDisableWpCron,
  restoreWpConfig,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';
import * as path from 'path';
import * as fs from 'fs';
import { WP_ROOT } from './helpers/env';

// ─── CPT registration mu-plugin ──────────────────────────────────
const MU_PLUGINS_DIR = path.join(WP_ROOT, 'wp-content', 'mu-plugins');
const CPT_MU_PLUGIN = path.join(MU_PLUGINS_DIR, 'e2e-test-product-cpt.php');

const CPT_MU_PLUGIN_CONTENT = `<?php
/**
 * E2E Test: Register 'product' CPT for exclusion filter testing.
 */
if (!defined('ABSPATH')) exit;
add_action('init', function() {
    register_post_type('product', [
        'public'       => true,
        'label'        => 'Products',
        'has_archive'  => true,
        'rewrite'      => ['slug' => 'product'],
        'supports'     => ['title', 'editor'],
        'show_in_rest' => true,
    ]);
    // Flush rewrite rules so /product/{slug}/ pretty permalinks resolve immediately.
    flush_rewrite_rules();
});
`;

function installCptMuPlugin(): void {
  fs.mkdirSync(MU_PLUGINS_DIR, { recursive: true });
  fs.writeFileSync(CPT_MU_PLUGIN, CPT_MU_PLUGIN_CONTENT, 'utf8');
}

function uninstallCptMuPlugin(): void {
  if (fs.existsSync(CPT_MU_PLUGIN)) fs.unlinkSync(CPT_MU_PLUGIN);
}

// ─── DB helpers ──────────────────────────────────────────────────

async function getRecentStatByResource(resourceLike: string): Promise<any | null> {
  const [rows] = await getPool().execute(
    'SELECT id, resource, content_type, browser_type, username FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id DESC LIMIT 1',
    [`%${resourceLike}%`]
  ) as any;
  return rows.length > 0 ? rows[0] : null;
}

async function getStatCount(): Promise<number> {
  const [rows] = await getPool().execute('SELECT COUNT(*) as cnt FROM wp_slim_stats') as any;
  return rows[0].cnt;
}

async function createProductPost(title: string, slug: string): Promise<number> {
  const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
  const [result] = await getPool().execute(
    `INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_name, post_modified, post_modified_gmt, post_type, to_ping, pinged, post_content_filtered)
     VALUES (1, ?, ?, 'Test content.', ?, '', 'publish', 'closed', 'closed', ?, ?, ?, 'product', '', '', '')`,
    [now, now, title, slug, now, now]
  ) as any;
  return result.insertId;
}

async function createAttachmentPost(title: string, slug: string): Promise<number> {
  const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
  const [result] = await getPool().execute(
    `INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, guid, menu_order, post_type, post_mime_type, post_parent, comment_count)
     VALUES (1, ?, ?, '', ?, '', 'inherit', 'closed', 'closed', ?, '', '', ?, ?, '', ?, 0, 'attachment', 'image/jpeg', 0, 0)`,
    [now, now, title, slug, now, now, `${BASE_URL}/wp-content/uploads/${slug}.jpg`]
  ) as any;
  return result.insertId;
}

// ─── Test suite ──────────────────────────────────────────────────

test.describe('Exclusion Filters (@tracking-exclusions)', () => {
  test.beforeAll(async () => {
    enableDisableWpCron();
    installCptMuPlugin();
    await snapshotSlimstatOptions();
    // Ensure server-side tracking is on
    await setSlimstatSetting('javascript_mode', 'off');
    await setSlimstatSetting('is_tracking', 'on');
  });

  test.afterAll(async () => {
    await restoreSlimstatOptions();
    uninstallCptMuPlugin();
    restoreWpConfig();
    await closeDb();
  });

  test.beforeEach(async () => {
    await clearStatsTable();
  });

  // ────────────────────────────────────────────────────────────────
  // Test 1: CPT exclusion WITH cpt: prefix — should exclude
  // REQ-EXCL-003 — @smoke @positive @priority-critical
  // ────────────────────────────────────────────────────────────────
  test('CPT excluded when ignore_content_types contains cpt:product', async ({ browser }) => {
    await setSlimstatSetting('ignore_content_types', 'cpt:product');

    // Create product post via direct DB insert
    const slug = `e2e-cpt-excl-${Date.now()}`;
    const postId = await createProductPost('E2E CPT Exclusion Test', slug);
    // Use pretty permalink to avoid 301 redirect being tracked as redirect:301
    const productUrl = `${BASE_URL}/product/${slug}/`;

    // Clear stats after post creation
    await clearStatsTable();

    // Visit single product post as anonymous user
    const anonCtx = await browser.newContext();
    const anonPage = await anonCtx.newPage();
    await anonPage.goto(productUrl, { waitUntil: 'domcontentloaded' });

    // Server-side tracking fires during shutdown (same PHP request), so the DB
    // state is settled by the time goto() resolves. Poll until consistent null.
    await expect.poll(
      () => getRecentStatByResource(slug),
      { timeout: 6_000, intervals: [500] }
    ).toBeNull();

    await anonPage.close();
    await anonCtx.close();
  });

  // ────────────────────────────────────────────────────────────────
  // Test 2: CPT exclusion WITHOUT prefix — should NOT exclude
  // REQ-EXCL-004 — @smoke @negative @priority-critical
  // Confirms GitHub issue #233 behavior
  // ────────────────────────────────────────────────────────────────
  test('CPT NOT excluded when ignore_content_types lacks cpt: prefix (#233)', async ({ browser }) => {
    // Set exclusion WITHOUT the cpt: prefix — this should fail to match
    await setSlimstatSetting('ignore_content_types', 'product');

    // Create product post via direct DB insert
    const slug = `e2e-cpt-noprefix-${Date.now()}`;
    const postId = await createProductPost('E2E No-Prefix Product', slug);
    // Use pretty permalink to avoid 301 redirect being tracked as redirect:301
    const productUrl = `${BASE_URL}/product/${slug}/`;

    // Clear stats after post creation
    await clearStatsTable();

    // Visit as anonymous
    const anonCtx = await browser.newContext();
    const anonPage = await anonCtx.newPage();
    await anonPage.goto(productUrl, { waitUntil: 'domcontentloaded' });

    // Without cpt: prefix, the exclusion should NOT match — pageview tracked.
    // Poll until the row appears (expect.poll returns void, so fetch separately for assertions).
    await expect.poll(
      () => getRecentStatByResource(slug),
      { timeout: 10_000, intervals: [500] }
    ).not.toBeNull();

    // Verify the tracked row has content_type starting with 'cpt:'
    const stat = await getRecentStatByResource(slug);
    expect(stat!.content_type).toMatch(/^cpt:/);

    await anonPage.close();
    await anonCtx.close();
  });

  // ────────────────────────────────────────────────────────────────
  // Test 3: Bot exclusion — ignore_bots=on excludes Googlebot
  // REQ-EXCL-001 — @smoke @positive @priority-critical
  // ────────────────────────────────────────────────────────────────
  test('Bot excluded when ignore_bots is on with Googlebot UA', async ({ browser }) => {
    await setSlimstatSetting('ignore_bots', 'on');

    // Install header injector to override UA server-side
    installHeaderInjector();
    setHeaderOverrides({ 'User-Agent': 'Googlebot/2.1 (+http://www.google.com/bot.html)' });

    const countBefore = await getStatCount();

    let anonCtx: import('@playwright/test').BrowserContext | undefined;
    let anonPage: import('@playwright/test').Page | undefined;
    try {
      // Visit with Googlebot UA
      anonCtx = await browser.newContext({
        userAgent: 'Googlebot/2.1 (+http://www.google.com/bot.html)',
      });
      anonPage = await anonCtx.newPage();
      const marker = `e2e-bot-test-${Date.now()}`;
      await anonPage.goto(`${BASE_URL}/?e2e_marker=${marker}`, { waitUntil: 'domcontentloaded' });

      // Bot should be excluded — poll to confirm count stays unchanged
      await expect.poll(
        () => getStatCount(),
        { timeout: 6_000, intervals: [500] }
      ).toBe(countBefore);
    } finally {
      await anonPage?.close();
      await anonCtx?.close();
      uninstallHeaderInjector();
    }
  });

  // ────────────────────────────────────────────────────────────────
  // Test 4: Permalink exclusion — ignore_resources with /wp-login.php
  // REQ-EXCL-005 — @smoke @positive @priority-critical
  // ────────────────────────────────────────────────────────────────
  test('Permalink excluded when ignore_resources matches /wp-login.php', async ({ browser }) => {
    await setSlimstatSetting('ignore_resources', '/wp-login.php');

    const countBefore = await getStatCount();

    // Visit wp-login.php as anonymous
    const anonCtx = await browser.newContext();
    const anonPage = await anonCtx.newPage();
    await anonPage.goto(`${BASE_URL}/wp-login.php`, { waitUntil: 'domcontentloaded' });

    // wp-login.php should be excluded — poll to confirm count stays unchanged
    await expect.poll(
      () => getStatCount(),
      { timeout: 6_000, intervals: [500] }
    ).toBe(countBefore);

    await anonPage.close();
    await anonCtx.close();
  });

  // ────────────────────────────────────────────────────────────────
  // Test 5: User exclusion — ignore_wp_users=on with logged-in admin
  // REQ-EXCL-006 — @smoke @positive @priority-critical
  // ────────────────────────────────────────────────────────────────
  test('Logged-in admin excluded when ignore_wp_users is on', async ({ browser }) => {
    await setSlimstatSetting('ignore_wp_users', 'on');

    // Login directly in a fresh browser context
    const adminCtx = await browser.newContext();
    const adminPage = await adminCtx.newPage();
    await adminPage.goto(`${BASE_URL}/wp-login.php`);
    await adminPage.fill('#user_login', 'parhumm');
    await adminPage.fill('#user_pass', 'testpass123');
    await adminPage.click('#wp-submit');
    await adminPage.waitForURL('**/wp-admin/**', { timeout: 30_000 });

    // Clear stats table after login
    await clearStatsTable();

    // Navigate as logged-in admin to a frontend page with a marker
    const marker = `e2e-user-excl-${Date.now()}`;
    await adminPage.goto(`${BASE_URL}/?e2e_marker=${marker}`, { waitUntil: 'domcontentloaded' });

    // Admin should NOT be tracked — poll to confirm no row matching our marker appears
    await expect.poll(
      () => getRecentStatByResource(marker),
      { timeout: 6_000, intervals: [500] }
    ).toBeNull();

    await adminPage.close();
    await adminCtx.close();
  });

  // ────────────────────────────────────────────────────────────────
  // Test 6: Attachment exclusion WITH cpt: prefix — should exclude
  // REQ-EXCL-007 — @positive @priority-high
  // Confirms GitHub issue #236 behavior
  // ────────────────────────────────────────────────────────────────
  test('Attachment excluded when ignore_content_types = cpt:attachment (#236)', async ({ browser }) => {
    await setSlimstatSetting('ignore_content_types', 'cpt:attachment');

    const slug = `e2e-attachment-excl-${Date.now()}`;
    const attachmentId = await createAttachmentPost('E2E Attachment Exclusion Test', slug);
    const attachmentUrl = `${BASE_URL}/?attachment_id=${attachmentId}`;

    await clearStatsTable();

    const anonCtx = await browser.newContext();
    const anonPage = await anonCtx.newPage();
    await anonPage.goto(attachmentUrl, { waitUntil: 'domcontentloaded' });

    await expect.poll(
      () => getStatCount(),
      { timeout: 6_000, intervals: [500] }
    ).toBe(0);

    await anonPage.close();
    await anonCtx.close();
  });

  // ────────────────────────────────────────────────────────────────
  // Test 7: Attachment without exclusion — should track
  // REQ-EXCL-008 — @positive @priority-high
  // ────────────────────────────────────────────────────────────────
  test('Attachment tracked without exclusion, stat saved (#236)', async ({ browser }) => {
    await setSlimstatSetting('ignore_content_types', '');

    const slug = `e2e-attachment-track-${Date.now()}`;
    const attachmentId = await createAttachmentPost('E2E Attachment Tracking Test', slug);
    const attachmentUrl = `${BASE_URL}/?attachment_id=${attachmentId}`;

    await clearStatsTable();

    const anonCtx = await browser.newContext();
    const anonPage = await anonCtx.newPage();
    await anonPage.goto(attachmentUrl, { waitUntil: 'domcontentloaded' });

    await expect.poll(
      () => getStatCount(),
      { timeout: 10_000, intervals: [500] }
    ).toBeGreaterThan(0);

    await anonPage.close();
    await anonCtx.close();
  });
});
