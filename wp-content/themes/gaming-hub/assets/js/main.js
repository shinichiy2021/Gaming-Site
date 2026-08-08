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

	document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
		anchor.addEventListener('click', function (e) {
			const target = document.querySelector(this.getAttribute('href'));
			if (target) {
				e.preventDefault();
				target.scrollIntoView({ behavior: 'smooth' });
				if (navigation && navigation.classList.contains('is-open')) {
					navigation.classList.remove('is-open');
					menuToggle.setAttribute('aria-expanded', 'false');
				}
			}
		});
	});

	const header = document.querySelector('.site-header');
	if (header) {
		let lastScroll = 0;
		window.addEventListener('scroll', function () {
			const currentScroll = window.pageYOffset;
			if (currentScroll > 100) {
				header.style.boxShadow = '0 2px 20px rgba(0, 0, 0, 0.3)';
			} else {
				header.style.boxShadow = 'none';
			}
			lastScroll = currentScroll;
		});
	}
})();
