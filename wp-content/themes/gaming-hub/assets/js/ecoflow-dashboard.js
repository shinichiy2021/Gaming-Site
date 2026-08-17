(function () {
	'use strict';

	const dashboard = document.querySelector('.ecoflow-dashboard');
	if (!dashboard || !window.gamingHubEcoflow) {
		return;
	}

	function setField(name, value) {
		dashboard.querySelectorAll('[data-ecoflow-field="' + name + '"]').forEach(function (el) {
			if (value !== undefined && value !== null) {
				el.textContent = value;
			}
		});
	}

	function t(text) {
		return window.gamingHubT ? window.gamingHubT(text) : text;
	}

	const unavailableLabel = (gamingHubEcoflow.labels && gamingHubEcoflow.labels.unavailable) || t('未取得');

	function formatWatts(value) {
		if (value === null || value === undefined || value === '') {
			return unavailableLabel;
		}
		const watts = Math.round(Number(value));
		if (!Number.isFinite(watts)) {
			return unavailableLabel;
		}
		if (watts === 0) {
			return t('待機');
		}
		return watts.toLocaleString() + ' W';
	}

	function formatTemp(value) {
		if (value === null || value === undefined) {
			return '—';
		}
		return Number(value).toFixed(1) + ' ℃';
	}

	function formatWh(value) {
		if (value === null || value === undefined) {
			return unavailableLabel;
		}
		if (Number(value) > 1000) {
			return (Number(value) / 1000).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' kWh';
		}
		return Math.round(value).toLocaleString() + ' Wh';
	}

	function formatPack(remain, full) {
		if (remain === null || remain === undefined) {
			return unavailableLabel;
		}
		if (full === null || full === undefined || Number(full) <= 0) {
			return formatWh(remain);
		}
		return formatWh(remain) + ' / ' + formatWh(full);
	}

	function formatPercent(value) {
		if (value === null || value === undefined || value === '') {
			return unavailableLabel;
		}
		const soc = Number(value);
		if (!Number.isFinite(soc)) {
			return unavailableLabel;
		}
		const rounded = Math.round(Math.max(0, Math.min(100, soc)) * 10) / 10;
		if (Math.abs(rounded - Math.round(rounded)) < 0.05) {
			return Math.round(rounded) + '%';
		}
		return rounded.toFixed(1) + '%';
	}

	function formatMinutes(minutes) {
		if (!minutes || minutes <= 0) {
			return '—';
		}

		const hours = Math.floor(minutes / 60);
		const mins = minutes % 60;

		if (hours > 0) {
			return hours + t('時間') + mins + t('分');
		}

		return mins + t('分');
	}

	function deviceFlowSlice(data) {
		const remainTime = Number(data.remain_time) || 0;

		return {
			device_name: data.device_name || '',
			device_sn: data.device_sn || '',
			online: !!data.online,
			solar_in: data.solar_in === null || data.solar_in === undefined
				? null
				: Number(data.solar_in) || 0,
			solar_in_source: data.solar_in_source || '',
			hv_in: Number(data.hv_in) || 0,
			ac_in: Number(data.ac_in) || 0,
			ac_out: Number(data.ac_out) || 0,
			dc_out: Number(data.dc_out) || 0,
			input_total: Number(data.input_total) || 0,
			output_total: Number(data.output_total) || 0,
			battery_percent: data.battery_percent === null || data.battery_percent === undefined
				? null
				: Number(data.battery_percent) || 0,
			is_charging: !!data.is_charging,
			is_discharging: !!data.is_discharging,
			charge_state: data.charge_state || '',
			remain_time: remainTime,
			remain_time_label: data.is_charging ? t('満充電まで') : t('残り使用時間'),
			remain_time_display: formatMinutes(remainTime),
			capacity_wh: data.capacity_wh === null || data.capacity_wh === undefined
				? null
				: Number(data.capacity_wh),
			remain_capacity: data.remain_capacity === null || data.remain_capacity === undefined
				? null
				: Number(data.remain_capacity),
			extra: extraBatterySlice(data.extra),
		};
	}

	function extraBatterySlice(extra) {
		if (!extra || typeof extra !== 'object') {
			return {
				connected: true,
				battery_percent: null,
				capacity_wh: 1000,
				remain_capacity: null,
				capacity_source: 'default',
			};
		}

		const percent = extra.battery_percent === null || extra.battery_percent === undefined
			? null
			: Number(extra.battery_percent);
		const capacity = Number(extra.capacity_wh) || 1000;

		return {
			connected: extra.connected !== false,
			battery_percent: percent,
			capacity_wh: capacity,
			remain_capacity: extra.remain_capacity === null || extra.remain_capacity === undefined
				? (percent === null ? null : Math.round(capacity * percent / 100))
				: Number(extra.remain_capacity),
			capacity_source: extra.capacity_source || 'default',
		};
	}

	function independentDelta() {
		return {
			device_name: 'Delta 3 1500',
			device_sn: '',
			online: false,
			solar_in: 0,
			hv_in: 0,
			ac_in: 0,
			ac_out: 0,
			dc_out: 0,
			input_total: 0,
			output_total: 0,
			battery_percent: null,
			is_charging: false,
			is_discharging: false,
			charge_state: t('独立運転'),
			remain_time: 0,
			remain_time_label: '',
			remain_time_display: '—',
			capacity_wh: 1500,
			remain_capacity: null,
			extra: extraBatterySlice(),
		};
	}

	function buildFlowPayload(data) {
		const pro = deviceFlowSlice(data);
		const delta = data.secondary && typeof data.secondary === 'object'
			? deviceFlowSlice(data.secondary)
			: independentDelta();

		return {
			dual: true,
			independent: true,
			solar_in: delta.solar_in === null || delta.solar_in === undefined
				? null
				: Number(delta.solar_in) || 0,
			hv_in: Number(pro.hv_in) || Number(data.hv_in) || 0,
			grid_in: pro.ac_in,
			ac_in: pro.ac_in,
			pro_grid_charge: data.pro_grid_charge && typeof data.pro_grid_charge === 'object'
				? data.pro_grid_charge
				: (function () {
					const plan = data.charge_plan || {};
					const chargeW = Number(plan.charge_w) || 1000;
					const applied = Number(plan.last_applied_w) || 0;
					const now = new Date();
					const hour = now.getHours();
					const date = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
					const slot = Array.isArray(plan.slots)
						? plan.slots.find(function (row) {
							return row && Number(row.hour) === hour && String(row.date || '') === date;
						})
						: null;
					const active = applied > 0 || (slot && slot.mode === 'charge');
					return {
						active: active,
						watts: active ? chargeW : 0,
						message: plan.approval_note || '',
					};
				}()),
			pro: pro,
			battery_percent: pro.battery_percent,
			is_charging: pro.is_charging,
			charge_state: pro.charge_state,
			input_total: pro.input_total,
			output_total: pro.output_total,
			remain_time: pro.remain_time,
			remain_time_label: pro.remain_time_label,
			remain_time_display: pro.remain_time_display,
			delta: delta,
			link_watts: 0,
			home_out: Number(pro.ac_out) || 0,
			ups_out: (function () {
				if (data.ups_plug && data.ups_plug.source === 'ecoflow' && data.ups_plug.watts !== null && data.ups_plug.watts !== undefined) {
					return Number(data.ups_plug.watts);
				}
				if (data.secondary && data.secondary.ac_out !== null && data.secondary.ac_out !== undefined) {
					return Number(data.secondary.ac_out);
				}
				return null;
			}()),
			ups_source: data.ups_plug && data.ups_plug.source
				? data.ups_plug.source
				: (data.secondary && data.secondary.ac_out !== null && data.secondary.ac_out !== undefined ? 'ecoflow' : 'unavailable'),
			solar_in_source: (data.secondary && data.secondary.solar_in_source) || data.solar_in_source || 'unavailable',
			extra: extraBatterySlice(delta.extra || (data.secondary && data.secondary.extra)),
		};
	}

	function dispatchFlowUpdate(data) {
		document.dispatchEvent(
			new CustomEvent('gamingHubEcoflowStatus', {
				detail: buildFlowPayload(data),
			})
		);
	}

	function formatKwh(value) {
		if (value === null || value === undefined) {
			return '—';
		}
		return Number(value).toLocaleString(undefined, {
			minimumFractionDigits: 1,
			maximumFractionDigits: 1,
		}) + ' kWh';
	}

	function formatYen(value) {
		if (value === null || value === undefined) {
			return '';
		}
		return Number(value).toLocaleString(undefined, {
			minimumFractionDigits: 1,
			maximumFractionDigits: 1,
		}) + t(' 円');
	}

	function formatCalKwh(value) {
		if (value === null || value === undefined) {
			return '—';
		}
		return Number(value).toFixed(2);
	}

	function formatCalYen(value) {
		if (value === null || value === undefined) {
			return '—';
		}
		return Math.round(Number(value)).toLocaleString() + t(' 円');
	}

	function formatCalWatts(value) {
		if (value === null || value === undefined) {
			return '—';
		}
		return Math.round(Number(value)).toLocaleString() + ' W';
	}

	function calRoot() {
		return document.querySelector('[data-ecoflow-cal]');
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

	function formatEnergyTick(value, yen) {
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
				el.textContent = formatEnergyTick(ticks[i], yen);
			}
		});
	}

	function applyEnergyNow(energy) {
		const root = calRoot();
		if (!root || !energy || !energy.now) {
			return;
		}
		const nowIn = root.querySelector('[data-ecoflow-cal-now-in]');
		const nowOut = root.querySelector('[data-ecoflow-cal-now-out]');
		const nowPv = root.querySelector('[data-ecoflow-cal-now-pv]');
		if (nowIn) {
			nowIn.textContent = formatCalWatts(energy.now.input);
		}
		if (nowOut) {
			nowOut.textContent = formatCalWatts(energy.now.output);
		}
		if (nowPv) {
			nowPv.textContent = formatCalWatts(energy.now.solar);
		}
	}

	function applyEnergyTotals(root, energy) {
		if (!energy.totals) {
			return;
		}
		const monthSave = root.querySelector('[data-ecoflow-cal-month-save]');
		const monthIn = root.querySelector('[data-ecoflow-cal-month-in]');
		const monthOut = root.querySelector('[data-ecoflow-cal-month-out]');
		const monthPv = root.querySelector('[data-ecoflow-cal-month-pv]');
		if (monthSave) {
			monthSave.textContent = formatCalYen(energy.totals.saved_yen);
		}
		if (monthOut) {
			monthOut.textContent = formatCalKwh(energy.totals.output_kwh) + ' kWh';
		}
		if (monthIn) {
			monthIn.textContent = t('入力 ') + formatCalKwh(energy.totals.input_kwh) + ' kWh';
		}
		if (monthPv) {
			monthPv.textContent = formatCalKwh(energy.totals.solar_kwh) + ' kWh';
		}
	}

	function renderEnergyTodayChart(root, energy) {
		const wrap = root.querySelector('[data-ecoflow-cal-today]');
		const hours = energy.today_hours;
		if (!wrap) {
			return;
		}
		if (!Array.isArray(hours) || !hours.length) {
			wrap.hidden = true;
			return;
		}
		wrap.hidden = false;

		let maxK = 0;
		hours.forEach(function (row) {
			maxK = Math.max(maxK, Number(row.solar_kwh) || 0, Number(row.output_kwh) || 0);
		});
		const axis = energyTicks(energy.today_kwh_max || maxK);
		setTickTexts(root.querySelectorAll('[data-ecoflow-cal-today-kwh-tick]'), axis.ticks, false);

		const outLine = root.querySelector('[data-ecoflow-cal-today-out-line]');
		if (outLine) {
			outLine.setAttribute('points', polylineFromValues(hours.map(function (row) {
				return row.output_kwh;
			}), axis.max));
		}

		const track = root.querySelector('[data-ecoflow-cal-today-track]');
		if (!track) {
			return;
		}
		const nowHour = new Date().getHours();
		track.querySelectorAll('[data-ecoflow-cal-today-col]').forEach(function (col) {
			col.remove();
		});
		hours.forEach(function (row) {
			const hour = Number(row.hour);
			const isNow = hour === nowHour;
			const hasData = !!row.has_data;
			const solar = row.solar_kwh;
			const height = solar === null || solar === undefined
				? 0
				: Math.max(0, Math.min(100, (Number(solar) / axis.max) * 100));
			const col = document.createElement('div');
			col.className = 'ecoflow-rate-col ecoflow-cal-col'
				+ (isNow ? ' is-now' : '')
				+ (!hasData ? ' is-empty' : '');
			col.setAttribute('data-ecoflow-cal-today-col', '');
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
			if (solar !== null && solar !== undefined) {
				tip.push(formatCalKwh(solar) + ' kWh');
			}
			if (row.output_kwh !== null && row.output_kwh !== undefined) {
				tip.push('OUT ' + formatCalKwh(row.output_kwh));
			}
			bar.setAttribute('title', tip.join(' · '));
			col.appendChild(bar);
			track.appendChild(col);
		});
	}

	function renderEnergyMonthChart(root, energy) {
		const days = energy.days || [];
		if (!days.length) {
			return;
		}

		let maxK = 0;
		let maxY = 0;
		days.forEach(function (day) {
			maxK = Math.max(maxK, Number(day.solar_kwh) || 0, Number(day.output_kwh) || 0);
			maxY = Math.max(maxY, Number(day.saved_yen) || 0);
		});
		const kwhAxis = energyTicks(energy.kwh_max || maxK);
		const yenAxis = energyTicks(energy.yen_max || maxY);
		setTickTexts(root.querySelectorAll('[data-ecoflow-cal-kwh-tick]'), kwhAxis.ticks, false);
		setTickTexts(root.querySelectorAll('[data-ecoflow-cal-yen-tick]'), yenAxis.ticks, true);

		const outLine = root.querySelector('[data-ecoflow-cal-out-line]');
		const yenLine = root.querySelector('[data-ecoflow-cal-yen-line]');
		if (outLine) {
			outLine.setAttribute('points', polylineFromValues(days.map(function (day) {
				return day.output_kwh;
			}), kwhAxis.max));
		}
		if (yenLine) {
			yenLine.setAttribute('points', polylineFromValues(days.map(function (day) {
				return day.saved_yen;
			}), yenAxis.max));
		}

		const track = root.querySelector('[data-ecoflow-cal-track]');
		const hoursRow = root.querySelector('[data-ecoflow-cal-hours]');
		if (!track) {
			return;
		}
		track.querySelectorAll('[data-ecoflow-cal-col]').forEach(function (col) {
			col.remove();
		});
		const yenSvg = track.querySelector('.ecoflow-price-line');
		const today = energy.today || '';
		days.forEach(function (day) {
			const isToday = !!day.is_today;
			const hasData = !!day.has_data;
			const isFuture = today && day.date > today;
			const solar = day.solar_kwh;
			const height = solar === null || solar === undefined
				? 0
				: Math.max(0, Math.min(100, (Number(solar) / kwhAxis.max) * 100));
			const col = document.createElement('div');
			col.className = 'ecoflow-rate-col ecoflow-cal-col'
				+ (isToday ? ' is-now' : '')
				+ (!hasData || isFuture ? ' is-empty' : '');
			col.setAttribute('data-ecoflow-cal-col', '');
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
				tip.push('PV ' + formatCalKwh(day.solar_kwh));
				tip.push('OUT ' + formatCalKwh(day.output_kwh));
				tip.push(formatCalYen(day.saved_yen));
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

	function paintEnergyCell(cellEl, day) {
		if (!cellEl || !day) {
			return;
		}
		cellEl.classList.toggle('is-now', !!day.is_today);
		cellEl.classList.toggle('is-solar', !!day.has_data);
		cellEl.classList.toggle('is-charge', !!day.has_data && Number(day.saved_yen) > 0);
		cellEl.classList.toggle('is-past', !day.is_today && !day.has_data);
		const map = {
			in: formatCalKwh(day.input_kwh),
			out: formatCalKwh(day.output_kwh),
			pv: formatCalKwh(day.solar_kwh),
			save: formatCalYen(day.saved_yen),
		};
		Object.keys(map).forEach(function (key) {
			const el = cellEl.querySelector('[data-k="' + key + '"]');
			if (el) {
				el.textContent = map[key];
			}
		});
	}

	function renderEnergyCalendar(energy) {
		const root = calRoot();
		if (!root || !energy) {
			return;
		}

		root.setAttribute('data-month', energy.month || '');
		const label = root.querySelector('[data-ecoflow-cal-label]');
		if (label && energy.label) {
			label.textContent = energy.label;
		}
		const prevBtn = root.querySelector('[data-ecoflow-cal-prev]');
		const nextBtn = root.querySelector('[data-ecoflow-cal-next]');
		if (prevBtn && energy.prev) {
			prevBtn.setAttribute('data-month', energy.prev);
		}
		if (nextBtn && energy.next) {
			nextBtn.setAttribute('data-month', energy.next);
		}

		applyEnergyNow(energy);
		applyEnergyTotals(root, energy);
		renderEnergyTodayChart(root, energy);
		renderEnergyMonthChart(root, energy);

		const grid = root.querySelector('[data-ecoflow-cal-grid]');
		if (!grid || !Array.isArray(energy.days)) {
			return;
		}

		grid.innerHTML = '';
		const startW = Number(energy.start_wday) || 0;
		const today = energy.today || '';
		for (let i = 0; i < startW; i += 1) {
			const blank = document.createElement('li');
			blank.className = 'ecoflow-plan-slot is-blank';
			grid.appendChild(blank);
		}

		energy.days.forEach(function (day) {
			const isPast = !day.is_today && today && day.date < today;
			const cell = document.createElement('li');
			cell.className = 'ecoflow-plan-slot'
				+ (day.is_today ? ' is-now' : '')
				+ (day.has_data ? ' is-solar' : '')
				+ (day.has_data && Number(day.saved_yen) > 0 ? ' is-charge' : '')
				+ (isPast && !day.has_data ? ' is-past' : '');
			cell.setAttribute('data-ecoflow-cal-day', day.date);

			const hourEl = document.createElement('span');
			hourEl.className = 'ecoflow-plan-slot-hour';
			hourEl.textContent = String(day.day);
			cell.appendChild(hourEl);

			[
				['ecoflow-plan-slot-mode', 'IN ', 'in', formatCalKwh(day.input_kwh)],
				['ecoflow-plan-slot-mode', 'OUT ', 'out', formatCalKwh(day.output_kwh)],
				['ecoflow-plan-slot-watts', 'PV ', 'pv', formatCalKwh(day.solar_kwh)],
				['ecoflow-plan-slot-yen', 'SAVE ', 'save', formatCalYen(day.saved_yen)],
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

	function applyEnergy(energy) {
		const root = calRoot();
		if (!root || !energy) {
			return;
		}

		applyEnergyNow(energy);

		const viewing = root.getAttribute('data-month') || energy.month;
		if (viewing !== energy.month) {
			return;
		}

		applyEnergyTotals(root, energy);
		renderEnergyTodayChart(root, energy);
		renderEnergyMonthChart(root, energy);

		const today = (energy.days || []).find(function (day) {
			return day.is_today;
		});
		if (today) {
			paintEnergyCell(root.querySelector('[data-ecoflow-cal-day="' + today.date + '"]'), today);
		}
	}

	function bindCalendarNav() {
		const root = calRoot();
		if (!root || !gamingHubEcoflow.energyUrl) {
			return;
		}

		function loadMonth(month) {
			if (!month) {
				return;
			}
			fetch(gamingHubEcoflow.energyUrl + '?month=' + encodeURIComponent(month), { credentials: 'same-origin' })
				.then(function (response) {
					return response.json();
				})
				.then(function (payload) {
					if (payload && payload.success && payload.data) {
						renderEnergyCalendar(payload.data);
					}
				})
				.catch(function () {
					// Keep painted month.
				});
		}

		const prevBtn = root.querySelector('[data-ecoflow-cal-prev]');
		const nextBtn = root.querySelector('[data-ecoflow-cal-next]');
		if (prevBtn) {
			prevBtn.addEventListener('click', function () {
				loadMonth(prevBtn.getAttribute('data-month'));
			});
		}
		if (nextBtn) {
			nextBtn.addEventListener('click', function () {
				loadMonth(nextBtn.getAttribute('data-month'));
			});
		}
	}

	function updateSocLine(plan) {
		const bars = dashboard.querySelectorAll('[data-ecoflow-soc-bar]');
		const series = plan && plan.soc_series ? plan.soc_series : [];
		bars.forEach(function (bar, hour) {
			const pct = series[hour];
			const col = bar.closest('.ecoflow-rate-col');
			const kind = plan && plan.soc_chart_kind ? plan.soc_chart_kind[hour] : '';
			if (pct === null || pct === undefined || Number.isNaN(Number(pct))) {
				bar.style.height = '0%';
				bar.setAttribute('title', '—');
				if (col) {
					col.classList.add('is-empty');
					col.classList.remove('is-actual', 'is-forecast');
				}
				return;
			}
			const value = Math.max(0, Math.min(100, Number(pct)));
			bar.style.height = value.toFixed(1) + '%';
			bar.setAttribute('title', Math.round(value) + '%');
			if (col) {
				col.classList.remove('is-empty');
				col.classList.toggle('is-actual', kind === 'actual' || kind === 'live');
				col.classList.toggle('is-forecast', kind === 'forecast');
			}
		});
		const nowEl = dashboard.querySelector('[data-ecoflow-soc-now]');
		if (nowEl && plan && plan.soc_now !== null && plan.soc_now !== undefined) {
			nowEl.textContent = Math.round(Number(plan.soc_now)) + '%';
		}
		const endEl = dashboard.querySelector('[data-ecoflow-soc-end]');
		if (endEl && plan && plan.soc_end !== null && plan.soc_end !== undefined) {
			endEl.textContent = t('24時 ') + Math.round(Number(plan.soc_end)) + '%';
		}
	}

	function updatePriceLine(hourly) {
		const line = dashboard.querySelector('[data-ecoflow-price-line]');
		if (!line || !Array.isArray(hourly) || !hourly.length) {
			return;
		}
		const prices = hourly.map(function (row) {
			return Number(row.total_price) || 0;
		});
		const min = 0;
		const max = Math.max(Math.max.apply(null, prices), min + 1);
		const span = Math.max(1, max - min);
		const points = hourly.map(function (row) {
			const hour = Number(row.hour);
			const y = Math.max(0, Math.min(100, 100 - ((Number(row.total_price) - min) / span) * 100));
			return ((hour + 0.5) * 10).toFixed(1) + ',' + y.toFixed(1);
		});
		line.setAttribute('points', points.join(' '));

		const ticks = dashboard.querySelectorAll('[data-ecoflow-yen-tick]');
		ticks.forEach(function (el, i) {
			el.textContent = (max - (span * i / 4)).toFixed(1);
		});
	}

	function updateSolarLine(plan) {
		const line = dashboard.querySelector('[data-ecoflow-solar-line]');
		const area = dashboard.querySelector('[data-ecoflow-solar-area]');
		const hours = plan && (plan.solar_chart || plan.solar_hours);
		if (!line && !area) {
			return;
		}
		if (!hours || !hours.length) {
			return;
		}
		const cap = Math.max(1, Number(plan && plan.solar_capacity_w) || 1300);
		const points = [];
		for (let hour = 0; hour < 24; hour += 1) {
			const watts = Math.max(0, Number(hours[hour]) || 0);
			const y = Math.max(0, Math.min(100, 100 - (watts / cap) * 100));
			points.push(((hour + 0.5) * 10).toFixed(1) + ',' + y.toFixed(1));
		}
		const joined = points.join(' ');
		if (line) {
			line.setAttribute('points', joined);
		}
		if (area) {
			area.setAttribute('points', joined ? ('0,100 ' + joined + ' 240,100') : '');
		}
		const todayEl = dashboard.querySelector('[data-ecoflow-pv-today]');
		if (todayEl && plan && plan.solar_today_kwh !== null && plan.solar_today_kwh !== undefined) {
			todayEl.textContent = t('今日 ') + Number(plan.solar_today_kwh).toFixed(1) + ' kWh';
		}
	}

	function slotModeLabel(mode) {
		if (mode === 'charge') {
			return t('充電');
		}
		if (mode === 'solar') {
			return t('太陽光');
		}
		if (mode === 'past') {
			return t('経過');
		}
		return t('充電オフ');
	}

	function todayStamp() {
		const now = new Date();
		const y = now.getFullYear();
		const m = String(now.getMonth() + 1).padStart(2, '0');
		const d = String(now.getDate()).padStart(2, '0');
		return y + '-' + m + '-' + d;
	}

	function renderPlanChart(plan) {
		const track = dashboard.querySelector('[data-ecoflow-plan-track]');
		if (!track) {
			return;
		}

		const slots = Array.isArray(plan && plan.slots) ? plan.slots : [];
		const today = todayStamp();
		const hour = new Date().getHours();
		const chargeW = Math.max(1, Number(plan && plan.charge_w) || 1000);
		const byHour = {};
		const nextLabels = [];
		const yenByHour = [];
		let lastYen = 30;

		slots.forEach(function (slot) {
			if (!slot) {
				return;
			}
			if (slot.date === today && slot.hour !== null && slot.hour !== undefined) {
				byHour[Number(slot.hour)] = slot;
			} else if (slot.date && slot.date !== today && slot.mode === 'charge' && slot.label) {
				nextLabels.push(slot.label);
			}
		});

		const socSeries = plan && Array.isArray(plan.soc_series) ? plan.soc_series : [];

		for (let h = 0; h < 24; h += 1) {
			const slot = byHour[h] || {};
			const mode = slot.mode || 'idle';
			const isNow = h === hour;
			const isCharge = mode === 'charge';
			const soc = socSeries[h];
			const hasSoc = soc !== null && soc !== undefined && !Number.isNaN(Number(soc));
			const height = hasSoc ? Math.max(0, Math.min(100, Number(soc))) : 0;
			const watts = isCharge ? Number(slot.watts != null ? slot.watts : chargeW) : 0;
			const chargeH = isCharge ? Math.max(8, Math.min(100, (watts / chargeW) * 100)) : 0;
			const col = track.querySelector('[data-ecoflow-plan-col][data-hour="' + h + '"]');
			if (col) {
				col.className = 'ecoflow-rate-col ecoflow-plan-col is-' + mode
					+ (isNow ? ' is-now' : '')
					+ (hasSoc ? '' : ' is-empty');
				let pip = col.querySelector('.ecoflow-rate-now-pip');
				if (isNow && !pip) {
					pip = document.createElement('span');
					pip.className = 'ecoflow-rate-now-pip';
					pip.textContent = t('NOW');
					col.insertBefore(pip, col.firstChild);
				} else if (!isNow && pip) {
					pip.remove();
				}
				const chargeBar = col.querySelector('[data-ecoflow-plan-charge-bar]');
				if (chargeBar) {
					chargeBar.style.height = chargeH.toFixed(1) + '%';
					chargeBar.hidden = !isCharge;
				}
				const bar = col.querySelector('[data-ecoflow-plan-bar]');
				if (bar) {
					bar.style.height = height.toFixed(1) + '%';
					const tips = [h + ':00', slotModeLabel(mode)];
					if (hasSoc) {
						tips.push(Math.round(height) + '%');
					}
					if (isCharge) {
						tips.push(formatWatts(watts));
					}
					if (slot.yen !== null && slot.yen !== undefined) {
						tips.push(formatYen(slot.yen));
					}
					bar.setAttribute('title', tips.join(' · '));
				}
			}

			if (slot.yen !== null && slot.yen !== undefined) {
				lastYen = Number(slot.yen);
			}
			yenByHour[h] = lastYen;
		}

		dashboard.querySelectorAll('[data-ecoflow-plan-hour]').forEach(function (el) {
			const h = Number(el.getAttribute('data-hour'));
			const isNow = h === hour;
			el.classList.toggle('is-now', isNow);
			el.textContent = (h % 3 === 0 || isNow) ? String(h) : '';
		});

		const nowSlot = byHour[hour] || {};
		const nowMode = nowSlot.mode || 'idle';
		setField('plan_now_mode', slotModeLabel(nowMode));
		setField(
			'plan_now_watts',
			nowSlot.watts === null || nowSlot.watts === undefined
				? '—'
				: formatWatts(nowSlot.watts)
		);
		const nowStat = dashboard.querySelector('.ecoflow-plan-stat-now');
		if (nowStat) {
			nowStat.className = 'ecoflow-rates-stat ecoflow-plan-stat-now is-' + nowMode;
		}

		const nextEl = dashboard.querySelector('[data-ecoflow-plan-next]');
		if (nextEl) {
			if (nextLabels.length) {
				nextEl.hidden = false;
				nextEl.textContent = t('翌 ') + nextLabels.join('、') + t(' も充電');
			} else {
				nextEl.hidden = true;
				nextEl.textContent = '';
			}
		}

		const solarHours = plan && (plan.solar_chart || plan.solar_hours);
		const solarLine = dashboard.querySelector('[data-ecoflow-plan-solar-line]');
		const solarArea = dashboard.querySelector('[data-ecoflow-plan-solar-area]');
		if (solarHours && (solarLine || solarArea)) {
			const cap = Math.max(1, Number(plan && plan.solar_capacity_w) || 1300);
			const solarPoints = [];
			for (let h = 0; h < 24; h += 1) {
				const watts = Math.max(0, Number(solarHours[h]) || 0);
				const y = Math.max(0, Math.min(100, 100 - (watts / cap) * 100));
				solarPoints.push(((h + 0.5) * 10).toFixed(1) + ',' + y.toFixed(1));
			}
			const joined = solarPoints.join(' ');
			if (solarLine) {
				solarLine.setAttribute('points', joined);
			}
			if (solarArea) {
				solarArea.setAttribute('points', joined ? ('0,100 ' + joined + ' 240,100') : '');
			}
		}

		const priceLine = dashboard.querySelector('[data-ecoflow-plan-price-line]');
		if (priceLine && yenByHour.length) {
			const min = 0;
			const max = Math.max(Math.max.apply(null, yenByHour), min + 1);
			const span = Math.max(1, max - min);
			const points = yenByHour.map(function (yen, h) {
				const y = Math.max(0, Math.min(100, 100 - ((Number(yen) - min) / span) * 100));
				return ((h + 0.5) * 10).toFixed(1) + ',' + y.toFixed(1);
			});
			priceLine.setAttribute('points', points.join(' '));
			dashboard.querySelectorAll('[data-ecoflow-plan-yen-tick]').forEach(function (el, i) {
				el.textContent = (max - (span * i / 4)).toFixed(1);
			});
		}
	}

	function applyChargePlan(plan) {
		const panel = dashboard.querySelector('.ecoflow-plan');
		if (panel) {
			panel.classList.toggle('is-deficit', !!plan.needs_grid);
			panel.classList.toggle('is-ok', !plan.needs_grid);
			panel.classList.toggle('is-approved', !!plan.is_approved_current);
			panel.classList.toggle('is-stale', !!plan.needs_reapprove);
			if (plan.plan_id) {
				panel.setAttribute('data-plan-id', plan.plan_id);
			}
		}

		setField('plan_note', plan.note || '');
		setField('plan_approval', plan.approval_note || '');
		setField('plan_deficit', formatKwh(plan.deficit_kwh));
		setField('plan_window', plan.window_label || '—');
		setField(
			'plan_window_price',
			plan.window_avg_yen === null || plan.window_avg_yen === undefined
				? ''
				: t('平均 ') + Number(plan.window_avg_yen).toLocaleString(undefined, {
					minimumFractionDigits: 1,
					maximumFractionDigits: 1,
				}) + t(' 円/kWh')
		);
		if (plan.price_provider) {
			setField('plan_provider', ' · ' + plan.price_provider);
		}
		setField('plan_weather', plan.weather || '—');
		setField('plan_weather_meta', plan.weather_location || '');
		if (plan.temp_now !== null && plan.temp_now !== undefined) {
			setField('plan_temp', Number(plan.temp_now).toFixed(1) + ' ℃');
		}
		if (plan.temp_max !== null && plan.temp_max !== undefined) {
			const tMin = plan.temp_min !== null && plan.temp_min !== undefined ? plan.temp_min : plan.temp_max;
			setField(
				'plan_temp_meta',
				t('最低 ') + Number(tMin).toFixed(1) + t('℃ / 最高 ') + Number(plan.temp_max).toFixed(1) + '℃'
			);
		}
		setField('plan_ac', formatKwh(plan.ac_today_kwh));
		if (plan.ac_weekend) {
			setField(
				'plan_ac_meta',
				t('いま ') + Number(plan.ac_now_w || 0).toLocaleString() + t(' W · 設定 ') + Number(plan.ac_setpoint_c || 26).toFixed(0) + '℃'
			);
		} else if (plan.ac_on) {
			setField(
				'plan_ac_meta',
				t('いま ') + Number(plan.ac_now_w || 0).toLocaleString() + t(' W · 平日は 28℃超でオン')
			);
		} else {
			setField('plan_ac_meta', t('平日は 28℃超でオン'));
		}
		setField('plan_solar_today', formatKwh(plan.solar_today_kwh));
		setField('plan_solar', formatKwh(plan.solar_remaining_kwh));
		setField('plan_load', formatKwh(plan.room_remaining_kwh != null ? plan.room_remaining_kwh : plan.load_remaining_kwh));
		if (plan.room_daily_kwh != null) {
			setField(
				'plan_load_meta',
				t('今日 ') + Number(plan.room_daily_kwh).toFixed(1) + t(' kWh（AC ')
					+ Number(plan.ac_today_kwh || 0).toFixed(1) + t(' + その他 ')
					+ Number(plan.base_today_kwh || 0).toFixed(1) + '）'
			);
		}
		const dcW = Number(plan.dc1500_w) || 100;
		const dcKwh = plan.dc1500_remaining_kwh != null
			? plan.dc1500_remaining_kwh
			: (dcW / 1000) * (24 - new Date().getHours());
		setField('plan_dc1500', formatKwh(dcKwh));
		setField('plan_dc1500_meta', (dcW / 1000).toLocaleString(undefined, {
			minimumFractionDigits: 2,
			maximumFractionDigits: 2,
		}) + t(' kW 固定'));
		setField('plan_battery', formatKwh(plan.usable_battery_kwh));
		updateSocLine(plan);
		updateSolarLine(plan);
		renderPlanChart(plan);
		showSendNotice(plan.send_notice);

		const approveBtn = dashboard.querySelector('[data-ecoflow-approve]');
		const cancelBtn = dashboard.querySelector('[data-ecoflow-cancel]');
		if (approveBtn) {
			approveBtn.hidden = !!plan.is_approved_current && !plan.needs_reapprove;
		}
		if (cancelBtn) {
			cancelBtn.hidden = !(plan.is_approved_current || plan.needs_reapprove);
		}
	}

	const sendNoticeStorageKey = 'gamingHubEcoflowSendNoticeId';

	function rememberSendNoticeId(id) {
		if (!id) {
			return false;
		}
		try {
			if (window.sessionStorage.getItem(sendNoticeStorageKey) === id) {
				return false;
			}
			window.sessionStorage.setItem(sendNoticeStorageKey, id);
			return true;
		} catch (err) {
			return true;
		}
	}

	function hideSendToastLater(toast) {
		window.clearTimeout(showSendNotice._timer);
		showSendNotice._timer = window.setTimeout(function () {
			toast.classList.remove('is-visible');
		}, 8000);
	}

	function showSendNotice(notice) {
		if (!notice || !notice.id || !notice.message) {
			return;
		}

		if (notice.at) {
			const ts = Date.parse(notice.at);
			if (!isNaN(ts) && (Date.now() - ts) > 90000) {
				return;
			}
		}

		if (!rememberSendNoticeId(notice.id)) {
			return;
		}

		const toast = dashboard.querySelector('[data-ecoflow-send-toast]');
		if (!toast) {
			return;
		}

		toast.textContent = notice.message;
		toast.classList.toggle('is-error', notice.ok === false);
		toast.hidden = false;
		toast.classList.add('is-visible');
		hideSendToastLater(toast);
	}

	function bindSendToast() {
		const toast = dashboard.querySelector('[data-ecoflow-send-toast]');
		if (!toast) {
			return;
		}

		const seededId = toast.getAttribute('data-notice-id');
		if (seededId) {
			try {
				window.sessionStorage.setItem(sendNoticeStorageKey, seededId);
			} catch (err) {
				// Private mode.
			}
		}

		if (toast.classList.contains('is-visible')) {
			hideSendToastLater(toast);
		}
	}

	function postPlan(url, body) {
		return fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': gamingHubEcoflow.restNonce || '',
			},
			body: JSON.stringify(body || {}),
		}).then(function (response) {
			return response.json().then(function (payload) {
				if (!response.ok || !payload.success) {
					throw new Error(payload.message || 'Request failed');
				}
				return payload.data;
			});
		});
	}

	function bindPlanActions() {
		if (!gamingHubEcoflow.canApprove) {
			return;
		}

		const approveBtn = dashboard.querySelector('[data-ecoflow-approve]');
		const cancelBtn = dashboard.querySelector('[data-ecoflow-cancel]');
		const panel = dashboard.querySelector('.ecoflow-plan');

		if (approveBtn) {
			approveBtn.addEventListener('click', function () {
				const planId = panel ? panel.getAttribute('data-plan-id') : '';
				if (!planId) {
					return;
				}
				approveBtn.disabled = true;
				postPlan(gamingHubEcoflow.approveUrl, { plan_id: planId })
					.then(function (plan) {
						applyChargePlan(plan);
						refreshDashboard();
					})
					.catch(function (error) {
						setField('plan_approval', error.message || t('承認に失敗しました'));
					})
					.finally(function () {
						approveBtn.disabled = false;
					});
			});
		}

		if (cancelBtn) {
			cancelBtn.addEventListener('click', function () {
				cancelBtn.disabled = true;
				postPlan(gamingHubEcoflow.cancelUrl, {})
					.then(function (plan) {
						applyChargePlan(plan);
						refreshDashboard();
					})
					.catch(function (error) {
						setField('plan_approval', error.message || t('取り消しに失敗しました'));
					})
					.finally(function () {
						cancelBtn.disabled = false;
					});
			});
		}
	}

	function applyDashboardData(data) {
		const proGrid = data.pro_grid_charge && typeof data.pro_grid_charge === 'object'
			? data.pro_grid_charge
			: null;
		if (proGrid) {
			setField(
				'pro_grid_charge',
				proGrid.active ? formatWatts(proGrid.watts) : t('待機')
			);
			setField('pro_grid_charge_note', proGrid.message || '');
		}

		setField('hv_in', formatWatts(data.hv_in));
		setField('ac_out', formatWatts(data.ac_out));
		setField('dc_out', formatWatts(data.dc_out));
		const lvSource = (data.secondary && data.secondary.solar_in_source) || data.solar_in_source || 'unavailable';
		setField(
			'solar_delta_label',
			lvSource === 'unavailable' || lvSource === 'theoretical_lv' || lvSource === ''
				? t('Low Volt 入力 (未取得)')
				: t('Low Volt 入力 (実測)')
		);
		setField(
			'solar_delta',
			lvSource === 'unavailable'
				? unavailableLabel
				: formatWatts(data.secondary && data.secondary.solar_in)
		);
		if (data.secondary) {
			setField('secondary_soc', formatPercent(data.secondary.battery_percent));
			const socSource = data.secondary.soc_source || '';
			setField(
				'secondary_soc_label',
				socSource === 'unavailable'
					? t('残量 (1500 · 未取得)')
					: t('残量 (1500 · 実測)')
			);
		}
		setField('battery_temp', formatTemp(data.battery_temp));
		setField('remain_capacity', formatWh(data.remain_capacity));
		setField('charge_state_stat', data.charge_state);

		const pvNow = dashboard.querySelector('[data-ecoflow-pv-now]');
		if (pvNow) {
			if (data.solar_in === null || data.solar_in === undefined || lvSource === 'unavailable') {
				pvNow.textContent = unavailableLabel;
			} else {
				pvNow.textContent = formatWatts(data.solar_in);
			}
		}

		if (data.secondary) {
			setField('secondary_charge_state', data.secondary.charge_state);
			const upsSource = data.ups_plug && data.ups_plug.source
				? data.ups_plug.source
				: (data.secondary.ac_out !== null && data.secondary.ac_out !== undefined ? 'ecoflow' : 'unavailable');
			const upsWatts = data.ups_plug && data.ups_plug.watts !== null && data.ups_plug.watts !== undefined
				? data.ups_plug.watts
				: data.secondary.ac_out;
			setField(
				'ups_out',
				upsSource === 'ecoflow' && upsWatts !== null && upsWatts !== undefined
					? formatWatts(upsWatts)
					: unavailableLabel
			);
			setField(
				'ups_out_label',
				upsSource === 'ecoflow'
					? t('AC 出力 → UPS (1500 · 実測 · MQTT)')
					: t('AC 出力 → UPS (未取得)')
			);
			const extraPack = data.secondary.extra && typeof data.secondary.extra === 'object'
				? data.secondary.extra
				: {};
			setField('extra_soc', formatPercent(extraPack.battery_percent));
			setField(
				'secondary_remain',
				formatPack(data.secondary.remain_capacity, data.secondary.capacity_wh || 1500)
			);
			const capacitySource = data.secondary.capacity_source || 'default';
			const socSource = data.secondary.soc_source || '';
			setField(
				'secondary_remain_label',
				socSource === 'unavailable'
					? t('残容量 (1500 · 未取得)')
					: (capacitySource !== 'default'
						? t('残容量 (1500 · 実測)')
						: t('残容量 (1500)'))
			);
			setField(
				'extra_remain',
				formatPack(extraPack.remain_capacity, extraPack.capacity_wh || 1000)
			);
			setField(
				'extra_remain_label',
				extraPack.capacity_source === 'mqtt'
					? t('残容量 (Extra · MQTT)')
					: (extraPack.capacity_source && extraPack.capacity_source !== 'default'
						? t('残容量 (Extra · 実測)')
						: t('残容量 (Extra · 未取得)'))
			);
			setField( 'delta_ac_in', formatWatts( data.secondary.ac_in ) );
			if (data.secondary.grid_rescue) {
				setField(
					'delta_rescue',
					data.secondary.grid_rescue.active
						? formatWatts(data.secondary.grid_rescue.watts)
						: t('待機 (5%以下で開始)')
				);
				setField('delta_rescue_note', data.secondary.grid_rescue.message || '');
			}
		}

		if (data.charge_plan) {
			applyChargePlan(data.charge_plan);
		}

		if (data.energy) {
			applyEnergy(data.energy);
		}

		dispatchFlowUpdate(data);

		const updated = dashboard.querySelector('.ecoflow-updated');
		if (updated && data.updated_at) {
			updated.textContent = t('最終更新: ') + data.updated_at;
		}
	}

	function refreshDashboard() {
		fetch(gamingHubEcoflow.refreshUrl, { credentials: 'same-origin' })
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (!payload.success || !payload.data) {
					return;
				}

				applyDashboardData(payload.data);
			})
			.catch(function () {
				// Silent fail; page still shows cached server data.
			});
	}

	function refreshRates() {
		if (!gamingHubEcoflow.ratesUrl) {
			return;
		}

		fetch(gamingHubEcoflow.ratesUrl, { credentials: 'same-origin' })
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (!payload.success || !payload.forecast) {
					return;
				}

				const forecast = payload.forecast;
				const marks = {
					sunny: t('でんき日和'),
					caution: t('でんき注意報'),
					alert: t('でんき警報'),
					normal: t('通常'),
				};
				const panel = dashboard.querySelector('.ecoflow-rates');
				if (!panel) {
					return;
				}

				if (forecast.updated_at) {
					const updated = panel.querySelector('[data-ecoflow-rates-updated]');
					if (updated) {
						updated.textContent = t('更新 ') + forecast.updated_at;
					}
				}

				if (forecast.current) {
					const nowEl = panel.querySelector('[data-ecoflow-rates-now]');
					if (nowEl) {
						nowEl.textContent = Number(forecast.current.total_price).toFixed(1);
					}
					const markEl = panel.querySelector('[data-ecoflow-rates-mark]');
					if (markEl) {
						markEl.textContent = marks[forecast.current.forecast_mark] || marks.normal;
					}
					const nowCard = panel.querySelector('.ecoflow-rates-stat-now');
					if (nowCard) {
						nowCard.className = 'ecoflow-rates-stat ecoflow-rates-stat-now ecoflow-rate-mark-' + (forecast.current.forecast_mark || 'normal');
					}
				}

				if (forecast.cheapest_hour) {
					const lowEl = panel.querySelector('[data-ecoflow-rates-low]');
					if (lowEl) {
						lowEl.textContent = Number(forecast.cheapest_hour.total_price).toFixed(1);
					}
					const lowLabel = panel.querySelector('[data-ecoflow-rates-low-label]');
					if (lowLabel) {
						lowLabel.textContent = forecast.cheapest_hour.label || '';
					}
				}

				const hourly = forecast.days && forecast.days.today ? forecast.days.today.hourly : forecast.hourly_today;
				updatePriceLine(hourly);
			})
			.catch(function () {
				// Keep last painted bars.
			});
	}

	bindSendToast();
	refreshDashboard();
	bindPlanActions();
	bindCalendarNav();
	refreshRates();
	setInterval(refreshDashboard, gamingHubEcoflow.interval || 60000);
	setInterval(refreshRates, 60 * 60 * 1000);

	if (window.gamingHubActiveRefresh) {
		window.gamingHubActiveRefresh.register(refreshDashboard);
		window.gamingHubActiveRefresh.register(refreshRates);
	}
})();
