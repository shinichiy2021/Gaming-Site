<?php
/**
 * Powerwall 3 + Model 3 energy flow (simulated until Tesla API is wired).
 *
 * Grid is import-only (買電) — no export to the utility grid.
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_POWERWALL_FLOW_CACHE_KEY', 'gaming_hub_powerwall_flow_v20' );
define( 'GAMING_HUB_POWERWALL_FLOW_CACHE_TTL', 30 );
define( 'GAMING_HUB_POWERWALL_SOLAR_POLL_MS', HOUR_IN_SECONDS * 1000 );

/**
 * Build a time-of-day demo profile for the flow diagram.
 *
 * @param bool $force_solar_refresh Refresh hourly weather cache.
 * @return array<string, mixed>
 */
function gaming_hub_powerwall_simulated_flow( $force_solar_refresh = false ) {
	$hour   = (int) wp_date( 'G' );
	$minute = (int) wp_date( 'i' );
	$time   = $hour + ( $minute / 60 );

	$solar_capacity = gaming_hub_powerwall_solar_capacity_w();
	$solar_data     = gaming_hub_powerwall_get_solar_generation( $force_solar_refresh );
	$solar          = (float) ( $solar_data['watts'] ?? 0 );

	$home_data = gaming_hub_powerwall_get_home_load();
	$home      = (float) ( $home_data['watts'] ?? 0 );

	$model3_watts    = gaming_hub_powerwall_model3_watts_at_time( $time );
	$model3_charging = $model3_watts > 0;
	$model3_soc      = gaming_hub_powerwall_model3_soc_at_time( $time );
	$model3_meta     = gaming_hub_powerwall_model3_demo_meta();

	$load = $home + $model3_watts;

	$soc = 48 + ( 32 * sin( ( ( $time - 8 ) / 24 ) * 2 * M_PI ) );
	$soc = max( 12, min( 98, $soc ) );

	$grid_import        = 0.0;
	$solar_to_powerwall = 0.0;
	$powerwall_watts    = 0.0;
	$is_charging        = false;
	$is_discharging     = false;
	$charge_state       = __( '待機中', 'gaming-hub' );

	if ( $solar >= $load ) {
		$excess             = $solar - $load;
		$solar_to_powerwall = min( $excess, 4500 );
		$powerwall_watts    = $solar_to_powerwall;
		$is_charging        = $solar_to_powerwall >= 80;
		$charge_state       = $is_charging ? __( '充電中', 'gaming-hub' ) : __( '待機中', 'gaming-hub' );
	} else {
		$deficit         = $load - $solar;
		$from_battery    = min( $deficit, 8000 );
		$grid_import     = max( 0, $deficit - $from_battery );
		$powerwall_watts = $from_battery;
		$is_discharging  = $from_battery >= 80;
		$charge_state    = $is_discharging ? __( '放電中', 'gaming-hub' ) : __( '待機中', 'gaming-hub' );

		if ( $grid_import >= 80 && ! $is_discharging ) {
			$charge_state = __( 'グリッド充電', 'gaming-hub' );
		} elseif ( $grid_import >= 80 && $is_discharging ) {
			$charge_state = __( '放電中', 'gaming-hub' );
		}
	}

	return array(
		'solar_w'            => round( $solar ),
		'solar_capacity_w'   => $solar_capacity,
		'solar_meta'         => $solar_data,
		'home_w'             => round( $home ),
		'home_meta'            => $home_data['meta'] ?? array(),
		'grid_export_w'      => 0,
		'grid_import_w'      => round( $grid_import ),
		'grid_buy_only'      => true,
		'powerwall'          => array(
			'battery_percent' => (int) round( $soc ),
			'is_charging'     => $is_charging,
			'is_discharging'  => $is_discharging,
			'charge_state'    => $charge_state,
			'watts'           => round( abs( $powerwall_watts ) ),
		),
		'model3'             => gaming_hub_powerwall_model3_present(
			gaming_hub_powerwall_model3_demo_status( $model3_soc, $model3_charging, $model3_watts )
		),
		'model3_meta'        => $model3_meta,
		'solar_to_powerwall' => round( $solar_to_powerwall ),
		'simulated'          => true,
		'updated_at'         => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
	);
}

