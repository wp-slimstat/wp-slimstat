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

export async function shopperJourney(page: Page, runId: string, store: { product: string; email: string }, blocks = false, tracked = true): Promise<{ orderUrl: string }> {
  // Keep correlation on real redirects without changing any tracker inputs.
  await page.addInitScript(id => {
    const url = new URL(location.href);
    url.searchParams.set('e2e_run', id);
    history.replaceState(null, '', url.href);
  }, runId);
  await page.goto(store.product, { waitUntil: 'networkidle' });
  const cookieAccept = page.getByRole('button', { name: 'Accept All', exact: true });
  if (await cookieAccept.isVisible()) await cookieAccept.click();
  if (tracked) await waitForTracking(page, runId, 1);
  await page.locator('button.single_add_to_cart_button').click();
  await expect(page.locator(blocks ? '.wp-block-woocommerce-cart' : '.woocommerce-cart-form')).toBeVisible();
  if (tracked) await waitForTracking(page, runId, 2);
  await page.locator(blocks ? '.wc-block-cart__submit-button' : 'a.checkout-button').click();
  await expect(page.locator(blocks ? '.wc-block-checkout__form' : 'form.checkout')).toBeVisible();
  if (tracked) await waitForTracking(page, runId, 3);
  if (blocks) {
    await page.locator('#email').fill(store.email);
    const country = page.locator('#billing-country');
    if (await country.evaluate(el => el.tagName === 'SELECT')) await country.selectOption('DE');
    else {
      await country.fill('Germany');
      await page.getByRole('option', { name: 'Germany', exact: true }).click();
    }
    await page.locator('#billing-first_name').fill('E2EPurchaseBuyer');
    await page.locator('#billing-last_name').fill('InternalQA');
    await page.locator('#billing-address_1').fill('1 Test Lane');
    await page.locator('#billing-city').fill('Berlin');
    await page.locator('#billing-postcode').fill('10115');
    const phone = page.locator('#billing-phone');
    if (await phone.isVisible()) await phone.fill('0300000000');
    await page.getByRole('button', { name: /place order/i }).click();
  } else {
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
  }
  await expect(page).toHaveURL(/order-received/);
  await expect(page.getByText(/^Thank you\. Your order has been (received|fulfilled)\.$/)).toBeVisible();
  if (tracked) await waitForTracking(page, runId, 4);
  return { orderUrl: page.url() };
}
