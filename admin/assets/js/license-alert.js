/**
 * SlimStat Pro license activation alert — copy the coupon code to the clipboard
 * in one click, with a brief, accessible "copied" confirmation.
 *
 * Vanilla, dependency-free. Falls back to a hidden-textarea copy where the async
 * Clipboard API is unavailable, and degrades to plain selection if both fail
 * (the chip text stays selectable).
 *
 * @since 5.5.0
 */
(function () {
	'use strict';

	function fallbackCopy(text) {
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.setAttribute('readonly', '');
		ta.style.position = 'fixed';
		ta.style.top = '-9999px';
		document.body.appendChild(ta);
		ta.select();
		try {
			document.execCommand('copy');
		} catch (e) {}
		document.body.removeChild(ta);
	}

	function confirmCopied(button) {
		button.classList.add('is-copied');

		var meta = button.closest('.slimstat-license-alert__meta');
		var live = meta ? meta.querySelector('[data-slimstat-copy-live]') : null;
		if (live) {
			// Re-set to trigger the announcement even on a repeat copy.
			live.textContent = '';
			live.textContent = button.getAttribute('data-copied-label') || '';
		}

		window.clearTimeout(button.slimstatCopyTimer);
		button.slimstatCopyTimer = window.setTimeout(function () {
			button.classList.remove('is-copied');
			if (live) {
				live.textContent = '';
			}
		}, 1600);
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-slimstat-copy]');
		if (!button) {
			return;
		}

		var text = button.getAttribute('data-slimstat-copy');
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(
				function () {
					confirmCopied(button);
				},
				function () {
					fallbackCopy(text);
					confirmCopied(button);
				}
			);
		} else {
			fallbackCopy(text);
			confirmCopied(button);
		}
	});
})();
