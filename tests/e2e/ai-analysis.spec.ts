import { test, expect } from '@playwright/test';
import { BASE_URL } from './helpers/env';
import { seedPageviews, getPool, clearStatsCaches, closeDb, snapshotOption, restoreOption, snapshotSlimstatOptions, restoreSlimstatOptions } from './helpers/setup';
import { getProState, setProLicense } from './helpers/pro-state';

const prefix = '/e2e-native-ai-';
const report = '#slim_p1_03';

test.beforeAll(async () => {
  await getPool().execute('DELETE FROM wp_slim_stats WHERE resource LIKE ?', [`${prefix}%`]);
  await seedPageviews({ count: 12, resourcePrefix: prefix });
  await clearStatsCaches();
});
test.afterAll(async () => {
  await getPool().execute('DELETE FROM wp_slim_stats WHERE resource LIKE ?', [`${prefix}%`]);
  await clearStatsCaches();
  await closeDb();
});
test.beforeEach(async ({ page }) => {
  await page.addInitScript(() => Object.defineProperty(navigator, 'clipboard', {
    configurable: true, value: { writeText: async (text: string) => { (window as any).__copied = text; } },
  }));
  await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`);
  await page.locator(report).scrollIntoViewIfNeeded();
  await expect(page.locator(`${report} .inside`)).not.toHaveText(/^\s*$/);
});

test('copy success has an accessible, non-repeating Free invitation @compat', async ({ page }, info) => {
  await page.evaluate(() => { (window as any).SlimStatAdminParams.ai.native = false; });
  const button = page.locator(`${report} .slimstat-ai-prompt`);
  await button.click();
  const toast = page.locator('#slimstat-ai-toast');
  await expect(toast.getByRole('status')).toContainText('AI Prompt Copied!');
  await expect(toast.locator('.ss-ai-toast-lead')).toHaveText('Paste it into ChatGPT, Claude, Gemini, Grok, or your favorite AI to analyze your report.');
  await expect(toast).toContainText('Want AI insights without leaving WordPress?');
  await page.screenshot({ path: info.outputPath('free-ai-toast.png') });
  await toast.getByRole('button', { name: 'Close' }).click();
  await expect(toast).toHaveCount(0);
  await expect(button).toBeFocused();
  await expect(button).toBeEnabled();
  // The second copy is the compact message: no invitation, the same action row.
  await button.click();
  await expect(toast.getByRole('status')).toContainText('AI Prompt Copied!');
  await expect(toast).not.toContainText('Want AI insights');
  await expect(toast.getByRole('link', { name: 'Explore Pro AI' })).toBeVisible();
});

for (const native of [false, true]) {
  test(`both Prompt Copied messages lead with Explore Pro AI, then Close (${native ? 'Pro' : 'Free'}) @compat`, async ({ page }, info) => {
    // Attribution on the current URL must not reach the internal link.
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2&utm_source=elsewhere&utm_campaign=leak`);
    await page.locator(report).scrollIntoViewIfNeeded();
    await expect(page.locator(`${report} .inside`)).not.toHaveText(/^\s*$/);
    await page.evaluate(n => { (window as any).SlimStatAdminParams.ai.native = n; }, native);
    const button = page.locator(`${report} .slimstat-ai-prompt`);
    const toast = page.locator('#slimstat-ai-toast');
    for (const variant of native ? ['compact'] : ['expanded', 'compact']) {
      await button.click();
      await expect(toast.locator('.ss-ai-toast-offer')).toHaveCount(variant === 'expanded' ? 1 : 0);
      const explore = toast.locator('.ss-ai-toast-actions > a.button-primary');
      await expect(explore).toHaveText('Explore Pro AI →');
      const href = new URL((await explore.getAttribute('href'))!, BASE_URL);
      expect(href.pathname + href.search).toBe('/wp-admin/admin.php?page=slimai');
      // Bolder than the paragraph that invites to Pro, and the darkest text in the message.
      expect(Number(await toast.locator('.ss-ai-toast-lead').evaluate(n => getComputedStyle(n).fontWeight))).toBeGreaterThanOrEqual(600);
      for (const [width, dir] of [[1440, 'ltr'], [390, 'ltr'], [390, 'rtl']] as const) {
        await page.setViewportSize({ width, height: 900 });
        const row = await toast.evaluate((node, d) => {
          document.documentElement.dir = d;
          const [a, b] = Array.from(node.querySelectorAll('.ss-ai-toast-actions > *')).map(n => n.getBoundingClientRect());
          const box = node.getBoundingClientRect();
          return {
            order: Array.from(node.querySelectorAll('.ss-ai-toast-actions > *'), n => n.textContent),
            sameRow: Math.abs(a.top - b.top) < 1 && Math.abs(a.height - b.height) < 1,
            closeAfter: d === 'rtl' ? b.right <= a.left - 4 : b.left >= a.right + 4,
            inside: box.left >= 0 && box.right <= window.innerWidth && node.scrollWidth <= node.clientWidth,
          };
        }, dir);
        expect(row, `${variant} ${width} ${dir}`).toEqual({ order: ['Explore Pro AI →', 'Close'], sameRow: true, closeAfter: true, inside: true });
      }
      await page.evaluate(() => { document.documentElement.dir = 'ltr'; });
      await page.setViewportSize({ width: 1440, height: 900 });
      await toast.screenshot({ path: info.outputPath(`toast-${native ? 'pro' : 'free'}-${variant}.png`) });
      // Keyboard: Tab reaches Explore before Close, and Close hands focus back.
      await explore.focus();
      await page.keyboard.press('Tab');
      await expect(toast.getByRole('button', { name: 'Close' })).toBeFocused();
      await page.keyboard.press('Enter');
      await expect(toast).toHaveCount(0);
      await expect(button).toBeFocused();
      await expect(button).toBeEnabled({ timeout: 5000 });
    }
  });
}

