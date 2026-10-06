/**
 * E2E regression: a consent upgrade for an already-tracked pageview must update
 * that row, never insert a second one.
 *
 * The JS fires `consent_upgrade=1` from the WP Consent API listeners whenever a
 * grant arrives (wp-slimstat.js `wp_listen_for_consent_change` /
 * `wp_consent_type_defined` — neither checks whether server-side tracking already
 * produced a row). With `javascript_mode=off` the page already carries a
 * checksummed `SlimStatParams.id`, so that upgrade names an existing row.
 *
 * `Ajax.php` routed every such request to `Processor::process()` for a
 * "session-wide consent merge" that only exists when `anonymous_tracking=on`.
 * With it off, process() fell through to a plain INSERT:
 *   - one pageview became two rows (double-counted), and
 *   - the new row's `resource` was the tracker endpoint itself, because
 *     process() falls back to REQUEST_URI and its self-tracking guard names
 *     only `wp-admin/admin-ajax.php` — never the REST route added later. So
 *     `/wp-json/slimstat/v1/hit` was recorded as a visited page.
 *
 * Over AJAX the same request was rejected as error 308 (that guard firing), so
 * the consent upgrade silently applied nothing at all. Both arms are asserted.
 *
 * Found via `outbound-link-tracking.spec.ts` on the WP 6.4 CI lane, which polled
 * the first row while the JS had moved on to the second.
 */
import { test, expect, APIRequestContext } from '@playwright/test';
import {
  clearStatsTable,
  getPool,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  setSlimstatOptions,
  closeDb,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

// A plain desktop UA: the default APIRequestContext one classifies as a crawler.
const UA =
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

async function countRows(): Promise<number> {
  const [rows] = (await getPool().execute(
    'SELECT COUNT(*) AS n FROM wp_slim_stats',
  )) as any;
  return Number(rows[0].n);
}

async function resources(): Promise<string[]> {
  const [rows] = (await getPool().execute(
    'SELECT resource FROM wp_slim_stats ORDER BY id',
  )) as any;
  return rows.map((r: any) => String(r.resource ?? ''));
}

/** Load a front-end page server-side-tracked and return its checksummed id. */
async function seedPageview(request: APIRequestContext, marker: string): Promise<string> {
  const res = await request.get(`${BASE_URL}/?consent-upgrade-probe=${marker}`, {
    headers: { 'User-Agent': UA },
  });
  const id = (await res.text()).match(/"id":"(\d+\.[a-f0-9]+)"/)?.[1];
  expect(
    id,
    'seed page must expose SlimStatParams.id — javascript_mode=off makes the pageview server-side',
  ).toBeTruthy();
  expect(await countRows(), 'the seed request stores exactly one row').toBe(1);
  return id!;
}

test.describe('Consent upgrade on an already-tracked pageview', () => {
  test.beforeAll(async () => {
    await snapshotSlimstatOptions();
  });

  test.afterAll(async () => {
    await restoreSlimstatOptions();
    await closeDb();
  });

  test.beforeEach(async () => {
    await setSlimstatOptions({
      is_tracking: 'on',
      // Server-side tracking, so the page carries a real checksummed id and the
      // consent upgrade below names an existing row — the case that broke.
      javascript_mode: 'off',
      // The branch under test: with anonymous tracking off there is no anonymous
      // row to merge, so the upgrade must be an in-place update of the known row.
      anonymous_tracking: 'off',
      gdpr_enabled: 'off',
      ignore_wp_users: 'no',
      ignore_bots: 'no',
      enable_browscap: 'no',
    });
    await clearStatsTable();
  });

  for (const arm of [
    { name: 'REST', path: '/wp-json/slimstat/v1/hit' },
    { name: 'AJAX', path: '/wp-admin/admin-ajax.php' },
  ]) {
    test(`${arm.name} transport upgrades the existing row instead of inserting a second`, async ({
      request,
    }) => {
      const marker = `${arm.name.toLowerCase()}-${Date.now()}`;
      const seedId = await seedPageview(request, marker);

      const res = await request.post(`${BASE_URL}${arm.path}`, {
        headers: { 'User-Agent': UA },
        form: {
          action: 'slimtrack',
          id: seedId,
          consent_upgrade: '1',
          pageview_id: seedId,
        },
      });
      const body = (await res.text()).trim();

      expect(
        await countRows(),
        `consent upgrade over ${arm.name} must not insert a second row for one pageview`,
      ).toBe(1);
      expect(
        (await resources()).filter((r) => r.includes('slimstat/v1/hit') || r.includes('admin-ajax.php')),
        'the tracker endpoint must never be stored as a visited page',
      ).toEqual([]);
      expect(
        body,
        `consent upgrade over ${arm.name} must answer with the row it upgraded, not an error code`,
      ).toBe(seedId);
    });
  }

  test('the upgraded row keeps the page URL the visitor was on', async ({ request }) => {
    const marker = `resource-${Date.now()}`;
    const seedId = await seedPageview(request, marker);

    await request.post(`${BASE_URL}/wp-json/slimstat/v1/hit`, {
      headers: { 'User-Agent': UA },
      form: { action: 'slimtrack', id: seedId, consent_upgrade: '1', pageview_id: seedId },
    });

    const stored = await resources();
    expect(stored, 'exactly one row survives the upgrade').toHaveLength(1);
    expect(
      stored[0],
      'the upgrade must preserve the original resource, not overwrite it with the tracker endpoint',
    ).toContain(marker);
  });
});
