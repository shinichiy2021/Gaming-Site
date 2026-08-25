import { useEffect, useRef, useState } from 'react';
import { batteryTone, formatPack, formatWatts, isFlowActive, isRegenActive } from './constants';
import { useFlowCanvas } from './useFlowCanvas';

function PackEta( { status } ) {
	if ( ! status || status.eta_mode === 'idle' || ! status.remain_time_label ) {
		return null;
	}

	return (
		<p className="ecoflow-node-eta">
			<span>{ status.remain_time_label }</span>
			<strong>{ status.remain_time_display || '—' }</strong>
		</p>
	);
}

function PhoneBattery( { percent, charging } ) {
	if ( ! Number.isFinite( percent ) ) {
		return null;
	}

	const level = Math.max( 0, Math.min( 100, percent ) );
	const tone = batteryTone( level );
	const classes = [
		'ecoflow-phone-batt',
		charging ? 'is-charging' : '',
		tone.className,
	].filter( Boolean ).join( ' ' );

	return (
		<span
			className={ classes }
			style={ { '--battery-level': level, '--batt-tone': tone.color } }
			title={ `${ level }%` }
		>
			<span className="ecoflow-phone-batt-icon" aria-hidden="true">
				<span className="ecoflow-phone-batt-shell">
					<span className="ecoflow-phone-batt-fill" />
				</span>
				<span className="ecoflow-phone-batt-nub" />
			</span>
			<span className="ecoflow-phone-batt-pct">{ `${ level }%` }</span>
		</span>
	);
}

function formatYen( value ) {
	return `¥${ Math.round( Number( value ) || 0 ).toLocaleString() }`;
}

function formatCabinWatts( value, idle, live ) {
	if ( ! live || value === null || value === undefined || value === '' ) {
		return idle;
	}

	const watts = Number( value );
	if ( ! Number.isFinite( watts ) ) {
		return idle;
	}

	return `${ Math.round( Math.max( 0, watts ) ).toLocaleString() } W`;
}

function formatInputWatts( value, idle, active ) {
	if ( ! active ) {
		return idle;
	}

	const watts = Number( value );
	if ( ! Number.isFinite( watts ) ) {
		return idle;
	}

	return `${ Math.round( Math.max( 0, watts ) ).toLocaleString() } W`;
}

function wallExtras( status, labels ) {
	if ( ! status.live ) {
		return [];
	}

	const charging = ! status.asleep && !! status.is_charging && status.supply_kind !== 'supercharger';
	const items = [];
	const yenH = Number( status.wall_yen_per_h );
	const todayYen = Number( status.wall_today_yen );
	const todayKwh = Number( status.wall_today_kwh );
	const sessionKwh = Number( status.wall_session_kwh );
	const sessionYen = Number( status.wall_session_yen );
	const spansDays = !! status.wall_span_days;
	const buy = labels.buy || '買電';
	const perHour = labels.yenPerHour || '円/時';
	const todayBuy = labels.todayBuy || '今日 買電';
	const session = labels.session || '今回';
	const total = labels.total || '合計';

	if ( charging && Number.isFinite( yenH ) && yenH > 0 ) {
		items.push( `${ buy } ${ Math.round( yenH ).toLocaleString() } ${ perHour }` );
	}

	if ( ( charging || spansDays ) && Number.isFinite( sessionKwh ) && sessionKwh > 0 ) {
		// A charge that ran past midnight is split across two daily counters, so label
		// it as the start-to-end total and name the dates it covers.
		const range = spansDays && status.wall_span_label ? ` (${ status.wall_span_label })` : '';
		items.push( `${ spansDays ? total : session } ${ sessionKwh.toLocaleString( undefined, { maximumFractionDigits: 2 } ) } kWh · ${ formatYen( sessionYen ) }${ range }` );
	}

	// Today's home charging total stays visible while standby so the node is never blank.
	items.push( `${ todayBuy } ${ ( Number.isFinite( todayKwh ) ? todayKwh : 0 ).toLocaleString( undefined, { maximumFractionDigits: 2 } ) } kWh · ${ formatYen( Number.isFinite( todayYen ) ? todayYen : 0 ) }` );

	return items;
}

function cabinExtras( status, labels ) {
	if ( ! status.live ) {
		return [];
	}

	const kwh = Number( status.cabin_today_kwh );
	const yen = Number( status.cabin_today_yen );

	return [
		`${ labels.todayUse || '今日 使用' } ${ ( Number.isFinite( kwh ) ? kwh : 0 ).toLocaleString( undefined, { maximumFractionDigits: 2 } ) } kWh`,
		`${ labels.todayBill || '今日 電気代' } ${ formatYen( yen ) }`,
	];
}

