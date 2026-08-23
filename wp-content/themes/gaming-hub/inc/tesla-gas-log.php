<?php
/**
 * Tesla gasoline-savings log (daily / hourly charts, EcoFlow-style).
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_TESLA_GAS_LOG_PREFIX', 'gaming_hub_tesla_gas_v1_' );

/**
 * Option key for one month of Tesla gas-savings days.
 *
 * @param string $ym Y-m.
 */
function gaming_hub_tesla_gas_log_key( $ym ) {
	return GAMING_HUB_TESLA_GAS_LOG_PREFIX . $ym;
}

/**
 * Convert today's km into gasoline liters and yen saved vs a 15 km/L car.
 *
 * @param float $km Distance.
 * @return array<string, float|int|string>
 */
function gaming_hub_tesla_gas_metrics_from_km( $km ) {
	$km         = max( 0, (float) $km );
	$yen_per_l  = 171.7;
	$as_of      = '';
	$price_label = '';

	if ( function_exists( 'gaming_hub_tajimi_gasoline_price' ) ) {
		$price     = gaming_hub_tajimi_gasoline_price();
		$yen_per_l = (float) ( $price['yen_per_l'] ?? $yen_per_l );
		$as_of     = (string) ( $price['as_of'] ?? '' );
	}

	$km_per_l  = defined( 'GAMING_HUB_GAS_COMPARE_KM_PER_L' ) ? (float) GAMING_HUB_GAS_COMPARE_KM_PER_L : 15.0;
	$wh_per_km = defined( 'GAMING_HUB_MODEL3_WH_PER_KM' ) ? (float) GAMING_HUB_MODEL3_WH_PER_KM : 150.0;
	$elec_yen  = function_exists( 'gaming_hub_tesla_electricity_yen_per_kwh' )
		? gaming_hub_tesla_electricity_yen_per_kwh()
		: 30.0;

	$gas_l   = $km >= 0.1 ? $km / $km_per_l : 0.0;
	$ev_kwh  = $km >= 0.1 ? ( $km * $wh_per_km ) / 1000.0 : 0.0;
	$gas_yen = $gas_l * $yen_per_l;
	$ev_yen  = $ev_kwh * $elec_yen;
	$saved   = max( 0, $gas_yen - $ev_yen );

	if ( function_exists( 'gaming_hub_tesla_gasoline_compare' ) ) {
		$empty = gaming_hub_tesla_gasoline_compare( array( 'today_km' => $km ), 0, 0 );
		$price_label = (string) ( $empty['price_label'] ?? '' );
	}

	return array(
		'km'              => round( $km, 1 ),
		'gas_l'           => round( $gas_l, 2 ),
		'ev_kwh'          => round( $ev_kwh, 2 ),
		'gas_yen'         => (int) round( $gas_yen ),
		'ev_yen'          => (int) round( $ev_yen ),
		'saved_yen'       => (int) round( $saved ),
		'yen_per_l'       => $yen_per_l,
		'elec_yen_per_kwh'=> round( (float) $elec_yen, 1 ),
		'as_of'           => $as_of,
		'price_label'     => $price_label,
	);
}

/**
 * Days stored for a month.
 *
 * @param string $ym Y-m.
 * @return array<string, array<string, mixed>>
 */
function gaming_hub_tesla_gas_log_month_days( $ym ) {
	$raw = get_option( gaming_hub_tesla_gas_log_key( $ym ), array() );

	return is_array( $raw ) ? $raw : array();
}

/**
 * Persist one month.
 *
 * @param string                          $ym   Y-m.
 * @param array<string, array<string, mixed>> $days Day map.
 */
function gaming_hub_tesla_gas_log_save_month( $ym, array $days ) {
	update_option( gaming_hub_tesla_gas_log_key( $ym ), $days, false );
}

/**
 * Write today's km into the monthly log (hourly delta when km grows).
 *
 * @param float $today_km Today's driving km.
 */
