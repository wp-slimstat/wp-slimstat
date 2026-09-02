jQuery(document).ready(function () {
    const modal = document.querySelector('.wrap-slimstat .slimstat-pro-modal');
    const scrim = document.querySelector('.slimstat-general-scrim');

    if (!modal || !scrim) {
        return;
    }

    // Remembers the trigger so focus can return to it on close — same
    // restore-focus convention as goals-funnels.js's dialogs (onDialogOpen/
    // onDialogClose), kept local here since that file's helpers are captured
    // over its own drawer/builder/sheet jQuery objects, not exported.
    let opener = null;

    function openModal(trigger) {
        opener = trigger;
        modal.classList.add('is-open');
        scrim.classList.add('is-open');
    }

    function closeModal() {
        modal.classList.remove('is-open');
        scrim.classList.remove('is-open');
        if (opener && typeof opener.focus === 'function') {
            opener.focus();
        }
        opener = null;
    }

    document.addEventListener('click', function (e) {
        const trigger = e.target.closest('[data-upgrade]');
        if (trigger) {
            openModal(trigger);
            return;
        }
        if (e.target === scrim) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
        }
    });

    // Per-box pager for a Pro account's un-gated "top N" boxes
    // (GeneralReports::renderPager()). Purely client-side — every page was
    // already rendered server-side (no new query/AJAX per click), this only
    // toggles which .slimstat-page is [hidden]. Scoped to the single
    // .slimstat-general-pager the click happened in, so General's several
    // independent boxes never page each other.
    document.querySelectorAll('.slimstat-general-pager').forEach(function (pager) {
        var pages = pager.querySelectorAll('.slimstat-page');
        var pageCount = pages.length;
        var prevBtn = pager.querySelector('.slimstat-general-pager-prev');
        var nextBtn = pager.querySelector('.slimstat-general-pager-next');
        var status = pager.querySelector('.slimstat-general-pager-status');
        var statusFormat = pager.dataset.statusFormat || 'Page %1$s of %2$s';

        function showPage(index) {
            pages.forEach(function (page, i) {
                page.hidden = i !== index;
            });
            pager.dataset.page = index;
            prevBtn.disabled = index === 0;
            nextBtn.disabled = index === pageCount - 1;
            if (status) {
                status.textContent = statusFormat
                    .replace('%1$s', index + 1)
                    .replace('%2$s', pageCount);
            }
        }

        prevBtn.addEventListener('click', function () {
            var current = parseInt(pager.dataset.page, 10) || 0;
            if (current > 0) {
                showPage(current - 1);
            }
        });

        nextBtn.addEventListener('click', function () {
            var current = parseInt(pager.dataset.page, 10) || 0;
            if (current < pageCount - 1) {
                showPage(current + 1);
            }
        });
    });
});
