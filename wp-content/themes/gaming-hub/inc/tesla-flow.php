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
 * Pack kWh + remaining-time fields for the Tesla flow node (Delta Pro 3 style).
 *
 * @param array<string, mixed> $model3    Model 3 HUD slice.
 * @param int|null             $soc       Battery percent.
 * @param bool                 $charging  Home or Supercharger charging.
 * @param bool                 $asleep    Vehicle asleep.
 * @param int|null             $charge_w  Charge watts.
 * @param int|null             $drive_w   Drive watts.
 * @param int|null             $cabin_w   Cabin watts.
 * @return array<string, mixed>
 */
function gaming_hub_tesla_flow_pack_fields( array $model3, $soc, $charging, $asleep, $charge_w, $drive_w, $cabin_w ) {
	$full_kwh = isset( $model3['battery_kwh_nominal'] ) && is_numeric( $model3['battery_kwh_nominal'] )
		? (float) $model3['battery_kwh_nominal']
		: 0.0;
	if ( $full_kwh <= 0 ) {
		$full_kwh = defined( 'GAMING_HUB_MODEL3_BATTERY_KWH' ) ? (float) GAMING_HUB_MODEL3_BATTERY_KWH : 60.0;
	}

	$remain_kwh = isset( $model3['battery_kwh_estimate'] ) && is_numeric( $model3['battery_kwh_estimate'] )
		? (float) $model3['battery_kwh_estimate']
		: 0.0;
	if ( $remain_kwh <= 0 && null !== $soc ) {
		$remain_kwh = $full_kwh * ( max( 0, min( 100, (float) $soc ) ) / 100.0 );
	}

	$capacity_wh = (int) round( $full_kwh * 1000 );
	$remain_wh   = (int) round( max( 0, $remain_kwh ) * 1000 );
	$eta_mode    = 'idle';
	$eta_label   = '';
	$eta_display = '—';

	$format_min = function_exists( 'gaming_hub_format_ecoflow_minutes' )
		? 'gaming_hub_format_ecoflow_minutes'
		: ( function_exists( 'gaming_hub_format_duration_minutes' ) ? 'gaming_hub_format_duration_minutes' : null );

	$format_eta = static function ( $minutes ) use ( $format_min ) {
		$minutes = max( 1, min( 2880, (int) $minutes ) );
		return $format_min ? $format_min( $minutes ) : (string) $minutes;
	};

	if ( ! $asleep && $charging ) {
		$eta_mode  = 'charge';
		$eta_label = __( '満充電まで', 'gaming-hub' );
		$limit     = isset( $model3['charge_limit_percent'] ) && is_numeric( $model3['charge_limit_percent'] )
			? max( 0, min( 100, (int) $model3['charge_limit_percent'] ) )
			: 100;
		$soc_n     = (float) ( $soc ?? 0 );
		$minutes   = null;

		if ( isset( $model3['minutes_to_full'] ) && is_numeric( $model3['minutes_to_full'] ) ) {
			$minutes = (int) round( (float) $model3['minutes_to_full'] );
		}

		if ( $soc_n >= max( 99.5, $limit - 0.5 ) ) {
			$eta_display = __( '満充電', 'gaming-hub' );
		} elseif ( null !== $minutes && $minutes > 0 ) {
			$eta_display = $format_eta( $minutes );
		} else {
			$charge_watts = max( 0, (int) ( $charge_w ?? 0 ) );
			$to_full_wh   = max( 0, (int) round( $capacity_wh * ( $limit / 100 ) ) - $remain_wh );
			if ( $charge_watts > 0 && $to_full_wh > 0 ) {
				$eta_display = $format_eta( (int) round( $to_full_wh / $charge_watts * 60 ) );
			}
		}
	} elseif ( ! $asleep ) {
		$dsg_w = 0;
		if ( (int) ( $drive_w ?? 0 ) >= 80 ) {
			$dsg_w = (int) $drive_w;
		} elseif ( (int) ( $cabin_w ?? 0 ) >= 80 ) {
			$dsg_w = (int) $cabin_w;
		}

		if ( $dsg_w >= 80 && $remain_wh > 0 ) {
			$eta_mode  = 'discharge';
			$eta_label = __( '0%まで', 'gaming-hub' );
			if ( null !== $soc && (float) $soc <= 0.5 ) {
				$eta_display = '0%';
			} else {
				$eta_display = $format_eta( (int) round( $remain_wh / $dsg_w * 60 ) );
			}
		}
	}

	return array(
		'capacity_wh'         => $capacity_wh,
		'remain_capacity'     => $remain_wh,
		'eta_mode'            => $eta_mode,
		'remain_time_label'   => $eta_label,
		'remain_time_display' => $eta_display,
	);
}

