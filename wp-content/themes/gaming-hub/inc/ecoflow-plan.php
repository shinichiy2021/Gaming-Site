<?php
/**
 * EcoFlow daily charge plan: buy grid only on deficit, at the cheapest hours (daytime included).
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_ECOFLOW_SOLAR_PRO_W', 800 );
define( 'GAMING_HUB_ECOFLOW_SOLAR_DELTA1500_W', 500 );
define( 'GAMING_HUB_ECOFLOW_SOLAR_CAPACITY_W', GAMING_HUB_ECOFLOW_SOLAR_PRO_W + GAMING_HUB_ECOFLOW_SOLAR_DELTA1500_W );
define( 'GAMING_HUB_ECOFLOW_ROOM_DAILY_KWH', 5.5 );
define( 'GAMING_HUB_ECOFLOW_ROOM_BASE_DAILY_KWH', 0 );
define( 'GAMING_HUB_ECOFLOW_AC_START_C', 24.0 );
define( 'GAMING_HUB_ECOFLOW_AC_WEEKDAY_START_C', 28.0 );
define( 'GAMING_HUB_ECOFLOW_AC_SETPOINT_C', 26.0 );
define( 'GAMING_HUB_ECOFLOW_AC_W_PER_C', 70 );
define( 'GAMING_HUB_ECOFLOW_AC_MAX_W', 550 );
define( 'GAMING_HUB_ECOFLOW_PLAN_MIN_SOC', 25 );
define( 'GAMING_HUB_ECOFLOW_PLAN_CHARGE_W', 1000 );
define( 'GAMING_HUB_ECOFLOW_PLAN_IDLE_W', 0 );
define( 'GAMING_HUB_ECOFLOW_BACKUP_RESERVE_GRID_ON', 100 );
define( 'GAMING_HUB_ECOFLOW_BACKUP_RESERVE_GRID_OFF', 5 );
define( 'GAMING_HUB_ECOFLOW_DELTA1500_DC_W', 100 );
define( 'GAMING_HUB_ECOFLOW_PLAN_CACHE_TTL', 10 * MINUTE_IN_SECONDS );
define( 'GAMING_HUB_ECOFLOW_PRO_CAPACITY_WH', 4096 );

/**
 * Combined EcoFlow array: Pro high-volt 800 W + Delta 1500 low-volt 500 W.
 */
function gaming_hub_ecoflow_solar_capacity_w() {
	return (int) GAMING_HUB_ECOFLOW_SOLAR_CAPACITY_W;
}

/**
 * Dashboard label for the EcoFlow solar arrays.
 */
function gaming_hub_ecoflow_solar_panel_label() {
	return sprintf(
		/* translators: 1: Pro watts, 2: 1500 watts */
		__( 'Pro %1$s W + 1500 %2$s W', 'gaming-hub' ),
		number_format_i18n( (int) GAMING_HUB_ECOFLOW_SOLAR_PRO_W ),
		number_format_i18n( (int) GAMING_HUB_ECOFLOW_SOLAR_DELTA1500_W )
	);
}

/**
 * Scale a 24h watt series from the Tajimi profile capacity to the EcoFlow array.
 *
 * @param array<int, mixed> $hours Hourly watts.
 * @return array<int, int>
 */
function gaming_hub_ecoflow_scale_solar_hours( array $hours ) {
	$from = function_exists( 'gaming_hub_powerwall_solar_capacity_w' )
		? (int) gaming_hub_powerwall_solar_capacity_w()
		: 1500;
	$to   = gaming_hub_ecoflow_solar_capacity_w();
	$out  = array();

	foreach ( $hours as $hour => $watts ) {
		$scaled = (float) $watts;
		if ( $from > 0 && $from !== $to ) {
			$scaled = $scaled * ( $to / $from );
		}
		$out[ (int) $hour ] = (int) max( 0, min( $to, round( $scaled ) ) );
	}

	return $out;
}

/**
 * Gaming-room hourly weights (24h, sums to 24.0).
 *
 * @return array<int, float>
 */
function gaming_hub_ecoflow_room_hourly_weights() {
	return array(
		0  => 0.42,
		1  => 0.36,
		2  => 0.32,
		3  => 0.32,
		4  => 0.34,
		5  => 0.40,
		6  => 0.55,
		7  => 0.72,
		8  => 0.80,
		9  => 0.75,
		10 => 0.70,
		11 => 0.78,
		12 => 0.95,
		13 => 0.88,
		14 => 0.82,
		15 => 0.88,
		16 => 1.10,
		17 => 1.45,
		18 => 1.75,
		19 => 1.95,
		20 => 2.05,
		21 => 1.85,
		22 => 1.50,
		23 => 0.86,
	);
}

