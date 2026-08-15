import { useEffect, useRef, useState } from 'react';
import { formatWatts, isFlowActive } from './constants';
import { useFlowCanvas } from './useFlowCanvas';

function Model3BatteryRing( { model3 } ) {
	const soc = Number( model3.battery_percent );
	const hasSoc = Number.isFinite( soc );
	const isCharging = !!model3.is_charging;

	if ( ! hasSoc ) {
		return null;
	}

	return (
		<div className="pw-model3-pin-gauge" style={ { '--battery-level': soc } }>
			<div className={ `pw-flow-battery-ring pw-model3-battery-ring is-compact${ isCharging ? ' is-charging' : '' }` }>
				<div className="pw-flow-battery-inner">
					<span className="pw-flow-battery-value">{ `${ soc }%` }</span>
				</div>
			</div>
			{ isCharging ? (
				<div className="pw-model3-pin-charging">
					<span>{ model3.charge_rate_label || '—' }</span>
					{ model3.charge_eta_label ? <small>{ model3.charge_eta_label }</small> : null }
				</div>
			) : null }
		</div>
	);
}

function Callout( { flowId, label, watts, state, active, extra } ) {
	return (
		<div
			className={ `pw-flow-pin pw-flow-pin-${ flowId }${ active ? ' is-active' : '' }` }
			data-flow-id={ flowId }
		>
			<span className="pw-flow-pin-label">{ label }</span>
			<strong>{ formatWatts( watts ) }</strong>
			{ state ? <p className="pw-flow-node-state">{ state }</p> : null }
			{ extra }
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
	const images = window.gamingHubPowerwallFlow?.images || {};
	const houseSrc = images.house || '';
	const soc = Number( powerwall.battery_percent );
	const socLabel = Number.isFinite( soc ) ? `${ soc }%` : '—';

	return (
		<div className="pw-flow-scene">
			<div
				ref={ mapRef }
				className="pw-flow-map is-house"
				data-charging={ powerwall.is_charging ? '1' : '0' }
				aria-label={ labels.flow }
			>
				{ houseSrc ? (
					<img
						src={ houseSrc }
						alt=""
						className="pw-flow-house"
					/>
				) : null }

				<canvas ref={ canvasRef } className="pw-flow-canvas" aria-hidden="true" />

				<div className="pw-flow-pins">
					<Callout
						flowId="solar"
						label={ labels.solar }
						watts={ status.solar_w }
						active={ isFlowActive( 'solar', status ) }
					/>

					<Callout
						flowId="powerwall"
						label={ labels.powerwall }
						watts={ powerwall.watts }
						state={ powerwall.charge_state || '—' }
						active={ isFlowActive( 'solar', status ) || isFlowActive( 'home', status ) }
						extra={ <span className="pw-flow-pin-soc">{ socLabel }</span> }
					/>

					<Callout
						flowId="home"
						label={ labels.home }
						watts={ status.home_w }
						active={ isFlowActive( 'home', status ) }
					/>

					<Callout
						flowId="model3"
						label={ labels.model3 }
						watts={ model3.watts }
						state={ model3.charge_state || '—' }
						active={ isFlowActive( 'car', status ) }
						extra={ <Model3BatteryRing model3={ model3 } /> }
					/>

					<Callout
						flowId="grid"
						label={ labels.grid }
						watts={ status.grid_import_w }
						active={ isFlowActive( 'gridImport', status ) }
						extra={ <small className="pw-flow-grid-note">{ labels.gridNote }</small> }
					/>
				</div>
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
					<strong>{ Number.isFinite( Number( model3.battery_percent ) ) ? `${ model3.battery_percent }%` : '—' }</strong>
					{ model3.is_charging ? (
						<small>{ model3.charge_rate_label || '—' }{ model3.charge_eta_label ? ` · ${ model3.charge_eta_label }` : '' }</small>
					) : (
						<small>{ model3.charge_state || '—' }</small>
					) }
				</div>
				<div className="pw-flow-summary-item">
					<span>{ labels.import }</span>
					<strong>{ formatWatts( status.grid_import_w ) }</strong>
				</div>
			</div>
		</div>
	);
}
