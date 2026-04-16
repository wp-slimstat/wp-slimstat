/**
 * Regression test for GitHub issue #291 / support ticket #14843 (Bob Boyd).
 *
 * Bob reported: "ignore_bots" setting wasn't stopping Chrome-based Googlebot.
 * Root cause: When Browscap identifies Chrome-based Googlebot as "Chrome"
 * (without crawler=true), the UADetector fallback is skipped, browser_type
 * stays 0, and ignore_bots doesn't trigger.
 *
 * Fix: Browscap::apply_bot_safety_net() runs the generic bot regex as
 * defense-in-depth after Browscap/UADetector resolution.
 *
 * NOTE: The E2E behavioral test passes on BOTH branches because the local
 * Browscap database doesn't classify Chrome-based Googlebot (returns
 * "Default Browser"), so UADetector's generic regex catches it on both.
 * The branch-specific regression proof is in the PHP-level test:
 *   php tests/browscap-bot-safety-net-test.php
 *   → FAILS on development (apply_bot_safety_net doesn't exist)
 *   → PASSES on fix/291 branch
 *
 * This E2E test validates the full tracking pipeline works correctly
 * with ignore_bots=on and Browscap enabled.
 *
 * @see https://github.com/wp-slimstat/wp-slimstat/issues/291
 */
import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';
import * as fs from 'fs';
import * as path from 'path';
import { fileURLToPath } from 'url';
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
  restoreWpConfig,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

const EMPTY_STORAGE_STATE = { cookies: [], origins: [] };

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const PLUGIN_DIR = path.resolve(__dirname, '..', '..');
const WP_ROOT = process.env.WP_ROOT || path.resolve(PLUGIN_DIR, '..', '..', '..');
const WP_CONFIG = path.join(WP_ROOT, 'wp-config.php');

let wpConfigBackup: string | null = null;

function injectConfigLine(line: string): void {
  const content = fs.readFileSync(WP_CONFIG, 'utf8');
  if (wpConfigBackup === null) wpConfigBackup = content;
  if (content.includes(line)) return;
  const marker = "/* That's all, stop editing!";
  const idx = content.indexOf(marker);
  if (idx === -1) throw new Error('Cannot find stop-editing marker in wp-config.php');
  fs.writeFileSync(WP_CONFIG, content.slice(0, idx) + line + '\n' + content.slice(idx), 'utf8');
}

function restoreConfig(): void {
  if (wpConfigBackup !== null) {
    fs.writeFileSync(WP_CONFIG, wpConfigBackup, 'utf8');
    wpConfigBackup = null;
  }
}

async function getStatCount(): Promise<number> {
  const [rows] = (await getPool().execute(
    'SELECT COUNT(*) as cnt FROM wp_slim_stats'
  )) as any;
  return rows[0].cnt;
}

// Bob's exact scenario: Chrome-based Googlebot UAs
const BOT_UAS = [
  {
    name: 'Chrome-based desktop Googlebot',
    ua: 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Googlebot/2.1; +http://www.google.com/bot.html) Chrome/130.0.6723.117 Safari/537.36',
    marker: 'reg291-chrome-gbot',
  },
  {
    name: 'Chrome-based mobile Googlebot',
    ua: 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.6723.117 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
    marker: 'reg291-mobile-gbot',
  },
];

test.describe('Regression #291 — Chrome-based Googlebot bypass (@regression)', () => {
  test.beforeAll(async () => {
    injectConfigLine("define('DONOTCACHEPAGE', true);");
    try {
      execSync(`rm -rf "${path.join(WP_ROOT, 'wp-content/cache/wp-rocket')}"`, { stdio: 'ignore' });
    } catch {}

    await snapshotSlimstatOptions();
    await setSlimstatSetting('_migration_5460', '99.0.0');
    await setSlimstatSetting('javascript_mode', 'off');
    await setSlimstatSetting('is_tracking', 'on');
    await setSlimstatSetting('gdpr_enabled', 'off');
    await setSlimstatSetting('ignore_bots', 'on');
    await setSlimstatSetting('enable_browscap', 'on');
  });

  test.afterAll(async () => {
    await restoreSlimstatOptions();
    restoreConfig();
    restoreWpConfig();
    await closeDb();
  });

  test.beforeEach(async () => {
    await clearStatsTable();
  });

  for (const { name, ua, marker: markerPrefix } of BOT_UAS) {
    test(`${name} blocked by ignore_bots (#291)`, async ({ browser }) => {
      installHeaderInjector();
      setHeaderOverrides({ 'User-Agent': ua });

      const countBefore = await getStatCount();

      let anonCtx: import('@playwright/test').BrowserContext | undefined;
      let anonPage: import('@playwright/test').Page | undefined;
      try {
        anonCtx = await browser.newContext({
          javaScriptEnabled: false,
          storageState: EMPTY_STORAGE_STATE,
          userAgent: ua,
        });
        anonPage = await anonCtx.newPage();
        await anonPage.goto(`${BASE_URL}/?e2e_marker=${markerPrefix}-${Date.now()}`, {
          waitUntil: 'domcontentloaded',
        });

        await expect
          .poll(() => getStatCount(), { timeout: 8_000, intervals: [500] })
          .toBe(countBefore);
      } finally {
        await anonPage?.close();
        await anonCtx?.close();
        uninstallHeaderInjector();
      }
    });
  }
});