function gaming_hub_tesla_gas_log_record_today( $today_km ) {
	$today_km = max( 0, (float) $today_km );
	$today    = wp_date( 'Y-m-d' );
	$ym       = substr( $today, 0, 7 );
	$hour     = (int) wp_date( 'G' );
	$days     = gaming_hub_tesla_gas_log_month_days( $ym );
	$prev     = isset( $days[ $today ] ) && is_array( $days[ $today ] ) ? $days[ $today ] : array();
	$prev_km  = isset( $prev['km'] ) && is_numeric( $prev['km'] ) ? (float) $prev['km'] : null;
	$metrics  = gaming_hub_tesla_gas_metrics_from_km( $today_km );
	$hours    = isset( $prev['hours'] ) && is_array( $prev['hours'] ) ? $prev['hours'] : array();

	if ( null !== $prev_km ) {
		$delta = $today_km - $prev_km;
		if ( $delta >= 0.05 ) {
			$slot          = isset( $hours[ $hour ] ) && is_array( $hours[ $hour ] ) ? $hours[ $hour ] : array();
			$slice         = gaming_hub_tesla_gas_metrics_from_km( $delta );
			$slot['km']    = round( (float) ( $slot['km'] ?? 0 ) + $delta, 1 );
			$slot['gas_l'] = round( (float) ( $slot['gas_l'] ?? 0 ) + (float) $slice['gas_l'], 2 );
			$slot['saved_yen'] = (int) round( (float) ( $slot['saved_yen'] ?? 0 ) + (float) $slice['saved_yen'] );
			$hours[ $hour ] = $slot;
		}
	}

	$days[ $today ] = array_merge(
		$metrics,
		array(
			'date'       => $today,
			'hours'      => $hours,
			'updated_at' => time(),
		)
	);

	gaming_hub_tesla_gas_log_save_month( $ym, $days );
}

/**
 * Keep today's row in sync with the latest odometer snapshot.
 */
function gaming_hub_tesla_gas_log_sync_from_odo() {
	if ( ! defined( 'GAMING_HUB_MODEL3_ODO_OPTION' ) ) {
		return;
	}

	$odo = get_option( GAMING_HUB_MODEL3_ODO_OPTION, array() );
	if ( ! is_array( $odo ) || (string) ( $odo['date'] ?? '' ) !== wp_date( 'Y-m-d' ) ) {
		return;
	}

	if ( ! isset( $odo['today_km'] ) || ! is_numeric( $odo['today_km'] ) ) {
		return;
	}

	gaming_hub_tesla_gas_log_record_today( (float) $odo['today_km'] );
}

/**
 * Chart axis ticks (reuse EcoFlow helper when present).
 *
 * @param float $max Raw max.
 * @return array{0: float, 1: array<int, float>}
 */
function gaming_hub_tesla_gas_axis_ticks( $max ) {
	if ( function_exists( 'gaming_hub_ecoflow_energy_axis_ticks' ) ) {
		return gaming_hub_ecoflow_energy_axis_ticks( $max );
	}

	$max = max( 0, (float) $max );
	$top = $max > 0 ? $max : 1.0;

	return array( $top, array( $top, $top * 0.75, $top * 0.5, $top * 0.25, 0 ) );
}

/**
 * Live NOW slice for the savings HUD.
 *
 * @param array<string, mixed>|null $status Powerwall/Tesla status.
 * @return array<string, mixed>
 */
function gaming_hub_tesla_gas_now_slice( $status = null ) {
	if ( ! is_array( $status ) && function_exists( 'gaming_hub_get_powerwall_flow_status' ) ) {
		$status = gaming_hub_get_powerwall_flow_status();
	}

	$status = is_array( $status ) ? $status : array();
	$flow   = isset( $status['tesla_flow'] ) && is_array( $status['tesla_flow'] )
		? $status['tesla_flow']
		: array();
	$gas    = isset( $flow['gas'] ) && is_array( $flow['gas'] ) ? $flow['gas'] : array();
	$asleep = ! empty( $flow['asleep'] ) || ! empty( $status['tesla_asleep'] );
	$live   = ! empty( $flow['live'] );

	return array(
		'asleep'          => $asleep,
		'speed_km'        => ( $asleep || ! $live ) ? 0 : (int) ( $flow['speed_km'] ?? 0 ),
		'saved_yen_per_h' => ( $asleep || ! $live ) ? 0 : (int) ( $gas['saved_yen_per_h'] ?? 0 ),
		'today_km'        => $live ? (float) ( $gas['today_km'] ?? 0 ) : 0.0,
		'saved_yen'       => $live ? (int) ( $gas['saved_yen'] ?? 0 ) : 0,
		'gas_l'           => $live ? (float) ( $gas['gas_l'] ?? 0 ) : 0.0,
		'price_label'     => (string) ( $gas['price_label'] ?? '' ),
	);
}

