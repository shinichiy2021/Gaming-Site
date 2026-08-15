(function (window) {
	'use strict';

	window.gamingHubT = function (text) {
		if (text === null || text === undefined || text === '') {
			return text;
		}

		const i18n = window.gamingHubI18n || {};
		if (i18n.lang !== 'en' || !i18n.en) {
			return text;
		}

		return Object.prototype.hasOwnProperty.call(i18n.en, text) ? i18n.en[text] : text;
	};
})(window);
