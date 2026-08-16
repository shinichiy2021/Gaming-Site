import { useEffect, useRef, useState } from 'react';
import { FLOW_THRESHOLD, formatPack, formatSoc, formatWatts, parseSoc, deltaGridAc, hvInput, proGridCharge, solarToDelta, upsOutput } from './constants';
import { useFlowCanvas } from './useFlowCanvas';

function isFlowActive( flowId, status ) {
	if ( ! status ) {
		return false;
	}

	if ( flowId === 'solar' ) {
		return solarToDelta( status ) >= FLOW_THRESHOLD;
	}

	if ( flowId === 'deltaGrid' ) {
		return deltaGridAc( status ) >= FLOW_THRESHOLD;
	}

	if ( flowId === 'grid' ) {
		return proGridCharge( status ).active && proGridCharge( status ).watts >= FLOW_THRESHOLD;
	}

	if ( flowId === 'hv' ) {
		return hvInput( status ) >= FLOW_THRESHOLD;
	}

	if ( flowId === 'proToLink' || flowId === 'linkToDelta' || flowId === 'proToDelta' ) {
		return false;
	}

	if ( flowId === 'proToHome' || flowId === 'home' ) {
		const watts = Number( status.home_out ) || Number( status.pro?.ac_out ) || 0;
		return watts >= FLOW_THRESHOLD;
	}

	if ( flowId === 'deltaToUps' || flowId === 'ups' ) {
		return upsOutput( status ) >= FLOW_THRESHOLD;
	}

	return false;
}

