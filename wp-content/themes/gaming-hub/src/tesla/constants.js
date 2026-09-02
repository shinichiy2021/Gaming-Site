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

export function isRegenActive( status ) {
	if ( status?.asleep ) {
		return false;
	}

	return Number( status?.regen_w ) >= FLOW_THRESHOLD;
}

/** Plugged at a DC stall (charging or waiting on the stall). */
export function isSuperchargerConnected( status ) {
	if ( ! status || status.asleep || ! status.live ) {
		return false;
	}

	if ( status.supply_kind === 'supercharger' ) {
		return true;
	}

	if ( status.input_type === 'dc' ) {
		return true;
	}

	if ( status.fast_charger_present ) {
		return true;
	}

	return false;
}

export function connectionsForStatus( status ) {
	return FLOW_CONNECTIONS.map( ( connection ) => {
		if ( connection.id !== 'drive' || ! isRegenActive( status ) ) {
			return connection;
		}

		return {
			...connection,
			from: { id: 'drive', side: 'left' },
			to: { id: 'tesla', side: 'right' },
			color: '#64d2ff',
		};
	} );
}

export function wattsForFlow( flowId, status ) {
	if ( ! status || status.asleep ) {
		return 0;
	}

	if ( flowId === 'wall' ) {
		const watts = Number( status.wall_w ) || 0;
		if ( watts >= FLOW_THRESHOLD ) {
			return watts;
		}

		if ( status.live && status.is_charging && status.supply_kind !== 'supercharger' ) {
			return Math.max( watts, FLOW_THRESHOLD );
		}

		return watts;
	}

	if ( flowId === 'super' ) {
		const watts = Number( status.super_w ) || 0;

		if ( ! status.super_charging ) {
			return 0;
		}

		if ( watts >= FLOW_THRESHOLD ) {
			return watts;
		}

		// Under-reported watts during an active DC session only (never when the car reports 0).
		if ( watts > 0 ) {
			return Math.max( watts, FLOW_THRESHOLD );
		}

		return 0;
	}

	if ( flowId === 'drive' ) {
		if ( isRegenActive( status ) ) {
			return Number( status.regen_w ) || 0;
		}

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

export function formatWh( value ) {
	if ( value === null || value === undefined || value === '' || ! Number.isFinite( Number( value ) ) ) {
		return '—';
	}

	if ( Number( value ) > 1000 ) {
		return `${ ( Number( value ) / 1000 ).toLocaleString( undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 } ) } kWh`;
	}

	return `${ Math.round( value ).toLocaleString() } Wh`;
}

export function formatPack( remain, full ) {
	if ( remain === null || remain === undefined || ! Number.isFinite( Number( remain ) ) ) {
		return ( typeof window !== 'undefined' && window.gamingHubT )
			? window.gamingHubT( '未取得' )
			: '未取得';
	}

	if ( ! Number.isFinite( Number( full ) ) || Number( full ) <= 0 ) {
		return formatWh( remain );
	}

	return `${ formatWh( remain ) } / ${ formatWh( full ) }`;
}
