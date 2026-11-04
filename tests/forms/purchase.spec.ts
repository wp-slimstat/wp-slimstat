import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { fixtureScript } from './helpers';
const fixture = JSON.parse(readFileSync(process.env.FORMS_QA_FIXTURE!, 'utf8'));
async function purchase(mode: string, args: string[] = []) {
  const out=await fixtureScript('tests/forms/purchase.php',[mode,...args]);
  const line=out.split('\n').find(line=>line.startsWith('FORMS_PURCHASE_JSON:'));
  if(!line) throw new Error('Purchase fixture returned no result.');
  return JSON.parse(line.slice('FORMS_PURCHASE_JSON:'.length));
}
if(process.env.FORMS_QA_PRO==='1') test('Confirmed form to real WooCommerce checkout attributes only completed revenue',async({page})=>{
  test.setTimeout(90000);
  try {
    const store=await purchase('seed');
    await page.goto('/?page_id='+fixture.page);
    await page.waitForFunction(()=>!!(window as any).SlimStatParams?.id);
    const id=parseInt(await page.evaluate(()=>(window as any).SlimStatParams.id),10);
    await page.locator('[name="your-name"]').fill('Purchase QA');
    await page.locator('[name="your-email"]').fill(store.email);
    await page.locator('[type="submit"]').click();
    await expect(page.locator('.wpcf7-form')).toHaveAttribute('data-status','sent');
    await page.goto(store.product);
    await page.waitForFunction(()=>!!(window as any).SlimStatParams?.id);
    await page.locator('button.single_add_to_cart_button').click();
    await expect(page.locator('.woocommerce-cart-form')).toBeVisible();
    await page.waitForFunction(()=>!!(window as any).SlimStatParams?.id);
    await page.locator('a.checkout-button').click();
    await expect(page.locator('form.checkout')).toBeVisible();
    await page.waitForFunction(()=>!!(window as any).SlimStatParams?.id);
    await page.locator('#billing_first_name').fill('FormPurchaseQA');
    await page.locator('#billing_last_name').fill('Internal');
    await page.locator('#billing_country').selectOption('DE');
    await page.locator('#billing_address_1').fill('1 Test Lane');
    await page.locator('#billing_city').fill('Berlin');
    await page.locator('#billing_postcode').fill('10115');
    await page.locator('#billing_phone').fill('0300000000');
    await page.locator('#billing_email').fill(store.email);
    await page.locator('label[for="payment_method_bacs"]').click();
    await page.locator('#place_order').click();
    await expect(page).toHaveURL(/order-received/);
    const result=await purchase('confirm',[String(id),String(fixture.form)]);
    expect(result.unpaid).toEqual([]);
    expect(result.paid).toHaveLength(1);
    expect(Number(result.paid[0].orders)).toBe(1);
    expect(Number(result.paid[0].net)).toBe(Number(result.total));
  } finally { await purchase('cleanup'); }
});