/**
 * Fetch cached Powerwall flow status.
 *
 * @param bool $force_refresh Skip transient cache.
 * @return array<string, mixed>
 */
function gaming_hub_get_powerwall_flow_status( $force_refresh = false ) {
	if ( ! $force_refresh ) {
		$cached = get_transient( GAMING_HUB_POWERWALL_FLOW_CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$status = gaming_hub_powerwall_simulated_flow( $force_refresh );

	if ( gaming_hub_tesla_model3_is_configured() ) {
		$model3 = gaming_hub_fetch_tesla_model3_status();

		if ( is_wp_error( $model3 ) ) {
			$status['model3_error'] = gaming_hub_tesla_user_facing_error( $model3 );
			$status['model3_source'] = 'simulated';
		} else {
			$status['model3']        = $model3;
			$status['model3_source'] = 'tesla';
			$status                  = gaming_hub_powerwall_recalc_flow_load( $status );
			$status['simulated']     = false;
		}
	} else {
		$status['model3_source'] = 'simulated';
		$status['model3_meta']   = gaming_hub_powerwall_model3_demo_meta();
	}

	if ( isset( $status['model3'] ) && is_array( $status['model3'] ) ) {
		$status['model3'] = gaming_hub_powerwall_model3_with_combo( $status['model3'], $status );
	}

	if ( function_exists( 'gaming_hub_tesla_vehicle_flow_payload' ) ) {
		$status['tesla_flow'] = gaming_hub_tesla_vehicle_flow_payload(
			is_array( $status['model3'] ?? null ) ? $status['model3'] : array(),
			(string) ( $status['model3_source'] ?? 'simulated' )
		);
	}

	$status['tesla_drive_ready'] = ! empty( $status['model3']['drive_ready'] );
	$status['tesla_asleep']      = ! empty( $status['model3']['asleep'] );
	$status['tesla_needs_location_scope'] = function_exists( 'gaming_hub_tesla_has_location_scope' )
		&& 'tesla' === (string) ( $status['model3_source'] ?? '' )
		&& ! gaming_hub_tesla_has_location_scope();
	$status['tesla_link_note'] = function_exists( 'gaming_hub_tesla_link_note' )
		? gaming_hub_tesla_link_note( $status )
		: '';

	$status['updated_at'] = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );

	$cost = gaming_hub_powerwall_calculate_daily_cost( $force_refresh );
	if ( is_wp_error( $cost ) ) {
		$status['cost_error'] = $cost->get_error_message();
	} else {
		$status['cost_meta'] = $cost;
	}

	set_transient( GAMING_HUB_POWERWALL_FLOW_CACHE_KEY, $status, GAMING_HUB_POWERWALL_FLOW_CACHE_TTL );

	return $status;
}

/**
 * Payload for the React flow mount node.
 *
 * @param array<string, mixed> $status Flow status.
 * @return array<string, mixed>
 */
function gaming_hub_powerwall_flow_payload( array $status ) {
	return array(
		'solar_w'          => (int) ( $status['solar_w'] ?? 0 ),
		'solar_capacity_w' => (int) ( $status['solar_capacity_w'] ?? gaming_hub_powerwall_solar_capacity_w() ),
		'solar_meta'       => $status['solar_meta'] ?? array(),
		'home_w'           => (int) ( $status['home_w'] ?? 0 ),
		'home_meta'        => $status['home_meta'] ?? array(),
		'grid_export_w' => 0,
		'grid_import_w' => (int) ( $status['grid_import_w'] ?? 0 ),
		'grid_buy_only' => true,
		'powerwall'     => $status['powerwall'] ?? array(),
		'model3'        => gaming_hub_powerwall_model3_present(
			is_array( $status['model3'] ?? null ) ? $status['model3'] : array()
		),
		'model3_source' => $status['model3_source'] ?? 'simulated',
		'simulated'     => ! empty( $status['simulated'] ),
		'tesla'         => $status['tesla_flow'] ?? ( function_exists( 'gaming_hub_tesla_vehicle_flow_payload' )
			? gaming_hub_tesla_vehicle_flow_payload(
				is_array( $status['model3'] ?? null ) ? $status['model3'] : array(),
				(string) ( $status['model3_source'] ?? 'simulated' )
			)
			: array() ),
	);
}

/**
 * REST: GET /gaming-hub/v1/powerwall/flow
 */
function gaming_hub_register_powerwall_flow_rest_route() {
	register_rest_route(
		'gaming-hub/v1',
		'/powerwall/flow',
		array(
			'methods'             => 'GET',
			'callback'            => 'gaming_hub_rest_powerwall_flow',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'gaming_hub_register_powerwall_flow_rest_route' );

/**
 * REST callback for Powerwall flow refresh.
 */
function gaming_hub_rest_powerwall_flow() {
	$status = gaming_hub_get_powerwall_flow_status( true );

	return new WP_REST_Response(
		array(
			'success' => true,
			'data'    => array_merge(
				$status,
				array( 'flow' => gaming_hub_powerwall_flow_payload( $status ) )
			),
		),
		200
	);
}

/**
 * Enqueue Powerwall flow scripts on the Powerwall page.
 */
function gaming_hub_powerwall_flow_scripts() {
	if ( ! is_front_page() && ! is_page( 'powerwall' ) ) {
		return;
	}

	wp_enqueue_script(
		'gaming-hub-powerwall-flow',
		get_template_directory_uri() . '/assets/js/powerwall-flow.js',
		array( 'gaming-hub-i18n' ),
		GAMING_HUB_VERSION,
		true
	);

	$tesla_assets = function_exists( 'gaming_hub_tesla_vehicle_flow_assets' )
		? gaming_hub_tesla_vehicle_flow_assets()
		: array( 'labels' => array(), 'images' => array() );

	wp_enqueue_script(
		'gaming-hub-tesla-flow',
		get_template_directory_uri() . '/assets/js/tesla-flow.js',
		array( 'gaming-hub-i18n' ),
		GAMING_HUB_VERSION,
		true
	);

	wp_localize_script(
		'gaming-hub-tesla-flow',
		'gamingHubTeslaFlow',
		$tesla_assets
	);

	wp_localize_script(
		'gaming-hub-powerwall-flow',
		'gamingHubPowerwallFlow',
		array(
			'labels' => array(
				'solar'     => __( 'ソーラー (1.5kW)', 'gaming-hub' ),
				'powerwall' => __( 'Powerwall 3', 'gaming-hub' ),
				'home'      => __( 'ホーム', 'gaming-hub' ),
				'model3'    => __( 'Model 3', 'gaming-hub' ),
				'grid'      => __( 'グリッド', 'gaming-hub' ),
				'gridNote'  => __( '買電のみ', 'gaming-hub' ),
				'flow'      => __( '電力フロー', 'gaming-hub' ),
				'import'    => __( '買電', 'gaming-hub' ),
				'simulated' => __( '多治見市・天気連動シミュレーション', 'gaming-hub' ),
			),
			'images' => array(
				'solar'     => get_template_directory_uri() . '/assets/images/tesla-solar-gaming.jpg',
				'powerwall' => get_template_directory_uri() . '/assets/images/tesla-powerwall-gaming.jpg',
				'model3'    => get_template_directory_uri() . '/assets/images/tesla-model3-gaming.jpg',
				'grid'      => get_template_directory_uri() . '/assets/images/tesla-grid-gaming.jpg',
				'home'      => get_template_directory_uri() . '/assets/images/ecoflow-room-gaming.jpg',
			),
		)
	);

	wp_enqueue_script(
		'gaming-hub-powerwall-dashboard',
		get_template_directory_uri() . '/assets/js/powerwall-dashboard.js',
		array( 'gaming-hub-active-refresh', 'gaming-hub-i18n', 'gaming-hub-powerwall-flow', 'gaming-hub-tesla-flow' ),
		GAMING_HUB_VERSION,
		true
	);

	wp_localize_script(
		'gaming-hub-powerwall-dashboard',
		'gamingHubPowerwall',
		array(
			'refreshUrl'   => rest_url( 'gaming-hub/v1/powerwall/flow' ),
			'interval'     => GAMING_HUB_POWERWALL_FLOW_CACHE_TTL * 1000,
			'solarInterval'=> GAMING_HUB_POWERWALL_SOLAR_POLL_MS,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'gaming_hub_powerwall_flow_scripts' );
