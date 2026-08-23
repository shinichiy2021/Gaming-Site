import { useEffect, useRef, useState } from 'react';
import { batteryTone, formatWatts, isFlowActive, isRegenActive } from './constants';
import { useFlowCanvas } from './useFlowCanvas';

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

function PhotoNode( { flowId, label, note, watts, photo, photoClass, active, extra, standbyLabel, className } ) {
	const classes = [
		'ecoflow-node',
		'ecoflow-node-banner',
		`tesla-node-${ flowId }`,
		active ? 'is-active' : 'is-standby',
		className,
	].filter( Boolean ).join( ' ' );

	return (
		<div className={ classes } data-flow-id={ flowId }>
			{ photo ? (
				<img src={ photo } alt="" className={ `ecoflow-node-photo ${ photoClass || '' }` } />
			) : null }
			<span className="ecoflow-node-label">{ label }</span>
			{ note ? <small>{ note }</small> : null }
			<strong>{ formatWatts( watts, standbyLabel ) }</strong>
			{ extra }
		</div>
	);
}

function shiftMeta( status, labels ) {
	const gears = [ 'P', 'R', 'N', 'D' ];
	const current = String( status.shift || '' ).toUpperCase();
	const ready = gears.includes( current );
	const names = {
		P: labels.park || 'P',
		R: labels.reverse || 'R',
		N: labels.neutral || 'N',
		D: labels.driveGear || 'D',
	};

	return {
		gears,
		current,
		ready,
		label: ready ? names[ current ] : ( labels.shiftUnknown || '—' ),
	};
}

function ShiftIcon( { status, labels } ) {
	const { gears, current, ready, label } = shiftMeta( status, labels );

	return (
		<span
			className={ `tesla-shift${ ready ? ` is-${ current.toLowerCase() }` : ' is-unknown' }` }
			title={ ready ? `${ label } (${ current })` : label }
			aria-label={ ready ? `${ labels.shift || 'シフト' } ${ current }` : label }
		>
			<span className="tesla-shift-badge">{ ready ? current : '—' }</span>
			<span className="tesla-shift-prnd" aria-hidden="true">
				{ gears.map( ( gear ) => (
					<span
						key={ gear }
						className={ ready && gear === current ? 'is-current' : '' }
					>
						{ gear }
					</span>
				) ) }
			</span>
		</span>
	);
}

function teslaStateLabel( status, labels ) {
	if ( ! status.live ) {
		return labels.idle;
	}

	if ( status.asleep ) {
		return labels.asleep || labels.idle;
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
		return status.supply_label || labels.charging;
	}

	if ( ( status.cabin_w || 0 ) >= 80 && status.climate_on ) {
		return status.drive_ready
			? labels.climate
			: `${ labels.climate } · ${ labels.drivePending || '' }`.replace( /\s·\s$/, '' );
	}

	if ( ( status.cabin_w || 0 ) >= 80 && status.sentry ) {
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
	const soc = Number( status.battery_percent );
	const hasSoc = status.live && Number.isFinite( soc );
	const tone = batteryTone( hasSoc ? soc : NaN );
	const charging = !! status.live && !! status.is_charging;
	const regenOn = isRegenActive( status );
	const driveOn = isFlowActive( 'drive', status );
	const cabinOn = isFlowActive( 'cabin', status );
	const teslaActive = charging || driveOn || cabinOn;
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
							watts={ status.wall_w }
							photo={ images.wall }
							photoClass="tesla-photo-wall"
							active={ isFlowActive( 'wall', status ) }
							standbyLabel={ idle }
						/>
						<PhotoNode
							flowId="super"
							label={ labels.super }
							note={ labels.superNote }
							watts={ status.super_w }
							photo={ images.super }
							photoClass="tesla-photo-super"
							active={ isFlowActive( 'super', status ) }
							standbyLabel={ idle }
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
							<ShiftIcon status={ status } labels={ labels } />
						</div>
						<span className="ecoflow-node-label">{ status.vehicle_name || labels.tesla }</span>
						<p className="ecoflow-node-state">{ teslaStateLabel( status, labels ) }</p>
						{ status.live && status.range_label ? <small>{ status.range_label }</small> : null }
					</div>

					<div className="tesla-flow-outputs">
						<PhotoNode
							flowId="drive"
							label={ regenOn ? ( labels.regen || labels.drive ) : labels.drive }
							note={ regenOn ? labels.regenNote : null }
							watts={ regenOn ? status.regen_w : status.drive_w }
							photo={ images.drive }
							photoClass="tesla-photo-drive"
							className={ regenOn ? 'is-regen' : '' }
							active={ driveOn }
							standbyLabel={ idle }
							extra={ driveOn && status.speed_km > 0 ? <small>{ `${ status.speed_km } km/h` }</small> : null }
						/>
						<PhotoNode
							flowId="cabin"
							label={ labels.cabin }
							watts={ status.cabin_w }
							photo={ images.cabin }
							photoClass="tesla-photo-cabin"
							active={ cabinOn }
							standbyLabel={ idle }
							extra={ cabinOn && status.climate_on ? <small>{ labels.climate }</small> : ( cabinOn && status.sentry ? <small>{ labels.sentry }</small> : null ) }
						/>
					</div>
				</div>
			</div>
		</div>
	);
}
