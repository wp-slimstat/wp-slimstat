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
});
