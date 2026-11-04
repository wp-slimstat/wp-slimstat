// Video tutorials catalog client (admin/assets/js/video-catalog.js), run as shipped in a sandbox.
//
// The client is the only code that talks to wp-slimstat.com, and it runs on every admin page of
// every user who can see the SlimStat menu. What is pinned here:
//   - nothing is requested before this user opted in, and an automatic check runs at most once a day;
//   - the request carries no cookies, referrer, headers or parameters, and concurrent loads share it;
//   - remote JSON and localStorage (writable by any script on the origin) are both validated, and
//     every URL the page will use points at wp-slimstat.com;
//   - a failed or offline check falls back to the saved list without retrying on every page load;
//   - new videos are new IDs published after the user's baseline: never the list that existed when
//     they started, never an edited video, and they stay new until the Videos page has shown them.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.join(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'admin/assets/js/video-catalog.js'), 'utf8');
const example = JSON.parse(fs.readFileSync(path.join(root, 'docs/video-tutorials/videos.example.json'), 'utf8'));
const DAY = 864e5;
const NOW = Date.parse('2026-11-03T12:00:00Z');
const CATALOG_URL = 'https://wp-slimstat.com/videos/videos.json';

// ── A minimal DOM: enough for the admin menu the marker edits. ──────────────────────────────
class Node {
    constructor(tag, classes = '', text = '') {
        this.tagName = tag.toUpperCase();
        this.className = classes;
        this.attributes = {};
        this.children = [];
        this.parentNode = null;
        this.textContent = text;
    }
    get classList() {
        const node = this;
        return { contains: c => node.className.split(/\s+/).includes(c), add: c => { node.className += ' ' + c; } };
    }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; }
    appendChild(child) { return this.insertBefore(child, null); }
    insertBefore(child, ref) {
        if (child.parentNode) child.parentNode.removeChild(child);
        const at = ref ? this.children.indexOf(ref) : -1;
        this.children.splice(at < 0 ? this.children.length : at, 0, child);
        child.parentNode = this;
        return child;
    }
    removeChild(child) { this.children.splice(this.children.indexOf(child), 1); child.parentNode = null; return child; }
    get nextSibling() { const s = this.parentNode.children; return s[s.indexOf(this) + 1] || null; }
    all() { return this.children.flatMap(c => [c, ...c.all()]); }
    querySelectorAll(selector) {
        const m = /^\.([\w-]+)$/.exec(selector);
        assert(m, 'unsupported selector ' + selector);
        return this.all().filter(n => n.classList.contains(m[1]));
    }
    querySelector(selector) {
        if (selector === 'a[href$="page=slimvideos"] .slimstat-menu-label') return this.all().find(n => n.videosLabel) || null;
        if (selector === '.wp-menu-name > .slimstat-menu-title') return this.all().find(n => n.classList.contains('slimstat-menu-title') && n.parentNode.classList.contains('wp-menu-name')) || null;
        return this.querySelectorAll(selector)[0] || null;
    }
}

function adminMenu() {
    const menu = new Node('ul');
    const top = menu.appendChild(new Node('a'));
    const name = top.appendChild(new Node('div', 'wp-menu-name'));
    name.appendChild(new Node('span', 'slimstat-menu-title', 'SlimStat'));
    // WordPress repeats the menu title as the folded flyout's aria-hidden heading.
    const submenu = menu.appendChild(new Node('ul', 'wp-submenu'));
    const head = submenu.appendChild(new Node('li', 'wp-submenu-head'));
    head.appendChild(new Node('span', 'slimstat-menu-title', 'SlimStat'));
    const sub = submenu.appendChild(new Node('a'));
    const label = sub.appendChild(new Node('span', 'slimstat-menu-label', 'Videos'));
    label.videosLabel = true;
    return { menu, name, head, label };
}

