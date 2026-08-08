(function () {
	'use strict';

	const config = window.gamingHubActiveRefreshConfig || {
		reloadAfterMs: 60000,
		reloadOnActive: false,
	};

	const handlers = [];
	let hiddenAt = null;
	let debounceTimer = null;

	function register(handler) {
		if (typeof handler === 'function') {
			handlers.push(handler);
		}
	}

	function runHandlers() {
		handlers.forEach(function (handler) {
			try {
				handler();
			} catch (error) {
				// Keep other refresh handlers running.
			}
		});
	}

	function onBecomeActive() {
		const hiddenMs = hiddenAt ? Date.now() - hiddenAt : 0;
		hiddenAt = null;

		if (config.reloadOnActive && hiddenMs >= config.reloadAfterMs) {
			window.location.reload();
			return;
		}

		runHandlers();
	}

	function scheduleBecomeActive() {
		if (debounceTimer) {
			clearTimeout(debounceTimer);
		}

		debounceTimer = setTimeout(function () {
			debounceTimer = null;
			onBecomeActive();
		}, 150);
	}

	function markHidden() {
		if (!hiddenAt) {
			hiddenAt = Date.now();
		}
	}

	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'hidden') {
			markHidden();
			return;
		}

		if (document.visibilityState === 'visible') {
			scheduleBecomeActive();
		}
	});

	window.addEventListener('pagehide', markHidden);

	window.addEventListener('pageshow', function (event) {
		if (event.persisted) {
			scheduleBecomeActive();
		}
	});

	window.addEventListener('focus', function () {
		if (document.visibilityState === 'visible') {
			scheduleBecomeActive();
		}
	});

	window.gamingHubActiveRefresh = {
		register: register,
		refresh: runHandlers,
	};
})();
