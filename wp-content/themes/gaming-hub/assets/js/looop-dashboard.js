(function () {
	'use strict';

	const dashboards = document.querySelectorAll('.looop-dashboard, .looop-home-widget');
	if (!dashboards.length || !window.gamingHubLooop) {
		return;
	}

	dashboards.forEach(function (dashboard) {
		const tabs = dashboard.querySelectorAll('.looop-day-tab');
		const panels = dashboard.querySelectorAll('.looop-chart-panel');

		tabs.forEach(function (tab) {
			tab.addEventListener('click', function () {
				if (tab.disabled) {
					return;
				}

				const day = tab.getAttribute('data-day');
				tabs.forEach(function (item) {
					item.classList.toggle('is-active', item === tab);
					item.setAttribute('aria-selected', item === tab ? 'true' : 'false');
				});
				panels.forEach(function (panel) {
					panel.classList.toggle('is-active', panel.getAttribute('data-day-panel') === day);
				});
				if (dashboard.classList.contains('looop-dashboard')) {
					dashboard.setAttribute('data-active-day', day);
				}
			});
		});
	});

	function updateText(root, selector, value) {
		const node = root.querySelector(selector);
		if (node && value !== undefined && value !== null) {
			node.textContent = value;
		}
	}

	function refreshForecast() {
		fetch(gamingHubLooop.refreshUrl, { credentials: 'same-origin' })
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (!payload.success || !payload.forecast) {
					return;
				}

				const forecast = payload.forecast;
				const marks = {
					sunny: 'でんき日和',
					caution: 'でんき注意報',
					alert: 'でんき警報',
					normal: '通常',
				};

				dashboards.forEach(function (dashboard) {
					updateText(dashboard, '.looop-updated', '最終更新: ' + forecast.updated_at);
					updateText(dashboard, '[data-looop-updated]', '更新: ' + forecast.updated_at);

					if (forecast.current) {
						const total = Number(forecast.current.total_price || forecast.current.power_price).toFixed(2);
						updateText(dashboard, '[data-looop-current-price]', total);
						updateText(dashboard, '[data-looop-current-time]', forecast.current.label);

						const markNode = dashboard.querySelector('[data-looop-current-mark]');
						const mark = marks[forecast.current.forecast_mark] || marks.normal;

						if (markNode) {
							markNode.textContent = mark;
						}

						const currentCard = dashboard.querySelector('.looop-current-card, .looop-home-stat-current');
						if (currentCard) {
							['looop-mark-sunny', 'looop-mark-caution', 'looop-mark-alert', 'looop-mark-normal'].forEach(function (markClass) {
								currentCard.classList.remove(markClass);
							});
							currentCard.classList.add('looop-mark-' + (forecast.current.forecast_mark || 'normal'));
						}
					}

					if (forecast.cheapest_hour) {
						const cheapestPrice = Number(forecast.cheapest_hour.total_price || forecast.cheapest_hour.power_price).toFixed(2);
						updateText(dashboard, '[data-looop-cheapest-price]', cheapestPrice);
						updateText(
							dashboard,
							'[data-looop-cheapest]',
							forecast.cheapest_hour.label
						);

						const cheapestCombined = dashboard.querySelector('[data-looop-cheapest]:not([data-looop-cheapest-price])');
						if (cheapestCombined && !dashboard.querySelector('[data-looop-cheapest-price]')) {
							cheapestCombined.textContent = forecast.cheapest_hour.label + ' 頃（' + cheapestPrice + ' 円/kWh）';
						}
					}
				});
			})
			.catch(function () {
				// Silent fail; page will refresh on next interval.
			});
	}

	setInterval(refreshForecast, gamingHubLooop.interval || 3600000);

	if (window.gamingHubActiveRefresh) {
		window.gamingHubActiveRefresh.register(refreshForecast);
	}
})();
