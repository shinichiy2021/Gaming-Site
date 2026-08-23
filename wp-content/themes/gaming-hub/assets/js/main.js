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
})();