/**
 * Electrical watts for a room AC from outdoor temperature.
 *
 * Typical 6–8 tatami inverter at half load: off at or below the start temp,
 * ~70 W per °C above that, cap 0.55 kW.
 *
 * @param float|null $celsius Outdoor °C.
 * @param float|null $start_c Turn-on threshold °C.
 */
function gaming_hub_ecoflow_ac_watts_for_temp( $celsius, $start_c = null ) {
	if ( null === $celsius || ! is_numeric( $celsius ) ) {
		return 0;
	}

	$start = null !== $start_c && is_numeric( $start_c )
		? (float) $start_c
		: (float) GAMING_HUB_ECOFLOW_AC_START_C;
	$delta = (float) $celsius - $start;
	if ( $delta <= 0 ) {
		return 0;
	}

	return (int) min( GAMING_HUB_ECOFLOW_AC_MAX_W, max( 0, round( $delta * GAMING_HUB_ECOFLOW_AC_W_PER_C ) ) );
}

/**
 * Whether Shinichi's room uses the weekend AC schedule (Sat–Sun).
 *
 * Weekdays stay off unless outdoor temp exceeds 28°C.
 *
 * @param int|null $timestamp Unix timestamp in site timezone context.
 */
function gaming_hub_ecoflow_room_ac_is_weekend( $timestamp = null ) {
	$weekday = (int) ( null === $timestamp ? wp_date( 'N' ) : wp_date( 'N', $timestamp ) );

	return $weekday >= 6;
}

/**
 * Room energy from base load + temperature-driven AC.
 *
 * @param int                 $from_hour Current hour 0–23.
 * @param array<int, mixed>   $temps     Hourly outdoor °C (0–23).
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_room_energy_from_temps( $from_hour, array $temps ) {
	$weights    = gaming_hub_ecoflow_room_hourly_weights();
	$weight_sum = array_sum( $weights ) ?: 24.0;
	$base_daily = (float) GAMING_HUB_ECOFLOW_ROOM_BASE_DAILY_KWH;
	$has_temps  = false;
	$is_weekend = gaming_hub_ecoflow_room_ac_is_weekend();
	$ac_start_c = $is_weekend
		? (float) GAMING_HUB_ECOFLOW_AC_START_C
		: (float) GAMING_HUB_ECOFLOW_AC_WEEKDAY_START_C;

	foreach ( $temps as $t ) {
		if ( is_numeric( $t ) ) {
			$has_temps = true;
			break;
		}
	}

	$base_hours = array_fill( 0, 24, 0.0 );
	$ac_hours   = array_fill( 0, 24, 0.0 );
	$ac_watts   = array_fill( 0, 24, 0 );

	for ( $h = 0; $h < 24; $h++ ) {
		$base_hours[ $h ] = $base_daily * ( ( $weights[ $h ] ?? 0 ) / $weight_sum );
		if ( ! $has_temps ) {
			continue;
		}

		$ac_watts[ $h ] = gaming_hub_ecoflow_ac_watts_for_temp(
			isset( $temps[ $h ] ) ? $temps[ $h ] : null,
			$ac_start_c
		);
		$ac_hours[ $h ] = $ac_watts[ $h ] / 1000.0;
	}

	$room_today_kwh     = 0.0;
	$room_remaining_kwh = 0.0;
	$ac_today_kwh       = 0.0;
	$ac_remaining_kwh   = 0.0;
	$base_today_kwh     = 0.0;
	$base_remaining_kwh = 0.0;

	for ( $h = 0; $h < 24; $h++ ) {
		$hour_kwh          = $base_hours[ $h ] + $ac_hours[ $h ];
		$room_today_kwh   += $hour_kwh;
		$base_today_kwh   += $base_hours[ $h ];
		$ac_today_kwh     += $ac_hours[ $h ];
		if ( $h >= $from_hour ) {
			$room_remaining_kwh += $hour_kwh;
			$base_remaining_kwh += $base_hours[ $h ];
			$ac_remaining_kwh   += $ac_hours[ $h ];
		}
	}

	$now_temp = isset( $temps[ $from_hour ] ) && is_numeric( $temps[ $from_hour ] )
		? (float) $temps[ $from_hour ]
		: null;
	$ac_now_w = (int) ( $ac_watts[ $from_hour ] ?? 0 );

	return array(
		'room_today_kwh'     => round( $room_today_kwh, 2 ),
		'room_remaining_kwh' => round( $room_remaining_kwh, 2 ),
		'ac_today_kwh'       => round( $ac_today_kwh, 2 ),
		'ac_remaining_kwh'   => round( $ac_remaining_kwh, 2 ),
		'base_today_kwh'     => round( $base_today_kwh, 2 ),
		'base_remaining_kwh' => round( $base_remaining_kwh, 2 ),
		'ac_now_w'           => $ac_now_w,
		'ac_watts'           => $ac_watts,
		'ac_on'              => $ac_now_w > 0 || $ac_today_kwh > 0,
		'ac_weekend'         => $is_weekend,
		'ac_start_c'         => $ac_start_c,
		'temp_now'           => $now_temp,
		'setpoint_c'         => GAMING_HUB_ECOFLOW_AC_SETPOINT_C,
	);
}

/**
 * Hourly Pro SOC % from now through tonight (null = already past).
 *
 * @param int               $from_hour Current hour.
 * @param int               $soc       Current SOC 0–100.
 * @param float             $full_wh   Full pack Wh.
 * @param array<int, mixed> $slots       Plan slots.
 * @param array<int, int>   $ac_watts    Hourly AC watts.
 * @param array<int, mixed> $solar_hours Hourly solar watts.
 * @return array<int, float|null>
 */
