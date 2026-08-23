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

	function paintPlan(plan) {
		if (!plan) {
			return;
		}

		root.classList.toggle('is-deficit', !!plan.needs_grid);
		root.classList.toggle('is-ok', !plan.needs_grid);
		root.setAttribute('data-plan-id', plan.plan_id || '');
		root.setAttribute('data-plan-date', plan.plan_date || '');

		setText('[data-tesla-plan-title]', plan.title || '');
		setText('[data-tesla-plan-note]', plan.note || '');
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
		setText(
			'[data-tesla-plan-soc-end]',
			plan.soc_end == null ? '' : t('計画後 %s%%').replace('%s', String(Math.round(Number(plan.soc_end))))
		);
		setText(
			'[data-tesla-plan-target]',
			plan.target_soc == null ? '—' : String(Math.round(Number(plan.target_soc))) + '%'
		);
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
		const nextLabels = [];
		const yenByHour = [];

		slots.forEach(function (slot) {
			if (!slot) {
				return;
			}
			if (slot.date === viewDate && slot.hour != null) {
				byHour[Number(slot.hour)] = slot;
			} else if (slot.date && slot.date !== viewDate && slot.mode === 'charge' && slot.label) {
				nextLabels.push(slot.label);
			}
		});

		const nowSlot = byHour[hour] || {};
		const nowMode = isLiveDay ? (nowSlot.mode || 'idle') : 'idle';
		const nowEl = root.querySelector('[data-tesla-plan-now-mode]');
		const nowWrap = root.querySelector('.ecoflow-plan-stat-now');
		if (nowEl) {
			nowEl.textContent = modeLabel(nowMode);
		}
		if (nowWrap) {
			nowWrap.className = 'ecoflow-rates-stat ecoflow-plan-stat-now is-' + nowMode;
		}
		if (nowMode === 'drive' && nowSlot.drive_km != null) {
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
		const socSeries = Array.isArray(plan.soc_series) ? plan.soc_series : [];
		if (track) {
			for (let h = 0; h < 24; h += 1) {
				const slot = byHour[h] || {};
				const mode = slot.mode || 'idle';
				const isNow = isLiveDay && h === hour;
				const isCharge = mode === 'charge';
				const soc = socSeries[h];
				const hasSoc = soc !== null && soc !== undefined && !Number.isNaN(Number(soc));
				const height = hasSoc ? Math.max(0, Math.min(100, Number(soc))) : 0;
				const watts = isCharge ? Number(slot.watts != null ? slot.watts : chargeW) : 0;
				const chargeH = isCharge ? Math.max(8, Math.min(100, (watts / chargeW) * 100)) : 0;
				const col = track.querySelector('[data-tesla-plan-col][data-hour="' + h + '"]');
				if (!col) {
					continue;
				}
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
				const chargeBar = col.querySelector('[data-tesla-plan-charge-bar]');
				if (chargeBar) {
					chargeBar.style.height = chargeH.toFixed(1) + '%';
					chargeBar.hidden = !isCharge;
				}
				const bar = col.querySelector('[data-tesla-plan-bar]');
				if (bar) {
					bar.style.height = height.toFixed(1) + '%';
				}
				const tip = [h + ':00', modeLabel(mode)];
				if (hasSoc) {
					tip.push(Math.round(Number(soc)) + '%');
				}
				if (isCharge) {
					tip.push(Math.round(watts).toLocaleString() + ' W');
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
					hourEl.textContent = (h % 3 === 0 || isNow) ? String(h) : '';
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
	}

	function applyBundle(plan) {
		if (!plan) {
			return;
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
		const initial = root.getAttribute('data-initial');
		if (initial) {
			applyBundle(JSON.parse(initial));
		}
	} catch (err) {
		// Keep server-rendered plan.
	}

	loadPlan();
	setInterval(loadPlan, 30000);
})();
