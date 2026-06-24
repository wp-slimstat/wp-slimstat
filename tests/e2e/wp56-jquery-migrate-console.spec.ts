/**
 * E2E (compat matrix): JQMIGRATE console watchdog for jQuery 4.0 readiness.
 *
 * Proves the Phase-8 own-code migration: with the dev jQuery Migrate build
 * force-loaded, exercising the SlimStat admin surfaces must emit NO JQMIGRATE
 * warning attributable to OWN-CODE JS (admin.js, slimstat-daterangepicker.js,
 * addon-email-reports.js). Warnings from the bundled, unmaintained vendored libs
 * (qTip2, jqVMap, bootstrap-switch) are allow-listed — they're shimmed by jQuery
 * Migrate and tracked for a future upgrade, not migrated here.
 *
 * Without the loader mu-plugin WP ships jquery-migrate.min.js (silent), so the
 * watchdog would be a false-green — the mu-plugin forces the warning-emitting
 * dev build. Tagged @compat. (Source-level coverage of the own-code shorthands
 * is the local gate: composer test:jquery4-shorthand.)
 */
import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { fileURLToPath } from 'url';
import { BASE_URL, WP_ROOT } from './helpers/env';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const MU_DIR = path.join(WP_ROOT, 'wp-content', 'mu-plugins');
const MU_SRC = path.join(__dirname, 'helpers', 'jquery-migrate-loader-mu-plugin.php');
const MU_DEST = path.join(MU_DIR, 'jquery-migrate-loader-mu-plugin.php');

// Own-code JS files Phase 8 migrated — a JQMIGRATE warning sourced here is a regression.
const OWN_CODE_JS = ['admin.js', 'slimstat-daterangepicker.js', 'addon-email-reports.js'];

const ADMIN_PAGES = [
  '/wp-admin/admin.php?page=slimview1',
  '/wp-admin/admin.php?page=slimview2',
  '/wp-admin/admin.php?page=slimconfig&tab=1',
];

test.describe('JQMIGRATE watchdog — own-code is jQuery-4.0 clean @compat', () => {
  test.beforeAll(() => {
    fs.mkdirSync(MU_DIR, { recursive: true });
    fs.copyFileSync(MU_SRC, MU_DEST);
  });

  test.afterAll(() => {
    if (fs.existsSync(MU_DEST)) fs.unlinkSync(MU_DEST);
  });

  for (const url of ADMIN_PAGES) {
    test(`no own-code JQMIGRATE warning on ${url} @compat`, async ({ page }) => {
      const migrateWarnings: { text: string; src: string }[] = [];
      page.on('console', (msg) => {
        const t = msg.text();
        if (t.includes('JQMIGRATE')) {
          migrateWarnings.push({ text: t, src: msg.location().url || '' });
        }
      });

      await page.goto(`${BASE_URL}${url}`, { waitUntil: 'networkidle' });
      // Exercise the migrated filters-form path where present.
      const form = page.locator('#slimstat-filters-form');
      if (await form.count()) {
        await page.evaluate(() => {
          const f = document.querySelector('#slimstat-filters-form');
          // triggerHandler fires bound handlers WITHOUT the native submit/navigation,
          // so the page stays put and JQMIGRATE warnings are captured. Errors surface
          // (no .catch swallow) so a broken handler fails the test.
          if (f && (window as any).jQuery) (window as any).jQuery(f).triggerHandler('submit');
        });
      }

      const ownCodeWarnings = migrateWarnings.filter((w) =>
        OWN_CODE_JS.some((f) => w.src.includes(f)),
      );
      expect(
        ownCodeWarnings,
        `own-code JQMIGRATE warnings (vendored qTip2/jqVMap/bootstrap-switch are allow-listed):\n${ownCodeWarnings
          .map((w) => `  ${w.src}: ${w.text}`)
          .join('\n')}`,
      ).toHaveLength(0);
    });
  }
});
