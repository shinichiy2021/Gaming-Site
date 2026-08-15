<?php
/**
 * Model 3 demo simulation (30 km/day average driving).
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Average daily driving distance (km). */
define( 'GAMING_HUB_MODEL3_DAILY_KM', 30 );

/** Typical Model 3 consumption (Wh/km, mixed driving). */
define( 'GAMING_HUB_MODEL3_WH_PER_KM', 150 );

/** Assumed usable battery capacity for SOC demo (kWh). */
define( 'GAMING_HUB_MODEL3_BATTERY_KWH', 60 );

/** Evening home-charging window start (fractional hour). */
define( 'GAMING_HUB_MODEL3_CHARGE_START', 17.0 );

/** Evening home-charging window end (fractional hour). */
define( 'GAMING_HUB_MODEL3_CHARGE_END', 22.5 );

/**
 * Daily energy to replenish after 30 km driving.
 */
function gaming_hub_powerwall_model3_daily_kwh() {
	return ( GAMING_HUB_MODEL3_DAILY_KM * GAMING_HUB_MODEL3_WH_PER_KM ) / 1000.0;
}

/**
 * Hours in the evening charging window.
 */
function gaming_hub_powerwall_model3_charge_duration_hours() {
	return GAMING_HUB_MODEL3_CHARGE_END - GAMING_HUB_MODEL3_CHARGE_START;
}

/**
 * Constant charge power to deliver daily_kwh within the evening window.
 */
function gaming_hub_powerwall_model3_charge_watts() {
	$duration = gaming_hub_powerwall_model3_charge_duration_hours();
	if ( $duration <= 0 ) {
		return 0;
	}

	return (int) round( ( gaming_hub_powerwall_model3_daily_kwh() * 1000 ) / $duration );
}

/**
 * Whether fractional local time is within the home-charging window.
 *
 * @param float $time Hour + minute/60 (0–24).
 */
function gaming_hub_powerwall_model3_is_charging_time( $time ) {
	return $time >= GAMING_HUB_MODEL3_CHARGE_START && $time <= GAMING_HUB_MODEL3_CHARGE_END;
}

/**
 * Model 3 charging watts for hourly cost simulation.
 *
 * @param int $hour Hour 0–23.
 */
function gaming_hub_powerwall_model3_watts_for_hour( $hour ) {
	$charge_w = gaming_hub_powerwall_model3_charge_watts();

	if ( $hour >= 17 && $hour <= 21 ) {
		return $charge_w;
	}

	if ( 22 === $hour ) {
		return (int) round( $charge_w * 0.5 );
	}

	return 0;
}

/**
 * Live charge watts at fractional local time.
 *
 * @param float $time Hour + minute/60.
 */
function gaming_hub_powerwall_model3_watts_at_time( $time ) {
	if ( ! gaming_hub_powerwall_model3_is_charging_time( $time ) ) {
		return 0;
	}

	return (float) gaming_hub_powerwall_model3_charge_watts();
}

/**
 * Demo SOC after daily 30 km drive, rising during evening charge.
 *
 * @param float $time Hour + minute/60.
 */
function gaming_hub_powerwall_model3_soc_at_time( $time ) {
	$base_soc = 52;
	$gain     = ( gaming_hub_powerwall_model3_daily_kwh() / GAMING_HUB_MODEL3_BATTERY_KWH ) * 100;

	if ( ! gaming_hub_powerwall_model3_is_charging_time( $time ) ) {
		return (int) round( $base_soc );
	}

	$duration = gaming_hub_powerwall_model3_charge_duration_hours();
	$progress = ( $time - GAMING_HUB_MODEL3_CHARGE_START ) / $duration;

	return (int) round( min( 95, $base_soc + ( $progress * $gain ) ) );
}

/**
 * Metadata for dashboard display.
 *
 * @return array<string, mixed>
 */
function gaming_hub_powerwall_model3_demo_meta() {
	return array(
		'daily_km'      => GAMING_HUB_MODEL3_DAILY_KM,
		'daily_kwh'     => round( gaming_hub_powerwall_model3_daily_kwh(), 1 ),
		'wh_per_km'     => GAMING_HUB_MODEL3_WH_PER_KM,
		'charge_window' => '17:00–22:30',
		'charge_watts'  => gaming_hub_powerwall_model3_charge_watts(),
		'profile'       => sprintf(
			/* translators: 1: daily km, 2: daily kWh */
			__( '1日平均 %1$s km', 'gaming-hub' ),
			number_format_i18n( GAMING_HUB_MODEL3_DAILY_KM )
		),
	);
}

