export const FLOW_THRESHOLD = 8;

/** Independent: Grid → Pro → room, LV solar → Delta 3 1500. */
export const FLOW_CONNECTIONS_DUAL = [
	{
		id: 'grid',
		from: { id: 'grid', side: 'right' },
		to: { id: 'pro', side: 'left' },
		axis: 'horizontal',
		color: '#7c4dff',
		showLabel: true,
	},
	{
		id: 'hv',
		from: { id: 'hv', side: 'right' },
		to: { id: 'pro', side: 'left' },
		axis: 'horizontal',
		color: '#ff9100',
		showLabel: true,
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
	{
		id: 'deltaGrid',
		from: { id: 'deltaGrid', side: 'right' },
		to: { id: 'delta', side: 'left' },
		axis: 'horizontal',
		color: '#7c4dff',
		showLabel: true,
	},
	{
		id: 'solar',
		from: { id: 'solar', side: 'right' },
		to: { id: 'delta', side: 'left' },
		axis: 'horizontal',
		color: '#00f5d4',
		showLabel: true,
	},
	{
		id: 'deltaToUps',
		from: { id: 'delta', side: 'bottom' },
		to: { id: 'ups', side: 'top' },
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

export function parseSoc( value ) {
	if ( value === null || value === undefined || value === '' ) {
		return null;
	}

	const soc = Number( value );
	if ( ! Number.isFinite( soc ) ) {
		return null;
	}

	return Math.round( Math.max( 0, Math.min( 100, soc ) ) * 10 ) / 10;
}

export function formatSoc( value ) {
	const soc = parseSoc( value );
	if ( soc === null ) {
		return ( typeof window !== 'undefined' && window.gamingHubT )
			? window.gamingHubT( '未取得' )
			: '未取得';
	}

	if ( Math.abs( soc - Math.round( soc ) ) < 0.05 ) {
		return `${ Math.round( soc ) }%`;
	}

	return `${ soc.toFixed( 1 ) }%`;
}

export function formatWatts( value ) {
	if ( value === null || value === undefined ) {
		return ( typeof window !== 'undefined' && window.gamingHubT )
			? window.gamingHubT( '未取得' )
			: '未取得';
	}

	return `${ Math.round( Number( value ) ).toLocaleString() } W`;
}

export function formatWh( value ) {
	if ( value === null || value === undefined ) {
		return '—';
	}

	if ( Number( value ) > 1000 ) {
		return `${ ( Number( value ) / 1000 ).toLocaleString( undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 } ) } kWh`;
	}

	return `${ Math.round( value ).toLocaleString() } Wh`;
}

export function formatPack( remain, full ) {
	if ( ! Number.isFinite( Number( full ) ) || Number( full ) <= 0 ) {
		return formatWh( remain );
	}

	return `${ formatWh( remain ) } / ${ formatWh( full ) }`;
}

export function solarToDelta( status ) {
	if ( ! status ) {
		return null;
	}

	const source = status.delta?.solar_in_source || status.solar_in_source || '';
	if ( source === 'unavailable' ) {
		return null;
	}

	const watts = status.delta?.solar_in ?? status.solar_in;
	if ( watts === null || watts === undefined ) {
		return null;
	}

	return Number( watts ) || 0;
}

export function proGridCharge( status ) {
	if ( ! status ) {
		return { active: false, watts: 0, message: '' };
	}

	if ( status.pro_grid_charge && typeof status.pro_grid_charge === 'object' ) {
		return {
			active: !! status.pro_grid_charge.active,
			watts: Number( status.pro_grid_charge.watts ) || 0,
			message: status.pro_grid_charge.message || '',
		};
	}

	return { active: false, watts: 0, message: '' };
}

export function deltaGridAc( status ) {
	if ( ! status ) {
		return 0;
	}

	return Number( status.delta?.ac_in ) || 0;
}

export function hvInput( status ) {
	if ( ! status ) {
		return 0;
	}

	return Number( status.hv_in ) || Number( status.pro?.hv_in ) || 0;
}

export function upsOutput( status ) {
	if ( ! status ) {
		return null;
	}

	if ( status.ups_source && 'ecoflow' !== status.ups_source && 'switchbot' !== status.ups_source ) {
		return null;
	}

	if ( Object.prototype.hasOwnProperty.call( status, 'ups_out' ) && status.ups_out !== null && status.ups_out !== undefined ) {
		return Number( status.ups_out ) || 0;
	}

	return null;
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
