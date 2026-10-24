jQuery(function ($) {
    const sidebar = document.querySelector('.slimstat-notification-sidebar');
    const overlay = document.querySelector('.slimstat-notification-sidebar__overlay');
    if (!sidebar || !overlay || sidebar.dataset.notificationsReady) return;
    sidebar.dataset.notificationsReady = 'true';
    const reload = sidebar.querySelector('.slimstat-notification-sidebar__reload');
    const dismissAll = sidebar.querySelector('.slimstat-notification-sidebar__dismiss-all');
    const tabs = sidebar.querySelectorAll('.slimstat-notification-sidebar__tab');
    let pending = false;
    let timer = 0;
    let checked = Date.now();

    function close() {
        sidebar.classList.remove('is-active');
        overlay.classList.remove('is-active');
        document.body.classList.remove('slimstat-no-scroll');
    }
    document.documentElement.style.setProperty('--scrollbar-width', (window.innerWidth - document.documentElement.clientWidth) + 'px');
    document.querySelectorAll('.js-slimstat-open-notification').forEach(button => {
        button.addEventListener('click', event => {
            event.preventDefault();
            const open = !sidebar.classList.contains('is-active');
            sidebar.classList.toggle('is-active', open);
            overlay.classList.toggle('is-active', open);
            document.body.classList.toggle('slimstat-no-scroll', open);
        });
    });
    overlay.addEventListener('click', close);
    sidebar.querySelector('.slimstat-notification-sidebar__close')?.addEventListener('click', close);
    document.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });

    function updateDismissAll() {
        const inbox = sidebar.querySelector('[data-tab="tab-1"]').classList.contains('slimstat-notification-sidebar__tab--active');
        const count = sidebar.querySelectorAll('#tab-1 .slimstat-notification-sidebar__card:not(.slimstat-notification-sidebar__no-card)').length;
        if (dismissAll) dismissAll.style.display = inbox && count ? 'inline-flex' : 'none';
    }
    tabs.forEach(tab => tab.addEventListener('click', () => {
        tabs.forEach(item => item.classList.toggle('slimstat-notification-sidebar__tab--active', item === tab));
        sidebar.querySelectorAll('.slimstat-notification-sidebar__tab-pane').forEach(pane => {
            pane.classList.toggle('slimstat-notification-sidebar__tab-pane--active', pane.id === tab.dataset.tab);
        });
        updateDismissAll();
    }));

    function apply(data) {
        $('#tab-1 .slimstat-notification-sidebar__cards').html(data.inbox_html);
        $('#tab-2 .slimstat-notification-sidebar__cards').html(data.dismissed_html);
        const count = Number(data.inbox_count) || 0;
        const label = new Intl.NumberFormat(document.documentElement.lang || undefined).format(count);
        $('.slimstat-notifications').toggleClass('slimstat-notifications--has-items', count > 0);
        $('.slimstat-header__badge, .notification-badge, .slimstat-menu-notification-count').remove();
        if (count) {
            $('<span class="slimstat-header__badge">').text(label).appendTo('.slimstat-header__icon--notifications');
            const badge = $('<span class="update-plugins slimstat-menu-notification-count">').addClass('count-' + count);
            $('<span class="plugin-count">').text(label).appendTo(badge);
            $('#adminmenu .wp-menu-name > .slimstat-menu-title').parent().append(badge);
        }
        updateDismissAll();
    }
    function schedule() {
        clearTimeout(timer);
        if (!document.hidden) timer = setTimeout(() => refresh('notification_status'), Math.max(0, 60000 - (Date.now() - checked)));
    }
    function refresh(action, extra) {
        if (pending || (action === 'notification_status' && document.hidden)) return;
        pending = true;
        clearTimeout(timer);
        if (action === 'refresh_notifications') reload?.classList.add('is-loading');
        $.ajax({
            url: slimstat_admin.ajax_url, type: 'POST', dataType: 'json', timeout: 15000,
            data: Object.assign({ action: 'slimstat_' + action, slimstat_nonce: slimstat_admin.nonce }, extra),
        }).done(response => {
            if (response.success) apply(response.data);
        }).always(() => {
            pending = false;
            checked = Date.now();
            reload?.classList.remove('is-loading');
            schedule();
        });
    }
    reload?.addEventListener('click', () => refresh('refresh_notifications'));
    $(sidebar).on('click', '.slimstat-notification-sidebar__dismiss, .slimstat-notification-sidebar__dismiss-all', function (event) {
        event.preventDefault();
        refresh('dismiss_notification', { notification_id: this.classList.contains('slimstat-notification-sidebar__dismiss-all') ? 'all' : this.dataset.notificationId });
    });
    document.addEventListener('visibilitychange', schedule);
    updateDismissAll();
    schedule();
});
