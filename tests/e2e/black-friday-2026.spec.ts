/** Black Friday 2026 strip: where it shows, what it sends, dismissal, copy, keyboard, layout and scope. */
import { test, expect, Page, Browser } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { BASE_URL, ADMIN_USER } from './helpers/env';
import { closeDb, getPool, restoreOption, restoreSlimstatOptions, setOptionValue, setSlimstatOptions, snapshotOption, snapshotSlimstatOptions } from './helpers/setup';
import { getProState } from './helpers/pro-state';

const here = path.dirname(fileURLToPath(import.meta.url));
const SWITCH = 'slimstat_e2e_bf2026';
const PROBE = ['_transient_slimstat_migration_probe', '_transient_timeout_slimstat_migration_probe'];
const ANCHOR = 'slimstat_release_60_since';
const DISMISSED = 'slimstat_release_60_dismissed';
const BF_DISMISSED = 'slimstat_bf2026_dismissed';
const isDismissal = (r: any) => r.method() === 'POST' && /\/admin-ajax\.php$/.test(new URL(r.url()).pathname) && /(?:^|&)action=slimstat_bf2026_dismiss(?:&|$)/.test(r.postData() ?? '');
const DASHBOARD = '/wp-admin/index.php';
const SLIMSTAT = ['slimview1', 'slimview3', 'slimconfig', 'slimheatmap', 'slimpro'].map(slug => `/wp-admin/admin.php?page=${slug}`);
const ELSEWHERE = ['/wp-admin/plugins.php', '/wp-admin/edit.php', '/wp-admin/options-general.php', '/wp-admin/index.php?page=slimstat-e2e-bf26'];
const strip = (page: Page) => page.locator('#slimstat-bf-2026');
const releaseNotice = (page: Page) => page.locator('#slimstat-notice-latest-news');
const shots = process.env.E2E_SHOTS_DIR;
let admin = 0;
let savedMeta: any[] = [];

async function shoot(page: Page, name: string, target = strip(page)) {
  const file = shots ? path.join(shots, `${name}.png`) : test.info().outputPath(`${name}.png`);
  if (shots) fs.mkdirSync(shots, { recursive: true });
  await target.screenshot({ path: file });
}
async function on(value: boolean) { await setOptionValue(SWITCH, value ? 'on' : 'off'); }
async function meta(key: string, value: string | null) {
  await getPool().execute('DELETE FROM wp_usermeta WHERE user_id=? AND meta_key=?', [admin, key]);
  if (value !== null) await getPool().execute('INSERT INTO wp_usermeta (user_id,meta_key,meta_value) VALUES (?,?,?)', [admin, key, value]);
}
async function anchor(): Promise<string | null> {
  const [rows] = await getPool().execute('SELECT meta_value FROM wp_usermeta WHERE user_id=? AND meta_key=?', [admin, ANCHOR]) as any;
  return rows[0]?.meta_value ?? null;
}
/**
 * SlimStat's own background requests, which fire with or without the strip: report boxes load as
 * they scroll into view (so the count follows layout, and the strip pushes boxes down), and the
 * admin-bar and live-analytics widgets poll on timers.
 */
const background = (r: any) => /^action=slimstat_(load_reports|get_adminbar_stats|get_live_analytics_data)&/.test(r.postData() ?? '');
/**
 * Every network request a page load makes, as method + path, sorted; query strings carry nonces
 * and cache busters. blob: and data: URLs are in-page (the charts make blob: ones on some loads).
 */
async function load(page: Page, url: string): Promise<{ requests: string[]; queries: number }> {
  const requests: string[] = [];
  const listener = (r: any) => {
    const u = new URL(r.url());
    const action = /(?:^|&)action=([\w-]+)/.exec(r.postData() ?? '')?.[1];
    if ((u.protocol === 'http:' || u.protocol === 'https:') && !background(r)) requests.push(`${r.method()} ${u.host}${u.pathname}${action ? ` ${action}` : ''}`);
  };
  page.on('request', listener);
  const response = await page.goto(url);
  await page.waitForLoadState('networkidle');
  page.off('request', listener);
  const html = (await response!.text());
  return { requests: requests.sort(), queries: Number(/slimstat-e2e-queries:(\d+)/.exec(html)?.[1] ?? NaN) };
}
async function freshContext(browser: Browser, auth: string) {
  const state = JSON.parse(fs.readFileSync(path.join(here, '.auth', auth), 'utf8'));
  return browser.newContext({ storageState: state, baseURL: BASE_URL, ignoreHTTPSErrors: process.env.PW_IGNORE_HTTPS === '1' });
}

