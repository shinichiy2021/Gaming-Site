import { useEffect, useRef, useState } from 'react';
import { formatWatts, isFlowActive } from './constants';
import { useFlowCanvas } from './useFlowCanvas';

function BatteryNode( { device, label, flowId, accent = 'pw' } ) {
	if ( ! device ) {
		return null;
	}

	const batteryPercent = Number( device.battery_percent );
	const batteryLabel = Number.isFinite( batteryPercent ) ? `${ batteryPercent }%` : '—';
	const isCharging = !! device.is_charging;

	return (
		<div className={ `pw-flow-node pw-flow-node-battery pw-flow-node-${ accent }` } data-flow-id={ flowId }>
			<div
				className={ `pw-flow-battery-ring${ isCharging ? ' is-charging' : device.is_discharging ? ' is-discharging' : '' }` }
				style={ { '--battery-level': Number.isFinite( batteryPercent ) ? batteryPercent : 0 } }
			>
				<div className="pw-flow-battery-inner">
					<span className="pw-flow-battery-value">{ batteryLabel }</span>
					<span className="pw-flow-battery-label">{ label }</span>
				</div>
			</div>
			<p className="pw-flow-node-state">{ device.charge_state || '—' }</p>
		</div>
	);
}

export default function PowerwallFlowDiagram( { initial, labels } ) {
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

		document.addEventListener( 'gamingHubPowerwallFlow', onUpdate );
		return () => document.removeEventListener( 'gamingHubPowerwallFlow', onUpdate );
	}, [] );

	const powerwall = status.powerwall || {};
	const model3 = status.model3 || {};

	return (
		<div
			ref={ mapRef }
			className="pw-flow-map"
			data-charging={ powerwall.is_charging ? '1' : '0' }
			aria-label={ labels.flow }
		>
			<canvas ref={ canvasRef } className="pw-flow-canvas" aria-hidden="true" />

			<div className="pw-flow-content">
				<div className="pw-flow-layout">
					<div
						className={ `pw-flow-node pw-flow-node-solar${ isFlowActive( 'solar', status ) ? ' is-active' : '' }` }
						data-flow-id="solar"
					>
						<span className="pw-flow-node-icon" aria-hidden="true">☀️</span>
						<span className="pw-flow-node-label">{ labels.solar }</span>
						<strong>{ formatWatts( status.solar_w ) }</strong>
					</div>

					<BatteryNode device={ powerwall } label={ labels.powerwall } flowId="powerwall" />

					<div
						className={ `pw-flow-node pw-flow-node-home${ isFlowActive( 'home', status ) ? ' is-active' : '' }` }
						data-flow-id="home"
					>
						<span className="pw-flow-node-icon" aria-hidden="true">🏠</span>
						<span className="pw-flow-node-label">{ labels.home }</span>
						<strong>{ formatWatts( status.home_w ) }</strong>
					</div>

					<div
						className={ `pw-flow-node pw-flow-node-car${ isFlowActive( 'car', status ) ? ' is-active' : '' }` }
						data-flow-id="model3"
					>
						<span className="pw-flow-node-icon" aria-hidden="true">🚗</span>
						<span className="pw-flow-node-label">{ labels.model3 }</span>
						<strong>{ formatWatts( model3.watts ) }</strong>
						<p className="pw-flow-node-state">{ model3.charge_state || '—' }</p>
					</div>

					<div
						className={ `pw-flow-node pw-flow-node-grid pw-flow-node-import-only${ isFlowActive( 'gridImport', status ) ? ' is-active' : '' }` }
						data-flow-id="grid"
					>
						<span className="pw-flow-node-icon" aria-hidden="true">↙️</span>
						<span className="pw-flow-node-label">{ labels.grid }</span>
						<strong>{ formatWatts( status.grid_import_w ) }</strong>
						<small className="pw-flow-grid-note">{ labels.gridNote }</small>
					</div>

					<div className="pw-flow-summary">
						<div className="pw-flow-summary-item">
							<span>{ labels.solar }</span>
							<strong>{ formatWatts( status.solar_w ) }</strong>
						</div>
						<div className="pw-flow-summary-item">
							<span>{ labels.powerwall }</span>
							<strong>{ powerwall.charge_state || '—' }</strong>
						</div>
						<div className="pw-flow-summary-item">
							<span>{ labels.model3 }</span>
							<strong>{ model3.charge_state || '—' }</strong>
						</div>
						<div className="pw-flow-summary-item">
							<span>{ labels.import }</span>
							<strong>{ formatWatts( status.grid_import_w ) }</strong>
						</div>
					</div>
				</div>
			</div>
		</div>
	);
}
