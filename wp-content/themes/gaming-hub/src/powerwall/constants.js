export const FLOW_THRESHOLD = 8;

/** Overlay connections on the house diagram. */
export const FLOW_CONNECTIONS = [
	{
		id: 'solar',
		from: { id: 'solar', side: 'bottom' },
		to: { id: 'powerwall', side: 'top' },
		color: '#ffb300',
		showLabel: true,
	},
	{
		id: 'home',
		from: { id: 'powerwall', side: 'left' },
		to: { id: 'home', side: 'right' },
		color: '#69f0ae',
		showLabel: true,
	},
	{
		id: 'car',
		from: { id: 'powerwall', side: 'bottom' },
		to: { id: 'model3', side: 'top' },
		color: '#e82127',
		showLabel: true,
	},
	{
		id: 'gridImport',
		from: { id: 'grid', side: 'left' },
		to: { id: 'powerwall', side: 'right' },
		color: '#64b5f6',
		showLabel: true,
	},
];

export function flowSpeed( watts ) {
	return Math.max( 0.12, Math.min( 0.85, 900 / Math.max( watts, 1 ) ) );
}

export function formatWatts( value ) {
	if ( value === null || value === undefined ) {
		return '—';
	}

	return `${ Math.round( value ).toLocaleString() } W`;
}

export function wattsForFlow( flowId, status ) {
	if ( ! status ) {
		return 0;
	}

	if ( flowId === 'solar' ) {
		return Number( status.solar_w ) || 0;
	}

	if ( flowId === 'home' ) {
		return Number( status.home_w ) || 0;
	}

	if ( flowId === 'car' ) {
		return Number( status.model3?.watts ) || 0;
	}

	if ( flowId === 'gridImport' ) {
		return Number( status.grid_import_w ) || 0;
	}

	return 0;
}

export function isFlowActive( flowId, status ) {
	return wattsForFlow( flowId, status ) >= FLOW_THRESHOLD;
}
