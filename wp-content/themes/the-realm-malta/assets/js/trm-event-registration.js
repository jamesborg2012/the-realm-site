/**
 * On-site event registration modal (item 29).
 *
 * Vanilla JS, no dependencies. Opens an accessible modal from the "Register"
 * button, validates required fields client-side, submits via AJAX to
 * `trem_register_for_event`, and on success replaces the trigger with an inline
 * confirmation. Server-side re-checks (seat re-count, duplicate email) remain
 * the authoritative gate.
 */
(function () {
	'use strict';

	if (typeof trmEventReg === 'undefined') {
		return;
	}

	var trigger = document.querySelector('[data-trm-reg-trigger]');
	var overlay = document.querySelector('[data-trm-reg-overlay]');
	if (!trigger || !overlay) {
		return;
	}

	var modal = overlay.querySelector('.trm-event-reg__modal');
	var form = overlay.querySelector('[data-trm-reg-form]');
	var closeBtn = overlay.querySelector('[data-trm-reg-close]');
	var messageEl = overlay.querySelector('[data-trm-reg-message]');
	var submitBtn = overlay.querySelector('[data-trm-reg-submit]');
	var eventId = modal ? modal.getAttribute('data-event-id') : '';
	var lastFocused = null;

	var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

	function showMessage(text, isError) {
		if (!messageEl) {
			return;
		}
		messageEl.textContent = text;
		messageEl.hidden = false;
		messageEl.classList.toggle('is-error', !!isError);
		messageEl.classList.toggle('is-success', !isError);
	}

	function clearMessage() {
		if (messageEl) {
			messageEl.hidden = true;
			messageEl.textContent = '';
			messageEl.classList.remove('is-error', 'is-success');
		}
	}

	function openModal() {
		lastFocused = document.activeElement;
		overlay.hidden = false;
		document.body.classList.add('trm-event-reg-open');
		clearMessage();
		var first = form ? form.querySelector('input') : null;
		if (first) {
			first.focus();
		}
		document.addEventListener('keydown', onKeydown);
	}

	function closeModal() {
		overlay.hidden = true;
		document.body.classList.remove('trm-event-reg-open');
		document.removeEventListener('keydown', onKeydown);
		if (lastFocused && typeof lastFocused.focus === 'function') {
			lastFocused.focus();
		}
	}

	function onKeydown(event) {
		if (event.key === 'Escape') {
			event.preventDefault();
			closeModal();
			return;
		}
		if (event.key === 'Tab') {
			trapFocus(event);
		}
	}

	function trapFocus(event) {
		var nodes = Array.prototype.slice.call(modal.querySelectorAll(FOCUSABLE)).filter(function (el) {
			return el.offsetParent !== null || el === document.activeElement;
		});
		if (!nodes.length) {
			return;
		}
		var firstNode = nodes[0];
		var lastNode = nodes[nodes.length - 1];

		if (event.shiftKey && document.activeElement === firstNode) {
			event.preventDefault();
			lastNode.focus();
		} else if (!event.shiftKey && document.activeElement === lastNode) {
			event.preventDefault();
			firstNode.focus();
		}
	}

	function isValidEmail(value) {
		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
	}

	function submit(event) {
		event.preventDefault();
		clearMessage();

		var first = form.first_name.value.trim();
		var last = form.last_name.value.trim();
		var phone = form.phone.value.trim();
		var email = form.email.value.trim();

		if (!first || !last || !email) {
			showMessage(trmEventReg.messages.required, true);
			return;
		}
		if (!isValidEmail(email)) {
			showMessage(trmEventReg.messages.invalidEmail, true);
			return;
		}

		submitBtn.disabled = true;

		var body = new URLSearchParams();
		body.append('action', 'trem_register_for_event');
		body.append('nonce', trmEventReg.nonce);
		body.append('event_id', eventId);
		body.append('first_name', first);
		body.append('last_name', last);
		body.append('phone', phone);
		body.append('email', email);

		fetch(trmEventReg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (res) {
			return res.json();
		}).then(function (res) {
			if (res && res.success) {
				onSuccess(res.data && res.data.message);
			} else {
				submitBtn.disabled = false;
				showMessage((res && res.data && res.data.message) || trmEventReg.messages.error, true);
			}
		}).catch(function () {
			submitBtn.disabled = false;
			showMessage(trmEventReg.messages.error, true);
		});
	}

	function onSuccess(message) {
		// Replace the form with the confirmation and neutralise the trigger so
		// the same page can't trivially double-submit.
		if (form) {
			form.hidden = true;
		}
		showMessage(message || trmEventReg.messages.success, false);

		var cta = document.createElement('p');
		cta.className = 'trm-single-event__registered';
		cta.textContent = message || trmEventReg.messages.success;
		if (trigger.parentNode) {
			trigger.parentNode.replaceChild(cta, trigger);
		}
	}

	trigger.addEventListener('click', openModal);
	if (closeBtn) {
		closeBtn.addEventListener('click', closeModal);
	}
	overlay.addEventListener('click', function (event) {
		if (event.target === overlay) {
			closeModal();
		}
	});
	if (form) {
		form.addEventListener('submit', submit);
	}
})();
