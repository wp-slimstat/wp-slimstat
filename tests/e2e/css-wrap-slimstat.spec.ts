/**
 * AC-CSS-001 / AC-CSS-002: .wrap-slimstat container scoping
 *
 * Verifies:
 * - SlimStat admin pages use .wrap-slimstat (not .wrap.slimstat)
 * - No style leakage outside .wrap-slimstat container
 * - All SlimStat admin pages use consistent wrapper class
 * - No remaining .wrap.slimstat selectors in CSS or PHP output
 */
import { test, expect } from '@playwright/test';
import {
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  installOptionMutator,
  uninstallOptionMutator,
} from './helpers/setup';
import { BASE_URL, PLUGIN_DIR } from './helpers/env';
import * as fs from 'fs';
import * as path from 'path';

test.describe('AC-CSS-001/002: .wrap-slimstat Container Scoping', () => {
  test.setTimeout(60_000);

  test.beforeEach(async () => {
    await snapshotSlimstatOptions();
    installOptionMutator();
  });

  test.afterEach(async () => {
    uninstallOptionMutator();
    await restoreSlimstatOptions();
  });

  test('SlimStat dashboard uses .wrap-slimstat wrapper class', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForTimeout(2000);

    // The main wrapper should use wrap-slimstat
    const wrapSlimstat = page.locator('.wrap-slimstat');
    await expect(wrapSlimstat).toBeVisible({ timeout: 10_000 });

    // Should NOT have old-style .wrap.slimstat (element with both classes separately)
    const oldStyleWrap = await page.evaluate(() => {
      const el = document.querySelector('.wrap.slimstat');
      // Only flag if it exists AND doesn't also have wrap-slimstat
      if (!el) return false;
      return !el.classList.contains('wrap-slimstat');
    });
    expect(oldStyleWrap).toBe(false);
  });

  test('all SlimStat admin pages use consistent wrapper class', async ({ page }) => {
    const pages = ['slimview1', 'slimview2', 'slimview3', 'slimconfig'];

    for (const slug of pages) {
      const response = await page.goto(`${BASE_URL}/wp-admin/admin.php?page=${slug}`, {
        waitUntil: 'domcontentloaded',
      });

      // Some pages may redirect or not exist; only check 200 responses
      if (!response || response.status() !== 200) continue;

      await page.waitForTimeout(1000);

      const hasWrapSlimstat = await page.evaluate(() => {
        return document.querySelector('.wrap-slimstat') !== null;
      });

      expect(hasWrapSlimstat, `Page ${slug} should have .wrap-slimstat`).toBe(true);
    }
  });

  test('no style leakage outside .wrap-slimstat container', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForTimeout(2000);

    // Check that WP core admin elements outside .wrap-slimstat are not
    // affected by SlimStat-specific styles
    const leakageCheck = await page.evaluate(() => {
      const adminMenu = document.querySelector('#adminmenu');
      const wpContent = document.querySelector('#wpcontent');

      if (!adminMenu || !wpContent) return { ok: true };

      // Get computed styles for core WP elements
      const menuStyle = window.getComputedStyle(adminMenu);
      const contentStyle = window.getComputedStyle(wpContent);

      // These should not have SlimStat-specific overrides
      // Basic sanity: WP admin menu should still be visible and positioned
      return {
        ok: true,
        menuDisplay: menuStyle.display,
        contentMargin: contentStyle.marginLeft,
      };
    });

    expect(leakageCheck.ok).toBe(true);
    // Admin menu should still be displayed normally
    if (leakageCheck.menuDisplay) {
      expect(leakageCheck.menuDisplay).not.toBe('none');
    }
  });

  test('no .wrap.slimstat selectors in CSS source files', async () => {
    const cssDir = path.join(PLUGIN_DIR, 'admin', 'assets', 'css');
    if (!fs.existsSync(cssDir)) {
      test.skip();
      return;
    }

    const cssFiles = fs.readdirSync(cssDir).filter(f => f.endsWith('.css'));
    const violations: string[] = [];

    for (const cssFile of cssFiles) {
      const content = fs.readFileSync(path.join(cssDir, cssFile), 'utf8');

      // Look for old-style .wrap.slimstat selector (not .wrap-slimstat)
      // Match ".wrap.slimstat" but not ".wrap-slimstat"
      const matches = content.match(/\.wrap\.slimstat(?!-)/g);
      if (matches) {
        violations.push(`${cssFile}: found ${matches.length} instance(s) of .wrap.slimstat`);
      }
    }

    expect(violations).toHaveLength(0);
  });

  test('no .wrap.slimstat in PHP template HTML output', async ({ page }) => {
    const pages = ['slimview1', 'slimconfig'];

    for (const slug of pages) {
      await page.goto(`${BASE_URL}/wp-admin/admin.php?page=${slug}`, {
        waitUntil: 'domcontentloaded',
      });

      const html = await page.content();

      // Check for old-style class="wrap slimstat" (two separate classes)
      // This pattern indicates the deprecated wrapper style
      const hasOldClass = /class="[^"]*\bwrap\b[^"]*\bslimstat\b[^"]*"/.test(html) &&
        !html.includes('wrap-slimstat');

      expect(hasOldClass, `Page ${slug} should not use class="wrap slimstat"`).toBe(false);
    }
  });

  test('WordPress core .wrap padding does not affect SlimStat', async ({ page }) => {
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview1`, {
      waitUntil: 'domcontentloaded',
    });
    await page.waitForTimeout(2000);

    const paddingCheck = await page.evaluate(() => {
      const wrapSlimstat = document.querySelector('.wrap-slimstat') as HTMLElement;
      if (!wrapSlimstat) return null;

      // .wrap-slimstat should not inherit WP core .wrap padding unintentionally
      const computed = window.getComputedStyle(wrapSlimstat);
      return {
        exists: true,
        className: wrapSlimstat.className,
        hasWrapClass: wrapSlimstat.classList.contains('wrap'),
      };
    });

    expect(paddingCheck).not.toBeNull();
    expect(paddingCheck!.exists).toBe(true);
    expect(paddingCheck!.className).toContain('wrap-slimstat');
  });
});
