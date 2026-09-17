(function () {
	'use strict';

	const root = document.querySelector('[data-tesla-charge]');
	if (!root || typeof gamingHubTeslaCharge === 'undefined') {
		return;
	}

	const endpoint = gamingHubTeslaCharge.url || '';

	function t(text) {
		return window.gamingHubT ? window.gamingHubT(text) : text;
	}

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

	function formatYen(value, known) {
		if (!known || value == null || value === '') {
			return '—';
		}
		return window.gamingHubYen
			? window.gamingHubYen(value)
			: Math.round(Number(value)).toLocaleString() + ' 円';
	}

	function formatRate(value) {
		if (value == null || value === '') {
			return '—';
		}
		const amount = Number(value).toFixed(1);
		if (window.gamingHubLang && window.gamingHubLang() === 'en') {
			return '¥' + amount + '/kWh';
		}
		return amount + ' 円/kWh';
	}

	function setText(sel, text) {
		const el = root.querySelector(sel);
		if (el) {
			el.textContent = text == null ? '' : String(text);
		}
	}

	function lastMeta(session) {
		if (session.yen_known) {
			return formatRate(session.yen_per_kwh);
		}
		if (session.supply === 'supercharger' && session.peak_w) {
			return Math.round(Number(session.peak_w)).toLocaleString() + ' W';
		}
		return formatRate(session.yen_per_kwh);
	}

	function rowHtml(session, active) {
		const isSuper = session.supply === 'supercharger';
		const limit = session.limit_soc
			? '<span class="tesla-charge-limit">' + esc(t('Limit %s%%').replace('%s', String(session.limit_soc))) + '</span>'
			: '';
		const badge = active
			? '<span class="tesla-charge-badge">' + esc(t('In progress')) + '</span>'
			: '';
		const supply = '<span class="tesla-charge-supply">' + esc(session.supply_label || (isSuper ? t('Supercharger') : t('Home charging'))) + '</span>';
		const site = session.site_name
			? '<span class="tesla-charge-site">' + esc(session.site_name) + '</span>'
			: '';
		return (
			'<li class="tesla-charge-row' +
			(active ? ' is-active' : '') +
			(isSuper ? ' is-super' : '') +
			'"' +
			(active ? ' data-tesla-charge-current' : '') +
			'>' +
			'<div class="tesla-charge-when">' +
			'<strong>' + esc(session.when_label || (active ? t('Charging') : '—')) + '</strong>' +
			badge +
			supply +
			site +
			limit +
			'</div>' +
			'<div class="tesla-charge-meta">' +
			'<span>' + esc(session.range_label || '—') + '</span>' +
			'<span>' + esc(session.duration_label || '—') + '</span>' +
			'<span>' + esc(formatKwh(session.kwh)) + '</span>' +
			'<span>' + esc(formatYen(session.yen, !!session.yen_known)) + '</span>' +
			'<span>' + esc(lastMeta(session)) + '</span>' +
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

		let nowLabel = t('Standby');
		if (current) {
			nowLabel = current.supply === 'supercharger' ? t('Supercharging') : t('Charging');
		}
		setText('[data-tesla-charge-now]', nowLabel);
		if (current) {
			const detail = [current.supply_label || '', current.range_label || '', current.kwh != null ? Number(current.kwh).toFixed(2) + ' kWh' : '']
				.filter(Boolean)
				.join(' · ');
			setText('[data-tesla-charge-now-detail]', detail || '—');
		} else {
			setText('[data-tesla-charge-now-detail]', '—');
		}

		setText('[data-tesla-charge-count]', String(totals.count || 0));
		setText(
			'[data-tesla-charge-count-detail]',
			t('Home %1$s · Super %2$s')
				.replace('%1$s', String(totals.home_count || 0))
				.replace('%2$s', String(totals.super_count || 0))
		);
		setText('[data-tesla-charge-kwh]', formatKwh(totals.kwh || 0));
		setText(
			'[data-tesla-charge-kwh-detail]',
			t('Home %1$s · Super %2$s')
				.replace('%1$s', Number(totals.home_kwh || 0).toFixed(2))
				.replace('%2$s', Number(totals.super_kwh || 0).toFixed(2))
		);
		setText('[data-tesla-charge-yen]', formatYen(totals.yen || 0, true));
		setText(
			'[data-tesla-charge-yen-detail]',
			t('Home %1$s · Super %2$s')
				.replace('%1$s', Math.round(Number(totals.home_yen || 0)).toLocaleString())
				.replace('%2$s', Math.round(Number(totals.super_yen || 0)).toLocaleString())
		);

		const hiddenEl = root.querySelector('[data-tesla-charge-hidden]');
		if (hiddenEl) {
			const hiddenCount = Number(totals.hidden_count) || 0;
			if (hiddenCount > 0) {
				hiddenEl.hidden = false;
				hiddenEl.textContent = ' ' + t('Brief wake/precondition pulses hidden: %d.')
					.replace('%d', String(hiddenCount));
			} else {
				hiddenEl.hidden = true;
				hiddenEl.textContent = '';
			}
		}

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
				esc(t('No charge sessions this month yet. They appear after the next home/Supercharger session or a Fleet history sync.')) +
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
		let url = endpoint + (month ? '?month=' + encodeURIComponent(month) : '');
		if (window.gamingHubWithLang) {
			url = window.gamingHubWithLang(url);
		}
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
