/* Native dialogs contain focus and handle Escape; retain report scroll on return. */
(() => {
    'use strict';
    const positions = new WeakMap();
    function remember(dialog) {
        const opener = document.activeElement;
        positions.set(dialog, { opener, report: opener?.closest('.postbox')?.id, x: window.scrollX, y: window.scrollY });
        document.documentElement.classList.add('ss-report-dialog-open');
    }
    window.SlimStatReportDialogs = {
        open(dialog) {
            if (!dialog || dialog.open) return;
            remember(dialog);
            dialog.showModal();
        },
    };
    document.addEventListener('click', event => {
        const trigger = event.target.closest('[data-report-dialog]');
        if (trigger) {
            const dialog = document.getElementById(trigger.dataset.reportDialog);
            window.SlimStatReportDialogs.open(dialog);
        }
        const close = event.target.closest('[data-dialog-close]');
        if (close) close.closest('dialog').close();
    });
    document.addEventListener('beforetoggle', event => {
        if (!event.target.matches('dialog.ss-report-dialog') || event.newState !== 'open') return;
        if (!positions.has(event.target)) remember(event.target);
    }, true);
    document.addEventListener('close', event => {
        if (!event.target.matches('dialog.ss-report-dialog')) return;
        if (!document.querySelector('dialog.ss-report-dialog[open]')) document.documentElement.classList.remove('ss-report-dialog-open');
        const position = positions.get(event.target);
        if (!position) return;
        positions.delete(event.target);
        const target = position.opener?.isConnected ? position.opener : document.getElementById(position.report)?.querySelector('a.slimstat-utm-builder-link');
        target?.focus({ preventScroll: true });
        window.scrollTo(position.x, position.y);
    }, true);
})();