test('Copy Prompt leads to the AI Analysis page, then to Pro features with its attribution @compat', async ({ page, context }) => {
  // The non-native journey: Free, or a Pro awaiting activation where Pro is installed. wp-slimstat.com is stubbed.
  const panelCta = 'https://wp-slimstat.com/pro-features/?utm_source=wp-slimstat&utm_medium=link&utm_campaign=ai-analysis&utm_content=slimai-pro-panel';
  const kept = ['slimstat_pro_license_verdict', 'slimstat_e2e_license_state'];
  const pro = (await getProState(page)).pro_installed;
  if (pro) {
    await snapshotSlimstatOptions();
    for (const name of kept) await snapshotOption(name);
    expect(await setProLicense(page, 'none')).toBe('none');
  }
  try {
    const external: string[] = [];
    await context.route('https://wp-slimstat.com/**', route => { external.push(route.request().url()); return route.fulfill({ status: 200, contentType: 'text/html', body: '<title>stub</title>' }); });
    await page.goto(`${BASE_URL}/wp-admin/admin.php?page=slimview2`);
    // Before the report loads, Copy Prompt says "No data to copy" and shows no toast.
    await expect(page.locator(`${report} .inside`)).not.toHaveText(/^\s*$/);
    await page.locator(`${report} .slimstat-ai-prompt`).click();
    await page.locator('#slimstat-ai-toast').getByRole('link', { name: 'Explore Pro AI' }).click();
    await expect(page).toHaveURL(/\/wp-admin\/admin\.php\?page=slimai$/);
    await expect(page.getByRole('heading', { name: 'Get insights right here in WordPress' })).toBeVisible();
    const [popup] = await Promise.all([page.waitForEvent('popup'), page.locator('.ss-ai-pro-cta').click()]);
    await popup.waitForLoadState();
    expect(popup.url()).toBe(panelCta);
    expect(external).toEqual([panelCta]);
  } finally {
    if (pro) {
      expect(await setProLicense(page, 'valid')).toBe('valid');
      for (const name of kept) await restoreOption(name);
      await restoreSlimstatOptions();
    }
  }
});