function DeviceNode( { device, label, flowId, photo, compact, hero, prominent } ) {
	if ( ! device ) {
		return null;
	}

	const batteryPercent = device.battery_percent === null || device.battery_percent === undefined || device.battery_percent === ''
		? NaN
		: Number( device.battery_percent );
	const hasBattery = Number.isFinite( batteryPercent );
	const fullWh = Number( device.capacity_wh );
	const remainWh = Number.isFinite( Number( device.remain_capacity ) )
		? Number( device.remain_capacity )
		: ( hasBattery && Number.isFinite( fullWh ) ? fullWh * batteryPercent / 100 : null );
	const packLabel = Number.isFinite( fullWh ) && fullWh > 0
		? formatPack( remainWh, fullWh )
		: '';
	const isCharging = !! device.is_charging;
	const overlay = hero || prominent;
	const classes = [
		'ecoflow-node',
		'ecoflow-node-battery',
		'ecoflow-node-device',
		isCharging ? 'is-charging' : '',
		hero ? 'is-hero' : '',
		prominent ? 'is-prominent' : '',
	].filter( Boolean ).join( ' ' );
	const photoClass = [
		'ecoflow-node-photo',
		hero ? 'ecoflow-node-photo-pro' : '',
		prominent ? 'ecoflow-node-photo-delta' : '',
	].filter( Boolean ).join( ' ' );

	return (
		<div className={ classes } data-flow-id={ flowId }>
			<div
				className="ecoflow-node-art"
				style={ hasBattery ? { '--battery-level': batteryPercent } : undefined }
			>
				{ photo ? (
					<img src={ photo } alt="" className={ photoClass } />
				) : null }
				{ hasBattery ? (
					<>
						{ overlay ? <span className="ecoflow-hud-fill" aria-hidden="true" /> : null }
						<span className="ecoflow-hud-pct">{ formatSoc( batteryPercent ) }</span>
					</>
				) : null }
			</div>
			<span className="ecoflow-node-label">{ label }</span>
			{ packLabel ? <small className="ecoflow-node-pack">{ packLabel }</small> : null }
			<p className="ecoflow-node-state">{ device.charge_state || '—' }</p>
			{ ! compact && hasBattery && ! photo ? (
				<div
					className={ `ecoflow-battery-ring ecoflow-battery-ring-map${ isCharging ? ' is-charging' : ' is-discharging' }` }
					style={ { '--battery-level': batteryPercent } }
				>
					<div className="ecoflow-battery-inner">
						<span className="ecoflow-battery-value">{ formatSoc( batteryPercent ) }</span>
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
	const solarWatts = solarToDelta( status );
	const hvWatts = hvInput( status );
	const proGrid = proGridCharge( status );
	const roomWatts = Number( status.home_out ) || Number( pro.ac_out ) || 0;
	const upsWatts = upsOutput( status );
	const deltaAcIn = deltaGridAc( status );
	const extra = status.extra || delta.extra || { connected: true, battery_percent: null, capacity_wh: 1000 };
	const extraSoc = parseSoc( extra.battery_percent );
	const extraCap = Number( extra.capacity_wh ) || 1000;
	const extraCapLabel = extraCap >= 1000
		? `${ ( extraCap / 1000 ).toLocaleString( undefined, { maximumFractionDigits: 1 } ) } kWh`
		: `${ extraCap } Wh`;
	const deltaSoc = formatSoc( delta.battery_percent );

	return (
		<div className="ecoflow-dual-layout is-independent">
			<section className="ecoflow-system ecoflow-system-pro" aria-label={ labels.pro }>
				<p className="ecoflow-system-title">{ labels.pro }</p>

				<div className="ecoflow-input-stack">
					<div
						className={ `ecoflow-node ecoflow-node-grid ecoflow-node-grid-slot${ isFlowActive( 'grid', status ) ? ' is-active' : '' }` }
						data-flow-id="grid"
					>
						<span className="ecoflow-node-icon" aria-hidden="true">⚡</span>
						<span className="ecoflow-node-label">{ labels.gridCharge || labels.grid }</span>
						<strong>{ proGrid.active ? formatWatts( proGrid.watts ) : ( labels.gridIdle || '待機' ) }</strong>
						{ proGrid.message ? <small>{ proGrid.message }</small> : null }
					</div>

					<div
						className={ `ecoflow-node ecoflow-node-hv${ isFlowActive( 'hv', status ) ? ' is-active' : '' }` }
						data-flow-id="hv"
					>
						{ images.solar ? (
							<img src={ images.solar } alt="" className="ecoflow-node-photo ecoflow-node-photo-solar" />
						) : (
							<span className="ecoflow-node-icon" aria-hidden="true">☀️</span>
						) }
						<span className="ecoflow-node-label">{ labels.hv || 'ハイボルト' }</span>
						<strong>{ formatWatts( hvWatts ) }</strong>
					</div>
				</div>

				<DeviceNode device={ pro } label={ labels.pro } flowId="pro" photo={ images.pro } hero />

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

				<div className="ecoflow-flow-summary ecoflow-flow-summary-system">
					<div className="ecoflow-flow-summary-item">
						<span>{ labels.gridCharge || labels.grid }</span>
						<strong>{ proGrid.active ? formatWatts( proGrid.watts ) : ( labels.gridIdle || '待機' ) }</strong>
						{ proGrid.message ? <small>{ proGrid.message }</small> : null }
					</div>
					<div className="ecoflow-flow-summary-item">
						<span>{ labels.hv || 'ハイボルト' }</span>
						<strong>{ formatWatts( hvWatts ) }</strong>
					</div>
					<div className="ecoflow-flow-summary-item">
						<span>{ labels.home }</span>
						<strong>{ formatWatts( roomWatts ) }</strong>
						<small>{ pro.charge_state || '—' }</small>
					</div>
				</div>
			</section>

			<section className="ecoflow-system ecoflow-system-delta" aria-label={ labels.delta }>
				<p className="ecoflow-system-title">{ labels.delta }</p>

				<div className="ecoflow-input-stack">
					<div
						className={ `ecoflow-node ecoflow-node-grid ecoflow-node-grid-slot${ isFlowActive( 'deltaGrid', status ) ? ' is-active' : '' }` }
						data-flow-id="deltaGrid"
					>
						<span className="ecoflow-node-icon" aria-hidden="true">⚡</span>
						<span className="ecoflow-node-label">{ labels.deltaGrid || 'グリッド AC 入力' }</span>
						<strong>{ formatWatts( deltaAcIn ) }</strong>
						<small>{ labels.acInMeasured || '実測 · MQTT' }</small>
					</div>

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
						<strong>{ formatWatts( solarWatts ) }</strong>
						<small>{
							status.solar_in_source === 'unavailable' || solarWatts === null || solarWatts === undefined
								? '未取得'
								: ( labels.lvMeasured || '実測 · MQTT' )
						}</small>
					</div>
				</div>

				<div className="ecoflow-delta-cluster">
					<DeviceNode device={ delta } label={ labels.delta } flowId="delta" photo={ images.delta } prominent />

					<div
						className={ `ecoflow-node ecoflow-node-extra${ Number.isFinite( extraSoc ) ? ' has-soc' : '' }${ delta.is_charging ? ' is-charging' : '' }` }
						data-flow-id="extra"
						style={ Number.isFinite( extraSoc ) ? { '--battery-level': extraSoc } : undefined }
					>
						<div className="ecoflow-node-art ecoflow-extra-pack">
							{ images.extra ? (
								<img src={ images.extra } alt="" className="ecoflow-node-photo ecoflow-node-photo-extra" />
							) : (
								<span className="ecoflow-node-icon" aria-hidden="true">🔋</span>
							) }
							<span className="ecoflow-hud-fill" aria-hidden="true" />
							{ Number.isFinite( extraSoc ) ? (
								<span className="ecoflow-hud-pct">{ formatSoc( extraSoc ) }</span>
							) : null }
						</div>
						<span className="ecoflow-node-label">{ labels.extra || 'Extra Battery 1kW' }</span>
						<small>{ extraCapLabel }</small>
					</div>
				</div>

				<div
					className={ `ecoflow-node ecoflow-node-home ecoflow-node-ups${ isFlowActive( 'deltaToUps', status ) ? ' is-active' : '' }` }
					data-flow-id="ups"
				>
					{ images.ups ? (
						<img src={ images.ups } alt="" className="ecoflow-node-photo ecoflow-node-photo-ups" />
					) : (
						<span className="ecoflow-node-icon" aria-hidden="true">🔋</span>
					) }
					<span className="ecoflow-node-label">{ labels.ups || '常時稼働エリア (UPS)' }</span>
					<strong>{ formatWatts( upsWatts ) }</strong>
					<small>{ status.ups_source === 'ecoflow' ? ( labels.acOut || 'AC 出力 · MQTT' ) : ( status.ups_source === 'switchbot' ? ( labels.upsPlug || 'SwitchBot Plug' ) : '未取得' ) }</small>
				</div>

				<div className="ecoflow-flow-summary ecoflow-flow-summary-system">
					<div className="ecoflow-flow-summary-item">
						<span>{ labels.deltaGrid || 'グリッド AC 入力' }</span>
						<strong>{ formatWatts( deltaAcIn ) }</strong>
					</div>
					<div className="ecoflow-flow-summary-item">
						<span>{ labels.solar }</span>
						<strong>{ formatWatts( solarWatts ) }</strong>
					</div>
					<div className="ecoflow-flow-summary-item">
						<span>{ labels.ups || '常時稼働エリア (UPS)' }</span>
						<strong>{ formatWatts( upsWatts ) }</strong>
						<small>{ delta.charge_state || '—' } · { deltaSoc } · EB { formatSoc( extraSoc ) }</small>
					</div>
				</div>
			</section>
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
