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
define( 'GAMING_HUB_ECOFLOW_ROOM_BASE_DAILY_KWH', 0 );
define( 'GAMING_HUB_ECOFLOW_AC_START_C', 30.0 );
define( 'GAMING_HUB_ECOFLOW_AC_START_W', 500 );
define( 'GAMING_HUB_ECOFLOW_AC_W_PER_C', 100 );
define( 'GAMING_HUB_ECOFLOW_AC_MAX_W', 1000 );
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
 * Split a combined solar series into Pro HV 800 W and 1500 LV 500 W shares.
 *
 * @param array<int, mixed> $hours Combined hourly watts.
 * @return array{pro: array<int, int>, delta: array<int, int>}
 */
function gaming_hub_ecoflow_split_solar_hours( array $hours ) {
	$pro_cap = (int) GAMING_HUB_ECOFLOW_SOLAR_PRO_W;
	$d_cap   = (int) GAMING_HUB_ECOFLOW_SOLAR_DELTA1500_W;
	$total   = max( 1, $pro_cap + $d_cap );
	$pro     = array();
	$delta   = array();

	for ( $h = 0; $h < 24; $h++ ) {
		$combined    = max( 0, (float) ( $hours[ $h ] ?? 0 ) );
		$pro[ $h ]   = (int) round( $combined * $pro_cap / $total );
		$delta[ $h ] = (int) max( 0, round( $combined - $pro[ $h ] ) );
	}

	return array(
		'pro'   => $pro,
		'delta' => $delta,
	);
}

/**
 * SVG points for stacked Pro / 1500 solar areas (viewBox 240×100).
 *
 * @param array<int, mixed> $pro_hours   Pro HV watts.
 * @param array<int, mixed> $delta_hours 1500 LV watts.
 * @param int               $cap         Combined capacity watts.
 * @return array{delta_area: string, pro_area: string, total_line: string}
 */
function gaming_hub_ecoflow_chart_solar_stack_points( array $pro_hours, array $delta_hours, $cap ) {
	$cap       = max( 1, (int) $cap );
	$delta_pts = array();
	$total_pts = array();

	for ( $h = 0; $h < 24; $h++ ) {
		$d  = max( 0, (float) ( $delta_hours[ $h ] ?? 0 ) );
		$p  = max( 0, (float) ( $pro_hours[ $h ] ?? 0 ) );
		$x  = ( ( $h + 0.5 ) * 10 );
		$dy = max( 0, min( 100, 100 - ( $d / $cap ) * 100 ) );
		$ty = max( 0, min( 100, 100 - ( ( $d + $p ) / $cap ) * 100 ) );
		$delta_pts[] = $x . ',' . round( $dy, 1 );
		$total_pts[] = $x . ',' . round( $ty, 1 );
	}

	return array(
		'delta_area' => $delta_pts ? ( '0,100 ' . implode( ' ', $delta_pts ) . ' 240,100' ) : '',
		'pro_area'   => $delta_pts ? ( implode( ' ', $delta_pts ) . ' ' . implode( ' ', array_reverse( $total_pts ) ) ) : '',
		'total_line' => implode( ' ', $total_pts ),
	);
}

/**
 * 1500 system pack (main + Extra) energy for stacked SOC.
 *
 * @param array<string, mixed> $status EcoFlow status.
 * @return array{full_wh: float, remain_wh: float|null, soc: float|null}
 */
