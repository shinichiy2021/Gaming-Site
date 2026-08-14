import { useEffect, useRef, useState } from 'react';
import { formatWatts, isFlowActive } from './constants';
import { useFlowCanvas } from './useFlowCanvas';

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
					<strong>{ model3.charge_state || '—' }</strong>
				</div>
				<div className="pw-flow-summary-item">
					<span>{ labels.import }</span>
					<strong>{ formatWatts( status.grid_import_w ) }</strong>
				</div>
			</div>
		</div>
	);
}
