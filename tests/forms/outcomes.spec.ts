import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { fixtureScript, snapshot } from './helpers';
const fixture = JSON.parse(readFileSync(process.env.FORMS_QA_FIXTURE!, 'utf8'));
for (const [mode, bit] of [['spam',16], ['failed',32]] as const) {
  test('CF7 '+mode+' never becomes a successful submission', async ({page})=>{
    await fixtureScript('tests/forms/outcome-mode.php', [mode]);
    try {
      await page.goto('/?page_id='+fixture.page);
      await page.waitForFunction(()=>!!(window as any).SlimStatParams?.id);
      const id=parseInt(await page.evaluate(()=>(window as any).SlimStatParams.id),10);
      await page.locator('[name="your-name"]').fill('Outcome QA');
      await page.locator('[name="your-email"]').fill('qa@example.com');
      await page.locator('[type="submit"]').click();
      await expect(page.locator('.wpcf7-form')).toHaveAttribute('data-status',mode);
      await expect.poll(async()=>Number((await snapshot('cf7:'+fixture.form,id)).cohort?.flags)&bit).toBe(bit);
      expect(Number((await snapshot('cf7:'+fixture.form,id)).cohort.flags)&64).toBe(0);
    } finally {
      await fixtureScript('tests/forms/outcome-mode.php', ['']);
    }
  });
}