// ── The sandbox. ────────────────────────────────────────────────────────────────────────────
function sandbox(options = {}) {
    const store = options.store || new Map();
    const requests = [];
    const clock = { now: options.now || NOW };
    const dom = adminMenu();
    const listeners = {};
    const responder = options.respond || (() => ({ body: JSON.stringify(example) }));
    class FakeDate extends Date {
        constructor(...args) { super(...(args.length ? args : [clock.now])); }
        static now() { return clock.now; }
    }
    const window = {
        SlimStatVideoConfig: {
            catalogUrl: CATALOG_URL,
            origin: 'https://wp-slimstat.com',
            watchBase: 'https://wp-slimstat.com/videos/',
            screen: 'slimvideos',
            scope: options.scope || 'site1user1',
            newVideos: 'New video tutorials',
            newPill: 'New',
        },
        localStorage: options.noStorage ? undefined : {
            getItem: k => (store.has(k) ? store.get(k) : null),
            setItem: (k, v) => { if (options.quota) throw new Error('QuotaExceededError'); store.set(k, String(v)); },
            removeItem: k => store.delete(k),
        },
        navigator: { onLine: options.offline ? false : true, locks: options.locks },
        fetch: (url, init) => {
            requests.push({ url, init });
            const r = responder(requests.length, url, init);
            if (r instanceof Error) return Promise.reject(r);
            return Promise.resolve({
                ok: r.status === undefined || (r.status >= 200 && r.status < 300),
                status: r.status || 200,
                url: r.url === undefined ? url : r.url,
                headers: { get: name => (r.headers || {})[name.toLowerCase()] || null },
                text: () => Promise.resolve(r.body),
            });
        },
        addEventListener: (type, fn) => { (listeners[type] = listeners[type] || []).push(fn); },
        setTimeout, clearTimeout,
    };
    const document = {
        getElementById: id => (id === 'adminmenu' ? (options.noMenu ? null : dom.menu) : id === 'slimstat-videos' ? options.videosPage || null : null),
        createElement: tag => new Node(tag),
    };
    window.window = window;
    window.document = document;
    const context = Object.assign(window, { Date: FakeDate, URL, Promise, JSON, Math, Number, String, Object, Array, isNaN, isFinite, AbortController });
    vm.runInNewContext(source, context, { filename: 'video-catalog.js' });
    return { api: window.SlimStatVideos, store, requests, clock, dom, listeners, window };
}

const tick = () => new Promise(resolve => setTimeout(resolve, 0));
const settle = async () => { for (let i = 0; i < 5; i++) await tick(); };
const ids = catalog => catalog.videos.map(v => v.id);
const clone = value => JSON.parse(JSON.stringify(value));
const withVideos = (...extra) => Object.assign(clone(example), { videos: example.videos.concat(extra) });
const video = (over = {}) => Object.assign({
    id: 'fresh-video', title: 'A fresh video', slug: 'a-fresh-video', youtubeId: 'AbCdEfGhIj0',
    thumbnail: 'https://wp-slimstat.com/images/videos/fresh.webp', category: 'getting-started',
    published: '2026-11-04T10:00:00+00:00', order: 5,
}, over);
const menuBadges = dom => dom.menu.querySelectorAll('.slimstat-menu-video');
// Values built inside the sandbox have its own Array/Object prototypes; compare them as data.
const same = (actual, expected, message) => assert.deepStrictEqual(JSON.parse(JSON.stringify(actual)), JSON.parse(JSON.stringify(expected)), message);
let passed = 0;
async function check(name, fn) { await fn(); passed++; console.log('PASS: ' + name); }