/**
 * 24 hourly chart rows.
 *
 * @param array<int, mixed> $hours Hour map.
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_tesla_gas_hour_rows( $hours ) {
	$hours = is_array( $hours ) ? $hours : array();
	$rows  = array();

	for ( $h = 0; $h < 24; $h++ ) {
		$row = isset( $hours[ $h ] ) && is_array( $hours[ $h ] ) ? $hours[ $h ] : array();
		$km  = isset( $row['km'] ) ? (float) $row['km'] : null;
		$rows[] = array(
			'hour'      => $h,
			'km'        => $km,
			'gas_l'     => isset( $row['gas_l'] ) ? (float) $row['gas_l'] : null,
			'saved_yen' => isset( $row['saved_yen'] ) ? (int) $row['saved_yen'] : null,
			'has_data'  => null !== $km && $km > 0,
		);
	}

	return $rows;
}

/**
 * Month payload for the Tesla savings calendar.
 *
 * @param string                    $ym     Y-m.
 * @param array<string, mixed>|null $status Live status.
 * @return array<string, mixed>
 */
function gaming_hub_tesla_gas_month_payload( $ym, $status = null ) {
	gaming_hub_tesla_gas_log_sync_from_odo();

	$ym    = preg_match( '/^\d{4}-\d{2}$/', (string) $ym ) ? (string) $ym : wp_date( 'Y-m' );
	$today = wp_date( 'Y-m-d' );
	$start = $ym . '-01';
	$ts    = strtotime( $start . ' 00:00:00' );
	if ( ! $ts ) {
		$ym    = wp_date( 'Y-m' );
		$start = $ym . '-01';
		$ts    = strtotime( $start . ' 00:00:00' );
	}

	$days_in_month = (int) gmdate( 't', $ts );
	$start_wday    = (int) wp_date( 'w', $ts );
	$log           = gaming_hub_tesla_gas_log_month_days( $ym );
	$cells         = array();
	$totals        = array(
		'km'        => 0.0,
		'gas_l'     => 0.0,
		'ev_kwh'    => 0.0,
		'saved_yen' => 0.0,
	);

	for ( $d = 1; $d <= $days_in_month; $d++ ) {
		$date = $ym . '-' . sprintf( '%02d', $d );
		$row  = isset( $log[ $date ] ) && is_array( $log[ $date ] ) ? $log[ $date ] : null;
		$cell = array(
			'date'      => $date,
			'day'       => $d,
			'km'        => $row ? (float) ( $row['km'] ?? 0 ) : null,
			'gas_l'     => $row ? (float) ( $row['gas_l'] ?? 0 ) : null,
			'ev_kwh'    => $row ? (float) ( $row['ev_kwh'] ?? 0 ) : null,
			'gas_yen'   => $row ? (int) ( $row['gas_yen'] ?? 0 ) : null,
			'ev_yen'    => $row ? (int) ( $row['ev_yen'] ?? 0 ) : null,
			'saved_yen' => $row ? (int) ( $row['saved_yen'] ?? 0 ) : null,
			'has_data'  => (bool) $row,
			'is_today'  => $date === $today,
		);
		$cells[] = $cell;

		if ( $row ) {
			$totals['km']        += (float) ( $cell['km'] ?? 0 );
			$totals['gas_l']     += (float) ( $cell['gas_l'] ?? 0 );
			$totals['ev_kwh']    += (float) ( $cell['ev_kwh'] ?? 0 );
			$totals['saved_yen'] += (float) ( $cell['saved_yen'] ?? 0 );
		}
	}

	$max_km  = 0.0;
	$max_yen = 0.0;
	foreach ( $cells as $cell ) {
		$max_km  = max( $max_km, (float) ( $cell['km'] ?? 0 ) );
		$max_yen = max( $max_yen, abs( (float) ( $cell['saved_yen'] ?? 0 ) ) );
	}
	list( $km_max, $km_ticks )   = gaming_hub_tesla_gas_axis_ticks( $max_km );
	list( $yen_max, $yen_ticks ) = gaming_hub_tesla_gas_axis_ticks( $max_yen );

	$today_hours     = array();
	$today_km_max    = 1.0;
	$today_km_ticks  = array( 1, 0.75, 0.5, 0.25, 0 );
	$today_yen_max   = 1.0;
	$today_yen_ticks = array( 1, 0.75, 0.5, 0.25, 0 );
	if ( $ym === substr( $today, 0, 7 ) ) {
		$today_hours = gaming_hub_tesla_gas_hour_rows( $log[ $today ]['hours'] ?? array() );
		$hour_km     = 0.0;
		$hour_yen    = 0.0;
		foreach ( $today_hours as $hour_row ) {
			$hour_km  = max( $hour_km, (float) ( $hour_row['km'] ?? 0 ) );
			$hour_yen = max( $hour_yen, (float) ( $hour_row['saved_yen'] ?? 0 ) );
		}
		list( $today_km_max, $today_km_ticks )   = gaming_hub_tesla_gas_axis_ticks( $hour_km );
		list( $today_yen_max, $today_yen_ticks ) = gaming_hub_tesla_gas_axis_ticks( $hour_yen );
	}

	$now  = gaming_hub_tesla_gas_now_slice( $status );
	$prev = wp_date( 'Y-m', strtotime( $start . ' -1 month' ) );
	$next = wp_date( 'Y-m', strtotime( $start . ' +1 month' ) );
	$today_row = isset( $log[ $today ] ) && is_array( $log[ $today ] ) ? $log[ $today ] : null;

	return array(
		'month'           => $ym,
		'label'           => wp_date( 'Y年n月', $ts ),
		'today'           => $today,
		'start_wday'      => $start_wday,
		'days'            => $cells,
		'totals'          => array(
			'km'        => round( $totals['km'], 1 ),
			'gas_l'     => round( $totals['gas_l'], 2 ),
			'ev_kwh'    => round( $totals['ev_kwh'], 2 ),
			'saved_yen' => (int) round( $totals['saved_yen'] ),
		),
		'now'             => $now,
		'today_stats'     => array(
			'km'        => $today_row ? (float) ( $today_row['km'] ?? 0 ) : (float) ( $now['today_km'] ?? 0 ),
			'gas_l'     => $today_row ? (float) ( $today_row['gas_l'] ?? 0 ) : (float) ( $now['gas_l'] ?? 0 ),
			'ev_kwh'    => $today_row ? (float) ( $today_row['ev_kwh'] ?? 0 ) : 0.0,
			'gas_yen'   => $today_row ? (int) ( $today_row['gas_yen'] ?? 0 ) : 0,
			'ev_yen'    => $today_row ? (int) ( $today_row['ev_yen'] ?? 0 ) : 0,
			'saved_yen' => $today_row ? (int) ( $today_row['saved_yen'] ?? 0 ) : (int) ( $now['saved_yen'] ?? 0 ),
		),
		'prev'            => $prev,
		'next'            => $next,
		'weekdays'        => array( '日', '月', '火', '水', '木', '金', '土' ),
		'km_max'          => $km_max,
		'km_ticks'        => $km_ticks,
		'yen_max'         => $yen_max,
		'yen_ticks'       => $yen_ticks,
		'today_hours'     => $today_hours,
		'today_km_max'    => $today_km_max,
		'today_km_ticks'  => $today_km_ticks,
		'today_yen_max'   => $today_yen_max,
		'today_yen_ticks' => $today_yen_ticks,
	);
}

