import { useEffect, useRef, useState } from 'react';
import { formatWatts, isFlowActive } from './constants';
import { useFlowCanvas } from './useFlowCanvas';

function parseSoc( value ) {
	if ( value === null || value === undefined || value === '' ) {
		return null;
	}

	const soc = Number( value );
	return Number.isFinite( soc ) ? Math.max( 0, Math.min( 100, Math.round( soc ) ) ) : null;
}

function HudNode( {
	flowId,
	label,
	watts,
	state,
	active,
	photo,
	photoClass,
	hero,
	soc,
	isCharging,
	extra,
} ) {
	const level = parseSoc( soc );
	const hasSoc = null !== level;
	const classes = [
		'pw-hud-node',
		`pw-hud-node-${ flowId }`,
		hero ? 'is-hero' : '',
		active ? 'is-active' : '',
		isCharging ? 'is-charging' : '',
	].filter( Boolean ).join( ' ' );

	return (
		<div className={ classes } data-flow-id={ flowId }>
			<div
				className="pw-hud-art"
				style={ hasSoc ? { '--battery-level': level } : undefined }
			>
				{ photo ? (
					<img src={ photo } alt="" className={ `pw-hud-photo ${ photoClass || '' }` } />
				) : null }
				{ hasSoc ? (
					<>
						<span className="pw-hud-fill" aria-hidden="true" />
						<span className="pw-hud-pct">{ `${ level }%` }</span>
					</>
				) : null }
			</div>
			<span className="pw-hud-label">{ label }</span>
			{ hasSoc ? <strong className="pw-hud-soc">{ `${ level }%` }</strong> : null }
			<strong className="pw-hud-watts">{ formatWatts( watts ) }</strong>
			{ state ? <p className="pw-hud-state">{ state }</p> : null }
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
	const pwSoc = parseSoc( powerwall.battery_percent );
	const m3Soc = parseSoc( model3.battery_percent );

	return (
		<div className="pw-flow-scene">
			<div
				ref={ mapRef }
				className="pw-flow-map is-gaming"
				data-charging={ powerwall.is_charging ? '1' : '0' }
				aria-label={ labels.flow }
			>
				<canvas ref={ canvasRef } className="pw-flow-canvas" aria-hidden="true" />

				<div className="pw-hud-layout">
					<HudNode
						flowId="solar"
						label={ labels.solar }
						watts={ status.solar_w }
						active={ isFlowActive( 'solar', status ) }
						photo={ images.solar }
						photoClass="pw-hud-photo-solar"
					/>

					<HudNode
						flowId="grid"
						label={ labels.grid }
						watts={ status.grid_import_w }
						state={ labels.gridNote }
						active={ isFlowActive( 'gridImport', status ) }
						photo={ images.grid }
						photoClass="pw-hud-photo-grid"
					/>

					<HudNode
						flowId="powerwall"
						label={ labels.powerwall }
						watts={ powerwall.watts }
						state={ powerwall.charge_state || '—' }
						active={ isFlowActive( 'solar', status ) || isFlowActive( 'home', status ) || isFlowActive( 'car', status ) }
						photo={ images.powerwall }
						photoClass="pw-hud-photo-powerwall"
						hero
						soc={ pwSoc }
						isCharging={ !! powerwall.is_charging }
					/>

					<HudNode
						flowId="home"
						label={ labels.home }
						watts={ status.home_w }
						active={ isFlowActive( 'home', status ) }
						photo={ images.home }
						photoClass="pw-hud-photo-home"
					/>

					<HudNode
						flowId="model3"
						label={ labels.model3 }
						watts={ model3.watts }
						state={ model3.charge_state || '—' }
						active={ isFlowActive( 'car', status ) }
						photo={ images.model3 }
						photoClass="pw-hud-photo-car"
						hero
						soc={ m3Soc }
						isCharging={ !! model3.is_charging }
						extra={ model3.is_charging ? (
							<p className="pw-hud-charge">
								{ model3.charge_rate_label || '—' }
								{ model3.charge_eta_label ? ` · ${ model3.charge_eta_label }` : '' }
							</p>
						) : null }
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
					<strong>{ null !== pwSoc ? `${ pwSoc }%` : '—' }</strong>
					<small>{ powerwall.charge_state || '—' }</small>
				</div>
				<div className="pw-flow-summary-item">
					<span>{ labels.model3 }</span>
					<strong>{ null !== m3Soc ? `${ m3Soc }%` : '—' }</strong>
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
