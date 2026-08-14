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
		return Math.round(value).toLocaleString() + ' Wh';
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
		};
	}

	function linkWatts(pro) {
		return Number(pro.dc_out) || 0;
	}

	function syntheticDelta(pro) {
		const dcOut = linkWatts(pro);
		const charging = dcOut >= 8;

		return {
			device_name: 'Delta 3 1500',
			device_sn: '',
			online: charging,
			solar_in: 0,
			ac_in: 0,
			ac_out: 0,
			dc_out: 0,
			input_total: dcOut,
			output_total: 0,
			battery_percent: null,
			is_charging: charging,
			is_discharging: false,
			charge_state: charging ? 'DC 12V 受電中' : '待機',
			remain_time: 0,
			remain_time_label: '',
			remain_time_display: '—',
		};
	}

	function buildFlowPayload(data) {
		const pro = deviceFlowSlice(data);
		const delta = data.secondary && typeof data.secondary === 'object'
			? deviceFlowSlice(data.secondary)
			: syntheticDelta(pro);
		const link = linkWatts(pro);

		return {
			dual: true,
			solar_in: pro.solar_in,
			grid_in: pro.ac_in,
			ac_in: pro.ac_in,
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
			link_watts: link,
			home_out: Number(pro.ac_out) || 0,
		};
	}

	function dispatchFlowUpdate(data) {
		document.dispatchEvent(
			new CustomEvent('gamingHubEcoflowStatus', {
				detail: buildFlowPayload(data),
			})
		);
	}

	function applyDashboardData(data) {
		setField('ac_in_stat', formatWatts(data.ac_in));
		setField('ac_out', formatWatts(data.ac_out));
		setField('dc_out', formatWatts(data.dc_out));
		setField('dc12v_link', formatWatts(data.dc_out));
		setField('battery_temp', formatTemp(data.battery_temp));
		setField('remain_capacity', formatWh(data.remain_capacity));
		setField('charge_state_stat', data.charge_state);

		if (data.secondary) {
			setField('secondary_charge_state', data.secondary.charge_state);
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

	refreshDashboard();
	setInterval(refreshDashboard, gamingHubEcoflow.interval || 60000);

	if (window.gamingHubActiveRefresh) {
		window.gamingHubActiveRefresh.register(refreshDashboard);
	}
})();