test('a long translation wraps the action row instead of clipping it @compat', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.evaluate(() => (window as any).wp.i18n.setLocaleData({
    'Explore Pro AI →': ['Entdecken Sie die KI-Analyse von SlimStat Pro →'],
    'Close': ['Schließen und später erneut anzeigen'],
  }, 'wp-slimstat'));
  await page.locator(`${report} .slimstat-ai-prompt`).click();
  const toast = page.locator('#slimstat-ai-toast');
  const fit = await toast.evaluate(node => Array.from(node.querySelectorAll('.ss-ai-toast-actions > *')).every(n => {
    const r = n.getBoundingClientRect(); const box = node.getBoundingClientRect();
    return r.left >= box.left && r.right <= box.right && n.scrollWidth <= n.clientWidth + 1;
  }));
  expect(fit).toBe(true);
});

test('clipboard failure never announces success @compat', async ({ page }) => {
  await page.evaluate(() => {
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async () => { throw new Error('denied'); } } });
    document.execCommand = () => false;
  });
  await page.locator(`${report} .slimstat-ai-prompt`).click();
  await expect(page.locator(`${report} .slimstat-ai-prompt`)).toContainText('Copy failed');
  await expect(page.locator('#slimstat-ai-toast')).toHaveCount(0);
});

test('disabled AI actions stay aligned with long text, RTL and a narrow viewport @compat', async ({ page }, info) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.evaluate(() => Object.assign((window as any).SlimStatAdminParams.ai, { native: true, route: '/slimstat/v1/ai', settings: '/wp-admin/admin.php?page=slimai&tab=settings' }));
  await page.route(url => decodeURIComponent(url.href).includes('/slimstat/v1/ai/status'), route => route.fulfill({ json: { enabled: false, saved: null } }));
  const trigger = page.locator(`${report} .slimstat-ai-analyze`);
  await trigger.click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toContainText('AI analysis is disabled');
  const actions = dialog.locator('.ss-ai-actions');
  await expect(actions.getByRole('link')).toHaveCount(1);
  await expect(actions.getByRole('button', { name: 'Copy Prompt Instead' })).toBeVisible();
  for (const dir of ['ltr', 'rtl']) {
    await dialog.evaluate((node, direction) => {
      node.setAttribute('dir', direction);
      node.querySelector('.ss-ai-actions a')!.textContent = 'AI Analysis Settings with a substantially longer translated explanation';
    }, dir);
    const bounds = await actions.evaluate(node => {
      const rect = node.getBoundingClientRect();
      return [...node.children].every(child => { const box = child.getBoundingClientRect(); return box.left >= rect.left - 1 && box.right <= rect.right + 1; });
    });
    expect(bounds).toBe(true);
    await page.screenshot({ path: info.outputPath(`disabled-ai-${dir}.png`) });
  }
  await dialog.getByRole('button', { name: 'Close', exact: true }).click();
  await expect(trigger).toBeFocused();
});

test('Free explanation supports keyboard close and restores focus @compat', async ({ page }) => {
  await page.evaluate(() => { (window as any).SlimStatAdminParams.ai.native = false; });
  const button = page.locator(`${report} .slimstat-ai-analyze`);
  await button.click();
  await expect(page.getByRole('dialog')).toContainText('one-click insights');
  await expect(page.getByRole('dialog').getByRole('link', { name: 'Explore Pro AI' })).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await expect(button).toBeFocused();
});

test('copy fallback survives replacement of its original report actions @compat', async ({ page }) => {
  await page.evaluate(() => {
    Object.assign((window as any).SlimStatAdminParams.ai, { native: true, route: '/slimstat/v1/ai' });
    document.addEventListener('click', event => {
      if ((event.target as Element).closest('.slimstat-ai-prompt')) (window as any).__fallbackConnected = true;
    });
  });
  await page.route(url => decodeURIComponent(url.href).includes('/slimstat/v1/ai/status'), route => route.fulfill({ json: { enabled: false, saved: null } }));
  for (const removeTrigger of [false, true]) {
    const trigger = page.locator(`${report} .slimstat-ai-analyze`);
    await trigger.click();
    await expect(page.getByRole('dialog')).toContainText('AI analysis is disabled');
    await expect(page.locator(`${report} .slimstat-ai-prompt`)).toBeEnabled();
    await trigger.evaluate((node, remove) => {
      (window as any).__fallbackConnected = false;
      const parent = node.parentElement!;
      parent.replaceWith(parent.cloneNode(true));
      if (remove) node.remove();
    }, removeTrigger);
    await page.getByRole('dialog').getByRole('button', { name: 'Copy Prompt Instead' }).click();
    await expect.poll(() => page.evaluate(() => (window as any).__fallbackConnected)).toBe(true);
  }
});

