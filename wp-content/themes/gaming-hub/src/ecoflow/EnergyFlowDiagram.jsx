import { useEffect, useRef, useState } from 'react';
import { FLOW_THRESHOLD, formatPack, formatSoc, formatWatts, parseSoc, deltaGridAc, hvInput, proGridCharge, solarToDelta, upsOutput } from './constants';
// Flow canvas lines disabled — keep BattIcon discharge arrow only.

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
		? ( typeof window !== 'undefined' && window.gamingHubT ? window.gamingHubT( 'n/a' ) : 'n/a' )
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
			<p className="ecoflow-node-state">{ mqttMissing ? ( typeof window !== 'undefined' && window.gamingHubT ? window.gamingHubT( 'n/a' ) : 'n/a' ) : ( device.charge_state || '—' ) }</p>
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
		? window.gamingHubT( ' yen' )
		: ' yen';

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

function liveTodayBuy( todayBuy ) {
	if ( ! todayBuy || typeof todayBuy !== 'object' ) {
		return { pro: null, delta: null };
	}

	return {
		pro: Number( todayBuy.pro_wh ),
		delta: Number( todayBuy.delta_wh ),
	};
}

function useLiveTodayBuy( todayBuy ) {
	return liveTodayBuy( todayBuy );
}

function asWatts( value ) {
	const watts = Number( value );
	return Number.isFinite( watts ) ? Math.max( 0, watts ) : 0;
}

function whToKwh( value ) {
	const wh = Number( value );
	return Number.isFinite( wh ) ? Math.max( 0, wh ) / 1000 : 0;
}

function formatKw( watts ) {
	const w = asWatts( watts );
	if ( w < FLOW_THRESHOLD ) {
		return '0';
	}

	return ( w / 1000 ).toLocaleString( undefined, { maximumFractionDigits: 1 } );
}

function BattIcon( { charging, discharging } ) {
	const mode = charging ? 'is-charging' : ( discharging ? 'is-discharging' : '' );

	return (
		<span className={ `teslogic-batt-icon${ mode ? ` ${ mode }` : '' }` } aria-hidden="true">
			<span className="teslogic-batt-icon__body">
				<span className="teslogic-batt-icon__fill" />
				{ charging ? <span className="teslogic-batt-icon__mark">⚡</span> : null }
				{ ! charging && discharging ? (
					<span className="teslogic-batt-icon__mark teslogic-batt-icon__mark--discharge">
						<svg className="teslogic-batt-icon__arrow" viewBox="0 0 24 24" width="1em" height="1em" focusable="false">
							<path
								fill="#ffea00"
								stroke="#111"
								strokeWidth="1.4"
								strokeLinejoin="round"
								d="M8 2h8v9h5L12 22 3 11h5V2z"
							/>
						</svg>
					</span>
				) : null }
			</span>
			<span className="teslogic-batt-icon__nub" />
		</span>
	);
}

function MetricPair( { currentLabel, currentValue, totalLabel, totalValue, currentPct, totalPct, showBars } ) {
	return (
		<div className="teslogic-metrics">
			<div className="teslogic-metric">
				<span className="teslogic-metric__label">{ currentLabel }</span>
				<strong className="teslogic-metric__value">{ currentValue }</strong>
				{ showBars ? (
					<span className="teslogic-metric__bar" style={ { '--bar': `${ Math.min( 100, currentPct || 0 ) }%` } } />
				) : null }
			</div>
			<div className="teslogic-metric">
				<span className="teslogic-metric__label">{ totalLabel }</span>
				<strong className="teslogic-metric__value">{ totalValue }</strong>
				{ showBars ? (
					<span className="teslogic-metric__bar" style={ { '--bar': `${ Math.min( 100, totalPct || 0 ) }%` } } />
				) : null }
			</div>
		</div>
	);
}