test.describe('Black Friday 2026 strip', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeAll(async ({ browser }) => {
    for (const option of [SWITCH, ...PROBE]) await snapshotOption(option);
    await snapshotSlimstatOptions();
    const [users] = await getPool().execute('SELECT ID FROM wp_users WHERE user_login=?', [ADMIN_USER]) as any;
    admin = users[0].ID;
    [savedMeta] = await getPool().execute('SELECT meta_key,meta_value FROM wp_usermeta WHERE user_id=? AND meta_key IN (?,?,?)', [admin, ANCHOR, DISMISSED, BF_DISMISSED]) as any;
    // A finished migration, and the 6.0 release bar due (one day into its window), so stacking is observable.
    await setOptionValue(PROBE[0], 'clean');
    await setOptionValue(PROBE[1], String(Math.floor(Date.now() / 1000) + 86400));
    await setSlimstatOptions({ notice_latest_news: 'on', display_notifications: 'on' });
    const context = await freshContext(browser, 'admin.json');
    const pro = (await getProState(await context.newPage())).pro_activated;
    await context.close();
    // A failing precondition, not a skip (e2e-quarantine-census): Pro hides the strip by design,
    // which BlackFriday2026Test covers, so this spec needs the Free-only lane CI runs.
    expect(pro, 'run on a Free-only install: SlimStat Pro is active, and Pro hides the strip').toBe(false);
    await on(true);
  });
  test.afterAll(async () => {
    for (const key of [ANCHOR, DISMISSED, BF_DISMISSED]) await meta(key, null);
    for (const row of savedMeta) await meta(row.meta_key, row.meta_value);
    await restoreSlimstatOptions();
    for (const option of [SWITCH, ...PROBE]) await restoreOption(option);
    await closeDb();
  });
  test.beforeEach(async () => {
    await on(true);
    await meta(ANCHOR, String(Math.floor(Date.now() / 1000) - 86400));
    await meta(DISMISSED, null);
    await meta(BF_DISMISSED, null);
  });

  test('shows on SlimStat screens and the Dashboard only, and never stacks with the release notice', async ({ page }) => {
    const since = await anchor();
    for (const url of SLIMSTAT) {
      await page.goto(url);
      await expect(strip(page), url).toBeVisible();
      await expect(releaseNotice(page), `${url}: the strip takes the release bar's slot`).toHaveCount(0);
      await expect(strip(page).getByRole('link', { name: /See Black Friday pricing/ })).toHaveAttribute('href', 'https://wp-slimstat.com/pricing/?utm_source=wp-slimstat&utm_medium=link&utm_campaign=bf-2026&utm_content=banner');
    }
    await page.goto(DASHBOARD);
    await expect(strip(page)).toBeVisible();
    await expect(strip(page)).toHaveClass('notice');
    await expect(releaseNotice(page)).toHaveCount(0);
    await expect(strip(page).getByRole('link', { name: /See Black Friday pricing/ })).toHaveAttribute('href', /utm_content=dashboard$/);
    const h1 = await page.locator('#wpbody-content h1').first().boundingBox();
    expect((await strip(page).boundingBox())!.y, 'core moved the notice below the Dashboard heading').toBeGreaterThan(h1!.y);
    for (const url of ELSEWHERE) { await page.goto(url); await expect(strip(page), url).toHaveCount(0); }
    expect(await anchor(), 'the release bar keeps its own clock').toBe(since);

    await on(false);
    await page.goto(SLIMSTAT[0]);
    await expect(strip(page)).toHaveCount(0);
    await expect(releaseNotice(page), 'off, the release bar is back').toHaveCount(1);
    await page.goto(DASHBOARD);
    await expect(strip(page)).toHaveCount(0);
    await expect(releaseNotice(page)).toHaveCount(1);
  });

  test('adds no request and no query; copy sends nothing and dismiss sends one same-origin request', async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    const external: string[] = [];
    page.on('request', r => { if (/wp-slimstat\.com/.test(r.url())) external.push(r.url()); });
    for (const url of [SLIMSTAT[0], DASHBOARD]) {
      // Each state is measured on its second load: the first load after an option write runs
      // ~17 extra queries (seen with the strip off and on alike), which would swamp a difference of 1.
      await on(false);
      await load(page, url);
      const off = await load(page, url);
      await on(true);
      await load(page, url);
      const shown = await load(page, url);
      await expect(strip(page)).toBeVisible();
      expect(shown.requests, `${url}: same requests with the strip`).toEqual(off.requests);
      expect(shown.queries, `${url}: no added queries`).toBeLessThanOrEqual(off.queries);
      test.info().annotations.push({ type: 'queries', description: `${url}: off ${off.queries}, on ${shown.queries}` });
      const during: any[] = [];
      const listener = (r: any) => { if (!background(r)) during.push(r); };
      page.on('request', listener);
      await strip(page).getByRole('button', { name: 'Copy code' }).click();
      await page.waitForTimeout(300);
      expect(during.map(r => r.url()), 'copy sends nothing').toEqual([]);
      // A double click: the second click lands while the first save is still in flight.
      await strip(page).getByRole('button', { name: 'Dismiss Black Friday offer' }).dblclick();
      await expect(strip(page)).toHaveCount(0);
      await page.waitForTimeout(300);
      page.off('request', listener);
      expect(during.length, 'a double click on dismiss sends exactly one request').toBe(1);
      expect(isDismissal(during[0]), `${during[0].method()} ${during[0].url()} is the dismissal`).toBe(true);
      expect(new URL(during[0].url()).origin, 'to this site').toBe(new URL(page.url()).origin);
      await meta(BF_DISMISSED, null);
    }
    expect(external).toEqual([]);
  });

  test('dismissal moves focus and holds for this admin on both surfaces and in every browser', async ({ page, context, browser }) => {
    await page.goto(DASHBOARD);
    await strip(page).getByRole('button', { name: 'Dismiss Black Friday offer' }).click();
    await expect(strip(page)).toHaveCount(0);
    await expect(page.locator('#wpbody-content h1').first()).toBeFocused();
    const [rows] = await getPool().execute('SELECT meta_value FROM wp_usermeta WHERE user_id=? AND meta_key=?', [admin, BF_DISMISSED]) as any;
    expect(rows.map((row: any) => row.meta_value), 'stored once, on this admin').toEqual(['1']);
    expect((await context.cookies()).filter(c => /bf2026|bf26/.test(c.name)), 'no cookie').toEqual([]);
    await page.reload();
    await expect(strip(page)).toHaveCount(0);
    // The release notice waits only while the strip shows (its 14-day clock never started), so it takes the slot now.
    await expect(releaseNotice(page), 'the release notice gets its slot back, alone').toHaveCount(1);
    await page.goto(SLIMSTAT[0]);
    await expect(strip(page)).toHaveCount(0);
    const other = await freshContext(browser, 'admin.json');
    const second = await other.newPage();
    for (const url of [SLIMSTAT[0], DASHBOARD]) {
      await second.goto(url);
      await expect(second.locator('#wpbody-content h1').first(), url).toBeVisible();
      await expect(strip(second), `${url}: another browser does not see it either`).toHaveCount(0);
    }
    await other.close();
  });

  test('a refused dismissal keeps the strip, says so, and a retry works', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto(SLIMSTAT[0]);
    const forged = await page.request.post('/wp-admin/admin-ajax.php', { form: { action: 'slimstat_bf2026_dismiss', security: 'expired' } });
    expect(forged.status(), 'a bad nonce is refused').toBe(403);
    const ajax = (u: URL) => u.pathname.endsWith('/admin-ajax.php');
    await page.route(ajax, route => isDismissal(route.request()) ? route.fulfill({ status: 500, body: '' }) : route.continue());
    await strip(page).getByRole('button', { name: 'Dismiss Black Friday offer' }).click();
    await expect(strip(page).locator('[role="status"]')).toHaveText('Could not save dismissal. Try again.');
    await expect(strip(page)).toBeVisible();
    await page.unroute(ajax);
    await page.reload();
    await strip(page).getByRole('button', { name: 'Dismiss Black Friday offer' }).click();
    await expect(strip(page)).toHaveCount(0);
    expect(errors).toEqual([]);
    const [rows] = await getPool().execute('SELECT meta_value FROM wp_usermeta WHERE user_id=? AND meta_key=?', [admin, BF_DISMISSED]) as any;
    expect(rows.length, 'only the accepted request stored it').toBe(1);
  });

  test('copy writes the code, or selects it when the clipboard is unavailable or refused', async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await page.goto(SLIMSTAT[0]);
    const copy = strip(page).locator('.slimstat-bf26__copybtn');
    const status = strip(page).locator('[role="status"]');
    await copy.click();
    await expect(copy).toHaveText('Copied');
    await expect(status).toHaveText('Copied');
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe('BFCM2026');
    await shoot(page, 'copied');
    await page.keyboard.press('Tab');
    await expect(copy).toHaveText('Copy code');
    await expect(status).toHaveText('');

    for (const block of ['refused', 'absent']) {
      await page.addInitScript(mode => {
        if (mode === 'refused') navigator.clipboard.writeText = () => Promise.reject(new DOMException('denied', 'NotAllowedError'));
        else Object.defineProperty(Navigator.prototype, 'clipboard', { configurable: true, get: () => undefined });
      }, block);
      await page.reload();
      await copy.click();
      await expect(copy, block).toHaveText('Code selected');
      await expect(status).toHaveText('Code selected');
      expect(await page.evaluate(() => String(document.getSelection()))).toBe('BFCM2026');
    }
  });

  test('keyboard order runs copy, pricing, dismiss, each with a visible focus ring', async ({ page }) => {
    await page.goto(SLIMSTAT[0]);
    const copy = strip(page).locator('.slimstat-bf26__copybtn');
    const cta = strip(page).locator('.slimstat-bf26__cta');
    const dismiss = strip(page).locator('.slimstat-bf26__dismiss');
    await strip(page).locator('.slimstat-bf26__title').click();
    await copy.focus();
    for (const [target, next] of [[copy, cta], [cta, dismiss]] as const) {
      await expect(target).toBeFocused();
      await page.keyboard.press('Tab');
      await expect(next).toBeFocused();
      expect(await next.evaluate(el => getComputedStyle(el).boxShadow), 'focus ring').not.toBe('none');
    }
    await page.keyboard.press('Shift+Tab');
    await page.keyboard.press('Shift+Tab');
    await expect(copy).toBeFocused();
    expect(await copy.evaluate(el => getComputedStyle(el).boxShadow)).not.toBe('none');
    await shoot(page, 'focus-copy');
  });

  test('fits every width, RTL and long translations, forced colors, and is hidden in print', async ({ page }) => {
    const fits = async (label: string) => {
      const problems = await strip(page).evaluate(root => {
        const box = root.getBoundingClientRect();
        const out: string[] = [];
        if (box.right > document.documentElement.clientWidth + 0.5) out.push(`strip right ${box.right} > viewport`);
        if (root.scrollWidth > root.clientWidth + 1) out.push(`strip scrolls ${root.scrollWidth}/${root.clientWidth}`);
        const x = root.querySelector('.slimstat-bf26__dismiss')!.getBoundingClientRect();
        for (const el of root.querySelectorAll('.slimstat-bf26__copy, .slimstat-bf26__stub, .slimstat-bf26__cta')) {
          const r = el.getBoundingClientRect();
          if (r.left < box.left - 0.5 || r.right > box.right + 0.5) out.push(`${el.className} leaves the strip`);
          const range = document.createRange(); range.selectNodeContents(el);
          for (const t of range.getClientRects()) if (t.right > x.left && t.left < x.right && t.bottom > x.top && t.top < x.bottom) out.push(`${el.className} runs under the dismiss button`);
        }
        // The code is dir="ltr" inside a page that may be RTL: its text keeps clear of the stub edges and of the perforation on its row.
        const stub = root.querySelector('.slimstat-bf26__stub')!.getBoundingClientRect();
        const btn = root.querySelector('.slimstat-bf26__copybtn')!.getBoundingClientRect();
        const codeText = document.createRange();
        codeText.selectNodeContents(root.querySelector('.slimstat-bf26__code')!);
        const c = codeText.getBoundingClientRect();
        const sameRow = btn.top < c.bottom && btn.bottom > c.top;
        const clearance = Math.min(c.left - stub.left, stub.right - c.right, sameRow ? Math.max(btn.left - c.right, c.left - btn.right) : Infinity);
        if (clearance < 8) out.push(`code text is ${Math.round(clearance)}px from the stub edge or perforation`);
        return out;
      });
      expect(problems, label).toEqual([]);
    };
    for (const [width, height] of [[1440, 900], [1024, 768], [768, 1024], [390, 844], [320, 640]]) {
      await page.setViewportSize({ width, height });
      for (const [url, name] of [[SLIMSTAT[0], 'slimstat'], [DASHBOARD, 'dashboard']]) {
        await page.goto(url);
        await fits(`${name} @${width}`);
        if ([1440, 768, 390, 320].includes(width)) await shoot(page, `${name}-${width}`);
      }
    }
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(SLIMSTAT[0]);
    for (const selector of ['.slimstat-bf26__copybtn', '.slimstat-bf26__cta', '.slimstat-bf26__dismiss']) {
      expect((await strip(page).locator(selector).boundingBox())!.height, `${selector} touch target`).toBeGreaterThanOrEqual(44);
    }
    for (const width of [390, 320]) {
      await page.setViewportSize({ width, height: 800 });
      await page.goto(SLIMSTAT[0]);
      const labelLines = await strip(page).evaluate(root => {
        root.querySelector('.slimstat-bf26__label')!.firstChild!.textContent = 'Gutscheincode';
        root.querySelector('.slimstat-bf26__copybtn')!.firstChild!.textContent = 'Gutscheincode kopieren';
        const range = document.createRange();
        range.selectNodeContents(root.querySelector('.slimstat-bf26__label')!);
        return range.getClientRects().length;
      });
      expect(labelLines, `a one-word code label stays whole @${width}`).toBe(1);
      await fits(`German stub @${width}`);
    }
    for (const width of [1024, 320]) {
      await page.setViewportSize({ width, height: 800 });
      await page.goto(SLIMSTAT[0]);
      await page.evaluate(() => {
        document.documentElement.dir = 'rtl';
        const root = document.getElementById('slimstat-bf-2026')!;
        for (const el of root.querySelectorAll('.slimstat-bf26__title, .slimstat-bf26__value, .slimstat-bf26__label, .slimstat-bf26__copybtn, .slimstat-bf26__cta')) {
          el.firstChild!.textContent = el.firstChild!.textContent!.repeat(2) + ' Verlängerungszeichenkette';
        }
      });
      await fits(`rtl + long @${width}`);
      const box = (await strip(page).boundingBox())!;
      expect((await strip(page).locator('.slimstat-bf26__dismiss').boundingBox())!.x - box.x, 'dismiss mirrors to the left').toBeLessThan(box.width / 2);
      await expect(strip(page).locator('.slimstat-bf26__code')).toHaveCSS('direction', 'ltr');
      await shoot(page, `rtl-long-${width}`);
    }
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.emulateMedia({ forcedColors: 'active' });
    await page.goto(SLIMSTAT[0]);
    await expect(strip(page).locator('.slimstat-bf26__stub')).toHaveCSS('border-top-style', 'solid');
    await shoot(page, 'forced-colors');
    await page.emulateMedia({ forcedColors: 'none', media: 'print' });
    await expect(strip(page)).toBeHidden();
  });

  test('leaves the rest of the admin, the front end and other roles untouched', async ({ page, browser }) => {
    const PROPS = ['color', 'background-color', 'font-family', 'font-size', 'font-weight', 'line-height', 'margin', 'padding', 'border', 'border-radius', 'display', 'box-shadow', 'outline'];
    const SAMPLES: Record<string, string[]> = {
      [SLIMSTAT[0]]: ['.slimstat-header__cta', '.slimstat-pageintro__title', '.postbox', '#wpadminbar', '#adminmenu a', 'button, .button'],
      [DASHBOARD]: ['#wpbody-content h1', '#dashboard-widgets .postbox', '#dashboard-widgets .hndle', '.notice:not(#slimstat-bf-2026)', '#wpadminbar', 'button, .button'],
    };
    const styles = (url: string) => page.evaluate(([samples, props]) => Object.fromEntries(samples.map(selector => {
      const el = [...document.querySelectorAll(selector)].find(e => !e.closest('#slimstat-bf-2026'));
      return [selector, el ? props.map(p => getComputedStyle(el).getPropertyValue(p)).join('|') : null];
    })), [SAMPLES[url], PROPS]);
    for (const url of [SLIMSTAT[0], DASHBOARD]) {
      await on(false); await page.goto(url);
      const off = await styles(url);
      expect(Object.values(off).filter(Boolean).length, `${url}: the samples exist`).toBeGreaterThanOrEqual(4);
      await on(true); await page.goto(url);
      await expect(strip(page)).toBeVisible();
      expect(await styles(url), `${url}: other admin UI is styled the same with the strip`).toEqual(off);
      expect(await page.evaluate(() => Object.keys(window).filter(k => /bf26|bf-2026|bf2026/i.test(k))), 'no globals').toEqual([]);
    }
    const assets: string[] = [];
    page.on('request', r => { if (/promotions\.(css|js)/.test(r.url())) assets.push(r.url()); });
    const front = await page.goto('/');
    expect(await front!.text()).not.toContain('slimstat-bf');
    expect(assets, 'the front end loads neither promotions file').toEqual([]);
    const author = await freshContext(browser, 'author.json');
    const authorPage = await author.newPage();
    await authorPage.goto(DASHBOARD);
    await expect(strip(authorPage), 'an author never sees the offer').toHaveCount(0);
    await author.close();
  });
});