function formatGasValue( status, labels, idle ) {
	const gas = status.gas || {};
	if ( ( status.regen_w || 0 ) >= 80 ) {
		return null;
	}

	if ( ( gas.gas_l_per_h || 0 ) > 0 ) {
		return `${ Number( gas.gas_l_per_h ).toLocaleString( undefined, { maximumFractionDigits: 2 } ) } L/h`;
	}

	if ( ( gas.gas_l || 0 ) > 0 ) {
		return `${ Number( gas.gas_l ).toLocaleString( undefined, { maximumFractionDigits: 2 } ) } L`;
	}

	return idle;
}

function gasExtras( status, labels ) {
	const gas = status.gas || {};
	const items = [];
	const driving = ! status.asleep && ( gas.gas_l_per_h || 0 ) > 0;

	if ( driving && ( gas.saved_yen_per_h || 0 ) > 0 ) {
		items.push( `${ labels.saved || '節約' } ${ formatYen( gas.saved_yen_per_h ) }/h` );
	} else if ( ( gas.saved_yen || 0 ) > 0 ) {
		items.push( `${ labels.saved || '節約' } ${ formatYen( gas.saved_yen ) }` );
	}

	if ( driving && ( gas.saved_yen || 0 ) > 0 ) {
		items.push( `${ labels.gasToday || '本日' } ${ formatYen( gas.saved_yen ) }` );
	}

	if ( gas.price_label ) {
		items.push( gas.price_label );
	}

	if ( status.speed_km > 0 && driving ) {
		items.push( `${ status.speed_km } km/h` );
	}

	return items;
}

function PhotoNode( { flowId, label, note, watts, photo, photoClass, active, extra, overlay, standbyLabel, className, display } ) {
	const classes = [
		'ecoflow-node',
		'ecoflow-node-banner',
		`tesla-node-${ flowId }`,
		active ? 'is-active' : 'is-standby',
		className,
	].filter( Boolean ).join( ' ' );

	return (
		<div className={ classes } data-flow-id={ flowId }>
			{ overlay || ( photo ? (
				<img src={ photo } alt="" className={ `ecoflow-node-photo ${ photoClass || '' }` } />
			) : null ) }
			<span className="ecoflow-node-label">{ label }</span>
			{ note ? <small>{ note }</small> : null }
			<strong>{ display || formatWatts( watts, standbyLabel ) }</strong>
			{ extra }
		</div>
	);
}

function shiftMeta( status, labels ) {
	const gears = [ 'P', 'R', 'N', 'D' ];
	const current = status.asleep ? 'P' : String( status.shift || '' ).toUpperCase();
	const ready = gears.includes( current );
	const names = {
		P: labels.park || 'P',
		R: labels.reverse || 'R',
		N: labels.neutral || 'N',
		D: labels.driveGear || 'D',
	};

	return {
		current,
		ready,
		label: ready ? names[ current ] : ( labels.shiftUnknown || '—' ),
	};
}

function ShiftIcon( { status, labels } ) {
	const { current, ready, label } = shiftMeta( status, labels );

	return (
		<span
			className={ `tesla-shift${ ready ? ` is-${ current.toLowerCase() }` : ' is-unknown' }` }
			title={ ready ? `${ label } (${ current })` : label }
			aria-label={ ready ? `${ labels.shift || 'シフト' } ${ current }` : label }
		>
			{ ready ? current : '—' }
		</span>
	);
}

