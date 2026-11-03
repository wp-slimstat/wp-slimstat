/* Country → City drilldown: one dialog for the map, its country list and Top Countries. */
(() => {
    'use strict';
    const { __, sprintf } = window.wpSlimstatI18n;
    const CODE = /^[a-z0-9]{2}$/;
    let dialog = null;
    let request = null;
    let country = '';

    function build() {
        dialog = document.createElement('dialog');
        dialog.id = 'slimstat-country-cities';
        dialog.className = 'ss-report-dialog ss-cc';
        dialog.setAttribute('aria-labelledby', 'slimstat-country-cities-title');
        dialog.innerHTML = '<div class="ss-report-dialog__heading"><h2 id="slimstat-country-cities-title"><img class="ss-cc__flag" alt="" width="24" height="24" hidden><span></span></h2>'
            + '<button type="button" class="button" data-dialog-close></button></div><div class="ss-cc__body" aria-live="polite"></div>';
        dialog.querySelector('[data-dialog-close]').textContent = __('Close', 'wp-slimstat');
        dialog.addEventListener('close', () => request && request.abort());
        dialog.addEventListener('click', event => {
            const more = event.target.closest('[data-ss-cc-more]');
            if (more) load(parseInt(more.dataset.ssCcMore, 10) || 0, more);
            if (event.target.closest('[data-ss-cc-retry]')) load(0);
        });
        // The shared dialog styles are scoped to .wrap-slimstat, which the WordPress Dashboard lacks.
        let host = document.querySelector('.wrap-slimstat');
        if (!host) {
            host = document.createElement('div');
            host.className = 'wrap-slimstat ss-cc-host';
            document.body.appendChild(host);
        }
        host.appendChild(dialog);
    }

    function setTitle(text, flag) {
        dialog.querySelector('h2 span').textContent = text;
        const img = dialog.querySelector('.ss-cc__flag');
        img.hidden = !flag;
        if (flag) img.src = flag;
    }

    function status(body, message, retry) {
        body.replaceChildren();
        const box = document.createElement('div');
        box.className = retry ? 'ss-cc__error' : 'ss-cc__loading';
        const text = document.createElement('p');
        text.textContent = message;
        box.appendChild(text);
        if (retry) {
            box.setAttribute('role', 'alert');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'button';
            button.dataset.ssCcRetry = '';
            button.textContent = __('Try again', 'wp-slimstat');
            box.appendChild(button);
        }
        body.appendChild(box);
    }

    // The same request a report refresh sends: page, filters, date range and network scope.
    function params(start) {
        const params = window.SlimStatAdminParams || {};
        const admin = window.SlimStatAdmin;
        const data = new URLSearchParams({
            action: 'slimstat_country_cities',
            security: (document.getElementById('meta-box-order-nonce') || {}).value || '',
            page: (admin && admin.get_current_tab()) || (document.body.classList.contains('index-php') ? 'dashboard' : ''),
            country,
            start: String(start),
        });
        if (params.network_scope_nonce) {
            data.set('slimstat_network_scope', '1');
            data.set('slimstat_network_nonce', params.network_scope_nonce);
        }
        document.querySelectorAll('#slimstat-filters-form .slimstat-post-filter').forEach(input => {
            if (input.name) data.set(input.name, input.value);
        });
        return data;
    }

    function load(start, trigger) {
        if (request) request.abort();
        const controller = new AbortController();
        request = controller;
        const body = dialog.querySelector('.ss-cc__body');
        if (trigger) {
            trigger.disabled = true;
        } else {
            status(body, __('Loading city statistics…', 'wp-slimstat'));
        }
        const url = (window.SlimStatAdminParams && window.SlimStatAdminParams.ajax_url) || window.ajaxurl;
        fetch(url, { method: 'POST', body: params(start), credentials: 'same-origin', signal: controller.signal })
            .then(response => response.json())
            .then(json => {
                if (!json || !json.success) {
                    throw new Error((json && json.data && json.data.message) || '');
                }
                if (trigger) {
                    append(body, json.data.html, trigger);
                } else {
                    setTitle(json.data.title, json.data.flag);
                    body.innerHTML = json.data.html;
                }
            })
            .catch(error => {
                if ('AbortError' === error.name) return;
                const message = error.message || __('City statistics could not be loaded.', 'wp-slimstat');
                if (trigger) {
                    trigger.disabled = false;
                    const note = document.createElement('p');
                    note.className = 'ss-cc__note';
                    note.setAttribute('role', 'alert');
                    note.textContent = message;
                    trigger.after(note);
                } else {
                    status(body, message, true);
                }
            })
            .finally(() => {
                if (request === controller) request = null;
            });
    }

    // "Show more": the response holds the next rows and the next button only.
    function append(body, html, trigger) {
        const template = document.createElement('template');
        template.innerHTML = html;
        const rows = [...template.content.querySelectorAll('tr')];
        body.querySelector('.ss-cc__table tbody').append(...rows);
        const more = template.content.querySelector('.ss-cc__more');
        trigger.closest('.ss-cc__more').replaceWith(more || '');
        // Keyboard users continue from the first new city rather than from the top.
        const first = rows[0] && rows[0].querySelector('th');
        if (first) {
            first.tabIndex = -1;
            first.focus();
        }
    }

    function open(code, name, opener) {
        code = String(code || '').toLowerCase();
        if (!CODE.test(code) || !window.SlimStatReportDialogs) return;
        if (!dialog) build();
        country = code;
        const row = opener && opener.closest('[data-slimstat-country]');
        const flag = row && row.querySelector('img');
        /* translators: %s: country name. */
        setTitle(name ? sprintf(__('Top Cities in %s', 'wp-slimstat'), name) : __('Top Cities', 'wp-slimstat'), flag ? flag.src : '');
        window.SlimStatReportDialogs.open(dialog, opener || document.activeElement);
        load(0);
    }

    function fromRow(row) {
        const label = row.querySelector('strong, .slimstat-filter-link');
        const opener = row.matches('[role="button"]') ? row : row.querySelector('a') || row;
        open(row.dataset.slimstatCountry, label ? label.textContent.trim() : '', opener);
    }

    // Capture phase, so admin.js's filter-link handler never sees the click. A modified or
    // middle click keeps the link's own behaviour: filter all reports by this country.
    document.addEventListener('click', event => {
        if (0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const row = event.target.closest('[data-slimstat-country]');
        if (!row || !CODE.test(row.dataset.slimstatCountry || '')) return;
        event.preventDefault();
        event.stopPropagation();
        fromRow(row);
    }, true);
    document.addEventListener('keydown', event => {
        if ('Enter' !== event.key && ' ' !== event.key) return;
        if (!event.target.matches || !event.target.matches('[role="button"][data-slimstat-country]')) return;
        event.preventDefault();
        fromRow(event.target);
    });

    window.SlimStatCountryCities = { open };
})();
