/* Campaign links are generated locally; no requests or stored campaign data. */
(() => {
    'use strict';
    const panel = document.getElementById('slimstat-utm-builder');
    if (!panel) return;
    const { __ } = window.wpSlimstatI18n;
    const form = panel.querySelector('form');
    const website = form.elements.website;
    const tags = [...form.querySelectorAll('input[name^="utm_"]')];
    const output = form.querySelector('textarea');
    const status = form.querySelector('[data-utm-status]');
    const copy = form.querySelector('button[type="submit"]');

    function update() {
        render();
        // Primary only once there is a URL; never disabled, so a click still points at the missing field.
        copy.classList.toggle('button-primary', !!output.value);
    }

    function render() {
        output.value = '';
        status.textContent = '';
        website.setCustomValidity('');
        for (const field of tags) {
            const value = field.value.trim();
            let message = '';
            if (Array.from(value).length > 191) {
                message = __('Use no more than 191 characters per tag.', 'wp-slimstat');
            } else if (/[<>\x00-\x1f\x7f]/.test(field.value)) {
                message = __('Use plain text without HTML or control characters.', 'wp-slimstat');
            } else if (field.required && !value) {
                message = __('Enter a value for this required field.', 'wp-slimstat');
            }
            field.setCustomValidity(message);
        }
        if (!form.elements.utm_campaign.value.trim() && !form.elements.utm_id.value.trim()) {
            form.elements.utm_campaign.setCustomValidity(__('Enter a campaign name or campaign ID.', 'wp-slimstat'));
        }
        let url;
        try {
            url = new URL(website.value.trim());
            if (!/^https?:\/\//i.test(website.value.trim()) || url.username || url.password || /\s/.test(website.value.trim())) throw new Error();
        } catch (_) {
            website.setCustomValidity(__('Enter a full http:// or https:// URL without login credentials.', 'wp-slimstat'));
            return;
        }
        if (!form.checkValidity()) return;
        // Keep unrelated query bytes intact, including signed/encoded values.
        const names = tags.map(field => field.name);
        const parts = url.search.slice(1).split('&').filter(pair => {
            const key = [...new URLSearchParams(pair).keys()][0] || '';
            return pair && !names.includes(key.toLowerCase().split('[')[0]);
        });
        const campaign = new URLSearchParams();
        for (const field of tags) {
            if (field.value.trim()) campaign.set(field.name, field.value.trim());
        }
        url.search = [...parts, campaign.toString()].join('&');
        if (url.search.length - 1 > 16384) {
            website.setCustomValidity(__('This URL is too long to track. Shorten its existing query parameters.', 'wp-slimstat'));
            return;
        }
        output.value = url.href;
    }

    form.addEventListener('input', update);
    form.addEventListener('reset', () => setTimeout(update, 0));
    form.addEventListener('submit', async event => {
        event.preventDefault();
        update();
        // Native validation must be able to focus invalid fields inside the disclosure.
        for (const field of form.querySelectorAll('input:invalid')) field.closest('details').open = true;
        if (!form.reportValidity() || !output.value) return;
        const value = output.value;
        let copied = false;
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(value);
                copied = true;
            }
        } catch (_) { /* Plain HTTP and denied clipboard permissions use selection below. */ }
        if (!copied) {
            output.focus();
            output.select();
            try { copied = document.execCommand('copy'); } catch (_) { /* Leave text selected. */ }
        }
        if (value === output.value) status.textContent = copied
            ? __('Campaign URL copied.', 'wp-slimstat')
            : __('Link selected. Use your browser’s Copy command.', 'wp-slimstat');
    });

    let opener = null;
    function open() {
        panel.open = true;
        website.focus();
    }
    document.addEventListener('click', event => {
        const link = event.target.closest('a.slimstat-utm-builder-link');
        if (link) {
            event.preventDefault();
            opener = link.closest('.postbox')?.id;
            open();
        }
    });
    // Closed, the panel hides, so focus goes back to the button that opened it. Looked up
    // by report, because refreshing a report replaces its button.
    panel.addEventListener('toggle', () => {
        if (panel.open) return;
        (document.querySelector(`#${opener} a.slimstat-utm-builder-link`) || document.querySelector('a.slimstat-utm-builder-link'))?.focus();
    });
    window.addEventListener('hashchange', () => {
        if (window.location.hash === '#slimstat-utm-builder') open();
    });
    if (window.location.hash === '#slimstat-utm-builder') open();
    update();
})();
