jQuery(function ($) {
    const sidebar = document.querySelector('.slimstat-notification-sidebar');
    const overlay = document.querySelector('.slimstat-notification-sidebar__overlay');
    if (!sidebar || !overlay || sidebar.dataset.notificationsReady) return;
    sidebar.dataset.notificationsReady = 'true';
    const reload = sidebar.querySelector('.slimstat-notification-sidebar__reload');
    const dismissAll = sidebar.querySelector('.slimstat-notification-sidebar__dismiss-all');
    const tabs = sidebar.querySelectorAll('.slimstat-notification-sidebar__tab');
    let pending = false;
    const queuedActions = [];
    let timer = 0;
    let checked = Date.now();
    let countdownTimer = 0;
    let opener = null;
    const lastHtml = {};

    function updatePromotions() {
        clearTimeout(countdownTimer);
        let next = 30000;
        document.querySelectorAll('.slimstat-notification-sidebar__card[data-ends-at], .slimstat-header__promo').forEach(item => {
            const end = Date.parse(item.dataset.endsAt);
            if (!Number.isFinite(end)) return;
            const remaining = end - Date.now();
            if (remaining <= 0) {
                if (item.classList.contains('slimstat-header__promo')) {
                    const upgrade = document.querySelector('.slimstat-header__cta');
                    if (upgrade && item.contains(document.activeElement)) upgrade.focus();
                }
                if (item.contains(document.activeElement)) sidebar.querySelector('.slimstat-notification-sidebar__close')?.focus();
                item.remove();
                return;
            }
            next = Math.min(next, remaining);
            const countdown = item.querySelector('.slimstat-notification-sidebar__countdown');
            if (countdown) {
                const minutes = Math.ceil(remaining / 60000);
                const format = new Intl.NumberFormat(document.documentElement.lang || undefined);
                const values = [Math.floor(minutes / 1440), Math.floor(minutes % 1440 / 60), minutes % 60];
                countdown.textContent = slimstat_admin.ends_in.replace(/%([123])\$s/g, (_, index) => format.format(values[Number(index) - 1]));
            }
        });
        const cards = [...sidebar.querySelectorAll('#tab-1 .slimstat-notification-sidebar__card:not(.slimstat-notification-sidebar__no-card)')];
        const badges = cards.filter(card => card.dataset.badge);
        const badge = badges.find(card => card.classList.contains('slimstat-notification-sidebar__danger')) || badges[0];
        paintBadges(cards.length, badge ? { label: badge.dataset.badge, urgent: badge.classList.contains('slimstat-notification-sidebar__danger'), ends_at: badge.dataset.endsAt } : null);
        updateSidebarState();
        if (!document.hidden) countdownTimer = setTimeout(updatePromotions, next);
    }

    $(sidebar).on('click', '.slimstat-notification-sidebar__copy', async function () {
        const button = this;
        const code = button.parentElement.querySelector('code');
        const status = button.parentElement.querySelector('[role="status"]');
        let copied = false;
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(code.textContent);
                copied = true;
            }
        } catch (_) { /* Selection below also works when clipboard permission is denied. */ }
        if (!copied) {
            const range = document.createRange();
            range.selectNodeContents(code);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            try { copied = document.execCommand('copy'); } catch (_) { /* Keep the code selected for manual copying. */ }
        }
        button.textContent = copied ? slimstat_admin.copied : slimstat_admin.copy;
        status.textContent = copied ? slimstat_admin.copied : slimstat_admin.copy_failed;
    });

    function close() {
        if (!sidebar.classList.contains('is-active')) return;
        sidebar.classList.remove('is-active');
        overlay.classList.remove('is-active');
        document.body.classList.remove('slimstat-no-scroll');
        opener?.setAttribute('aria-expanded', 'false');
        opener?.focus();
        sidebar.setAttribute('aria-hidden', 'true');
    }
    document.documentElement.style.setProperty('--scrollbar-width', (window.innerWidth - document.documentElement.clientWidth) + 'px');
    document.querySelectorAll('.js-slimstat-open-notification').forEach(button => {
        button.addEventListener('click', event => {
            event.preventDefault();
            if (sidebar.classList.contains('is-active')) { close(); return; }
            opener = button;
            const open = true;
            sidebar.classList.toggle('is-active', open);
            overlay.classList.toggle('is-active', open);
            document.body.classList.toggle('slimstat-no-scroll', open);
            sidebar.setAttribute('aria-hidden', 'false');
            button.setAttribute('aria-expanded', 'true');
            sidebar.querySelector('.slimstat-notification-sidebar__close')?.focus();
        });
    });
    overlay.addEventListener('click', close);
    sidebar.querySelector('.slimstat-notification-sidebar__close')?.addEventListener('click', close);
    document.addEventListener('keydown', event => {
        if (!sidebar.classList.contains('is-active')) return;
        if (event.key === 'Escape') close();
        if (event.key !== 'Tab') return;
        const controls = [...sidebar.querySelectorAll('a[href], button, [tabindex="0"]')].filter(el => el.offsetParent !== null);
        const first = controls[0], last = controls[controls.length - 1];
        if (event.shiftKey && (document.activeElement === first || !sidebar.contains(document.activeElement))) {
            event.preventDefault(); last?.focus();
        } else if (!event.shiftKey && (document.activeElement === last || !sidebar.contains(document.activeElement))) {
            event.preventDefault(); first?.focus();
        }
    });

    function updateSidebarState() {
        const inbox = sidebar.querySelector('[data-tab="tab-1"]').classList.contains('slimstat-notification-sidebar__tab--active');
        const count = sidebar.querySelectorAll('#tab-1 .slimstat-notification-sidebar__card:not(.slimstat-notification-sidebar__no-card)').length;
        if (dismissAll) dismissAll.hidden = !inbox || !count;
        const pane = sidebar.querySelector('.slimstat-notification-sidebar__tab-pane--active .slimstat-notification-sidebar__cards');
        if (pane && !pane.querySelector('.slimstat-notification-sidebar__card')) {
            const card = $('<div class="slimstat-notification-sidebar__card slimstat-notification-sidebar__no-card">');
            const body = $('<div class="slimstat-notification-sidebar__card-body">').appendTo(card);
            $('<h2 class="slimstat-notification-sidebar__card-content">').text(slimstat_admin.empty_title).appendTo(body);
            $('<p class="slimstat-notification-sidebar__card-content">').text(inbox ? slimstat_admin.empty_inbox : slimstat_admin.empty_dismissed).appendTo(body);
            card.appendTo(pane);
        }
    }
    tabs.forEach(tab => tab.addEventListener('click', () => {
        tabs.forEach(item => {
            item.classList.toggle('slimstat-notification-sidebar__tab--active', item === tab);
            item.setAttribute('aria-selected', String(item === tab));
            item.tabIndex = item === tab ? 0 : -1;
        });
        sidebar.querySelectorAll('.slimstat-notification-sidebar__tab-pane').forEach(pane => {
            pane.classList.toggle('slimstat-notification-sidebar__tab-pane--active', pane.id === tab.dataset.tab);
            pane.setAttribute('aria-hidden', String(pane.id !== tab.dataset.tab));
        });
        updateSidebarState();
    }));

    tabs.forEach((tab, index) => tab.addEventListener('keydown', event => {
        if (['Enter', ' ', 'ArrowLeft', 'ArrowRight'].includes(event.key)) {
            event.preventDefault();
            const next = event.key.startsWith('Arrow') ? tabs[(index + 1) % tabs.length] : tab;
            next.click(); next.focus();
        }
    }));

    function paintBadges(count, badgeData) {
        const label = new Intl.NumberFormat(document.documentElement.lang || undefined).format(count);
        $('.slimstat-notifications').toggleClass('slimstat-notifications--has-items', count > 0);
        $('.slimstat-header__badge, .notification-badge, .slimstat-menu-notification-count').remove();
        if (count) {
            $('<span class="slimstat-header__badge" dir="ltr">')
                .toggleClass('slimstat-header__badge--urgent', !!badgeData?.urgent)
                .text(label).appendTo('.slimstat-header__icon--notifications');
            const badge = $('<span class="update-plugins slimstat-menu-notification-count">').addClass('count-' + count);
            $('<span class="plugin-count">').text(label).appendTo(badge);
            $('#adminmenu .wp-menu-name > .slimstat-menu-title').parent().append(badge);
        }
        document.querySelector('.slimstat-header__promo')?.classList.toggle('slimstat-header__promo--in-panel',
            !!sidebar.querySelector('.slimstat-notification-sidebar__promo'));
    }
    function apply(data) {
        const focused = document.activeElement;
        const focusedCard = focused?.closest('.slimstat-notification-sidebar__card');
        const cardId = focusedCard?.dataset.notificationId;
        const control = focused?.classList.contains('slimstat-notification-sidebar__copy') ? '.slimstat-notification-sidebar__copy'
            : focused?.classList.contains('slimstat-notification-sidebar__button') ? '.slimstat-notification-sidebar__button' : '.slimstat-notification-sidebar__dismiss';
        for (const [tab, html] of [['tab-1', data.inbox_html], ['tab-2', data.dismissed_html]]) {
            if (lastHtml[tab] !== html) {
                $('#' + tab + ' .slimstat-notification-sidebar__cards').html(html);
                lastHtml[tab] = html;
            }
        }
        if (cardId && !focused.isConnected) {
            const replacement = sidebar.querySelector('.slimstat-notification-sidebar__tab-pane--active [data-notification-id="' + CSS.escape(cardId) + '"] ' + control);
            (replacement && replacement.offsetParent !== null ? replacement : sidebar.querySelector('.slimstat-notification-sidebar__close'))?.focus();
        }
        updatePromotions();
    }
    function schedule() {
        clearTimeout(timer);
        if (!document.hidden) timer = setTimeout(() => refresh('notification_status'), Math.max(0, 60000 - (Date.now() - checked)));
    }
    function refresh(action, extra) {
        if (action === 'notification_status' && document.hidden) return;
        if (pending) {
            // Background polls coalesce; user actions must survive an in-flight request.
            if (action !== 'notification_status') queuedActions.push([action, extra]);
            return;
        }
        pending = true;
        clearTimeout(timer);
        const message = sidebar.querySelector('.slimstat-notification-sidebar__message');
        const manual = action !== 'notification_status';
        if (manual) message.textContent = '';
        const failed = () => { if (manual) message.textContent = slimstat_admin.notification_error; };
        if (action === 'refresh_notifications') reload?.classList.add('is-loading');
        $.ajax({
            url: slimstat_admin.ajax_url, type: 'POST', dataType: 'json', timeout: 15000,
            data: Object.assign({ action: 'slimstat_' + action, slimstat_nonce: slimstat_admin.nonce }, extra),
        }).done(response => {
            if (response.success) apply(response.data);
            else failed();
        }).fail(failed).always(() => {
            pending = false;
            checked = Date.now();
            reload?.classList.remove('is-loading');
            const next = queuedActions.shift();
            if (next) refresh(...next);
            else schedule();
        });
    }
    reload?.addEventListener('click', () => refresh('refresh_notifications'));
    $(sidebar).on('click', '.slimstat-notification-sidebar__dismiss, .slimstat-notification-sidebar__dismiss-all', function (event) {
        event.preventDefault();
        refresh('dismiss_notification', { notification_id: this.classList.contains('slimstat-notification-sidebar__dismiss-all') ? 'all' : this.dataset.notificationId });
    });
    document.addEventListener('visibilitychange', () => { schedule(); updatePromotions(); });
    updateSidebarState();
    updatePromotions();
    schedule();
});
