/** Actual builder submissions reconciled against the isolated analytics database. */
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { snapshot } from './helpers';
const fixtures = JSON.parse(readFileSync(process.env.FORMS_QA_BUILDERS!, 'utf8'));
for (const [builder, fixture] of Object.entries(fixtures) as [string, {form:number,page:number}][]) {
  test(builder+': validation, real success, deduplication and value privacy', async ({page}, testInfo) => {
    const batches: string[] = [];
    const errors: string[] = [];
    const interactions: string[] = [];
    page.on('pageerror',error=>errors.push(error.stack || error.message));
    page.on('request',request=>{const body=request.postData()||'';if(body.includes('&fa='))batches.push(body);if(body.startsWith('action=slimtrack'))interactions.push(body);});
    await page.goto('/?page_id='+fixture.page);
    await expect(page.locator('[data-slimstat-form]')).toHaveCount(1);
    await page.waitForFunction(()=>!!(window as any).SlimStatParams?.id);
    const id = parseInt(await page.evaluate(()=>(window as any).SlimStatParams.id),10);
    const key = builder+':'+fixture.form;
    const form = page.locator('form').first();
    const submit = ()=>form.locator('button:not([type]):visible,button[type=submit]:visible,input[type=submit]:visible,input[type=button]:visible').last().click();
    await expect.poll(async()=>Number((await snapshot(key,id)).cohort?.flags)&2).toBe(2);
    await submit();
    await expect.poll(async()=>Number((await snapshot(key,id)).cohort?.flags)&8).toBe(8);
    expect(Number((await snapshot(key,id)).cohort.flags)&64).toBe(0);
    const fields = form.locator('input[type=text]:visible,input[type=email]:visible,textarea:visible');
    for(let i=0;i<await fields.count();i++) {
      const field=fields.nth(i), name=await field.getAttribute('name')||'', type=await field.getAttribute('type');
      if (/honeypot|frm_verify|hp-|_hp|hp_|-hp$/.test(name)) continue;
      if (builder==='wpforms' && !/\[fields\]\[[123]\]/.test(name)) continue;
      if (builder==='formidable' && !(await field.getAttribute('id')||'').startsWith('field_qa-')) continue;
      await field.evaluate(el=>el.setAttribute('value','QA_PRIVATE_DEFAULT'));
      await field.click();
      await field.fill(type==='email'||/email/.test(name)?'qa@example.com':'QA_PRIVATE_SENTINEL');
      await field.press('Tab');
    }
    const confirmedRequest = () => page.waitForResponse(response=>response.request().method()==='POST' && !(response.request().postData()||'').includes('slimtrack'));
    // Fluent ignores rapid resubmission for 1.5s even after invalid input; wait for its test-only guard.
    if (builder === 'fluent') await page.waitForFunction(()=>!(window as any).ff_sumitting_form);
    await Promise.all([confirmedRequest(), submit()]);
    if(builder==='snow'||builder==='mw') {
      // A confirmation page is a step, not a successful submission.
      expect(Number((await snapshot(key,id)).cohort.flags)&64).toBe(0);
      await expect(form.locator('input[type=text]:visible,input[type=email]:visible')).toHaveCount(0);
      await Promise.all([confirmedRequest(), submit()]);
    }
    await expect.poll(async()=>Number((await snapshot(key,id)).cohort?.flags)&64, {timeout:15000}).toBe(64);
    const final=await snapshot(key,id);
    expect(Number(final.cohort.flags)&15).toBe(15);
    if (process.env.FORMS_QA_PRO === '1') {
      expect(final.cohort.fields).not.toBe('');
      expect(final.cohort.fields).not.toMatch(/PRIVATE|example|email/);
    } else expect(final.cohort.fields).toBe('');
    expect(batches.length).toBeGreaterThan(0);
    for(const payload of batches) {
      expect(payload).not.toMatch(/QA_PRIVATE_SENTINEL|qa%40example|qa@example/);
      const data=JSON.parse(new URLSearchParams(payload).get('fa')!);
      expect(data.forms.every(form=>form.flags<16)).toBe(true);
    }
    for (const payload of interactions) {
      const notes = new URLSearchParams(payload).get('no');
      if (notes) expect(Buffer.from(notes,'base64').toString('utf8')).not.toMatch(/QA_PRIVATE_(SENTINEL|DEFAULT)/);
    }
    await page.reload();
    expect(Number((await snapshot(key,id)).totals.submitted)).toBe(Number(final.totals.submitted));
    await testInfo.attach('builder-errors', {body:JSON.stringify(errors),contentType:'application/json'});
    expect(errors).toEqual([]);
  });
}
