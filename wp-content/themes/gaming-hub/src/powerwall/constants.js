export const FLOW_THRESHOLD = 8;

/** Powerwall 3 + Model 3 connections (grid import only). */
export const FLOW_CONNECTIONS = [
	{
		id: 'solar',
		from: { id: 'solar', side: 'right' },
		to: { id: 'powerwall', side: 'left' },
		axis: 'horizontal',
		color: '#ffb300',
	},
	{
		id: 'home',
		from: { id: 'powerwall', side: 'right' },
		to: { id: 'home', side: 'left' },
		axis: 'horizontal',
		color: '#69f0ae',
	},
	{
		id: 'car',
		from: { id: 'powerwall', side: 'bottom' },
		to: { id: 'model3', side: 'top' },
		axis: 'vertical',
		color: '#e82127',
	},
	{
		id: 'gridImport',
		from: { id: 'grid', side: 'top' },
		to: { id: 'powerwall', side: 'bottom' },
		axis: 'vertical',
		color: '#64b5f6',
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
