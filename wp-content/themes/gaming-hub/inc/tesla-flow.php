<?php
/**
 * Tesla vehicle energy flow (Wall Connector / Supercharger → battery → drive / cabin).
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Estimate cabin / HVAC watts from climate + sentry.
 *
 * @param array<string, mixed> $climate Climate state.
 * @param bool                 $sentry  Sentry mode on.
 */
function gaming_hub_tesla_estimate_cabin_w( array $climate, $sentry ) {
	$climate_on = ! empty( $climate['is_climate_on'] )
		|| ! empty( $climate['is_auto_conditioning_on'] )
		|| ! empty( $climate['is_preconditioning'] )
		|| ! empty( $climate['climate_keeper_mode'] );

	$fan     = max( 0, (int) ( $climate['fan_status'] ?? 0 ) );
	$cabin_w = 0;

	if ( $climate_on ) {
		$cabin_w = 900 + min( 2500, $fan * 180 );
		$inside  = isset( $climate['inside_temp'] ) && is_numeric( $climate['inside_temp'] )
			? (float) $climate['inside_temp']
			: null;
		$outside = isset( $climate['outside_temp'] ) && is_numeric( $climate['outside_temp'] )
			? (float) $climate['outside_temp']
			: null;
		if ( null !== $inside && null !== $outside ) {
			$cabin_w += (int) min( 1200, abs( $outside - $inside ) * 80 );
		}
	} elseif ( $sentry ) {
		$cabin_w = 280;
	}

	return max( 0, (int) round( $cabin_w ) );
}

/**
 * Time-of-day Tesla vehicle demo (commute, Wall Connector, occasional Supercharger).
 *
 * @param float $time Hour + minute/60.
 * @return array<string, mixed>
 */
function gaming_hub_tesla_vehicle_demo_energy( $time ) {
	$weekday = (int) wp_date( 'N' );
	$charge  = gaming_hub_powerwall_model3_charge_watts();

	if ( $time >= 7.5 && $time < 9.0 ) {
		$progress = ( $time - 7.5 ) / 1.5;
		return array(
			'mode'     => 'drive',
			'wall_w'   => 0,
			'super_w'  => 0,
			'drive_w'  => (int) round( 9000 + ( 4000 * sin( $progress * M_PI ) ) ),
			'cabin_w'  => 1400,
			'shift'    => 'D',
			'speed_km' => (int) round( 28 + ( 22 * sin( $progress * M_PI ) ) ),
		);
	}

	if ( 6 === $weekday && $time >= 11.0 && $time < 11.6 ) {
		return array(
			'mode'     => 'supercharger',
			'wall_w'   => 0,
			'super_w'  => 72000,
			'drive_w'  => 0,
			'cabin_w'  => 1600,
			'shift'    => 'P',
			'speed_km' => 0,
		);
	}

	if ( $time >= 17.0 && $time < 17.7 ) {
		$progress = ( $time - 17.0 ) / 0.7;
		return array(
			'mode'     => 'drive',
			'wall_w'   => 0,
			'super_w'  => 0,
			'drive_w'  => (int) round( 8000 + ( 3500 * sin( $progress * M_PI ) ) ),
			'cabin_w'  => 1200,
			'shift'    => 'D',
			'speed_km' => (int) round( 22 + ( 18 * sin( $progress * M_PI ) ) ),
		);
	}

	if ( gaming_hub_powerwall_model3_is_charging_time( $time ) ) {
		return array(
			'mode'     => 'wall',
			'wall_w'   => $charge,
			'super_w'  => 0,
			'drive_w'  => 0,
			'cabin_w'  => 0,
			'shift'    => 'P',
			'speed_km' => 0,
		);
	}

	if ( $time >= 22.5 || $time < 6.0 ) {
		return array(
			'mode'     => 'cabin',
			'wall_w'   => 0,
			'super_w'  => 0,
			'drive_w'  => 0,
			'cabin_w'  => 280,
			'shift'    => 'P',
			'speed_km' => 0,
		);
	}

	return array(
		'mode'     => 'idle',
		'wall_w'   => 0,
		'super_w'  => 0,
		'drive_w'  => 0,
		'cabin_w'  => 0,
		'shift'    => 'P',
		'speed_km' => 0,
	);
}

/**
 * Build Tesla-only energy-flow payload from Model 3 + optional live extras.
 *
 * @param array<string, mixed> $model3 Model 3 HUD slice.
 * @param string               $source tesla|simulated.
 * @return array<string, mixed>
 */
