(function () {
	'use strict';

	const root = document.querySelector('[data-tesla-plan]');
	if (!root) {
		return;
	}

	function t(text) {
		return window.gamingHubT ? window.gamingHubT(text) : text;
	}

	const views = {
		today: null,
		yesterday: null,
		tomorrow: null,
	};
	let selectedDay = 'today';
	let liveCharge = { charging: false, watts: 0 };

	function rememberLiveCharge(status) {
		if (!status) {
			return;
		}
		liveCharge = {
			charging: !!status.live && !!status.is_charging && !status.asleep,
			watts: Math.max(0, Number(status.wall_w || 0), Number(status.super_w || 0)),
		};
	}

	function modeLabel(mode) {
		if (mode === 'charge') {
			return t('充電');
		}
		if (mode === 'drive') {
			return t('走行');
		}
		if (mode === 'past') {
			return t('経過');
		}
		if (mode === 'sleep') {
			return t('スリープ');
		}
		return t('待機');
	}

	function formatYenPerKwh(value) {
		if (value === null || value === undefined) {
			return '';
		}
		return t('平均 %s 円/kWh').replace('%s', Number(value).toLocaleString(undefined, {
			minimumFractionDigits: 1,
			maximumFractionDigits: 1,
		}));
	}

	function wattsLinePoints(hours, cap) {
		const safeCap = Math.max(1, Number(cap) || 2000);
		const pts = [];
		for (let hour = 0; hour < 24; hour += 1) {
			const watts = Math.max(0, Number(hours && hours[hour]) || 0);
			const y = Math.max(0, Math.min(100, 100 - (watts / safeCap) * 100));
			pts.push(((hour + 0.5) * 10).toFixed(1) + ',' + y.toFixed(1));
		}
		return pts.join(' ');
	}

	function priceLinePoints(yenByHour) {
		const prices = [];
		let last = 30;
		for (let h = 0; h < 24; h += 1) {
			if (yenByHour[h] != null) {
				last = Number(yenByHour[h]);
			}
			prices[h] = last;
		}
		const max = Math.max.apply(null, prices.concat([1]));
		const span = Math.max(1, max);
		const pts = prices.map(function (price, hour) {
			const y = Math.max(0, Math.min(100, 100 - (price / span) * 100));
			return ((hour + 0.5) * 10).toFixed(1) + ',' + y.toFixed(1);
		});
		return { points: pts.join(' '), max: max, span: span };
	}

	function setText(sel, value) {
		const el = root.querySelector(sel);
		if (el && value !== undefined && value !== null) {
			el.textContent = value;
		}
	}

	function slotChargeHistory(slot, isPast) {
		if (!isPast || !slot || !slot.charge_input) {
			return '';
		}
		const logged = String(slot.charge_input);
		if (logged === 'home_ac' || logged === 'away_ac' || logged === 'dc') {
			return logged;
		}
		return '';
	}

	function showChargeBar(slot, planCharge, isPast, isLiveNowCharge) {
		if (slotChargeHistory(slot, isPast)) {
			return true;
		}
		if (isLiveNowCharge) {
			return true;
		}
		return planCharge && !isPast;
	}

	function chargeBarTone(slot, opts) {
		if (!opts.isCharge) {
			return 'plan';
		}
		const planned = !!(slot && slot.planned_charge);
		if (opts.isLiveNow && opts.liveInput && opts.liveInput !== 'none') {
			if (planned && opts.liveInput === 'home_ac') {
				return 'plan';
			}
			return opts.liveInput;
		}
		const history = slotChargeHistory(slot, opts.isPast);
		if (history) {
			if (planned && history === 'home_ac') {
				return 'plan';
			}
			return history;
		}
		return 'plan';
	}

	function chargeInputLabel(tone) {
		if (tone === 'away_ac') {
			return t('外出先 AC');
		}
		if (tone === 'dc') {
			return t('DC 入力');
		}
		if (tone === 'home_ac') {
			return t('自宅充電（実績）');
		}
		return '';
	}

	function chargeBarClass(tone, isCharge, asleepNow) {
		let cls = 'ecoflow-plan-charge-bar';
		if (!isCharge) {
			return cls;
		}
		if (asleepNow) {
			return cls + ' is-deferred';
		}
		if (tone && tone !== 'plan') {
			cls += ' is-input-' + tone;
		}
		return cls;
	}

	function inputSubLabel(input) {
		if (!input) {
			return '—';
		}
		if (input.charging && input.watts > 0) {
			return Math.round(input.watts).toLocaleString() + ' W';
		}
		if (input.plugged) {
			return t('接続中');
		}
		return '—';
	}

	function inputFromFlow(status) {
		if (!status) {
			return null;
		}
		if (status.input_type && status.input_label) {
			return {
				type: status.input_type,
				label: status.input_label,
				watts: Number(status.input_watts || 0),
				plugged: !!status.input_plugged,
				charging: !!status.input_charging,
			};
		}
		const kind = status.supply_kind || '';
		const charging = !!status.is_charging;
		const watts = Math.max(Number(status.wall_w || 0), Number(status.super_w || 0));
		if (kind === 'supercharger') {
			return { type: 'dc', label: t('DC 入力'), watts: charging ? watts : 0, plugged: true, charging: charging };
		}
		if (kind === 'home') {
			const away = status.at_home === false || status.supply_label === t('外出先 AC');
			const home = status.at_home === true || status.supply_label === t('自宅 AC');
			return {
				type: away ? 'away_ac' : 'home_ac',
				label: away ? t('外出先 AC') : (home ? t('自宅 AC') : (status.supply_label || t('拠点補給'))),
				watts: charging ? watts : 0,
				plugged: true,
				charging: charging,
			};
		}
		return { type: 'none', label: t('未接続'), watts: 0, plugged: false, charging: false };
	}

	function paintInputStat(input) {
		const wrap = root.querySelector('.ecoflow-plan-stat-input');
		if (!wrap || !input) {
			return;
		}
		wrap.className = 'ecoflow-rates-stat ecoflow-plan-stat-input is-' + (input.type || 'none');
		setText('[data-tesla-plan-input]', input.label || t('未接続'));
		setText('[data-tesla-plan-input-sub]', inputSubLabel(input));
	}

	function paintPlan(plan) {
		if (!plan) {
			return;
		}

		root.classList.toggle('is-deficit', !!plan.needs_grid);
		root.classList.toggle('is-ok', !plan.needs_grid);
		root.classList.toggle('is-asleep', !!(plan.asleep && (plan.plan_day || 'today') === 'today'));
		root.setAttribute('data-plan-id', plan.plan_id || '');
		root.setAttribute('data-plan-date', plan.plan_date || '');

		setText('[data-tesla-plan-title]', plan.title || '');
		setText('[data-tesla-plan-note]', plan.note || '');
		const inputPlan = (views.today && (views.today.input_type || views.today.input_label)) ? views.today : plan;
		paintInputStat({
			type: inputPlan.input_type || 'none',
			label: inputPlan.input_label || t('未接続'),
			watts: Number(inputPlan.input_watts || 0),
			plugged: !!inputPlan.input_plugged,
			charging: !!inputPlan.input_charging,
		});
		const sleepEl = root.querySelector('[data-tesla-plan-sleep]');
		if (sleepEl) {
			const asleepNow = !!(plan.asleep && (plan.plan_day || 'today') === 'today');
			sleepEl.hidden = !asleepNow;
			if (asleepNow) {
				sleepEl.textContent = plan.asleep_note || t('スリープ中です。残量は入眠時の値を固定表示し、API では更新しません。起きたら自動で再開します。');
			}
		}
		const autoEl = root.querySelector('[data-tesla-plan-auto]');
		if (autoEl) {
			const autoPlan = views.today || plan;
			autoEl.textContent = autoPlan.auto_note || t('AI PLAN に合わせて自宅充電のオン／オフとチャージキャップを自動で送ります。Tesla アプリの予約充電はオフにしてください。');
			autoEl.classList.toggle('is-error', !!autoPlan.auto_error);
		}
		setText('[data-tesla-plan-window]', plan.window_label || '—');
		setText('[data-tesla-plan-window-card]', plan.window_label || '—');
		setText('[data-tesla-plan-window-price]', formatYenPerKwh(plan.window_avg_yen));
		setText('[data-tesla-plan-window-price-card]', formatYenPerKwh(plan.window_avg_yen));
		setText(
			'[data-tesla-plan-deficit]',
			plan.deficit_kwh == null ? '—' : Number(plan.deficit_kwh).toLocaleString(undefined, {
				minimumFractionDigits: 1,
				maximumFractionDigits: 1,
			}) + ' kWh'
		);
		setText('[data-tesla-plan-deficit-label]', plan.deficit_hud_label || '');
		setText(
			'[data-tesla-plan-km]',
			plan.km_hud == null ? '—' : Number(plan.km_hud).toLocaleString(undefined, {
				minimumFractionDigits: 1,
				maximumFractionDigits: 1,
			}) + ' km'
		);
		setText('[data-tesla-plan-km-label]', plan.km_hud_label || '');
		setText(
			'[data-tesla-plan-soc-now]',
			plan.soc_now == null ? '—' : Math.round(Number(plan.soc_now)) + '%'
		);
		const asleepToday = !!(plan.asleep && (plan.plan_day || 'today') === 'today');
		setText(
			'[data-tesla-plan-soc-end]',
			asleepToday
				? t('スリープ中・固定')
				: (plan.soc_end == null ? '' : t('計画後 %s%%').replace('%s', String(Math.round(Number(plan.soc_end)))))
		);
		setText(
			'[data-tesla-plan-target]',
			plan.target_soc == null ? '—' : String(Math.round(Number(plan.target_soc))) + '%'
		);
		setText('[data-tesla-plan-target-note]', plan.target_note || '');
		setText(
			'[data-tesla-plan-drive]',
			plan.km == null ? '—' : Number(plan.km).toLocaleString(undefined, {
				minimumFractionDigits: 1,
				maximumFractionDigits: 1,
			}) + ' km'
		);
		if (plan.saved_yen) {
			setText(
				'[data-tesla-plan-save]',
				t('普通車換算 %1$s L · 節約 %2$s 円')
					.replace('%1$s', Number(plan.gas_l || 0).toLocaleString(undefined, {
						minimumFractionDigits: 2,
						maximumFractionDigits: 2,
					}))
					.replace('%2$s', Math.round(Number(plan.saved_yen)).toLocaleString())
			);
		}

		const slots = Array.isArray(plan.slots) ? plan.slots : [];
		const viewDate = plan.plan_date || '';
		const isLiveDay = (plan.plan_day || 'today') === 'today';
		const hour = new Date().getHours();
		const chargeW = Math.max(1, Number(plan.charge_w) || 6000);
		const byHour = {};
		const nextHours = [];
		const yenByHour = [];

		slots.forEach(function (slot) {
			if (!slot) {
				return;
			}
			if (slot.date === viewDate && slot.hour != null) {
				byHour[Number(slot.hour)] = slot;
			} else if (slot.date && slot.date !== viewDate && slot.mode === 'charge' && slot.hour != null) {
				nextHours.push(Number(slot.hour));
			}
		});
		nextHours.sort(function (a, b) { return a - b; });
		const nextLabels = [];
		if (nextHours.length) {
			let start = nextHours[0];
			let prev = nextHours[0];
			for (let i = 1; i < nextHours.length; i += 1) {
				if (nextHours[i] === prev + 1) {
					prev = nextHours[i];
					continue;
				}
				nextLabels.push(start + ':00–' + ((prev + 1) % 24 === 0 && prev === 23 ? '24' : String(prev + 1)) + ':00');
				start = nextHours[i];
				prev = nextHours[i];
			}
			nextLabels.push(start + ':00–' + ((prev + 1) % 24 === 0 && prev === 23 ? '24' : String(prev + 1)) + ':00');
		}

		const nowSlot = byHour[hour] || {};
		const asleepNow = isLiveDay && !!(plan.asleep || (views.today && views.today.asleep));
		const liveCharging = isLiveDay && !asleepNow && !!(liveCharge.charging || plan.live_charging);
		const liveWatts = Number(liveCharge.watts || plan.live_charge_w || 0);
		const nowMode = asleepNow ? 'sleep' : (liveCharging ? 'charge' : (isLiveDay ? (nowSlot.mode || 'idle') : 'idle'));
		const nowEl = root.querySelector('[data-tesla-plan-now-mode]');
		const nowWrap = root.querySelector('.ecoflow-plan-stat-now');
		if (nowEl) {
			nowEl.textContent = modeLabel(nowMode);
		}
		if (nowWrap) {
			nowWrap.className = 'ecoflow-rates-stat ecoflow-plan-stat-now is-' + nowMode;
		}
		if (asleepNow) {
			setText(
				'[data-tesla-plan-now-watts]',
				plan.soc_now == null
					? t('入眠時の残量を表示')
					: t('固定 %s%%').replace('%s', String(Math.round(Number(plan.soc_now))))
			);
		} else if (liveCharging) {
			setText('[data-tesla-plan-now-watts]', Math.round(Math.max(0, liveWatts)).toLocaleString() + ' W');
		} else if (nowMode === 'drive' && nowSlot.drive_km != null) {
			setText('[data-tesla-plan-now-watts]', Number(nowSlot.drive_km).toFixed(1) + ' km');
		} else if (nowSlot.watts != null) {
			setText('[data-tesla-plan-now-watts]', Math.round(Number(nowSlot.watts)).toLocaleString() + ' W');
		} else {
			setText('[data-tesla-plan-now-watts]', '—');
		}

		const nextEl = root.querySelector('[data-tesla-plan-next]');
		if (nextEl) {
			if (nextLabels.length) {
				nextEl.hidden = false;
				nextEl.textContent = t('翌 %s も充電').replace('%s', nextLabels.join('、'));
			} else {
				nextEl.hidden = true;
				nextEl.textContent = '';
			}
		}

		const track = root.querySelector('[data-tesla-plan-track]');
		const socSeries = Array.isArray(plan.soc_series) ? plan.soc_series.slice() : [];
		const sleepFrom = asleepNow && plan.sleep_from_hour != null && !Number.isNaN(Number(plan.sleep_from_hour))
			? Math.max(0, Math.min(23, Number(plan.sleep_from_hour)))
			: (asleepNow ? hour : null);
		const heldSoc = asleepNow && plan.sleep_held_soc != null && !Number.isNaN(Number(plan.sleep_held_soc))
			? Number(plan.sleep_held_soc)
			: (asleepNow && plan.soc_now != null ? Number(plan.soc_now) : null);
		if (asleepNow && heldSoc != null && sleepFrom != null) {
			for (let h = sleepFrom; h <= hour; h += 1) {
				socSeries[h] = heldSoc;
			}
		}
		const chart = root.querySelector('.ecoflow-plan-chart');
		if (chart) {
			chart.classList.toggle('is-asleep-chart', asleepNow);
		}
		const legend = root.querySelector('[data-tesla-plan-legend]');
		if (legend) {
			legend.textContent = asleepNow
				? t('灰棒: スリープ中の固定残量 · 薄い金帯: 計画充電（未実行）· 朱橙線: 走行見込み · 青緑線: 請求単価')
				: t('黄棒: 残量 · 黄帯: 自宅充電（実績）· 金帯: 充電予定 · 色帯: 外出先/DC 実績 · 朱橙線: 走行 · 青緑線: 単価');
		}
		if (track) {
			for (let h = 0; h < 24; h += 1) {
				const slot = byHour[h] || {};
				const isNow = isLiveDay && h === hour;
				const isHold = asleepNow && sleepFrom != null && h >= sleepFrom && h <= hour;
				const liveHere = isNow && !asleepNow && (liveCharge.charging || !!plan.live_charging);
				const slotMode = slot.mode || 'idle';
				const planCharge = slotMode === 'charge';
				const mode = (isNow && asleepNow) ? 'sleep' : (liveHere ? 'charge' : slotMode);
				const isPastHour = (plan.plan_day === 'yesterday') || (isLiveDay && h < hour);
				const isLiveNowCharge = isNow && !asleepNow && (liveCharge.charging || !!plan.live_charging);
				const isCharge = showChargeBar(slot, planCharge, isPastHour, isLiveNowCharge);
				const soc = socSeries[h];
				const hasSoc = soc !== null && soc !== undefined && !Number.isNaN(Number(soc));
				const height = hasSoc ? Math.max(0, Math.min(100, Number(soc))) : 0;
				const watts = liveHere
					? Math.max(Number(liveCharge.watts || plan.live_charge_w || 0), Number(slot.watts != null ? slot.watts : chargeW) || 0)
					: (isCharge ? Number(slot.watts != null ? slot.watts : chargeW) : 0);
				const chargeH = isCharge ? Math.max(8, Math.min(100, (watts / chargeW) * 100)) : 0;
				const col = track.querySelector('[data-tesla-plan-col][data-hour="' + h + '"]');
				if (!col) {
					continue;
				}
				const displayMode = (isCharge && slotMode === 'idle') ? 'charge' : mode;
				col.className = 'ecoflow-rate-col ecoflow-plan-col is-' + displayMode
					+ (isNow ? ' is-now' : '')
					+ (isHold ? ' is-sleep-hold' : '')
					+ (hasSoc ? '' : ' is-empty');
				let pip = col.querySelector('.ecoflow-rate-now-pip');
				if (isNow && !pip) {
					pip = document.createElement('span');
					pip.className = 'ecoflow-rate-now-pip';
					col.insertBefore(pip, col.firstChild);
				} else if (!isNow && pip) {
					pip.remove();
					pip = null;
				}
				if (pip) {
					pip.classList.toggle('is-sleep', asleepNow);
					pip.textContent = asleepNow ? t('SLEEP') : t('NOW');
				}
				const chargeBar = col.querySelector('[data-tesla-plan-charge-bar]');
				const liveInput = (views.today && views.today.input_type) ? views.today.input_type : (plan.input_type || '');
				const chargeTone = chargeBarTone(slot, {
					isCharge: isCharge,
					isPast: isPastHour,
					isLiveNow: isLiveNowCharge,
					liveInput: liveInput,
				});
				if (chargeBar) {
					chargeBar.style.height = chargeH.toFixed(1) + '%';
					chargeBar.hidden = !isCharge;
					chargeBar.className = chargeBarClass(chargeTone, isCharge, asleepNow);
					chargeBar.setAttribute('data-charge-tone', chargeTone);
				}
				const bar = col.querySelector('[data-tesla-plan-bar]');
				if (bar) {
					bar.style.height = height.toFixed(1) + '%';
					bar.classList.toggle('is-held', isHold);
				}
				const tip = [h + ':00', modeLabel(mode)];
				if (isHold) {
					tip.push(t('固定残量'));
				}
				if (hasSoc) {
					tip.push(Math.round(Number(soc)) + '%');
				}
				if (isCharge) {
					tip.push(Math.round(watts).toLocaleString() + ' W');
					if (chargeTone !== 'plan') {
						const inputLabel = chargeInputLabel(chargeTone);
						if (inputLabel) {
							tip.push(inputLabel);
						}
					} else {
						tip.push(t('充電予定'));
					}
					if (asleepNow) {
						tip.push(t('計画のみ（未実行）'));
					}
				}
				if (slot.drive_km != null) {
					tip.push(Number(slot.drive_km).toFixed(1) + ' km');
				}
				if (slot.yen != null) {
					yenByHour[h] = Number(slot.yen);
					tip.push(Number(slot.yen).toFixed(1) + ' 円');
				}
				const stack = col.querySelector('.ecoflow-soc-stack');
				if (stack) {
					stack.setAttribute('title', tip.join(' · '));
				}

				const hourEl = root.querySelector('[data-tesla-plan-hour][data-hour="' + h + '"]');
				if (hourEl) {
					hourEl.classList.toggle('is-now', isNow);
					hourEl.classList.toggle('is-sleep-hold', isHold);
					hourEl.textContent = (h % 3 === 0 || isNow || (isHold && h === sleepFrom)) ? String(h) : '';
				}
			}
		}

		const price = priceLinePoints(yenByHour);
		root.querySelectorAll('[data-tesla-plan-price-line]').forEach(function (line) {
			line.setAttribute('points', price.points);
		});
		root.querySelectorAll('[data-tesla-plan-yen-tick]').forEach(function (el, i) {
			el.textContent = (price.max - (price.span * i / 4)).toFixed(1);
		});
		root.querySelectorAll('[data-tesla-plan-drive-line]').forEach(function (line) {
			line.setAttribute('points', wattsLinePoints(plan.drive_chart, plan.drive_chart_cap));
		});
		scrollPlanChartToNow();
	}

	function scrollPlanChartToNow() {
		const plot = root.querySelector('.ecoflow-rate-plot');
		const nowCol = root.querySelector('.ecoflow-plan-col.is-now');
		if (!plot || !nowCol || plot.scrollWidth <= plot.clientWidth + 8) {
			return;
		}

		const left = nowCol.offsetLeft - (plot.clientWidth / 2) + (nowCol.offsetWidth / 2);
		plot.scrollLeft = Math.max(0, left);
	}

	function applyBundle(plan) {
		if (!plan) {
			return;
		}
		if (plan.live_charging) {
			liveCharge = {
				charging: true,
				watts: Number(plan.live_charge_w || liveCharge.watts || 0),
			};
		}
		views.today = plan;
		if (plan.view_days && plan.view_days.yesterday) {
			views.yesterday = plan.view_days.yesterday;
		}
		if (plan.view_days && plan.view_days.tomorrow) {
			views.tomorrow = plan.view_days.tomorrow;
		}
		paintPlan(views[selectedDay] || plan);
	}

	root.querySelectorAll('[data-tesla-plan-day]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			const day = btn.getAttribute('data-tesla-plan-day');
			if (!day || !views[day]) {
				return;
			}
			selectedDay = day;
			root.querySelectorAll('[data-tesla-plan-day]').forEach(function (el) {
				el.classList.toggle('is-active', el === btn);
			});
			paintPlan(views[day]);
		});
	});

	document.addEventListener('gamingHubTeslaPlan', function (event) {
		if (event && event.detail) {
			applyBundle(event.detail);
		}
	});

	document.addEventListener('gamingHubTeslaFlow', function (event) {
		if (!event || !event.detail) {
			return;
		}
		rememberLiveCharge(event.detail);
		const flowInput = inputFromFlow(event.detail);
		if (flowInput && views.today) {
			views.today.input_type = flowInput.type;
			views.today.input_label = flowInput.label;
			views.today.input_watts = flowInput.watts;
			views.today.input_plugged = flowInput.plugged;
			views.today.input_charging = flowInput.charging;
			if (Array.isArray(views.today.slots) && flowInput.charging && flowInput.type && flowInput.type !== 'none') {
				const hourNow = new Date().getHours();
				const planDate = views.today.plan_date || '';
				views.today.slots.forEach(function (slot) {
					if (!slot || slot.date !== planDate || Number(slot.hour) !== hourNow) {
						return;
					}
					slot.charge_input = flowInput.type;
					slot.mode = 'charge';
				});
			}
		}
		if (views.today) {
			views.today.asleep = !!event.detail.asleep;
			if (event.detail.asleep && event.detail.battery_percent != null && !Number.isNaN(Number(event.detail.battery_percent))) {
				// Keep the held SOC; do not overwrite with fluctuating projections.
				if (views.today.soc_now == null) {
					views.today.soc_now = Number(event.detail.battery_percent);
				}
				if (views.today.sleep_from_hour == null) {
					views.today.sleep_from_hour = new Date().getHours();
				}
			}
			if (!event.detail.asleep && event.detail.battery_percent != null && !Number.isNaN(Number(event.detail.battery_percent))) {
				views.today.soc_now = Number(event.detail.battery_percent);
				views.today.sleep_from_hour = null;
			}
		}
		paintPlan(views[selectedDay] || views.today);
	});

	function loadPlan() {
		if (!window.gamingHubTeslaPlan || !gamingHubTeslaPlan.url) {
			return;
		}
		fetch(gamingHubTeslaPlan.url, { credentials: 'same-origin' })
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (payload && payload.success && payload.data) {
					applyBundle(payload.data);
				}
			})
			.catch(function () {
				// Keep painted plan.
			});
	}

	try {
		const flowRoot = document.getElementById('tesla-energy-flow-root');
		if (flowRoot && flowRoot.dataset.initial) {
			rememberLiveCharge(JSON.parse(flowRoot.dataset.initial));
		}
	} catch (err) {
		// Keep idle until the live poll arrives.
	}

	try {
		const initial = root.getAttribute('data-initial');
		if (initial) {
			applyBundle(JSON.parse(initial));
		}
	} catch (err) {
		// Keep server-rendered plan.
	}

	loadPlan();
	setInterval(loadPlan, 60000);
})();