function gaming_hub_ecoflow_plan_delta_pack( array $status ) {
	$delta = ( isset( $status['secondary'] ) && is_array( $status['secondary'] ) )
		? $status['secondary']
		: array();
	$extra = ( isset( $delta['extra'] ) && is_array( $delta['extra'] ) )
		? $delta['extra']
		: array();

	$main_full = isset( $delta['capacity_wh'] ) && is_numeric( $delta['capacity_wh'] ) && $delta['capacity_wh'] > 0
		? (float) $delta['capacity_wh']
		: (float) ( defined( 'GAMING_HUB_ECOFLOW_DELTA1500_CAPACITY_WH' )
			? ( GAMING_HUB_ECOFLOW_DELTA1500_CAPACITY_WH - ( defined( 'GAMING_HUB_ECOFLOW_DELTA1500_EXTRA_WH' ) ? GAMING_HUB_ECOFLOW_DELTA1500_EXTRA_WH : 1000 ) )
			: 1500 );

	$extra_full = 0.0;
	if ( ! empty( $extra['connected'] ) && isset( $extra['capacity_wh'] ) && is_numeric( $extra['capacity_wh'] ) && $extra['capacity_wh'] > 0 ) {
		$extra_full = (float) $extra['capacity_wh'];
	}

	$main_remain = null;
	if ( isset( $delta['remain_capacity'] ) && is_numeric( $delta['remain_capacity'] ) ) {
		$main_remain = max( 0.0, (float) $delta['remain_capacity'] );
	} elseif ( isset( $delta['battery_percent'] ) && is_numeric( $delta['battery_percent'] ) ) {
		$main_remain = $main_full * max( 0, min( 100, (float) $delta['battery_percent'] ) ) / 100.0;
	}

	$extra_remain = 0.0;
	$has_extra    = false;
	if ( $extra_full > 0 && isset( $extra['battery_percent'] ) && is_numeric( $extra['battery_percent'] ) ) {
		$has_extra    = true;
		$extra_remain = isset( $extra['remain_capacity'] ) && is_numeric( $extra['remain_capacity'] )
			? max( 0.0, (float) $extra['remain_capacity'] )
			: $extra_full * max( 0, min( 100, (float) $extra['battery_percent'] ) ) / 100.0;
	} elseif ( $extra_full > 0 && isset( $extra['remain_capacity'] ) && is_numeric( $extra['remain_capacity'] ) ) {
		$has_extra    = true;
		$extra_remain = max( 0.0, (float) $extra['remain_capacity'] );
	}

	$full = $main_full + ( $has_extra ? $extra_full : 0.0 );
	if ( $full <= 0 ) {
		return array(
			'full_wh'   => 0.0,
			'remain_wh' => null,
			'soc'       => null,
		);
	}

	if ( null === $main_remain ) {
		return array(
			'full_wh'   => $full,
			'remain_wh' => null,
			'soc'       => null,
		);
	}

	$remain = min( $full, $main_remain + ( $has_extra ? $extra_remain : 0.0 ) );
	$soc    = 100.0 * $remain / $full;

	return array(
		'full_wh'   => $full,
		'remain_wh' => $remain,
		'soc'       => round( max( 0.0, min( 100.0, $soc ) ), 1 ),
	);
}

/**
 * Stacked bar heights: Pro and 1500 remaining energy as % of combined capacity.
 *
 * @param float|null $pro_pct   Pro SOC 0–100.
 * @param float|null $delta_pct 1500 SOC 0–100.
 * @param float      $pro_wh    Pro full Wh.
 * @param float      $delta_wh  1500 full Wh.
 * @return array{pro: float, delta: float, combined: float|null}
 */
