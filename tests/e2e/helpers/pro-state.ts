/**
 * The one place a spec is allowed to ask "is Pro usable here?".
 *
 * Every Pro spec used to answer that with its own probe, and every one of those probes
 * answered the wrong question. `pro_active` in version-floor-test-mu-plugin.php is
 * `class_exists('WpSlimstatProPlugin') || … || is_plugin_active(…)`; pro-maxmind's
 * isProActive() greps plugins.php for the strings 'wp-slimstat-pro' and 'Deactivate'.
 * Both are TRUE for a Pro that included its main file, threw out of its constructor,
 * registered no provider and asked WordPress to deactivate it — the exact state Pro
 * 3.0.0 21204b6c was in for a whole 137-minute census, during which 10 Pro failures
 * were filed against the wrong causes and 7 more tests skipped themselves.
 *
 * So: `pro_installed` is the only fact that may produce a skip (the Free CI lanes carry
 * no Pro at all — a declared omission in ci.yml, since Pro is a private repository).
 * Pro present but not `pro_booted` is the defect itself and must FAIL, loudly, naming
 * the degradation keys.
 */
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { BASE_URL } from './env';

export interface ProState {
  version: string | null;
  pro_version: string | null;
  /** Legacy disjunct kept for existing callers; true on an active-but-dead Pro. */
  pro_active: boolean;
  /** The plugin file exists in WP_PLUGIN_DIR. */
  pro_installed: boolean;
  /** WordPress's opinion: is_plugin_active(). */
  pro_activated: boolean;
  /** Pro's own behaviour: report slim_p8_01 registered, and no `pro_` degradation. */
  pro_booted: boolean;
  pro_degradations: string[];
}

export async function getProState(page: Page): Promise<ProState> {
  const res = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
    form: { action: 'e2e_get_slimstat_version' },
  });
  expect(res.ok(), 'version-floor mu-plugin AJAX endpoint should respond OK').toBeTruthy();
  return (await res.json()).data as ProState;
}

/**
 * Gate a Pro-only test. Skips ONLY when Pro is absent from the machine; fails when Pro
 * is present but did not boot. Returns the state so callers can read pro_version etc.
 */
export async function requireProBooted(page: Page): Promise<ProState> {
  const state = await getProState(page);

  if (!state.pro_installed) {
    test.skip(true, 'wp-slimstat-pro is not installed on this machine (declared omission: the Free CI lanes carry no Pro)');
    return state;
  }

  expect(
    state.pro_activated,
    'wp-slimstat-pro is installed but not activated — a Pro spec must never run against a switched-off Pro'
  ).toBe(true);

  expect(
    state.pro_booted,
    `wp-slimstat-pro is active but never booted: report slim_p8_01 is absent from slimstat_reports_info` +
      (state.pro_degradations.length ? `, degradations recorded: ${state.pro_degradations.join(', ')}` : '') +
      '. This is the defect, not a reason to skip — see A0 (load-order floor guard).'
  ).toBe(true);

  return state;
}