function FlowCard( {
	flowId,
	label,
	icon,
	active,
	unavailable,
	className,
	currentLabel,
	currentValue,
	totalLabel,
	totalValue,
	currentPct,
	totalPct,
	showBars,
	note,
	extra,
} ) {
	const classes = [
		'teslogic-card',
		unavailable ? 'is-unavailable' : ( active ? 'is-active' : 'is-standby' ),
		className,
	].filter( Boolean ).join( ' ' );

	return (
		<div className={ classes } data-flow-id={ flowId }>
			<div className="teslogic-card__head">
				{ icon ? <span className="teslogic-card__icon" aria-hidden="true">{ icon }</span> : null }
				<span className="teslogic-card__label">{ label }</span>
			</div>
			{ note ? <small className="teslogic-card__note">{ note }</small> : null }
			<MetricPair
				currentLabel={ currentLabel }
				currentValue={ currentValue }
				totalLabel={ totalLabel }
				totalValue={ totalValue }
				currentPct={ currentPct }
				totalPct={ totalPct }
				showBars={ showBars }
			/>
			{ extra }
		</div>
	);
}

function formatWattsExact( watts ) {
	const w = Math.round( asWatts( watts ) );
	return `${ w.toLocaleString() } W`;
}

function formatTodayKw( wh ) {
	const kwh = whToKwh( wh );
	return `${ kwh.toLocaleString( undefined, { maximumFractionDigits: 1 } ) } kW`;
}

function PackBatteryCard( {
	flowId,
	label,
	soc,
	hasSoc,
	charging,
	discharging,
	unavailable,
	currentW,
	totalKwh,
	inputW,
	outputW,
	showIo,
	stateLabel,
	packLabel,
	eta,
	children,
} ) {
	const tone = batteryTone( hasSoc ? soc : NaN );
	const classes = [
		'teslogic-card',
		'teslogic-card--battery',
		charging ? 'is-charging' : '',
		( ! charging && discharging ) ? 'is-discharging' : '',
		unavailable ? 'is-unavailable is-asleep' : '',
		( ! unavailable && ( charging || discharging || currentW >= FLOW_THRESHOLD || ( showIo && ( asWatts( inputW ) >= FLOW_THRESHOLD || asWatts( outputW ) >= FLOW_THRESHOLD ) ) ) ) ? 'is-active' : 'is-standby',
		tone.className,
	].filter( Boolean ).join( ' ' );

	return (
		<div
			className={ classes }
			data-flow-id={ flowId }
			style={ hasSoc ? { '--battery-level': soc, '--batt-tone': tone.color } : undefined }
		>
			<div className="teslogic-battery__top teslogic-battery__top--stack">
				<BattIcon charging={ charging } discharging={ discharging } />
				<strong className="teslogic-battery__soc">
					{ hasSoc ? formatSoc( soc ) : '—' }
				</strong>
				{ packLabel ? <small className="teslogic-battery__pack">{ packLabel }</small> : null }
			</div>
			{ showIo ? (
				<MetricPair
					currentLabel="Input"
					currentValue={ unavailable ? '—' : formatWattsExact( inputW ) }
					totalLabel="Output"
					totalValue={ unavailable ? '—' : formatWattsExact( outputW ) }
				/>
			) : (
				<MetricPair
					currentLabel="Current, kW"
					currentValue={ unavailable ? '—' : formatKw( currentW ) }
					totalLabel="Total, kWh"
					totalValue={ unavailable ? '—' : totalKwh.toLocaleString( undefined, { maximumFractionDigits: 1 } ) }
				/>
			) }
			{ label ? <span className="teslogic-battery__name">{ label }</span> : null }
			<small className="teslogic-battery__state">{ stateLabel }</small>
			{ eta }
			{ children }
		</div>
	);
}

