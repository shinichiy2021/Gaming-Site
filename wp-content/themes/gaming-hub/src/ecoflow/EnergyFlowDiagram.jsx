import { useEffect, useRef, useState } from 'react';
import { FLOW_THRESHOLD, formatWatts, linkWatts } from './constants';
import { useFlowCanvas } from './useFlowCanvas';

function isFlowActive( flowId, status ) {
	if ( ! status ) {
		return false;
	}

	if ( flowId === 'solar' ) {
		return ( Number( status.solar_in ) || 0 ) >= FLOW_THRESHOLD;
	}

	if ( flowId === 'grid' ) {
		return ( Number( status.grid_in ?? status.ac_in ) || 0 ) >= FLOW_THRESHOLD;
	}

	if ( flowId === 'proToLink' || flowId === 'linkToDelta' || flowId === 'proToDelta' ) {
		const watts = Number( status.link_watts ) || linkWatts( status.pro );
		return watts >= FLOW_THRESHOLD;
	}

	if ( flowId === 'proToHome' || flowId === 'home' ) {
		const watts = Number( status.home_out ) || Number( status.pro?.ac_out ) || 0;
		return watts >= FLOW_THRESHOLD;
	}

	return false;
}

function DeviceNode( { device, label, flowId, photo, compact } ) {
	if ( ! device ) {
		return null;
	}

	const batteryPercent = Number( device.battery_percent );
	const hasBattery = Number.isFinite( batteryPercent );
	const isCharging = !! device.is_charging;

	return (
		<div
			className={ `ecoflow-node ecoflow-node-battery ecoflow-node-device${ isCharging ? ' is-charging' : '' }` }
			data-flow-id={ flowId }
		>
			<div className="ecoflow-node-art">
				{ photo ? (
					<img src={ photo } alt="" className="ecoflow-node-photo" />
				) : null }
				{ hasBattery ? (
					<span className="ecoflow-hud-pct">{ `${ batteryPercent }%` }</span>
				) : null }
			</div>
			<span className="ecoflow-node-label">{ label }</span>
			<p className="ecoflow-node-state">{ device.charge_state || '—' }</p>
			{ ! compact && hasBattery && ! photo ? (
				<div
					className={ `ecoflow-battery-ring ecoflow-battery-ring-map${ isCharging ? ' is-charging' : ' is-discharging' }` }
					style={ { '--battery-level': batteryPercent } }
				>
					<div className="ecoflow-battery-inner">
						<span className="ecoflow-battery-value">{ `${ batteryPercent }%` }</span>
						<span className="ecoflow-battery-label">{ label }</span>
					</div>
				</div>
			) : null }
		</div>
	);
}

function DualFlowDiagram( { status, labels, images } ) {
	const pro = status.pro || {};
	const delta = status.delta || {};
	const link = Number( status.link_watts ) || linkWatts( pro );
	const dcLabel = labels.dcLink || labels.acLink;
	const roomWatts = Number( status.home_out ) || Number( pro.ac_out ) || 0;

	return (
		<div className="ecoflow-dual-layout">
			<div
				className={ `ecoflow-node ecoflow-node-solar${ isFlowActive( 'solar', status ) ? ' is-active' : '' }` }
				data-flow-id="solar"
			>
				{ images.solar ? (
					<img src={ images.solar } alt="" className="ecoflow-node-photo ecoflow-node-photo-solar" />
				) : (
					<span className="ecoflow-node-icon" aria-hidden="true">☀️</span>
				) }
				<span className="ecoflow-node-label">{ labels.solar }</span>
				<strong>{ formatWatts( status.solar_in ) }</strong>
			</div>

			<div
				className={ `ecoflow-node ecoflow-node-grid ecoflow-node-grid-slot${ isFlowActive( 'grid', status ) ? ' is-active' : '' }` }
				data-flow-id="grid"
			>
				<span className="ecoflow-node-icon" aria-hidden="true">⚡</span>
				<span className="ecoflow-node-label">{ labels.grid }</span>
				<strong>{ formatWatts( status.grid_in ?? status.ac_in ) }</strong>
			</div>

			<DeviceNode device={ pro } label={ labels.pro } flowId="pro" photo={ images.pro } />

			<div
				className={ `ecoflow-node ecoflow-node-link${ isFlowActive( 'proToLink', status ) ? ' is-active' : '' }` }
				data-flow-id="link"
			>
				{ images.dc12v ? (
					<img src={ images.dc12v } alt="" className="ecoflow-node-photo ecoflow-node-photo-link" />
				) : (
					<span className="ecoflow-node-icon" aria-hidden="true">🔌</span>
				) }
				<span className="ecoflow-node-label">{ dcLabel }</span>
				<strong>{ formatWatts( link ) }</strong>
			</div>

			<DeviceNode device={ delta } label={ labels.delta } flowId="delta" photo={ images.delta } compact />

			<div
				className={ `ecoflow-node ecoflow-node-home ecoflow-node-room${ isFlowActive( 'proToHome', status ) ? ' is-active' : '' }` }
				data-flow-id="home"
			>
				{ images.room ? (
					<img src={ images.room } alt="" className="ecoflow-node-photo ecoflow-node-photo-room" />
				) : (
					<span className="ecoflow-node-icon" aria-hidden="true">🏠</span>
				) }
				<span className="ecoflow-node-label">{ labels.home }</span>
				<strong>{ formatWatts( roomWatts ) }</strong>
				<small>{ labels.acOut || 'AC 出力' }</small>
			</div>

			<div className="ecoflow-flow-summary ecoflow-flow-summary-dual">
				<div className="ecoflow-flow-summary-item">
					<span>{ labels.pro }</span>
					<strong>{ pro.charge_state || '—' }</strong>
					<small>{ formatWatts( pro.ac_out || roomWatts ) } → { labels.home }</small>
				</div>
				<div className="ecoflow-flow-summary-item">
					<span>{ dcLabel }</span>
					<strong>{ formatWatts( link ) }</strong>
					<small>{ labels.pro } → { labels.delta }</small>
				</div>
				<div className="ecoflow-flow-summary-item">
					<span>{ labels.home }</span>
					<strong>{ formatWatts( roomWatts ) }</strong>
					<small>{ labels.acOut || 'AC 出力' }</small>
				</div>
			</div>
		</div>
	);
}

