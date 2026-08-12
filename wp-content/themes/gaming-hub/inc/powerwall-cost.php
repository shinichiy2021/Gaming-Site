<?php
/**
 * Daily electricity cost & savings (LOOOP 6 kW contract, Chubu).
 *
 * Simulates 24h load (home + Model 3), solar generation, and Powerwall
 * discharge against LOOOP hourly spot-derived prices.
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Powerwall max discharge watts used in daily cost model. */
define( 'GAMING_HUB_POWERWALL_COST_BATTERY_MAX_W', 8000 );

/**
 * Simulate one hour of energy balance (import-only grid).
 *
 * @param float $solar_w Solar generation watts.
 * @param float $load_w  Total load watts.
 * @return array{solar_self_kwh: float, battery_self_kwh: float, grid_kwh: float}
 */
function gaming_hub_powerwall_hour_energy_balance( $solar_w, $load_w ) {
	$solar_w = max( 0, $solar_w );
	$load_w  = max( 0, $load_w );

	if ( $load_w <= 0 ) {
		return array(
			'solar_self_kwh'   => 0.0,
			'battery_self_kwh' => 0.0,
			'grid_kwh'         => 0.0,
		);
	}

	if ( $solar_w >= $load_w ) {
		return array(
			'solar_self_kwh'   => $load_w / 1000.0,
			'battery_self_kwh' => 0.0,
			'grid_kwh'         => 0.0,
		);
	}

	$deficit_w    = $load_w - $solar_w;
	$battery_w    = min( $deficit_w, (float) GAMING_HUB_POWERWALL_COST_BATTERY_MAX_W );
	$grid_w       = max( 0, $deficit_w - $battery_w );

	return array(
		'solar_self_kwh'   => $solar_w / 1000.0,
		'battery_self_kwh' => $battery_w / 1000.0,
		'grid_kwh'         => $grid_w / 1000.0,
	);
}

/**
 * Calculate today's projected daily usage and savings vs no-solar scenario.
 *
 * @param bool $force_refresh Refresh LOOOP / solar caches.
 * @return array<string, mixed>|WP_Error
 */
function gaming_hub_powerwall_calculate_daily_cost( $force_refresh = false ) {
	$price_data = gaming_hub_looop_hourly_price_map_today( $force_refresh );

	if ( is_wp_error( $price_data ) ) {
		return $price_data;
	}

	$price_map      = $price_data['map'];
	$fallback_price = (float) $price_data['fallback'];
	$forecast       = $price_data['forecast'];
	$solar_profile  = gaming_hub_powerwall_solar_hourly_profile( $force_refresh );
	$solar_hours    = $solar_profile['hours'] ?? array();

	$load_kwh           = 0.0;
	$solar_gen_kwh      = 0.0;
	$solar_self_kwh     = 0.0;
	$battery_self_kwh   = 0.0;
	$grid_import_kwh    = 0.0;
	$cost_with_solar    = 0.0;
	$cost_without_solar = 0.0;

	for ( $hour = 0; $hour < 24; $hour++ ) {
		$home_w   = gaming_hub_powerwall_home_watts_for_hour( $hour );
		$model3_w = gaming_hub_powerwall_model3_watts_for_hour( $hour );
		$solar_w  = (float) ( $solar_hours[ $hour ] ?? 0 );
		$load_w   = $home_w + $model3_w;

		$hour_load_kwh = $load_w / 1000.0;
		$balance       = gaming_hub_powerwall_hour_energy_balance( $solar_w, $load_w );
		$price         = $price_map[ $hour ] ?? $fallback_price;

		$load_kwh         += $hour_load_kwh;
		$solar_gen_kwh    += $solar_w / 1000.0;
		$solar_self_kwh   += $balance['solar_self_kwh'];
		$battery_self_kwh += $balance['battery_self_kwh'];
		$grid_import_kwh  += $balance['grid_kwh'];
		$cost_with_solar    += $balance['grid_kwh'] * $price;
		$cost_without_solar += $hour_load_kwh * $price;
	}

	$saved_yen     = $cost_without_solar - $cost_with_solar;
	$saved_percent = $cost_without_solar > 0
		? round( ( $saved_yen / $cost_without_solar ) * 100, 1 )
		: 0.0;

	return array(
		'contract_kw'            => Gaming_Hub_Looop_Api::DEFAULT_CONTRACT_KW,
		'provider'               => __( '中部電力 LOOOP', 'gaming-hub' ),
		'date'                   => wp_date( 'Y-m-d' ),
		'date_label'             => wp_date( get_option( 'date_format' ) ),
		'total_kwh'              => round( $load_kwh, 2 ),
		'solar_generation_kwh'   => round( $solar_gen_kwh, 2 ),
		'solar_self_kwh'         => round( $solar_self_kwh, 2 ),
		'battery_self_kwh'       => round( $battery_self_kwh, 2 ),
		'grid_import_kwh'        => round( $grid_import_kwh, 2 ),
		'cost_with_solar_yen'    => (int) round( $cost_with_solar ),
		'cost_without_solar_yen' => (int) round( $cost_without_solar ),
		'saved_yen'              => (int) round( $saved_yen ),
		'saved_percent'          => $saved_percent,
		'avg_grid_price_yen'     => $grid_import_kwh > 0
			? round( $cost_with_solar / $grid_import_kwh, 2 )
			: null,
		'pricing_note'           => $forecast['pricing_note'] ?? '',
		'disclaimer'             => $forecast['disclaimer'] ?? '',
		'looop_updated_at'       => $forecast['updated_at'] ?? '',
		'solar_source'           => $solar_profile['source'] ?? '',
		'solar_capacity_kw'      => round( gaming_hub_powerwall_solar_capacity_w() / 1000, 1 ),
	);
}
