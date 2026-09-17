(function (window) {
	'use strict';

	function apostropheVariants(text) {
		if (typeof text !== 'string' || text === '') {
			return [];
		}

		const curly = '\u2019';
		const straight = "'";
		const variants = [text];
		const a = text.split(curly).join(straight);
		const b = text.split(straight).join(curly);
		if (a !== text) {
			variants.push(a);
		}
		if (b !== text && b !== a) {
			variants.push(b);
		}

		return variants;
	}

	window.gamingHubT = function (text) {
		if (text === null || text === undefined || text === '') {
			return text;
		}

		const i18n = window.gamingHubI18n || {};
		if (i18n.lang !== 'ja' || !i18n.ja) {
			return text;
		}

		const map = i18n.ja;
		const keys = apostropheVariants(String(text));
		for (let i = 0; i < keys.length; i++) {
			if (Object.prototype.hasOwnProperty.call(map, keys[i])) {
				return map[keys[i]];
			}
		}

		return text;
	};

	window.gamingHubLang = function () {
		const i18n = window.gamingHubI18n || {};
		return i18n.lang === 'en' ? 'en' : 'ja';
	};

	/** Locale-aware yen: "¥1,234" (en) / "1,234 円" (ja). */
	window.gamingHubYen = function (value, decimals) {
		const n = Number(value);
		if (!Number.isFinite(n)) {
			return '—';
		}
		const places = typeof decimals === 'number' ? decimals : 0;
		const amount = places > 0
			? n.toLocaleString(undefined, { minimumFractionDigits: places, maximumFractionDigits: places })
			: Math.round(n).toLocaleString();
		return window.gamingHubLang() === 'en' ? '¥' + amount : amount + ' 円';
	};

	/** Append lang= so REST matches the switcher even when the cookie is missing. */
	window.gamingHubWithLang = function (url) {
		const base = String(url || '');
		if (!base) {
			return base;
		}
		const lang = window.gamingHubLang();
		const join = base.indexOf('?') >= 0 ? '&' : '?';
		return base + join + 'lang=' + encodeURIComponent(lang);
	};
})(window);
