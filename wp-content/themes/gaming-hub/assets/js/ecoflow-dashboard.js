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

	function formatWatts(value) {
		if (value === null || value === undefined) {
			return '—';
		}
		return Math.round(value).toLocaleString() + ' W';
	}

	function formatTemp(value) {
		if (value === null || value === undefined) {
			return '—';
		}
		return Number(value).toFixed(1) + ' ℃';
	}

	function formatWh(value) {
		if (value === null || value === undefined) {
			return '—';
		}
		if (Number(value) > 1000) {
			return (Number(value) / 1000).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' kWh';
		}
		return Math.round(value).toLocaleString() + ' Wh';
	}

	function formatPack(remain, full) {
		if (full === null || full === undefined || Number(full) <= 0) {
			return formatWh(remain);
		}
		return formatWh(remain) + ' / ' + formatWh(full);
	}

	function formatMinutes(minutes) {
		if (!minutes || minutes <= 0) {
			return '—';
		}

		const hours = Math.floor(minutes / 60);
		const mins = minutes % 60;

		if (hours > 0) {
			return hours + '時間' + mins + '分';
		}

		return mins + '分';
	}

	function deviceFlowSlice(data) {
		const remainTime = Number(data.remain_time) || 0;

		return {
			device_name: data.device_name || '',
			device_sn: data.device_sn || '',
			online: !!data.online,
			solar_in: Number(data.solar_in) || 0,
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
			remain_time_label: data.is_charging ? '満充電まで' : '残り使用時間',
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
			};
		}

		return {
			connected: extra.connected !== false,
			battery_percent: extra.battery_percent === null || extra.battery_percent === undefined
				? null
				: Number(extra.battery_percent),
			capacity_wh: Number(extra.capacity_wh) || 1000,
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
			charge_state: '独立運転',
			remain_time: 0,
			remain_time_label: '',
			remain_time_display: '—',
			capacity_wh: 2500,
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
			solar_in: Number(delta.solar_in) || 0,
			hv_in: Number(pro.hv_in) || Number(data.hv_in) || 0,
			grid_in: pro.ac_in,
			ac_in: pro.ac_in,
			pro_grid_charge: data.pro_grid_charge && typeof data.pro_grid_charge === 'object'
				? data.pro_grid_charge
				: (data.charge_plan ? {
					active: Number(data.charge_plan.last_applied_w) >= 1000,
					watts: Number(data.charge_plan.last_applied_w) || 0,
					message: data.charge_plan.approval_note || '',
				} : {}),
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
			ups_out: data.ups_plug && data.ups_plug.watts !== null && data.ups_plug.watts !== undefined
				? Number(data.ups_plug.watts)
				: Number(delta.ac_out) || 0,
			ups_source: data.ups_plug && data.ups_plug.watts !== null && data.ups_plug.watts !== undefined
				? 'switchbot'
				: 'ecoflow',
			solar_in_source: data.solar_in_source || (data.secondary && data.secondary.solar_in_source) || 'theoretical_lv',
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
		}) + ' 円';
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
		return Math.round(Number(value)).toLocaleString() + ' 円';
	}

	function formatCalWatts(value) {
		if (value === null || value === undefined) {
			return '—';
		}
		return Math.round(Number(value)).toLocaleString() + ' W';
	}

	function applyEnergyNow(energy) {
		const root = dashboard.querySelector('[data-ecoflow-cal]');
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
		const root = dashboard.querySelector('[data-ecoflow-cal]');
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

		const monthSave = root.querySelector('[data-ecoflow-cal-month-save]');
		const monthIn = root.querySelector('[data-ecoflow-cal-month-in]');
		const monthOut = root.querySelector('[data-ecoflow-cal-month-out]');
		const monthPv = root.querySelector('[data-ecoflow-cal-month-pv]');
		if (energy.totals) {
			if (monthSave) {
				monthSave.textContent = formatCalYen(energy.totals.saved_yen);
			}
			if (monthIn) {
				monthIn.textContent = formatCalKwh(energy.totals.input_kwh) + ' kWh';
			}
			if (monthOut) {
				monthOut.textContent = formatCalKwh(energy.totals.output_kwh) + ' kWh';
			}
			if (monthPv) {
				monthPv.textContent = formatCalKwh(energy.totals.solar_kwh) + ' kWh';
			}
		}

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
		const root = dashboard.querySelector('[data-ecoflow-cal]');
		if (!root || !energy) {
			return;
		}

		applyEnergyNow(energy);

		const viewing = root.getAttribute('data-month') || energy.month;
		if (viewing !== energy.month) {
			return;
		}

		if (energy.totals) {
			const monthSave = root.querySelector('[data-ecoflow-cal-month-save]');
			const monthIn = root.querySelector('[data-ecoflow-cal-month-in]');
			const monthOut = root.querySelector('[data-ecoflow-cal-month-out]');
			const monthPv = root.querySelector('[data-ecoflow-cal-month-pv]');
			if (monthSave) {
				monthSave.textContent = formatCalYen(energy.totals.saved_yen);
			}
			if (monthIn) {
				monthIn.textContent = formatCalKwh(energy.totals.input_kwh) + ' kWh';
			}
			if (monthOut) {
				monthOut.textContent = formatCalKwh(energy.totals.output_kwh) + ' kWh';
			}
			if (monthPv) {
				monthPv.textContent = formatCalKwh(energy.totals.solar_kwh) + ' kWh';
			}
		}

		const today = (energy.days || []).find(function (day) {
			return day.is_today;
		});
		if (today) {
			paintEnergyCell(root.querySelector('[data-ecoflow-cal-day="' + today.date + '"]'), today);
		}
	}

	function bindCalendarNav() {
		const root = dashboard.querySelector('[data-ecoflow-cal]');
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
			endEl.textContent = '24時 ' + Math.round(Number(plan.soc_end)) + '%';
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
		const cap = Math.max(1, Number(plan && plan.solar_capacity_w) || 1500);
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
			todayEl.textContent = '今日 ' + Number(plan.solar_today_kwh).toFixed(1) + ' kWh';
		}
	}

	function slotModeLabel(mode) {
		if (mode === 'charge') {
			return '充電';
		}
		if (mode === 'solar') {
			return '太陽光';
		}
		if (mode === 'past') {
			return '経過';
		}
		return '充電オフ';
	}

	function todayStamp() {
		const now = new Date();
		const y = now.getFullYear();
		const m = String(now.getMonth() + 1).padStart(2, '0');
		const d = String(now.getDate()).padStart(2, '0');
		return y + '-' + m + '-' + d;
	}

	function renderSlots(slots) {
		const list = dashboard.querySelector('[data-ecoflow-slots]');
		if (!list || !Array.isArray(slots)) {
			return;
		}

		const today = todayStamp();
		const hour = new Date().getHours();
		list.innerHTML = '';

		slots.forEach(function (slot) {
			const li = document.createElement('li');
			const mode = slot.mode || 'idle';
			const isNow = slot.date === today && Number(slot.hour) === hour;
			const isNext = slot.date && slot.date !== today;
			li.className = 'ecoflow-plan-slot is-' + mode
				+ (isNow ? ' is-now' : '')
				+ (isNext ? ' is-tomorrow' : '');

			const hourEl = document.createElement('span');
			hourEl.className = 'ecoflow-plan-slot-hour';
			hourEl.textContent = (isNext ? '翌 ' : '') + (slot.label || '');
			li.appendChild(hourEl);

			const modeEl = document.createElement('span');
			modeEl.className = 'ecoflow-plan-slot-mode';
			modeEl.textContent = slotModeLabel(mode);
			li.appendChild(modeEl);

			const wattsEl = document.createElement('span');
			wattsEl.className = 'ecoflow-plan-slot-watts';
			wattsEl.textContent = slot.watts === null || slot.watts === undefined
				? '—'
				: Number(slot.watts).toLocaleString() + ' W';
			li.appendChild(wattsEl);

			if (slot.yen !== null && slot.yen !== undefined) {
				const yenEl = document.createElement('span');
				yenEl.className = 'ecoflow-plan-slot-yen';
				yenEl.textContent = formatYen(slot.yen);
				li.appendChild(yenEl);
			}

			list.appendChild(li);
		});
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
				: '平均 ' + Number(plan.window_avg_yen).toLocaleString(undefined, {
					minimumFractionDigits: 1,
					maximumFractionDigits: 1,
				}) + ' 円/kWh'
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
				'最低 ' + Number(tMin).toFixed(1) + '℃ / 最高 ' + Number(plan.temp_max).toFixed(1) + '℃'
			);
		}
		setField('plan_ac', formatKwh(plan.ac_today_kwh));
		setField(
			'plan_ac_meta',
			'いま ' + Number(plan.ac_now_w || 0).toLocaleString() + ' W · 設定 ' + Number(plan.ac_setpoint_c || 26).toFixed(0) + '℃'
		);
		setField('plan_solar_today', formatKwh(plan.solar_today_kwh));
		setField('plan_solar', formatKwh(plan.solar_remaining_kwh));
		setField('plan_load', formatKwh(plan.room_remaining_kwh != null ? plan.room_remaining_kwh : plan.load_remaining_kwh));
		if (plan.room_daily_kwh != null) {
			setField(
				'plan_load_meta',
				'今日 ' + Number(plan.room_daily_kwh).toFixed(1) + ' kWh（AC '
					+ Number(plan.ac_today_kwh || 0).toFixed(1) + ' + その他 '
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
		}) + ' kW 固定');
		setField('plan_battery', formatKwh(plan.usable_battery_kwh));
		updateSocLine(plan);
		updateSolarLine(plan);
		renderSlots(plan.slots || []);
		showSendNotice(plan.send_notice);
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
						setField('plan_approval', error.message || '承認に失敗しました');
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
						setField('plan_approval', error.message || '取り消しに失敗しました');
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
				proGrid.active ? formatWatts(proGrid.watts) : '待機'
			);
			setField('pro_grid_charge_note', proGrid.message || '');
		}

		setField('hv_in', formatWatts(data.hv_in));
		setField('ac_out', formatWatts(data.ac_out));
		setField('dc_out', formatWatts(data.dc_out));
		const lvSource = (data.secondary && data.secondary.solar_in_source) || data.solar_in_source || 'theoretical_lv';
		setField(
			'solar_delta_label',
			lvSource === 'theoretical_lv' || lvSource === ''
				? 'Low Volt 入力 (理論 HV×50%)'
				: 'Low Volt 入力 (実測)'
		);
		setField('solar_delta', formatWatts(data.secondary && data.secondary.solar_in));
		if (data.secondary && data.secondary.battery_percent !== null && data.secondary.battery_percent !== undefined) {
			setField('secondary_soc', Number(data.secondary.battery_percent) + '%');
			setField(
				'secondary_soc_label',
				data.secondary.soc_source && !String(data.secondary.soc_source).startsWith('baseline_minus_ups')
					? '残量 (1500 · 実測)'
					: '残量 (1500 · 6%起点)'
			);
		}
		setField('battery_temp', formatTemp(data.battery_temp));
		setField('remain_capacity', formatWh(data.remain_capacity));
		setField('charge_state_stat', data.charge_state);

		const pvNow = dashboard.querySelector('[data-ecoflow-pv-now]');
		if (pvNow && data.solar_in !== null && data.solar_in !== undefined) {
			pvNow.textContent = Math.round(Number(data.solar_in)).toLocaleString() + ' W';
		}

		if (data.secondary) {
			setField('secondary_charge_state', data.secondary.charge_state);
			const plugWatts = data.ups_plug && data.ups_plug.watts !== null && data.ups_plug.watts !== undefined
				? data.ups_plug.watts
				: data.secondary.ac_out;
			setField('ups_out', formatWatts(plugWatts));
			setField(
				'ups_out_label',
				data.ups_plug && data.ups_plug.watts !== null && data.ups_plug.watts !== undefined
					? 'AC 出力 → UPS (SwitchBot)'
					: 'AC 出力 → UPS (1500)'
			);
			const extraSoc = data.secondary.extra && data.secondary.extra.battery_percent !== null && data.secondary.extra.battery_percent !== undefined
				? data.secondary.extra.battery_percent
				: data.secondary.battery_percent;
			if (extraSoc !== null && extraSoc !== undefined) {
				setField('extra_soc', Number(extraSoc) + '%');
			}
			setField(
				'secondary_remain',
				formatPack(data.secondary.remain_capacity, data.secondary.capacity_wh || 2500)
			);
			const capacitySource = data.secondary.capacity_source || 'default';
			setField(
				'secondary_remain_label',
				capacitySource !== 'default'
					? '残容量 (1500 + Extra · 実測)'
					: '残容量 (1500 + Extra)'
			);
			if (data.secondary.grid_rescue) {
				setField(
					'delta_rescue',
					data.secondary.grid_rescue.active
						? formatWatts(data.secondary.grid_rescue.watts)
						: '待機 (5%以下で開始)'
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
			updated.textContent = '最終更新: ' + data.updated_at;
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
					sunny: 'でんき日和',
					caution: 'でんき注意報',
					alert: 'でんき警報',
					normal: '通常',
				};
				const panel = dashboard.querySelector('.ecoflow-rates');
				if (!panel) {
					return;
				}

				if (forecast.updated_at) {
					const updated = panel.querySelector('[data-ecoflow-rates-updated]');
					if (updated) {
						updated.textContent = '更新 ' + forecast.updated_at;
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
