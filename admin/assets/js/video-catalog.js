/*
 * Video tutorials: the catalog client and the new-video marker in the admin menu. GPL-2.0-or-later.
 *
 * Loaded on every admin page of a user who can see the SlimStat menu, so it is small and idle by
 * default: nothing is requested until this user opts in on SlimStat > Videos. After that the
 * catalog (a static JSON file on wp-slimstat.com) is requested at most once a day, from this
 * browser only, with no cookies, referrer, custom headers or parameters (only the browser's own
 * Origin header, see docs/video-tutorials/README.md), and saved in localStorage.
 *
 * localStorage is shared with every script on this origin, front end included, so whatever is
 * read back is validated exactly like a network response. Validated text is only ever rendered
 * with textContent, thumbnails must be https on wp-slimstat.com, and watch links are built from
 * the slug rather than taken from the catalog.
 *
 * A video is new when its ID is not among those this user has seen and it was published after
 * the user's baseline (taken the first time the catalog loads for them). Editing a video keeps
 * its ID, so it is not new; the Videos page marks everything it shows as seen.
 */
(function () {
    'use strict';
    const config = window.SlimStatVideoConfig;
    if (!config || !config.catalogUrl) return;

    const DAY = 864e5;
    // A video dated a few days before the baseline but added to the catalog after it is still news.
    const GRACE = 7 * DAY;
    const MAX_BYTES = 262144;
    const MAX_VIDEOS = 500;
    const MAX_CATEGORIES = 50;
    const TIMEOUT = 10000;
    const CATALOG_KEY = 'slimstat_videos_catalog';
    const STATE_KEY = 'slimstat_videos_state_' + config.scope;
    const ID = /^[a-z0-9][a-z0-9_-]{0,63}$/;
    const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
    const YOUTUBE = /^[A-Za-z0-9_-]{11}$/;
    const DURATION = /^(?:\d{1,2}:)?\d{1,2}:\d{2}$/;
    const DATE = /^(\d{4})-(\d{2})-(\d{2})(?:T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2}))?$/;
    let inflight = null;

    // ── Storage: every access may throw (private mode, quota, blocked storage). ──────────
    function storage() {
        try { return window.localStorage || null; } catch (e) { return null; }
    }
    function read(key) {
        try { const s = storage(); return s ? JSON.parse(s.getItem(key)) : null; } catch (e) { return null; }
    }
    function write(key, value) {
        try { const s = storage(); if (s) s.setItem(key, JSON.stringify(value)); } catch (e) { /* not saved: the next page asks again */ }
    }
    function remove(key) {
        try { const s = storage(); if (s) s.removeItem(key); } catch (e) { /* nothing to remove */ }
    }

    // ── Validation. ───────────────────────────────────────────────────────────────────────
    const is = (pattern, value) => typeof value === 'string' && pattern.test(value);
    const time = (value) => (typeof value === 'number' && isFinite(value) && value > 0 ? value : 0);
    const integer = (value, fallback) => (Number.isInteger(value) && Math.abs(value) <= 1e6 ? value : fallback);

    function text(value, max) {
        if (typeof value !== 'string') return '';
        // eslint-disable-next-line no-control-regex
        const clean = value.replace(/[\u0000-\u001f\u007f]+/g, ' ').replace(/\s+/g, ' ').trim();
        return clean.length > max ? clean.slice(0, max - 1).trim() + '\u2026' : clean;
    }

    /** An ISO 8601 date or date-time, as its UTC day ('YYYY-MM-DD'), or '' if it is not a real date. */
    function day(value) {
        const m = typeof value === 'string' && DATE.exec(value);
        if (!m) return '';
        const calendar = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
        if (calendar.getUTCMonth() !== +m[2] - 1 || calendar.getUTCDate() !== +m[3]) return '';
        const parsed = Date.parse(value.length === 10 ? value + 'T00:00:00Z' : value);
        return isNaN(parsed) ? '' : new Date(parsed).toISOString().slice(0, 10);
    }

    /** An https URL on wp-slimstat.com, absolute; '' for anything else. */
    function asset(value) {
        if (typeof value !== 'string' || value.length > 2048) return '';
        try {
            const url = new URL(value, config.origin);
            return url.origin === config.origin && !url.username && !url.password ? url.href : '';
        } catch (e) {
            return '';
        }
    }

    /** The catalog as the page may use it, or null. Unknown fields are ignored, bad entries dropped one by one. */
    function validate(data) {
        if (!data || typeof data !== 'object' || data.version !== 1 || !Array.isArray(data.videos)) return null;
        const categories = [];
        const known = Object.create(null);
        (Array.isArray(data.categories) ? data.categories : []).forEach((item) => {
            if (categories.length < MAX_CATEGORIES && item && is(ID, item.id) && !known[item.id]) {
                known[item.id] = true;
                categories.push({ id: item.id, title: text(item.title, 80), order: integer(item.order, null) });
            }
        });
        const videos = [];
        const seen = Object.create(null);
        data.videos.forEach((item) => {
            if (videos.length >= MAX_VIDEOS || !item || typeof item !== 'object') return;
            const video = {
                id: item.id,
                title: text(item.title, 200),
                slug: item.slug,
                youtubeId: item.youtubeId,
                thumbnail: asset(item.thumbnail),
                category: is(ID, item.category) ? item.category : '',
                published: day(item.published),
                order: integer(item.order, 1000),
                duration: is(DURATION, item.duration) ? item.duration : '',
                description: text(item.description, 500),
            };
            if (is(ID, video.id) && !seen[video.id] && is(SLUG, video.slug) && video.slug.length <= 100 && is(YOUTUBE, video.youtubeId) && video.title && video.published) {
                seen[video.id] = true;
                videos.push(video);
            }
        });
        return { version: 1, categories, videos };
    }

    function parse(body) {
        if (typeof body !== 'string' || body.length > MAX_BYTES) return null;
        try { return validate(JSON.parse(body)); } catch (e) { return null; }
    }

    // ── The saved catalog and the network. ────────────────────────────────────────────────
    function saved() {
        const entry = read(CATALOG_KEY) || {};
        return { catalog: validate(entry.catalog), fetchedAt: time(entry.fetchedAt), checkedAt: time(entry.checkedAt) };
    }

    /** Within the last day, and not from the future (a clock change, or a tampered record). */
    const recent = (at, now) => at > 0 && at <= now && now - at < DAY;

    function download() {
        if (typeof window.fetch !== 'function') return Promise.resolve(null);
        const controller = typeof AbortController === 'function' ? new AbortController() : null;
        const timer = controller ? setTimeout(() => controller.abort(), TIMEOUT) : 0;
        // No headers: a custom one would make the browser send a CORS preflight. ETag and
        // Cache-Control are handled by the HTTP cache: 'no-cache' revalidates it on each daily check.
        return window.fetch(config.catalogUrl, {
            method: 'GET',
            mode: 'cors',
            credentials: 'omit',
            referrerPolicy: 'no-referrer',
            cache: 'no-cache',
            signal: controller ? controller.signal : undefined,
        }).then((response) => {
            const length = Number(response.headers.get('Content-Length')) || 0;
            if (!response.ok || length > MAX_BYTES || (response.url && new URL(response.url).origin !== config.origin)) return null;
            return response.text().then(parse);
        }).catch(() => null).then((catalog) => {
            clearTimeout(timer);
            return catalog;
        });
    }

    /** Runs task once across this origin's tabs where the Web Locks API exists, otherwise directly. */
    function exclusive(task) {
        const locks = window.navigator && window.navigator.locks;
        if (locks && typeof locks.request === 'function') {
            try { return locks.request('slimstat_videos_fetch', task).catch(task); } catch (e) { /* fall through */ }
        }
        return Promise.resolve().then(task);
    }

    /**
     * The catalog, from the saved copy while it is under a day old, otherwise from wp-slimstat.com.
     *   auto:  a background check; skipped if one was attempted in the last day, even if it failed.
     *   force: Retry; always asks.
     * Resolves { catalog, stale }: catalog is null only if there is nothing to show.
     */
    function load(options) {
        const opts = options || {};
        if (inflight) return inflight;
        inflight = exclusive(() => {
            // Read inside the lock: another tab may have refreshed while this one waited.
            const entry = saved();
            const now = Date.now();
            const fresh = entry.catalog && recent(entry.fetchedAt, now);
            const offline = window.navigator && window.navigator.onLine === false;
            if (!opts.force && (fresh || (opts.auto && recent(entry.checkedAt, now)))) {
                return { catalog: entry.catalog, stale: !fresh };
            }
            if (offline) return { catalog: entry.catalog, stale: true, offline: true };
            return download().then((catalog) => {
                const ok = !!catalog;
                write(CATALOG_KEY, { fetchedAt: ok ? now : entry.fetchedAt, checkedAt: now, catalog: ok ? catalog : entry.catalog });
                return { catalog: ok ? catalog : entry.catalog, stale: !ok };
            });
        }).catch(() => ({ catalog: saved().catalog, stale: true })).then((result) => {
            inflight = null;
            return result;
        });
        return inflight;
    }

    // ── This user's choices and what they have seen. ───────────────────────────────────────
    function getState() {
        const raw = read(STATE_KEY) || {};
        return {
            load: raw.load === true,
            auto: raw.auto === true,
            since: day(raw.since),
            seen: Array.isArray(raw.seen) ? raw.seen.filter((id) => is(ID, id)).slice(0, MAX_VIDEOS) : [],
        };
    }

    function setConsent(choice) {
        const state = getState();
        if ('load' in choice) state.load = !!choice.load;
        if ('auto' in choice) state.auto = !!choice.auto;
        write(STATE_KEY, state);
    }

    function forget() {
        remove(STATE_KEY);
        updateMenu(0);
    }

    /** IDs of videos this user has not seen yet; none before the user has a baseline. */
    function newVideos(catalog, state) {
        const s = state || getState();
        if (!catalog || !s.since) return [];
        const seen = new Set(s.seen);
        return catalog.videos.filter((v) => !seen.has(v.id) && v.published >= s.since).map((v) => v.id);
    }

    /** Everything in this catalog has now been shown; the first call also sets the baseline. */
    function markSeen(catalog) {
        const state = getState();
        state.since = state.since || new Date(Date.now() - GRACE).toISOString().slice(0, 10);
        state.seen = catalog.videos.map((v) => v.id);
        write(STATE_KEY, state);
        updateMenu(0);
    }

    // ── The menu marker. ──────────────────────────────────────────────────────────────────
    /** A text-only element: the one way this feature puts catalog text on the page. */
    function el(tag, className, content) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (content) node.textContent = content;
        return node;
    }

    /** ▶ before "SlimStat" and a New pill on Videos while count > 0; removed otherwise. Idempotent. */
    function updateMenu(count) {
        const menu = document.getElementById('adminmenu');
        if (!menu) return;
        Array.prototype.forEach.call(menu.querySelectorAll('.slimstat-menu-video'), (node) => node.parentNode.removeChild(node));
        if (!count) return;
        // WordPress prints the title twice: in the menu link, and as the aria-hidden heading of the
        // folded menu's flyout. Both get the icon; only the link gets the text.
        const link = menu.querySelector('.wp-menu-name > .slimstat-menu-title');
        Array.prototype.forEach.call(menu.querySelectorAll('.slimstat-menu-title'), (title) => {
            // U+FE0E keeps the triangle a text glyph rather than an emoji.
            const badge = el('span', 'slimstat-menu-video slimstat-menu-video-badge', '\u25B6\uFE0E');
            badge.setAttribute('aria-hidden', 'true');
            badge.setAttribute('title', config.newVideos);
            title.parentNode.insertBefore(badge, title);
        });
        if (link) link.parentNode.insertBefore(el('span', 'slimstat-menu-video screen-reader-text', ' ' + config.newVideos), link.nextSibling);
        const item = menu.querySelector('a[href$="page=' + config.screen + '"] .slimstat-menu-label');
        if (item) item.appendChild(el('span', 'slimstat-menu-video slimstat-menu-new-badge', config.newPill));
    }

    function mark(catalog, state) {
        if (!catalog || !state.auto) return updateMenu(0);
        if (!state.since) return markSeen(catalog);
        updateMenu(newVideos(catalog, state).length);
    }

    // ── Rendering helpers shared with the Videos page. ────────────────────────────────────
    function watchUrl(slug) {
        return config.watchBase + (is(SLUG, slug) && slug.length <= 100 ? slug + '/' : '');
    }

    /**
     * Rows for the page, in order. Built-in categories keep their translated labels; the catalog
     * may reorder them and add its own. Videos in an unknown category go to the last row.
     */
    function groups(catalog, builtins, moreLabel) {
        const rows = Object.create(null);
        (builtins || []).forEach((c, i) => { rows[c.id] = { id: c.id, title: c.label, order: (i + 1) * 10, videos: [] }; });
        catalog.categories.forEach((c) => {
            if (rows[c.id]) {
                if (c.order !== null) rows[c.id].order = c.order;
            } else if (c.title) {
                rows[c.id] = { id: c.id, title: c.title, order: c.order === null ? 1000 : c.order, videos: [] };
            }
        });
        const more = { id: '', title: moreLabel, order: 1e9, videos: [] };
        catalog.videos.forEach((v) => (rows[v.category] || more).videos.push(v));
        const byVideo = (a, b) => a.order - b.order || (a.published < b.published ? 1 : a.published > b.published ? -1 : 0) || a.title.localeCompare(b.title);
        return Object.keys(rows).map((k) => rows[k]).concat(more)
            .filter((row) => row.videos.length)
            .sort((a, b) => a.order - b.order || a.title.localeCompare(b.title))
            .map((row) => Object.assign(row, { videos: row.videos.sort(byVideo) }));
    }

    window.SlimStatVideos = { validate, parse, load, getState, setConsent, forget, newVideos, markSeen, updateMenu, groups, watchUrl, el };

    // ── Background check on ordinary admin pages. ─────────────────────────────────────────
    // The Videos page runs its own load; a page without the menu has nothing to mark; a user who
    // has not opted in costs one small localStorage read. Everything else waits for idle time.
    if (document.getElementById('slimstat-videos') || !document.getElementById('adminmenu') || !getState().auto) return;
    let catalog = null;
    const idle = window.requestIdleCallback || ((fn) => setTimeout(fn, 0));
    idle(() => {
        const first = saved();
        catalog = first.catalog;
        mark(catalog, getState());
        // Every write stamps checkedAt, so a check in the last day means nothing is due.
        if (!recent(first.checkedAt, Date.now())) {
            load({ auto: true }).then((result) => {
                catalog = result.catalog;
                mark(catalog, getState());
            });
        }
    }, { timeout: 2000 });
    // The Videos page in another tab marks videos seen; follow it here.
    window.addEventListener('storage', (event) => {
        if (event.key === CATALOG_KEY) catalog = saved().catalog;
        if (event.key === STATE_KEY || event.key === CATALOG_KEY) mark(catalog, getState());
    });
})();
