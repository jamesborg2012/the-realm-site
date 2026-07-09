/**
 * Admin registrations table — inline edit + single-row delete.
 *
 * Seats-taken is a live COUNT(*) server-side, so after an edit/delete we simply
 * refresh the on-screen count from the AJAX response — deleting a row frees a
 * seat with nothing to decrement locally.
 */
(function () {
	'use strict';

	if (typeof tremRegAdmin === 'undefined') {
		return;
	}

	var wrap = document.querySelector('.trem-reg-admin');
	if (!wrap) {
		return;
	}

	var countEl = wrap.querySelector('.trem-reg-admin__count');

	function post(action, data) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', tremRegAdmin.nonce);
		Object.keys(data).forEach(function (key) {
			body.append(key, data[key]);
		});

		return fetch(tremRegAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (res) {
			return res.json();
		});
	}

	function setCount(value) {
		if (countEl && typeof value !== 'undefined') {
			countEl.textContent = value;
		}
	}

	function setRowEditing(row, editing) {
		row.classList.toggle('is-editing', editing);
		row.querySelectorAll('.trem-reg-admin__view').forEach(function (el) {
			el.hidden = editing;
		});
		row.querySelectorAll('.trem-reg-admin__input').forEach(function (el) {
			el.hidden = !editing;
		});
		toggle(row, '.trem-reg-admin__edit', !editing);
		toggle(row, '.trem-reg-admin__save', editing);
		toggle(row, '.trem-reg-admin__cancel', editing);
	}

	function toggle(row, selector, show) {
		var btn = row.querySelector(selector);
		if (btn) {
			btn.hidden = !show;
		}
	}

	function fieldInput(row, field) {
		return row.querySelector('[data-field="' + field + '"] .trem-reg-admin__input');
	}

	function fieldView(row, field) {
		return row.querySelector('[data-field="' + field + '"] .trem-reg-admin__view');
	}

	function beginEdit(row) {
		// Snapshot current values so Cancel can restore them.
		['first_name', 'last_name', 'phone', 'email'].forEach(function (field) {
			var input = fieldInput(row, field);
			if (input) {
				input.dataset.original = input.value;
			}
		});
		setRowEditing(row, true);
		var first = fieldInput(row, 'first_name');
		if (first) {
			first.focus();
		}
	}

	function cancelEdit(row) {
		['first_name', 'last_name', 'phone', 'email'].forEach(function (field) {
			var input = fieldInput(row, field);
			if (input && typeof input.dataset.original !== 'undefined') {
				input.value = input.dataset.original;
			}
		});
		setRowEditing(row, false);
	}

	function saveEdit(row) {
		var payload = {
			id: row.getAttribute('data-id'),
			first_name: fieldInput(row, 'first_name').value.trim(),
			last_name: fieldInput(row, 'last_name').value.trim(),
			phone: fieldInput(row, 'phone').value.trim(),
			email: fieldInput(row, 'email').value.trim()
		};

		if (!payload.first_name || !payload.last_name || !payload.email) {
			window.alert(tremRegAdmin.i18n.required);
			return;
		}

		var saveBtn = row.querySelector('.trem-reg-admin__save');
		if (saveBtn) {
			saveBtn.disabled = true;
		}

		post('trem_admin_update_registration', payload).then(function (res) {
			if (saveBtn) {
				saveBtn.disabled = false;
			}
			if (!res || !res.success) {
				window.alert((res && res.data && res.data.message) || tremRegAdmin.i18n.genericError);
				return;
			}
			var data = res.data.data;
			['first_name', 'last_name', 'phone', 'email'].forEach(function (field) {
				var view = fieldView(row, field);
				var input = fieldInput(row, field);
				if (view) {
					view.textContent = data[field];
				}
				if (input) {
					input.value = data[field];
				}
			});
			setRowEditing(row, false);
			setCount(res.data.seats_taken);
		}).catch(function () {
			if (saveBtn) {
				saveBtn.disabled = false;
			}
			window.alert(tremRegAdmin.i18n.genericError);
		});
	}

	function deleteRow(row) {
		if (!window.confirm(tremRegAdmin.i18n.confirmDelete)) {
			return;
		}

		post('trem_admin_delete_registration', { id: row.getAttribute('data-id') }).then(function (res) {
			if (!res || !res.success) {
				window.alert((res && res.data && res.data.message) || tremRegAdmin.i18n.genericError);
				return;
			}
			row.parentNode.removeChild(row);
			setCount(res.data.seats_taken);
		}).catch(function () {
			window.alert(tremRegAdmin.i18n.genericError);
		});
	}

	wrap.addEventListener('click', function (event) {
		var target = event.target;
		if (!(target instanceof Element)) {
			return;
		}
		var row = target.closest('.trem-reg-admin__row');
		if (!row) {
			return;
		}

		if (target.classList.contains('trem-reg-admin__edit')) {
			beginEdit(row);
		} else if (target.classList.contains('trem-reg-admin__cancel')) {
			cancelEdit(row);
		} else if (target.classList.contains('trem-reg-admin__save')) {
			saveEdit(row);
		} else if (target.classList.contains('trem-reg-admin__delete')) {
			deleteRow(row);
		}
	});
})();
