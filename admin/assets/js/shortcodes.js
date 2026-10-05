/* global SlimStatShortcodes, wp, setUserSetting */
(function () {
    'use strict';
    const { __, sprintf } = wp.i18n;
    const config = SlimStatShortcodes;
    const $ = id => document.getElementById('ss-sc-' + id);
    if (!$('code')) return;
    let selected = config.catalog.find(item => item.id === 'users');
    let manual = false;
    let timer;
    let request = 0;
    const modeLabels = { count: __('Number', 'wp-slimstat'), top: __('Top list', 'wp-slimstat'), recent: __('List', 'wp-slimstat'), 'count-all': __('Number, all time', 'wp-slimstat'), 'top-all': __('Top list, all time', 'wp-slimstat'), 'recent-all': __('List, all time', 'wp-slimstat') };
    const node = (tag, text, className) => {
        const el = document.createElement(tag);
        if (text) el.textContent = text;
        if (className) el.className = className;
        return el;
    };
    const link = (text, href, external = false) => {
        const el = node('a', text);
        el.href = href;
        if (external) { el.target = '_blank'; el.rel = 'noopener'; }
        return el;
    };
    function catalog() {
        const query = $('search').value.trim().toLocaleLowerCase();
        const items = config.catalog.filter(item => (item.label + ' ' + item.group + ' ' + item.id).toLocaleLowerCase().includes(query));
        $('rail').replaceChildren();
        $('select').replaceChildren();
        let group = '';
        let section;
        let options;
        items.forEach(item => {
            if (item.group !== group) {
                group = item.group;
                section = node('section');
                section.append(node('h3', group));
                $('rail').append(section);
                options = node('optgroup'); options.label = group; $('select').append(options);
            }
            const button = node('button', item.label);
            button.type = 'button'; button.dataset.id = item.id;
            button.tabIndex = selected.id === item.id || (!items.some(entry => entry.id === selected.id) && item === items[0]) ? 0 : -1;
            if (selected.id === item.id) button.setAttribute('aria-current', 'true');
            if (!config.pro && item.tier === 'pro') button.append(node('span', __('Pro', 'wp-slimstat'), 'ss-sc-chip'));
            if (item.privacy === 'staff') button.append(node('span', __('Staff only', 'wp-slimstat'), 'ss-sc-staff'));
            button.addEventListener('click', () => select(item));
            section.append(button);
            const option = node('option', item.label); option.value = item.id; option.selected = selected.id === item.id; options.append(option);
        });
        $('select').disabled = !items.length;
        $('no-results').hidden = !!items.length;
        /* translators: %s: search text. */
        $('no-results').querySelector('p').textContent = sprintf(__('No shortcode matches “%s”.', 'wp-slimstat'), $('search').value);
    }
    function surfaces(item) {
        $('privacy').hidden = item.privacy !== 'staff';
        $('unlock').replaceChildren(); $('unlock').hidden = true;
        $('hint').replaceChildren(); $('hint').hidden = true;
        if (!config.pro && item.tier === 'pro') {
            $('unlock').hidden = false;
            const upgrade = link(__('Upgrade to Pro', 'wp-slimstat'), config.pricing, true); upgrade.className = 'button button-primary';
            $('unlock').append(node('p', item.benefit), upgrade);
            if (config.compare) $('unlock').append(link(__('Compare Free and Pro', 'wp-slimstat'), config.compare));
        } else if (!item.available) {
            $('unlock').hidden = false;
            $('unlock').append(node('p', item.tier === 'pro' ? __('Update SlimStat Pro to use this shortcode.', 'wp-slimstat') : __('This report is unavailable. Activate WooCommerce and set up reporting for Ecommerce.', 'wp-slimstat')), link(__('Go to Plugins', 'wp-slimstat'), config.plugins));
        }
        if (!config.pro && item.hint && !config.dismissed[item.id]) {
            $('hint').hidden = false;
            $('hint').append(node('p', item.hint), link(__('Upgrade to Pro', 'wp-slimstat'), config.hintPricing, true));
            const dismiss = node('button', '', 'notice-dismiss'); dismiss.type = 'button';
            dismiss.setAttribute('aria-label', __('Dismiss Pro hint', 'wp-slimstat'));
            dismiss.addEventListener('click', () => { setUserSetting('slimstat_sc_hint_' + item.id, 'seen'); config.dismissed[item.id] = true; $('hint').hidden = true; $('code').focus(); });
            $('hint').append(dismiss);
        }
    }
    function select(item, initial = false) {
        const railFocused = $('rail').contains(document.activeElement);
        selected = item; manual = false;
        $('manual').hidden = true; $('builder').hidden = false;
        $('title').textContent = item.label; $('description').textContent = item.description;
        $('display').replaceChildren();
        item.modes.forEach(mode => { const option = node('option', modeLabels[mode] || mode); option.value = mode; $('display').append(option); });
        if (item.modes.includes('top') && !['id', 'visit_id', '*', 'count'].includes(item.id)) $('display').value = 'top';
        $('display-label').hidden = item.modes.length === 1;
        $('period').value = '-30'; $('limit').value = '10'; $('filters').replaceChildren();
        $('builder').hidden = item.modes[0] === 'live';
        catalog(); surfaces(item); build(initial);
        if (railFocused) { const current = $('rail').querySelector('[aria-current]'); if (current) current.focus(); }
    }
    function build(initial = false) {
        if (manual) return;
        const mode = $('display').value;
        const filters = [];
        $('period').parentElement.hidden = mode.endsWith('-all');
        $('limit').parentElement.hidden = mode.startsWith('count');
        const columns = mode.startsWith('top') && selected.id !== 'count' ? selected.id + ',count' : selected.id;
        if (mode !== 'live') {
            if (!mode.endsWith('-all')) filters.push('interval equals ' + $('period').value);
            if (!mode.startsWith('count')) filters.push('limit_results equals ' + Math.max(1, Math.min(100, Number($('limit').value) || 10)));
            $('filters').querySelectorAll('.ss-sc-filter').forEach(row => {
                const inputs = row.querySelectorAll('select, input');
                filters.push(inputs[0].value + ' ' + inputs[1].value + ' ' + encodeURIComponent(inputs[2].value));
            });
        }
        $('code').value = '[slimstat f="' + mode + '" w="' + columns + '"]' + (filters.length ? filters.join('&&&') + '[/slimstat]' : '');
        if (!initial) schedule();
    }
    function loading() {
        $('preview').replaceChildren();
        $('preview').setAttribute('aria-busy', 'true');
        $('preview').classList.add('is-loading');
        $('sample').hidden = true;
    }
    function schedule() { clearTimeout(timer); ++request; loading(); timer = setTimeout(preview, 400); }
    async function preview() {
        clearTimeout(timer);
        const current = ++request;
        loading();
        $('error').hidden = true; $('code-message').textContent = '';
        try {
            const result = await wp.apiFetch({ path: '/slimstat/v1/shortcode/preview', method: 'POST', data: { shortcode: $('code').value } });
            if (current !== request) return;
            $('preview').innerHTML = result.html;
            if (!$('preview').textContent.trim()) {
                const comment = [...$('preview').childNodes].find(child => child.nodeType === Node.COMMENT_NODE);
                const empty = manual ? __('No pageviews matched. Try a longer period or remove a filter.', 'wp-slimstat') : sprintf(
                    /* translators: %s: selected reporting period. */
                    __('No pageviews matched in %s. Try a longer period or remove a filter.', 'wp-slimstat'), $('display').value.endsWith('-all') ? __('All time', 'wp-slimstat') : $('period').selectedOptions[0].textContent);
                $('preview').replaceChildren(node('p', comment ? comment.textContent.trim() : empty));
            }
            $('sample').hidden = !result.sample; $('privacy').hidden = !result.staff_only;
            $('status').textContent = __('Preview updated.', 'wp-slimstat');
            $('code').removeAttribute('aria-invalid');
            if (manual) {
                const match = $('code').value.match(/\bw\s*=\s*["']([^"']+)["']/);
                const item = match && config.catalog.find(entry => entry.id === match[1].split(',')[0]);
                if (item) surfaces(item);
                $('privacy').hidden = !result.staff_only;
            }
        } catch (error) {
            if (current !== request) return;
            const message = error.message || __('The preview could not connect to WordPress. Check your connection and retry.', 'wp-slimstat');
            $('error').hidden = false; $('error').querySelector('p').textContent = message;
            $('code-message').textContent = message;
            $('code').setAttribute('aria-invalid', 'true');
        } finally {
            if (current === request) { $('preview').setAttribute('aria-busy', 'false'); $('preview').classList.remove('is-loading'); }
        }
    }
    $('search').addEventListener('input', catalog);
    $('search').addEventListener('keydown', event => { if (event.key === 'Escape') { $('search').value = ''; catalog(); } });
    $('no-results').querySelector('button').addEventListener('click', () => { $('search').value = ''; catalog(); $('search').focus(); });
    $('select').addEventListener('change', () => select(config.catalog.find(item => item.id === $('select').value)));
    $('rail').addEventListener('keydown', event => {
        if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
        const buttons = [...$('rail').querySelectorAll('button')];
        const index = buttons.indexOf(document.activeElement);
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : Math.max(0, Math.min(buttons.length - 1, index + (event.key === 'ArrowDown' ? 1 : -1)));
        if (buttons[next]) { event.preventDefault(); buttons[next].focus(); }
    });
    $('builder').addEventListener('change', () => build());
    $('builder').addEventListener('input', () => build());
    $('add-filter').addEventListener('click', () => {
        const row = node('div', '', 'ss-sc-filter');
        const column = node('select'); column.setAttribute('aria-label', __('Filter column', 'wp-slimstat'));
        Object.entries(config.columns).forEach(([key, info]) => { const option = node('option', info[0]); option.value = key; column.append(option); });
        const operator = node('select'); operator.setAttribute('aria-label', __('Filter operator', 'wp-slimstat'));
        Object.entries(config.operators).forEach(([key, text]) => { const option = node('option', Array.isArray(text) ? text[0] : text); option.value = key; operator.append(option); });
        const value = node('input'); value.type = 'text'; value.setAttribute('aria-label', __('Filter value', 'wp-slimstat'));
        const remove = node('button', __('Remove filter', 'wp-slimstat'), 'button-link'); remove.type = 'button';
        remove.addEventListener('click', () => { row.remove(); build(); $('add-filter').focus(); });
        row.append(column, operator, value, remove); $('filters').append(row); column.focus(); build();
    });
    $('code').addEventListener('input', () => { manual = true; $('manual').hidden = false; $('builder').hidden = true; schedule(); });
    $('reset').addEventListener('click', () => select(selected));
    $('run').addEventListener('click', preview); $('retry').addEventListener('click', preview);
    $('copy').addEventListener('click', async () => {
        try {
            if (!navigator.clipboard) throw new Error('clipboard');
            await navigator.clipboard.writeText($('code').value);
            $('copy').textContent = __('Copied', 'wp-slimstat'); $('status').textContent = __('Copied', 'wp-slimstat');
            setTimeout(() => { $('copy').textContent = __('Copy', 'wp-slimstat'); }, 2000);
        } catch (error) {
            $('code').focus(); $('code').select(); $('code-message').textContent = __('Your browser could not copy automatically. The shortcode is selected; use your keyboard to copy it.', 'wp-slimstat');
        }
    });
    select(selected, true);
}());
