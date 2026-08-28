(function () {
	'use strict';

	const root = document.querySelector('[data-tesla-charge]');
	if (!root || typeof gamingHubTeslaCharge === 'undefined') {
		return;
	}

	const endpoint = gamingHubTeslaCharge.url || '';

	function esc(text) {
		const d = document.createElement('div');
		d.textContent = text == null ? '' : String(text);
		return d.innerHTML;
	}

	function formatKwh(value) {
		if (value == null || value === '') {
			return '—';
		}
		return Number(value).toFixed(2) + ' kWh';
	}

	function formatYen(value) {
		if (value == null || value === '') {
			return '—';
		}
		return Math.round(Number(value)).toLocaleString() + ' 円';
	}

	function formatRate(value) {
		if (value == null || value === '') {
			return '—';
		}
		return Number(value).toFixed(1) + ' 円/kWh';
	}

	function setText(sel, text) {
		const el = root.querySelector(sel);
		if (el) {
			el.textContent = text == null ? '' : String(text);
		}
	}

	function rowHtml(session, active) {
		const limit = session.limit_soc
			? '<span class="tesla-charge-limit">' + esc('上限 ' + session.limit_soc + '%') + '</span>'
			: '';
		const badge = active
			? '<span class="tesla-charge-badge">' + esc('進行中') + '</span>'
			: '';
		return (
			'<li class="tesla-charge-row' + (active ? ' is-active' : '') + '"' +
			(active ? ' data-tesla-charge-current' : '') + '>' +
			'<div class="tesla-charge-when">' +
			'<strong>' + esc(session.when_label || (active ? '充電中' : '—')) + '</strong>' +
			badge + limit +
			'</div>' +
			'<div class="tesla-charge-meta">' +
			'<span>' + esc(session.range_label || '—') + '</span>' +
			'<span>' + esc(session.duration_label || '—') + '</span>' +
			'<span>' + esc(formatKwh(session.kwh)) + '</span>' +
			'<span>' + esc(formatYen(session.yen)) + '</span>' +
			'<span>' + esc(formatRate(session.yen_per_kwh)) + '</span>' +
			'</div>' +
			'</li>'
		);
	}

	function paint(data) {
		if (!data) {
			return;
		}

		root.setAttribute('data-month', data.month || '');
		setText('[data-tesla-charge-label]', data.label || '');

		const prev = root.querySelector('[data-tesla-charge-prev]');
		const next = root.querySelector('[data-tesla-charge-next]');
		if (prev) {
			prev.setAttribute('data-month', data.prev || '');
		}
		if (next) {
			next.setAttribute('data-month', data.next || '');
		}

		const totals = data.totals || {};
		const current = data.current || null;
		const sessions = Array.isArray(data.sessions) ? data.sessions : [];

		setText('[data-tesla-charge-now]', current ? '充電中' : '待機');
		if (current) {
			const detail = [current.range_label || '', current.kwh != null ? Number(current.kwh).toFixed(2) + ' kWh' : '']
				.filter(Boolean)
				.join(' · ');
			setText('[data-tesla-charge-now-detail]', detail || '—');
		} else {
			setText('[data-tesla-charge-now-detail]', '—');
		}

		setText('[data-tesla-charge-count]', String(totals.count || 0));
		setText('[data-tesla-charge-kwh]', formatKwh(totals.kwh || 0));
		setText('[data-tesla-charge-yen]', formatYen(totals.yen || 0));

		const list = root.querySelector('[data-tesla-charge-list]');
		if (!list) {
			return;
		}

		let html = '';
		if (current) {
			html += rowHtml(current, true);
		}
		if (!sessions.length && !current) {
			html +=
				'<li class="tesla-charge-empty" data-tesla-charge-empty>' +
				esc('この月の充電セッションはまだありません。次回の自宅充電から記録されます。') +
				'</li>';
		}
		sessions.forEach(function (session) {
			html += rowHtml(session, false);
		});
		list.innerHTML = html;
	}

	function load(month) {
		if (!endpoint) {
			return;
		}
		const url = endpoint + (month ? '?month=' + encodeURIComponent(month) : '');
		fetch(url, { credentials: 'same-origin' })
			.then(function (res) {
				return res.json();
			})
			.then(function (json) {
				if (json && json.success && json.data) {
					paint(json.data);
				}
			})
			.catch(function () {
				/* ignore */
			});
	}

	root.querySelectorAll('[data-tesla-charge-prev], [data-tesla-charge-next]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			const month = btn.getAttribute('data-month') || '';
			if (month) {
				load(month);
			}
		});
	});

	document.addEventListener('gamingHubPowerwallFlow', function () {
		load(root.getAttribute('data-month') || '');
	});
	document.addEventListener('gamingHubTeslaFlow', function () {
		load(root.getAttribute('data-month') || '');
	});
})();
