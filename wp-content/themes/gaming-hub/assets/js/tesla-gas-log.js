(function () {
	'use strict';

	const root = document.querySelector('[data-tesla-gas]');
	if (!root || !window.gamingHubTeslaGas) {
		return;
	}

	function t(text) {
		return window.gamingHubT ? window.gamingHubT(text) : text;
	}

	function formatKm(value, digits) {
		if (value === null || value === undefined) {
			return '—';
		}
		return Number(value).toLocaleString(undefined, {
			minimumFractionDigits: digits === undefined ? 1 : digits,
			maximumFractionDigits: digits === undefined ? 1 : digits,
		}) + ' km';
	}

	function formatL(value) {
		if (value === null || value === undefined) {
			return '—';
		}
		return Number(value).toLocaleString(undefined, {
			minimumFractionDigits: 2,
			maximumFractionDigits: 2,
		}) + ' L';
	}

	function formatYen(value) {
		if (value === null || value === undefined) {
			return '—';
		}
		return Math.round(Number(value)).toLocaleString() + ' 円';
	}

	function formatNow(now) {
		const yenH = now && Number(now.saved_yen_per_h);
		if (!now || now.asleep || !yenH) {
			return t('待機');
		}
		return Math.round(yenH).toLocaleString() + ' 円/時';
	}

	function energyNiceMax(value) {
		const v = Math.max(0, Number(value) || 0);
		if (v <= 0) {
			return 1;
		}
		const exp = Math.pow(10, Math.floor(Math.log10(v)));
		const n = v / exp;
		const nice = n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10;
		return nice * exp;
	}

	function energyTicks(max, steps) {
		const top = energyNiceMax(max);
		const ticks = [];
		const count = steps || 4;
		for (let i = 0; i <= count; i += 1) {
			ticks.push(top - (top * i / count));
		}
		return { max: top, ticks: ticks };
	}

	function formatTick(value, yen) {
		const n = Number(value) || 0;
		if (yen) {
			return n >= 10 ? String(Math.round(n)) : n.toFixed(1);
		}
		if (n >= 10) {
			return String(Math.round(n));
		}
		return n.toFixed(n >= 1 ? 1 : 2);
	}

	function polylineFromValues(values, max) {
		const n = Math.max(1, values.length);
		const top = Math.max(1, Number(max) || 1);
		return values.map(function (value, i) {
			const x = ((i + 0.5) / n) * 100;
			const y = Math.max(0, Math.min(100, 100 - (Math.max(0, Number(value) || 0) / top) * 100));
			return x.toFixed(2) + ',' + y.toFixed(1);
		}).join(' ');
	}

	function setTickTexts(nodes, ticks, yen) {
		nodes.forEach(function (el, i) {
			if (ticks[i] !== undefined) {
				el.textContent = formatTick(ticks[i], yen);
			}
		});
	}

	function applyNow(now) {
		if (!now) {
			return;
		}
		const nowEl = root.querySelector('[data-tesla-gas-now]');
		const speedEl = root.querySelector('[data-tesla-gas-now-speed]');
		const priceEl = root.querySelector('[data-tesla-gas-price]');
		if (nowEl) {
			nowEl.textContent = formatNow(now);
		}
		if (speedEl) {
			speedEl.textContent = t('%s km/h').replace('%s', String(Math.round(Number(now.speed_km) || 0)));
		}
		if (priceEl && now.price_label) {
			priceEl.textContent = now.price_label;
		}
	}

	function applyTodayStats(stats) {
		if (!stats) {
			return;
		}
		const kmEl = root.querySelector('[data-tesla-gas-today-km]');
		const lEl = root.querySelector('[data-tesla-gas-today-l]');
		const evEl = root.querySelector('[data-tesla-gas-today-ev]');
		const saveEl = root.querySelector('[data-tesla-gas-today-save]');
		if (kmEl) {
			kmEl.textContent = formatKm(stats.km);
		}
		if (lEl) {
			lEl.textContent = formatL(stats.gas_l);
		}
		if (evEl) {
			evEl.textContent = formatYen(stats.ev_yen);
		}
		if (saveEl) {
			saveEl.textContent = formatYen(stats.saved_yen);
		}
	}

	function applyTotals(totals) {
		if (!totals) {
			return;
		}
		const kmEl = root.querySelector('[data-tesla-gas-month-km]');
		const saveEl = root.querySelector('[data-tesla-gas-month-save]');
		const lEl = root.querySelector('[data-tesla-gas-month-l]');
		if (kmEl) {
			kmEl.textContent = formatKm(totals.km);
		}
		if (saveEl) {
			saveEl.textContent = formatYen(totals.saved_yen);
		}
		if (lEl) {
			lEl.textContent = formatL(totals.gas_l);
		}
	}

	function renderTodayChart(data) {
		const wrap = root.querySelector('[data-tesla-gas-today-chart]');
		const hours = data.today_hours;
		if (!wrap) {
			return;
		}
		if (!Array.isArray(hours) || !hours.length) {
			wrap.hidden = true;
			return;
		}
		wrap.hidden = false;

		let maxKm = 0;
		let maxYen = 0;
		hours.forEach(function (row) {
			maxKm = Math.max(maxKm, Number(row.km) || 0);
			maxYen = Math.max(maxYen, Number(row.saved_yen) || 0);
		});
		const kmAxis = energyTicks(data.today_km_max || maxKm);
		const yenAxis = energyTicks(data.today_yen_max || maxYen);
		setTickTexts(root.querySelectorAll('[data-tesla-gas-today-km-tick]'), kmAxis.ticks, false);
		setTickTexts(root.querySelectorAll('[data-tesla-gas-today-yen-tick]'), yenAxis.ticks, true);

		const yenLine = root.querySelector('[data-tesla-gas-today-yen-line]');
		if (yenLine) {
			yenLine.setAttribute('points', polylineFromValues(hours.map(function (row) {
				return row.saved_yen;
			}), yenAxis.max));
		}

		const track = root.querySelector('[data-tesla-gas-today-track]');
		if (!track) {
			return;
		}
		const nowHour = new Date().getHours();
		track.querySelectorAll('[data-tesla-gas-today-col]').forEach(function (col) {
			col.remove();
		});
		const yenSvg = track.querySelector('.ecoflow-price-line');
		hours.forEach(function (row) {
			const hour = Number(row.hour);
			const isNow = hour === nowHour;
			const hasData = !!row.has_data;
			const km = row.km;
			const height = km === null || km === undefined
				? 0
				: Math.max(0, Math.min(100, (Number(km) / kmAxis.max) * 100));
			const col = document.createElement('div');
			col.className = 'ecoflow-rate-col ecoflow-cal-col'
				+ (isNow ? ' is-now' : '')
				+ (!hasData ? ' is-empty' : '');
			col.setAttribute('data-tesla-gas-today-col', '');
			col.setAttribute('data-hour', String(hour));
			if (isNow) {
				const pip = document.createElement('span');
				pip.className = 'ecoflow-rate-now-pip';
				pip.textContent = t('NOW');
				col.appendChild(pip);
			}
			const bar = document.createElement('span');
			bar.className = 'ecoflow-rate-bar ecoflow-cal-pv-bar';
			bar.style.height = height.toFixed(1) + '%';
			const tip = [hour + ':00'];
			if (km !== null && km !== undefined) {
				tip.push(formatKm(km));
			}
			if (row.saved_yen !== null && row.saved_yen !== undefined) {
				tip.push(formatYen(row.saved_yen));
			}
			bar.setAttribute('title', tip.join(' · '));
			col.appendChild(bar);
			if (yenSvg) {
				track.insertBefore(col, yenSvg);
			} else {
				track.appendChild(col);
			}
		});
	}

	function renderMonthChart(data) {
		const days = data.days || [];
		if (!days.length) {
			return;
		}

		let maxKm = 0;
		let maxYen = 0;
		days.forEach(function (day) {
			maxKm = Math.max(maxKm, Number(day.km) || 0);
			maxYen = Math.max(maxYen, Number(day.saved_yen) || 0);
		});
		const kmAxis = energyTicks(data.km_max || maxKm);
		const yenAxis = energyTicks(data.yen_max || maxYen);
		setTickTexts(root.querySelectorAll('[data-tesla-gas-km-tick]'), kmAxis.ticks, false);
		setTickTexts(root.querySelectorAll('[data-tesla-gas-yen-tick]'), yenAxis.ticks, true);

		const yenLine = root.querySelector('[data-tesla-gas-yen-line]');
		if (yenLine) {
			yenLine.setAttribute('points', polylineFromValues(days.map(function (day) {
				return day.saved_yen;
			}), yenAxis.max));
		}

		const track = root.querySelector('[data-tesla-gas-track]');
		const hoursRow = root.querySelector('[data-tesla-gas-hours]');
		if (!track) {
			return;
		}
		track.querySelectorAll('[data-tesla-gas-col]').forEach(function (col) {
			col.remove();
		});
		const yenSvg = track.querySelector('.ecoflow-price-line');
		const today = data.today || '';
		days.forEach(function (day) {
			const isToday = !!day.is_today;
			const hasData = !!day.has_data;
			const isFuture = today && day.date > today;
			const km = day.km;
			const height = km === null || km === undefined
				? 0
				: Math.max(0, Math.min(100, (Number(km) / kmAxis.max) * 100));
			const col = document.createElement('div');
			col.className = 'ecoflow-rate-col ecoflow-cal-col'
				+ (isToday ? ' is-now' : '')
				+ (!hasData || isFuture ? ' is-empty' : '');
			col.setAttribute('data-tesla-gas-col', '');
			col.setAttribute('data-date', day.date || '');
			if (isToday) {
				const pip = document.createElement('span');
				pip.className = 'ecoflow-rate-now-pip';
				pip.textContent = t('NOW');
				col.appendChild(pip);
			}
			const bar = document.createElement('span');
			bar.className = 'ecoflow-rate-bar ecoflow-cal-pv-bar';
			bar.style.height = height.toFixed(1) + '%';
			const tip = [day.date || ''];
			if (hasData) {
				tip.push(formatKm(day.km));
				tip.push(formatL(day.gas_l));
				tip.push(formatYen(day.saved_yen));
			}
			bar.setAttribute('title', tip.join(' · '));
			col.appendChild(bar);
			if (yenSvg) {
				track.insertBefore(col, yenSvg);
			} else {
				track.appendChild(col);
			}
		});

		if (hoursRow) {
			hoursRow.innerHTML = '';
			days.forEach(function (day) {
				const d = Number(day.day) || 0;
				const isToday = !!day.is_today;
				const show = d === 1 || d % 5 === 0 || isToday;
				const el = document.createElement('span');
				el.className = 'ecoflow-rate-hour' + (isToday ? ' is-now' : '');
				el.textContent = show ? String(d) : '';
				hoursRow.appendChild(el);
			});
		}
	}

	function paintDayCell(cellEl, day) {
		if (!cellEl || !day) {
			return;
		}
		cellEl.classList.toggle('is-now', !!day.is_today);
		cellEl.classList.toggle('is-solar', !!day.has_data);
		cellEl.classList.toggle('is-charge', !!day.has_data && Number(day.saved_yen) > 0);
		cellEl.classList.toggle('is-past', !day.is_today && !day.has_data);
		const map = {
			km: formatKm(day.km),
			l: formatL(day.gas_l),
			save: formatYen(day.saved_yen),
		};
		Object.keys(map).forEach(function (key) {
			const el = cellEl.querySelector('[data-k="' + key + '"]');
			if (el) {
				el.textContent = map[key];
			}
		});
	}

	function renderCalendar(data) {
		if (!data) {
			return;
		}

		root.setAttribute('data-month', data.month || '');
		const label = root.querySelector('[data-tesla-gas-label]');
		if (label && data.label) {
			label.textContent = data.label;
		}
		const prevBtn = root.querySelector('[data-tesla-gas-prev]');
		const nextBtn = root.querySelector('[data-tesla-gas-next]');
		if (prevBtn && data.prev) {
			prevBtn.setAttribute('data-month', data.prev);
		}
		if (nextBtn && data.next) {
			nextBtn.setAttribute('data-month', data.next);
		}

		const isCurrentMonth = !!data.today && data.month === String(data.today).slice(0, 7);
		const todayHud = root.querySelector('[data-tesla-gas-today]');
		if (todayHud) {
			todayHud.hidden = !isCurrentMonth;
		}

		applyNow(data.now);
		applyTotals(data.totals);
		if (isCurrentMonth) {
			applyTodayStats(data.today_stats);
		}
		renderTodayChart(data);
		renderMonthChart(data);

		const grid = root.querySelector('[data-tesla-gas-grid]');
		if (!grid || !Array.isArray(data.days)) {
			return;
		}

		grid.innerHTML = '';
		const startW = Number(data.start_wday) || 0;
		const today = data.today || '';
		for (let i = 0; i < startW; i += 1) {
			const blank = document.createElement('li');
			blank.className = 'ecoflow-plan-slot is-blank';
			grid.appendChild(blank);
		}

		data.days.forEach(function (day) {
			const isPast = !day.is_today && today && day.date < today;
			const cell = document.createElement('li');
			cell.className = 'ecoflow-plan-slot'
				+ (day.is_today ? ' is-now' : '')
				+ (day.has_data ? ' is-solar' : '')
				+ (day.has_data && Number(day.saved_yen) > 0 ? ' is-charge' : '')
				+ (isPast && !day.has_data ? ' is-past' : '');
			cell.setAttribute('data-tesla-gas-day', day.date);

			const hourEl = document.createElement('span');
			hourEl.className = 'ecoflow-plan-slot-hour';
			hourEl.textContent = String(day.day);
			cell.appendChild(hourEl);

			[
				['ecoflow-plan-slot-watts', 'KM ', 'km', formatKm(day.km)],
				['ecoflow-plan-slot-mode', 'GAS ', 'l', formatL(day.gas_l)],
				['ecoflow-plan-slot-yen', 'SAVE ', 'save', formatYen(day.saved_yen)],
			].forEach(function (row) {
				const line = document.createElement('span');
				line.className = row[0];
				line.appendChild(document.createTextNode(row[1]));
				const b = document.createElement('b');
				b.setAttribute('data-k', row[2]);
				b.textContent = row[3];
				line.appendChild(b);
				cell.appendChild(line);
			});

			grid.appendChild(cell);
		});
	}

	function applyLive(data) {
		if (!data) {
			return;
		}

		applyNow(data.now);
		applyTodayStats(data.today_stats);

		const viewing = root.getAttribute('data-month') || data.month;
		if (viewing !== data.month) {
			return;
		}

		applyTotals(data.totals);
		renderTodayChart(data);
		renderMonthChart(data);

		const today = (data.days || []).find(function (day) {
			return day.is_today;
		});
		if (today) {
			paintDayCell(root.querySelector('[data-tesla-gas-day="' + today.date + '"]'), today);
		}
	}

	function loadMonth(month, full) {
		if (!month || !gamingHubTeslaGas.url) {
			return;
		}
		fetch(gamingHubTeslaGas.url + '?month=' + encodeURIComponent(month), { credentials: 'same-origin' })
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (payload && payload.success && payload.data) {
					if (full) {
						renderCalendar(payload.data);
					} else {
						applyLive(payload.data);
					}
				}
			})
			.catch(function () {
				// Keep painted month.
			});
	}

	const prevBtn = root.querySelector('[data-tesla-gas-prev]');
	const nextBtn = root.querySelector('[data-tesla-gas-next]');
	if (prevBtn) {
		prevBtn.addEventListener('click', function () {
			loadMonth(prevBtn.getAttribute('data-month'), true);
		});
	}
	if (nextBtn) {
		nextBtn.addEventListener('click', function () {
			loadMonth(nextBtn.getAttribute('data-month'), true);
		});
	}

	document.addEventListener('gamingHubTeslaFlow', function (event) {
		const tesla = event && event.detail ? event.detail : null;
		const gas = tesla && tesla.gas ? tesla.gas : null;
		if (!gas) {
			return;
		}
		applyNow({
			asleep: !!tesla.asleep,
			speed_km: tesla.live ? tesla.speed_km : 0,
			saved_yen_per_h: tesla.live ? gas.saved_yen_per_h : 0,
			price_label: gas.price_label,
		});
		if (tesla.live) {
			applyTodayStats({
				km: gas.today_km,
				gas_l: gas.gas_l,
				ev_yen: gas.ev_yen,
				saved_yen: gas.saved_yen,
			});
		}
	});

	setInterval(function () {
		const month = root.getAttribute('data-month');
		if (month) {
			loadMonth(month, false);
		}
	}, 30000);
})();