function gaming_hub_ecoflow_soc_series( $from_hour, $soc, $full_wh, array $slots, array $ac_watts, array $solar_hours = array() ) {
	$today    = wp_date( 'Y-m-d' );
	$grid_w   = array_fill( 0, 24, GAMING_HUB_ECOFLOW_PLAN_IDLE_W );
	$dc_w     = (int) GAMING_HUB_ECOFLOW_DELTA1500_DC_W;
	$full_kwh = max( 0.5, (float) $full_wh / 1000.0 );
	$series   = array_fill( 0, 24, null );
	$pct      = max( 0.0, min( 100.0, (float) $soc ) );

	foreach ( $slots as $slot ) {
		if ( ( $slot['date'] ?? '' ) !== $today ) {
			continue;
		}
		$h = (int) ( $slot['hour'] ?? -1 );
		if ( $h < 0 || $h > 23 || null === ( $slot['watts'] ?? null ) ) {
			continue;
		}
		$grid_w[ $h ] = (int) $slot['watts'];
	}

	for ( $h = $from_hour; $h < 24; $h++ ) {
		$series[ $h ] = round( $pct, 1 );
		$load_w       = (int) ( $ac_watts[ $h ] ?? 0 ) + $dc_w;
		$solar_w      = max( 0, (float) ( $solar_hours[ $h ] ?? 0 ) );
		$net_w        = $grid_w[ $h ] + $solar_w - $load_w;
		$pct         += ( $net_w / 1000.0 ) / $full_kwh * 100.0;
		$pct          = max( 0.0, min( 100.0, $pct ) );
	}

	return $series;
}