test('native consent, loading, safe results, copy and failed regeneration @compat', async ({ page }, info) => {
  let generated = 0;
  let release: (() => void) | undefined;
  const pending = new Promise<void>(resolve => { release = resolve; });
  await page.evaluate(() => Object.assign((window as any).SlimStatAdminParams.ai, { native: true, route: '/slimstat/v1/ai' }));
  await page.route(url => decodeURIComponent(url.href).includes('/slimstat/v1/ai/'), async route => {
    const url = decodeURIComponent(route.request().url());
    if (url.includes('/status')) return route.fulfill({ json: { enabled: true, saved: null, matches: false } });
    if (url.includes('/connection')) return route.fulfill({ json: { provider: 'test-provider', model: 'test-model', selection: 'opaque', consented: generated > 0 } });
    generated++;
    if (generated > 1) return route.fulfill({ status: 503, json: { code: 'failed', message: 'Provider unavailable. Your last saved result is unchanged.' } });
    expect(route.request().postDataJSON().consent).toBe(true);
    await pending;
    return route.fulfill({ json: { saved: true, analysis: {
      context: { title: 'At a Glance', from: '2026-01-01', to: '2026-01-31', timezone: 'Europe/Berlin', filters: ['Browser: selected value withheld'] },
      generated: 1793700000, provider: 'test-provider', model: 'test-model',
      content: '## Report Summary\n12 synthetic pageviews.\n## Key Insights\n- Small sample.\n<img src=x onerror="window.__unsafe=true">\n[Unsafe](javascript:alert(1))',
    } } });
  });
  await page.locator(`${report} .slimstat-ai-analyze`).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toContainText('Selected aggregate analytics will be sent');
  expect(generated).toBe(0);
  const run = dialog.getByRole('button', { name: 'Analyze Current Report' });
  await expect(run).toBeDisabled();
  await dialog.getByRole('checkbox').check();
  await run.click();
  await expect(dialog).toContainText('Analyzing your report…');
  await expect(run).toBeDisabled();
  expect(generated).toBe(1);
  release!();
  await expect(dialog).toContainText('12 synthetic pageviews');
  await expect(dialog.locator('.ss-ai-result img, .ss-ai-result script, .ss-ai-result a')).toHaveCount(0);
  expect(await page.evaluate(() => (window as any).__unsafe)).toBeUndefined();
  await dialog.getByRole('button', { name: 'Copy Result' }).click();
  expect(await page.evaluate(() => (window as any).__copied)).toContain('12 synthetic pageviews');
  await page.screenshot({ path: info.outputPath('native-ai-result.png') });
  await dialog.getByRole('button', { name: 'Regenerate Analysis' }).click();
  await expect(dialog.getByRole('alert')).toContainText('Provider unavailable');
  await expect(dialog).toContainText('12 synthetic pageviews');
  expect(generated).toBe(2);
});

