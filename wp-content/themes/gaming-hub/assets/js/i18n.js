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
})(window);