/**
 * Get or build the charge plan for the current EcoFlow status.
 *
 * @param array<string, mixed> $status Device status.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_get_charge_plan( array $status ) {
	$hour = (int) wp_date( 'G' );
	$soc  = isset( $status['battery_percent'] ) ? (int) $status['battery_percent'] : 0;
	$key  = 'gaming_hub_ecoflow_plan_v23_' . wp_date( 'Y-m-d' ) . '_' . $hour . '_' . (int) floor( $soc / 5 ) . '_' . GAMING_HUB_ECOFLOW_PLAN_CHARGE_W . '_' . GAMING_HUB_ECOFLOW_PLAN_IDLE_W . '_' . GAMING_HUB_ECOFLOW_SOLAR_CAPACITY_W;

	$cached = get_transient( $key );
	if ( is_array( $cached ) && ! empty( $cached['slots'] ) && isset( $cached['charge_w'], $cached['dc1500_remaining_kwh'], $cached['ac_today_kwh'], $cached['soc_series'], $cached['solar_hours'] ) ) {
		return gaming_hub_ecoflow_finalize_charge_plan( $cached, $status );
	}

	$plan = gaming_hub_ecoflow_build_charge_plan( $status );
	set_transient( $key, $plan, GAMING_HUB_ECOFLOW_PLAN_CACHE_TTL );

	return gaming_hub_ecoflow_finalize_charge_plan( $plan, $status );
}

/**
 * Overlay live schedule state and RATE MAP solar series (actual + forecast).
 *
 * @param array<string, mixed> $plan   Cached or built plan.
 * @param array<string, mixed> $status Device status.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_finalize_charge_plan( array $plan, array $status ) {
	if ( function_exists( 'gaming_hub_ecoflow_attach_schedule_state' ) ) {
		$plan = gaming_hub_ecoflow_attach_schedule_state( $plan );
	}

	$plan['charge_w'] = GAMING_HUB_ECOFLOW_PLAN_CHARGE_W;
	$plan['idle_w']   = GAMING_HUB_ECOFLOW_PLAN_IDLE_W;
	if ( isset( $plan['slots'] ) && is_array( $plan['slots'] ) ) {
		$plan['slots'] = gaming_hub_ecoflow_normalize_plan_slots( $plan['slots'] );
	}

	$hour     = (int) wp_date( 'G' );
	$forecast = is_array( $plan['solar_hours'] ?? null ) ? $plan['solar_hours'] : array();
	$actuals  = function_exists( 'gaming_hub_ecoflow_energy_today_solar_hours' )
		? gaming_hub_ecoflow_energy_today_solar_hours()
		: array();
	$live = (int) ( $plan['solar_now_w'] ?? 0 );
	if ( function_exists( 'gaming_hub_ecoflow_combined_site_watts' ) ) {
		$live = (int) round( gaming_hub_ecoflow_combined_site_watts( $status )['solar'] );
	} elseif ( isset( $status['solar_in'] ) ) {
		$live = max( 0, (int) $status['solar_in'] );
	}
	$chart    = array();
	$kinds    = array();

	for ( $h = 0; $h < 24; $h++ ) {
		$forecast_w = (int) round( max( 0, (float) ( $forecast[ $h ] ?? 0 ) ) );
		if ( $h < $hour && isset( $actuals[ $h ] ) && null !== $actuals[ $h ] ) {
			$chart[] = (int) $actuals[ $h ];
			$kinds[] = 'actual';
		} elseif ( $h === $hour ) {
			$chart[] = $live;
			$kinds[] = 'live';
		} else {
			$chart[] = $forecast_w;
			$kinds[] = 'forecast';
		}
	}

	$plan['solar_chart']      = $chart;
	$plan['solar_chart_kind'] = $kinds;
	$plan['solar_now_w']      = $live;

	$soc_series  = is_array( $plan['soc_series'] ?? null ) ? $plan['soc_series'] : array_fill( 0, 24, null );
	$soc_actuals = function_exists( 'gaming_hub_ecoflow_energy_today_soc_hours' )
		? gaming_hub_ecoflow_energy_today_soc_hours()
		: array();
	$live_soc    = isset( $status['battery_percent'] ) && null !== $status['battery_percent']
		? max( 0, min( 100, (float) $status['battery_percent'] ) )
		: ( isset( $plan['soc_now'] ) ? (float) $plan['soc_now'] : null );
	$soc_kinds   = array();

	for ( $h = 0; $h < 24; $h++ ) {
		if ( $h < $hour && isset( $soc_actuals[ $h ] ) && null !== $soc_actuals[ $h ] ) {
			$soc_series[ $h ] = (float) $soc_actuals[ $h ];
			$soc_kinds[ $h ]  = 'actual';
		} elseif ( $h === $hour && null !== $live_soc ) {
			$soc_series[ $h ] = round( $live_soc, 1 );
			$soc_kinds[ $h ]  = 'live';
		} elseif ( isset( $soc_series[ $h ] ) && is_numeric( $soc_series[ $h ] ) ) {
			$soc_kinds[ $h ] = 'forecast';
		} else {
			$soc_kinds[ $h ] = 'empty';
		}
	}

	$plan['soc_series']     = $soc_series;
	$plan['soc_chart_kind'] = $soc_kinds;
	if ( null !== $live_soc ) {
		$plan['soc_now'] = round( $live_soc, 1 );
	}

	return $plan;
}

/**
 * Build today's deficit and recommended cheap-grid window.
 *
 * @param array<string, mixed> $status Device status.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_build_charge_plan( array $status ) {
	$hour        = (int) wp_date( 'G' );
	$solar_remaining_kwh = 0.0;
	$solar_today_kwh     = 0.0;
	$solar_hours         = array();
	$weather             = '';
	$weather_location    = '';
	$temps               = array();
	$temp_now            = null;
	$temp_max            = null;
	$temp_min            = null;

	if ( function_exists( 'gaming_hub_powerwall_solar_hourly_profile' ) ) {
		$profile          = gaming_hub_powerwall_solar_hourly_profile();
		$solar_hours      = gaming_hub_ecoflow_scale_solar_hours( $profile['hours'] ?? array() );
		$weather          = (string) ( $profile['weather'] ?? '' );
		$weather_location = (string) ( $profile['location'] ?? '' );
		$temps            = is_array( $profile['temps'] ?? null ) ? $profile['temps'] : array();
		$temp_now         = isset( $profile['temp_now'] ) ? $profile['temp_now'] : null;
		$temp_max         = isset( $profile['temp_max'] ) ? $profile['temp_max'] : null;
		$temp_min         = isset( $profile['temp_min'] ) ? $profile['temp_min'] : null;
	}

	foreach ( $solar_hours as $h => $watts ) {
		$kwh = max( 0, (float) $watts ) / 1000.0;
		$solar_today_kwh += $kwh;
		if ( (int) $h >= $hour ) {
			$solar_remaining_kwh += $kwh;
		}
	}

	$room = gaming_hub_ecoflow_room_energy_from_temps( $hour, $temps );
	if ( null === $room['temp_now'] && null !== $temp_now ) {
		$room['temp_now'] = $temp_now;
	}

	$room_remaining_kwh    = (float) $room['room_remaining_kwh'];
	$hours_left            = 24 - $hour;
	$dc1500_w              = (int) GAMING_HUB_ECOFLOW_DELTA1500_DC_W;
	$dc1500_remaining_kwh  = ( $dc1500_w / 1000.0 ) * $hours_left;
	$dc1500_today_kwh      = ( $dc1500_w / 1000.0 ) * 24;
	$load_remaining_kwh    = $room_remaining_kwh + $dc1500_remaining_kwh;

	$full_wh = gaming_hub_ecoflow_plan_full_wh( $status );
	$soc     = isset( $status['battery_percent'] ) && null !== $status['battery_percent']
		? max( 0, min( 100, (int) $status['battery_percent'] ) )
		: 0;
	$usable_kwh = max( 0, ( $soc - GAMING_HUB_ECOFLOW_PLAN_MIN_SOC ) / 100.0 ) * ( $full_wh / 1000.0 );

	$deficit_kwh = max( 0, $load_remaining_kwh - $solar_remaining_kwh - $usable_kwh );
	$needed      = $deficit_kwh <= 0.05;

	$windows = array();
	$avg_yen = null;
	$note    = '';
	$picked  = array(
		'windows' => array(),
		'avg_yen' => null,
		'picked'  => array(),
	);

	if ( $needed ) {
		$note = __( '今日の残りはソーラーと電池で足りる見込みです。グリッド充電は不要です。', 'gaming-hub' );
	} else {
		$picked  = gaming_hub_ecoflow_pick_cheap_hours( $hour, $deficit_kwh, $solar_hours );
		$windows = $picked['windows'];
		$avg_yen = $picked['avg_yen'];
		$note    = sprintf(
			/* translators: 1: deficit kWh, 2: charge watts */
			__( '発電が使用量を下回りそうです。スマートタイムONEの最安時間（昼も含む）に %2$s W で約 %1$s kWh をグリッド充電します。', 'gaming-hub' ),
			number_format_i18n( $deficit_kwh, 1 ),
			number_format_i18n( GAMING_HUB_ECOFLOW_PLAN_CHARGE_W )
		);
	}

	$slots      = gaming_hub_ecoflow_build_day_slots( $hour, $solar_hours, $picked['picked'] );
	$soc_series = gaming_hub_ecoflow_soc_series(
		$hour,
		$soc,
		$full_wh,
		$slots,
		is_array( $room['ac_watts'] ?? null ) ? $room['ac_watts'] : array(),
		$solar_hours
	);
	$soc_future = array_values( array_filter( $soc_series, 'is_numeric' ) );
	$plan_id    = gaming_hub_ecoflow_plan_id_from_slots( $slots );
	$price      = gaming_hub_ecoflow_smart_time_one_meta();
	$solar_series = array();
	for ( $h = 0; $h < 24; $h++ ) {
		$solar_series[] = (int) round( max( 0, (float) ( $solar_hours[ $h ] ?? 0 ) ) );
	}

	return array(
		'deficit_kwh'          => round( $deficit_kwh, 2 ),
		'needs_grid'           => ! $needed,
		'window_label'         => $windows ? implode( '、', $windows ) : __( 'グリッド充電不要', 'gaming-hub' ),
		'window_avg_yen'       => $avg_yen,
		'load_remaining_kwh'   => round( $load_remaining_kwh, 2 ),
		'room_remaining_kwh'   => round( $room_remaining_kwh, 2 ),
		'dc1500_w'             => $dc1500_w,
		'dc1500_remaining_kwh' => round( $dc1500_remaining_kwh, 2 ),
		'dc1500_today_kwh'     => round( $dc1500_today_kwh, 2 ),
		'solar_remaining_kwh'  => round( $solar_remaining_kwh, 2 ),
		'solar_today_kwh'      => round( $solar_today_kwh, 2 ),
		'usable_battery_kwh'   => round( $usable_kwh, 2 ),
		'weather'              => $weather,
		'weather_location'     => $weather_location,
		'temp_now'             => null === $room['temp_now'] ? $temp_now : $room['temp_now'],
		'temp_max'             => $temp_max,
		'temp_min'             => $temp_min,
		'ac_today_kwh'         => $room['ac_today_kwh'],
		'ac_remaining_kwh'     => $room['ac_remaining_kwh'],
		'ac_now_w'             => $room['ac_now_w'],
		'ac_on'                => ! empty( $room['ac_on'] ),
		'ac_weekend'           => ! empty( $room['ac_weekend'] ),
		'ac_start_c'           => $room['ac_start_c'] ?? ( ! empty( $room['ac_weekend'] ) ? GAMING_HUB_ECOFLOW_AC_START_C : GAMING_HUB_ECOFLOW_AC_WEEKDAY_START_C ),
		'base_today_kwh'       => $room['base_today_kwh'],
		'ac_setpoint_c'        => $room['setpoint_c'],
		'reserve_soc'          => GAMING_HUB_ECOFLOW_PLAN_MIN_SOC,
		'room_daily_kwh'       => $room['room_today_kwh'],
		'charge_w'             => GAMING_HUB_ECOFLOW_PLAN_CHARGE_W,
		'idle_w'               => GAMING_HUB_ECOFLOW_PLAN_IDLE_W,
		'slots'                => $slots,
		'soc_series'           => $soc_series,
		'soc_now'              => $soc,
		'soc_min'              => $soc_future ? round( min( $soc_future ), 1 ) : $soc,
		'soc_end'              => $soc_future ? round( (float) end( $soc_future ), 1 ) : $soc,
		'solar_hours'          => $solar_series,
		'solar_capacity_w'     => gaming_hub_ecoflow_solar_capacity_w(),
		'solar_now_w'          => (int) ( $solar_series[ $hour ] ?? 0 ),
		'plan_id'              => $plan_id,
		'price_provider'       => $price['provider'],
		'price_note'           => $price['note'],
		'note'                 => $note,
		'updated_at'           => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
	);
}

