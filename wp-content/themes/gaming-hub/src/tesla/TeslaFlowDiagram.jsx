import { useEffect, useRef, useState } from 'react';
import { batteryTone, formatWatts, isFlowActive } from './constants';
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

function PhotoNode( { flowId, label, note, watts, photo, photoClass, active, extra } ) {
	const classes = [
		'ecoflow-node',
		'ecoflow-node-banner',
		`tesla-node-${ flowId }`,
		active ? 'is-active' : 'is-standby',
	].filter( Boolean ).join( ' ' );

	return (
		<div className={ classes } data-flow-id={ flowId }>
			{ photo ? (
				<img src={ photo } alt="" className={ `ecoflow-node-photo ${ photoClass || '' }` } />
			) : null }
			<span className="ecoflow-node-label">{ label }</span>
			{ note ? <small>{ note }</small> : null }
			<strong>{ formatWatts( watts ) }</strong>
			{ extra }
		</div>
	);
}

function teslaStateLabel( status, labels ) {
	if ( status.mode === 'drive' || ( status.drive_w || 0 ) >= 80 ) {
		const speed = Number( status.speed_km ) || 0;
		return speed > 0
			? `${ labels.driving } · ${ speed } km/h`
			: labels.driving;
	}

	if ( status.is_charging ) {
		return status.supply_label || labels.charging;
	}

	if ( status.climate_on ) {
		return labels.climate;
	}

	if ( status.sentry ) {
		return labels.sentry;
	}

	return status.charge_state || labels.idle;
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
	const soc = Number( status.battery_percent );
	const hasSoc = Number.isFinite( soc );
	const tone = batteryTone( soc );
	const charging = !! status.is_charging;
	const teslaClasses = [
		'ecoflow-node',
		'ecoflow-node-battery',
		'ecoflow-node-device',
		'is-hero',
		charging ? 'is-charging' : '',
		! charging && ( status.drive_w || 0 ) >= 80 ? 'is-discharging' : '',
		! charging && ( status.drive_w || 0 ) < 80 && ( status.cabin_w || 0 ) < 80 ? 'is-standby' : '',
		charging || ( status.drive_w || 0 ) >= 80 || ( status.cabin_w || 0 ) >= 80 ? 'is-active' : '',
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
						/>
						<PhotoNode
							flowId="super"
							label={ labels.super }
							note={ labels.superNote }
							watts={ status.super_w }
							photo={ images.super }
							photoClass="tesla-photo-super"
							active={ isFlowActive( 'super', status ) }
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
							<PhoneBattery percent={ soc } charging={ charging } />
						</div>
						<span className="ecoflow-node-label">{ status.vehicle_name || labels.tesla }</span>
						<p className="ecoflow-node-state">{ teslaStateLabel( status, labels ) }</p>
						{ status.range_label ? <small>{ status.range_label }</small> : null }
					</div>

					<div className="tesla-flow-outputs">
						<PhotoNode
							flowId="drive"
							label={ labels.drive }
							watts={ status.drive_w }
							photo={ images.drive }
							photoClass="tesla-photo-drive"
							active={ isFlowActive( 'drive', status ) }
							extra={ status.speed_km > 0 ? <small>{ `${ status.speed_km } km/h` }</small> : null }
						/>
						<PhotoNode
							flowId="cabin"
							label={ labels.cabin }
							watts={ status.cabin_w }
							photo={ images.cabin }
							photoClass="tesla-photo-cabin"
							active={ isFlowActive( 'cabin', status ) }
							extra={ status.climate_on ? <small>{ labels.climate }</small> : ( status.sentry ? <small>{ labels.sentry }</small> : null ) }
						/>
					</div>
				</div>
			</div>
		</div>
	);
}
