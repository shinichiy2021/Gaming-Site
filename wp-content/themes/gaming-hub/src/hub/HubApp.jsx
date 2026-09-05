import { useCallback, useEffect, useRef, useState } from 'react';

function pathOf(url) {
	try {
		return new URL(url, window.location.href).pathname.replace(/\/+$/, '') || '/';
	} catch {
		return '';
	}
}

/**
 * Mobile EcoFlow ↔ Tesla switcher + optional SPA panel routing.
 */
export default function HubApp({ items, initialActive, spaEnabled, navLabel }) {
	const tabs = Array.isArray(items) ? items : [];
	const [active, setActive] = useState(initialActive || tabs[0]?.slug || 'ecoflow');
	const activeRef = useRef(active);
	const navigatingRef = useRef(false);

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
		(slug, { push = true } = {}) => {
			if (!slug || slug === activeRef.current) {
				return;
			}
			const url = urlFor(slug);
			if (!url) {
				return;
			}

			if (!spaEnabled || !document.querySelector('[data-hub-spa-panels]')) {
				window.location.assign(url);
				return;
			}

			if (navigatingRef.current) {
				return;
			}
			navigatingRef.current = true;
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
			navigatingRef.current = false;
			window.scrollTo({ top: 0, behavior: 'auto' });
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
