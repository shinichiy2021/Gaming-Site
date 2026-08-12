<?php
/**
 * Home load simulation for Powerwall flow (3-adult household).
 *
 * Based on typical Japanese 3-person household daily use (~10.5 kWh/day)
 * with hourly peaks for morning / evening routines and seasonal AC/heating.
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_POWERWALL_HOME_DAILY_KWH', 10.5 );

/**
 * Relative hourly load shape (24h, sums to 24.0).
 *
 * @return array<int, float>
 */
function gaming_hub_powerwall_home_hourly_weights() {
	return array(
		0  => 0.42,
		1  => 0.38,
		2  => 0.36,
		3  => 0.36,
		4  => 0.38,
		5  => 0.48,
		6  => 0.92,
		7  => 1.35,
		8  => 1.18,
		9  => 0.72,
		10 => 0.62,
		11 => 0.68,
		12 => 0.95,
		13 => 0.78,
		14 => 0.62,
		15 => 0.62,
		16 => 0.72,
		17 => 1.05,
		18 => 1.48,
		19 => 1.62,
		20 => 1.55,
		21 => 1.28,
		22 => 0.88,
		23 => 0.58,
	);
}

/**
 * Seasonal multiplier for Tajimi (AC in summer, heating in winter).
 *
 * @param int $month Month 1–12.
 */
function gaming_hub_powerwall_home_seasonal_factor( $month ) {
	$factors = array(
		1  => 1.22,
		2  => 1.18,
		3  => 0.96,
		4  => 0.92,
		5  => 0.96,
		6  => 1.08,
		7  => 1.38,
		8  => 1.42,
		9  => 1.08,
		10 => 0.96,
		11 => 0.96,
		12 => 1.18,
	);

	return $factors[ $month ] ?? 1.0;
}

/**
 * Label for the current time band.
 *
 * @param int $hour Hour 0–23.
 */
function gaming_hub_powerwall_home_time_band( $hour ) {
	if ( $hour >= 6 && $hour <= 8 ) {
		return __( '朝のピーク', 'gaming-hub' );
	}

	if ( $hour >= 18 && $hour <= 21 ) {
		return __( '夕方のピーク', 'gaming-hub' );
	}

	if ( $hour >= 22 || $hour <= 5 ) {
		return __( '深夜・早朝', 'gaming-hub' );
	}

	if ( $hour >= 9 && $hour <= 16 ) {
		return __( '昼間', 'gaming-hub' );
	}

	return __( '平常時', 'gaming-hub' );
}

/**
 * Simulated home consumption for an average 3-adult household.
 *
 * @return array{watts: int, meta: array<string, mixed>}
 */
function gaming_hub_powerwall_get_home_load() {
	$hour       = (int) wp_date( 'G' );
	$month      = (int) wp_date( 'n' );
	$day_of_week = (int) wp_date( 'N' );
	$weights    = gaming_hub_powerwall_home_hourly_weights();
	$weight_sum = array_sum( $weights );

	$daily_kwh = GAMING_HUB_POWERWALL_HOME_DAILY_KWH;
	$daily_kwh *= gaming_hub_powerwall_home_seasonal_factor( $month );

	if ( $day_of_week >= 6 ) {
		$daily_kwh *= 1.08;
	}

	$hour_wh = ( $daily_kwh * 1000 ) * ( $weights[ $hour ] / $weight_sum );
	$watts   = (int) round( $hour_wh );

	return array(
		'watts' => $watts,
		'meta'  => array(
			'occupants'      => 3,
			'profile'        => __( '大人3人世帯（平均）', 'gaming-hub' ),
			'daily_kwh'      => round( $daily_kwh, 1 ),
			'hour_slot'      => wp_date( 'Y-m-d H:00' ),
			'time_band'      => gaming_hub_powerwall_home_time_band( $hour ),
			'seasonal_factor'=> gaming_hub_powerwall_home_seasonal_factor( $month ),
			'is_weekend'     => $day_of_week >= 6,
		),
	);
}

/**
 * Home load watts for a specific hour (daily cost simulation).
 *
 * @param int      $hour      Hour 0–23.
 * @param int|null $timestamp Reference day (defaults to today in site TZ).
 */
function gaming_hub_powerwall_home_watts_for_hour( $hour, $timestamp = null ) {
	$timestamp   = $timestamp ?? time();
	$month       = (int) wp_date( 'n', $timestamp );
	$day_of_week = (int) wp_date( 'N', $timestamp );
	$weights     = gaming_hub_powerwall_home_hourly_weights();
	$weight_sum  = array_sum( $weights );

	$daily_kwh = GAMING_HUB_POWERWALL_HOME_DAILY_KWH;
	$daily_kwh *= gaming_hub_powerwall_home_seasonal_factor( $month );

	if ( $day_of_week >= 6 ) {
		$daily_kwh *= 1.08;
	}

	$hour_wh = ( $daily_kwh * 1000 ) * ( $weights[ $hour ] / $weight_sum );

	return (int) round( $hour_wh );
}