/**
 * LOOOP Smart Time ONE (電灯) labels for the Chubu area.
 *
 * @return array{provider: string, note: string}
 */
function gaming_hub_ecoflow_smart_time_one_meta() {
	return array(
		'provider' => __( 'LOOOP スマートタイムONE（電灯）', 'gaming-hub' ),
		'note'     => __( '中部エリア。請求単価 = 電源料金＋サービス料＋託送従量＋再エネ賦課金。', 'gaming-hub' ),
	);
}

/**
 * Hourly yen/kWh map for Smart Time ONE (Chubu).
 *
 * @return array<string, mixed>|WP_Error|null
 */
function gaming_hub_ecoflow_smart_time_one_price_map() {
	if ( ! function_exists( 'gaming_hub_looop_hourly_price_map_today' ) ) {
		return null;
	}

	return gaming_hub_looop_hourly_price_map_today();
}

/**
 * Resolve usable full pack energy in Wh.
 *
 * @param array<string, mixed> $status Device status.
 */
function gaming_hub_ecoflow_plan_full_wh( array $status ) {
	$capacity = isset( $status['remain_capacity'] ) ? (float) $status['remain_capacity'] : 0;
	$soc      = isset( $status['battery_percent'] ) ? (int) $status['battery_percent'] : 0;

	if ( $capacity > 200 && $soc > 5 ) {
		$full = $capacity / ( $soc / 100 );
		if ( $full >= 1500 && $full <= 12000 ) {
			return $full;
		}
	}

	return (float) GAMING_HUB_ECOFLOW_PRO_CAPACITY_WH;
}

