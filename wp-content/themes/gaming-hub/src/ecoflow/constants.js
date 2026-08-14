export const FLOW_THRESHOLD = 8;

/** Dual-device: Solar/Grid → Pro → DC 12V → 1500, Pro AC → room. */
export const FLOW_CONNECTIONS_DUAL = [
	{
		id: 'solar',
		from: { id: 'solar', side: 'bottom' },
		to: { id: 'pro', side: 'top' },
		axis: 'vertical',
		color: '#00f5d4',
		showLabel: true,
	},
	{
		id: 'grid',
		from: { id: 'grid', side: 'right' },
		to: { id: 'pro', side: 'left' },
		axis: 'horizontal',
		color: '#7c4dff',
		showLabel: true,
	},
	{
		id: 'proToLink',
		from: { id: 'pro', side: 'right' },
		to: { id: 'link', side: 'left' },
		axis: 'horizontal',
		color: '#ffe600',
		showLabel: true,
	},
	{
		id: 'linkToDelta',
		from: { id: 'link', side: 'right' },
		to: { id: 'delta', side: 'left' },
		axis: 'horizontal',
		color: '#ffe600',
		showLabel: false,
	},
	{
		id: 'proToHome',
		from: { id: 'pro', side: 'bottom' },
		to: { id: 'home', side: 'top' },
		axis: 'vertical',
		align: 'from',
		color: '#69f0ae',
		showLabel: true,
		alwaysLabel: true,
	},
];

/** Single-device anchor connections. */
export const FLOW_CONNECTIONS_SINGLE = [
	{
		id: 'solar',
		from: { id: 'solar', side: 'bottom' },
		to: { id: 'battery', side: 'top' },
		axis: 'vertical',
		color: '#00f5d4',
	},
	{
		id: 'grid',
		from: { id: 'grid', side: 'right' },
		to: { id: 'battery', side: 'left' },
		axis: 'horizontal',
		color: '#7c4dff',
	},
	{
		id: 'home',
		from: { id: 'battery', side: 'right' },
		to: { id: 'home', side: 'left' },
		axis: 'horizontal',
		color: '#69f0ae',
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

export function linkWatts( pro ) {
	return Number( pro?.dc_out ) || 0;
}

export function homeOutput( status ) {
	if ( ! status ) {
		return 0;
	}

	if ( status.dual ) {
		return Number( status.home_out ) || Number( status.pro?.ac_out ) || 0;
	}

	return Number( status.output_total ) || 0;
}