function PackCell( {
	flowId,
	label,
	soc,
	hasSoc,
	capLabel,
	charging,
	discharging,
	unavailable,
	eta,
} ) {
	const tone = batteryTone( hasSoc ? soc : NaN );
	const classes = [
		'teslogic-pack-cell',
		charging ? 'is-charging' : '',
		discharging ? 'is-discharging' : '',
		unavailable ? 'is-unavailable' : '',
		tone.className,
	].filter( Boolean ).join( ' ' );

	return (
		<div
			className={ classes }
			data-flow-id={ flowId }
			style={ hasSoc ? { '--battery-level': soc, '--batt-tone': tone.color } : undefined }
		>
			<div className="teslogic-pack-cell__head">
				<span className="teslogic-pack-cell__label">{ label }</span>
				<strong className="teslogic-pack-cell__soc">{ hasSoc ? formatSoc( soc ) : '—' }</strong>
			</div>
			<span
				className="teslogic-pack-cell__bar"
				aria-hidden="true"
			>
				<span className="teslogic-pack-cell__fill" />
			</span>
			<small className="teslogic-pack-cell__cap">{ capLabel }</small>
			{ eta }
		</div>
	);
}

function ExtraLines( { lines } ) {
	if ( ! lines?.length ) {
		return null;
	}

	return (
		<div className="teslogic-card__extras">
			{ lines.map( ( line ) => (
				<small key={ line }>{ line }</small>
			) ) }
		</div>
	);
}