/**
 * Pick the cheapest upcoming hours that can cover the deficit.
 *
 * Daytime solar hours are included; cheapest yen/kWh wins.
 *
 * @param int               $from_hour   Current hour 0–23.
 * @param float             $deficit_kwh Energy to buy.
 * @param array<int, mixed> $solar_hours Unused; kept for call-site compatibility.
 * @return array{windows: array<int, string>, avg_yen: float|null, picked: array<int, array{hour: int, index: int, yen: float}>}
 */
function gaming_hub_ecoflow_pick_cheap_hours( $from_hour, $deficit_kwh, $solar_hours ) {
	$price_data = gaming_hub_ecoflow_smart_time_one_price_map();

	if ( is_wp_error( $price_data ) || ! is_array( $price_data ) ) {
		return array(
			'windows' => array( __( '単価データなし', 'gaming-hub' ) ),
			'avg_yen' => null,
			'picked'  => array(),
		);
	}

	$today_map = $price_data['map'];
	$forecast  = $price_data['forecast'] ?? array();
	$tomorrow  = array();

	foreach ( $forecast['days']['tomorrow']['hourly'] ?? array() as $row ) {
		$tomorrow[ (int) $row['hour'] ] = (float) $row['total_price'];
	}

	$candidates = array();
	$kwh_per_h  = GAMING_HUB_ECOFLOW_PLAN_CHARGE_W / 1000.0;

	for ( $i = 0; $i < 18; $i++ ) {
		$abs        = $from_hour + $i;
		$h          = $abs % 24;
		$tomorrow_h = $abs >= 24;

		$yen = $tomorrow_h
			? ( $tomorrow[ $h ] ?? $today_map[ $h ] ?? $price_data['fallback'] )
			: ( $today_map[ $h ] ?? $price_data['fallback'] );

		$candidates[] = array(
			'hour'  => $h,
			'index' => $i,
			'yen'   => (float) $yen,
		);
	}

	if ( empty( $candidates ) ) {
		return array(
			'windows' => array( __( '候補時間なし', 'gaming-hub' ) ),
			'avg_yen' => null,
			'picked'  => array(),
		);
	}

	$weights = gaming_hub_ecoflow_room_hourly_weights();

	usort(
		$candidates,
		static function ( $a, $b ) use ( $weights ) {
			if ( $a['yen'] !== $b['yen'] ) {
				return $a['yen'] <=> $b['yen'];
			}

			$weight_a = $weights[ $a['hour'] ] ?? 1.0;
			$weight_b = $weights[ $b['hour'] ] ?? 1.0;
			if ( $weight_a !== $weight_b ) {
				return $weight_a <=> $weight_b;
			}

			return $a['index'] <=> $b['index'];
		}
	);

	$hours_needed = max( 1, (int) ceil( $deficit_kwh / max( $kwh_per_h, 0.1 ) ) );
	$picked       = array_slice( $candidates, 0, $hours_needed );
	$avg          = array_sum( array_column( $picked, 'yen' ) ) / count( $picked );

	usort(
		$picked,
		static function ( $a, $b ) {
			return $a['index'] <=> $b['index'];
		}
	);

	return array(
		'windows' => gaming_hub_ecoflow_group_hour_windows( $picked ),
		'avg_yen' => round( $avg, 1 ),
		'picked'  => $picked,
	);
}

