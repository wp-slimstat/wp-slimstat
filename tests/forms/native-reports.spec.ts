/** Native controls must preserve the same authorized Form Analytics selection. */
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
const fixture = JSON.parse(readFileSync(process.env.FORMS_QA_FIXTURE!, 'utf8'));
const password = process.env.FORMS_QA_PASSWORD_FILE ? readFileSync(process.env.FORMS_QA_PASSWORD_FILE, 'utf8').trim() : process.env.FORMS_QA_PASSWORD!;
test('Native form navigation, refresh, AI copy, filters and date range agree', async ({ page, context }) => {
  await context.grantPermissions(['clipboard-read', 'clipboard-write']);
  await page.goto('/wp-login.php');
  // WordPress focuses the username on a delayed timer; finish that before typing.
  await expect(page.locator('#user_login')).toBeFocused();
  await page.locator('#user_login').fill(process.env.FORMS_QA_USER || 'slimformsqa');
  await page.locator('#user_pass').fill(password);
  await page.locator('#wp-submit').click();
  await expect(page).toHaveURL(/\/wp-admin\//);
  await page.goto('/wp-admin/admin.php?page=slimforms');
  await expect(page.locator('#adminmenu a[href="admin.php?page=slimforms"] .slimstat-menu-new-badge')).toBeVisible();
  await expect(page.locator('.slimstat-date-range-btn')).toBeVisible();
  await page.locator('.ss-forms-table a').filter({hasText:'Ask about a project'}).click();
  const formInput = page.locator('[name="fs[addon_form]"]');
  await expect(formInput).toHaveValue('equals cf7:'+fixture.form);
  const saved = page.waitForResponse(r=>r.url().includes('admin-ajax.php') && (r.request().postData()||'').includes('slimstat_manage_filters'));
  await page.locator('#slimstat-save-filter').click();
  expect(await (await saved).text()).toMatch(/Saved|Already saved/);
  await page.goto('/wp-admin/admin.php?page=slimforms');
  await page.locator('#slimstat-load-saved-filters').click();
  await page.locator('#slim_filters_overlay .slimstat-filter-link').filter({hasText:'cf7:'+fixture.form}).first().click();
  await expect(formInput).toHaveValue('equals cf7:'+fixture.form);
  const completed = page.locator('.ss-forms-funnel > div').filter({has:page.locator('dt',{hasText:/^Completed$/})}).locator('dd');
  await expect(completed).toBeVisible();
  const total = await completed.innerText();
  if (process.env.FORMS_QA_PRO === '1') {
    const download = page.waitForEvent('download');
    await page.locator('#slim_forms_01 .button-export-to-xls').click();
    const stream = await (await download).createReadStream();
    const chunks: Buffer[] = [];
    for await (const chunk of stream!) chunks.push(chunk);
    const csv = Buffer.concat(chunks).toString('utf8');
    expect(csv).toContain('Ask about a project');
    expect(Number(csv.trim().split(/\r?\n/)[1].split(',')[3])).toBe(Number(total.replace(/,/g,'')));
    expect(csv).not.toMatch(/PRIVATE_SENTINEL|@example/);
  }

  await page.locator('#slim_forms_01 .refresh').click();
  await expect(completed).toHaveText(total);
  await expect.poll(()=>page.evaluate(()=>!!(window as any).Chart?.getChart(document.querySelector('.ss-forms canvas')))).toBe(true);
  await page.getByRole('button',{name:'Copy AI Prompt',exact:true}).click();
  const prompt = await page.evaluate(()=>navigator.clipboard.readText());
  expect(prompt).toContain(total);
  expect(prompt).toContain('Completed');
  expect(prompt).not.toContain('Illustrative report preview');
  expect(prompt).not.toContain('Upgrade to Pro');
  await page.locator('#slimstat-filter-name').selectOption('browser_type');
  await page.locator('#slimstat-filter-value').fill('2');
  await page.locator('#slimstat-filters input[type=submit]').click();
  await expect(formInput).toHaveValue('equals cf7:'+fixture.form);
  await expect(page.locator('[name="fs[browser_type]"]')).toHaveValue('equals 2');
  await expect(page.locator('.ss-forms-empty')).toBeVisible();
  if (process.env.FORMS_QA_PRO === '1') {
    await page.goto('/wp-admin/admin.php?page=slimforms&fs[addon_form]=equals%20cf7%3A'+fixture.form+'&fs[browser]=contains%20Chrome');
    await expect(page.locator('.ss-forms-funnel')).toBeVisible();
  } else {
    await page.goto('/wp-admin/admin.php?page=slimforms&fs[addon_form]=equals%20cf7%3A'+fixture.form+'&fs[country]=equals%20de');
    await expect(page.getByRole('heading',{name:'Adjust your report selection'})).toBeVisible();
    await expect(page.locator('.ss-forms-funnel')).toHaveCount(0);
  }
  await page.goto('/wp-admin/admin.php?page=slimforms&fs[addon_form]=equals%20cf7%3A'+fixture.form);
  await page.locator('.slimstat-date-range-btn').click();
  await page.getByText('Yesterday',{exact:true}).click();
  await expect(formInput).toHaveValue('equals cf7:'+fixture.form);
  await expect(page.locator('.ss-forms-empty')).toBeVisible();
  await page.locator('#adminmenu .wp-submenu a[href="admin.php?page=slimview1"]').click();
  await expect(page).toHaveURL(/page=slimview1/);
  await expect(formInput).toHaveCount(0);
  await expect(page.locator('.slimstat-filter-list').filter({hasText:'cf7:'+fixture.form})).toHaveCount(0);
  await page.goto('/wp-admin/admin.php?page=slimview1&fs[addon_form]=equals%20cf7%3A'+fixture.form);
  await expect(formInput).toHaveCount(0);
});
