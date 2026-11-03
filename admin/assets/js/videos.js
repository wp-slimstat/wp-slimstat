/*
 * SlimStat > Videos: renders the validated catalog as one row per category. GPL-2.0-or-later.
 *
 * Every state is server-rendered in admin/view/videos.php and only shown or hidden here. Cards are
 * built with DOM APIs from the catalog that video-catalog.js validated: titles are textContent,
 * thumbnails are https URLs on wp-slimstat.com, and each link is built from the video's slug.
 * Videos open on wp-slimstat.com in a new tab; nothing is embedded here.
 */
(function () {
    'use strict';
    const api = window.SlimStatVideos;
    const copy = window.SlimStatVideosPage;
    const root = document.getElementById('slimstat-videos');
    if (!api || !copy || !root) return;
    const el = api.el;
    const newPill = window.SlimStatVideoConfig.newPill;

    const rows = document.getElementById('ss-videos-rows');
    const status = document.getElementById('ss-videos-status');
    const stale = document.getElementById('ss-videos-stale');
    const errorTitle = document.getElementById('ss-videos-error-title');
    const failed = errorTitle.textContent;
    const auto = document.getElementById('ss-videos-auto');
    let attempt = 0;

    function show(state) {
        root.querySelectorAll('[data-show]').forEach((node) => {
            node.hidden = !node.dataset.show.split(' ').includes(state);
        });
    }

    function card(video, isNew) {
        const link = el('a', 'ss-videos-card');
        link.href = api.watchUrl(video.slug);
        link.target = '_blank';
        link.rel = 'noopener noreferrer';

        const thumb = el('span', 'ss-videos-thumb');
        if (video.thumbnail) {
            const img = el('img');
            // loading before src, or the browser may start the request eagerly.
            img.loading = 'lazy';
            img.decoding = 'async';
            img.referrerPolicy = 'no-referrer';
            img.alt = '';
            img.width = 320;
            img.height = 180;
            img.addEventListener('error', () => {
                img.remove();
                thumb.classList.add('is-missing');
            });
            img.src = video.thumbnail;
            thumb.appendChild(img);
        } else {
            thumb.classList.add('is-missing');
        }
        thumb.appendChild(el('span', 'ss-videos-play'));
        if (video.duration) thumb.appendChild(el('span', 'ss-videos-duration', video.duration));
        link.appendChild(thumb);

        const body = el('span', 'ss-videos-body');
        const title = el('span', 'ss-videos-title', video.title);
        if (isNew) body.appendChild(el('span', 'slimstat-menu-new-badge', newPill));
        body.appendChild(title);
        if (video.description) body.appendChild(el('span', 'ss-videos-description', video.description));
        body.appendChild(el('span', 'screen-reader-text', ' ' + copy.newTab));
        link.appendChild(body);

        const item = el('li', 'ss-videos-item');
        item.appendChild(link);
        return item;
    }

    function render(catalog, fresh) {
        rows.replaceChildren();
        api.groups(catalog, copy.categories, copy.more).forEach((group, i) => {
            const section = el('section', 'ss-videos-row');
            const heading = el('h2', 'ss-videos-row__title', group.title);
            heading.id = 'ss-videos-row-' + i;
            heading.tabIndex = -1;
            section.setAttribute('aria-labelledby', heading.id);
            const list = el('ul', 'ss-videos-list');
            list.setAttribute('role', 'list');
            group.videos.forEach((video) => list.appendChild(card(video, fresh.has(video.id))));
            section.append(heading, list);
            rows.appendChild(section);
        });
    }

    function start(force, focus) {
        const mine = ++attempt;
        show('loading');
        stale.hidden = true;
        status.textContent = copy.loading;
        api.load({ force }).then((result) => {
            if (mine !== attempt) return;
            if (!result.catalog) {
                errorTitle.textContent = result.offline ? copy.offline : failed;
                status.textContent = errorTitle.textContent;
                show('error');
                if (focus) document.getElementById('ss-videos-retry').focus();
                return;
            }
            // Captured before markSeen() records this list as seen.
            render(result.catalog, new Set(api.newVideos(result.catalog)));
            api.markSeen(result.catalog);
            show(result.catalog.videos.length ? 'ready' : 'empty');
            stale.hidden = !result.stale;
            status.textContent = copy.loaded;
            const heading = rows.querySelector('h2');
            if (focus && heading) heading.focus();
        });
    }

    document.getElementById('ss-videos-show').addEventListener('click', () => {
        api.setConsent({ load: true });
        start(false, true);
    });
    document.getElementById('ss-videos-retry').addEventListener('click', () => start(true, true));
    document.getElementById('ss-videos-forget').addEventListener('click', () => {
        attempt++;
        api.forget();
        rows.replaceChildren();
        auto.checked = false;
        stale.hidden = true;
        status.textContent = '';
        show('consent');
        document.getElementById('ss-videos-show').focus();
    });
    auto.addEventListener('change', () => api.setConsent({ auto: auto.checked }));

    const state = api.getState();
    auto.checked = state.auto;
    if (state.load) {
        start(false, false);
    } else {
        show('consent');
    }
})();