/**
 * Short "8/25→8/26" label for a charge that ran across midnight.
 *
 * @param string $start_date Y-m-d.
 * @param string $end_date   Y-m-d.
 * @return string
 */
function gaming_hub_tesla_date_span_label( $start_date, $end_date ) {
	$short = static function ( $ymd ) {
		$parts = explode( '-', (string) $ymd );
		return 3 === count( $parts ) ? (int) $parts[1] . '/' . (int) $parts[2] : '';
	};

	$from = $short( $start_date );
	$to   = $short( $end_date );

	return ( '' !== $from && '' !== $to ) ? $from . '→' . $to : '';
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

	$empty_gas = function_exists( 'gaming_hub_tesla_gasoline_compare' )
		? gaming_hub_tesla_gasoline_compare( $model3, 0, 0 )
		: array();

	$live = $live || ! empty( $model3['live'] );

	if ( ! $live ) {
		return array(
			'wall_w'          => null,
			'super_w'         => null,
			'drive_w'         => null,
			'cabin_w'         => null,
			'regen_w'         => null,
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
			'drive_ready'     => false,
			'asleep'          => false,
			'gas'             => $empty_gas,
			'cabin_today_kwh' => 0,
			'cabin_today_yen' => 0,
			'wall_yen_per_h'  => 0,
			'wall_today_kwh'  => 0,
			'wall_today_yen'  => 0,
			'wall_session_kwh'=> 0,
			'wall_session_yen'=> 0,
			'wall_span_days'  => false,
			'wall_span_label' => '',
			'elec_yen_per_kwh'=> 0,
			'capacity_wh'     => null,
			'remain_capacity' => null,
			'eta_mode'        => 'idle',
			'remain_time_label'   => '',
			'remain_time_display' => '—',
		);
	}

	$model3 = gaming_hub_powerwall_model3_present( $model3 );
	if ( 'tesla' === $source && function_exists( 'gaming_hub_tesla_finish_cached_model3' ) ) {
		$model3 = gaming_hub_tesla_finish_cached_model3(
			$model3,
			! empty( $model3['asleep'] )
		);
	}
	$charging = ! empty( $model3['is_charging'] );
	$asleep   = ! $charging && ! empty( $model3['asleep'] );
	$kind     = (string) ( $model3['supply_kind'] ?? ( $charging ? 'home' : 'none' ) );
	$charge_w = $charging ? gaming_hub_tesla_live_watt( $model3['watts'] ?? null ) : 0;
	if ( $charging && ( null === $charge_w || $charge_w < 80 ) ) {
		$rate_kw = (float) ( $model3['charge_rate_kw'] ?? 0 );
		if ( $rate_kw > 0.05 ) {
			$charge_w = (int) round( $rate_kw * 1000 );
		}
	}

	$wall_w  = ( $charging && 'supercharger' !== $kind )
		? ( null === $charge_w ? 0 : $charge_w )
		: 0;
	$super_w = ( $charging && 'supercharger' === $kind )
		? ( null === $charge_w ? 0 : $charge_w )
		: 0;
	$drive_w = array_key_exists( 'drive_w', $model3 ) ? gaming_hub_tesla_live_watt( $model3['drive_w'] ) : null;
	$cabin_w = array_key_exists( 'cabin_w', $model3 ) ? gaming_hub_tesla_live_watt( $model3['cabin_w'] ) : null;
	$regen_w = array_key_exists( 'regen_w', $model3 ) ? gaming_hub_tesla_live_watt( $model3['regen_w'] ) : 0;
	$mode    = (string) ( $model3['vehicle_mode'] ?? '' );

	if ( '' === $mode ) {
		if ( ( $super_w ?? 0 ) >= 80 ) {
			$mode = 'supercharger';
		} elseif ( ( $wall_w ?? 0 ) >= 80 ) {
			$mode = 'wall';
		} elseif ( ( $regen_w ?? 0 ) >= 80 ) {
			$mode = 'regen';
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

	$gas = function_exists( 'gaming_hub_tesla_gasoline_compare' )
		? gaming_hub_tesla_gasoline_compare( $model3, $drive_w, (int) ( $model3['speed_km'] ?? 0 ) )
		: array();

	$cabin_energy = function_exists( 'gaming_hub_tesla_cabin_energy_today' )
		? gaming_hub_tesla_cabin_energy_today()
		: array(
			'today_kwh' => 0.0,
			'today_yen' => 0,
		);

	if ( $asleep && ! $charging ) {
		$wall_w  = 0;
		$super_w = 0;
		$drive_w = 0;
		$cabin_w = 0;
		$regen_w = 0;
		$mode    = 'idle';
		$gas     = function_exists( 'gaming_hub_tesla_gasoline_compare' )
			? gaming_hub_tesla_gasoline_compare( $model3, 0, 0 )
			: array();
	}

	$wall_home = $charging && 'supercharger' !== $kind && ! $asleep;
	$yen_kwh   = function_exists( 'gaming_hub_tesla_electricity_yen_per_kwh' )
		? gaming_hub_tesla_electricity_yen_per_kwh()
		: 30.0;
	$wall_w_num = max( 0, (int) ( $wall_w ?? 0 ) );
	$energy_added = isset( $model3['charge_energy_added'] ) && is_numeric( $model3['charge_energy_added'] )
		? max( 0, (float) $model3['charge_energy_added'] )
		: null;
	$wall_meta = array(
		'soc'       => $soc,
		'limit_soc' => isset( $model3['charge_limit_percent'] ) && is_numeric( $model3['charge_limit_percent'] )
			? (int) $model3['charge_limit_percent']
			: null,
	);
	$wall_energy  = function_exists( 'gaming_hub_tesla_record_wall_energy' )
		? gaming_hub_tesla_record_wall_energy( $wall_w_num, $wall_home, $energy_added, $wall_meta )
		: gaming_hub_tesla_wall_energy_empty();
	$super_on = $charging && 'supercharger' === $kind && ! $asleep;
	$super_w_num = max( 0, (int) ( $super_w ?? 0 ) );
	if ( function_exists( 'gaming_hub_tesla_record_super_energy' ) ) {
		gaming_hub_tesla_record_super_energy( $super_w_num, $super_on, $energy_added, $wall_meta );
	}
	$wall_yen_h = $wall_home
		? (int) round( ( $wall_w_num / 1000.0 ) * $yen_kwh )
		: 0;
	$span_days  = ! empty( $wall_energy['session_spans_days'] );
	$span_label = $span_days
		? gaming_hub_tesla_date_span_label( $wall_energy['session_start_date'], $wall_energy['session_end_date'] )
		: '';

	$pack = gaming_hub_tesla_flow_pack_fields(
		$model3,
		$soc,
		$charging,
		$asleep,
		$charge_w,
		$drive_w,
		$cabin_w
	);
	$capacity_wh = $pack['capacity_wh'];
	$remain_wh   = $pack['remain_capacity'];
	$eta_mode    = $pack['eta_mode'];
	$eta_label   = $pack['remain_time_label'];
	$eta_display = $pack['remain_time_display'];

	return array(
		'wall_w'          => $wall_w,
		'super_w'         => $super_w,
		'drive_w'         => $drive_w,
		'cabin_w'         => $cabin_w,
		'regen_w'         => $regen_w,
		'mode'            => $mode,
		'shift'           => $asleep ? 'P' : (string) ( $model3['shift_state'] ?? '' ),
		'speed_km'        => $asleep ? 0 : (int) ( $model3['speed_km'] ?? 0 ),
		'climate_on'      => ! $asleep && ! empty( $model3['climate_on'] ),
		'sentry'          => ! $asleep && ! empty( $model3['sentry_mode'] ),
		'battery_percent' => $soc,
		'is_charging'     => $charging,
		'charge_state'    => $charging
			? __( '充電中', 'gaming-hub' )
			: ( ( $regen_w ?? 0 ) >= 80
				? __( '回生充電', 'gaming-hub' )
				: __( '待機', 'gaming-hub' ) ),
		'cabin_today_kwh' => $cabin_energy['today_kwh'],
		'cabin_today_yen' => $cabin_energy['today_yen'],
		'wall_yen_per_h'  => $wall_yen_h,
		'wall_today_kwh'  => $wall_energy['today_kwh'],
		'wall_today_yen'  => $wall_energy['today_yen'],
		'wall_session_kwh'=> $wall_energy['session_kwh'],
		'wall_session_yen'=> $wall_energy['session_yen'],
		'wall_span_days'  => $span_days,
		'wall_span_label' => $span_label,
		'elec_yen_per_kwh'=> round( $yen_kwh, 1 ),
		'capacity_wh'     => $capacity_wh,
		'remain_capacity' => $remain_wh,
		'eta_mode'        => $eta_mode,
		'remain_time_label'   => $eta_label,
		'remain_time_display' => $eta_display,
		'vehicle_name'    => (string) ( $model3['vehicle_name'] ?? 'Model 3' ),
		'supply_kind'     => $kind,
		'supply_label'    => (string) ( $model3['supply_label'] ?? '' ),
		'range_label'     => $asleep ? '' : (string) ( $model3['range_label'] ?? '' ),
		'live'            => true,
		'simulated'       => false,
		'drive_ready'     => ! $asleep && ! empty( $model3['drive_ready'] ),
		'asleep'          => $asleep,
		'gas'             => $gas,
	);
}

/**
 * Tesla vehicle flow labels + image URLs.
 *
 * @return array<string, mixed>
 */
function gaming_hub_tesla_vehicle_flow_assets() {
	$base = get_template_directory_uri() . '/assets/images/';
	$ver  = defined( 'GAMING_HUB_VERSION' ) ? '?ver=' . rawurlencode( (string) GAMING_HUB_VERSION ) : '';

	return array(
		'labels' => array(
			'title'      => __( 'Tesla 電力フロー', 'gaming-hub' ),
			'wall'       => __( '普通充電', 'gaming-hub' ),
			'wallNote'   => __( '200V', 'gaming-hub' ),
			'super'      => __( '急速充電', 'gaming-hub' ),
			'superNote'  => __( 'Supercharger', 'gaming-hub' ),
			'tesla'      => __( 'Tesla', 'gaming-hub' ),
			'drive'      => __( 'ガソリン換算', 'gaming-hub' ),
			'regen'      => __( '回生充電', 'gaming-hub' ),
			'regenNote'  => __( '減速・ブレーキ', 'gaming-hub' ),
			'cabin'      => __( '車内電力', 'gaming-hub' ),
			'flow'       => __( 'Tesla の入出力', 'gaming-hub' ),
			'idle'       => __( '待機', 'gaming-hub' ),
			'charging'   => __( '充電中', 'gaming-hub' ),
			'driving'    => __( '走行中', 'gaming-hub' ),
			'climate'    => __( 'エアコン', 'gaming-hub' ),
			'sentry'     => __( 'Sentry', 'gaming-hub' ),
			'live'          => __( 'Tesla Fleet API 実データ', 'gaming-hub' ),
			'asleep'        => __( 'スリープ中', 'gaming-hub' ),
			'drivePending'  => __( '走行データ未取得', 'gaming-hub' ),
			'shift'         => __( 'シフト', 'gaming-hub' ),
			'park'          => __( 'パーキング', 'gaming-hub' ),
			'reverse'       => __( 'リバース', 'gaming-hub' ),
			'neutral'       => __( 'ニュートラル', 'gaming-hub' ),
			'driveGear'     => __( 'ドライブ', 'gaming-hub' ),
			'shiftUnknown'  => __( 'シフト未取得', 'gaming-hub' ),
			'saved'         => __( '節約', 'gaming-hub' ),
			'todayUse'      => __( '今日 使用', 'gaming-hub' ),
			'todayBill'     => __( '今日 電気代', 'gaming-hub' ),
			'buy'           => __( '買電', 'gaming-hub' ),
			'todayBuy'      => __( '今日 買電', 'gaming-hub' ),
			'yenPerHour'    => __( '円/時', 'gaming-hub' ),
			'session'       => __( '今回', 'gaming-hub' ),
			'total'         => __( '合計', 'gaming-hub' ),
		),
		'images' => array(
			'wall'  => $base . 'tesla-wall-connector-gaming.jpg' . $ver,
			'super' => $base . 'tesla-supercharger-gaming.jpg' . $ver,
			'tesla' => $base . 'tesla-model3-gaming.jpg' . $ver,
			'drive' => $base . 'tesla-drive-gaming.jpg' . $ver,
			'cabin' => $base . 'tesla-cabin-gaming.jpg' . $ver,
		),
	);
}