test('failures explain the cause, Retry recovers once and reopening keeps the result @compat', async ({ page }, info) => {
  const fail = (code: string, message: string, data: object) => ({ status: 503, json: { code: `slimstat_ai_${code}`, message, data: { status: 503, ...data } } });
  const analysis = (content: string, generated: number) => ({ context: { title: 'At a Glance', from: '2026-10-01', to: '2026-10-31', timezone: 'UTC', filters: [] }, generated, provider: 'test-provider', model: 'test-model', content });
  let saved: object | null = null;
  let release: () => void;
  const pending = new Promise<void>(resolve => { release = resolve; });
  const answers: (() => Promise<object | null>)[] = [
    async () => fail('upstream', 'The AI provider had a temporary problem. Retry in a moment.', { retryable: true, diagnostic: 'HTTP 502 · ServerException' }),
    async () => fail('rate', 'The AI provider is limiting requests right now. Wait a moment, then retry.', { retryable: true, retry_after: 2 }),
    async () => { await pending; saved = analysis('Recovered after one retry.', 1793700000); return { json: { saved: true, analysis: saved } }; },
    // The site finishes, but the browser loses the response: the saved result is shown instead of paying again.
    async () => { saved = analysis('Finished while the browser was offline.', 1793700100); return null; },
    async () => fail('credentials', 'The AI provider denied access. An administrator can check the provider connection and its model permissions.', { retryable: false, diagnostic: 'HTTP 401 · ClientException' }),
  ];
  let calls = 0;
  await page.evaluate(() => Object.assign((window as any).SlimStatAdminParams.ai, { native: true, route: '/slimstat/v1/ai', connectors: '/wp-admin/options-connectors.php' }));
  await page.route(url => decodeURIComponent(url.href).includes('/slimstat/v1/ai/'), async route => {
    const url = decodeURIComponent(route.request().url());
    if (url.includes('/status')) return route.fulfill({ json: { enabled: true, saved, matches: !!saved } });
    if (url.includes('/connection')) return route.fulfill({ json: { provider: 'test-provider', model: 'test-model', selection: 'opaque', consented: true } });
    const answer = await answers[calls++]();
    return answer ? route.fulfill(answer) : route.abort('internetdisconnected');
  });
  const trigger = page.locator(`${report} .slimstat-ai-analyze`);
  const dialog = page.getByRole('dialog');
  const alert = dialog.getByRole('alert');
  const retry = alert.getByRole('button', { name: /^Retry/ });
  await trigger.click();
  await expect(alert).toContainText('temporary problem');
  await expect(alert).toContainText('Provider error: HTTP 502 · ServerException');
  await expect(alert).toContainText('A new attempt may incur another provider charge.');
  await page.screenshot({ path: info.outputPath('ai-retry.png') });
  await retry.click();
  await expect(alert).toContainText('limiting requests');
  await expect(retry).toHaveText('Retry in 2s');
  await expect(retry).toBeDisabled();
  await expect(retry).toHaveText('Retry', { timeout: 5000 });
  await retry.click({ clickCount: 3 });
  await expect(dialog).toContainText('Analyzing your report');
  await trigger.evaluate(node => { (node as HTMLElement).click(); (node as HTMLElement).click(); });
  await expect(page.locator('#slimstat-ai-toast')).toContainText('Analysis in progress');
  release!();
  await expect(dialog).toContainText('Recovered after one retry.');
  expect(calls, 'rapid clicks send one request').toBe(3);
  await dialog.getByRole('button', { name: 'Regenerate Analysis' }).click();
  await expect(dialog).toContainText('Finished while the browser was offline.');
  await expect(alert).toHaveCount(0);
  expect(calls).toBe(4);
  await dialog.getByRole('button', { name: 'Close', exact: true }).click();
  await trigger.click();
  await expect(dialog).toContainText('Finished while the browser was offline.');
  await expect(dialog.getByRole('button', { name: 'Regenerate Analysis' })).toBeVisible();
  expect(calls, 'reopening shows the saved result without a new request').toBe(4);
  await dialog.getByRole('button', { name: 'Regenerate Analysis' }).click();
  await expect(alert).toContainText('denied access');
  await expect(alert.getByRole('link', { name: 'AI Connections →' })).toHaveAttribute('href', '/wp-admin/options-connectors.php');
  await expect(retry).toHaveCount(0);
  await expect(dialog.getByRole('button', { name: 'Analyze Current Report' })).toBeDisabled();
  await expect(dialog).toContainText('Finished while the browser was offline.');
  await page.screenshot({ path: info.outputPath('ai-credentials.png') });
  expect(calls).toBe(5);
});