function SingleFlowDiagram( { status, labels } ) {
	const batteryPercent = Number( status.battery_percent ) || 0;
	const isCharging = !! status.is_charging;

	return (
		<>
			<div className="ecoflow-energy-nodes">
				<div
					className={ `ecoflow-node ecoflow-node-solar${ isFlowActive( 'solar', status ) ? ' is-active' : '' }` }
					data-flow-id="solar"
				>
					<span className="ecoflow-node-icon" aria-hidden="true">☀️</span>
					<span className="ecoflow-node-label">{ labels.solar }</span>
					<strong>{ formatWatts( status.solar_in ) }</strong>
				</div>

				<div
					className={ `ecoflow-node ecoflow-node-grid${ isFlowActive( 'grid', status ) ? ' is-active' : '' }` }
					data-flow-id="grid"
				>
					<span className="ecoflow-node-icon" aria-hidden="true">🔌</span>
					<span className="ecoflow-node-label">{ labels.grid }</span>
					<strong>{ formatWatts( status.grid_in ?? status.ac_in ) }</strong>
				</div>

				<div className="ecoflow-node ecoflow-node-battery" data-flow-id="battery">
					<div
						className={ `ecoflow-battery-ring ecoflow-battery-ring-map${ isCharging ? ' is-charging' : ' is-discharging' }` }
						style={ { '--battery-level': batteryPercent } }
					>
						<div className="ecoflow-battery-inner">
							<span className="ecoflow-battery-value">{ batteryPercent }%</span>
							<span className="ecoflow-battery-label">{ labels.battery }</span>
						</div>
					</div>
					<p className="ecoflow-node-state">{ status.charge_state || '—' }</p>
					{ status.remain_time > 0 && (
						<p className="ecoflow-remain-time ecoflow-remain-time-map">
							{ status.remain_time_label }
							<strong>{ status.remain_time_display || '—' }</strong>
						</p>
					) }
				</div>

				<div
					className={ `ecoflow-node ecoflow-node-home${ isFlowActive( 'home', status ) ? ' is-active' : '' }` }
					data-flow-id="home"
				>
					<span className="ecoflow-node-icon" aria-hidden="true">🏠</span>
					<span className="ecoflow-node-label">{ labels.home }</span>
					<strong>{ formatWatts( status.output_total ) }</strong>
				</div>
			</div>

			<div className="ecoflow-flow-summary">
				<div className="ecoflow-flow-summary-item">
					<span>{ labels.inputTotal }</span>
					<strong>{ formatWatts( status.input_total ) }</strong>
				</div>
				<div className="ecoflow-flow-summary-item">
					<span>{ labels.outputTotal }</span>
					<strong>{ formatWatts( status.output_total ) }</strong>
				</div>
			</div>
		</>
	);
}

export default function EnergyFlowDiagram( { initial, labels } ) {
	const mapRef = useRef( null );
	const canvasRef = useRef( null );
	const [ status, setStatus ] = useState( initial || {} );
	const images = window.gamingHubEcoflowFlow?.images || {};

	useFlowCanvas( canvasRef, mapRef, status );

	useEffect( () => {
		const onUpdate = ( event ) => {
			if ( event.detail ) {
				setStatus( event.detail );
			}
		};

		document.addEventListener( 'gamingHubEcoflowStatus', onUpdate );
		return () => document.removeEventListener( 'gamingHubEcoflowStatus', onUpdate );
	}, [] );

	const isDual = status.dual !== false;

	return (
		<div
			ref={ mapRef }
			className={ `ecoflow-energy-map${ isDual ? ' is-dual is-gaming' : '' }` }
			data-charging={ status.is_charging ? '1' : '0' }
			data-dual={ isDual ? '1' : '0' }
			aria-label={ labels.flow }
		>
			<canvas ref={ canvasRef } className="ecoflow-energy-canvas" aria-hidden="true" />

			<div className="ecoflow-energy-content">
				{ isDual ? (
					<DualFlowDiagram status={ status } labels={ labels } images={ images } />
				) : (
					<SingleFlowDiagram status={ status } labels={ labels } />
				) }
			</div>
		</div>
	);
}