function teslaStateLabel( status, labels ) {
	if ( ! status.live ) {
		return labels.idle;
	}

	if ( status.asleep ) {
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

export default function TeslaFlowDiagram( { initial, labels } ) {
	const mapRef = useRef( null );
	const canvasRef = useRef( null );
	const [ status, setStatus ] = useState( initial || {} );

	useFlowCanvas( canvasRef, mapRef, status );

	useEffect( () => {
		const onUpdate = ( event ) => {
			if ( event.detail ) {
				setStatus( event.detail );
			}
		};

		document.addEventListener( 'gamingHubTeslaFlow', onUpdate );
		return () => document.removeEventListener( 'gamingHubTeslaFlow', onUpdate );
	}, [] );

	const assets = window.gamingHubTeslaFlow || {};
	const images = assets.images || {};
	const idle = labels.idle || '待機';
	const asleep = !! status.asleep;
	const soc = Number( status.battery_percent );
	const hasSoc = status.live && Number.isFinite( soc );
	const tone = batteryTone( hasSoc ? soc : NaN );
	const charging = ! asleep && !! status.live && !! status.is_charging;
	const wallCharging = charging && status.supply_kind !== 'supercharger';
	const superCharging = charging && status.supply_kind === 'supercharger';
	const regenOn = ! asleep && isRegenActive( status );
	const driveOn = ! asleep && isFlowActive( 'drive', status );
	const cabinOn = ! asleep && isFlowActive( 'cabin', status );
	const wallOn = ! asleep && ( wallCharging || isFlowActive( 'wall', status ) );
	const superOn = ! asleep && ( superCharging || isFlowActive( 'super', status ) );
	const teslaActive = ! asleep && ( charging || driveOn || cabinOn );
	const fullWh = Number( status.capacity_wh );
	const remainWh = Number.isFinite( Number( status.remain_capacity ) )
		? Number( status.remain_capacity )
		: ( hasSoc && Number.isFinite( fullWh ) ? fullWh * soc / 100 : null );
	const packLabel = status.live && Number.isFinite( fullWh ) && fullWh > 0
		? formatPack( remainWh, fullWh )
		: '';
	const teslaClasses = [
		'ecoflow-node',
		'ecoflow-node-battery',
		'ecoflow-node-device',
		'is-hero',
		charging || regenOn ? 'is-charging' : '',
		! charging && ! regenOn && driveOn ? 'is-discharging' : '',
		teslaActive ? 'is-active' : 'is-standby',
		tone.className,
	].filter( Boolean ).join( ' ' );

	return (
		<div className="tesla-flow-scene">
			<div
				ref={ mapRef }
				className="tesla-flow-map ecoflow-energy-map"
				aria-label={ labels.flow }
			>
				<canvas ref={ canvasRef } className="ecoflow-energy-canvas tesla-flow-canvas" aria-hidden="true" />

				<div className="ecoflow-system tesla-flow-system">
					<p className="ecoflow-system-title tesla-flow-title">{ labels.title }</p>

					<div className="ecoflow-input-stack tesla-flow-inputs">
						<PhotoNode
							flowId="wall"
							label={ labels.wall }
							note={ labels.wallNote }
							watts={ asleep ? 0 : status.wall_w }
							photo={ images.wall }
							photoClass="tesla-photo-wall"
							active={ wallOn }
							standbyLabel={ idle }
							display={ wallOn ? formatInputWatts( status.wall_w, idle, true ) : null }
							extra={ wallExtras( status, labels ).map( ( line ) => (
								<small key={ line } className="tesla-gas-saved">{ line }</small>
							) ) }
						/>
						<PhotoNode
							flowId="super"
							label={ labels.super }
							note={ labels.superNote }
							watts={ asleep ? 0 : status.super_w }
							photo={ images.super }
							photoClass="tesla-photo-super"
							active={ superOn }
							standbyLabel={ idle }
							display={ superOn && ! isFlowActive( 'super', status ) ? labels.charging : null }
						/>
					</div>

					<div
						className={ teslaClasses }
						data-flow-id="tesla"
						style={ hasSoc ? { '--battery-level': soc, '--batt-tone': tone.color } : undefined }
					>
						<div className="ecoflow-node-art" style={ hasSoc ? { '--battery-level': soc, '--batt-tone': tone.color } : undefined }>
							{ images.tesla ? (
								<img src={ images.tesla } alt="" className="ecoflow-node-photo ecoflow-node-photo-pro tesla-photo-car" />
							) : null }
							{ hasSoc ? <PhoneBattery percent={ soc } charging={ charging || regenOn } /> : null }
						</div>
						<span className="ecoflow-node-label">{ status.vehicle_name || labels.tesla }</span>
						{ packLabel ? <small className="ecoflow-node-pack">{ packLabel }</small> : null }
						<p className="ecoflow-node-state">{ teslaStateLabel( status, labels ) }</p>
						{ ! asleep && status.live ? <PackEta status={ status } /> : null }
						{ ! asleep && status.live && status.range_label ? <small>{ status.range_label }</small> : null }
					</div>

					<div className="tesla-flow-outputs">
						<PhotoNode
							flowId="drive"
							label={ regenOn ? ( labels.regen || labels.drive ) : labels.drive }
							note={ regenOn ? labels.regenNote : ( labels.gasCar || null ) }
							watts={ regenOn ? status.regen_w : status.drive_w }
							className={ regenOn ? 'is-regen' : '' }
							active={ ! asleep && ( driveOn || ( status.gas?.saved_yen || 0 ) > 0 ) }
							standbyLabel={ idle }
							overlay={ <ShiftIcon status={ status } labels={ labels } /> }
							display={ regenOn ? null : formatGasValue( status, labels, idle ) }
							extra={ gasExtras( status, labels ).map( ( line ) => (
								<small key={ line } className={ line.indexOf( labels.saved || '節約' ) === 0 || line.indexOf( labels.gasToday || '本日' ) === 0 ? 'tesla-gas-saved' : '' }>{ line }</small>
							) ) }
						/>
						<PhotoNode
							flowId="cabin"
							label={ labels.cabin }
							watts={ status.cabin_w }
							photo={ images.cabin }
							photoClass="tesla-photo-cabin"
							active={ cabinOn }
							standbyLabel={ idle }
							display={ formatCabinWatts( status.cabin_w, idle, ! asleep && !! status.live ) }
							extra={ cabinExtras( status, labels ).map( ( line ) => (
								<small key={ line } className={ line.indexOf( labels.todayBill || '今日 電気代' ) === 0 ? 'tesla-gas-saved' : '' }>{ line }</small>
							) ) }
						/>
					</div>
				</div>
			</div>
		</div>
	);
}
