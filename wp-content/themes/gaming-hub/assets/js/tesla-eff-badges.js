(function () {
	'use strict';

	const root = document.querySelector('[data-tesla-eff-badges]');
	if (!root) {
		return;
	}

	const tiers = ['idle', 'good', 'ok', 'high', 'low', 'regen'];

	function setBadge(sel, text, tier) {
		const el = root.querySelector(sel);
		if (!el) {
			return;
		}
		const label = text == null ? '' : String(text);
		el.textContent = label;
		el.hidden = !label;
		tiers.forEach(function (name) {
			el.classList.toggle('is-' + name, name === (tier || 'idle'));
		});
	}

	function paint(eff) {
		if (!eff || typeof eff !== 'object') {
			root.hidden = true;
			return;
		}
		const wh = eff.badge_wh || '';
		const regen = eff.badge_regen || '';
		setBadge('[data-tesla-eff-wh]', wh, eff.tier_wh || 'idle');
		setBadge('[data-tesla-eff-regen]', regen, eff.tier_regen || 'idle');
		root.hidden = !wh && !regen;
	}

	document.addEventListener('gamingHubTeslaFlow', function (event) {
		const detail = event && event.detail ? event.detail : null;
		if (detail && detail.efficiency) {
			paint(detail.efficiency);
		}
	});
})();
