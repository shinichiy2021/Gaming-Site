import { useEffect, useRef, useState } from 'react';
import { batteryTone, formatPack, isFlowActive, isRegenActive, isSuperchargerConnected, FLOW_THRESHOLD } from './constants';

function formatYen( value ) {
	if ( typeof window !== 'undefined' && typeof window.gamingHubYen === 'function' ) {
		return window.gamingHubYen( value );
	}

	return `¥${ Math.round( Number( value ) || 0 ).toLocaleString() }`;
}

function formatYenPerHour( value, yenPerHourLabel ) {
	const n = Math.round( Number( value ) || 0 ).toLocaleString();
	const isEn = typeof window !== 'undefined' && typeof window.gamingHubLang === 'function'
		&& window.gamingHubLang() === 'en';

	if ( isEn ) {
		return `¥${ n }/h`;
	}

	return `${ n } ${ yenPerHourLabel || '円/時' }`;
}

function wallAcContext( status, asleep, charging ) {
	if ( asleep || status.supply_kind === 'supercharger' ) {
		return { plugged: false, atHome: false, away: false };
	}

	const plugged = !! status.plugged || charging || !! status.input_plugged;
	const inputType = String( status.input_type || 'none' );
	// Prefer live at_home when known — input_type alone used to stick on home_ac after a recent home plug.
	const atHome = plugged && status.at_home !== false && (
		inputType === 'home_ac'
		|| ( inputType === 'none' && status.at_home === true )
	);
	const away = plugged && (
		status.at_home === false
		|| inputType === 'away_ac'
		|| ( inputType === 'none' && status.at_home === false )
	);

	return { plugged, atHome, away: away && ! atHome };
}

function asWatts( value ) {
	const watts = Number( value );
	return Number.isFinite( watts ) ? Math.max( 0, watts ) : 0;
}

function asKwh( value ) {
	const kwh = Number( value );
	return Number.isFinite( kwh ) ? Math.max( 0, kwh ) : 0;
}

function pctOf( part, whole ) {
	if ( ! Number.isFinite( part ) || ! Number.isFinite( whole ) || whole <= 0 ) {
		return 0;
	}

	return Math.max( 0, Math.min( 100, ( part / whole ) * 100 ) );
}

function formatKw( watts ) {
	const w = asWatts( watts );
	if ( w < FLOW_THRESHOLD ) {
		return '0 kW';
	}

	return `${ ( w / 1000 ).toLocaleString( undefined, { maximumFractionDigits: 1 } ) } kW`;
}

function formatKwh( value ) {
	const kwh = asKwh( value );
	return `${ kwh.toLocaleString( undefined, { maximumFractionDigits: 2 } ) } kWh`;
}

function yenPerHour( watts, yenPerKwh ) {
	const w = asWatts( watts );
	const rate = Number( yenPerKwh );
	if ( w < FLOW_THRESHOLD || ! Number.isFinite( rate ) || rate <= 0 ) {
		return 0;
	}

	return Math.round( ( w / 1000 ) * rate );
}

function formatNowMetric( watts, yenPerKwh, yenPerHOverride, yenPerHourLabel ) {
	const power = formatKw( watts );
	const yenH = Number.isFinite( yenPerHOverride ) && yenPerHOverride > 0
		? Math.round( yenPerHOverride )
		: yenPerHour( watts, yenPerKwh );
	if ( yenH > 0 ) {
		return `${ power } / ${ formatYenPerHour( yenH, yenPerHourLabel ) }`;
	}

	return `${ power } / —`;
}

function formatTodayMetric( kwh, yen ) {
	const cost = Number.isFinite( Number( yen ) ) ? formatYen( yen ) : '—';
	return `${ formatKwh( kwh ) } / ${ cost }`;
}

function BattIcon( { charging } ) {
	return (
		<span className={ `teslogic-batt-icon${ charging ? ' is-charging' : '' }` } aria-hidden="true">
			<span className="teslogic-batt-icon__body">
				<span className="teslogic-batt-icon__fill" />
				{ charging ? <span className="teslogic-batt-icon__bolt">⚡</span> : null }
			</span>
			<span className="teslogic-batt-icon__nub" />
		</span>
	);
}

