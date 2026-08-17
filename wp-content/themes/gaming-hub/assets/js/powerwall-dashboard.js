(function () {
	'use strict';

	const dashboard = document.querySelector('.pw-flow-dashboard');
	if (!dashboard || !window.gamingHubPowerwall) {
		return;
	}

	function t(text) {
		return window.gamingHubT ? window.gamingHubT(text) : text;
	}

	function setField(name, value) {
		dashboard.querySelectorAll('[data-pw-field="' + name + '"]').forEach(function (el) {
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

	function formatKwh(value, digits) {
		if (value === null || value === undefined) {
			return '—';
		}
		return Number(value).toLocaleString(undefined, {
			minimumFractionDigits: digits || 1,
			maximumFractionDigits: digits || 1,
		}) + ' kWh';
	}

	function formatYen(value) {
		if (value === null || value === undefined) {
			return '—';
		}
		return '¥' + Math.round(value).toLocaleString();
	}

	function applyCostMeta(cost) {
		if (!cost) {
			return;
		}

		const contractKw = cost.contract_kw != null
			? Number(cost.contract_kw).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 })
			: '6.0';

		setField(
			'cost_subtitle',
			(cost.provider || t('LOOOP スマートタイムONE（電灯）'))
				+ t(' · 契約 ') + contractKw + ' kW · '
				+ (cost.date_label || '—') + t('（24時間シミュレーション）')
		);
		setField('cost_total_kwh', formatKwh(cost.total_kwh));
		setField(
			'cost_grid_kwh',
			t('買電 ') + Number(cost.grid_import_kwh || 0).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' kWh'
				+ t(' · ソーラー自家消費 ')
				+ Number(cost.solar_self_kwh || 0).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' kWh'
		);
		setField('cost_with_solar', formatYen(cost.cost_with_solar_yen));
		setField(
			'cost_without_solar',
			t('ソーラーなし想定: ') + formatYen(cost.cost_without_solar_yen)
		);
		setField('cost_saved', formatYen(cost.saved_yen));
		setField(
			'cost_saved_percent',
			t('約 ') + Number(cost.saved_percent || 0).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + t('% 削減')
		);
		setField('cost_solar_gen', formatKwh(cost.solar_generation_kwh));
		setField(
			'cost_battery_self',
			t('Powerwall 自家消費 ')
				+ Number(cost.battery_self_kwh || 0).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' kWh'
		);

		const noteParts = [];
		if (cost.pricing_note) {
			noteParts.push(cost.pricing_note);
		}
		if (cost.disclaimer) {
			noteParts.push(cost.disclaimer);
		}
		if (noteParts.length) {
			setField('cost_note', noteParts.join(' · '));
		}
	}

	function setBadge(name, text, className) {
		const el = dashboard.querySelector('[data-pw-badge="' + name + '"]');
		if (!el) {
			return;
		}
		if (!text) {
			el.hidden = true;
			el.textContent = '';
			return;
		}
		el.hidden = false;
		el.textContent = text;
		if (className) {
			el.className = 'pw-model3-badge ' + className;
		}
	}

	function setBar(name, percent) {
		const el = dashboard.querySelector('[data-pw-bar="' + name + '"]');
		if (el) {
			el.style.width = Math.max(0, Math.min(100, Number(percent) || 0)) + '%';
		}
	}

	function applyModel3Battery(model3) {
		if (!model3) {
			return;
		}

		const soc = model3.battery_percent ?? '—';
		const isCharging = !!model3.is_charging;
		const unit = dashboard.querySelector('[data-pw-model3-unit]');
		const gauge = dashboard.querySelector('[data-pw-field="model3_gauge"]');
		const chargingPanel = dashboard.querySelector('[data-pw-charging-panel]');
		const ring = gauge ? gauge.querySelector('.pw-model3-battery-ring') : null;
		const photo = dashboard.querySelector('[data-pw-model3-photo]');
		const nextRaid = dashboard.querySelector('[data-pw-next-raid]');
		const statusKey = model3.status_key || (isCharging ? 'raid' : 'idle');

		setField('model3_name', model3.vehicle_name || 'Model 3');
		setField('model3_soc', soc + '%');
		setField('model3_soc_gauge', soc + '%');
		setField('model3_hp', model3.hp_label || (t('HP ') + soc + '%'));
		setField('model3_kwh', model3.battery_kwh_label || '—');
		setField('model3_state', model3.charge_state || '—');
		setField('model3_state_detail', model3.charge_state || '—');
		setField('model3_limit', model3.cap_label || (t('チャージキャップ ') + Number(model3.charge_limit_percent || 100).toLocaleString() + '%'));
		setField('model3_range', model3.range_label || (model3.range_km ? t('残MP ') + Number(model3.range_km).toLocaleString() + ' km' : '—'));
		setField('model3_quest', model3.quest_label || '—');
		setField('model3_charge_rate', model3.charge_rate_label || '—');
		setField('model3_charge_eta', model3.charge_eta_label || '—');
		setField('model3_charge_complete', model3.charge_complete_label || '—');
		setField('model3_drop', model3.drop_label || '—');
		setField('model3_odometer', model3.odometer_label || t('累計EXP —'));
		setField('model3_patch', model3.patch_label || t('パッチ —'));
		setField('model3_next_raid', model3.next_raid_label || '');
		setField(
			'model3_combo',
			[model3.combo_label, model3.supply_label].filter(Boolean).join(' · ')
		);

		setBadge('status', model3.badge_status || model3.charge_state || t('待機'), 'is-' + statusKey);
		setBadge('supply', model3.plugged ? (model3.supply_label || '') : '', 'is-supply');
		setBadge('sentry', model3.sentry_label || '', 'is-sentry');
		setBadge('lock', model3.lock_label || '', 'is-lock');
		setBar('hp', model3.battery_percent);
		setBar('mp', model3.mp_percent);
		setBar('quest', model3.quest_percent);

		if (unit) {
			unit.setAttribute('data-status', statusKey);
		}

		if (gauge && soc !== '—') {
			gauge.style.setProperty('--battery-level', String(soc));
		}

		if (ring) {
			ring.classList.toggle('is-charging', isCharging);
		}

		if (photo) {
			photo.classList.toggle('is-charging', isCharging);
		}

		if (chargingPanel) {
			chargingPanel.hidden = !isCharging;
			chargingPanel.classList.toggle('is-visible', isCharging);
		}

		if (nextRaid) {
			nextRaid.hidden = !model3.next_raid_label;
		}
	}

	function applyFlowData(data) {
		if (!data || !data.flow) {
			return;
		}

		const flow = data.flow;
		const powerwall = flow.powerwall || {};
		const model3 = flow.model3 || {};

		setField('solar_w', formatWatts(flow.solar_w));
		setField('home_w', formatWatts(flow.home_w));
		setField('model3_w', formatWatts(model3.watts));
		applyModel3Battery(model3);
		setField('grid_import_w', formatWatts(flow.grid_import_w));
		setField('powerwall_soc', (powerwall.battery_percent ?? '—') + '%');

		if (data.solar_meta) {
			const meta = data.solar_meta;
			const cloud = meta.cloud_cover !== null && meta.cloud_cover !== undefined
				? t('雲量 ') + Math.round(meta.cloud_cover) + '%'
				: t('雲量 —');
			const panelLabel = meta.panel_label || t('1.5 kW パネル');
			const note = t('ソーラー (') + panelLabel + '): ' + (meta.location || t('岐阜県多治見市'))
				+ t(' · 気象庁日照平年値 + 天気連動 · ')
				+ (meta.hour_slot || '—') + t(' 時点 · ')
				+ (meta.weather || '—') + ' · '
				+ cloud + t(' · 1時間ごとに更新');
			setField('solar_note', note);
		}

		if (data.home_meta) {
			const meta = data.home_meta;
			const note = t('ホーム: ') + (meta.profile || t('大人3人世帯（平均）'))
				+ t(' · 1日約 ') + (meta.daily_kwh || '10.5') + ' kWh · '
				+ (meta.time_band || '—') + ' · '
				+ (meta.hour_slot || '—') + t(' 時点');
			setField('home_note', note);
		}

		if (data.model3_meta && data.model3_source === 'simulated') {
			const meta = data.model3_meta;
			setField(
				'model3_note',
				t('Model 3: 1日平均 ') + (meta.daily_km || 30) + ' km'
					+ t(' · 充電約 ') + (meta.daily_kwh || '4.5') + ' kWh'
					+ ' · ' + (meta.charge_window || '17:00–22:30')
					+ t(' · 約 ') + Math.round(meta.charge_watts || 0).toLocaleString() + ' W'
			);
		}

		if (data.cost_meta) {
			applyCostMeta(data.cost_meta);
		}

		document.dispatchEvent(new CustomEvent('gamingHubPowerwallFlow', {
			detail: flow,
		}));
	}

	function refresh() {
		fetch(window.gamingHubPowerwall.refreshUrl, {
			credentials: 'same-origin',
			headers: { Accept: 'application/json' },
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (payload && payload.success && payload.data) {
					applyFlowData(payload.data);

					const updated = dashboard.querySelector('.pw-flow-updated');
					if (updated && payload.data.updated_at) {
						updated.textContent = t('最終更新: ') + payload.data.updated_at;
					}
				}
			})
			.catch(function () {
				// Ignore transient network errors; active-refresh will reload the page.
			});
	}

	const interval = Number(window.gamingHubPowerwall.interval) || 30000;
	const solarInterval = Number(window.gamingHubPowerwall.solarInterval) || 3600000;

	setInterval(refresh, interval);
	setInterval(refresh, solarInterval);
})();
