import { useCallback, useEffect, useRef, useState } from 'react';

const SWIPE_MIN_X = 72;
const SWIPE_MAX_MS = 650;
const SWIPE_RATIO = 1.25;

function pathOf(url) {
	try {
		return new URL(url, window.location.href).pathname.replace(/\/+$/, '') || '/';
	} catch {
		return '';
	}
}

function ignoreSwipeTarget(target) {
	if (!target || typeof target.closest !== 'function') {
		return true;
	}
	return Boolean(
		target.closest(
			'a, button, input, textarea, select, label, [contenteditable="true"], .hub-switcher, [data-no-hub-swipe], .content-wrapper table, .pgo-event-table-wrap, canvas, [role="slider"]'
		)
	);
}

/**
 * Mobile EcoFlow ↔ Tesla switcher + optional SPA panel routing.
 */
export default function HubApp({ items, initialActive, spaEnabled, navLabel }) {
	const tabs = Array.isArray(items) ? items : [];
	const [active, setActive] = useState(initialActive || tabs[0]?.slug || 'ecoflow');
	const [leaving, setLeaving] = useState('');
	const activeRef = useRef(active);
	const navigatingRef = useRef(false);
	const swipeRef = useRef({ tracking: false, x: 0, y: 0, t: 0 });

	useEffect(() => {
		activeRef.current = active;
	}, [active]);

	const urlFor = useCallback(
		(slug) => {
			const item = tabs.find((tab) => tab.slug === slug);
			return item?.url || '';
		},
		[tabs]
	);

	const applyPanels = useCallback((slug) => {
		const panels = document.querySelectorAll('[data-hub-panel]');
		if (!panels.length) {
			return false;
		}
		panels.forEach((panel) => {
			const match = panel.getAttribute('data-hub-panel') === slug;
			panel.classList.toggle('is-active', match);
			panel.toggleAttribute('hidden', !match);
			panel.setAttribute('aria-hidden', match ? 'false' : 'true');
		});
		window.requestAnimationFrame(() => {
			window.dispatchEvent(new Event('resize'));
		});
		return true;
	}, []);

	const goTo = useCallback(
		(slug, { push = true, leaveDir = '' } = {}) => {
			if (!slug || slug === activeRef.current) {
				return;
			}
			const url = urlFor(slug);
			if (!url) {
				return;
			}

			if (!spaEnabled || !document.querySelector('[data-hub-spa-panels]')) {
				if (leaveDir) {
					document.body.classList.add(
						leaveDir === 'left' ? 'hub-swipe-leave-left' : 'hub-swipe-leave-right'
					);
					window.setTimeout(() => {
						window.location.assign(url);
					}, 140);
					return;
				}
				window.location.assign(url);
				return;
			}

			if (navigatingRef.current) {
				return;
			}
			navigatingRef.current = true;
			if (leaveDir) {
				setLeaving(leaveDir);
			}

			window.setTimeout(() => {
				setActive(slug);
				applyPanels(slug);
				try {
					const next = new URL(url, window.location.href);
					const state = { hub: slug };
					if (push) {
						window.history.pushState(state, '', next.pathname + next.search + next.hash);
					} else {
						window.history.replaceState(state, '', next.pathname + next.search + next.hash);
					}
				} catch {
					// Ignore history errors.
				}
				setLeaving('');
				navigatingRef.current = false;
				window.scrollTo({ top: 0, behavior: 'auto' });
			}, leaveDir ? 120 : 0);
		},
		[applyPanels, spaEnabled, urlFor]
	);

	useEffect(() => {
		if (!spaEnabled) {
			return undefined;
		}
		applyPanels(active);
		const onPop = () => {
			const path = window.location.pathname.replace(/\/+$/, '') || '/';
			const match = tabs.find((tab) => pathOf(tab.url) === path);
			if (match) {
				setActive(match.slug);
				applyPanels(match.slug);
			}
		};
		window.addEventListener('popstate', onPop);
		return () => window.removeEventListener('popstate', onPop);
	}, [active, applyPanels, spaEnabled, tabs]);

	useEffect(() => {
		const mobileMq = window.matchMedia('(max-width: 768px)');

		const onStart = (event) => {
			if (!mobileMq.matches || navigatingRef.current) {
				return;
			}
			if (!event.changedTouches || event.changedTouches.length !== 1) {
				return;
			}
			if (ignoreSwipeTarget(event.target)) {
				swipeRef.current.tracking = false;
				return;
			}
			const touch = event.changedTouches[0];
			swipeRef.current = {
				tracking: true,
				x: touch.clientX,
				y: touch.clientY,
				t: Date.now(),
			};
		};

		const onEnd = (event) => {
			const swipe = swipeRef.current;
			if (!swipe.tracking || !mobileMq.matches || navigatingRef.current) {
				swipeRef.current.tracking = false;
				return;
			}
			swipe.tracking = false;
			if (!event.changedTouches || event.changedTouches.length !== 1) {
				return;
			}
			const touch = event.changedTouches[0];
			const dx = touch.clientX - swipe.x;
			const dy = touch.clientY - swipe.y;
			const elapsed = Date.now() - swipe.t;
			if (elapsed > SWIPE_MAX_MS || Math.abs(dx) < SWIPE_MIN_X) {
				return;
			}
			if (Math.abs(dx) < Math.abs(dy) * SWIPE_RATIO) {
				return;
			}

			const index = tabs.findIndex((tab) => tab.slug === activeRef.current);
			let nextIndex = index;
			if (index < 0) {
				nextIndex = dx < 0 ? tabs.length - 1 : 0;
			} else {
				nextIndex = index + (dx < 0 ? 1 : -1);
			}
			if (nextIndex < 0 || nextIndex >= tabs.length) {
				return;
			}
			goTo(tabs[nextIndex].slug, {
				push: true,
				leaveDir: dx < 0 ? 'left' : 'right',
			});
		};

		const onCancel = () => {
			swipeRef.current.tracking = false;
		};

		document.addEventListener('touchstart', onStart, { passive: true });
		document.addEventListener('touchend', onEnd, { passive: true });
		document.addEventListener('touchcancel', onCancel, { passive: true });
		return () => {
			document.removeEventListener('touchstart', onStart);
			document.removeEventListener('touchend', onEnd);
			document.removeEventListener('touchcancel', onCancel);
		};
	}, [goTo, tabs]);

	useEffect(() => {
		document.body.classList.toggle('hub-swipe-leave-left', leaving === 'left');
		document.body.classList.toggle('hub-swipe-leave-right', leaving === 'right');
		return () => {
			document.body.classList.remove('hub-swipe-leave-left', 'hub-swipe-leave-right');
		};
	}, [leaving]);

	if (!tabs.length) {
		return null;
	}

	return (
		<div className="hub-switcher-track" role="tablist" aria-label={navLabel || 'Hub'}>
			{tabs.map((tab) => {
				const isActive = tab.slug === active;
				const className = [
					'hub-switcher-tab',
					`hub-switcher-tab--${tab.slug}`,
					isActive ? 'is-active' : '',
				]
					.filter(Boolean)
					.join(' ');
				return (
					<a
						key={tab.slug}
						className={className}
						href={tab.url}
						role="tab"
						aria-selected={isActive ? 'true' : 'false'}
						aria-current={isActive ? 'page' : undefined}
						data-hub-slug={tab.slug}
						onClick={(event) => {
							if (!spaEnabled) {
								return;
							}
							if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
								return;
							}
							event.preventDefault();
							goTo(tab.slug, { push: true });
						}}
					>
						<span className="hub-switcher-label">{tab.label}</span>
					</a>
				);
			})}
		</div>
	);
}
