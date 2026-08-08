export const FLOW_THRESHOLD = 8;

/** Dual-device anchor connections (resolved from DOM at runtime). */
export const FLOW_CONNECTIONS_DUAL = [
	{
		id: 'solar',
		from: { id: 'solar', side: 'right' },
		to: { id: 'pro', side: 'left' },
		axis: 'horizontal',
		color: '#00e676',
	},
	{
		id: 'grid',
		from: { id: 'grid', side: 'top' },
		to: { id: 'pro', side: 'bottom' },
		axis: 'vertical',
		color: '#64b5f6',
	},
	{
		id: 'proToDelta',
		from: { id: 'pro', side: 'right' },
		to: { id: 'delta', side: 'left' },
		axis: 'horizontal',
		color: '#00e676',
	},
	{
		id: 'deltaToHome',
		from: { id: 'delta', side: 'right' },
		to: { id: 'home', side: 'left' },
		axis: 'horizontal',
		color: '#69f0ae',
	},
];

/** Single-device anchor connections. */
export const FLOW_CONNECTIONS_SINGLE = [
	{
		id: 'solar',
		from: { id: 'solar', side: 'bottom' },
		to: { id: 'battery', side: 'top' },
		axis: 'vertical',
		color: '#00e676',
	},
	{
		id: 'grid',
		from: { id: 'grid', side: 'right' },
		to: { id: 'battery', side: 'left' },
		axis: 'horizontal',
		color: '#64b5f6',
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

export function linkWatts( pro, delta ) {
	if ( ! pro || ! delta ) {
		return 0;
	}

	const proAcOut = Number( pro.ac_out ) || 0;
	const deltaAcIn = Number( delta.ac_in ) || 0;

	if ( proAcOut >= FLOW_THRESHOLD && deltaAcIn >= FLOW_THRESHOLD ) {
		return Math.min( proAcOut, deltaAcIn );
	}

	if ( deltaAcIn >= FLOW_THRESHOLD ) {
		return deltaAcIn;
	}

	if ( proAcOut >= FLOW_THRESHOLD && delta.is_charging ) {
		return proAcOut;
	}

	return 0;
}

export function homeOutput( status ) {
	if ( ! status ) {
		return 0;
	}

	if ( status.dual && status.delta ) {
		const total = Number( status.delta.output_total ) || 0;
		if ( total >= FLOW_THRESHOLD ) {
			return total;
		}

		return Math.max(
			Number( status.delta.ac_out ) || 0,
			0
		);
	}

	return Number( status.output_total ) || 0;
}
