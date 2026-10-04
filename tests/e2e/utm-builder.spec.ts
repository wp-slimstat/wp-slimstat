import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';

const target = `${BASE_URL}/wp-admin/admin.php?page=slimview5`;

test.describe('UTM link builder', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto(`${target}#slimstat-utm-builder`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#slimstat-utm-builder')).toHaveAttribute('open', '');
  });

  test('requires source, medium and either campaign name or ID, and clears stale output', async ({ page }) => {
    const output = page.locator('#slimstat-utm-result');
    const extra = page.locator('.slimstat-utm-builder__extra');
    for (const name of ['utm_id', 'utm_term', 'utm_content']) {
      await expect(page.locator(`[name="${name}"]`)).toBeHidden();
    }
    await expect(output).toHaveValue('');
    // Not primary while there is no URL, yet stays clickable to point at the missing field.
    const copy = page.getByRole('button', { name: 'Copy campaign URL' });
    await expect(copy).not.toHaveClass(/button-primary/);
    await expect(page.locator('label[for="slimstat-utm_campaign"]')).toContainText('*');
    await copy.click();
    await expect(page.locator('[name="utm_source"]')).toBeFocused();
    await page.locator('[name="utm_source"]').fill('newsletter');
    await page.locator('[name="utm_medium"]').fill('email');
    await expect(output).toHaveValue('');
    await page.getByRole('button', { name: 'Copy campaign URL' }).click();
    await expect(page.locator('[name="utm_campaign"]')).toBeFocused();
    await extra.locator('summary').focus();
    await page.keyboard.press('Enter');
    await page.locator('[name="utm_id"]').fill('0');
    expect(new URL(await output.inputValue()).searchParams.get('utm_id')).toBe('0');
    await expect(copy).toHaveClass(/button-primary/);
    await extra.locator('summary').click();
    await expect(page.locator('[name="utm_id"]')).toBeHidden();
    expect(new URL(await output.inputValue()).searchParams.get('utm_id')).toBe('0');
    await page.locator('[name="utm_source"]').fill('   ');
    await expect(output).toHaveValue('');
    await page.locator('[name="utm_source"]').fill('newsletter');
    for (const value of ['javascript:alert(1)', 'https://user:password@example.com/', 'example.com', 'https:example.com', 'https://exa mple.com/']) {
      await page.locator('[name="website"]').fill(value);
      await expect(output).toHaveValue('');
    }
    await page.locator('[name="website"]').fill('https://example.com/?long=' + 'x'.repeat(16384));
    await expect(output).toHaveValue('');
    await page.getByRole('button', { name: 'Reset', exact: true }).click();
    await expect(page.locator('[name="utm_id"]')).toHaveValue('');
    await expect(output).toHaveValue('');
  });

  test('preserves query bytes and fragments, replaces duplicate tags and copies exact encoded values', async ({ page }) => {
    await page.locator('.slimstat-utm-builder__extra > summary').click();
    await page.locator('[name="website"]').fill('https://example.com/path?keep=a%20b&repeat=1&repeat=2&utm_source=old&utm_source=again&utm_content=stale&utm_medium%5B%5D=x&UTM_CAMPAIGN=old#pricing');
    for (const [name, value] of Object.entries({ utm_source: 'A&B', utm_medium: 'Email', utm_campaign: 'Spring + 20%', utm_id: '0', utm_term: 'café 東京', utm_content: '&amp; \\ header' })) {
      await page.locator(`[name="${name}"]`).fill(value);
    }
    const generated = await page.locator('#slimstat-utm-result').inputValue();
    const url = new URL(generated);
    expect(url.search).toContain('keep=a%20b&repeat=1&repeat=2');
    expect(url.hash).toBe('#pricing');
    expect(url.searchParams.getAll('utm_source')).toEqual(['A&B']);
    expect(url.searchParams.has('utm_medium[]')).toBe(false);
    expect(url.searchParams.has('UTM_CAMPAIGN')).toBe(false);
    expect(url.searchParams.get('utm_term')).toBe('café 東京');
    expect(url.searchParams.get('utm_campaign')).toBe('Spring + 20%');
    expect(url.searchParams.get('utm_content')).toBe('&amp; \\ header');
    // Verify both successful clipboard writes and the denied/unavailable fallback.
    await page.evaluate(() => Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async (text: string) => { (window as any).copiedCampaign = text; } } }));
    await page.getByRole('button', { name: 'Copy campaign URL' }).click();
    await expect(page.locator('[data-utm-status]')).toHaveText('Campaign URL copied.');
    expect(await page.evaluate(() => (window as any).copiedCampaign)).toBe(generated);
    await page.evaluate(() => {
      Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async () => { throw new Error('Denied'); } } });
      document.execCommand = () => false;
    });
    await page.getByRole('button', { name: 'Copy campaign URL' }).click();
    await expect(page.locator('[data-utm-status]')).toHaveText('Link selected. Use your browser’s Copy command.');
    expect(await page.locator('#slimstat-utm-result').evaluate((el: HTMLTextAreaElement) => el.selectionEnd - el.selectionStart)).toBe(generated.length);
    await page.locator('[name="utm_content"]').evaluate((el: HTMLInputElement) => {
      el.value = 'x'.repeat(192);
      el.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await expect(page.locator('#slimstat-utm-result')).toHaveValue('');
    await page.locator('[name="utm_content"]').fill('<b>changed</b>');
    await expect(page.locator('#slimstat-utm-result')).toHaveValue('');
    await page.locator('.slimstat-utm-builder__extra > summary').click();
    await page.getByRole('button', { name: 'Copy campaign URL' }).click();
    await expect(page.locator('[name="utm_content"]')).toBeFocused();
    await expect(page.locator('.slimstat-utm-builder__extra')).toHaveAttribute('open', '');
  });

  test('report shortcut preserves filters and draft, with keyboard and mobile RTL access', async ({ page }) => {
    await page.goto(`${target}&fs[utm_source]=equals+newsletter`, { waitUntil: 'domcontentloaded' });
    await page.locator('#slim_p3_04 .slimstat-utm-builder-link').click();
    await expect(page.locator('[name="website"]')).toBeFocused();
    expect(page.url()).toContain('newsletter');
    await page.locator('[name="utm_source"]').fill('draft');
    await page.evaluate(() => new Promise<void>(resolve => (window as any).SlimStatAdmin.refresh_report('slim_p3_04')().always(resolve)));
    await expect(page.locator('[name="utm_source"]')).toHaveValue('draft');
    await page.locator('#slimstat-utm-builder > summary').focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('#slimstat-utm-builder')).not.toHaveAttribute('open');
    await page.keyboard.press('Enter');
    await expect(page.locator('[name="utm_source"]')).toHaveValue('draft');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.evaluate(() => document.documentElement.dir = 'rtl');
    const geometry = await page.locator('#slimstat-utm-builder').evaluate(el => ({ client: el.clientWidth, scroll: el.scrollWidth }));
    expect(geometry.scroll).toBeLessThanOrEqual(geometry.client + 1);
    await expect(page.locator('#slimstat-utm-result')).toHaveAttribute('dir', 'ltr');
  });
});
