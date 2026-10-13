import { test, expect } from '@playwright/test';
import { getPool, closeDb } from './helpers/setup';
import { BASE_URL } from './helpers/env';

async function optionValue(name: string): Promise<string | null> {
  const [rows] = await getPool().execute('SELECT option_value FROM wp_options WHERE option_name = ?', [name]) as any;
  return rows[0]?.option_value ?? null;
}

test.describe('admin request input boundaries', () => {
  test.afterAll(async () => { await closeDb(); });

  test('nested report and goal fields fail without fatals or option writes', async ({ page }) => {
    const before = {
      settings: await optionValue('slimstat_options'),
      goals: await optionValue('slimstat_goals'),
    };

    const reportResponse = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
      form: { action: 'slimstat_load_report', 'report_id[]': 'x' },
    });
    expect(reportResponse.status()).toBeLessThan(500);
    expect(await reportResponse.text()).not.toContain('Fatal error');

    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview6`, { waitUntil: 'domcontentloaded' });
    const nonce = await page.evaluate(() => (window as any).SlimStatAdminParams?.goals_nonce);
    expect(typeof nonce).toBe('string');
    const goalResponse = await page.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, {
      form: {
        action: 'slimstat_save_goal',
        security: nonce,
        name: 'Malformed boundary probe',
        'dimension[]': 'resource',
        operator: 'equals',
        value: '/must-not-save',
      },
    });
    expect(goalResponse.status()).toBeLessThan(500);
    expect(await goalResponse.json()).toMatchObject({ success: false, data: { message: 'Invalid goal definition' } });

    expect(await optionValue('slimstat_options')).toBe(before.settings);
    expect(await optionValue('slimstat_goals')).toBe(before.goals);
  });
});