test('cached mismatch is free to view and no-provider state offers a fallback @compat', async ({ page }) => {
  const paths: string[] = [];
  await page.evaluate(() => Object.assign((window as any).SlimStatAdminParams.ai, { native: true, route: '/slimstat/v1/ai', connectors: '/wp-admin/options-connectors.php' }));
  await page.route(url => decodeURIComponent(url.href).includes('/slimstat/v1/ai/'), async route => {
    const url = decodeURIComponent(route.request().url());
    paths.push(url);
    if (url.includes('/connection')) return route.fulfill({ json: { provider: null } });
    return route.fulfill({ json: { enabled: true, matches: false, saved: {
      context: { title: 'At a Glance', from: '2025-01-01', to: '2025-01-31', timezone: 'UTC', filters: [] },
      generated: 1738400000, provider: 'old-provider', model: 'old-model', content: 'An older saved result.',
    } } });
  });
  await page.locator(`${report} .slimstat-ai-analyze`).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toContainText('belongs to another date range');
  await expect(dialog).toContainText('2025-01-01');
  expect(paths).toHaveLength(1);
  await dialog.getByRole('button', { name: 'Analyze Current Report' }).click();
  await expect(dialog).toContainText('Connect AI to unlock instant insights');
  await expect(dialog.getByRole('link', { name: 'Set Up AI' })).toBeVisible();
  await expect(dialog.getByRole('button', { name: 'Copy Prompt Instead' })).toBeVisible();
  expect(paths.some(path => path.includes('/analyze'))).toBe(false);
});

test('a changed report selection discards a slower response @compat', async ({ page }) => {
  let release: () => void;
  const pending = new Promise<void>(resolve => { release = resolve; });
  let calls = 0;
  await page.evaluate(() => Object.assign((window as any).SlimStatAdminParams.ai, { native: true, route: '/slimstat/v1/ai' }));
  await page.route(url => decodeURIComponent(url.href).includes('/slimstat/v1/ai/'), async route => {
    const url = decodeURIComponent(route.request().url());
    if (url.includes('/status')) return route.fulfill({ json: { enabled: true, saved: null } });
    if (url.includes('/connection')) return route.fulfill({ json: { provider: 'test', model: 'test', selection: 'opaque', consented: true } });
    calls++;
    await pending;
    return route.fulfill({ json: { saved: true, analysis: { content: 'Obsolete result must not render' } } });
  });
  await page.locator(`${report} .slimstat-ai-analyze`).click();
  await expect(page.getByRole('dialog')).toContainText('Analyzing your report');
  await page.evaluate(() => { (document.querySelector('.slimstat-date-range-input') as HTMLElement).dataset.start = '2025-01-01'; });
  release!();
  await expect(page.getByRole('dialog').getByRole('alert')).toContainText('report selection changed');
  await expect(page.locator('.ss-ai-result')).toHaveCount(0);
  expect(calls).toBe(1);
});

test('closing during analysis never retries or renders the late response @compat', async ({ page }) => {
  let release: () => void;
  const pending = new Promise<void>(resolve => { release = resolve; });
  let calls = 0;
  await page.evaluate(() => Object.assign((window as any).SlimStatAdminParams.ai, { native: true, route: '/slimstat/v1/ai' }));
  await page.route(url => decodeURIComponent(url.href).includes('/slimstat/v1/ai/'), async route => {
    const url = decodeURIComponent(route.request().url());
    if (url.includes('/status')) return route.fulfill({ json: { enabled: true, saved: null } });
    if (url.includes('/connection')) return route.fulfill({ json: { provider: 'test', model: 'test', selection: 'opaque', consented: true } });
    calls++;
    await pending;
    return route.fulfill({ json: { saved: true, analysis: { content: 'Late response' } } });
  });
  await page.locator(`${report} .slimstat-ai-analyze`).click();
  await expect(page.getByRole('dialog')).toContainText('Analyzing your report');
  await page.getByRole('dialog').getByRole('button', { name: 'Close', exact: true }).click();
  release!();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await expect(page.locator('.ss-ai-result')).toHaveCount(0);
  expect(calls).toBe(1);
});