function DualFlowDiagram( { status, labels, liveYen, liveSolar, liveUsage, liveBuy } ) {
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
	const deltaMissing = isDeltaMqttMissing( status );
	const upsLive = status.ups_source === 'ecoflow' || status.ups_source === 'switchbot';
	const na = typeof window !== 'undefined' && window.gamingHubT ? window.gamingHubT( 'n/a' ) : 'n/a';
	const extraCharging = ! extraMissing && ! deltaMissing && (
		extra.eta_mode === 'charge' || !! extra.is_charging
	);
	const extraDischarging = ! extraMissing && ! deltaMissing && (
		extra.eta_mode === 'discharge' || !! extra.is_discharging
	);

	const proSoc = parseSoc( pro.battery_percent );
	const hasProSoc = proSoc !== null;
	const proAcOut = Number( pro.ac_out ) || 0;
	// Pack Input/Output = port totals (EcoFlow app style). Port cards keep the same meters.
	const proInW = Math.max(
		asWatts( pro.input_total ),
		asWatts( pro.input_watts ),
		asWatts( proGrid.watts ) + asWatts( hvWatts )
	);
	const proOutW = Math.max(
		asWatts( pro.output_total ),
		asWatts( pro.output_watts ),
		asWatts( roomWatts ),
		asWatts( proAcOut )
	);
	const proCharging = pro.eta_mode === 'charge'
		|| ( pro.eta_mode !== 'discharge' && !! pro.is_charging );
	const proDischarging = pro.eta_mode === 'discharge'
		|| ( pro.eta_mode !== 'charge' && ! proCharging && (
			!! pro.is_discharging || Math.max( proOutW, roomWatts, proAcOut ) >= FLOW_THRESHOLD
		) );
	const proCurrentW = proCharging ? proInW : proOutW;
	const proTodayKwh = proCharging
		? whToKwh( liveBuy?.pro ) + whToKwh( liveSolar?.pro )
		: whToKwh( liveUsage?.room );
	const proFullWh = Number( pro.capacity_wh );
	const proRemainWh = Number.isFinite( Number( pro.remain_capacity ) )
		? Number( pro.remain_capacity )
		: ( hasProSoc && Number.isFinite( proFullWh ) ? proFullWh * proSoc / 100 : null );
	const proPackLabel = Number.isFinite( proFullWh ) && proFullWh > 0 ? formatPack( proRemainWh, proFullWh ) : '';

	const deltaSocNum = parseSoc( delta.battery_percent );
	const hasDeltaSoc = ! deltaMissing && deltaSocNum !== null;
	const deltaAcOut = Number( delta.ac_out ) || 0;
	// Prefer Low Volt + AC meters when present (incl. 0). Sticky powInSumW /
	// input_total otherwise leaves a ghost Input while feeds are idle.
	const deltaFeedIn = asWatts( deltaAcIn ) + asWatts( solarWatts );
	const deltaHasFeedMeters = ( delta.solar_in !== null && delta.solar_in !== undefined )
		|| ( delta.ac_in !== null && delta.ac_in !== undefined );
	const deltaInW = deltaHasFeedMeters
		? deltaFeedIn
		: Math.max(
			asWatts( delta.input_total ),
			asWatts( delta.input_watts ),
			deltaFeedIn
		);
	const deltaOutW = Math.max(
		asWatts( delta.output_total ),
		asWatts( delta.output_watts ),
		asWatts( upsWatts ),
		asWatts( deltaAcOut )
	);
	const deltaCharging = ! deltaMissing && (
		delta.eta_mode === 'charge'
		|| ( delta.eta_mode !== 'discharge' && !! delta.is_charging )
	);
	const deltaDischarging = ! deltaMissing && (
		delta.eta_mode === 'discharge'
		|| ( delta.eta_mode !== 'charge' && ! deltaCharging && (
			!! delta.is_discharging
			|| Math.max( deltaOutW, asWatts( upsWatts ), deltaAcOut ) >= FLOW_THRESHOLD
		) )
	);
	const deltaCurrentW = deltaCharging ? deltaInW : deltaOutW;
	const deltaTodayKwh = deltaCharging
		? whToKwh( liveBuy?.delta ) + whToKwh( liveSolar?.delta )
		: whToKwh( liveUsage?.ups );
	const mainFullWh = Number.isFinite( Number( delta.capacity_wh ) ) && Number( delta.capacity_wh ) > 0
		? Number( delta.capacity_wh )
		: 1500;
	const unitFullWh = mainFullWh + ( extraMissing ? 0 : extraCap );
	const mainRemainWh = Number.isFinite( Number( delta.remain_capacity ) )
		? Number( delta.remain_capacity )
		: ( hasDeltaSoc ? mainFullWh * deltaSocNum / 100 : null );
	const extraRemainWh = ! extraMissing && Number.isFinite( Number( extra.remain_capacity ) )
		? Number( extra.remain_capacity )
		: ( ! extraMissing && extraSoc !== null ? extraCap * extraSoc / 100 : 0 );
	const unitRemainWh = mainRemainWh === null
		? null
		: mainRemainWh + ( extraMissing ? 0 : extraRemainWh );
	const mainSoc = hasDeltaSoc ? deltaSocNum : null;
	const hasMainSoc = hasDeltaSoc;
	const unitSoc = ( unitRemainWh !== null && unitFullWh > 0 )
		? Math.max( 0, Math.min( 100, ( unitRemainWh / unitFullWh ) * 100 ) )
		: deltaSocNum;
	const hasUnitSoc = ! deltaMissing && unitSoc !== null;
	const deltaPackLabel = deltaMissing
		? ( typeof window !== 'undefined' && window.gamingHubT ? window.gamingHubT( 'n/a' ) : 'n/a' )
		: ( unitFullWh > 0 ? formatPack( unitRemainWh, unitFullWh ) : '' );
	const mainCapLabel = deltaMissing
		? na
		: formatPack( mainRemainWh, mainFullWh );
	const extraCapLabel = ( extraMissing || deltaMissing )
		? na
		: formatPack( extraRemainWh, extraCap );
	const unitCharging = deltaCharging || extraCharging;
	const unitDischarging = ! unitCharging && ( deltaDischarging || extraDischarging );

	return (
		<div className="ecoflow-dual-layout is-independent teslogic-dual">
			<section className="teslogic-system ecoflow-teslogic-system" aria-label={ labels.pro }>
				<p className="teslogic-title">{ labels.pro }</p>

				<div className="teslogic-top">
					<FlowCard
						flowId="grid"
						className={ `teslogic-card--grid teslogic-card--icon-lg${ isFlowActive( 'grid', status ) ? ' is-inputting' : '' }` }
						label={ labels.gridCharge || labels.grid }
						icon={ isFlowActive( 'grid', status ) ? '⚡' : '🔌' }
						active={ isFlowActive( 'grid', status ) }
						currentLabel="Current"
						currentValue={ formatWattsExact( proGrid.watts ) }
						totalLabel="Today"
						totalValue={ formatTodayKw( liveBuy?.pro ) }
						extra={ (
							<ExtraLines
								lines={ [
									formatYenInt( liveYen?.proGrid ),
									proGrid.message || null,
								].filter( Boolean ) }
							/>
						) }
					/>

					<PackBatteryCard
						flowId="pro"
						label={ labels.pro }
						soc={ proSoc }
						hasSoc={ hasProSoc }
						charging={ proCharging }
						discharging={ proDischarging }
						unavailable={ false }
						showIo
						inputW={ proInW }
						outputW={ proOutW }
						currentW={ proCurrentW }
						totalKwh={ proTodayKwh }
						stateLabel={ pro.charge_state || '—' }
						packLabel={ proPackLabel }
						eta={ <PackEta device={ pro } /> }
					/>

					<FlowCard
						flowId="hv"
						className={ `teslogic-card--hv teslogic-card--icon-lg${ isFlowActive( 'hv', status ) ? ' is-inputting' : '' }` }
						label={ labels.hv || 'ハイボルト' }
						icon={ isFlowActive( 'hv', status ) ? '☀️' : '🔆' }
						active={ isFlowActive( 'hv', status ) }
						currentLabel="Current"
						currentValue={ formatWattsExact( hvWatts ) }
						totalLabel="Today"
						totalValue={ formatTodayKw( liveSolar?.pro ) }
					/>
				</div>

				<div className="teslogic-bottom teslogic-bottom--single">
					<FlowCard
						flowId="home"
						className={ `teslogic-card--home teslogic-card--icon-lg${ isFlowActive( 'proToHome', status ) ? ' is-outputting' : '' }` }
						label={ labels.home }
						icon={ isFlowActive( 'proToHome', status ) ? '❄️' : '🌤' }
						active={ isFlowActive( 'proToHome', status ) }
						currentLabel="Current"
						currentValue={ formatWattsExact( roomWatts ) }
						totalLabel="Today"
						totalValue={ formatTodayKw( liveUsage?.room ) }
						extra={ (
							<ExtraLines
								lines={ [
									`${ labels.todaySave || '今日 節約' } ${ formatYenInt( liveYen?.room ) }`,
								] }
							/>
						) }
					/>
				</div>
			</section>

			<section className="teslogic-system ecoflow-teslogic-system ecoflow-teslogic-system--delta" aria-label={ labels.delta }>
				<p className="teslogic-title">{ labels.delta }</p>

				<div className="teslogic-top teslogic-top--single">
					<PackBatteryCard
						flowId="delta"
						label=""
						soc={ unitSoc }
						hasSoc={ hasUnitSoc }
						charging={ unitCharging }
						discharging={ unitDischarging }
						unavailable={ deltaMissing }
						showIo
						inputW={ deltaInW }
						outputW={ deltaOutW }
						currentW={ deltaCurrentW }
						totalKwh={ deltaTodayKwh }
						stateLabel={ deltaMissing ? na : ( delta.charge_state || '—' ) }
						packLabel={ deltaPackLabel }
						eta={ deltaMissing ? null : <PackEta device={ delta } /> }
					>
						<div className="teslogic-pack-unit" aria-label={ labels.delta }>
							<PackCell
								flowId="delta-main"
								label={ labels.mainPack || 'Main pack' }
								soc={ mainSoc }
								hasSoc={ hasMainSoc }
								capLabel={ mainCapLabel }
								charging={ deltaCharging }
								discharging={ deltaDischarging && ! deltaCharging }
								unavailable={ deltaMissing }
							/>
							<PackCell
								flowId="extra"
								label={ labels.extra || 'Extra Battery 1kW' }
								soc={ extraSoc }
								hasSoc={ ! extraMissing && ! deltaMissing }
								capLabel={ extraCapLabel }
								charging={ extraCharging }
								discharging={ extraDischarging }
								unavailable={ extraMissing || deltaMissing }
							/>
						</div>
					</PackBatteryCard>
				</div>

				<div className="teslogic-bottom teslogic-bottom--triple">
					<FlowCard
						flowId="deltaGrid"
						className={ `teslogic-card--grid teslogic-card--icon-lg${ ! deltaMissing && isFlowActive( 'deltaGrid', status ) ? ' is-inputting' : '' }` }
						label={ labels.deltaGrid || 'グリッド AC 入力' }
						icon="⚡"
						active={ ! deltaMissing && isFlowActive( 'deltaGrid', status ) }
						unavailable={ deltaMissing }
						currentLabel="Current"
						currentValue={ deltaMissing ? na : formatWattsExact( deltaAcIn ) }
						totalLabel="Today"
						totalValue={ deltaMissing ? na : formatTodayKw( liveBuy?.delta ) }
						extra={ deltaMissing ? null : (
							<ExtraLines
								lines={ [ formatYenInt( liveYen?.grid ) ] }
							/>
						) }
					/>

					<FlowCard
						flowId="solar"
						className={ `teslogic-card--hv teslogic-card--icon-lg${ ! deltaMissing && isFlowActive( 'solar', status ) ? ' is-inputting' : '' }` }
						label={ labels.solar }
						icon={ ! deltaMissing && isFlowActive( 'solar', status ) ? '☀️' : '🔆' }
						active={ ! deltaMissing && isFlowActive( 'solar', status ) }
						unavailable={ solarWatts === null || solarWatts === undefined || deltaMissing }
						currentLabel="Current"
						currentValue={ ( solarWatts === null || solarWatts === undefined || deltaMissing ) ? na : formatWattsExact( solarWatts ) }
						totalLabel="Today"
						totalValue={ ( solarWatts === null || solarWatts === undefined || deltaMissing ) ? na : formatTodayKw( liveSolar?.delta ) }
					/>

					<FlowCard
						flowId="ups"
						className={ `teslogic-card--home teslogic-card--icon-lg${ isFlowActive( 'deltaToUps', status ) ? ' is-outputting' : '' }` }
						label={ labels.ups || '常時稼働エリア (UPS)' }
						icon="🔌"
						active={ ! ( deltaMissing && status.ups_source !== 'switchbot' ) && isFlowActive( 'deltaToUps', status ) }
						unavailable={ deltaMissing && status.ups_source !== 'switchbot' }
						currentLabel="Current"
						currentValue={ ( deltaMissing && status.ups_source !== 'switchbot' ) ? na : formatWattsExact( upsWatts ) }
						totalLabel="Today"
						totalValue={ upsLive ? formatTodayKw( liveUsage?.ups ) : na }
						extra={ upsLive ? (
							<ExtraLines
								lines={ [
									`${ labels.todaySave || '今日 節約' } ${ formatYenInt( liveYen?.ups ) }`,
								] }
							/>
						) : null }
					/>
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
	const [ status, setStatus ] = useState( initial || {} );
	const liveYen = useLiveTodayYen( status.today_yen );
	const liveSolar = useLiveTodaySolar( status.today_solar );
	const liveUsage = useLiveTodayUsage( status.today_usage );
	const liveBuy = useLiveTodayBuy( status.today_buy );

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
			className={ `ecoflow-energy-map teslogic-map is-flow-hidden${ isDual ? ' is-dual is-gaming' : '' }` }
			data-charging={ status.is_charging ? '1' : '0' }
			data-dual={ isDual ? '1' : '0' }
			aria-label={ labels.flow }
		>
			<div className="ecoflow-energy-content">
				{ isDual ? (
					<DualFlowDiagram status={ status } labels={ labels } liveYen={ liveYen } liveSolar={ liveSolar } liveUsage={ liveUsage } liveBuy={ liveBuy } />
				) : (
					<SingleFlowDiagram status={ status } labels={ labels } />
				) }
			</div>
		</div>
	);
}