/**
 * Format minutes as a short Japanese duration label.
 *
 * @param int $minutes Duration in minutes.
 */
function gaming_hub_format_duration_minutes( $minutes ) {
	$minutes = max( 0, (int) $minutes );

	if ( $minutes <= 0 ) {
		return '—';
	}

	$hours = intdiv( $minutes, 60 );
	$mins  = $minutes % 60;

	if ( $hours > 0 && $mins > 0 ) {
		return sprintf(
			/* translators: 1: hours, 2: minutes */
			__( '約 %1$s時間%2$s分', 'gaming-hub' ),
			number_format_i18n( $hours ),
			number_format_i18n( $mins )
		);
	}

	if ( $hours > 0 ) {
		return sprintf(
			/* translators: %s: hours */
			__( '約 %1$s時間', 'gaming-hub' ),
			number_format_i18n( $hours )
		);
	}

	return sprintf(
		/* translators: %s: minutes */
		__( '約 %1$s分', 'gaming-hub' ),
		number_format_i18n( $mins )
	);
}

/**
 * Enrich Model 3 payload with gauge labels and charging ETA fields.
 *
 * @param array<string, mixed> $model3 Raw model3 slice.
 * @return array<string, mixed>
 */
function gaming_hub_powerwall_model3_present( array $model3 ) {
	$soc           = max( 0, min( 100, (int) ( $model3['battery_percent'] ?? 0 ) ) );
	$is_charging   = ! empty( $model3['is_charging'] );
	$watts         = max( 0, (int) ( $model3['watts'] ?? 0 ) );
	$charge_limit  = max( $soc, min( 100, (int) ( $model3['charge_limit_percent'] ?? 100 ) ) );
	$battery_kwh   = (float) ( $model3['battery_kwh_nominal'] ?? GAMING_HUB_MODEL3_BATTERY_KWH );
	$charge_rate_kw = $watts > 0
		? round( $watts / 1000, 1 )
		: round( (float) ( $model3['charge_rate_kw'] ?? 0 ), 1 );

	$minutes_to_full = null;

	if ( isset( $model3['minutes_to_full'] ) && is_numeric( $model3['minutes_to_full'] ) ) {
		$minutes_to_full = max( 0, (int) round( (float) $model3['minutes_to_full'] ) );
	} elseif ( isset( $model3['time_to_full_charge_hours'] ) && is_numeric( $model3['time_to_full_charge_hours'] ) ) {
		$hours = (float) $model3['time_to_full_charge_hours'];
		if ( $hours > 0 ) {
			$minutes_to_full = max( 0, (int) round( $hours * 60 ) );
		}
	} elseif ( $is_charging && $watts > 0 && $charge_limit > $soc ) {
		$remaining_kwh   = ( ( $charge_limit - $soc ) / 100 ) * $battery_kwh;
		$minutes_to_full = max( 0, (int) round( ( $remaining_kwh / ( $watts / 1000 ) ) * 60 ) );
	}

	$charge_eta_label      = null;
	$charge_complete_label = null;

	if ( $is_charging && null !== $minutes_to_full && $minutes_to_full > 0 ) {
		$charge_eta_label      = gaming_hub_format_duration_minutes( $minutes_to_full );
		$charge_complete_label = wp_date( 'H:i', time() + ( $minutes_to_full * MINUTE_IN_SECONDS ) ) . ' ' . __( '頃完了', 'gaming-hub' );
	}

	$usable_kwh = round( $battery_kwh * $soc / 100, 1 );

	return array_merge(
		$model3,
		array(
			'battery_percent'       => $soc,
			'charge_limit_percent'  => $charge_limit,
			'charge_rate_kw'        => $charge_rate_kw,
			'charge_rate_label'     => $charge_rate_kw > 0
				? number_format_i18n( $charge_rate_kw, 1 ) . ' kW'
				: '—',
			'minutes_to_full'       => $minutes_to_full,
			'charge_eta_label'      => $charge_eta_label,
			'charge_complete_label' => $charge_complete_label,
			'battery_kwh_nominal'   => $battery_kwh,
			'battery_kwh_estimate'  => $usable_kwh,
			'battery_kwh_label'     => number_format_i18n( $usable_kwh, 1 ) . ' kWh',
		)
	);
}