test('saved analysis stays accessible on mobile and clipboard fallback works @compat', async ({ page }, info) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.evaluate(() => {
    Object.assign((window as any).SlimStatAdminParams.ai, { native: true, route: '/slimstat/v1/ai' });
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined });
    document.execCommand = command => { (window as any).__fallbackCopy = command; return true; };
  });
  await page.route(url => decodeURIComponent(url.href).includes('/slimstat/v1/ai/'), route => route.fulfill({ json: {
    enabled: true, matches: true, saved: {
      context: { title: 'At a Glance', from: '2026-10-01', to: '2026-10-31', timezone: 'Europe/Berlin', filters: [] },
      generated: 1793700000, provider: 'Test provider', model: 'Text model',
      content: '## Report Summary\nThis sample contains 12 pageviews.\n## Key Insights\n- The sample is small.\n## Recommended Actions\n- Review a longer date range.\n## Data Limitations\nThese figures do not establish a trend.',
    },
  } }));
  await page.locator(`${report} .slimstat-ai-analyze`).click();
  const dialog = page.getByRole('dialog');
  await expect(dialog).toContainText('Report Summary');
  expect(await dialog.evaluate(node => node.scrollWidth <= node.clientWidth)).toBe(true);
  await dialog.getByRole('button', { name: 'Copy Result' }).click();
  expect(await page.evaluate(() => (window as any).__fallbackCopy)).toBe('copy');
  await expect(page.locator('#slimstat-ai-toast')).toContainText('Analysis copied');
  await page.screenshot({ path: info.outputPath('native-ai-mobile.png') });
  await page.setViewportSize({ width: 1440, height: 1100 });
  await page.locator('#slimstat-ai-toast').getByRole('button', { name: 'Close' }).click();
  await page.screenshot({ path: info.outputPath('native-ai-desktop.png') });
});

test('saved result renders safe emphasis, local labels and a plain copy @compat', async ({ page }) => {
  await page.evaluate(() => Object.assign((window as any).SlimStatAdminParams.ai, { native: true, route: '/slimstat/v1/ai' }));
  await page.route(url => decodeURIComponent(url.href).includes('/slimstat/v1/ai/'), route => route.fulfill({ json: {
    enabled: true, matches: true, saved: {
      context: { title: 'Top Outbound Links', from: '2026-10-01', to: '2026-10-31', timezone: 'Europe/Berlin', filters: [] },
      generated: 1793700000, provider: 'Test provider', model: 'Text model',
      labels: { 'Row 1': 'Chef Knife <b>8in</b>', 'Row 12': '<img src=x onerror="window.__unsafe=1">Dutch Oven' },
      content: '## **Key Insights**\n1. **Row 1 leads** with 9 hits; Row 12 has *4 hits*.\n2. Row 2 has no saved name, 2 * 3 stays literal and **unclosed stays.\n---\n- ***Both*** <script>window.__unsafe=2</script>\n\n## Next steps\nAct.',
    },
  } }));
  await page.locator(`${report} .slimstat-ai-analyze`).click();
  const result = page.getByRole('dialog').locator('.ss-ai-result');
  await expect(result.locator('h3 strong')).toHaveText('Key Insights');
  await expect(result.locator('ol > li')).toHaveCount(2);
  await expect(result.locator('ol > li strong').first()).toHaveText('Chef Knife <b>8in</b> leads');
  await expect(result.locator('ol > li em')).toHaveText('4 hits');
  await expect(result.locator('ol > li').first()).toContainText('<img src=x onerror="window.__unsafe=1">Dutch Oven has');
  await expect(result.locator('ol > li').nth(1)).toHaveText('Row 2 has no saved name, 2 * 3 stays literal and **unclosed stays.');
  await expect(result.locator('ul strong > em')).toHaveText('Both');
  await expect(result.locator('img, script, b, a')).toHaveCount(0);
  expect(await page.evaluate(() => (window as any).__unsafe)).toBeUndefined();
  await page.getByRole('dialog').getByRole('button', { name: 'Copy Result' }).click();
  const copied = await page.evaluate(() => (window as any).__copied);
  expect(copied).toContain('Key Insights\n1. Chef Knife <b>8in</b> leads with 9 hits;');
  expect(copied).toContain('Both <script>');
  // A blank line before a later heading survives the copy.
  expect(copied).toContain('</script>\n\nNext steps\nAct.');
  expect(copied).not.toMatch(/\*\*Row|##|\*4 hits\*/);
});