(async () => {
    // ── Validation ─────────────────────────────────────────────────────────────────────────
    await check('the documented example catalog is accepted in full', () => {
        const { api } = sandbox();
        const catalog = api.validate(clone(example));
        assert(catalog, 'example must validate');
        same(ids(catalog), example.videos.map(v => v.id));
        assert.equal(catalog.categories.length, 8);
        assert.equal(catalog.videos[0].published, '2026-10-25', 'dates normalise to the UTC day');
        // Validation is idempotent: what is cached re-validates to itself.
        same(api.validate(clone(catalog)), catalog);
    });

    await check('invalid JSON, wrong roots, unknown schema versions and oversize bodies are rejected', () => {
        const { api } = sandbox();
        for (const body of ['', 'not json', 'null', '[]', '"x"', '{}', '{"version":2,"videos":[]}', '{"version":"1","videos":[]}', '{"version":1,"videos":{}}', '{"version":1}']) {
            assert.equal(api.parse(body), null, 'rejects ' + body);
        }
        assert.equal(api.parse(JSON.stringify({ version: 1, videos: [], pad: 'x'.repeat(300000) })), null, 'oversize');
        same(ids(api.parse('{"version":1,"videos":[]}')), [], 'an empty list is valid');
    });

    await check('each video is checked on its own: bad entries are dropped, the rest survive', () => {
        const { api } = sandbox();
        const bad = [
            null, 'string', 42, [],
            video({ id: undefined }), video({ id: ['ok'] }), video({ id: 'Has Space' }), video({ id: 'x'.repeat(65) }),
            video({ id: 'constructor', slug: 'proto-a' }), // must not collide with Object.prototype
            video({ id: 's1', slug: '../evil' }), video({ id: 's2', slug: 'Upper' }), video({ id: 's3', slug: 'a/b' }), video({ id: 's4', slug: 'https://evil.example/' }), video({ id: 's5', slug: undefined }),
            video({ id: 'y1', youtubeId: 'short' }), video({ id: 'y2', youtubeId: 'AbCdEfGhIj0"><script>' }), video({ id: 'y3', youtubeId: undefined }),
            video({ id: 't1', title: '' }), video({ id: 't2', title: '   ' }), video({ id: 't3', title: 7 }),
            video({ id: 'd1', published: 'yesterday' }), video({ id: 'd2', published: '2026-02-31' }), video({ id: 'd3', published: undefined }), video({ id: 'd4', published: 1730000000 }),
        ];
        const catalog = api.validate({ version: 1, videos: [video({ id: 'keeper' }), ...bad, video({ id: 'keeper', title: 'duplicate id' })] });
        same(ids(catalog), ['keeper', 'constructor'], 'only well-formed, first-seen ids survive');
        assert.equal(catalog.videos[0].title, 'A fresh video', 'the first of a duplicated id wins');
    });

    await check('text is trimmed and bounded; HTML stays inert text for the renderer', () => {
        const { api } = sandbox();
        const [v] = api.validate({ version: 1, videos: [video({ title: '  <img src=x onerror=alert(1)>\n\tTitle  ', description: 'd'.repeat(900), duration: '12:34:56', order: 2.5 })] }).videos;
        assert.equal(v.title, '<img src=x onerror=alert(1)> Title', 'markup is kept as literal text, never parsed');
        assert(v.description.length <= 500, 'description is capped');
        assert.equal(v.duration, '12:34:56');
        assert.equal(v.order, 1000, 'a non-integer order falls back to the end');
        const [w] = api.validate({ version: 1, videos: [video({ duration: '<b>1:00</b>', description: 5 })] }).videos;
        assert.equal(w.duration, '');
        assert.equal(w.description, '');
    });

    await check('thumbnails must be https on wp-slimstat.com; anything else becomes a placeholder', () => {
        const { api } = sandbox();
        const thumbs = {
            'https://wp-slimstat.com/images/videos/a.webp': 'https://wp-slimstat.com/images/videos/a.webp',
            '/images/videos/b.webp': 'https://wp-slimstat.com/images/videos/b.webp',
            'http://wp-slimstat.com/images/a.webp': '',
            'https://i.ytimg.com/vi/x/hqdefault.jpg': '',
            'https://wp-slimstat.com.evil.example/a.webp': '',
            'https://user:pass@wp-slimstat.com/a.webp': '',
            'https://wp-slimstat.com:8443/a.webp': '',
            '//evil.example/a.webp': '',
            'javascript:alert(1)': '',
            'data:image/svg+xml,<svg onload=alert(1)>': '',
        };
        Object.entries(thumbs).forEach(([input, expected], i) => {
            const catalog = api.validate({ version: 1, videos: [video({ id: 'v' + i, thumbnail: input })] });
            assert.equal(catalog.videos.length, 1, 'the video itself survives: ' + input);
            assert.equal(catalog.videos[0].thumbnail, expected, input);
        });
    });

    await check('watch links are built from the slug on wp-slimstat.com, never taken from the catalog', () => {
        const { api } = sandbox();
        assert.equal(api.watchUrl('slimstat-6-overview'), 'https://wp-slimstat.com/videos/slimstat-6-overview/');
        for (const slug of ['../x', 'https://evil.example', 'a b', '', undefined, 'javascript:alert(1)']) {
            assert.equal(api.watchUrl(slug), 'https://wp-slimstat.com/videos/', 'falls back to the hub for ' + slug);
        }
    });

    await check('categories: catalog order wins, built-in labels stay translatable, unknown ids go last', () => {
        const { api } = sandbox();
        const builtins = [{ id: 'getting-started', label: 'Erste Schritte' }, { id: 'heatmaps', label: 'Heatmaps (de)' }, { id: 'pro', label: 'Pro (de)' }];
        const catalog = api.validate({
            version: 1,
            categories: [{ id: 'heatmaps', title: 'Remote heatmaps title', order: 1 }, { id: 'brand-new', title: 'Brand new row', order: 15 }, { id: 'untitled', order: 2 }],
            videos: [
                video({ id: 'a', category: 'getting-started', order: 2 }),
                video({ id: 'b', category: 'getting-started', order: 1 }),
                video({ id: 'c', category: 'heatmaps' }),
                video({ id: 'd', category: 'brand-new' }),
                video({ id: 'e', category: 'nowhere' }),
                video({ id: 'f', category: 'untitled' }),
                video({ id: 'g', category: 'getting-started', order: 1, published: '2026-11-05' }),
            ],
        });
        const groups = api.groups(catalog, builtins, 'More videos');
        same(groups.map(g => g.title), ['Heatmaps (de)', 'Erste Schritte', 'Brand new row', 'More videos']);
        same(groups[1].videos.map(v => v.id), ['g', 'b', 'a'], 'order, then newest first');
        same(groups[3].videos.map(v => v.id).sort(), ['e', 'f'], 'unknown and untitled categories fall into More videos');
        assert(!groups.some(g => g.title === 'Pro (de)'), 'empty categories are not shown');
    });

    // ── Network and caching ────────────────────────────────────────────────────────────────
    await check('the request is anonymous: no cookies, referrer, headers or query string', async () => {
        const { api, requests } = sandbox();
        const result = await api.load();
        assert.equal(requests.length, 1);
        const { url, init } = requests[0];
        assert.equal(url, CATALOG_URL);
        assert.equal(init.credentials, 'omit');
        assert.equal(init.referrerPolicy, 'no-referrer');
        assert.equal(init.mode, 'cors');
        assert.equal(init.cache, 'no-cache', 'revalidate through the HTTP cache (ETag) on each daily check');
        assert.equal(init.headers, undefined, 'a custom header would force a CORS preflight');
        assert.equal(init.body, undefined);
        assert.equal(result.stale, false);
        same(ids(result.catalog), ids(api.validate(clone(example))));
    });

    await check('a fresh cache is used for 24 hours; then exactly one refresh', async () => {
        const { api, requests, clock } = sandbox();
        await api.load();
        clock.now += DAY - 1;
        await api.load();
        await api.load({ auto: true });
        assert.equal(requests.length, 1, 'within 24 hours nothing is requested');
        clock.now += 2;
        await api.load();
        assert.equal(requests.length, 2, 'refreshes once the day is up');
    });

    await check('concurrent loads share one request, with and without the Web Locks API', async () => {
        for (const locks of [undefined, { request: (name, task) => Promise.resolve().then(task) }]) {
            const { api, requests } = sandbox({ locks });
            const [a, b, c] = await Promise.all([api.load(), api.load(), api.load({ auto: true })]);
            assert.equal(requests.length, 1);
            assert.equal(a.catalog.videos.length, b.catalog.videos.length);
            assert.equal(c.catalog.videos.length, a.catalog.videos.length);
        }
    });

    await check('another tab that already refreshed is reused inside the lock', async () => {
        const store = new Map();
        const first = sandbox({ store });
        let release;
        const gate = new Promise(resolve => { release = resolve; });
        // Simulate a lock held by another tab: this tab's task runs only after that tab wrote the cache.
        const second = sandbox({ store, locks: { request: (name, task) => gate.then(task) } });
        const pending = second.api.load();
        await first.api.load();
        release();
        await pending;
        assert.equal(first.requests.length + second.requests.length, 1, 'the waiting tab re-reads the cache before fetching');
    });

    await check('network errors, HTTP errors, foreign redirects and invalid catalogs keep the saved list', async () => {
        const failures = [new TypeError('Failed to fetch'), { status: 500, body: '' }, { status: 404, body: 'nope' }, { body: '{"version":1' }, { body: '{"version":9,"videos":[]}' }, { url: 'https://evil.example/videos.json' }, { headers: { 'content-length': '999999' } }];
        for (const failure of failures) {
            let call = 0;
            const { api, requests, clock } = sandbox({ respond: () => (++call === 1 ? { body: JSON.stringify(example) } : failure) });
            const good = await api.load();
            clock.now += DAY + 1;
            const result = await api.load();
            assert.equal(requests.length, 2);
            assert.equal(result.stale, true, 'flagged as the saved list');
            same(ids(result.catalog), ids(good.catalog));
        }
    });

    await check('a failure with nothing saved reports an error, and the next automatic check waits a day', async () => {
        const { api, requests, clock } = sandbox({ respond: () => new TypeError('Failed to fetch') });
        const result = await api.load();
        assert.equal(result.catalog, null);
        clock.now += 60 * 60 * 1000;
        await api.load({ auto: true });
        assert.equal(requests.length, 1, 'background checks back off instead of retrying on every page');
        await api.load();
        assert.equal(requests.length, 2, 'opening the Videos page is the user asking, so it tries again');
        await api.load({ force: true });
        assert.equal(requests.length, 3, 'Retry always tries');
        clock.now += DAY;
        await api.load({ auto: true });
        assert.equal(requests.length, 4);
    });

    await check('offline: nothing is requested and the saved list is used', async () => {
        const store = new Map();
        await sandbox({ store }).api.load();
        const { api, requests, clock } = sandbox({ store, offline: true });
        clock.now += 2 * DAY;
        const result = await api.load();
        assert.equal(requests.length, 0);
        assert.equal(result.stale, true);
        assert(result.catalog.videos.length > 0);
        const empty = sandbox({ offline: true });
        assert.equal((await empty.api.load()).catalog, null);
        assert.equal(empty.requests.length, 0);
    });

    await check('a poisoned cache is validated again: foreign URLs and malformed entries never reach the page', async () => {
        const store = new Map();
        store.set('slimstat_videos_catalog', JSON.stringify({
            fetchedAt: NOW, checkedAt: NOW,
            catalog: { version: 1, categories: [], videos: [video({ id: 'ok' }), video({ id: 'evil', thumbnail: 'https://evil.example/x.png' }), video({ id: 'bad', slug: '"><img src=x>' })] },
        }));
        const { api, requests } = sandbox({ store });
        const result = await api.load();
        assert.equal(requests.length, 0, 'still fresh');
        same(ids(result.catalog), ['ok', 'evil']);
        assert.equal(result.catalog.videos[1].thumbnail, '');
        store.set('slimstat_videos_catalog', '{not json');
        assert.equal((await sandbox({ store }).api.load()).catalog.videos.length, example.videos.length, 'garbage is refetched');
        store.set('slimstat_videos_catalog', JSON.stringify({ fetchedAt: NOW + 10 * DAY, checkedAt: NOW + 10 * DAY, catalog: clone(example) }));
        const future = sandbox({ store });
        await future.api.load();
        assert.equal(future.requests.length, 1, 'a timestamp from the future is not fresh');
    });

    await check('storage that is missing or full degrades to a working, uncached load', async () => {
        for (const options of [{ noStorage: true }, { quota: true }]) {
            const { api, requests } = sandbox(options);
            assert.equal((await api.load()).catalog.videos.length, example.videos.length);
            api.setConsent({ load: true, auto: true });
            assert.equal(requests.length, 1);
        }
    });

    // ── Consent and the new-video marker ───────────────────────────────────────────────────
    await check('without the automatic-check opt-in, admin pages request nothing and mark nothing', async () => {
        const store = new Map();
        const seeded = sandbox({ store });
        seeded.api.setConsent({ load: true }); // listed the videos once, never asked for checks
        const { requests, dom } = sandbox({ store, now: NOW + 3 * DAY });
        await settle();
        assert.equal(requests.length, 0);
        assert.equal(menuBadges(dom).length, 0);
        assert.equal(sandbox().requests.length, 0, 'a user who never opted in requests nothing');
    });

    await check('first setup marks every existing video as seen: no notification', async () => {
        const store = new Map();
        const optIn = sandbox({ store });
        optIn.api.setConsent({ auto: true });
        await settle();
        assert.equal(optIn.requests.length, 0, 'opting in is not itself a request');
        const next = sandbox({ store });
        await settle();
        assert.equal(next.requests.length, 1, 'the check runs in the background on the next admin page');
        assert.equal(menuBadges(next.dom).length, 0);
        const state = next.api.getState();
        same(state.seen.slice().sort(), example.videos.map(v => v.id).sort());
        assert.match(state.since, /^\d{4}-\d{2}-\d{2}$/);
    });

    await check('new videos mark the menu until the Videos page has shown them', async () => {
        const store = new Map();
        const setup = sandbox({ store });
        setup.api.setConsent({ load: true, auto: true });
        setup.api.markSeen(await setup.api.load().then(r => r.catalog));
        const published = withVideos(video({ id: 'new-one' }), video({ id: 'new-two', slug: 'new-two' }));
        const later = sandbox({ store, now: NOW + DAY + 1, respond: () => ({ body: JSON.stringify(published) }) });
        await settle();
        assert.equal(later.requests.length, 1);
        same(later.api.newVideos(later.api.validate(clone(published))).sort(), ['new-one', 'new-two']);
        const [badge, sr, headBadge, pill] = menuBadges(later.dom);
        assert(badge && sr && headBadge && pill, 'badge, screen-reader text, flyout badge and submenu pill are added');
        assert.equal(headBadge.parentNode, later.dom.head, 'the folded flyout heading shows it too');
        assert.equal(headBadge.getAttribute('aria-hidden'), 'true');
        assert.equal(later.dom.head.children.indexOf(headBadge), 0);
        assert.equal(menuBadges(later.dom).filter(n => n.classList.contains('screen-reader-text')).length, 1, 'announced once');
        assert.equal(badge.parentNode, later.dom.name);
        assert.equal(later.dom.name.children.indexOf(badge), 0, 'the play icon sits immediately before the SlimStat label');
        assert.equal(badge.textContent.charAt(0), '▶');
        assert.equal(badge.getAttribute('aria-hidden'), 'true', 'the glyph is decorative');
        assert(sr.classList.contains('screen-reader-text') && /New video tutorials/.test(sr.textContent), 'and announced as text');
        assert.equal(pill.parentNode, later.dom.label, 'the Videos item says New, in the existing pill');
        // Another page load in the same state: no duplicate marker, no new request.
        const again = sandbox({ store, now: NOW + DAY + 2 });
        await settle();
        assert.equal(again.requests.length, 0);
        assert.equal(menuBadges(again.dom).length, 4);
        again.api.updateMenu(2);
        assert.equal(menuBadges(again.dom).length, 4, 'updating twice never duplicates the marker');
        // The Videos page loads and shows them.
        again.api.markSeen(again.api.validate(clone(published)));
        assert.equal(menuBadges(again.dom).length, 0, 'cleared once the page has shown them');
        const after = sandbox({ store, now: NOW + DAY + 3 });
        await settle();
        assert.equal(menuBadges(after.dom).length, 0, 'and stays cleared');
    });

    await check('editing an existing video, or adding an old one, is not a new video', async () => {
        const store = new Map();
        sandbox({ store }).api.setConsent({ auto: true });
        sandbox({ store }); // the next admin page takes the baseline
        await settle();
        const edited = clone(example);
        edited.videos[0].title = 'A new title';
        edited.videos[0].thumbnail = 'https://wp-slimstat.com/images/videos/new.webp';
        edited.videos[0].published = '2026-12-01';
        edited.videos.push(video({ id: 'backfilled', published: '2024-01-01' }));
        const { api } = sandbox({ store });
        same(api.newVideos(api.validate(edited)), []);
    });

    await check('pages without the SlimStat menu, and the Videos page itself, start no background check', async () => {
        const store = new Map();
        sandbox({ store }).api.setConsent({ auto: true });
        const bare = sandbox({ store, noMenu: true });
        const page = sandbox({ store, videosPage: {} });
        await settle();
        bare.api.updateMenu(3); // nothing to mark, nothing thrown
        assert.equal(bare.requests.length, 0, 'nothing to mark, so nothing to check');
        assert.equal(page.requests.length, 0, 'the Videos page runs its own load, not the background one');
    });

    await check('state is per site and per user', async () => {
        const store = new Map();
        sandbox({ store, scope: 'siteAuser1' }).api.setConsent({ load: true, auto: true });
        assert.equal(sandbox({ store, scope: 'siteBuser1' }).api.getState().auto, false);
        assert.equal(sandbox({ store, scope: 'siteAuser2' }).api.getState().load, false);
        assert.equal(sandbox({ store, scope: 'siteAuser1' }).api.getState().auto, true);
    });

    await check('forgetting the choice removes it, and the marker with it', async () => {
        const store = new Map();
        const s = sandbox({ store });
        s.api.setConsent({ load: true, auto: true });
        s.api.updateMenu(1);
        s.api.forget();
        same([s.api.getState().load, s.api.getState().auto], [false, false]);
        assert.equal(menuBadges(s.dom).length, 0);
        assert(!store.has('slimstat_videos_state_site1user1'));
    });

    await check('a tampered state record is read safely', () => {
        const store = new Map([['slimstat_videos_state_site1user1', JSON.stringify({ load: 'yes', auto: 1, since: '<b>', seen: [1, '"><x', 'ok-id', { a: 1 }] })]]);
        const state = sandbox({ store }).api.getState();
        assert.equal(state.load, false);
        assert.equal(state.auto, false);
        assert.equal(state.since, '');
        same(state.seen, ['ok-id']);
    });

    await check('a marker cleared in another tab clears here too', async () => {
        const store = new Map();
        const setup = sandbox({ store });
        setup.api.setConsent({ auto: true });
        setup.api.markSeen(setup.api.validate(clone(example)));
        const published = withVideos(video());
        store.set('slimstat_videos_catalog', JSON.stringify({ fetchedAt: NOW, checkedAt: NOW, catalog: published }));
        const tab = sandbox({ store });
        await settle();
        assert.equal(tab.requests.length, 0);
        assert.equal(menuBadges(tab.dom).length, 4);
        sandbox({ store }).api.markSeen(tab.api.validate(clone(published))); // the Videos page, in another tab
        (tab.listeners.storage || []).forEach(fn => fn({ key: 'slimstat_videos_state_site1user1' }));
        assert.equal(menuBadges(tab.dom).length, 0);
    });

    console.log(`video-catalog: ${passed} checks passed`);
})().catch(error => { console.error(error); process.exit(1); });