/**
 * Build today's remaining slots plus any overnight charge hours.
 *
 * @param int                                      $from_hour   Current hour 0–23.
 * @param array<int, mixed>                        $solar_hours Hourly solar watts.
 * @param array<int, array{hour: int, index: int, yen: float}> $picked Charge hours.
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_ecoflow_build_day_slots( $from_hour, $solar_hours, $picked ) {
	$today     = wp_date( 'Y-m-d' );
	$tomorrow  = wp_date( 'Y-m-d', current_datetime()->getTimestamp() + DAY_IN_SECONDS );
	$price     = gaming_hub_ecoflow_smart_time_one_price_map();
	$today_map = ( is_array( $price ) && isset( $price['map'] ) ) ? $price['map'] : array();
	$fallback  = ( is_array( $price ) && isset( $price['fallback'] ) ) ? (float) $price['fallback'] : null;
	$tomorrow_map = array();

	foreach ( $price['forecast']['days']['tomorrow']['hourly'] ?? array() as $row ) {
		$tomorrow_map[ (int) $row['hour'] ] = (float) $row['total_price'];
	}

	$charge_keys = array();
	foreach ( $picked as $row ) {
		$abs  = $from_hour + (int) $row['index'];
		$date = $abs >= 24 ? $tomorrow : $today;
		$charge_keys[ $date . '-' . (int) $row['hour'] ] = (float) $row['yen'];
	}

	$slots = array();

	for ( $h = 0; $h < 24; $h++ ) {
		$slots[] = gaming_hub_ecoflow_make_slot(
			$today,
			$h,
			$h < $from_hour,
			$solar_hours,
			$charge_keys,
			isset( $today_map[ $h ] ) ? (float) $today_map[ $h ] : $fallback
		);
	}

	foreach ( $picked as $row ) {
		$abs = $from_hour + (int) $row['index'];
		if ( $abs < 24 ) {
			continue;
		}

		$h       = (int) $row['hour'];
		$slots[] = gaming_hub_ecoflow_make_slot(
			$tomorrow,
			$h,
			false,
			array(),
			$charge_keys,
			isset( $tomorrow_map[ $h ] ) ? (float) $tomorrow_map[ $h ] : (float) $row['yen']
		);
	}

	return $slots;
}

/**
 * Rewrite slot watts to the current idle / charge constants.
 *
 * Cached and saved plans can still carry the old 200 W idle value.
 *
 * @param array<int, array<string, mixed>> $slots Plan slots.
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_ecoflow_normalize_plan_slots( array $slots ) {
	foreach ( $slots as &$slot ) {
		if ( ! is_array( $slot ) || ! empty( $slot['past'] ) || null === ( $slot['watts'] ?? null ) ) {
			continue;
		}

		$mode = (string) ( $slot['mode'] ?? 'idle' );
		$slot['watts'] = 'charge' === $mode
			? (int) GAMING_HUB_ECOFLOW_PLAN_CHARGE_W
			: (int) GAMING_HUB_ECOFLOW_PLAN_IDLE_W;
	}
	unset( $slot );

	return $slots;
}

/**
 * One hour in the visible schedule.
 *
 * @param string               $date        Y-m-d.
 * @param int                  $hour        0–23.
 * @param bool                 $past        Already elapsed.
 * @param array<int, mixed>    $solar_hours Hourly solar watts.
 * @param array<string, float> $charge_keys Date-hour keys to charge.
 * @param float|null           $yen         Price.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_make_slot( $date, $hour, $past, $solar_hours, $charge_keys, $yen ) {
	$key      = $date . '-' . (int) $hour;
	$solar_w  = (float) ( $solar_hours[ $hour ] ?? 0 );
	$is_charge = ! $past && isset( $charge_keys[ $key ] );

	if ( $past ) {
		$mode  = 'past';
		$watts = null;
	} elseif ( $is_charge ) {
		$mode  = 'charge';
		$watts = GAMING_HUB_ECOFLOW_PLAN_CHARGE_W;
	} elseif ( $solar_w >= 80 ) {
		$mode  = 'solar';
		$watts = GAMING_HUB_ECOFLOW_PLAN_IDLE_W;
	} else {
		$mode  = 'idle';
		$watts = GAMING_HUB_ECOFLOW_PLAN_IDLE_W;
	}

	$until = ( (int) $hour + 1 ) % 24;
	$label = ( 0 === $until && 23 === (int) $hour )
		? sprintf( '%d:00–24:00', $hour )
		: sprintf( '%d:00–%d:00', $hour, $until );

	return array(
		'id'      => $date . 'T' . sprintf( '%02d', $hour ),
		'date'    => $date,
		'hour'    => (int) $hour,
		'label'   => $label,
		'mode'    => $mode,
		'watts'   => $watts,
		'solar_w' => round( $solar_w ),
		'yen'     => null === $yen ? null : round( (float) $yen, 1 ),
		'past'    => (bool) $past,
	);
}

/**
 * Stable id for actionable future watts.
 *
 * @param array<int, array<string, mixed>> $slots Slots.
 */