function MetricPair( { currentLabel, currentValue, totalLabel, totalValue, currentPct, totalPct, showBars } ) {
	return (
		<div className="teslogic-metrics">
			<div className="teslogic-metric">
				<span className="teslogic-metric__label">{ currentLabel }</span>
				<strong className="teslogic-metric__value">{ currentValue }</strong>
				{ showBars ? (
					<span className="teslogic-metric__bar" style={ { '--bar': `${ Math.min( 100, currentPct || 0 ) }%` } } />
				) : null }
			</div>
			<div className="teslogic-metric">
				<span className="teslogic-metric__label">{ totalLabel }</span>
				<strong className="teslogic-metric__value">{ totalValue }</strong>
				{ showBars ? (
					<span className="teslogic-metric__bar" style={ { '--bar': `${ Math.min( 100, totalPct || 0 ) }%` } } />
				) : null }
			</div>
		</div>
	);
}

function FlowCard( {
	flowId,
	label,
	icon,
	active,
	className,
	currentLabel,
	currentValue,
	totalLabel,
	totalValue,
	currentPct,
	totalPct,
	showBars,
	showMetrics = true,
	note,
	extra,
} ) {
	const classes = [
		'teslogic-card',
		active ? 'is-active' : 'is-standby',
		className,
	].filter( Boolean ).join( ' ' );

	return (
		<div className={ classes } data-flow-id={ flowId }>
			<div className="teslogic-card__head">
				{ icon ? <span className="teslogic-card__icon" aria-hidden="true">{ icon }</span> : null }
				<span className="teslogic-card__label">{ label }</span>
			</div>
			{ note ? <small className="teslogic-card__note">{ note }</small> : null }
			{ showMetrics ? (
				<MetricPair
					currentLabel={ currentLabel }
					currentValue={ currentValue }
					totalLabel={ totalLabel }
					totalValue={ totalValue }
					currentPct={ currentPct }
					totalPct={ totalPct }
					showBars={ showBars }
				/>
			) : null }
			{ extra }
		</div>
	);
}

function BatteryCard( {
	soc,
	hasSoc,
	tempC,
	charging,
	regenOn,
	livePower,
	currentValue,
	totalValue,
	currentLabel,
	totalLabel,
	vehicleName,
	stateLabel,
	packLabel,
	tone,
	asleep,
} ) {
	const classes = [
		'teslogic-card',
		'teslogic-card--battery',
		charging || regenOn ? 'is-charging' : '',
		asleep ? 'is-asleep' : '',
		( ! asleep && ( charging || regenOn || livePower ) ) ? 'is-active' : 'is-standby',
		tone.className,
	].filter( Boolean ).join( ' ' );

	return (
		<div
			className={ classes }
			data-flow-id="tesla"
			style={ hasSoc ? { '--battery-level': soc, '--batt-tone': tone.color } : undefined }
		>
			<div className="teslogic-battery__top teslogic-battery__top--stack">
				<BattIcon charging={ charging || regenOn } />
				<strong className="teslogic-battery__soc">
					{ hasSoc ? `${ Math.round( soc ) }%` : '—' }
				</strong>
				{ packLabel ? <small className="teslogic-battery__pack">{ packLabel }</small> : null }
				{ Number.isFinite( tempC ) ? (
					<span className="teslogic-battery__temp">{ `${ Math.round( tempC ) } °C` }</span>
				) : null }
			</div>
			<MetricPair
				currentLabel={ currentLabel }
				currentValue={ currentValue }
				totalLabel={ totalLabel }
				totalValue={ totalValue }
			/>
			<span className="teslogic-battery__name">{ vehicleName }</span>
			<small className="teslogic-battery__state">{ stateLabel }</small>
		</div>
	);
}

function wallExtras( status, labels ) {
	if ( ! status.live ) {
		return [];
	}

	const charging = ! status.asleep && !! status.is_charging && status.supply_kind !== 'supercharger';
	const items = [];
	const sessionKwh = Number( status.wall_session_kwh );
	const sessionYen = Number( status.wall_session_yen );
	const spansDays = !! status.wall_span_days;
	const session = labels.session || '今回';
	const total = labels.total || '合計';

	if ( ( charging || spansDays ) && Number.isFinite( sessionKwh ) && sessionKwh > 0 ) {
		const range = spansDays && status.wall_span_label ? ` (${ status.wall_span_label })` : '';
		items.push( `${ spansDays ? total : session } ${ sessionKwh.toLocaleString( undefined, { maximumFractionDigits: 2 } ) } kWh · ${ formatYen( sessionYen ) }${ range }` );
	}

	return items;
}

