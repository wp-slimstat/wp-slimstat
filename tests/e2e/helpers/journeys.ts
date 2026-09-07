import { expect, type Page } from '@playwright/test';
import { getPool, waitForTrackerId } from './setup';

export async function getCorrelatedRows(runId: string): Promise<any[]> {
  const [rows] = await getPool().execute(
    'SELECT id, resource, referer, visit_id, username, email, notes FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id',
    [`%${runId}%`],
  );
  return rows as any[];
}

export async function waitForTracking(page: Page, runId: string, expectedCount: number): Promise<void> {
  await waitForTrackerId(page);
  await expect.poll(async () => (await getCorrelatedRows(runId)).length).toBeGreaterThanOrEqual(expectedCount);
}

export async function shopperJourney(page: Page, runId: string, store: { product: string; email: string }): Promise<{ orderUrl: string }> {
  // Keep correlation on real redirects without changing any tracker inputs.
  await page.addInitScript(id => {
    const url = new URL(location.href);
    url.searchParams.set('e2e_run', id);
    history.replaceState(null, '', url.href);
  }, runId);
  await page.goto(store.product, { waitUntil: 'networkidle' });
  const cookieAccept = page.getByRole('button', { name: 'Accept All', exact: true });
  if (await cookieAccept.isVisible()) await cookieAccept.click();
  await waitForTracking(page, runId, 1);
  await page.locator('button.single_add_to_cart_button').click();
  await expect(page.locator('.woocommerce-cart-form')).toBeVisible();
  await waitForTracking(page, runId, 2);
  await page.locator('a.checkout-button').click();
  await expect(page.locator('form.checkout')).toBeVisible();
  await waitForTracking(page, runId, 3);
  await page.locator('#billing_first_name').fill('E2EPurchaseBuyer');
  await page.locator('#billing_last_name').fill('InternalQA');
  await page.locator('#billing_country').selectOption('DE');
  await page.locator('#billing_address_1').fill('1 Test Lane');
  await page.locator('#billing_city').fill('Berlin');
  await page.locator('#billing_postcode').fill('10115');
  await page.locator('#billing_phone').fill('0300000000');
  await page.locator('#billing_email').fill(store.email);
  await page.locator('label[for="payment_method_bacs"]').click();
  await page.locator('#place_order').click();
  await expect(page).toHaveURL(/order-received/);
  await expect(page.locator('.woocommerce-order')).toBeVisible();
  await waitForTracking(page, runId, 4);
  return { orderUrl: page.url() };
}