function gaming_hub_ecoflow_plan_id_from_slots( array $slots ) {
	$payload = array();

	foreach ( $slots as $slot ) {
		if ( ! empty( $slot['past'] ) || null === $slot['watts'] ) {
			continue;
		}

		$payload[] = $slot['id'] . ':' . (int) $slot['watts'];
	}

	return md5( wp_json_encode( $payload ) );
}

/**
 * Group picked hours into readable ranges.
 *
 * @param array<int, array{hour: int, index: int}> $picked Sorted by index.
 * @return array<int, string>
 */
function gaming_hub_ecoflow_group_hour_windows( array $picked ) {
	if ( empty( $picked ) ) {
		return array();
	}

	$ranges = array();
	$start  = $picked[0];
	$prev   = $picked[0];

	for ( $i = 1, $n = count( $picked ); $i < $n; $i++ ) {
		$row = $picked[ $i ];
		if ( $row['index'] === $prev['index'] + 1 ) {
			$prev = $row;
			continue;
		}

		$ranges[] = gaming_hub_ecoflow_hour_range_label( $start['hour'], $prev['hour'] );
		$start    = $row;
		$prev     = $row;
	}

	$ranges[] = gaming_hub_ecoflow_hour_range_label( $start['hour'], $prev['hour'] );

	return $ranges;
}

/**
 * Format a start–end hour range.
 *
 * @param int $start Start hour.
 * @param int $end   Inclusive end hour.
 */
function gaming_hub_ecoflow_hour_range_label( $start, $end ) {
	$until = ( $end + 1 ) % 24;
	if ( 0 === $until && 23 === (int) $end ) {
		return sprintf( '%d:00–24:00', $start );
	}

	return sprintf( '%d:00–%d:00', $start, $until );
}