function superExtras( status, labels ) {
	if ( ! status.live ) {
		return [];
	}

	const charging = ! status.asleep && !! status.is_charging && status.supply_kind === 'supercharger';
	const items = [];
	const session = labels.session || '今回';
	const total = labels.total || '合計';
	const sessionKwh = Number( status.super_session_kwh );
	const spansDays = !! status.super_span_days;

	if ( ( charging || spansDays ) && Number.isFinite( sessionKwh ) && sessionKwh > 0 ) {
		const range = spansDays && status.super_span_label ? ` (${ status.super_span_label })` : '';
		items.push( `${ spansDays ? total : session } ${ sessionKwh.toLocaleString( undefined, { maximumFractionDigits: 2 } ) } kWh${ range }` );
	}

	return items;
}

function gasExtras( status, labels ) {
	if ( ! status.live ) {
		return [];
	}

	const gas = status.gas || {};
	return [
		`${ labels.saved || '節約' } ${ formatYen( gas.saved_yen ) }`,
	];
}

function ExtraLines( { lines, highlight } ) {
	if ( ! lines?.length ) {
		return null;
	}

	return (
		<div className="teslogic-card__extras">
			{ lines.map( ( line ) => (
				<small
					key={ line }
					className={ highlight && line.indexOf( highlight ) === 0 ? 'is-accent' : '' }
				>
					{ line }
				</small>
			) ) }
		</div>
	);
}

function teslaStateLabel( status, labels ) {
	if ( status.asleep ) {
		return labels.asleep || labels.idle || 'スリープ中';
	}

	if ( ! status.live ) {
		return labels.idle;
	}

	if ( status.mode === 'regen' || ( status.regen_w || 0 ) >= 80 ) {
		const speed = Number( status.speed_km ) || 0;
		return speed > 0
			? `${ labels.regen } · ${ speed } km/h`
			: ( labels.regen || labels.charging );
	}

	if ( status.mode === 'drive' || ( status.drive_w || 0 ) >= 80 ) {
		const speed = Number( status.speed_km ) || 0;
		return speed > 0
			? `${ labels.driving } · ${ speed } km/h`
			: labels.driving;
	}

	if ( status.is_charging ) {
		return labels.charging;
	}

	if ( status.sentry ) {
		return labels.sentry;
	}

	if ( status.live && status.drive_ready === false ) {
		return labels.drivePending || labels.idle;
	}

	return labels.idle;
}

const ICONS = {
	motor: '⚙',
	climate: '🌤',
	wall: '🔌',
	home: '🏠',
	homeCharging: '⚡',
	away: '🔌',
	awayCharging: '⚡',
	super: '⚡',
};

