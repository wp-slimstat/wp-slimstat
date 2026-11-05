/* Shared clipboard feedback and native Pro analysis. GPL-2.0-or-later. */
(() => {
    'use strict';
    const config = window.SlimStatAdminParams?.ai;
    if (!config || !window.wp?.i18n || !window.SlimStatReportDialogs) return;
    const { __, sprintf } = wp.i18n;
    let promoted = false;
    let toastTimer;
    let dialog;
    let sequence = 0;
    let active = false;

    /**
     * Create an element without interpreting report or provider text as HTML.
     *
     * @param {string} tag Trusted element name.
     * @param {string} text Plain text content.
     * @param {Object} attrs Trusted attribute names and values.
     * @returns {HTMLElement} The detached element.
     */
    function el(tag, text, attrs = {}) {
        const node = document.createElement(tag);
        if (text) node.textContent = text;
        Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, value));
        return node;
    }
    /**
     * Create a non-submitting button for an explicit user action.
     *
     * @param {string} text Localized button label.
     * @param {Function} callback Click handler.
     * @param {boolean} primary Whether to use the primary button style.
     * @returns {HTMLButtonElement} The detached button.
     */
    function action(text, callback, primary = false) {
        const node = el('button', text, { type: 'button', class: primary ? 'button button-primary' : 'button' });
        node.addEventListener('click', callback);
        return node;
    }
    /**
     * Use the server-provided contextual upgrade URL.
     *
     * @returns {HTMLAnchorElement} The Pro invitation link.
     */
    function upgrade() {
        return el('a', __('Explore Pro AI →', 'wp-slimstat'), { href: config.upgrade, class: 'button button-primary' });
    }
    /**
     * Confirm a completed copy without interrupting keyboard or pointer interaction.
     *
     * @param {string} title Localized status heading.
     * @param {string} description Localized supporting text.
     * @param {boolean} offer Allow the once-per-page administrator Pro invitation.
     */
    function toast(title, description, offer = false) {
        clearTimeout(toastTimer);
        document.getElementById('slimstat-ai-toast')?.remove();
        const node = el('aside', '', { id: 'slimstat-ai-toast', class: 'ss-ai-toast', 'aria-label': __('AI prompt', 'wp-slimstat') });
        const status = el('div', '', { role: 'status' });
        status.append(el('strong', title), el('p', description));
        node.append(status);
        if (offer && config.can_manage && !promoted) {
            promoted = true;
            const more = el('div', '', { class: 'ss-ai-toast-offer' });
            more.append(el('strong', __('Want AI insights without leaving WordPress?', 'wp-slimstat')),
                el('p', __('With SlimStat Pro, connect your AI provider and analyze reports in one click.', 'wp-slimstat')), upgrade());
            node.append(more);
        }
        node.append(action(__('Dismiss', 'wp-slimstat'), () => node.remove()));
        (dialog?.open ? dialog : document.body).append(node);
        const expire = () => { toastTimer = setTimeout(() => { if (!node.contains(document.activeElement)) node.remove(); }, 12000); };
        node.addEventListener('mouseenter', () => clearTimeout(toastTimer));
        node.addEventListener('mouseleave', expire);
        node.addEventListener('focusin', () => clearTimeout(toastTimer));
        node.addEventListener('focusout', expire);
        expire();
    }
    document.addEventListener('slimstat:ai-copy', event => {
        if (!event.detail?.copied) return;
        toast(__('✓ AI Prompt Copied!', 'wp-slimstat'),
            __('Paste it into ChatGPT, Claude, Gemini, Grok, or your favorite AI to analyze your report.', 'wp-slimstat'), !config.native);
    });

    /**
     * Capture selection metadata only; the server authorizes and rebuilds report data.
     *
     * @param {HTMLElement} trigger Report action with its report identifier.
     * @returns {Object} The selected dates, filters and report-specific controls.
     */
    function context(trigger) {
        const report = trigger.dataset.report;
        const viewer = document.querySelector('.ss-hmv');
        if (report === 'slimheatmap' && viewer) {
            if (!viewer.dataset.aiContext) throw new Error(__('Wait for the heatmap to finish loading.', 'wp-slimstat'));
            return { report, ...JSON.parse(viewer.dataset.aiContext) };
        }
        const range = document.querySelector('.slimstat-date-range-input');
        const filters = {};
        Object.entries(window.SlimStatGetFiltersForAjax?.() || {}).forEach(([key, value]) => {
            const match = /^fs\[([^\]]+)\]$/.exec(key);
            if (match) filters[match[1]] = value;
        });
        const result = { report, from: range?.dataset.start || '', to: range?.dataset.end || '', filters };
        if (report === 'slimheatmap') {
            result.device = document.querySelector('.ss-hm [name="device"]')?.value || '';
            result.search = document.querySelector('.ss-hm [name="q"]')?.value || '';
        }
        const granularity = trigger.closest('.postbox')?.querySelector('.slimstat-granularity-select');
        if (granularity?.value) result.granularity = granularity.value;
        return result;
    }
    /**
     * Send one authenticated REST request without an automatic retry.
     *
     * @param {string} path Feature endpoint suffix.
     * @param {Object} data Selection metadata and explicit consent, never report rows.
     * @returns {Promise<Object>} The REST response, or a rejected request.
     */
    function api(path, data) {
        return wp.apiFetch({ path: config.route + path, method: 'POST', data });
    }
    /**
     * Reuse the native dialog while invalidating responses from closed panels.
     *
     * @param {HTMLElement} trigger Action to restore focus to after closing.
     * @returns {HTMLElement} The empty panel body.
     */
    function open(trigger) {
        if (!dialog) {
            dialog = el('dialog', '', { class: 'ss-report-dialog ss-ai-dialog', 'aria-labelledby': 'ss-ai-title' });
            dialog.addEventListener('close', () => { ++sequence; });
            document.body.append(dialog);
        }
        const heading = el('h2', __('AI Analysis', 'wp-slimstat'), { id: 'ss-ai-title', tabindex: '-1', class: 'ss-report-dialog__heading' });
        const close = el('button', __('Close', 'wp-slimstat'), { type: 'button', class: 'button', 'data-dialog-close': '' });
        const header = el('header');
        header.append(heading, close);
        const body = el('div', '', { class: 'ss-ai-body' });
        dialog.replaceChildren(header, body);
        SlimStatReportDialogs.open(dialog, trigger);
        heading.focus();
        return body;
    }
    /**
     * Reuse the existing Free clipboard action for the same report.
     *
     * @param {HTMLElement} body Panel content container.
     * @param {HTMLElement} trigger Original report action.
     */
    function fallback(body, trigger) {
        body.append(action(__('Copy Prompt Instead', 'wp-slimstat'), () => {
            dialog.close();
            document.querySelector('.slimstat-ai-prompt[data-report="' + trigger.dataset.report + '"]')?.click();
        }));
    }
    /**
     * Replace only the error notice so a successful previous result stays readable.
     *
     * @param {HTMLElement} body Panel or library-entry content container.
     * @param {Object} exception Safe REST or locally constructed error.
     */
    function error(body, exception) {
        body.querySelector('.ss-ai-error')?.remove();
        body.prepend(el('p', exception?.message || __('AI analysis could not load. Please try again.', 'wp-slimstat'), { class: 'ss-ai-error', role: 'alert' }));
    }
    /**
     * Render headings and lists as text nodes; provider text never enters HTML parsing.
     *
     * @param {string} text Untrusted provider output.
     * @returns {HTMLElement} The inert result content.
     */
    function markdown(text) {
        const section = el('div', '', { class: 'ss-ai-result' });
        let list = null;
        String(text).split('\n').forEach(line => {
            const heading = /^#{1,6}\s+(.+)$/.exec(line);
            const bullet = /^(?:[-*+] |\d+[.)] )(.+)$/.exec(line);
            if (heading) { section.append(el('h3', heading[1])); list = null; }
            else if (bullet) {
                if (!list) { list = el('ul'); section.append(list); }
                list.append(el('li', bullet[1]));
            } else if (line.trim()) { section.append(el('p', line)); list = null; }
        });
        return section;
    }
    /**
     * Present one result with its original context and the shared clipboard fallback.
     *
     * @param {HTMLElement} body Panel or library-entry content container.
     * @param {Object} saved Server-validated analysis and context.
     * @param {boolean} cached Whether to label this as a saved snapshot.
     * @param {boolean} matches Whether the current selection matches; library views use the neutral snapshot notice.
     */
    function result(body, saved, cached, matches) {
        body.querySelector('.ss-ai-saved')?.remove();
        const section = el('section', '', { class: 'ss-ai-saved' });
        section.append(el('h3', saved.context.title), el('p',
            /* translators: 1: generation date, 2: provider, 3: model. */
            sprintf(__('Generated %1$s · %2$s / %3$s', 'wp-slimstat'), new Date(saved.generated * 1000).toLocaleString(), saved.provider, saved.model), { class: 'ss-ai-meta' }),
        el('p', saved.context.from + ' – ' + saved.context.to + ' · ' + saved.context.timezone, { class: 'ss-ai-meta', dir: 'ltr' }));
        if (saved.context.filters?.length) section.append(el('p', saved.context.filters.join(' · '), { class: 'ss-ai-meta' }));
        if (cached) section.append(el('p', matches
            ? __('Saved result. Analytics may have changed since it was generated.', 'wp-slimstat')
            : __('This saved result belongs to another date range, filter or report selection.', 'wp-slimstat'), { class: 'ss-ai-cache' }));
        section.append(markdown(saved.content), action(__('Copy Result', 'wp-slimstat'), async () => {
            try {
                if (!await window.SlimStatCopyText(saved.content)) throw new Error('Clipboard unavailable');
                toast(__('Analysis copied', 'wp-slimstat'), __('Your analysis is ready to paste.', 'wp-slimstat'));
            } catch (_) { error(body, new Error(__('Copy failed. Select the result text and copy it manually.', 'wp-slimstat'))); }
        }));
        body.prepend(section);
    }
    const library = document.querySelector('.ss-ai-library');
    if (library) {
        const entries = [...library.querySelectorAll('.ss-ai-entry')];
        const search = library.querySelector('#ss-ai-search');
        search?.addEventListener('input', () => {
            const query = search.value.trim().toLocaleLowerCase();
            let count = 0;
            entries.forEach(entry => {
                entry.hidden = !entry.querySelector('.ss-ai-entry-title').textContent.toLocaleLowerCase().includes(query);
                if (!entry.hidden) count++;
            });
            library.querySelector('#ss-ai-search-empty').hidden = count !== 0;
            library.querySelector('#ss-ai-search-status').textContent = sprintf(
                /* translators: %d: number of matching saved reports. */
                __('Matching saved reports: %d', 'wp-slimstat'), count);
        });
        entries.forEach(entry => {
            let loading = false;
            let loaded = false;
            entry.addEventListener('toggle', async () => {
                if (!entry.open || loaded || loading) return;
                loading = true;
                const body = entry.querySelector('.ss-ai-entry-body');
                body.setAttribute('aria-busy', 'true');
                body.replaceChildren(el('p', __('Loading saved analysis…', 'wp-slimstat'), { role: 'status' }));
                try {
                    const status = await api('/status', { report: entry.dataset.report, from: entry.dataset.from, to: entry.dataset.to, filters: {} });
                    body.replaceChildren();
                    if (!status.saved) throw new Error(__('This saved analysis is no longer available. Open the report to create a new one.', 'wp-slimstat'));
                    result(body, status.saved, true, true);
                    loaded = true;
                } catch (exception) {
                    body.replaceChildren();
                    error(body, exception);
                    body.append(el('p', __('Close and reopen this entry to try again.', 'wp-slimstat')));
                } finally {
                    loading = false;
                    body.removeAttribute('aria-busy');
                }
            });
        });
    }
    /**
     * Load saved results first and bind explicit analysis actions to this panel selection.
     *
     * @param {HTMLElement} body Current dialog content container.
     * @param {HTMLElement} trigger Original report action.
     * @param {number} id Sequence token used to discard closed or superseded panel responses.
     * @returns {Promise<void>} Completion of initial setup; generation remains a separate action.
     */
    async function native(body, trigger, id) {
        let selection;
        let status;
        body.append(el('p', __('Loading saved analysis…', 'wp-slimstat'), { role: 'status' }));
        try {
            selection = context(trigger);
            status = await api('/status', selection);
            if (id !== sequence || !dialog.open) return;
            body.replaceChildren();
            if (status.saved) result(body, status.saved, true, status.matches);
            if (!status.enabled) {
                body.append(el('p', __('AI analysis is disabled. An administrator can enable it in SlimStat’s AI Analysis settings.', 'wp-slimstat')));
                if (config.settings) body.append(el('a', __('AI Analysis Settings →', 'wp-slimstat'), { href: config.settings }));
                fallback(body, trigger);
                return;
            }
            const controls = el('div', '', { class: 'ss-ai-controls' });
            const prepare = action(status.saved ? (status.matches ? __('Regenerate Analysis', 'wp-slimstat') : __('Analyze Current Report', 'wp-slimstat')) : __('Analyze with AI', 'wp-slimstat'), async () => {
                if (active) return;
                active = true;
                prepare.disabled = true;
                try {
                    const current = context(trigger);
                    if (JSON.stringify(current) !== JSON.stringify(selection)) throw new Error(__('The report selection changed. Close this panel and reopen it for the current report.', 'wp-slimstat'));
                    const connection = await api('/connection', selection);
                    if (id !== sequence || !dialog.open) return;
                    if (!connection.provider) {
                        controls.replaceChildren(el('h3', __('Connect AI to unlock instant insights', 'wp-slimstat')),
                            el('p', __('Configure your preferred AI provider once to analyze reports directly in SlimStat.', 'wp-slimstat')));
                        if (config.connectors) controls.append(el('a', __('Set Up AI →', 'wp-slimstat'), { href: config.connectors, class: 'button button-primary' }));
                        else controls.append(el('p', __('Ask a site administrator to manage AI Connections.', 'wp-slimstat')));
                        return;
                    }
                    controls.replaceChildren(el('p', connection.provider + ' / ' + connection.model),
                        el('p', __('Selected aggregate analytics will be sent to this AI provider. Visitor identifiers and free-form labels are removed. Provider API charges may apply. Automatic selection does not guarantee the lowest price.', 'wp-slimstat')));
                    const consent = el('input', '', { type: 'checkbox', id: 'ss-ai-consent' });
                    consent.checked = !!connection.consented;
                    const label = el('label', '', { class: 'ss-ai-consent' });
                    label.append(consent, document.createTextNode(__('I agree to send this minimized report to the selected provider.', 'wp-slimstat')));
                    const perform = async () => {
                        if (active || !consent.checked) return;
                        try {
                            if (JSON.stringify(context(trigger)) !== JSON.stringify(selection)) throw new Error(__('The report selection changed. Close this panel and reopen it for the current report.', 'wp-slimstat'));
                        } catch (exception) { error(body, exception); return; }
                        active = true;
                        run.disabled = true;
                        consent.disabled = true;
                        const loading = el('div', '', { class: 'ss-ai-loading', role: 'status' });
                        loading.append(el('strong', __('Analyzing your report…', 'wp-slimstat')),
                            el('p', __('Reviewing your data and finding useful insights.', 'wp-slimstat')), el('div', '', { class: 'ss-ai-skeleton', 'aria-hidden': 'true' }));
                        controls.append(loading);
                        try {
                            const answer = await api('/analyze', { ...selection, consent: true, selection: connection.selection });
                            if (id !== sequence || !dialog.open) return;
                            if (JSON.stringify(context(trigger)) !== JSON.stringify(selection)) {
                                throw new Error(__('The report selection changed. Close this panel and reopen it for the current report.', 'wp-slimstat'));
                            }
                            result(body, answer.analysis, false, true);
                            if (!answer.saved) body.prepend(el('p', __('Analysis complete. This result was not saved.', 'wp-slimstat'), { role: 'status' }));
                            controls.replaceChildren(prepare);
                            prepare.textContent = __('Regenerate Analysis', 'wp-slimstat');
                            prepare.disabled = false;
                        } catch (exception) { if (id === sequence && dialog.open) error(body, exception); }
                        finally { active = false; loading.remove(); run.disabled = !consent.checked; consent.disabled = false; }
                    };
                    const run = action(__('Analyze Current Report', 'wp-slimstat'), perform, true);
                    run.disabled = !consent.checked;
                    consent.addEventListener('change', () => { run.disabled = !consent.checked; });
                    controls.append(label, run);
                    if (connection.consented) { active = false; await perform(); }
                } catch (exception) { if (id === sequence && dialog.open) error(body, exception); }
                finally { active = false; prepare.disabled = false; }
            }, true);
            controls.append(prepare);
            body.append(controls);
            fallback(body, trigger);
            if (!status.saved) prepare.click();
        } catch (exception) { if (id === sequence && dialog.open) { body.replaceChildren(); error(body, exception); fallback(body, trigger); } }
    }
    document.addEventListener('click', event => {
        const trigger = event.target.closest('.slimstat-ai-analyze');
        if (!trigger) return;
        if (active) { toast(__('Analysis in progress', 'wp-slimstat'), __('Wait for the current request to finish before starting another.', 'wp-slimstat')); return; }
        const body = open(trigger);
        const id = ++sequence;
        if (config.native) native(body, trigger, id);
        else {
            body.append(el('h3', __('From copy and paste to one-click insights', 'wp-slimstat')),
                el('p', __('With SlimStat Pro, connect your AI provider through WordPress and analyze reports without leaving your dashboard.', 'wp-slimstat')),
                el('p', __('Requires WordPress 7.0 or newer. Provider API charges may apply.', 'wp-slimstat')), upgrade());
            fallback(body, trigger);
        }
    });
})();
