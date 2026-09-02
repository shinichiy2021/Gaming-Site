import { createRoot } from 'react-dom/client';
import HubApp from './HubApp';

const mountNode = document.getElementById( 'hub-spa-root' );

if ( mountNode ) {
	const config = window.gamingHubSpa || {};
	const items = Array.isArray( config.items ) ? config.items : [];
	const active = config.active || items[0]?.slug || 'ecoflow';
	const spaEnabled = Boolean( config.spaEnabled );
	const navLabel = config.labels?.nav || mountNode.getAttribute( 'aria-label' ) || 'Hub';

	createRoot( mountNode ).render(
		<HubApp
			items={ items }
			initialActive={ active }
			spaEnabled={ spaEnabled }
			navLabel={ navLabel }
		/>
	);
}
