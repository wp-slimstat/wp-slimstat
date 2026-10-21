/* Heatmaps page list: one REST read per range and device; search, sort and paging stay in the browser. GPL-2.0-or-later. */
(function () {
    'use strict';
    const config = window.SlimStatHeatmaps;
    const root = document.querySelector('.ss-hm');
    // The page viewer replaces the list on the same screen.
    if (!config || !root || !root.querySelector('.ss-hm-table') || !window.wp || !wp.apiFetch) return;
    const { __, _n, sprintf } = wp.i18n;
    const PER_PAGE = 25;
    const form = root.querySelector('.ss-hm-toolbar');
    const table = root.querySelector('.ss-hm-table');
    const body = table.tBodies[0];
    const empty = root.querySelector('.ss-hm-empty');
    const pager = root.querySelector('.ss-hm-pager');
    const updated = root.querySelector('.ss-hm-updated');
    // The global date picker's resolved range; picking another one reloads the page.
    const range = root.querySelector('.slimstat-date-range-input');
    const number = new Intl.NumberFormat(document.documentElement.lang || undefined, { maximumFractionDigits: 2 });
    // An empty cell says so instead of looking like a rendering gap.
    const NONE = '\u2014';
    const state = { rows: [], ever: true, sort: 'clicks', dir: -1, page: 0, updated: 0, highlight: new URLSearchParams(location.search).get('highlight') || '' };
    let request = 0, pending = false, reload = false;
    let previews = 0, searchTimer;

    function el(tag, attrs, children) {
        const node = document.createElement(tag);
        Object.keys(attrs || {}).forEach((key) => node.setAttribute(key, attrs[key]));
        (children || []).forEach((child) => node.append(child));
        return node;
    }

    const sortValue = {
        page: (r) => r.page,
        cpp: (r) => (r.pageviews ? r.clicks / r.pageviews : 0),
        devices: (r) => (r.clicks ? r.devices[2] / r.clicks : 0),
        scroll: (r) => (null === r.scroll ? -1 : r.scroll),
        dead: (r) => (null === r.dead ? -1 : r.dead + r.rage),
        full: (r) => (r.full ? 1 : 0),
    };

    function visibleRows() {
        const q = form.q.value.trim().toLowerCase();
        const value = sortValue[state.sort] || ((r) => r[state.sort]);
        return state.rows
            .filter((r) => !q || r.page.toLowerCase().includes(q))
            .sort((a, b) => {
                const x = value(a);
                const y = value(b);
                return (x < y ? -1 : x > y ? 1 : 0) * state.dir || b.clicks - a.clicks;
            });
    }

    // Free: the page's most clicked links and buttons open under its row; again closes them.
    function openRow(row, tr) {
        if (row.url) {
            location.href = row.url;
            return;
        }
        const template = document.getElementById('ss-hm-preview');
        if (!template) {
            const dialog = document.getElementById('ss-hm-locked');
            if (dialog) dialog.showModal();
            return;
        }
        const button = tr.querySelector('.ss-hm-locked');
        const open = 'true' === button.getAttribute('aria-expanded');
        body.querySelectorAll('.ss-hm-preview-row').forEach((node) => node.remove());
        body.querySelectorAll('.ss-hm-locked[aria-expanded="true"]').forEach((node) => node.setAttribute('aria-expanded', 'false'));
        if (open) return;

        const panel = template.content.firstElementChild.cloneNode(true);
        const heading = panel.querySelector('[data-heading]');
        const list = panel.querySelector('ol');
        panel.id = 'ss-hm-preview-' + ++previews;
        heading.id = panel.id + '-title';
        panel.setAttribute('aria-labelledby', heading.id);
        /* translators: %s: page address, e.g. /pricing */
        heading.textContent = sprintf(__('Most clicked links and buttons on %s', 'wp-slimstat'), row.page);
        tr.after(el('tr', { class: 'ss-hm-preview-row' }, [el('td', { colspan: tr.cells.length }, [panel])]));
        button.setAttribute('aria-controls', panel.id);
        button.setAttribute('aria-expanded', 'true');

        const params = new URLSearchParams({ page: row.page, device: form.device.value, from: range.dataset.start, to: range.dataset.end });
        wp.apiFetch({ path: '/slimstat/v1/heatmap/targets?' + params })
            .then((targets) => {
                if (!targets.length) {
                    list.replaceWith(el('p', {}, [__('These clicks did not record where on the page they landed.', 'wp-slimstat')]));
                    return;
                }
                list.replaceChildren(...targets.map((target) => el('li', {}, [
                    el('span', { class: 'ss-hm-target' }, [target.label || __('Link or button without text', 'wp-slimstat')]),
                    el('span', { class: 'ss-hm-target-clicks' }, [sprintf(
                        /* translators: %s: number of clicks */
                        _n('%s click', '%s clicks', target.clicks, 'wp-slimstat'), number.format(target.clicks)
                    )]),
                ])));
                list.setAttribute('aria-busy', 'false');
            })
            .catch((error) => list.replaceWith(el('p', {}, [(error && error.message) || __('Heatmap data could not be loaded. Try a shorter date range, then retry.', 'wp-slimstat')])));
    }

    function devices(row) {
        if (row.devices === null) return el('span', {title: __('Unlock device insights with Pro', 'wp-slimstat')}, [el('span', {class: 'ss-hm-device-locked', 'aria-hidden': 'true'}, ['▬▬ ▬▬ ▬▬']), el('span', {class: 'screen-reader-text'}, [__('Device insights: Pro', 'wp-slimstat')])]);
        const names = [__('Desktop', 'wp-slimstat'), __('Tablet', 'wp-slimstat'), __('Mobile', 'wp-slimstat'), __('Unknown', 'wp-slimstat')];
        const counts = [...row.devices, Math.max(0, row.clicks - row.devices.reduce((sum, n) => sum + n, 0))];
        const label = counts.map((n, i) => names[i] + ' ' + number.format(row.clicks ? 100 * n / row.clicks : 0) + '%').join(', ');
        const bar = el('span', { class: 'ss-hm-devices', 'aria-hidden': 'true' });
        counts.forEach((n, i) => { if (n) bar.append(el('span', { class: 'ss-hm-device-' + i, style: 'flex-grow:' + n })); });
        return el('span', { class: 'ss-hm-device', tabindex: '0', title: __('Percentage of all recorded clicks in the selected date range and device filter. Unknown means no usable viewport width.', 'wp-slimstat') }, [bar, el('span', {}, [label])]);
    }

    function rowNode(row, shown) {
        const action = row.url
            ? el('a', { class: 'button button-small', href: row.url }, [__('View heatmap', 'wp-slimstat')])
            : el('button', { type: 'button', class: 'button button-small ss-hm-locked', title: __('Available in SlimStat Pro', 'wp-slimstat'), ...('free' === config.mode ? { 'aria-expanded': 'false' } : {}) }, [
                  el('span', { class: 'dashicons dashicons-lock', 'aria-hidden': 'true' }),
                  __('View heatmap', 'wp-slimstat'),
                  el('span', { class: 'screen-reader-text' }, [__('(Pro)', 'wp-slimstat')]),
              ]);
        if (!row.url) action.addEventListener('click', () => openRow(row, tr));
        // No post title (archives, search, 404): the address is the name.
        let path = row.page;
        try { path = decodeURI(path); } catch (_) { /* Keep malformed paths readable without throwing. */ }
        if (path === '/') path = __('Homepage', 'wp-slimstat');
        const page = el('td', { class: 'ss-hm-page' }, row.title ? [el('strong', {}, [row.title]), el('span', { class: 'ss-hm-path' }, [row.page])] : [el('strong', { title: row.page }, [path])]);
        const dead = null === row.dead ? NONE : sprintf(
            /* translators: 1: dead clicks, 2: rage clicks */
            __('%1$s dead · %2$s rage', 'wp-slimstat'), number.format(row.dead), number.format(row.rage)
        );
        const tr = el('tr', { class: row.page === state.highlight ? 'is-highlighted' : '' }, [
            page,
            el('td', { class: 'num ss-hm-nowrap' }, [
                number.format(row.clicks),
                ...(row.clicks < 30 ? [el('small', { class: 'ss-hm-low-data', tabindex: '0', title: __('Fewer than 30 recorded clicks. Use this as an early indication, not statistical assurance.', 'wp-slimstat') }, [__('Low data', 'wp-slimstat')])] : []),
            ]),
            el('td', { class: 'num' }, [number.format(row.pageviews)]),
            el('td', { class: 'num' }, [row.pageviews ? number.format(row.clicks / row.pageviews) : NONE]),
            el('td', {}, [devices(row)]),
            shown.scroll && el('td', { class: 'num' }, [null === row.scroll ? NONE : row.scroll + '%']),
            shown.dead && el('td', { class: 'ss-hm-nowrap' }, [dead]),
            el('td', { class: 'ss-hm-nowrap' }, [row.lastText]),
            shown.full && el('td', {}, [el('span', { class: 'ss-hm-badge' + (row.full ? ' is-full' : '') }, [row.full ? __('All clicks + scroll', 'wp-slimstat') : __('Link and button clicks', 'wp-slimstat')])]),
            el('td', { class: 'ss-hm-action' }, [action]),
        ].filter(Boolean));
        // The whole row opens it too; the button stays the keyboard path.
        tr.addEventListener('click', (e) => {
            if (!e.target.closest('a,button')) openRow(row, tr);
        });
        return tr;
    }

    function showEmpty(text, buttons, art) {
        const copy = el('div', {}, [el('p', {}, [text])]);
        (buttons || []).forEach(([label, handler]) => {
            const button = el('button', { type: 'button', class: 'button' }, [label]);
            button.addEventListener('click', handler);
            copy.append(button);
        });
        empty.replaceChildren(...(art ? [document.getElementById('ss-hm-demo').content.cloneNode(true)] : []), copy);
        empty.hidden = false;
    }

    function render() {
        const rows = visibleRows();
        const deviceSort = table.tHead.querySelector('[data-sort=devices] button');
        if (deviceSort) deviceSort.disabled = config.mode === 'free';
        const pages = Math.max(1, Math.ceil(rows.length / PER_PAGE));
        state.page = Math.min(state.page, pages - 1);
        // Columns only full tracking fills stay hidden until some row in the range has them.
        // Without Pro there is one tracking level, so no Tracking column to tell them apart.
        const shown = {
            scroll: state.rows.some((r) => null !== r.scroll),
            dead: state.rows.some((r) => null !== r.dead),
            full: 'pro' === config.mode && state.rows.some((r) => r.full),
        };
        ['scroll', 'dead', 'full'].forEach((key) => {
            table.tHead.querySelector('th[data-sort="' + key + '"]').hidden = !shown[key];
        });
        body.replaceChildren(...rows.slice(state.page * PER_PAGE, (state.page + 1) * PER_PAGE).map((row) => rowNode(row, shown)));
        if (state.locked && state.total > rows.length) {
            const preview = el('div', {class: 'slimstat-locked-rows', 'aria-hidden': 'true'}, [el('span'), el('span')]);
            body.append(el('tr', {class: 'ss-hm-locked-preview'}, [el('td', {colspan: '10'}, [preview,
                /* translators: %s: number of additional pages in the selected range. */
                el('p', {class: 'slimstat-preview-caption'}, [sprintf(_n('%s additional page. Illustrative preview; unlock with Pro.', '%s additional pages. Illustrative preview; unlock with Pro.', state.total - rows.length, 'wp-slimstat'), number.format(state.total - rows.length))])])]));
        }
        table.setAttribute('aria-busy', 'false');
        table.hidden = !rows.length;
        empty.hidden = true;
        const remove = root.querySelector('.ss-hm-delete');
        if (remove) remove.hidden = !state.ever;
        if (!state.ever) {
            showEmpty(
                __('No clicks recorded on this site yet. SlimStat records link and button clicks on every tracked page. Open your homepage, click a link, then select Refresh.', 'wp-slimstat'),
                [[__('Open your homepage', 'wp-slimstat'), () => window.open(config.home, '_blank', 'noopener')]],
                true
            );
        } else if (!state.rows.length) {
            const buttons = [];
            if (form.device.value) buttons.push([__('Show all devices', 'wp-slimstat'), () => { form.device.value = ''; load(); }]);
            if ('last_90_days' !== new URLSearchParams(location.search).get('type')) {
                buttons.push([__('Use last 90 days', 'wp-slimstat'), () => {
                    const url = new URL(location.href);
                    ['from', 'to'].forEach((key) => url.searchParams.delete(key));
                    url.searchParams.set('type', 'last_90_days');
                    location.href = url;
                }]);
            }
            showEmpty(
                form.device.value
                    ? __('No clicks recorded on this device in this date range.', 'wp-slimstat')
                    : __('No clicks recorded in this date range. SlimStat records link and button clicks on every tracked page; a page appears here after its first click.', 'wp-slimstat'),
                buttons,
                true
            );
        } else if (!rows.length) {
            /* translators: %s: the search text */
            showEmpty(sprintf(__('No pages match "%s". Search checks page addresses, like /pricing.', 'wp-slimstat'), form.q.value.trim()));
        }
        pager.hidden = pages < 2;
        /* translators: 1: current page number, 2: total pages */
        pager.querySelector('span').textContent = sprintf(__('Page %1$d of %2$d', 'wp-slimstat'), state.page + 1, pages);
        pager.querySelector('[data-step="-1"]').disabled = 0 === state.page;
        pager.querySelector('[data-step="1"]').disabled = state.page >= pages - 1;
        const minutes = Math.floor((Date.now() / 1000 - state.updated) / 60);
        updated.textContent = state.updated ? (minutes < 1 ? __('Updated just now', 'wp-slimstat') : sprintf(
            /* translators: %d: minutes since the list was computed */
            _n('Updated %d min ago', 'Updated %d min ago', minutes, 'wp-slimstat'), minutes
        )) : '';
    }

    function load(refresh, automatic = false) {
        if (pending) { if (!automatic) { reload = true; ++request; } return; }
        pending = true;
        const scroll = { x: window.scrollX, y: window.scrollY };
        const id = ++request;
        const params = new URLSearchParams({ device: form.device.value, from: range.dataset.start, to: range.dataset.end });
        if (refresh) params.set('refresh', '1');
        if (config.mode === 'free') { params.set('q', form.q.value.trim()); params.set('sort', state.sort); params.set('dir', state.dir); }
        table.setAttribute('aria-busy', 'true');
        table.classList.add('is-loading');
        wp.apiFetch({ path: config.route + '?' + params })
            .then((data) => {
                if (id !== request) return;
                state.rows = data.rows; state.total = data.total; state.locked = data.locked;
                state.ever = false !== data.ever;
                state.updated = data.updated;
                if (state.highlight && !automatic) {
                    const index = visibleRows().findIndex((r) => r.page === state.highlight);
                    if (index >= 0) state.page = Math.floor(index / PER_PAGE);
                }
                render();
                const mark = body.querySelector('.is-highlighted');
                if (mark && !automatic) mark.scrollIntoView({ block: 'center' });
                if (automatic) window.scrollTo(scroll.x, scroll.y);
            })
            .catch((error) => {
                if (id !== request) return;
                table.hidden = true;
                pager.hidden = true;
                showEmpty((error && error.message) || __('Heatmap data could not be loaded. Try a shorter date range, then retry.', 'wp-slimstat'), [[__('Retry', 'wp-slimstat'), () => load(true)]]);
            })
            .finally(() => {
                if (id === request) { table.classList.remove('is-loading'); table.setAttribute('aria-busy', 'false'); }
                pending = false;
                if (reload) { reload = false; load(true); }
            });
    }

    form.addEventListener('change', (e) => {
        if ('device' !== e.target.name) return;
        state.page = 0;
        load();
    });
    form.q.addEventListener('input', () => {
        state.page = 0;
        if (config.mode === 'free') { clearTimeout(searchTimer); searchTimer = setTimeout(load, 300); return; }
        render();
    });
    root.querySelector('.ss-hm-refresh').addEventListener('click', () => load(true));
    pager.addEventListener('click', (e) => {
        const step = e.target.closest('[data-step]');
        if (!step) return;
        state.page += Number(step.dataset.step);
        render();
        table.scrollIntoView({ block: 'start' });
    });
    table.tHead.addEventListener('click', (e) => {
        const th = e.target.closest('th[data-sort]');
        if (!th) return;
        const key = th.dataset.sort;
        state.dir = key === state.sort ? -state.dir : 'page' === key ? 1 : -1;
        if (config.mode === 'free' && key === 'devices') return;
        state.sort = key;
        table.tHead.querySelectorAll('th[data-sort]').forEach((cell) => {
            if (cell === th) cell.setAttribute('aria-sort', state.dir > 0 ? 'ascending' : 'descending');
            else cell.removeAttribute('aria-sort');
        });
        if (config.mode === 'free') load(); else render();
    });
    root.querySelectorAll('[data-dialog]').forEach((button) => {
        button.addEventListener('click', () => {
            const dialog = document.getElementById(button.dataset.dialog);
            // Safari does not focus a clicked button, so native focus restoration would land on <body>.
            dialog.addEventListener('close', () => button.focus(), { once: true });
            dialog.showModal();
        });
    });

    const confirmation = root.querySelector('[name="heatmap_delete_confirmation"]');
    if (confirmation) {
        const submit = confirmation.form.querySelector('[data-confirm-delete]');
        confirmation.addEventListener('input', () => { submit.disabled = confirmation.value !== 'DELETE'; });
    }
    // Polling reads the server's one-minute cache, so open tabs share one aggregation; only Refresh forces one.
    function refreshVisible() {
        if (!document.hidden && !root.querySelector('dialog[open]') && Date.now() / 1000 - state.updated >= 60) load(false, true);
    }
    setInterval(refreshVisible, 60000);
    document.addEventListener('visibilitychange', refreshVisible);
    load();
})();
