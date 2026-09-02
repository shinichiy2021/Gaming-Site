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

	const hubSwitcher = document.querySelector('.hub-switcher');
	if (hubSwitcher) {
		const mobileMq = window.matchMedia('(max-width: 768px)');
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

		function setHubSwitcherHidden(hidden) {
			const wasHidden = hubSwitcher.classList.contains('is-scroll-hidden');
			hubSwitcher.classList.toggle('is-scroll-hidden', hidden);
			if (wasHidden === hidden) {
				return;
			}
			lockedUntil = performance.now() + LOCK_MS;
			window.requestAnimationFrame(function () {
				lastY = window.pageYOffset || 0;
			});
		}

		function updateHubSwitcherVisibility() {
			const y = window.pageYOffset || 0;
			const now = performance.now();

			if (!mobileMq.matches) {
				setHubSwitcherHidden(false);
				lastY = y;
				ticking = false;
				return;
			}

			// Short pages: collapsing the bar shifts scrollY and flickers.
			if (scrollableRoom() < MIN_SCROLLABLE) {
				setHubSwitcherHidden(false);
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
				setHubSwitcherHidden(false);
			} else if (delta > DELTA_HIDE) {
				setHubSwitcherHidden(true);
			} else if (delta < -DELTA_SHOW) {
				setHubSwitcherHidden(false);
			}

			lastY = y;
			ticking = false;
		}

		window.addEventListener(
			'scroll',
			function () {
				if (ticking) {
					return;
				}
				ticking = true;
				window.requestAnimationFrame(updateHubSwitcherVisibility);
			},
			{ passive: true }
		);

		window.addEventListener('resize', function () {
			lockedUntil = 0;
			updateHubSwitcherVisibility();
		}, { passive: true });

		if (typeof mobileMq.addEventListener === 'function') {
			mobileMq.addEventListener('change', updateHubSwitcherVisibility);
		} else if (typeof mobileMq.addListener === 'function') {
			mobileMq.addListener(updateHubSwitcherVisibility);
		}
	}
})();
