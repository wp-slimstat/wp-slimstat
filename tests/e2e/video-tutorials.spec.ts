/**
 * SlimStat > Videos: the opt-in, the category rows, the watch links, the failure states and the
 * new-video marker in the admin sidebar.
 *
 * wp-slimstat.com is never contacted. Every request to it is answered here (the catalog from
 * docs/video-tutorials/videos.example.json, thumbnails with a 1x1 PNG, watch pages with a stub)
 * and recorded, so the spec can assert what the browser asked for, when, and with which headers.
 * The feature keeps all of its state in browser storage, and each test starts with a fresh
 * context, so no options or rows are touched.
 */
import { test, expect, BrowserContext, Page, Request } from '@playwright/test';
import fs from 'fs';
import path from 'path';
import { PLUGIN_DIR } from './helpers/env';

const ORIGIN = 'https://wp-slimstat.com';
const CATALOG = `${ORIGIN}/videos/videos.json`;
const VIDEOS_PAGE = '/wp-admin/admin.php?page=slimvideos';
const STATE_PREFIX = 'slimstat_videos_state_';
const example = JSON.parse(fs.readFileSync(path.join(PLUGIN_DIR, 'docs/video-tutorials/videos.example.json'), 'utf8'));
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');
const DAY = 86_400_000;

type Remote = { catalog: () => unknown; fail?: boolean; requests: Request[]; foreign: string[] };

/** Answers every request to wp-slimstat.com and records it; anything else off-site is refused and noted. */
async function remote(context: BrowserContext, catalog: () => unknown = () => example): Promise<Remote> {
  const state: Remote = { catalog, requests: [], foreign: [] };
  await context.route(/^https:\/\/wp-slimstat\.com\//, async (route) => {
    const request = route.request();
    state.requests.push(request);
    const url = request.url();
    if (url === CATALOG) {
      if (state.fail) return route.abort('internetdisconnected');
      return route.fulfill({ status: 200, contentType: 'application/json', headers: { 'access-control-allow-origin': '*' }, body: JSON.stringify(state.catalog()) });
    }
    if (url.includes('/images/')) return route.fulfill({ status: 200, contentType: 'image/png', body: PNG });
    return route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>Watch</title><p>Watch page</p>' });
  });
  await context.route(/^https?:\/\/(?!localhost|127\.0\.0\.1|wp-slimstat\.com)[^/]*evil[^/]*\//, (route) => {
    state.foreign.push(route.request().url());
    return route.abort();
  });
  return state;
}

const catalogRequests = (r: Remote) => r.requests.filter((q) => q.url() === CATALOG);
const withNew = (...extra: object[]) => ({ ...example, videos: [...example.videos, ...extra] });
const freshVideo = (over: object = {}) => ({
  id: 'e2e-new-video', title: 'A brand new tutorial', slug: 'a-brand-new-tutorial', youtubeId: 'AbCdEfGhIj0',
  thumbnail: `${ORIGIN}/images/videos/new.webp`, category: 'getting-started', published: new Date().toISOString(), order: 1, ...over,
});

/** Ages the saved catalog so the next check is due, as if a day had passed. */
async function ageCache(page: Page): Promise<void> {
  await page.evaluate((day) => {
    const entry = JSON.parse(localStorage.getItem('slimstat_videos_catalog') || 'null');
    if (!entry) throw new Error('no saved catalog to age');
    entry.fetchedAt -= 2 * day;
    entry.checkedAt -= 2 * day;
    localStorage.setItem('slimstat_videos_catalog', JSON.stringify(entry));
  }, DAY);
}

async function showVideos(page: Page, automatic = false): Promise<void> {
  await page.goto(VIDEOS_PAGE);
  if (automatic) await page.getByLabel(/Mark the SlimStat menu/).check();
  await page.getByRole('button', { name: 'Show videos' }).click();
  await expect(page.locator('.ss-videos-row').first()).toBeVisible();
}

// WordPress prints the menu title twice: in the link, and as the folded flyout's aria-hidden heading.
const menuTitle = (page: Page) => page.locator('#adminmenu .wp-menu-name .slimstat-menu-title');
const marker = (page: Page) => page.locator('#adminmenu .wp-menu-name .slimstat-menu-video-badge');
const anyMarker = (page: Page) => page.locator('#adminmenu .slimstat-menu-video');

