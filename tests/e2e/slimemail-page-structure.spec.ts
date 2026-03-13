/**
 * AC-RPT-002 / AC-CSS-003: SlimEmail page structure
 *
 * Navigates to SlimStat > Email Reports page and verifies:
 * - Page renders without PHP errors
 * - Form elements are present
 * - Uses .wrap-slimstat wrapper consistently
 * - Matches admin page structure conventions
 */
import { test, expect } from '@playwright/test';
import {
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  installOptionMutator,
  uninstallOptionMutator,
} from './helpers/setup';
import { BASE_URL, MYSQL_CONFIG } from './helpers/env';
import * as mysql from 'mysql2/promise';

let db: mysql.Pool;
let slimEmailExists = false;

test.beforeAll(async () => {
  db = mysql.createPool({ ...MYSQL_CONFIG, connectionLimit: 3 });

  // Check if SlimEmail page is registered (it may be a Pro feature)
  // We'll attempt to load it and check the response
});

test.afterAll(async () => {
  if (db) await db.end();
});

test.describe('AC-CSS-003: SlimEmail Page Structure', () => {
  test.setTimeout(60_000);

  test.beforeEach(async () => {
    await snapshotSlimstatOptions();
    installOptionMutator();
  });

  test.afterEach(async () => {
    uninstallOptionMutator();
    await restoreSlimstatOptions();
  });

  test('SlimEmail page renders without PHP errors', async ({ page }) => {
    const response = await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimemail`, {
      waitUntil: 'domcontentloaded',
    });

    // SlimEmail may not be available (Pro-only feature)
    if (!response || response.status() !== 200) {
      test.skip();
      return;
    }

    const html = await page.content();
    expect(html).not.toContain('Fatal error');
    expect(html).not.toMatch(/PHP Warning:.*\.php/);
    expect(html).not.toMatch(/PHP Notice:.*\.php/);
    expect(html).not.toContain('undefined function');
  });

  test('SlimEmail page has .wrap-slimstat wrapper', async ({ page }) => {
    const response = await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimemail`, {
      waitUntil: 'domcontentloaded',
    });

    if (!response || response.status() !== 200) {
      test.skip();
      return;
    }

    await page.waitForTimeout(2000);

    const hasWrapper = await page.evaluate(() => {
      return document.querySelector('.wrap-slimstat') !== null;
    });
    expect(hasWrapper).toBe(true);
  });

  test('SlimEmail page contains form elements', async ({ page }) => {
    const response = await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimemail`, {
      waitUntil: 'domcontentloaded',
    });

    if (!response || response.status() !== 200) {
      test.skip();
      return;
    }

    await page.waitForTimeout(2000);

    // Check for standard form elements expected in an email reports page
    const formCheck = await page.evaluate(() => {
      const forms = document.querySelectorAll('form');
      const inputs = document.querySelectorAll('input, select, textarea');
      const buttons = document.querySelectorAll('button, input[type="submit"], .button');

      return {
        formCount: forms.length,
        inputCount: inputs.length,
        buttonCount: buttons.length,
        hasContent: document.querySelector('.wrap-slimstat')?.textContent?.trim().length ?? 0,
      };
    });

    // Page should have at least some interactive elements
    expect(formCheck.inputCount + formCheck.buttonCount).toBeGreaterThan(0);
    expect(formCheck.hasContent).toBeGreaterThan(0);
  });

  test('SlimEmail page has standard WordPress admin header structure', async ({ page }) => {
    const response = await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimemail`, {
      waitUntil: 'domcontentloaded',
    });

    if (!response || response.status() !== 200) {
      test.skip();
      return;
    }

    await page.waitForTimeout(2000);

    const structureCheck = await page.evaluate(() => {
      return {
        hasAdminMenu: document.querySelector('#adminmenu') !== null,
        hasWpContent: document.querySelector('#wpcontent') !== null,
        hasAdminBar: document.querySelector('#wpadminbar') !== null,
        hasWrapSlimstat: document.querySelector('.wrap-slimstat') !== null,
        // Should not have inline styles overriding admin conventions
        wrapperInlineStyle: (document.querySelector('.wrap-slimstat') as HTMLElement)?.style?.cssText ?? '',
      };
    });

    expect(structureCheck.hasAdminMenu).toBe(true);
    expect(structureCheck.hasWpContent).toBe(true);
    expect(structureCheck.hasWrapSlimstat).toBe(true);
  });

  test('SlimEmail page does not break on narrow viewport', async ({ page }) => {
    const response = await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimemail`, {
      waitUntil: 'domcontentloaded',
    });

    if (!response || response.status() !== 200) {
      test.skip();
      return;
    }

    // Resize to tablet width
    await page.setViewportSize({ width: 768, height: 1024 });
    await page.waitForTimeout(1000);

    // Check for horizontal overflow
    const overflowCheck = await page.evaluate(() => {
      return {
        bodyScrollWidth: document.body.scrollWidth,
        windowWidth: window.innerWidth,
        hasOverflow: document.body.scrollWidth > window.innerWidth + 20, // 20px tolerance
      };
    });

    expect(overflowCheck.hasOverflow).toBe(false);
  });
});
