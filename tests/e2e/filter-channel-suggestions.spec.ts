/**
 * Overview filter: Channel (and the UTM dimensions) once answered "Invalid dimension",
 * so typing in the value box showed no suggestions (2026-10-04 screenshot audit).
 *
 * The first test is read-only. The second writes one marked row and deletes only that row.
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';
import { getPool, closeDb } from './helpers/setup';

const nonceFor = async (page) => (await (await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
  form: { action: 'test_create_nonce', nonce_action: 'meta-box-order' },
})).json()).data.nonce;

test('Channel and UTM dimensions return filter suggestions', async ({ page }) => {
  const nonce = await nonceFor(page);

  for (const dimension of ['traffic_channel', 'traffic_source', 'utm_campaign', 'utm_id']) {
    const res = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
      form: { action: 'slimstat_get_filter_options', security: nonce, dimension, time_range_type: 'last_28_days' },
    });
    const json = await res.json();
    expect(json, dimension).toMatchObject({ success: true });
    expect(Array.isArray(json.data), dimension).toBe(true);
  }
});

// QA R6: suggestions were raw slugs, and typing "soc" or "Organic Social" matched nothing.
test('Channel suggestions read as labels and match either the label or the slug', async ({ page }) => {
  const agent = `filter-channel-e2e-${Date.now()}`;
  await getPool().execute(
    'INSERT INTO wp_slim_stats (dt, ip, resource, user_agent, traffic_channel) VALUES (UNIX_TIMESTAMP(), ?, ?, ?, ?)',
    ['127.0.0.1', '/filter-channel-e2e', agent, 'organic_social'],
  );
  // The first test may have cached this range's suggestions before the row existed.
  await getPool().execute("DELETE FROM wp_options WHERE option_name LIKE '_transient_slimstat_%' OR option_name LIKE '_transient_timeout_slimstat_%'");
  try {
    const nonce = await nonceFor(page);
    for (const search of ['', 'soc', 'Organic Soc', 'organic_so']) {
      const res = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
        form: { action: 'slimstat_get_filter_options', security: nonce, dimension: 'traffic_channel', time_range_type: 'last_28_days', search },
      });
      expect((await res.json()).data, search).toContainEqual({ value: 'organic_social', label: 'Organic Social' });
    }
  } finally {
    await getPool().execute('DELETE FROM wp_slim_stats WHERE user_agent = ?', [agent]);
    await closeDb();
  }
});
