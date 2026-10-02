/* Ecommerce uses the native report endpoint and bundled Chart.js. GPL-2.0-or-later. */
(function ($) {
    'use strict';
    const { __, sprintf } = window.wpSlimstatI18n;
    let chart, request, requestKey, feedbackTimer, serial = 0, reportHashHandled = false;
    const state = { metric: 'net', interval: 'auto', compare: true, tabs: {}, expanded: {}, sort: {} };
    const root = () => document.querySelector('[data-ecommerce]');
    const announce = (message, visible = false) => {
        const target = document.querySelector('.ss-ec-feedback');
        if (target) {
            target.textContent = message;
            if (visible) {
                target.classList.add('ss-ec-toast'); target.classList.remove('screen-reader-text');
                clearTimeout(feedbackTimer);
                feedbackTimer = setTimeout(() => { target.classList.remove('ss-ec-toast'); target.classList.add('screen-reader-text'); }, 4000);
            }
        }
        const status = document.querySelector('.ss-ec-refresh-status');
        if (status) status.textContent = message;
    };
    function selectTab(tab, focus) {
        const card = tab.closest('.ss-ec-card');
        card.querySelectorAll('[role=tab]').forEach(item => {
            const active = item === tab;
            item.setAttribute('aria-selected', String(active)); item.tabIndex = active ? 0 : -1;
            document.getElementById(item.getAttribute('aria-controls')).hidden = !active;
        });
        state.tabs[card.getAttribute('aria-label')] = tab.id;
        if (focus) tab.focus();
    }
    function expand(panel, open, focus) {
        panel.querySelector('[data-expand]').setAttribute('aria-expanded', String(open));
        panel.querySelector('.ss-ec-report-detail').hidden = !open;
        panel.querySelector('[data-expand]').textContent = open ? __('Close report', 'wp-slimstat') : __('View report', 'wp-slimstat');
        state.expanded[panel.dataset.dimension] = open;
        rank(panel);
        if (focus) panel.querySelector('[data-expand]').focus();
    }
    function rank(panel) {
        const metric = panel.querySelector('[data-rank-metric]').value;
        const ascending = panel.querySelector('[data-sort]').getAttribute('aria-pressed') === 'true';
        const rows = Array.from(panel.querySelectorAll('.ss-ec-rankings > li'));
        const value = row => Number(metric === 'orders' ? row.dataset.rankOrders : row.dataset.rankValue);
        const max = Math.max(0, ...rows.map(row => Math.abs(value(row))));
        rows.sort((a, b) => (value(b) - value(a)) * (ascending ? -1 : 1));
        rows.forEach((row, index) => {
            row.parentElement.append(row);
            const detail = panel.querySelector('tr[data-rank-index="' + row.dataset.rankIndex + '"]');
            if (detail) detail.parentElement.append(detail);
            row.hidden = index >= 5 && !state.expanded[panel.dataset.dimension];
            row.querySelector('.ss-ec-rank-value').textContent = metric === 'orders' ? Number(row.dataset.rankOrders).toLocaleString(document.documentElement.lang) : row.dataset.rankMoney;
            row.querySelector('.ss-ec-rank-bar').style.setProperty('--ss-ec-share', (max ? 100 * Math.abs(value(row)) / max : 0) + '%');
            row.classList.toggle('is-negative', value(row) < 0);
        });
        state.sort[panel.dataset.dimension] = { metric, ascending };
    }
    function pointNote(point) {
        return [point.partial ? __('Partial interval', 'wp-slimstat') : '', point.provisional ? __('Provisional', 'wp-slimstat') : ''].filter(Boolean).join(' · ');
    }
    function draw() {
        const dashboard = root(); if (!dashboard) return;
        const performance = dashboard.querySelector('[data-series]'); if (!performance || !window.Chart) return;
        const series = JSON.parse(performance.dataset.series);
        const metric = state.metric;
        const button = dashboard.querySelector('[data-chart-metric="' + metric + '"]');
        dashboard.querySelectorAll('[data-chart-metric]').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
        const label = button.querySelector('span').textContent;
        dashboard.querySelector('[data-chart-title]').textContent = label;
        dashboard.querySelector('[data-value-heading]').textContent = label;
        dashboard.querySelector('[data-chart-description]').textContent = button.dataset.description;
        dashboard.querySelector('.ss-ec-chart-unit').textContent = ['net', 'aov'].includes(metric) ? performance.dataset.currency : (metric === 'rate' ? '%' : '');
        document.querySelectorAll('[data-compare]').forEach(input => { input.checked = state.compare; });
        dashboard.querySelectorAll('[data-comparison-content]').forEach(el => { el.hidden = !state.compare; });
        dashboard.querySelectorAll('[data-chart-metric]:not([data-chart-metric=rate]) small').forEach(el => { el.style.visibility = state.compare ? 'visible' : 'hidden'; });
        const tbody = dashboard.querySelector('[data-chart-table]');
        tbody.replaceChildren();
        series.current.forEach((point, i) => {
            const before = series.previous[i], row = document.createElement('tr');
            const entries = [point.label + (pointNote(point) ? ' · ' + pointNote(point) : ''), point.formatted[metric]];
            if (state.compare) entries.push(before.label + (pointNote(before) ? ' · ' + pointNote(before) : ''), before.formatted[metric]);
            entries.forEach((text, col) => {
                const cell = document.createElement(col ? 'td' : 'th');
                if (!col) cell.scope = 'row'; cell.textContent = text; row.append(cell);
            });
            tbody.append(row);
        });
        const colors = getComputedStyle(dashboard);
        const blue = colors.getPropertyValue('--ss-info-fg').trim();
        const muted = colors.getPropertyValue('--ss-text-meta').trim();
        const text = colors.getPropertyValue('--ss-text-muted').trim();
        const line = ['rate', 'aov'].includes(metric);
        const datasets = [{
            label: __('Selected period', 'wp-slimstat'), type: line ? 'line' : 'bar',
            data: series.current.map(p => p[metric]), borderColor: blue, backgroundColor: line ? blue + '12' : series.current.map(p => p.partial ? blue + 'cc' : blue),
            borderWidth: line ? 2 : 0, borderRadius: 3, maxBarThickness: 38, fill: line, tension: 0,
            pointRadius: series.current.map(p => p.partial || series.current.length < 3 ? 4 : 0), pointStyle: series.current.map(p => p.partial ? 'triangle' : 'circle'), pointHitRadius: 12, spanGaps: false,
        }];
        if (state.compare) datasets.push({
            label: __('Previous period', 'wp-slimstat'), type: 'line', data: series.previous.map(p => p[metric]),
            borderColor: muted, backgroundColor: muted, borderDash: [5, 4], borderWidth: 2,
            pointBackgroundColor: colors.getPropertyValue('--ss-surface').trim(),
            pointRadius: series.previous.length < 3 ? 4 : 0, pointHitRadius: 12, tension: 0, spanGaps: false, order: -1,
        });
        dashboard.querySelector('[data-chart-empty]').hidden = series.current.some(p => p[metric] !== null);
        if (chart) chart.destroy();
        chart = new Chart(performance.querySelector('canvas'), {
            type: 'bar', data: { labels: series.current.map(p => p.short), datasets },
            options: {
                responsive: true, maintainAspectRatio: false,
                animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : { duration: 180 },
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: { display: false },
                    tooltip: { padding: 12, displayColors: true, callbacks: {
                        title: items => items.length ? series.current[items[0].dataIndex].label : '',
                        label: item => {
                            const point = (item.datasetIndex ? series.previous : series.current)[item.dataIndex];
                            return item.dataset.label + ': ' + point.formatted[metric];
                        },
                        afterBody: items => {
                            if (!items.length) return [];
                            const index = items[0].dataIndex, point = series.current[index];
                            const notes = [pointNote(point)];
                            if (metric === 'rate') notes.push(sprintf(__('%1$s buying / %2$s eligible visits', 'wp-slimstat'), point.buyers, point.visits));
                            if (state.compare) notes.push(series.previous[index].label, pointNote(series.previous[index]));
                            return notes.filter(Boolean);
                        },
                    } },
                },
                scales: {
                    x: { grid: { display: false }, border: { display: false }, ticks: { color: text, maxTicksLimit: 8, maxRotation: 0, font: { size: 11 } } },
                    y: { beginAtZero: true, suggestedMax: metric === 'rate' ? 1 : undefined, border: { display: false }, grid: { color: colors.getPropertyValue('--ss-border-soft').trim() }, ticks: { color: text, maxTicksLimit: 5, precision: metric === 'orders' ? 0 : undefined, callback: v => Number(v).toLocaleString(document.documentElement.lang, { maximumFractionDigits: metric === 'rate' ? 2 : 0 }) + (metric === 'rate' ? '%' : ''), font: { size: 11 } } },
                },
            },
        });
    }
    function toolbar() {
        if (new URL(location.href).searchParams.get('page') !== 'slimview7') return;
        const form = document.getElementById('slimstat-filters-form'); if (!form) return;
        let controls = document.querySelector('.ss-ec-controls');
        if (!controls) {
            controls = document.createElement('div'); controls.className = 'ss-ec-controls';
            form.before(controls); controls.append(form);
            const chips = document.getElementById('slimstat-current-filters'); if (chips) controls.append(chips);
            const filters = document.getElementById('slimstat-filters'); filters.hidden = true;
            const toggle = document.createElement('button'); toggle.type = 'button'; toggle.className = 'button';
            toggle.textContent = __('Filters', 'wp-slimstat'); toggle.setAttribute('aria-expanded', 'false'); toggle.setAttribute('aria-controls', filters.id);
            toggle.addEventListener('click', () => { filters.hidden = !filters.hidden; toggle.setAttribute('aria-expanded', String(!filters.hidden)); if (!filters.hidden) filters.querySelector('select').focus(); });
            form.append(toggle);
            const saved = document.getElementById('slimstat-load-saved-filters'); if (saved) form.append(saved);
            const status = document.createElement('span'); status.className = 'ss-ec-refresh-status'; status.textContent = __('Ready to refresh', 'wp-slimstat'); form.append(status);
            document.getElementById('slimstat-filter-name').setAttribute('aria-label', __('Filter dimension', 'wp-slimstat'));
            document.getElementById('slimstat-filter-operator').setAttribute('aria-label', __('Filter operator', 'wp-slimstat'));
            document.getElementById('slimstat-filter-value').setAttribute('aria-label', __('Filter value', 'wp-slimstat'));
        }
        const compare = root() && root().querySelector('.ss-ec-compare');
        if (compare) { const old = controls.querySelector('.ss-ec-compare'); if (old) old.remove(); form.append(compare); }
    }
    function openLinkedReport() {
        if (reportHashHandled || !window.location.hash.startsWith('#ss-ec-panel-')) return;
        const panel = document.getElementById(window.location.hash.slice(1));
        if (!panel || !root()?.contains(panel) || !panel.matches('.ss-ec-report')) return;
        reportHashHandled = true;
        selectTab(document.getElementById(panel.getAttribute('aria-labelledby')), false);
        panel.focus();
    }
    window.addEventListener('hashchange', () => { reportHashHandled = false; openLinkedReport(); });
    function init() {
        toolbar(); const dashboard = root(); if (!dashboard) return;
        const interval = dashboard.querySelector('[data-interval]'); if (interval) state.interval = interval.value;
        dashboard.querySelectorAll('.ss-ec-card').forEach(card => {
            const tab = document.getElementById(state.tabs[card.getAttribute('aria-label')]);
            if (tab && card.contains(tab)) selectTab(tab, false);
        });
        dashboard.querySelectorAll('.ss-ec-report').forEach(panel => {
            const sort = state.sort[panel.dataset.dimension];
            if (sort) { panel.querySelector('[data-rank-metric]').value = sort.metric; panel.querySelector('[data-sort]').setAttribute('aria-pressed', String(sort.ascending)); }
            expand(panel, Boolean(state.expanded[panel.dataset.dimension]), false);
        });
        draw();
        openLinkedReport();
    }
    function failedRefresh(inside) {
        if (!root()) {
            inside.replaceChildren();
            const block = document.createElement('div'); block.className = 'ss-ec ss-ec-state'; block.setAttribute('data-ecommerce', '');
            const feedback = document.createElement('p'); feedback.className = 'ss-ec-feedback'; feedback.setAttribute('role', 'alert');
            const retry = document.createElement('button'); retry.type = 'button'; retry.className = 'button refresh'; retry.textContent = __('Retry', 'wp-slimstat');
            block.append(feedback, retry); inside.append(block);
        }
        const series = root().querySelector('[data-series]');
        if (series) {
            state.interval = JSON.parse(series.dataset.series).interval;
            root().querySelector('[data-interval]').value = state.interval;
        }
        clearTimeout(feedbackTimer);
        root().querySelector('.ss-ec-feedback')?.classList.remove('screen-reader-text', 'ss-ec-toast');
        announce(__('Could not refresh. Any displayed figures are from the previous response. Retry to get current data.', 'wp-slimstat'));
    }
    window.SlimStatEcommerce = {
        refresh: function () {
            const payload = { action: 'slimstat_load_report', security: $('#meta-box-order-nonce').val(), page: window.SlimStatAdmin.get_current_tab(), report_id: 'slim_p10_01', ecommerce_interval: state.interval };
            $('#slimstat-filters-form .slimstat-post-filter').each(function () { payload[this.name] = this.value; });
            if (window.SlimStatAdminParams.network_scope_nonce) { payload.slimstat_network_scope = 1; payload.slimstat_network_nonce = window.SlimStatAdminParams.network_scope_nonce; }
            const key = JSON.stringify(payload);
            if (request && key === requestKey) return request.then(function () {}, function () {});
            const token = ++serial;
            if (request) request.abort();
            requestKey = key;
            const inside = document.querySelector('#slim_p10_01 .inside');
            const active = document.activeElement;
            const focusInterval = active && active.matches('[data-interval]');
            const focusRefresh = active && active.matches('.ss-ec .refresh');
            const manual = Boolean(root());
            if (root()) { root().classList.add('is-loading'); root().setAttribute('aria-busy', 'true'); announce(__('Updating reports…', 'wp-slimstat')); }
            else { inside.innerHTML = '<div class="ss-ec-skeleton" aria-hidden="true"><div></div><div></div><div></div></div>'; inside.setAttribute('aria-busy', 'true'); }
            request = $.ajax({ method: 'POST', url: window.ajaxurl, data: payload, timeout: 45000 })
                .done(response => {
                    if (token !== serial) return;
                    const wrapper = document.createElement('div'); wrapper.innerHTML = response;
                    if (!wrapper.querySelector('[data-ecommerce]')) { failedRefresh(inside); return; }
                    if (chart) { chart.destroy(); chart = null; }
                    inside.replaceChildren(...wrapper.childNodes); init();
                    announce(__('Reports refreshed', 'wp-slimstat'), manual);
                    if (focusInterval) root().querySelector('[data-interval]')?.focus();
                    if (focusRefresh) root().querySelector('.refresh')?.focus();
                })
                .fail((_xhr, reason) => {
                    if (token !== serial || reason === 'abort') return;
                    failedRefresh(inside);
                })
                .always(() => {
                    if (token !== serial) return;
                    request = null; inside.removeAttribute('aria-busy');
                    if (root()) { root().classList.remove('is-loading'); root().removeAttribute('aria-busy'); }
                });
            // The native report queue continues after a failed report. Keep its
            // settled-promise contract while retaining the abortable request here.
            return request.then(function () {}, function () {});
        },
    };
    document.addEventListener('click', event => {
        const target = event.target.closest('button,a'); if (!target || !root()?.contains(target)) return;
        if (target.matches('[data-chart-metric]')) { state.metric = target.dataset.chartMetric; draw(); }
        if (target.matches('[role=tab]')) selectTab(target, false);
        if (target.matches('[data-expand]')) expand(target.closest('.ss-ec-report'), target.getAttribute('aria-expanded') !== 'true', false);
        if (target.matches('[data-sort]')) { target.setAttribute('aria-pressed', String(target.getAttribute('aria-pressed') !== 'true')); rank(target.closest('.ss-ec-report')); }
        if (target.matches('[data-open-report]')) {
            const panel = root().querySelector('[data-dimension="' + target.dataset.openReport + '"]');
            if (panel) { selectTab(document.getElementById(panel.getAttribute('aria-labelledby')), false); expand(panel, true, true); panel.scrollIntoView({ block: 'center' }); }
        }
        if (target.matches('a[href^="#"]:not(.refresh)')) {
            const destination = document.querySelector(target.getAttribute('href'));
            if (destination) { event.preventDefault(); if (destination.tagName === 'DETAILS') destination.open = true; destination.tabIndex = -1; destination.focus(); destination.scrollIntoView({ block: 'center' }); }
        }
        if (target.matches('[data-ec-export]')) announce(__('CSV download requested for the current report and filters.', 'wp-slimstat'), true);
    });
    document.addEventListener('change', event => {
        if (event.target.matches('[data-compare]')) { state.compare = event.target.checked; draw(); }
        if (!root()?.contains(event.target)) return;
        if (event.target.matches('[data-interval]')) { state.interval = event.target.value; window.SlimStatEcommerce.refresh(); }
        if (event.target.matches('[data-rank-metric]')) rank(event.target.closest('.ss-ec-report'));
    });
    document.addEventListener('keydown', event => {
        if (!root()?.contains(event.target)) return;
        const tab = event.target.closest('[role=tab]');
        if (tab && ['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) {
            event.preventDefault(); const tabs = Array.from(tab.parentElement.children); const rtl = document.documentElement.dir === 'rtl';
            let next = tabs.indexOf(tab) + ((event.key === 'ArrowRight') !== rtl ? 1 : -1);
            if (event.key === 'Home') next = 0; if (event.key === 'End') next = tabs.length - 1;
            selectTab(tabs[(next + tabs.length) % tabs.length], true);
        }
        if (event.key === 'Escape') {
            const details = event.target.closest('details[open]');
            const panel = event.target.closest('.ss-ec-report');
            if (details) { details.open = false; details.querySelector('summary').focus(); }
            else if (panel && state.expanded[panel.dataset.dimension]) expand(panel, false, true);
        }
    });
    $(init);
})(jQuery);
