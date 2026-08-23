<?php
/**
 * Tesla vehicle energy flow (Wall Connector / Supercharger → battery → drive / cabin).
 *
 * Live Tesla data only. Missing watts stay standby — no demo values.
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keep a live watt reading or null when the API did not provide it.
 *
 * @param mixed $value Raw watts or null.
 * @return int|null
 */
function gaming_hub_tesla_live_watt( $value ) {
	if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
		return null;
	}

	return max( 0, (int) round( (float) $value ) );
}

/**
 * Build Tesla-only energy-flow payload from Model 3.
 *
 * @param array<string, mixed> $model3 Model 3 HUD slice.
 * @param string               $source tesla|simulated.
 * @return array<string, mixed>
 */
function gaming_hub_tesla_vehicle_flow_payload( array $model3, $source = 'simulated' ) {
	$live = 'tesla' === $source;

	if ( ! $live ) {
		return array(
			'wall_w'          => null,
			'super_w'         => null,
			'drive_w'         => null,
			'cabin_w'         => null,
			'mode'            => 'idle',
			'shift'           => 'P',
			'speed_km'        => 0,
			'climate_on'      => false,
			'sentry'          => false,
			'battery_percent' => null,
			'is_charging'     => false,
			'charge_state'    => __( '待機', 'gaming-hub' ),
			'vehicle_name'    => (string) ( $model3['vehicle_name'] ?? 'Model 3' ),
			'supply_kind'     => 'none',
			'supply_label'    => '',
			'range_label'     => '',
			'live'            => false,
			'simulated'       => false,
		);
	}

	$model3   = gaming_hub_powerwall_model3_present( $model3 );
	$charging = ! empty( $model3['is_charging'] );
	$kind     = (string) ( $model3['supply_kind'] ?? 'none' );
	$charge_w = $charging ? gaming_hub_tesla_live_watt( $model3['watts'] ?? null ) : 0;

	$wall_w  = ( $charging && 'supercharger' !== $kind ) ? $charge_w : 0;
	$super_w = ( $charging && 'supercharger' === $kind ) ? $charge_w : 0;
	$drive_w = array_key_exists( 'drive_w', $model3 ) ? gaming_hub_tesla_live_watt( $model3['drive_w'] ) : null;
	$cabin_w = array_key_exists( 'cabin_w', $model3 ) ? gaming_hub_tesla_live_watt( $model3['cabin_w'] ) : null;
	$mode    = (string) ( $model3['vehicle_mode'] ?? '' );

	if ( '' === $mode ) {
		if ( ( $super_w ?? 0 ) >= 80 ) {
			$mode = 'supercharger';
		} elseif ( ( $wall_w ?? 0 ) >= 80 ) {
			$mode = 'wall';
		} elseif ( ( $drive_w ?? 0 ) >= 80 ) {
			$mode = 'drive';
		} elseif ( ( $cabin_w ?? 0 ) >= 80 ) {
			$mode = 'cabin';
		} else {
			$mode = 'idle';
		}
	}

	$soc = isset( $model3['battery_percent'] ) && is_numeric( $model3['battery_percent'] )
		? max( 0, min( 100, (int) $model3['battery_percent'] ) )
		: null;

	return array(
		'wall_w'          => $wall_w,
		'super_w'         => $super_w,
		'drive_w'         => $drive_w,
		'cabin_w'         => $cabin_w,
		'mode'            => $mode,
		'shift'           => (string) ( $model3['shift_state'] ?? 'P' ),
		'speed_km'        => (int) ( $model3['speed_km'] ?? 0 ),
		'climate_on'      => ! empty( $model3['climate_on'] ),
		'sentry'          => ! empty( $model3['sentry_mode'] ),
		'battery_percent' => $soc,
		'is_charging'     => $charging,
		'charge_state'    => __( '待機', 'gaming-hub' ),
		'vehicle_name'    => (string) ( $model3['vehicle_name'] ?? 'Model 3' ),
		'supply_kind'     => $kind,
		'supply_label'    => (string) ( $model3['supply_label'] ?? '' ),
		'range_label'     => (string) ( $model3['range_label'] ?? '' ),
		'live'            => true,
		'simulated'       => false,
	);
}

/**
 * Tesla vehicle flow labels + image URLs.
 *
 * @return array<string, mixed>
 */
function gaming_hub_tesla_vehicle_flow_assets() {
	$base = get_template_directory_uri() . '/assets/images/';

	return array(
		'labels' => array(
			'title'      => __( 'Tesla 電力フロー', 'gaming-hub' ),
			'wall'       => __( '普通充電', 'gaming-hub' ),
			'wallNote'   => __( 'Wall Connector', 'gaming-hub' ),
			'super'      => __( '急速充電', 'gaming-hub' ),
			'superNote'  => __( 'Supercharger', 'gaming-hub' ),
			'tesla'      => __( 'Tesla', 'gaming-hub' ),
			'drive'      => __( '走行消費', 'gaming-hub' ),
			'cabin'      => __( '車内電力', 'gaming-hub' ),
			'flow'       => __( 'Tesla の入出力', 'gaming-hub' ),
			'idle'       => __( '待機', 'gaming-hub' ),
			'charging'   => __( '充電中', 'gaming-hub' ),
			'driving'    => __( '走行中', 'gaming-hub' ),
			'climate'    => __( 'エアコン', 'gaming-hub' ),
			'sentry'     => __( 'Sentry', 'gaming-hub' ),
			'live'       => __( 'Tesla Fleet API 実データ', 'gaming-hub' ),
		),
		'images' => array(
			'wall'  => $base . 'tesla-wall-connector-gaming.jpg',
			'super' => $base . 'tesla-supercharger-gaming.jpg',
			'tesla' => $base . 'tesla-model3-gaming.jpg',
			'drive' => $base . 'tesla-drive-gaming.jpg',
			'cabin' => $base . 'tesla-cabin-gaming.jpg',
		),
	);
}