test.describe('Video tutorials', () => {
  test('Videos follows Settings in the menu, and nothing is requested before the opt-in', async ({ page, context }) => {
    const r = await remote(context);
    await page.goto('/wp-admin/index.php');
    const items = await page.locator('#adminmenu li[id^="toplevel_page_slim"] .wp-submenu a').allInnerTexts();
    const labels = items.map((text) => text.replace(/\s+/g, ' ').trim());
    const settings = labels.indexOf('Settings');
    expect(settings, labels.join(' | ')).toBeGreaterThan(0);
    expect(labels[settings + 1], labels.join(' | ')).toBe('Videos Tutorial');

    await page.goto(VIDEOS_PAGE);
    await expect(page.getByRole('heading', { level: 1, name: 'Video tutorials' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Load the video list from wp-slimstat.com?' })).toBeVisible();
    await expect(page.locator('[data-show="consent"]').getByText('your browser and the address of this site'), 'disclosed before consent').toBeVisible();
    await expect(page.locator('.ss-videos-row')).toHaveCount(0);
    const browse = page.getByRole('link', { name: /Browse the videos on wp-slimstat\.com/ });
    await expect(browse).toHaveAttribute('href', `${ORIGIN}/videos/?utm_source=wp-slimstat&utm_medium=link&utm_campaign=videos&utm_content=browse-videos`);
    await expect(page.locator('.slimstat-menu-tutorial-badge')).toHaveText('Tutorial');
    await expect(browse).toHaveAttribute('rel', 'noopener noreferrer');
    await page.goto('/wp-admin/edit.php');
    expect(r.requests.map((q) => q.url()), 'no request to wp-slimstat.com without consent').toEqual([]);
  });

  test('showing the videos lists categories in order, with lazy thumbnails and watch links in a new tab', async ({ page, context }) => {
    const r = await remote(context);
    await context.addCookies([{ name: 'probe', value: '1', domain: 'wp-slimstat.com', path: '/', secure: true, sameSite: 'None' }]);
    await showVideos(page);

    await expect(page.locator('.ss-videos-row h2')).toHaveText(['Getting started', 'Dashboard and reports', 'WooCommerce', 'Heatmaps', 'Advanced features']);
    await expect(page.locator('.ss-videos-row').nth(1).locator('.ss-videos-title')).toHaveText([
      'Sessions vs users vs pageviews: what did they come for?',
      'Bounce rate vs exit rate: is a high exit rate bad?',
    ]);
    const cards = page.locator('.ss-videos-card');
    await expect(cards).toHaveCount(example.videos.length);
    for (const video of example.videos) {
      const card = page.locator(`.ss-videos-card[href="${ORIGIN}/videos/${video.slug}/"]`);
      await expect(card).toHaveCount(1);
      await expect(card).toHaveAttribute('target', '_blank');
      await expect(card).toHaveAttribute('rel', 'noopener noreferrer');
      await expect(card.locator('.ss-videos-title')).toHaveText(video.title);
      const img = card.locator('img');
      await expect(img).toHaveAttribute('loading', 'lazy');
      await expect(img).toHaveAttribute('src', video.thumbnail);
      await expect(img).toHaveAttribute('alt', '');
    }
    await expect(page.locator('#ss-videos-status')).toHaveText('Videos loaded.');

    const sent = catalogRequests(r);
    expect(sent).toHaveLength(1);
    const headers = await sent[0].allHeaders();
    expect(headers.cookie, 'credentials are omitted').toBeUndefined();
    expect(headers.referer, 'no referrer reveals the page').toBeUndefined();
    expect(headers.origin, 'the one site detail a cross-origin request cannot drop, disclosed in the consent copy').toBe(new URL(page.url()).origin);
    expect(sent[0].url()).toBe(CATALOG);
    for (const q of r.requests.filter((q) => q.url().includes('/images/'))) {
      expect((await q.allHeaders()).referer, 'thumbnails send no referrer').toBeUndefined();
    }

    // The watch page opens in a new tab, cut off from this one.
    const first = example.videos[0];
    const [popup] = await Promise.all([context.waitForEvent('page'), page.locator(`.ss-videos-card[href="${ORIGIN}/videos/${first.slug}/"]`).click()]);
    await popup.waitForLoadState();
    expect(popup.url()).toBe(`${ORIGIN}/videos/${first.slug}/`);
    expect(await popup.evaluate(() => window.opener)).toBeNull();
    await popup.close();

    // A reload within the day uses the saved list.
    await page.reload();
    await expect(cards).toHaveCount(example.videos.length);
    expect(catalogRequests(r)).toHaveLength(1);
  });

  test('keyboard users reach every video and see where focus is', async ({ page, context }) => {
    await remote(context);
    await page.goto(VIDEOS_PAGE);
    await page.getByRole('button', { name: 'Show videos' }).focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('.ss-videos-row h2').first()).toBeFocused();
    await page.keyboard.press('Tab');
    const first = page.locator('.ss-videos-card').first();
    await expect(first).toBeFocused();
    expect(await first.evaluate((el) => getComputedStyle(el).outlineStyle)).not.toBe('none');
    for (let i = 1; i < example.videos.length; i++) await page.keyboard.press('Tab');
    await expect(page.locator('.ss-videos-card').last()).toBeFocused();
  });

  test('remote markup stays text, and foreign or malformed entries are dropped', async ({ page, context }) => {
    const hostile = withNew(
      freshVideo({ id: 'xss-title', title: '<img src=x onerror="window.__ssXss=1">Hostile', slug: 'hostile' }),
      freshVideo({ id: 'evil-thumb', title: 'Foreign thumbnail', slug: 'foreign-thumbnail', thumbnail: 'https://evil.example/pixel.png' }),
      freshVideo({ id: 'evil-slug', title: 'Escaping slug', slug: '../../evil' }),
      freshVideo({ id: 'evil-js', title: 'Script thumbnail', slug: 'script-thumbnail', thumbnail: 'javascript:alert(1)' }),
    );
    const r = await remote(context, () => hostile);
    await showVideos(page);
    await expect(page.locator('.ss-videos-title', { hasText: 'Hostile' })).toHaveText('<img src=x onerror="window.__ssXss=1">Hostile');
    expect(await page.evaluate(() => (window as any).__ssXss)).toBeUndefined();
    await expect(page.locator('.ss-videos-card[href*="evil"]')).toHaveCount(0);
    await expect(page.locator('.ss-videos-title', { hasText: 'Escaping slug' })).toHaveCount(0);
    for (const title of ['Foreign thumbnail', 'Script thumbnail']) {
      const card = page.locator('.ss-videos-card', { has: page.locator('.ss-videos-title', { hasText: title }) });
      await expect(card.locator('img')).toHaveCount(0);
      await expect(card.locator('.ss-videos-thumb')).toHaveClass(/is-missing/);
    }
    await expect(page.locator('#ss-videos-rows img:not([src^="https://wp-slimstat.com/"])')).toHaveCount(0);
    expect(r.foreign).toEqual([]);
  });

  test('a failed first load offers Retry; later failures fall back to the saved list', async ({ page, context }) => {
    const r = await remote(context);
    r.fail = true;
    await page.goto(VIDEOS_PAGE);
    await page.getByRole('button', { name: 'Show videos' }).click();
    const error = page.getByRole('alert');
    await expect(error).toContainText('could not be loaded');
    await expect(error.getByRole('link', { name: /Browse the videos on wp-slimstat\.com/ })).toHaveAttribute('href', `${ORIGIN}/videos/?utm_source=wp-slimstat&utm_medium=link&utm_campaign=videos&utm_content=browse-videos`);
    r.fail = false;
    await error.getByRole('button', { name: 'Try again' }).click();
    await expect(page.locator('.ss-videos-card')).toHaveCount(example.videos.length);
    await expect(page.locator('#ss-videos-stale')).toBeHidden();

    await ageCache(page);
    r.fail = true;
    await page.reload();
    await expect(page.locator('.ss-videos-card')).toHaveCount(example.videos.length);
    await expect(page.locator('#ss-videos-stale')).toBeVisible();
    await expect(page.locator('#ss-videos-stale')).toContainText('saved');
  });

  test('an empty catalog shows the empty state', async ({ page, context }) => {
    await remote(context, () => ({ version: 1, categories: [], videos: [] }));
    await page.goto(VIDEOS_PAGE);
    await page.getByRole('button', { name: 'Show videos' }).click();
    await expect(page.getByText('No video tutorials have been published yet.')).toBeVisible();
    await expect(page.locator('.ss-videos-row')).toHaveCount(0);
  });

  test('a new video puts a play icon before SlimStat on every admin page until the Videos page has shown it', async ({ page, context }) => {
    let catalog: unknown = example;
    const r = await remote(context, () => catalog);
    await showVideos(page, true);
    await page.goto('/wp-admin/index.php');
    await expect(menuTitle(page)).toBeVisible();
    await expect(anyMarker(page), 'the videos that existed at setup are not new').toHaveCount(0);
    expect(catalogRequests(r)).toHaveLength(1);

    catalog = withNew(freshVideo(), freshVideo({ id: 'e2e-second', slug: 'e2e-second', title: 'Second new tutorial' }));
    await ageCache(page);
    await page.goto('/wp-admin/index.php');
    await expect(marker(page)).toBeVisible();
    expect(catalogRequests(r)).toHaveLength(2);
    await expect(marker(page)).toHaveAttribute('aria-hidden', 'true');
    expect(await marker(page).evaluate((el) => el.nextElementSibling?.classList.contains('slimstat-menu-title'))).toBe(true);
    expect(await marker(page).textContent()).toMatch(/^▶/);
    await expect(page.locator('#adminmenu .slimstat-menu-video.screen-reader-text')).toHaveText(/New video tutorials/);
    await expect(page.locator('#adminmenu a[href$="page=slimvideos"] .slimstat-menu-new-badge')).toHaveText('New');
    await expect(page.locator('#adminmenu .wp-submenu-head .slimstat-menu-video-badge'), 'the folded flyout shows it too').toHaveCount(1);

    await page.goto('/wp-admin/edit.php');
    await expect(marker(page), 'still marked on other pages').toBeVisible();
    expect(catalogRequests(r), 'and the day-long cache means no new request').toHaveLength(2);

    await page.goto(VIDEOS_PAGE);
    await expect(page.locator('.ss-videos-card').filter({ hasText: 'A brand new tutorial' }).locator('.slimstat-menu-new-badge')).toHaveText('New');
    await expect(page.locator('.ss-videos-card').filter({ hasText: 'Second new tutorial' }).locator('.slimstat-menu-new-badge')).toHaveText('New');
    await expect(anyMarker(page), 'cleared once the page has shown them').toHaveCount(0);
    await page.goto('/wp-admin/index.php');
    await expect(menuTitle(page)).toBeVisible();
    await expect(anyMarker(page)).toHaveCount(0);
    expect(catalogRequests(r)).toHaveLength(2);

    // Editing a video it has already shown is not news.
    catalog = withNew(freshVideo({ title: 'Renamed tutorial' }), freshVideo({ id: 'e2e-second', slug: 'e2e-second', title: 'Second new tutorial' }));
    await ageCache(page);
    await page.goto('/wp-admin/index.php');
    await expect.poll(() => catalogRequests(r).length).toBe(3);
    await expect(anyMarker(page)).toHaveCount(0);
  });

  test('the site front end loads nothing for this feature', async ({ page, context }) => {
    const r = await remote(context);
    await showVideos(page, true);
    await ageCache(page);
    const before = r.requests.length;
    await page.goto('/');
    await page.waitForLoadState('load');
    await expect(page.locator('script[src*="video-catalog.js"], script[src*="videos.js"]')).toHaveCount(0);
    expect(r.requests.length).toBe(before);
  });

  test('forgetting the choice returns to the opt-in and clears this browser\'s state', async ({ page, context }) => {
    await remote(context);
    await showVideos(page, true);
    expect(await page.evaluate((prefix) => Object.keys(localStorage).filter((k) => k.startsWith(prefix)).length, STATE_PREFIX)).toBe(1);
    await page.getByRole('button', { name: 'Stop loading videos in this browser' }).click();
    await expect(page.getByRole('heading', { name: 'Load the video list from wp-slimstat.com?' })).toBeVisible();
    await expect(page.locator('.ss-videos-row')).toHaveCount(0);
    await expect(page.getByLabel(/Mark the SlimStat menu/)).not.toBeChecked();
    expect(await page.evaluate((prefix) => Object.keys(localStorage).filter((k) => k.startsWith(prefix)).length, STATE_PREFIX)).toBe(0);
  });
});
