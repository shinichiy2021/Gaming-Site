(function () {
	'use strict';

	const dashboard = document.querySelector('.pw-flow-dashboard');
	if (!dashboard || !window.gamingHubPowerwall) {
		return;
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
			(cost.provider || 'LOOOP スマートタイムONE（電灯）')
				+ ' · 契約 ' + contractKw + ' kW · '
				+ (cost.date_label || '—') + '（24時間シミュレーション）'
		);
		setField('cost_total_kwh', formatKwh(cost.total_kwh));
		setField(
			'cost_grid_kwh',
			'買電 ' + Number(cost.grid_import_kwh || 0).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' kWh'
				+ ' · ソーラー自家消費 '
				+ Number(cost.solar_self_kwh || 0).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' kWh'
		);
		setField('cost_with_solar', formatYen(cost.cost_with_solar_yen));
		setField(
			'cost_without_solar',
			'ソーラーなし想定: ' + formatYen(cost.cost_without_solar_yen)
		);
		setField('cost_saved', formatYen(cost.saved_yen));
		setField(
			'cost_saved_percent',
			'約 ' + Number(cost.saved_percent || 0).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '% 削減'
		);
		setField('cost_solar_gen', formatKwh(cost.solar_generation_kwh));
		setField(
			'cost_battery_self',
			'Powerwall 自家消費 '
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
		setField('model3_soc', (model3.battery_percent ?? '—') + '%');
		setField('model3_state', model3.charge_state || '—');
		setField('grid_import_w', formatWatts(flow.grid_import_w));
		setField('powerwall_soc', (powerwall.battery_percent ?? '—') + '%');

		if (data.solar_meta) {
			const meta = data.solar_meta;
			const cloud = meta.cloud_cover !== null && meta.cloud_cover !== undefined
				? '雲量 ' + Math.round(meta.cloud_cover) + '%'
				: '雲量 —';
			const panelLabel = meta.panel_label || '1.5 kW パネル';
			const note = 'ソーラー (' + panelLabel + '): ' + (meta.location || '岐阜県多治見市')
				+ ' · 気象庁日照平年値 + 天気連動 · '
				+ (meta.hour_slot || '—') + ' 時点 · '
				+ (meta.weather || '—') + ' · '
				+ cloud + ' · 1時間ごとに更新';
			setField('solar_note', note);
		}

		if (data.home_meta) {
			const meta = data.home_meta;
			const note = 'ホーム: ' + (meta.profile || '大人3人世帯（平均）')
				+ ' · 1日約 ' + (meta.daily_kwh || '10.5') + ' kWh · '
				+ (meta.time_band || '—') + ' · '
				+ (meta.hour_slot || '—') + ' 時点';
			setField('home_note', note);
		}

		if (data.model3_meta && data.model3_source === 'simulated') {
			const meta = data.model3_meta;
			setField(
				'model3_note',
				'Model 3: 1日平均 ' + (meta.daily_km || 30) + ' km'
					+ ' · 充電約 ' + (meta.daily_kwh || '4.5') + ' kWh'
					+ ' · ' + (meta.charge_window || '17:00–22:30')
					+ ' · 約 ' + Math.round(meta.charge_watts || 0).toLocaleString() + ' W'
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
						updated.textContent = '最終更新: ' + payload.data.updated_at;
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
