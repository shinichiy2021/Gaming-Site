(function () {
	'use strict';

	const root = document.querySelector('[data-tesla-gas]');
	if (!root || !window.gamingHubTeslaGas) {
		return;
	}

	const endpoint = gamingHubTeslaGas.url || '';
	const weekdays = ['日', '月', '火', '水', '木', '金', '土'];
	let summaryData = parseSummary(root.getAttribute('data-summary'));
	let summaryPeriod = 'day';

	function t(text) {
		return window.gamingHubT ? window.gamingHubT(text) : text;
	}

	function esc(text) {
		const d = document.createElement('div');
		d.textContent = text == null ? '' : String(text);
		return d.innerHTML;
	}

	function parseSummary(raw) {
		if (!raw) {
			return { day: null, week: null };
		}
		try {
			const parsed = JSON.parse(raw);
			return parsed && typeof parsed === 'object' ? parsed : { day: null, week: null };
		} catch (err) {
			return { day: null, week: null };
		}
	}

	function formatKm(value) {
		if (value === null || value === undefined || value === '') {
			return '—';
		}
		return Number(value).toFixed(1) + ' km';
	}

	function formatL(value) {
		if (value === null || value === undefined || value === '') {
			return '—';
		}
		return Number(value).toFixed(2) + ' L';
	}

	function formatYen(value) {
		if (value === null || value === undefined || value === '') {
			return '—';
		}
		return Math.round(Number(value)).toLocaleString() + ' 円';
	}

	function formatAvg(value) {
		if (value === null || value === undefined || value === '') {
			return '—';
		}
		return Number(value).toFixed(1) + ' 円/km';
	}

	function formatWhen(ymd) {
		const parts = String(ymd || '').split('-');
		if (parts.length !== 3) {
			return String(ymd || '—');
		}
		const dt = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
		if (Number.isNaN(dt.getTime())) {
			return String(ymd);
		}
		return (dt.getMonth() + 1) + '/' + dt.getDate() + '（' + weekdays[dt.getDay()] + '）';
	}

	function formatNow(now) {
		const yenH = now && Number(now.saved_yen_per_h);
		if (!now || now.asleep || !yenH) {
			return t('待機');
		}
		return Math.round(yenH).toLocaleString() + ' 円/時';
	}

	function setText(sel, text) {
		const el = root.querySelector(sel);
		if (el) {
			el.textContent = text == null ? '' : String(text);
		}
	}

	function paintSummary(period) {
		summaryPeriod = period === 'week' ? 'week' : 'day';
		const slice = summaryData && summaryData[summaryPeriod] ? summaryData[summaryPeriod] : null;

		root.querySelectorAll('[data-tesla-gas-summary-tab]').forEach(function (btn) {
			const active = btn.getAttribute('data-tesla-gas-summary-tab') === summaryPeriod;
			btn.classList.toggle('is-active', active);
			btn.setAttribute('aria-selected', active ? 'true' : 'false');
		});

		if (!slice) {
			setText('[data-tesla-gas-summary-label]', summaryPeriod === 'week' ? t('今週') : t('今日'));
			setText('[data-tesla-gas-summary-km]', '—');
			setText('[data-tesla-gas-summary-ev]', '—');
			setText('[data-tesla-gas-summary-save]', '—');
			setText('[data-tesla-gas-summary-avg]', '—');
			return;
		}

		setText('[data-tesla-gas-summary-label]', slice.label || (summaryPeriod === 'week' ? t('今週') : t('今日')));
		setText('[data-tesla-gas-summary-km]', formatKm(slice.km || 0));
		setText('[data-tesla-gas-summary-ev]', formatYen(slice.ev_yen));
		setText('[data-tesla-gas-summary-save]', formatYen(slice.saved_yen));
		setText('[data-tesla-gas-summary-avg]', formatAvg(slice.avg_yen_per_km));
	}

	function rowHtml(cell, today) {
		const date = String(cell.date || '');
		const isToday = !!cell.is_today || date === today;
		return (
			'<li class="tesla-charge-row' + (isToday ? ' is-active' : '') + '" data-tesla-gas-day="' + esc(date) + '">' +
			'<div class="tesla-charge-when">' +
			'<strong>' + esc(formatWhen(date)) + '</strong>' +
			(isToday ? '<span class="tesla-charge-badge">' + esc(t('今日')) + '</span>' : '') +
			'</div>' +
			'<div class="tesla-charge-meta">' +
			'<span>' + esc(formatKm(cell.km)) + '</span>' +
			'<span>' + esc(formatL(cell.gas_l)) + '</span>' +
			'<span>' + esc(formatYen(cell.ev_yen)) + '</span>' +
			'<span>' + esc(formatYen(cell.saved_yen)) + '</span>' +
			'</div>' +
			'</li>'
		);
	}

	function paint(data) {
		if (!data) {
			return;
		}

		root.setAttribute('data-month', data.month || '');
		setText('[data-tesla-gas-label]', data.label || '');

		const prev = root.querySelector('[data-tesla-gas-prev]');
		const next = root.querySelector('[data-tesla-gas-next]');
		if (prev) {
			prev.setAttribute('data-month', data.prev || '');
		}
		if (next) {
			next.setAttribute('data-month', data.next || '');
		}

		const now = data.now || {};
		const totals = data.totals || {};
		const todaySt = data.today_stats || {};
		const today = String(data.today || '');

		if (data.summary) {
			summaryData = data.summary;
			root.setAttribute('data-summary', JSON.stringify(data.summary));
			paintSummary(summaryPeriod);
		}

		setText('[data-tesla-gas-now]', formatNow(now));
		setText('[data-tesla-gas-now-speed]', t('%s km/h').replace('%s', String(Math.round(Number(now.speed_km) || 0))));
		if (now.price_label) {
			setText('[data-tesla-gas-price]', now.price_label);
		}

		setText('[data-tesla-gas-month-km]', formatKm(totals.km || 0));
		setText('[data-tesla-gas-month-save]', formatYen(totals.saved_yen));
		setText('[data-tesla-gas-month-l]', formatL(totals.gas_l || 0));
		setText('[data-tesla-gas-today-km]', t('今日 %s').replace('%s', formatKm(todaySt.km || 0)));
		setText('[data-tesla-gas-today-save]', t('今日 %s').replace('%s', formatYen(todaySt.saved_yen || 0)));
		setText('[data-tesla-gas-today-l]', t('今日 %s').replace('%s', formatL(todaySt.gas_l || 0)));

		const days = Array.isArray(data.days) ? data.days.slice() : [];
		const rows = days.filter(function (cell) {
			return !!cell && !!cell.has_data;
		}).reverse();

		const list = root.querySelector('[data-tesla-gas-list]');
		if (!list) {
			return;
		}

		if (!rows.length) {
			list.innerHTML =
				'<li class="tesla-charge-empty" data-tesla-gas-empty>' +
				esc(t('この月の走行ログはまだありません。')) +
				'</li>';
			return;
		}

		list.innerHTML = rows.map(function (cell) {
			return rowHtml(cell, today);
		}).join('');
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
				/* keep painted month */
			});
	}

	root.querySelectorAll('[data-tesla-gas-prev], [data-tesla-gas-next]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			const month = btn.getAttribute('data-month') || '';
			if (month) {
				load(month);
			}
		});
	});

	root.querySelectorAll('[data-tesla-gas-summary-tab]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			paintSummary(btn.getAttribute('data-tesla-gas-summary-tab') || 'day');
		});
	});

	document.addEventListener('gamingHubTeslaFlow', function (event) {
		const tesla = event && event.detail ? event.detail : null;
		const gas = tesla && tesla.gas ? tesla.gas : null;
		if (!gas) {
			return;
		}
		setText('[data-tesla-gas-now]', formatNow({
			saved_yen_per_h: gas.saved_yen_per_h,
			asleep: !!tesla.asleep,
			speed_km: tesla.speed_km,
			price_label: gas.price_label,
		}));
		setText(
			'[data-tesla-gas-now-speed]',
			t('%s km/h').replace('%s', String(Math.round(Number(tesla.speed_km) || 0)))
		);
		if (gas.price_label) {
			setText('[data-tesla-gas-price]', gas.price_label);
		}
	});

	document.addEventListener('gamingHubPowerwallFlow', function () {
		load(root.getAttribute('data-month') || '');
	});
})();