export default function TeslaFlowDiagram( { initial, labels } ) {
	const mapRef = useRef( null );
	const [ status, setStatus ] = useState( initial || {} );

	useEffect( () => {
		const onUpdate = ( event ) => {
			if ( event.detail ) {
				setStatus( event.detail );
			}
		};

		document.addEventListener( 'gamingHubTeslaFlow', onUpdate );
		return () => document.removeEventListener( 'gamingHubTeslaFlow', onUpdate );
	}, [] );

	const asleep = !! status.asleep;
	const soc = Number( status.battery_percent );
	const hasSoc = status.live && Number.isFinite( soc );
	const fullWh = Number( status.capacity_wh );
	const remainWh = Number.isFinite( Number( status.remain_capacity ) )
		? Number( status.remain_capacity )
		: ( hasSoc && Number.isFinite( fullWh ) && fullWh > 0 ? fullWh * soc / 100 : null );
	const packLabel = hasSoc && Number.isFinite( fullWh ) && fullWh > 0
		? formatPack( remainWh, fullWh )
		: '';
	const tone = batteryTone( hasSoc ? soc : NaN );
	const charging = ! asleep && !! status.live && !! status.is_charging;
	const superConnected = ! asleep && isSuperchargerConnected( status );
	const wallCharging = charging && status.supply_kind !== 'supercharger';
	const superCharging = ! asleep && !! status.super_charging;
	const regenOn = ! asleep && isRegenActive( status );
	const driveOn = ! asleep && isFlowActive( 'drive', status );
	const cabinOn = ! asleep && isFlowActive( 'cabin', status );
	const wallOn = ! asleep && ( wallCharging || isFlowActive( 'wall', status ) );
	const superOn = superConnected || superCharging;
	const wallCtx = wallAcContext( status, asleep, charging );
	const wallLabel = wallCtx.atHome
		? ( labels.homeAc || '自宅 AC' )
		: ( wallCtx.away ? ( labels.awayAc || '外出先 AC' ) : ( labels.wall || 'AC充電' ) );
	const wallIcon = wallCtx.atHome
		? ( wallOn ? ICONS.homeCharging : ICONS.home )
		: ( wallCtx.away
			? ( wallOn ? ICONS.awayCharging : ICONS.away )
			: ( wallOn ? ICONS.homeCharging : ICONS.wall ) );

	const driveW = asleep ? 0 : ( regenOn ? asWatts( status.regen_w ) : asWatts( status.drive_w ) );
	const cabinW = asleep ? 0 : asWatts( status.cabin_w );
	const wallW = asleep ? 0 : asWatts( status.wall_w );
	const superW = asleep ? 0 : asWatts( status.super_w );
	const chargeW = wallCharging ? wallW : ( superCharging ? superW : 0 );
	const outW = driveW + cabinW;
	const packCurrentW = charging || regenOn
		? Math.max( chargeW, regenOn ? driveW : 0 )
		: outW;

	const driveTodayKwh = asKwh( status.gas?.today_kwh );
	const cabinTodayKwh = asKwh( status.cabin_today_kwh );
	const wallTodayKwh = asKwh( status.wall_today_kwh );
	const superTodayKwh = asKwh( status.super_today_kwh );
	const outTodayKwh = driveTodayKwh + cabinTodayKwh;
	const chargeTodayKwh = wallTodayKwh + superTodayKwh;
	const packTotalKwh = charging ? chargeTodayKwh : outTodayKwh;
	const yenKwh = Number( status.yen_per_kwh ) > 0 ? Number( status.yen_per_kwh ) : 30;
	const driveTodayYen = Number( status.gas?.ev_yen ) || 0;
	const cabinTodayYen = Number( status.cabin_today_yen ) || 0;
	const wallTodayYen = Number( status.wall_today_yen ) || 0;
	const superTodayYen = Number( status.super_today_yen ) || 0;
	const packTodayYen = charging ? ( wallTodayYen + superTodayYen ) : ( driveTodayYen + cabinTodayYen );
	const powerCostLabel = labels.powerCost || '消費 / 電気代';
	const todayPowerCostLabel = labels.todayPowerCost || '今日 / 電気代';
	const yenPerHourLabel = labels.yenPerHour || '円/時';

	const driveShareNow = pctOf( driveW, Math.max( outW, packCurrentW, 1 ) );
	const cabinShareNow = pctOf( cabinW, Math.max( outW, 1 ) );
	const wallShareNow = pctOf( wallW, Math.max( chargeW || wallW, 1 ) );
	const superShareNow = pctOf( superW, Math.max( chargeW || superW, 1 ) );
	const driveShareToday = pctOf( driveTodayKwh, Math.max( outTodayKwh, 1 ) );
	const cabinShareToday = pctOf( cabinTodayKwh, Math.max( outTodayKwh, 1 ) );
	const wallShareToday = pctOf( wallTodayKwh, Math.max( chargeTodayKwh, 1 ) );
	const superShareToday = pctOf( superTodayKwh, Math.max( chargeTodayKwh, 1 ) );

	const speed = asleep ? 0 : ( Number( status.speed_km ) || 0 );

	return (
		<div className={ `tesla-flow-scene${ asleep ? ' is-asleep' : '' }` }>
			<div
				ref={ mapRef }
				className={ `tesla-flow-map ecoflow-energy-map teslogic-map is-flow-hidden${ asleep ? ' is-asleep' : '' }` }
				aria-label={ labels.flow }
			>
				{ /* Energy flow canvas temporarily disabled */ }

				<div className="teslogic-system">
					<p className="teslogic-title">
						{ labels.title }
						{ asleep ? (
							<span className="teslogic-sleep-badge">{ labels.asleep || 'スリープ中' }</span>
						) : null }
					</p>

					<div className="teslogic-top">
						<BatteryCard
							soc={ soc }
							hasSoc={ hasSoc }
							tempC={ Number.isFinite( Number( status.cabin_temp_c ) ) ? Number( status.cabin_temp_c ) : NaN }
							charging={ charging }
							regenOn={ regenOn }
							livePower={ packCurrentW >= FLOW_THRESHOLD }
							currentLabel={ powerCostLabel }
							currentValue={ formatNowMetric( packCurrentW, yenKwh, null, yenPerHourLabel ) }
							totalLabel={ todayPowerCostLabel }
							totalValue={ formatTodayMetric( packTotalKwh, packTodayYen ) }
							vehicleName={ status.vehicle_name || labels.tesla }
							stateLabel={ teslaStateLabel( status, labels ) }
							packLabel={ packLabel }
							tone={ tone }
							asleep={ asleep }
						/>
					</div>

					<div className="teslogic-bottom">
						<FlowCard
							flowId="wall"
							className={ `teslogic-card--aux${ wallCtx.atHome ? ' is-home-ac' : '' }${ wallCtx.away ? ' is-away-ac' : '' }${ wallOn && wallCtx.atHome ? ' is-inputting' : '' }` }
							label={ wallLabel }
							icon={ wallIcon }
							active={ wallOn }
							currentLabel={ powerCostLabel }
							currentValue={ formatNowMetric( wallOn ? wallW : 0, yenKwh, Number( status.wall_yen_per_h ), yenPerHourLabel ) }
							totalLabel={ todayPowerCostLabel }
							totalValue={ formatTodayMetric( wallTodayKwh, wallTodayYen ) }
							currentPct={ wallOn ? wallShareNow : 0 }
							totalPct={ wallShareToday }
							showBars
							note={ labels.wallNote || null }
							extra={ <ExtraLines lines={ wallExtras( status, labels ) } /> }
						/>

						<FlowCard
							flowId="super"
							className="teslogic-card--aux"
							label={ labels.super || 'Supercharger' }
							icon={ ICONS.super }
							active={ superOn }
							currentLabel={ powerCostLabel }
							currentValue={ formatNowMetric( superCharging ? superW : 0, yenKwh, null, yenPerHourLabel ) }
							totalLabel={ todayPowerCostLabel }
							totalValue={ formatTodayMetric( superTodayKwh, superTodayYen ) }
							currentPct={ superCharging ? superShareNow : 0 }
							totalPct={ superShareToday }
							showBars
							note={
								superOn && ! superCharging && superW < FLOW_THRESHOLD
									? ( labels.connected || '接続中' )
									: null
							}
							extra={ <ExtraLines lines={ superExtras( status, labels ) } /> }
						/>

						<FlowCard
							flowId="cabin"
							className="teslogic-card--aux"
							label={ labels.climate || labels.cabin || 'エアコン' }
							icon={ ICONS.climate }
							active={ cabinOn }
							currentLabel={ powerCostLabel }
							currentValue={ formatNowMetric( cabinW, yenKwh, null, yenPerHourLabel ) }
							totalLabel={ todayPowerCostLabel }
							totalValue={ formatTodayMetric( cabinTodayKwh, cabinTodayYen ) }
							currentPct={ cabinShareNow }
							totalPct={ cabinShareToday }
							showBars
						/>

						<FlowCard
							flowId="drive"
							className={ `teslogic-card--aux teslogic-card--motor${ regenOn ? ' is-regen' : '' }` }
							label={ regenOn ? ( labels.regen || '回生' ) : ( labels.rearMotor || labels.drive || 'モーター' ) }
							icon={ ICONS.motor }
							active={ ! asleep && ( driveOn || regenOn ) }
							currentLabel={ powerCostLabel }
							currentValue={ formatNowMetric( driveW, yenKwh, null, yenPerHourLabel ) }
							totalLabel={ todayPowerCostLabel }
							totalValue={ formatTodayMetric( driveTodayKwh, driveTodayYen ) }
							currentPct={ driveShareNow }
							totalPct={ driveShareToday }
							showBars
							note={ speed > 0 ? `${ speed } km/h` : null }
							extra={ <ExtraLines lines={ gasExtras( status, labels ) } highlight={ labels.saved || '節約' } /> }
						/>
					</div>
				</div>
			</div>
		</div>
	);
}
