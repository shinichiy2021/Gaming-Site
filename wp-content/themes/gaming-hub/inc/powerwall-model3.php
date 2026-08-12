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
