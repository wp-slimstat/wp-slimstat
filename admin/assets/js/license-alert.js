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

	function persistView(view) {
		var cfg = window.SlimStatLicenseAlert;
		if (!cfg || !cfg.ajaxUrl) {
			return;
		}
		var body = new URLSearchParams();
		body.append('action', cfg.action);
		body.append('nonce', cfg.nonce);
		body.append('view', view);
		window
			.fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.catch(function () {});
	}

	document.addEventListener('click', function (event) {
		// Minimize / expand.
		var toggle = event.target.closest('[data-slimstat-alert-toggle]');
		if (toggle) {
			var panel = toggle.closest('.slimstat-license-alert');
			if (!panel) {
				return;
			}
			var minimized = panel.classList.toggle('is-minimized');
			toggle.setAttribute('aria-expanded', minimized ? 'false' : 'true');
			var label = minimized
				? toggle.getAttribute('data-label-expand')
				: toggle.getAttribute('data-label-minimize');
			if (label) {
				toggle.setAttribute('aria-label', label);
			}
			persistView(minimized ? 'min' : 'full');
			return;
		}

		// Dismiss (snoozed server-side).
		var dismiss = event.target.closest('[data-slimstat-alert-dismiss]');
		if (dismiss) {
			var alert = dismiss.closest('.slimstat-license-alert');
			if (alert && alert.parentNode) {
				alert.parentNode.removeChild(alert);
			}
			persistView('dismissed');
			return;
		}

		// Copy the coupon code.
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
