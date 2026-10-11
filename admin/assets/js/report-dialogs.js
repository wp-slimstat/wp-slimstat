/* Native dialogs contain focus and handle Escape; retain report scroll on return. */
(() => {
    'use strict';
    let opener, openerReport, scrollX, scrollY;
    document.addEventListener('click', event => {
        const trigger = event.target.closest('[data-report-dialog]');
        if (trigger) {
            const dialog = document.getElementById(trigger.dataset.reportDialog);
            if (dialog && !dialog.open) dialog.showModal();
        }
        const close = event.target.closest('[data-dialog-close]');
        if (close) close.closest('dialog').close();
    });
    document.addEventListener('beforetoggle', event => {
        if (!event.target.matches('dialog.ss-report-dialog') || event.newState !== 'open') return;
        opener = document.activeElement; openerReport = opener?.closest('.postbox')?.id; scrollX = window.scrollX; scrollY = window.scrollY;
        document.documentElement.classList.add('ss-report-dialog-open');
    }, true);
    document.addEventListener('close', event => {
        if (!event.target.matches('dialog.ss-report-dialog')) return;
        document.documentElement.classList.remove('ss-report-dialog-open');
        const target = opener?.isConnected ? opener : document.getElementById(openerReport)?.querySelector('a.slimstat-utm-builder-link');
        target?.focus({ preventScroll: true });
        window.scrollTo(scrollX, scrollY);
    }, true);
})();