function gaming_hub_tesla_vehicle_flow_payload( array $model3, $source = 'simulated' ) {
	$model3   = gaming_hub_powerwall_model3_present( $model3 );
	$charging = ! empty( $model3['is_charging'] );
	$kind     = (string) ( $model3['supply_kind'] ?? 'none' );
	$charge_w = $charging ? max( 0, (int) ( $model3['watts'] ?? 0 ) ) : 0;

	$wall_w  = ( $charging && 'supercharger' !== $kind ) ? $charge_w : 0;
	$super_w = ( $charging && 'supercharger' === $kind ) ? $charge_w : 0;
	$drive_w = max( 0, (int) ( $model3['drive_w'] ?? 0 ) );
	$cabin_w = max( 0, (int) ( $model3['cabin_w'] ?? 0 ) );
	$mode    = (string) ( $model3['vehicle_mode'] ?? '' );

	if ( '' === $mode ) {
		if ( $super_w >= 80 ) {
			$mode = 'supercharger';
		} elseif ( $wall_w >= 80 ) {
			$mode = 'wall';
		} elseif ( $drive_w >= 80 ) {
			$mode = 'drive';
		} elseif ( $cabin_w >= 80 ) {
			$mode = 'cabin';
		} else {
			$mode = 'idle';
		}
	}

	$soc = max( 0, min( 100, (int) ( $model3['battery_percent'] ?? 0 ) ) );

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
		'charge_state'    => (string) ( $model3['charge_state'] ?? __( '待機', 'gaming-hub' ) ),
		'vehicle_name'    => (string) ( $model3['vehicle_name'] ?? 'Model 3' ),
		'supply_kind'     => $kind,
		'supply_label'    => (string) ( $model3['supply_label'] ?? '' ),
		'range_label'     => (string) ( $model3['range_label'] ?? '—' ),
		'live'            => 'tesla' === $source,
		'simulated'       => 'tesla' !== $source,
	);
}

/**
 * Apply commute / charge demo watts onto a Model 3 slice.
 *
 * @param array<string, mixed> $model3 Model 3 slice.
 * @return array<string, mixed>
 */
function gaming_hub_tesla_vehicle_apply_demo( array $model3 ) {
	$time   = (int) wp_date( 'G' ) + ( (int) wp_date( 'i' ) / 60 );
	$energy = gaming_hub_tesla_vehicle_demo_energy( $time );

	$model3['drive_w']      = $energy['drive_w'];
	$model3['cabin_w']      = $energy['cabin_w'];
	$model3['vehicle_mode'] = $energy['mode'];
	$model3['shift_state']  = $energy['shift'];
	$model3['speed_km']     = $energy['speed_km'];
	$model3['climate_on']   = $energy['cabin_w'] >= 800;
	$model3['sentry_mode']  = 280 === (int) $energy['cabin_w'];

	if ( 'supercharger' === $energy['mode'] ) {
		$model3['is_charging']  = true;
		$model3['watts']        = $energy['super_w'];
		$model3['supply_kind']  = 'supercharger';
		$model3['supply_label'] = __( 'フィールド補給', 'gaming-hub' );
		$model3['plugged']      = true;
		$model3['charge_state'] = __( 'チャージレイド', 'gaming-hub' );
	} elseif ( 'wall' === $energy['mode'] ) {
		$model3['is_charging']  = true;
		$model3['watts']        = $energy['wall_w'];
		$model3['supply_kind']  = 'home';
		$model3['supply_label'] = __( '拠点補給', 'gaming-hub' );
		$model3['plugged']      = true;
	}

	return $model3;
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
			'title'         => __( 'Tesla 電力フロー', 'gaming-hub' ),
			'wall'          => __( '普通充電', 'gaming-hub' ),
			'wallNote'      => __( 'Wall Connector', 'gaming-hub' ),
			'super'         => __( '急速充電', 'gaming-hub' ),
			'superNote'     => __( 'Supercharger', 'gaming-hub' ),
			'tesla'         => __( 'Tesla', 'gaming-hub' ),
			'drive'         => __( '走行消費', 'gaming-hub' ),
			'cabin'         => __( '車内電力', 'gaming-hub' ),
			'flow'          => __( 'Tesla の入出力', 'gaming-hub' ),
			'idle'          => __( '待機', 'gaming-hub' ),
			'charging'      => __( '充電中', 'gaming-hub' ),
			'driving'       => __( '走行中', 'gaming-hub' ),
			'climate'       => __( 'エアコン', 'gaming-hub' ),
			'sentry'        => __( 'Sentry', 'gaming-hub' ),
			'simulated'     => __( '時刻に応じたデモ（通勤・Wall Connector・Supercharger）', 'gaming-hub' ),
			'live'          => __( 'Tesla Fleet API 実データ', 'gaming-hub' ),
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
