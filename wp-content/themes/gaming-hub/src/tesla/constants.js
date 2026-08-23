export const FLOW_THRESHOLD = 80;

export const FLOW_CONNECTIONS = [
	{
		id: 'wall',
		from: { id: 'wall', side: 'right' },
		to: { id: 'tesla', side: 'left' },
		color: '#64d2ff',
		showLabel: true,
	},
	{
		id: 'super',
		from: { id: 'super', side: 'right' },
		to: { id: 'tesla', side: 'left' },
		color: '#e82127',
		showLabel: true,
	},
	{
		id: 'drive',
		from: { id: 'tesla', side: 'right' },
		to: { id: 'drive', side: 'left' },
		color: '#ffb300',
		showLabel: true,
	},
	{
		id: 'cabin',
		from: { id: 'tesla', side: 'right' },
		to: { id: 'cabin', side: 'left' },
		color: '#69f0ae',
		showLabel: true,
	},
];

export function flowSpeed( watts ) {
	return Math.max( 0.12, Math.min( 0.85, 900 / Math.max( watts, 1 ) ) );
}

export function formatWatts( value, standbyLabel ) {
	const idle = standbyLabel || '待機';
	if ( value === null || value === undefined || value === '' ) {
		return idle;
	}

	const watts = Number( value );
	if ( ! Number.isFinite( watts ) || watts < FLOW_THRESHOLD ) {
		return idle;
	}

	return `${ Math.round( watts ).toLocaleString() } W`;
}

export function wattsForFlow( flowId, status ) {
	if ( ! status ) {
		return 0;
	}

	if ( flowId === 'wall' ) {
		return Number( status.wall_w ) || 0;
	}

	if ( flowId === 'super' ) {
		return Number( status.super_w ) || 0;
	}

	if ( flowId === 'drive' ) {
		return Number( status.drive_w ) || 0;
	}

	if ( flowId === 'cabin' ) {
		return Number( status.cabin_w ) || 0;
	}

	return 0;
}

export function isFlowActive( flowId, status ) {
	return wattsForFlow( flowId, status ) >= FLOW_THRESHOLD;
}

export function batteryTone( percent ) {
	if ( ! Number.isFinite( percent ) ) {
		return { color: '#8b93a7', className: '' };
	}

	if ( percent <= 10 ) {
		return { color: '#ff453a', className: 'is-critical' };
	}

	if ( percent <= 20 ) {
		return { color: '#ffd60a', className: 'is-low' };
	}

	return { color: '#34c759', className: 'is-ok' };
}
