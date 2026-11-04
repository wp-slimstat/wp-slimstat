/** Real WordPress setup and report UX. Uses an explicitly provisioned disposable site. */
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
const fixture = JSON.parse(readFileSync(process.env.FORMS_QA_FIXTURE!, 'utf8'));
const password = process.env.FORMS_QA_PASSWORD_FILE ? readFileSync(process.env.FORMS_QA_PASSWORD_FILE, 'utf8').trim() : process.env.FORMS_QA_PASSWORD!;
if (!fixture.page || !fixture.form || !fixture.plain || !password) throw new Error('Provision the isolated Forms fixture before running this suite.');
async function login(page) {
  await page.goto('/wp-login.php');
  // WordPress focuses the username on a delayed timer; finish that before typing.
  await expect(page.locator('#user_login')).toBeFocused();
  await page.locator('#user_login').fill(process.env.FORMS_QA_USER || 'slimformsqa');
  await page.locator('#user_pass').fill(password);
  await page.locator('#wp-submit').click();
  await expect(page).toHaveURL(/\/wp-admin\//);
}
test('A page without forms has no Form Analytics SQL, assets or requests', async ({page}) => {
  const extra: string[]=[];
  page.on('request',r=>{if(/wp-slimstat-forms|form-fields\.js/.test(r.url()) || (r.postData()||'').includes('&fa=')) extra.push(r.url());});
  const response=await page.goto('/?page_id='+fixture.plain);
  await expect(page.locator('body')).toBeVisible();
  expect(response!.headers()['x-forms-qa-queries']).toBe('0');
  expect(await page.evaluate(()=>!!(window as any).SlimStatForms)).toBe(false);
  expect(extra).toEqual([]);
});
test('A real AJAX success produces a confirmed completion without collecting answers', async({page})=>{
  const batches:string[]=[];
  page.on('request',r=>{if((r.postData()||'').includes('&fa=')) batches.push(r.postData()!);});
  await page.goto('/?page_id='+fixture.page);
  await expect(page.locator('[data-slimstat-form]')).toHaveCount(1);
  await page.waitForFunction(()=>!!(window as any).SlimStatParams?.id);
  await page.locator('input[name="your-name"]').fill('QA PRIVATE_NAME');
  await page.locator('input[name="your-email"]').fill('qa@example.test');
  await page.locator('textarea[name="your-message"]').fill('PRIVATE_SENTINEL_NEVER_ANALYTICS');
  await page.locator('input[type=submit]').click();
  await expect(page.locator('.wpcf7-form')).toHaveAttribute('data-status','sent');
  expect(batches.length).toBeGreaterThan(0);
  for(const payload of batches){
    expect(payload).not.toMatch(/PRIVATE|qa%40example/);
    const batch=JSON.parse(new URLSearchParams(payload).get('fa')!);
    expect(batch.forms.every(f=>f.flags<16)).toBe(true);
  }
  await login(page);
  await page.goto('/wp-admin/admin.php?page=slimforms&form=cf7%3A'+fixture.form);
  await expect(page.getByRole('heading',{name:'Ask about a project'})).toBeVisible();
  const completed=page.locator('.ss-forms-funnel > div').filter({has:page.locator('dt',{hasText:/^Completed$/})}).locator('dd');
  await expect(completed).not.toHaveText('0');
});
test('One setting pauses collection and resumes it without losing the report', async({page,browser})=>{
  await login(page);
  await page.goto('/wp-admin/admin.php?page=slimforms&tab=setup');
  const checkbox=page.getByRole('checkbox',{name:'Collect form activity'});
  const visitor=await browser.newPage();
  try {
    await checkbox.uncheck();
    await page.getByRole('button',{name:'Save setting'}).click();
    await expect(page.getByText('Setting saved. Form activity collection is paused.',{exact:true})).toBeVisible();
    await visitor.goto('/?page_id='+fixture.page);
    await expect(visitor.locator('[data-slimstat-form]')).toHaveCount(0);
    await page.goto('/wp-admin/admin.php?page=slimforms&form=cf7%3A'+fixture.form);
    await expect(page.locator('.ss-forms-funnel')).toBeVisible();
  } finally {
    await page.goto('/wp-admin/admin.php?page=slimforms&tab=setup');
    await checkbox.check();await page.getByRole('button',{name:'Save setting'}).click();
    await expect(page.getByText('Setting saved. New form activity allowed by your privacy settings will be collected when SlimStat tracking is on.',{exact:true})).toBeVisible();
    await expect(checkbox).toBeChecked();await visitor.close();
  }
});
test('Reports and setup fit mobile and RTL layouts with keyboard-accessible explanations', async({page})=>{
  await login(page);
  await page.setViewportSize({width:390,height:844});
  for(const query of ['&tab=setup','&form=cf7%3A'+fixture.form]){
    await page.goto('/wp-admin/admin.php?page=slimforms'+query);
    for(const direction of ['ltr','rtl']){
      await page.evaluate(dir=>{document.documentElement.dir=dir;},direction);
      expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1)).toBe(true);
      const summary=page.locator('.ss-forms summary').first();
      await summary.focus();await page.keyboard.press('Enter');
      await expect(summary.locator('..')).toHaveAttribute('open','');
      await page.keyboard.press('Enter');
    }
  }
});
