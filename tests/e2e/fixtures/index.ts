/**
 * Fixture loader for wp-slimstat E2E test suite.
 *
 * Seeds deterministic WordPress site profiles using WP-CLI eval-file.
 * Each profile is idempotent — safe to run on every test run.
 *
 * Usage (in a Playwright test or global-setup):
 *
 *   import { seedProfile } from './fixtures/index.js';
 *   seedProfile('publisher');
 *   seedProfile('store', '/custom/wp/path');
 *
 * Skip detection (multisite):
 *   The multisite fixture emits "FIXTURE_SKIP:multisite" to stdout when the
 *   installation is not multisite. seedProfile returns the stdout string so
 *   callers can inspect it:
 *
 *   const out = seedProfile('multisite');
 *   if (out.includes('FIXTURE_SKIP:multisite')) test.skip();
 */

import { execSync } from 'child_process';
import * as path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);

export type SiteProfile = 'publisher' | 'store' | 'membership' | 'brochure' | 'multisite';

/**
 * Seeds a site profile fixture via WP-CLI.
 *
 * @param profile - One of the five named site profiles.
 * @param wpPath  - Absolute path to the WordPress installation root.
 *                  Defaults to /var/www/html (Local by Flywheel / Docker).
 * @returns       - Combined stdout/stderr string from WP-CLI, or empty string on failure.
 */
export function seedProfile(profile: SiteProfile, wpPath = '/var/www/html'): string {
    const fixturePath = path.join(__dirname, `${profile}.php`);

    try {
        const output = execSync(
            `wp eval-file "${fixturePath}" --path="${wpPath}" --allow-root`,
            { stdio: 'pipe' }
        );
        return output.toString();
    } catch (err) {
        const message = (err as NodeJS.ErrnoException & { stdout?: Buffer; stderr?: Buffer }).stdout?.toString()
            ?? (err as Error).message;
        console.warn(`[fixtures] Profile "${profile}" seed skipped or failed:`, message);
        return message;
    }
}

/**
 * Returns true if the fixture output contains a skip signal.
 * Useful for multisite tests that should be skipped on non-multisite installs.
 */
export function isSkipped(output: string, profile: SiteProfile): boolean {
    return output.includes(`FIXTURE_SKIP:${profile}`);
}
