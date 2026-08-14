<?php
/**
 * LOOOP tag integration – Chubu electricity forecast
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require get_template_directory() . '/inc/looop-api.php';

define( 'GAMING_HUB_LOOOP_TAG_SLUG', 'looop' );
define( 'GAMING_HUB_LOOOP_CACHE_TTL', HOUR_IN_SECONDS );

/**
 * Register LOOOP post tag on theme setup.
 */
function gaming_hub_setup_looop_tag() {
	if ( get_option( 'gaming_hub_looop_tag_created' ) ) {
		return;
	}

	if ( ! term_exists( GAMING_HUB_LOOOP_TAG_SLUG, 'post_tag' ) ) {
		wp_insert_term(
			'LOOOP',
			'post_tag',
			array(
				'slug'        => GAMING_HUB_LOOOP_TAG_SLUG,
				'description' => __( 'LOOOP-style electricity price forecast for Chubu area', 'gaming-hub' ),
			)
		);
	}

	update_option( 'gaming_hub_looop_tag_created', 1 );
}
add_action( 'init', 'gaming_hub_setup_looop_tag' );

/**
 * Ensure WordPress site timezone is Japan (JEPX uses JST).
 */
function gaming_hub_setup_site_timezone() {
	if ( get_option( 'gaming_hub_site_timezone_set' ) ) {
		return;
	}

	update_option( 'timezone_string', 'Asia/Tokyo' );
	update_option( 'gmt_offset', '0' );
	update_option( 'gaming_hub_site_timezone_set', 1 );
}
add_action( 'init', 'gaming_hub_setup_site_timezone', 5 );

/**
 * Get LOOOP tag archive URL.
 */
function gaming_hub_looop_url() {
	return function_exists( 'gaming_hub_ecoflow_url' ) ? gaming_hub_ecoflow_url() : home_url( '/tag/ecoflow/' );
}

/**
 * Fetch LOOOP forecast data.
 *
 * @param bool $force_refresh Skip cache.
 * @return array<string, mixed>|WP_Error
 */
function gaming_hub_get_looop_forecast( $force_refresh = false ) {
	$api = new Gaming_Hub_Looop_Api();
	return $api->get_forecast( $force_refresh );
}

/**
 * Map today's LOOOP hourly total price (¥/kWh) by hour 0–23.
 *
 * @param bool $force_refresh Skip LOOOP cache.
 * @return array{map: array<int, float>, fallback: float, forecast: array<string, mixed>}|WP_Error
 */
function gaming_hub_looop_hourly_price_map_today( $force_refresh = false ) {
	$forecast = gaming_hub_get_looop_forecast( $force_refresh );

	if ( is_wp_error( $forecast ) ) {
		return $forecast;
	}

	$map = array();
	foreach ( $forecast['hourly_today'] ?? array() as $row ) {
		$map[ (int) $row['hour'] ] = (float) $row['total_price'];
	}

	$fallback = 0.0;
	if ( ! empty( $map ) ) {
		$fallback = array_sum( $map ) / count( $map );
	} else {
		$api    = new Gaming_Hub_Looop_Api();
		$fixed  = $api->get_fixed_cost_breakdown();
		$fallback = $fixed['total'] + 12.0;
	}

	return array(
		'map'      => $map,
		'fallback' => round( $fallback, 2 ),
		'forecast' => $forecast,
	);
}

/**
 * Forecast mark label.
 *
 * @param string $mark Mark key.
 */
function gaming_hub_looop_mark_label( $mark ) {
	$labels = array(
		'sunny'   => __( 'でんき日和', 'gaming-hub' ),
		'caution' => __( 'でんき注意報', 'gaming-hub' ),
		'alert'   => __( 'でんき警報', 'gaming-hub' ),
		'normal'  => '',
	);

	return $labels[ $mark ] ?? '';
}

/**
 * REST route for hourly refresh.
 */
function gaming_hub_register_looop_rest_route() {
	register_rest_route(
		'gaming-hub/v1',
		'/looop/forecast',
		array(
			'methods'             => 'GET',
			'callback'            => 'gaming_hub_rest_looop_forecast',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'gaming_hub_register_looop_rest_route' );

/**
 * REST callback.
 */
function gaming_hub_rest_looop_forecast() {
	$forecast = gaming_hub_get_looop_forecast( true );

	if ( is_wp_error( $forecast ) ) {
		return new WP_REST_Response(
			array(
				'success' => false,
				'message' => $forecast->get_error_message(),
			),
			500
		);
	}

	return new WP_REST_Response(
		array(
			'success'  => true,
			'forecast' => $forecast,
		),
		200
	);
}

/**
 * Old /tag/looop/ URLs go to EcoFlow.
 */
function gaming_hub_redirect_looop_tag() {
	if ( is_tag( GAMING_HUB_LOOOP_TAG_SLUG ) ) {
		wp_safe_redirect( gaming_hub_ecoflow_url(), 301 );
		exit;
	}
}
add_action( 'template_redirect', 'gaming_hub_redirect_looop_tag' );
