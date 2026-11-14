(function () {
	'use strict';

	const menuToggle = document.querySelector('.menu-toggle');
	const navigation = document.querySelector('.main-navigation');

	if (menuToggle && navigation) {
		menuToggle.addEventListener('click', function () {
			const isOpen = navigation.classList.toggle('is-open');
			menuToggle.setAttribute('aria-expanded', isOpen);
		});
	}

	function remapLegacyHubHash() {
		const map = (window.gamingHubNav && window.gamingHubNav.hashMap) || {};
		const dest = map[window.location.hash];
		if (!dest) {
			return;
		}

		try {
			const destUrl = new URL(dest, window.location.origin);
			const herePath = window.location.pathname.replace(/\/+$/, '') || '/';
			const destPath = destUrl.pathname.replace(/\/+$/, '') || '/';
			if (herePath === destPath) {
				if (destUrl.hash === '#energy') {
					const energy = document.querySelector('#energy');
					if (energy) {
						energy.scrollIntoView({ behavior: 'smooth' });
					}
				}
				return;
			}
			window.location.replace(destUrl.pathname + destUrl.search + destUrl.hash);
		} catch (err) {
			window.location.replace(dest);
		}
	}

	remapLegacyHubHash();

	function hubHashFromHref(href) {
		try {
			const url = new URL(href, window.location.href);
			if (url.origin !== window.location.origin) {
				return '';
			}
			if (url.pathname !== '/' && url.pathname !== window.location.pathname) {
				return '';
			}
			return url.hash || '';
		} catch (err) {
			return '';
		}
	}

	function closeMobileNav() {
		if (navigation && navigation.classList.contains('is-open')) {
			navigation.classList.remove('is-open');
			if (menuToggle) {
				menuToggle.setAttribute('aria-expanded', 'false');
			}
		}
	}

	document.querySelectorAll('a[href]').forEach(function (anchor) {
		anchor.addEventListener('click', function (e) {
			const hash = hubHashFromHref(this.getAttribute('href'));
			if (!hash) {
				return;
			}
			const target = document.querySelector(hash);
			if (!target) {
				return;
			}
			e.preventDefault();
			history.replaceState(null, '', hash);
			target.scrollIntoView({ behavior: 'smooth' });
			closeMobileNav();
		});
	});

	const header = document.querySelector('.site-header');
	if (header) {
		function updateHeader() {
			const currentScroll = window.pageYOffset;
			header.classList.toggle('is-scrolled', currentScroll > 40);
		}
		updateHeader();
		window.addEventListener('scroll', updateHeader, { passive: true });
	}

	const headerBar = document.querySelector('.site-header .header-inner');
	const hubSwitcher = document.querySelector('.hub-switcher');
	const hubTagNav = document.querySelector('.nav-menu--hub-tags');
	const HIDE_AFTER_Y = 72;
	const DELTA_HIDE = 14;
	const DELTA_SHOW = 14;
	const MIN_SCROLLABLE = 160;
	const LOCK_MS = 340;
	let lastY = window.pageYOffset || 0;
	let ticking = false;
	let lockedUntil = 0;

	function scrollableRoom() {
		return Math.max(
			0,
			(document.documentElement.scrollHeight || 0) - (window.innerHeight || 0)
		);
	}

	// Hub tags as a whole (EcoFlow / Tesla / Pokémon GO + mobile switcher): top only.
	function setHubTagsAtTop(atTop) {
		if (hubSwitcher) {
			hubSwitcher.classList.toggle('is-scroll-hidden', !atTop);
			hubSwitcher.setAttribute('aria-hidden', atTop ? 'false' : 'true');
		}
		if (hubTagNav) {
			hubTagNav.classList.toggle('is-scroll-hidden', !atTop);
			hubTagNav.setAttribute('aria-hidden', atTop ? 'false' : 'true');
		}
		document.body.classList.toggle('is-hub-tags-away', !atTop);
		if (header) {
			header.classList.toggle('is-hub-tags-away', !atTop);
		}
	}

	function setScrollChromeHidden(hidden) {
		if (!headerBar) {
			return;
		}
		const wasHidden = headerBar.classList.contains('is-scroll-hidden');
		headerBar.classList.toggle('is-scroll-hidden', hidden);
		if (header) {
			header.classList.toggle('is-chrome-hidden', hidden);
		}
		if (wasHidden === hidden) {
			return;
		}
		lockedUntil = performance.now() + LOCK_MS;
		window.requestAnimationFrame(function () {
			lastY = window.pageYOffset || 0;
		});
	}

	function updateScrollChromeVisibility() {
		const y = window.pageYOffset || 0;
		const now = performance.now();
		const shortPage = scrollableRoom() < MIN_SCROLLABLE;

		setHubTagsAtTop(shortPage || y <= HIDE_AFTER_Y);

		if (!headerBar) {
			lastY = y;
			ticking = false;
			return;
		}

		// Short pages: collapsing the bar shifts scrollY and flickers.
		if (shortPage) {
			setScrollChromeHidden(false);
			lastY = y;
			ticking = false;
			return;
		}

		if (now < lockedUntil) {
			lastY = y;
			ticking = false;
			return;
		}

		const delta = y - lastY;
		if (y <= HIDE_AFTER_Y) {
			setScrollChromeHidden(false);
		} else if (delta > DELTA_HIDE) {
			setScrollChromeHidden(true);
		} else if (delta < -DELTA_SHOW) {
			setScrollChromeHidden(false);
		}

		lastY = y;
		ticking = false;
	}

	if (headerBar || hubSwitcher || hubTagNav) {
		updateScrollChromeVisibility();
		window.addEventListener(
			'scroll',
			function () {
				if (ticking) {
					return;
				}
				ticking = true;
				window.requestAnimationFrame(updateScrollChromeVisibility);
			},
			{ passive: true }
		);

		window.addEventListener('resize', function () {
			lockedUntil = 0;
			updateScrollChromeVisibility();
		}, { passive: true });
	}

	// Horizontally scrollable charts: flag scroll position on a positioned host
	// so CSS can show an inward edge shadow ("there is more to scroll") and hide
	// it once the user reaches that edge.
	function initChartScrollHints() {
		const groups = [
			{ scroll: '.looop-chart-scroll', host: '.looop-chart-layout' },
			{ scroll: '.ecoflow-plan:not(.ecoflow-cal) .ecoflow-rate-plot', host: '.ecoflow-rate-chart' },
		];

		groups.forEach(function (group) {
			document.querySelectorAll(group.scroll).forEach(function (scroller) {
				const host = scroller.closest(group.host) || scroller;

				function update() {
					const max = scroller.scrollWidth - scroller.clientWidth;
					let pos;
					if (max <= 2) {
						pos = 'none';
					} else if (scroller.scrollLeft <= 1) {
						pos = 'start';
					} else if (scroller.scrollLeft >= max - 1) {
						pos = 'end';
					} else {
						pos = 'middle';
					}
					host.setAttribute('data-scroll-pos', pos);
				}

				scroller.addEventListener('scroll', update, { passive: true });
				window.addEventListener('resize', update, { passive: true });
				window.addEventListener('gaming-hub:chart-scroll-refresh', update, { passive: true });
				// Re-check after fonts/layout settle and after the EcoFlow plan
				// chart auto-scrolls to the current hour.
				update();
				window.setTimeout(update, 400);
			});
		});
	}

	initChartScrollHints();
})();