/**
 * Render the Tesla gasoline-savings calendar.
 *
 * @param array<string, mixed>|null $status Live status.
 */
function gaming_hub_render_tesla_gas_log( $status = null ) {
	get_template_part(
		'template-parts/tesla',
		'gas-log',
		array(
			'calendar' => gaming_hub_tesla_gas_month_payload( wp_date( 'Y-m' ), $status ),
		)
	);
}

/**
 * REST: GET /gaming-hub/v1/tesla/gas
 */
function gaming_hub_register_tesla_gas_rest() {
	register_rest_route(
		'gaming-hub/v1',
		'/tesla/gas',
		array(
			'methods'             => 'GET',
			'callback'            => 'gaming_hub_rest_tesla_gas',
			'permission_callback' => '__return_true',
			'args'                => array(
				'month' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'gaming_hub_register_tesla_gas_rest' );

/**
 * REST callback.
 *
 * @param WP_REST_Request $request Request.
 */
function gaming_hub_rest_tesla_gas( WP_REST_Request $request ) {
	$month = (string) $request->get_param( 'month' );

	return new WP_REST_Response(
		array(
			'success' => true,
			'data'    => gaming_hub_tesla_gas_month_payload( $month ),
		),
		200
	);
}

/**
 * Enqueue Tesla gas-log script on the Tesla tag.
 */
function gaming_hub_tesla_gas_log_scripts() {
	if ( ! is_tag( 'tesla' ) && ! is_page( 'powerwall' ) ) {
		return;
	}

	wp_enqueue_script(
		'gaming-hub-tesla-gas-log',
		get_template_directory_uri() . '/assets/js/tesla-gas-log.js',
		array( 'gaming-hub-i18n' ),
		GAMING_HUB_VERSION,
		true
	);

	wp_localize_script(
		'gaming-hub-tesla-gas-log',
		'gamingHubTeslaGas',
		array(
			'url' => (string) wp_parse_url( rest_url( 'gaming-hub/v1/tesla/gas' ), PHP_URL_PATH ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'gaming_hub_tesla_gas_log_scripts' );