function gaming_hub_ecoflow_soc_stack_heights( $pro_pct, $delta_pct, $pro_wh, $delta_wh ) {
	$pro_wh    = max( 1.0, (float) $pro_wh );
	$delta_wh  = max( 0.0, (float) $delta_wh );
	$has_pro   = null !== $pro_pct && is_numeric( $pro_pct );
	$has_delta = null !== $delta_pct && is_numeric( $delta_pct ) && $delta_wh > 0;
	if ( ! $has_delta ) {
		$delta_wh = 0.0;
	}
	$full    = max( $pro_wh, $pro_wh + $delta_wh );
	$pro_h   = $has_pro
		? max( 0.0, min( 100.0, (float) $pro_pct ) ) / 100.0 * $pro_wh / $full * 100.0
		: 0.0;
	$delta_h = $has_delta
		? max( 0.0, min( 100.0, (float) $delta_pct ) ) / 100.0 * $delta_wh / $full * 100.0
		: 0.0;

	return array(
		'pro'      => round( $pro_h, 1 ),
		'delta'    => round( $delta_h, 1 ),
		'combined' => ( $has_pro || $has_delta ) ? round( $pro_h + $delta_h, 1 ) : null,
	);
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
 * Electrical watts for Shinichi-room AC from outdoor temperature.
 *
 * Off below 30°C. At 30°C starts at 500 W, then +100 W per °C, cap 1 kW (35°C+).
 *
 * @param float|null $celsius Outdoor °C.
 */
function gaming_hub_ecoflow_ac_watts_for_temp( $celsius ) {
	if ( null === $celsius || ! is_numeric( $celsius ) ) {
		return 0;
	}

	$start_c = (float) GAMING_HUB_ECOFLOW_AC_START_C;
	$delta   = (float) $celsius - $start_c;
	if ( $delta < 0 ) {
		return 0;
	}

	$watts = (int) GAMING_HUB_ECOFLOW_AC_START_W + (int) round( $delta * (int) GAMING_HUB_ECOFLOW_AC_W_PER_C );

	return (int) min( (int) GAMING_HUB_ECOFLOW_AC_MAX_W, max( 0, $watts ) );
}

/**
 * Room energy from base load + temperature-driven AC.
 *
 * @param int               $from_hour Current hour 0–23.
 * @param array<int, mixed> $temps     Hourly outdoor °C (0–23).
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_room_energy_from_temps( $from_hour, array $temps ) {
	$weights    = gaming_hub_ecoflow_room_hourly_weights();
	$weight_sum = array_sum( $weights ) ?: 24.0;
	$base_daily = (float) GAMING_HUB_ECOFLOW_ROOM_BASE_DAILY_KWH;
	$from_hour  = max( 0, min( 23, (int) $from_hour ) );
	$has_temps  = false;

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
			isset( $temps[ $h ] ) ? $temps[ $h ] : null
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
		'ac_start_c'         => (float) GAMING_HUB_ECOFLOW_AC_START_C,
		'ac_start_w'         => (int) GAMING_HUB_ECOFLOW_AC_START_W,
		'ac_max_w'           => (int) GAMING_HUB_ECOFLOW_AC_MAX_W,
		'temp_now'           => $now_temp,
	);
}

/**
 * Hourly pack SOC % from now through tonight (null = already past).
 *
 * @param int               $from_hour   Current hour.
 * @param int|float         $soc         Current SOC 0–100.
 * @param float             $full_wh     Full pack Wh.
 * @param array<int, mixed> $slots       Plan slots (grid watts when $use_grid).
 * @param array<int, mixed> $load_watts  Hourly load watts on this pack.
 * @param array<int, mixed> $solar_hours Hourly solar watts into this pack.
 * @param bool              $use_grid    Apply planned grid charge watts.
 * @return array<int, float|null>
 */
function gaming_hub_ecoflow_soc_series( $from_hour, $soc, $full_wh, array $slots, array $load_watts, array $solar_hours = array(), $use_grid = true ) {
	$today    = wp_date( 'Y-m-d' );
	$grid_w   = array_fill( 0, 24, GAMING_HUB_ECOFLOW_PLAN_IDLE_W );
	$full_kwh = max( 0.5, (float) $full_wh / 1000.0 );
	$series   = array_fill( 0, 24, null );
	$pct      = max( 0.0, min( 100.0, (float) $soc ) );

	if ( $use_grid ) {
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
	}

	for ( $h = $from_hour; $h < 24; $h++ ) {
		$series[ $h ] = round( $pct, 1 );
		$load_w       = max( 0, (float) ( $load_watts[ $h ] ?? 0 ) );
		$solar_w      = max( 0, (float) ( $solar_hours[ $h ] ?? 0 ) );
		$net_w        = ( $use_grid ? $grid_w[ $h ] : 0 ) + $solar_w - $load_w;
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
	$delta_pack = gaming_hub_ecoflow_plan_delta_pack( $status );
	$delta_key  = null !== ( $delta_pack['soc'] ?? null ) ? (int) floor( (float) $delta_pack['soc'] / 5 ) : 'x';
	$key        = 'gaming_hub_ecoflow_plan_v26_' . wp_date( 'Y-m-d' ) . '_' . $hour . '_' . (int) floor( $soc / 5 ) . '_' . $delta_key . '_' . GAMING_HUB_ECOFLOW_PLAN_CHARGE_W . '_' . GAMING_HUB_ECOFLOW_PLAN_IDLE_W . '_' . GAMING_HUB_ECOFLOW_SOLAR_CAPACITY_W . '_' . GAMING_HUB_ECOFLOW_AC_START_W . '_' . GAMING_HUB_ECOFLOW_AC_MAX_W;

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
	$split_fc = gaming_hub_ecoflow_split_solar_hours( $forecast );
	$split_act = function_exists( 'gaming_hub_ecoflow_energy_today_split_solar_hours' )
		? gaming_hub_ecoflow_energy_today_split_solar_hours()
		: array( 'pro' => array(), 'delta' => array() );
	$site = function_exists( 'gaming_hub_ecoflow_combined_site_watts' )
		? gaming_hub_ecoflow_combined_site_watts( $status )
		: array();
	$live_pro   = (int) round( max( 0, (float) ( $site['hv'] ?? 0 ) ) );
	$live_delta = (int) round( max( 0, (float) ( $site['lv'] ?? 0 ) ) );
	$live       = $live_pro + $live_delta;
	if ( $live <= 0 ) {
		$live = (int) ( $plan['solar_now_w'] ?? 0 );
	}

	$chart       = array();
	$chart_pro   = array();
	$chart_delta = array();
	$kinds       = array();

	for ( $h = 0; $h < 24; $h++ ) {
		$act_pro   = $split_act['pro'][ $h ] ?? null;
		$act_delta = $split_act['delta'][ $h ] ?? null;
		if ( $h < $hour && ( null !== $act_pro || null !== $act_delta ) ) {
			$pro_w   = (int) ( $act_pro ?? 0 );
			$delta_w = (int) ( $act_delta ?? 0 );
			$kinds[] = 'actual';
		} elseif ( $h === $hour ) {
			$pro_w   = $live_pro;
			$delta_w = $live_delta;
			$kinds[] = 'live';
		} else {
			$pro_w   = (int) ( $split_fc['pro'][ $h ] ?? 0 );
			$delta_w = (int) ( $split_fc['delta'][ $h ] ?? 0 );
			$kinds[] = 'forecast';
		}
		$chart_pro[]   = $pro_w;
		$chart_delta[] = $delta_w;
		$chart[]       = $pro_w + $delta_w;
	}

	$plan['solar_chart']       = $chart;
	$plan['solar_chart_pro']   = $chart_pro;
	$plan['solar_chart_delta'] = $chart_delta;
	$plan['solar_chart_kind']  = $kinds;
	$plan['solar_now_w']       = $live;
	$plan['solar_now_pro_w']   = $live_pro;
	$plan['solar_now_delta_w'] = $live_delta;
	$plan['solar_capacity_w']  = gaming_hub_ecoflow_solar_capacity_w();
	$plan['solar_pro_w']       = (int) GAMING_HUB_ECOFLOW_SOLAR_PRO_W;
	$plan['solar_delta_w']     = (int) GAMING_HUB_ECOFLOW_SOLAR_DELTA1500_W;

	$soc_series  = is_array( $plan['soc_series_pro'] ?? null )
		? $plan['soc_series_pro']
		: ( is_array( $plan['soc_series'] ?? null ) ? $plan['soc_series'] : array_fill( 0, 24, null ) );
	$delta_series = is_array( $plan['soc_series_delta'] ?? null )
		? $plan['soc_series_delta']
		: array_fill( 0, 24, null );
	$soc_actuals = function_exists( 'gaming_hub_ecoflow_energy_today_soc_hours' )
		? gaming_hub_ecoflow_energy_today_soc_hours()
		: array();
	$delta_actuals = function_exists( 'gaming_hub_ecoflow_energy_today_delta_soc_hours' )
		? gaming_hub_ecoflow_energy_today_delta_soc_hours()
		: array();
	$live_soc = isset( $status['battery_percent'] ) && null !== $status['battery_percent']
		? max( 0, min( 100, (float) $status['battery_percent'] ) )
		: ( isset( $plan['soc_now_pro'] ) ? (float) $plan['soc_now_pro'] : ( isset( $plan['soc_now'] ) ? (float) $plan['soc_now'] : null ) );
	$delta_pack     = gaming_hub_ecoflow_plan_delta_pack( $status );
	$live_delta_soc = $delta_pack['soc'];
	$pro_wh         = gaming_hub_ecoflow_plan_full_wh( $status );
	$delta_wh       = (float) ( $delta_pack['full_wh'] ?? 0 );
	$soc_kinds      = array();
	$bar_pro        = array();
	$bar_delta      = array();
	$combined       = array();

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

		if ( $h < $hour && isset( $delta_actuals[ $h ] ) && null !== $delta_actuals[ $h ] ) {
			$delta_series[ $h ] = (float) $delta_actuals[ $h ];
		} elseif ( $h === $hour && null !== $live_delta_soc ) {
			$delta_series[ $h ] = round( (float) $live_delta_soc, 1 );
		}

		$stack          = gaming_hub_ecoflow_soc_stack_heights(
			$soc_series[ $h ] ?? null,
			$delta_series[ $h ] ?? null,
			$pro_wh,
			$delta_wh
		);
		$bar_pro[ $h ]   = $stack['pro'];
		$bar_delta[ $h ] = $stack['delta'];
		$combined[ $h ]  = $stack['combined'];
		if ( null === $combined[ $h ] ) {
			$soc_kinds[ $h ] = 'empty';
		}
	}

	$now_stack = gaming_hub_ecoflow_soc_stack_heights( $live_soc, $live_delta_soc, $pro_wh, $delta_wh );
	$end_pro   = $soc_series[23] ?? null;
	$end_delta = $delta_series[23] ?? null;
	$end_stack = gaming_hub_ecoflow_soc_stack_heights( $end_pro, $end_delta, $pro_wh, $delta_wh );

	$plan['soc_series']        = $combined;
	$plan['soc_series_pro']    = $soc_series;
	$plan['soc_series_delta']  = $delta_series;
	$plan['soc_bar_pro']       = $bar_pro;
	$plan['soc_bar_delta']     = $bar_delta;
	$plan['soc_chart_kind']    = $soc_kinds;
	$plan['soc_now_pro']       = null !== $live_soc ? round( $live_soc, 1 ) : null;
	$plan['soc_now_delta']     = null !== $live_delta_soc ? round( (float) $live_delta_soc, 1 ) : null;
	$plan['soc_now']           = $now_stack['combined'];
	$plan['soc_end']           = $end_stack['combined'];
	$plan['soc_end_pro']       = null !== $end_pro && is_numeric( $end_pro ) ? round( (float) $end_pro, 1 ) : null;
	$plan['soc_end_delta']     = null !== $end_delta && is_numeric( $end_delta ) ? round( (float) $end_delta, 1 ) : null;
	$plan['pro_capacity_wh']   = (int) round( $pro_wh );
	$plan['delta_capacity_wh'] = (int) round( $delta_wh );

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
	$split_solar = gaming_hub_ecoflow_split_solar_hours( $solar_hours );
	$ac_watts    = is_array( $room['ac_watts'] ?? null ) ? $room['ac_watts'] : array();
	$soc_series_pro = gaming_hub_ecoflow_soc_series(
		$hour,
		$soc,
		$full_wh,
		$slots,
		$ac_watts,
		$split_solar['pro'],
		true
	);
	$delta_pack = gaming_hub_ecoflow_plan_delta_pack( $status );
	$delta_load = array_fill( 0, 24, $dc1500_w );
	if ( null !== ( $delta_pack['soc'] ?? null ) && ( $delta_pack['full_wh'] ?? 0 ) > 0 ) {
		$soc_series_delta = gaming_hub_ecoflow_soc_series(
			$hour,
			$delta_pack['soc'],
			$delta_pack['full_wh'],
			array(),
			$delta_load,
			$split_solar['delta'],
			false
		);
	} else {
		$soc_series_delta = array_fill( 0, 24, null );
	}
	$soc_future = array_values( array_filter( $soc_series_pro, 'is_numeric' ) );
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
		'ac_start_c'           => $room['ac_start_c'] ?? GAMING_HUB_ECOFLOW_AC_START_C,
		'ac_start_w'           => $room['ac_start_w'] ?? GAMING_HUB_ECOFLOW_AC_START_W,
		'ac_max_w'             => $room['ac_max_w'] ?? GAMING_HUB_ECOFLOW_AC_MAX_W,
		'base_today_kwh'       => $room['base_today_kwh'],
		'reserve_soc'          => GAMING_HUB_ECOFLOW_PLAN_MIN_SOC,
		'room_daily_kwh'       => $room['room_today_kwh'],
		'charge_w'             => GAMING_HUB_ECOFLOW_PLAN_CHARGE_W,
		'idle_w'               => GAMING_HUB_ECOFLOW_PLAN_IDLE_W,
		'slots'                => $slots,
		'soc_series'           => $soc_series_pro,
		'soc_series_pro'       => $soc_series_pro,
		'soc_series_delta'     => $soc_series_delta,
		'soc_now'              => $soc,
		'soc_now_pro'          => $soc,
		'soc_now_delta'        => $delta_pack['soc'],
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
