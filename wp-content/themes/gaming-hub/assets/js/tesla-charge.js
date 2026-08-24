(function () {
	'use strict';

	const root = document.querySelector('[data-tesla-charge]');
	if (!root || !window.gamingHubTeslaCharge) {
		return;
	}

	const cfg = window.gamingHubTeslaCharge;
	const statusEl = root.querySelector('[data-tesla-charge-status]');
	const buttons = root.querySelectorAll('[data-tesla-charge-action]');

	function t(text) {
		return window.gamingHubT ? window.gamingHubT(text) : text;
	}

	function setStatus(message, isError) {
		if (!statusEl) {
			return;
		}
		statusEl.textContent = message || '';
		statusEl.classList.toggle('is-error', !!isError);
	}

	function setBusy(busy) {
		buttons.forEach(function (button) {
			button.disabled = !!busy;
		});
	}

	function markCharging(charging) {
		root.classList.toggle('is-charging', !!charging);
		buttons.forEach(function (button) {
			const on = button.getAttribute('data-tesla-charge-action') === 'start';
			button.classList.toggle('is-current', charging ? !on : on);
		});
	}

	function send(action) {
		if (!cfg.url) {
			return;
		}

		setBusy(true);
		setStatus(t('送信中…'), false);

		fetch(cfg.url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.restNonce || '',
			},
			body: JSON.stringify({ action: action }),
		})
			.then(function (response) {
				return response.json().then(function (payload) {
					return { ok: response.ok, payload: payload };
				});
			})
			.then(function (result) {
				const payload = result.payload || {};
				if (!result.ok || !payload.success) {
					throw new Error(payload.message || t('充電コマンドに失敗しました。'));
				}

				const data = payload.data || {};
				setStatus(data.message || t('送りました。'), false);
				if (data.tesla) {
					markCharging(!!data.tesla.is_charging);
					document.dispatchEvent(new CustomEvent('gamingHubTeslaFlow', {
						detail: data.tesla,
					}));
				}
			})
			.catch(function (error) {
				setStatus(error.message || t('充電コマンドに失敗しました。'), true);
			})
			.then(function () {
				setBusy(false);
			});
	}

	buttons.forEach(function (button) {
		button.addEventListener('click', function () {
			send(button.getAttribute('data-tesla-charge-action'));
		});
	});

	document.addEventListener('gamingHubTeslaFlow', function (event) {
		if (event && event.detail) {
			markCharging(!!event.detail.live && !!event.detail.is_charging && !event.detail.asleep);
		}
	});

	try {
		const flowRoot = document.getElementById('tesla-energy-flow-root');
		if (flowRoot && flowRoot.dataset.initial) {
			const initial = JSON.parse(flowRoot.dataset.initial);
			markCharging(!!initial.live && !!initial.is_charging && !initial.asleep);
		}
	} catch (err) {
		// Keep idle until the live poll arrives.
	}
})();
