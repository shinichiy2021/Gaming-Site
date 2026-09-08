(function (window) {
	'use strict';

	window.gamingHubT = function (text) {
		if (text === null || text === undefined || text === '') {
			return text;
		}

		const i18n = window.gamingHubI18n || {};
		if (i18n.lang === 'ja' && i18n.ja && Object.prototype.hasOwnProperty.call(i18n.ja, text)) {
			return i18n.ja[text];
		}

		return text;
	};
})(window);
