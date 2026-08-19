import { useEffect, useRef, useState } from 'react';
import { FLOW_THRESHOLD, formatPack, formatSoc, formatWatts, parseSoc, deltaGridAc, hvInput, proGridCharge, solarToDelta, upsOutput } from './constants';
import { useFlowCanvas } from './useFlowCanvas';

function isFlowActive( flowId, status ) {
	if ( ! status ) {
		return false;
	}

	if ( flowId === 'solar' ) {
		const watts = solarToDelta( status );
		return watts !== null && watts !== undefined && Number( watts ) >= FLOW_THRESHOLD;
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

function batteryTone( percent ) {
	if ( ! Number.isFinite( percent ) ) {
		return { color: '#8b93a7', className: '' };
	}

	if ( percent <= 10 ) {
		return { color: '#ff453a', className: 'is-critical' };
	}

	if ( percent <= 20 ) {
		return { color: '#ffd60a', className: 'is-low' };
	}

	return { color: '#34c759', className: 'is-ok' };
}

function flowNodeClass( ...parts ) {
	return parts.filter( Boolean ).join( ' ' );
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
			title={ formatSoc( percent ) }
		>
			<span className="ecoflow-phone-batt-icon" aria-hidden="true">
				<span className="ecoflow-phone-batt-shell">
					<span className="ecoflow-phone-batt-fill" />
				</span>
				<span className="ecoflow-phone-batt-nub" />
			</span>
			<span className="ecoflow-phone-batt-pct">{ formatSoc( percent ) }</span>
		</span>
	);
}

function PackEta( { device } ) {
	if ( ! device || device.eta_mode === 'idle' || ! device.remain_time_label ) {
		return null;
	}

	return (
		<p className="ecoflow-node-eta">
			<span>{ device.remain_time_label }</span>
			<strong>{ device.remain_time_display || '—' }</strong>
		</p>
	);
}

function isDeltaMqttMissing( status ) {
	const delta = status && status.delta;
	if ( ! delta ) {
		return true;
	}

	if ( delta.mqtt_live !== true ) {
		return true;
	}

	return delta.soc_source === 'unavailable';
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
	const mqttMissing = flowId === 'delta' && (
		device.mqtt_live !== true || device.soc_source === 'unavailable'
	);
	const packLabel = mqttMissing
		? ( typeof window !== 'undefined' && window.gamingHubT ? window.gamingHubT( '未取得' ) : '未取得' )
		: ( Number.isFinite( fullWh ) && fullWh > 0 ? formatPack( remainWh, fullWh ) : '' );
	const isCharging = ! mqttMissing && (
		device.eta_mode === 'charge' || ( device.eta_mode !== 'discharge' && !! device.is_charging )
	);
	const acOut = Number( device.ac_out ) || 0;
	const outputTotal = Number( device.output_total ) || 0;
	const isDischarging = ! mqttMissing && (
		device.eta_mode === 'discharge'
		|| ( device.eta_mode !== 'charge' && (
			!! device.is_discharging
			|| ( ! isCharging && Math.max( acOut, outputTotal ) >= FLOW_THRESHOLD )
		) )
	);
	const isStandby = ! mqttMissing && ! isCharging && ! isDischarging;
	const tone = batteryTone( batteryPercent );
	const classes = [
		'ecoflow-node',
		'ecoflow-node-battery',
		'ecoflow-node-device',
		isCharging ? 'is-charging' : '',
		isDischarging ? 'is-discharging' : '',
		isStandby ? 'is-standby' : '',
		! mqttMissing && ! isStandby ? 'is-active' : '',
		mqttMissing ? 'is-unavailable' : '',
		tone.className,
		hero ? 'is-hero' : '',
		prominent ? 'is-prominent' : '',
	].filter( Boolean ).join( ' ' );
	const photoClass = [
		'ecoflow-node-photo',
		hero ? 'ecoflow-node-photo-pro' : '',
		prominent ? 'ecoflow-node-photo-delta' : '',
	].filter( Boolean ).join( ' ' );
	const battStyle = hasBattery
		? { '--battery-level': batteryPercent, '--batt-tone': tone.color }
		: undefined;

	return (
		<div className={ classes } data-flow-id={ flowId } style={ battStyle }>
			<div className="ecoflow-node-art" style={ battStyle }>
				{ photo ? (
					<img src={ photo } alt="" className={ photoClass } />
				) : null }
				<PhoneBattery percent={ batteryPercent } charging={ isCharging } />
			</div>
			<span className="ecoflow-node-label">{ label }</span>
			{ packLabel ? <small className="ecoflow-node-pack">{ packLabel }</small> : null }
			<p className="ecoflow-node-state">{ mqttMissing ? ( typeof window !== 'undefined' && window.gamingHubT ? window.gamingHubT( '未取得' ) : '未取得' ) : ( device.charge_state || '—' ) }</p>
			{ ! mqttMissing ? <PackEta device={ device } /> : null }
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

function formatYenInt( value ) {
	if ( ! Number.isFinite( value ) ) {
		return '—';
	}

	const suffix = ( typeof window !== 'undefined' && window.gamingHubT )
		? window.gamingHubT( ' 円' )
		: ' 円';

	return Math.round( value ).toLocaleString() + suffix;
}

function liveTodayYen( todayYen ) {
	if ( ! todayYen || typeof todayYen !== 'object' ) {
		return { room: null, grid: null, proGrid: null, ups: null, buy: null, net: null };
	}

	return {
		room: Number( todayYen.room_yen ),
		ups: Number( todayYen.ups_yen ),
		grid: Number( todayYen.grid_yen ),
		proGrid: Number( todayYen.pro_grid_yen ),
		buy: Number( todayYen.buy_yen ),
		net: Number( todayYen.net_yen ),
	};
}

function useLiveTodayYen( todayYen ) {
	return liveTodayYen( todayYen );
}

function formatTodayWatts( value ) {
	if ( ! Number.isFinite( value ) ) {
		return '—';
	}

	return Math.round( Math.max( 0, value ) ).toLocaleString() + ' W';
}

function liveTodaySolar( todaySolar ) {
	if ( ! todaySolar || typeof todaySolar !== 'object' ) {
		return { pro: null, delta: null };
	}

	return {
		pro: Number( todaySolar.pro_wh ),
		delta: Number( todaySolar.delta_wh ),
	};
}

function useLiveTodaySolar( todaySolar ) {
	return liveTodaySolar( todaySolar );
}

function liveTodayUsage( todayUsage ) {
	if ( ! todayUsage || typeof todayUsage !== 'object' ) {
		return { room: null, ups: null };
	}

	return {
		room: Number( todayUsage.room_wh ),
		ups: Number( todayUsage.ups_wh ),
	};
}

function useLiveTodayUsage( todayUsage ) {
	return liveTodayUsage( todayUsage );
}

function DualFlowDiagram( { status, labels, images, liveYen, liveSolar, liveUsage } ) {
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
	const extraMissing = extraSoc === null;
	const extraCapText = extraCap >= 1000
		? `${ ( extraCap / 1000 ).toLocaleString( undefined, { maximumFractionDigits: 1 } ) } kWh`
		: `${ extraCap } Wh`;
	const extraLastLabel = typeof window !== 'undefined' && window.gamingHubT
		? window.gamingHubT( '最終値' )
		: '最終値';
	const extraCapLabel = extraMissing
		? ( typeof window !== 'undefined' && window.gamingHubT ? window.gamingHubT( '未取得' ) : '未取得' )
		: ( extra.capacity_source === 'stale' ? `${ extraCapText } · ${ extraLastLabel }` : extraCapText );
	const deltaSoc = formatSoc( delta.battery_percent );
	const extraTone = batteryTone( extraSoc );
	const deltaMissing = isDeltaMqttMissing( status );
	const upsLive = status.ups_source === 'ecoflow' || status.ups_source === 'switchbot';
	const extraCharging = ! extraMissing && ! deltaMissing && (
		extra.eta_mode === 'charge' || !! extra.is_charging
	);
	const extraDischarging = ! extraMissing && ! deltaMissing && (
		extra.eta_mode === 'discharge' || !! extra.is_discharging
	);
	const extraStandby = extraMissing || deltaMissing || ( ! extraCharging && ! extraDischarging );

	return (
		<div className="ecoflow-dual-layout is-independent">
			<section className="ecoflow-system ecoflow-system-pro" aria-label={ labels.pro }>
				<p className="ecoflow-system-title">{ labels.pro }</p>

				<div className="ecoflow-input-stack">
					<div
						className={ flowNodeClass( 'ecoflow-node ecoflow-node-grid ecoflow-node-grid-slot ecoflow-node-banner', isFlowActive( 'grid', status ) ? 'is-active' : 'is-standby' ) }
						data-flow-id="grid"
					>
						{ images.grid ? (
							<img src={ images.grid } alt="" className="ecoflow-node-photo ecoflow-node-photo-grid" />
						) : (
							<span className="ecoflow-node-banner-art" aria-hidden="true">
								<span className="ecoflow-node-icon">⚡</span>
							</span>
						) }
						<span className="ecoflow-node-label">{ labels.gridCharge || labels.grid }</span>
						<strong>{ proGrid.active ? formatWatts( proGrid.watts ) : ( labels.gridIdle || '待機' ) }</strong>
						<small className="ecoflow-node-yen is-buy">
							{ labels.todayBuy || '今日 買電' } { formatYenInt( liveYen?.proGrid ) }
						</small>
						{ proGrid.message ? <small>{ proGrid.message }</small> : null }
					</div>

					<div
						className={ flowNodeClass( 'ecoflow-node ecoflow-node-hv ecoflow-node-banner', isFlowActive( 'hv', status ) ? 'is-active' : 'is-standby' ) }
						data-flow-id="hv"
					>
						{ images.solar ? (
							<img src={ images.solar } alt="" className="ecoflow-node-photo ecoflow-node-photo-solar" />
						) : (
							<span className="ecoflow-node-banner-art" aria-hidden="true">
								<span className="ecoflow-node-icon">☀️</span>
							</span>
						) }
						<span className="ecoflow-node-label">{ labels.hv || 'ハイボルト' }</span>
						<strong>{ formatWatts( hvWatts ) }</strong>
						<small className="ecoflow-node-yen ecoflow-node-gen">
							{ labels.todayGen || '今日 発電' } { formatTodayWatts( liveSolar?.pro ) }
						</small>
					</div>
				</div>

				<DeviceNode device={ pro } label={ labels.pro } flowId="pro" photo={ images.pro } hero />

				<div
					className={ flowNodeClass( 'ecoflow-node ecoflow-node-home ecoflow-node-room ecoflow-node-banner', isFlowActive( 'proToHome', status ) ? 'is-active' : 'is-standby' ) }
					data-flow-id="home"
				>
					{ images.room ? (
						<img src={ images.room } alt="" className="ecoflow-node-photo ecoflow-node-photo-room" />
					) : (
						<span className="ecoflow-node-icon" aria-hidden="true">🏠</span>
					) }
					<span className="ecoflow-node-label">{ labels.home }</span>
					<strong>{ formatWatts( roomWatts ) }</strong>
					<small className="ecoflow-node-yen ecoflow-node-gen">
						{ labels.todayUse || '今日 使用' } { formatTodayWatts( liveUsage?.room ) }
					</small>
					<small className="ecoflow-node-yen">
						{ labels.todaySave || '今日 節約' } { formatYenInt( liveYen?.room ) }
					</small>
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
						className={ flowNodeClass( 'ecoflow-node ecoflow-node-grid ecoflow-node-grid-slot ecoflow-node-banner', deltaMissing ? 'is-unavailable' : ( isFlowActive( 'deltaGrid', status ) ? 'is-active' : 'is-standby' ) ) }
						data-flow-id="deltaGrid"
					>
						{ images.grid ? (
							<img src={ images.grid } alt="" className="ecoflow-node-photo ecoflow-node-photo-grid" />
						) : (
							<span className="ecoflow-node-banner-art" aria-hidden="true">
								<span className="ecoflow-node-icon">⚡</span>
							</span>
						) }
						<span className="ecoflow-node-label">{ labels.deltaGrid || 'グリッド AC 入力' }</span>
						<strong>{ formatWatts( deltaAcIn ) }</strong>
						<small className={ deltaMissing ? '' : 'ecoflow-node-yen is-buy' }>{
							deltaMissing
								? ( typeof window !== 'undefined' && window.gamingHubT ? window.gamingHubT( '未取得' ) : '未取得' )
								: `${ labels.todayBuy || '今日 買電' } ${ formatYenInt( liveYen?.grid ) }`
						}</small>
					</div>

					<div
						className={ flowNodeClass( 'ecoflow-node ecoflow-node-solar ecoflow-node-banner', solarWatts === null || solarWatts === undefined ? ( deltaMissing ? 'is-unavailable' : 'is-standby' ) : ( isFlowActive( 'solar', status ) ? 'is-active' : 'is-standby' ) ) }
						data-flow-id="solar"
					>
						{ images.solar ? (
							<img src={ images.solar } alt="" className="ecoflow-node-photo ecoflow-node-photo-solar" />
						) : (
							<span className="ecoflow-node-banner-art" aria-hidden="true">
								<span className="ecoflow-node-icon">☀️</span>
							</span>
						) }
						<span className="ecoflow-node-label">{ labels.solar }</span>
						<strong>{ formatWatts( solarWatts ) }</strong>
						<small className={ solarWatts === null || solarWatts === undefined ? '' : 'ecoflow-node-yen ecoflow-node-gen' }>{
							solarWatts === null || solarWatts === undefined
								? ( typeof window !== 'undefined' && window.gamingHubT ? window.gamingHubT( '未取得' ) : '未取得' )
								: `${ labels.todayGen || '今日 発電' } ${ formatTodayWatts( liveSolar?.delta ) }`
						}</small>
					</div>
				</div>

				<div className="ecoflow-delta-cluster">
					<DeviceNode device={ delta } label={ labels.delta } flowId="delta" photo={ images.delta } prominent />
				</div>

				<div
					className={ flowNodeClass(
						'ecoflow-node ecoflow-node-extra ecoflow-node-banner',
						Number.isFinite( extraSoc ) ? 'has-soc' : '',
						extraCharging ? 'is-charging' : '',
						extraDischarging ? 'is-discharging' : '',
						extraMissing ? 'is-unavailable' : ( extraStandby ? 'is-standby' : 'is-active' ),
						extraTone.className
					) }
					data-flow-id="extra"
					style={ Number.isFinite( extraSoc ) ? { '--battery-level': extraSoc, '--batt-tone': extraTone.color } : undefined }
				>
					<div className="ecoflow-node-art ecoflow-extra-pack">
						{ images.extra ? (
							<img src={ images.extra } alt="" className="ecoflow-node-photo ecoflow-node-photo-extra" />
						) : (
							<span className="ecoflow-node-banner-art" aria-hidden="true">
								<span className="ecoflow-node-icon">🔋</span>
							</span>
						) }
						<PhoneBattery percent={ extraSoc } charging={ extraCharging } />
					</div>
					<span className="ecoflow-node-label">{ labels.extra || 'Extra Battery 1kW' }</span>
					<small>{ extraCapLabel }</small>
					{ ! extraMissing && ! deltaMissing ? <PackEta device={ extra } /> : null }
				</div>

				<div
					className={ flowNodeClass( 'ecoflow-node ecoflow-node-home ecoflow-node-ups ecoflow-node-banner', ( deltaMissing && status.ups_source !== 'switchbot' ) ? 'is-unavailable' : ( isFlowActive( 'deltaToUps', status ) ? 'is-active' : 'is-standby' ) ) }
					data-flow-id="ups"
				>
					{ images.ups ? (
						<img src={ images.ups } alt="" className="ecoflow-node-photo ecoflow-node-photo-ups" />
					) : (
						<span className="ecoflow-node-icon" aria-hidden="true">🔋</span>
					) }
					<span className="ecoflow-node-label">{ labels.ups || '常時稼働エリア (UPS)' }</span>
					<strong>{ formatWatts( upsWatts ) }</strong>
					{ upsLive ? (
						<>
							<small className="ecoflow-node-yen ecoflow-node-gen">
								{ labels.todayUse || '今日 使用' } { formatTodayWatts( liveUsage?.ups ) }
							</small>
							<small className="ecoflow-node-yen">
								{ labels.todaySave || '今日 節約' } { formatYenInt( liveYen?.ups ) }
							</small>
						</>
					) : (
						<small>{ typeof window !== 'undefined' && window.gamingHubT ? window.gamingHubT( '未取得' ) : '未取得' }</small>
					) }
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
					className={ flowNodeClass( 'ecoflow-node ecoflow-node-solar', isFlowActive( 'solar', status ) ? 'is-active' : 'is-standby' ) }
					data-flow-id="solar"
				>
					<span className="ecoflow-node-icon" aria-hidden="true">☀️</span>
					<span className="ecoflow-node-label">{ labels.solar }</span>
					<strong>{ formatWatts( status.solar_in ) }</strong>
				</div>

				<div
					className={ flowNodeClass( 'ecoflow-node ecoflow-node-grid', isFlowActive( 'grid', status ) ? 'is-active' : 'is-standby' ) }
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
					{ ( status.eta_mode === 'charge' || status.eta_mode === 'discharge' || status.remain_time > 0 ) && (
						<p className="ecoflow-remain-time ecoflow-remain-time-map">
							{ status.remain_time_label }
							<strong>{ status.remain_time_display || '—' }</strong>
						</p>
					) }
				</div>

				<div
					className={ flowNodeClass( 'ecoflow-node ecoflow-node-home', isFlowActive( 'home', status ) ? 'is-active' : 'is-standby' ) }
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
	const liveYen = useLiveTodayYen( status.today_yen );
	const liveSolar = useLiveTodaySolar( status.today_solar );
	const liveUsage = useLiveTodayUsage( status.today_usage );

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
					<DualFlowDiagram status={ status } labels={ labels } images={ images } liveYen={ liveYen } liveSolar={ liveSolar } liveUsage={ liveUsage } />
				) : (
					<SingleFlowDiagram status={ status } labels={ labels } />
				) }
			</div>
		</div>
	);
}
