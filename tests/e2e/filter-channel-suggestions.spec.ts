/**
 * Overview filter: Channel (and the UTM dimensions) once answered "Invalid dimension",
 * so typing in the value box showed no suggestions (2026-10-04 screenshot audit).
 *
 * Read-only: one nonce and one suggestions request, no rows written.
 */
import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';

test('Channel and UTM dimensions return filter suggestions', async ({ page }) => {
  const nonce = (await (await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
    form: { action: 'test_create_nonce', nonce_action: 'meta-box-order' },
  })).json()).data.nonce;

  for (const dimension of ['traffic_channel', 'traffic_source', 'utm_campaign', 'utm_id']) {
    const res = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
      form: { action: 'slimstat_get_filter_options', security: nonce, dimension, time_range_type: 'last_28_days' },
    });
    const json = await res.json();
    expect(json, dimension).toMatchObject({ success: true });
    expect(Array.isArray(json.data), dimension).toBe(true);
  }
});
