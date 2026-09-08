import { test, expect } from '@playwright/test';
import { BASE_URL, ADMIN_USER } from './helpers/env';

test('new anonymous contexts have no inherited WordPress authentication', async ({ browser }, testInfo) => {
  const inherited = await browser.newContext();
  const anonymous = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  try {
    const identity = async (context: typeof anonymous) => ({
      cookies: (await context.cookies()).map(cookie => cookie.name),
      response: await (await context.request.post(`${BASE_URL}/wp-admin/admin-ajax.php`, { form: { action: 'test_current_identity' } })).json(),
    });
    const before = await identity(inherited);
    const after = await identity(anonymous);
    await testInfo.attach('server-authentication-control', { body: JSON.stringify({ before, after }, null, 2), contentType: 'application/json' });
    expect(before.response.data.login).toBe(ADMIN_USER);
    expect(before.response.data.can_manage_options).toBe(true);
    expect(before.cookies.some(name => name.startsWith('wordpress_logged_in_'))).toBe(true);
    expect(after.response.data).toEqual({ login: '', roles: [], can_manage_options: false });
    expect(after.cookies).toEqual([]);
  } finally {
    await inherited.close();
    await anonymous.close();
  }
});
