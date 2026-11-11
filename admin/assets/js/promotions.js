/* Local session aggregates only. No transport, visitor tracking or database writes. */
(function () {
    'use strict';
    var config = window.SlimStatPromotions || {};
    // Keep translated labels and badges together without covering the report.
    var menuLabels = document.querySelectorAll('#adminmenu .wp-has-current-submenu .slimstat-menu-label');
    function fitMenu() {
        var width = 160;
        menuLabels.forEach(function (label) { width = Math.max(width, Math.ceil(label.getBoundingClientRect().width) + 24); });
        document.body.style.setProperty('--slimstat-menu-width', Math.min(width, window.innerWidth - 48) + 'px');
    }
    if (menuLabels.length) {
        document.body.classList.add('slimstat-menu-fit');
        fitMenu();
        window.addEventListener('resize', fitMenu);
        if (typeof ResizeObserver === 'function') {
            var menuObserver = new ResizeObserver(fitMenu);
            menuLabels.forEach(function (label) { menuObserver.observe(label); });
        }
    }
    var storageKey = 'slimstat-promotions:6.0:' + (config.scope || '');
    document.querySelectorAll('#adminmenu a[href*="page=slimpro"]').forEach(function (link) {
        var url = new URL(link.href, location.href);
        url.searchParams.set('utm_campaign', 'menu'); url.searchParams.set('utm_content', 'sidebar');
        link.href = url.href;
    });
    var counts = {};
    try { counts = JSON.parse(sessionStorage.getItem(storageKey) || '{}'); } catch (e) { counts = {}; }
    function record(link, event) {
        if (!config.scope || document.visibilityState !== 'visible') return;
        var url = new URL(link.href, location.href);
        var campaign = url.searchParams.get('utm_campaign');
        var content = url.searchParams.get('utm_content');
        if (!campaign || !content) return;
        var key = [config.page, campaign, content, config.edition, event].join(':');
        if (event === 'impression' && counts[key]) return;
        counts[key] = (counts[key] || 0) + 1;
        try { sessionStorage.setItem(storageKey, JSON.stringify(counts)); } catch (e) { /* Storage can be disabled. */ }
        document.dispatchEvent(new CustomEvent('slimstat:promotion', {detail: {
            event: event, page: config.page, campaign: campaign, content: content, edition: config.edition, release: '6.0'
        }}));
    }
    var observed = new WeakSet();
    var observer = typeof IntersectionObserver === 'function' ? new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting && entry.intersectionRatio >= 0.5) record(entry.target, 'impression');
        });
    }, {threshold: 0.5}) : null;
    function scan() {
        if (!observer || !config.scope) return;
        document.querySelectorAll('a[href*="utm_campaign="][href*="utm_content="]').forEach(function (link) {
            if (!observed.has(link)) { observed.add(link); observer.observe(link); }
        });
    }
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            document.querySelectorAll('a[href*="utm_campaign="][href*="utm_content="]').forEach(function (link) {
                if (observer) { observer.unobserve(link); observer.observe(link); }
            });
        }
    });
    document.addEventListener('click', function (event) {
        var button = event.target.closest('.slimstat-custom-db-notice .notice-dismiss');
        if (!button) return;
        // Core removes notices immediately; retain this one until its dismissal is saved.
        event.preventDefault();
        event.stopImmediatePropagation();
        var notice = button.closest('.slimstat-custom-db-notice');
        button.disabled = true;
        fetch(notice.dataset.ajaxUrl, {method: 'POST', credentials: 'same-origin', body: new URLSearchParams({
            action: 'slimstat_dismiss_notice', key: notice.dataset.noticeKey,
            stamp: notice.dataset.noticeStamp, snooze: notice.dataset.noticeSnooze,
            nonce: notice.dataset.noticeNonce
        })}).then(function (response) { if (!response.ok) throw new Error(); return response.json(); }).then(function (result) {
            if (!result.success) throw new Error();
            notice.remove();
        }).catch(function () {
            button.disabled = false;
            var status = notice.querySelector('.slimstat-notice-dismiss-status');
            status.classList.remove('screen-reader-text');
            status.textContent = config.dismissError || '';
        });
    }, true);
    document.addEventListener('click', function (event) {
        var dismiss = event.target.closest('.slimstat-release-dismiss');
        if (dismiss) {
            var bar = dismiss.closest('#slimstat-notice-latest-news');
            dismiss.disabled = true;
            fetch(bar.dataset.ajaxUrl, {method: 'POST', credentials: 'same-origin', body: new URLSearchParams({
                action: 'slimstat_notice_latest_news', security: bar.dataset.dismissNonce
            })}).then(function (response) { if (!response.ok) throw new Error(); return response.json(); }).then(function (result) {
                if (!result.success) throw new Error();
                bar.remove();
            }).catch(function () {
                dismiss.disabled = false;
                bar.querySelector('[role="status"]').textContent = config.dismissError || '';
            });
        }
        var link = event.target.closest('a[href]');
        if (link) record(link, 'click');
    });
    scan();
    new MutationObserver(scan).observe(document.body, {childList: true, subtree: true});
}());
/* Black Friday 2026 banner: delete after 2026-12-01. Bound to its own root; the only request is the dismissal, stored on the user. */
(function () {
    'use strict';
    var root = document.getElementById('slimstat-bf-2026');
    if (!root) return;
    var copy = root.querySelector('.slimstat-bf26__copybtn');
    var code = root.querySelector('.slimstat-bf26__code');
    var status = root.querySelector('[role="status"]');
    var label = copy.textContent;
    var dismissing = false;
    function say(text) { copy.textContent = text; status.textContent = text; }
    function select() {
        var range = document.createRange();
        var selection = document.getSelection();
        range.selectNodeContents(code);
        selection.removeAllRanges();
        selection.addRange(range);
        say(copy.getAttribute('data-slimstat-bf26-selected'));
    }
    root.addEventListener('click', function (event) {
        if (event.target.closest('.slimstat-bf26__copybtn')) {
            // No clipboard API on plain-HTTP admin: select the code for Ctrl+C instead.
            try {
                navigator.clipboard.writeText(code.textContent).then(function () { say(copy.getAttribute('data-slimstat-bf26-copied')); }, select);
            } catch (e) { select(); }
        }
        if (event.target.closest('.slimstat-bf26__dismiss') && !dismissing) {
            // Removed only once saved, so a strip that is gone stays gone on every browser. One request at a time,
            // so a double click cannot send two or move focus twice.
            dismissing = true;
            fetch(root.getAttribute('data-slimstat-bf26-ajax'), {method: 'POST', credentials: 'same-origin', body: new URLSearchParams({
                action: 'slimstat_bf2026_dismiss', security: root.getAttribute('data-slimstat-bf26-nonce')
            })}).then(function (response) { return response.ok ? response.json() : {}; }).then(function (result) {
                if (!result.success) throw new Error();
                var heading = document.querySelector('#wpbody-content h1');
                root.remove();
                if (heading) { heading.setAttribute('tabindex', '-1'); heading.focus(); }
            }).catch(function () { dismissing = false; status.textContent = root.getAttribute('data-slimstat-bf26-error'); });
        }
    });
    copy.addEventListener('blur', function () { copy.textContent = label; status.textContent = ''; });
}());
/* End Black Friday 2026 banner */
