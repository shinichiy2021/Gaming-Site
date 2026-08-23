<?php
/**
 * Tesla AI charge plan — cheapest LOOOP hours on 200V AC home charging.
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_TESLA_PLAN_VOLTS', 200 );
define( 'GAMING_HUB_TESLA_PLAN_AMPS', 15 );
define( 'GAMING_HUB_TESLA_PLAN_CHARGE_W', GAMING_HUB_TESLA_PLAN_VOLTS * GAMING_HUB_TESLA_PLAN_AMPS );
define( 'GAMING_HUB_TESLA_PLAN_TARGET_SOC', 80 );
define( 'GAMING_HUB_TESLA_PLAN_MIN_SOC', 10 );
define( 'GAMING_HUB_TESLA_PLAN_CACHE_TTL', 10 * MINUTE_IN_SECONDS );
define( 'GAMING_HUB_TESLA_PLAN_CACHE_PREFIX', 'gaming_hub_tesla_plan_v3_' );

/**
 * Short label for home AC charging (200V 普通充電).
 */
function gaming_hub_tesla_plan_charge_label() {
	return sprintf(
		/* translators: %s: volts */
		__( '%sV 普通充電', 'gaming-hub' ),
		number_format_i18n( GAMING_HUB_TESLA_PLAN_VOLTS )
	);
}

/**
 * Typical commute hours used when no hourly odometer log exists.
 *
 * @return array<int, int>
 */
function gaming_hub_tesla_plan_commute_hours() {
	return array( 7, 8, 17, 18 );
}

/**
 * Battery kWh used for SOC math.
 */
function gaming_hub_tesla_plan_battery_kwh() {
	return defined( 'GAMING_HUB_MODEL3_BATTERY_KWH' ) ? (float) GAMING_HUB_MODEL3_BATTERY_KWH : 60.0;
}

/**
 * Daily km target.
 */
function gaming_hub_tesla_plan_daily_km() {
	return defined( 'GAMING_HUB_MODEL3_DAILY_KM' ) ? (float) GAMING_HUB_MODEL3_DAILY_KM : 30.0;
}

/**
 * Wh per km.
 */
function gaming_hub_tesla_plan_wh_per_km() {
	return defined( 'GAMING_HUB_MODEL3_WH_PER_KM' ) ? (float) GAMING_HUB_MODEL3_WH_PER_KM : 150.0;
}

/**
 * Yesterday / today / tomorrow in site timezone.
 *
 * @return array{yesterday: string, today: string, tomorrow: string}
 */
function gaming_hub_tesla_plan_dates() {
	if ( function_exists( 'gaming_hub_ecoflow_plan_dates' ) ) {
		return gaming_hub_ecoflow_plan_dates();
	}

	$today = wp_date( 'Y-m-d' );
	$ts    = current_datetime()->getTimestamp();

	return array(
		'yesterday' => wp_date( 'Y-m-d', $ts - DAY_IN_SECONDS ),
		'today'     => $today,
		'tomorrow'  => wp_date( 'Y-m-d', $ts + DAY_IN_SECONDS ),
	);
}

/**
 * Hourly yen/kWh map.
 *
 * @param string $which yesterday|today|tomorrow.
 * @return array<int, float>
 */
function gaming_hub_tesla_plan_price_map( $which ) {
	if ( function_exists( 'gaming_hub_ecoflow_price_map_for_day' ) ) {
		return gaming_hub_ecoflow_price_map_for_day( $which );
	}

	$map      = array();
	$fallback = 30.0;
	if ( function_exists( 'gaming_hub_looop_hourly_price_map_today' ) ) {
		$price = gaming_hub_looop_hourly_price_map_today();
		if ( is_array( $price ) ) {
			$fallback = (float) ( $price['fallback'] ?? $fallback );
			foreach ( $price['map'] ?? array() as $hour => $yen ) {
				$map[ (int) $hour ] = (float) $yen;
			}
		}
	}

	for ( $h = 0; $h < 24; $h++ ) {
		if ( ! isset( $map[ $h ] ) ) {
			$map[ $h ] = $fallback;
		}
	}

	return $map;
}

/**
 * LOOOP labels.
 *
 * @return array{provider: string, note: string}
 */
function gaming_hub_tesla_plan_price_meta() {
	if ( function_exists( 'gaming_hub_ecoflow_smart_time_one_meta' ) ) {
		return gaming_hub_ecoflow_smart_time_one_meta();
	}

	return array(
		'provider' => __( 'LOOOP スマートタイムONE（電灯）', 'gaming-hub' ),
		'note'     => __( '中部エリア。請求単価 = 電源料金＋サービス料＋託送従量＋再エネ賦課金。', 'gaming-hub' ),
	);
}

/**
 * Range label for consecutive hours.
 *
 * @param int $start Start hour.
 * @param int $end   Inclusive end hour.
 */
function gaming_hub_tesla_plan_hour_range_label( $start, $end ) {
	if ( function_exists( 'gaming_hub_ecoflow_hour_range_label' ) ) {
		return gaming_hub_ecoflow_hour_range_label( $start, $end );
	}

	$until = ( $end + 1 ) % 24;
	if ( 0 === $until && 23 === (int) $end ) {
		return sprintf( '%d:00–24:00', $start );
	}

	return sprintf( '%d:00–%d:00', $start, $until );
}

/**
 * Group picked hours into ranges.
 *
 * @param array<int, array<string, mixed>> $picked Sorted by index.
 * @return array<int, string>
 */
function gaming_hub_tesla_plan_group_windows( array $picked ) {
	if ( function_exists( 'gaming_hub_ecoflow_group_hour_windows' ) ) {
		return gaming_hub_ecoflow_group_hour_windows( $picked );
	}

	if ( empty( $picked ) ) {
		return array();
	}

	$ranges = array();
	$start  = $picked[0];
	$prev   = $picked[0];
	for ( $i = 1, $n = count( $picked ); $i < $n; $i++ ) {
		$row = $picked[ $i ];
		if ( (int) $row['index'] === (int) $prev['index'] + 1 ) {
			$prev = $row;
			continue;
		}
		$ranges[] = gaming_hub_tesla_plan_hour_range_label( (int) $start['hour'], (int) $prev['hour'] );
		$start    = $row;
		$prev     = $row;
	}
	$ranges[] = gaming_hub_tesla_plan_hour_range_label( (int) $start['hour'], (int) $prev['hour'] );

	return $ranges;
}

/**
 * Prefer parked / overnight hours over commute hours.
 *
 * @param int $hour 0–23.
 */
function gaming_hub_tesla_plan_park_weight( $hour ) {
	$hour = (int) $hour;
	if ( in_array( $hour, gaming_hub_tesla_plan_commute_hours(), true ) ) {
		return 5.0;
	}
	if ( $hour >= 22 || $hour <= 6 ) {
		return 0.3;
	}

	return 1.0;
}

/**
 * Logged km for one date from the gas log.
 *
 * @param string $date Y-m-d.
 * @return array{km: float, hours: array<int, float>}
 */
function gaming_hub_tesla_plan_logged_drive( $date ) {
	$out = array(
		'km'    => 0.0,
		'hours' => array_fill( 0, 24, 0.0 ),
	);
	if ( ! function_exists( 'gaming_hub_tesla_gas_log_month_days' ) ) {
		return $out;
	}

	$days = gaming_hub_tesla_gas_log_month_days( substr( $date, 0, 7 ) );
	$row  = isset( $days[ $date ] ) && is_array( $days[ $date ] ) ? $days[ $date ] : null;
	if ( ! $row ) {
		return $out;
	}

	$out['km'] = max( 0, (float) ( $row['km'] ?? 0 ) );
	$hours     = isset( $row['hours'] ) && is_array( $row['hours'] ) ? $row['hours'] : array();
	for ( $h = 0; $h < 24; $h++ ) {
		$slot = isset( $hours[ $h ] ) && is_array( $hours[ $h ] ) ? $hours[ $h ] : array();
		$out['hours'][ $h ] = max( 0, (float) ( $slot['km'] ?? 0 ) );
	}

	return $out;
}

/**
 * Spread km across selected hours.
 *
 * @param float            $km    Distance.
 * @param array<int, int>  $hours Hour list.
 * @return array<int, float>
 */
function gaming_hub_tesla_plan_spread_km( $km, array $hours ) {
	$out = array_fill( 0, 24, 0.0 );
	$km  = max( 0, (float) $km );
	$n   = count( $hours );
	if ( $km < 0.05 || $n < 1 ) {
		return $out;
	}

	$each = round( $km / $n, 1 );
	$left = $km;
	foreach ( $hours as $i => $hour ) {
		$hour = (int) $hour;
		if ( $hour < 0 || $hour > 23 ) {
			continue;
		}
		$slice          = ( $i === $n - 1 ) ? round( $left, 1 ) : $each;
		$out[ $hour ]   = $slice;
		$left           = max( 0, $left - $slice );
	}

	return $out;
}

/**
 * 24 hourly driving km for a plan day.
 *
 * @param string $day      yesterday|today|tomorrow.
 * @param string $date     Y-m-d.
 * @param float  $today_km Live today's km.
 * @return array{km: float, remaining_km: float, hours: array<int, float>}
 */
function gaming_hub_tesla_plan_drive_profile( $day, $date, $today_km ) {
	$daily   = gaming_hub_tesla_plan_daily_km();
	$now     = (int) wp_date( 'G' );
	$commute = gaming_hub_tesla_plan_commute_hours();
	$logged  = gaming_hub_tesla_plan_logged_drive( $date );

	if ( 'yesterday' === $day ) {
		$km = $logged['km'] > 0.05 ? $logged['km'] : $daily;
		$sum_h = array_sum( $logged['hours'] );
		$hours = $sum_h >= 0.1 ? $logged['hours'] : gaming_hub_tesla_plan_spread_km( $km, $commute );

		return array(
			'km'           => round( $km, 1 ),
			'remaining_km' => 0.0,
			'hours'        => $hours,
		);
	}

	if ( 'tomorrow' === $day ) {
		return array(
			'km'           => $daily,
			'remaining_km' => $daily,
			'hours'        => gaming_hub_tesla_plan_spread_km( $daily, $commute ),
		);
	}

	$done      = max( 0, (float) $today_km );
	if ( $logged['km'] > $done ) {
		$done = $logged['km'];
	}
	$remaining = $now < 19 ? max( 0, $daily - $done ) : 0.0;
	$past_h    = array();
	$future_h  = array();
	foreach ( $commute as $hour ) {
		if ( $hour < $now ) {
			$past_h[] = $hour;
		} else {
			$future_h[] = $hour;
		}
	}

	$hours = array_fill( 0, 24, 0.0 );
	$log_h = array_sum( $logged['hours'] );
	if ( $log_h >= 0.1 ) {
		$hours = $logged['hours'];
	} elseif ( $done >= 0.1 ) {
		$hours = gaming_hub_tesla_plan_spread_km( $done, $past_h ? $past_h : $commute );
	}
	if ( $remaining >= 0.1 && $future_h ) {
		$extra = gaming_hub_tesla_plan_spread_km( $remaining, $future_h );
		for ( $h = 0; $h < 24; $h++ ) {
			$hours[ $h ] = round( (float) $hours[ $h ] + (float) $extra[ $h ], 1 );
		}
	}

	return array(
		'km'           => round( $done + $remaining, 1 ),
		'remaining_km' => round( $remaining, 1 ),
		'hours'        => $hours,
	);
}

/**
 * Pick cheapest parked hours.
 *
 * @param int    $from_hour   Current hour for today, else 0.
 * @param float  $deficit_kwh Energy to buy.
 * @param string $day         yesterday|today|tomorrow.
 * @return array{windows: array<int, string>, avg_yen: float|null, picked: array<int, array<string, mixed>>}
 */
function gaming_hub_tesla_plan_pick_hours( $from_hour, $deficit_kwh, $day ) {
	$dates     = gaming_hub_tesla_plan_dates();
	$today_map = gaming_hub_tesla_plan_price_map( 'today' );
	$day_map   = gaming_hub_tesla_plan_price_map( $day );
	$tom_map   = gaming_hub_tesla_plan_price_map( 'tomorrow' );
	$kwh_per_h = GAMING_HUB_TESLA_PLAN_CHARGE_W / 1000.0;
	$needed    = (int) ceil( max( 0, (float) $deficit_kwh ) / max( $kwh_per_h, 0.1 ) );

	if ( $needed < 1 ) {
		return array(
			'windows' => array(),
			'avg_yen' => null,
			'picked'  => array(),
		);
	}

	$candidates = array();
	if ( 'today' === $day ) {
		for ( $i = 0; $i < 18; $i++ ) {
			$abs        = $from_hour + $i;
			$h          = $abs % 24;
			$tomorrow_h = $abs >= 24;
			$yen        = $tomorrow_h
				? ( $tom_map[ $h ] ?? $today_map[ $h ] ?? 30 )
				: ( $today_map[ $h ] ?? 30 );
			$candidates[] = array(
				'hour'  => $h,
				'index' => $i,
				'yen'   => (float) $yen,
				'date'  => $tomorrow_h ? $dates['tomorrow'] : $dates['today'],
			);
		}
	} else {
		for ( $h = 0; $h < 24; $h++ ) {
			$candidates[] = array(
				'hour'  => $h,
				'index' => $h,
				'yen'   => (float) ( $day_map[ $h ] ?? 30 ),
				'date'  => $dates[ $day ] ?? $dates['today'],
			);
		}
	}

	usort(
		$candidates,
		static function ( $a, $b ) {
			if ( $a['yen'] !== $b['yen'] ) {
				return $a['yen'] <=> $b['yen'];
			}
			$wa = gaming_hub_tesla_plan_park_weight( (int) $a['hour'] );
			$wb = gaming_hub_tesla_plan_park_weight( (int) $b['hour'] );
			if ( $wa !== $wb ) {
				return $wa <=> $wb;
			}

			return $a['index'] <=> $b['index'];
		}
	);

	$picked = array_slice( $candidates, 0, $needed );
	usort(
		$picked,
		static function ( $a, $b ) {
			return $a['index'] <=> $b['index'];
		}
	);
	$avg = $picked ? array_sum( array_column( $picked, 'yen' ) ) / count( $picked ) : null;

	return array(
		'windows' => gaming_hub_tesla_plan_group_windows( $picked ),
		'avg_yen' => null === $avg ? null : round( $avg, 1 ),
		'picked'  => $picked,
	);
}

/**
 * Simulate hourly SOC.
 *
 * @param float                $start_soc Start %.
 * @param array<int, float>    $drive_km  Hourly km.
 * @param array<int, int>      $charge_w  Hourly charge watts.
 * @param int                  $from_hour First hour to simulate (today).
 * @return array<int, float|null>
 */
function gaming_hub_tesla_plan_soc_series( $start_soc, array $drive_km, array $charge_w, $from_hour = 0 ) {
	$battery = max( 1, gaming_hub_tesla_plan_battery_kwh() );
	$wh_km   = gaming_hub_tesla_plan_wh_per_km();
	$soc     = max( 0, min( 100, (float) $start_soc ) );
	$series  = array();

	for ( $h = 0; $h < 24; $h++ ) {
		if ( $h < $from_hour ) {
			$series[ $h ] = null;
			continue;
		}
		$net_kwh      = ( (int) ( $charge_w[ $h ] ?? 0 ) / 1000.0 ) - ( ( (float) ( $drive_km[ $h ] ?? 0 ) ) * $wh_km / 1000.0 );
		$soc          = max( GAMING_HUB_TESLA_PLAN_MIN_SOC, min( 100, $soc + ( $net_kwh / $battery ) * 100 ) );
		$series[ $h ] = round( $soc, 1 );
	}

	return $series;
}

/**
 * Build 24 slots (+ overnight charge extras).
 *
 * @param string                                 $date      View date.
 * @param string                                 $day       yesterday|today|tomorrow.
 * @param array<int, float>                      $drive_km  Hourly km.
 * @param array<int, array<string, mixed>>       $picked    Charge hours.
 * @param array<int, float>                      $yen_map   Prices.
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_tesla_plan_slots( $date, $day, array $drive_km, array $picked, array $yen_map ) {
	$now     = (int) wp_date( 'G' );
	$is_today = 'today' === $day;
	$charge  = array();
	$extra   = array();

	foreach ( $picked as $row ) {
		$key = (string) ( $row['date'] ?? $date ) . '-' . (int) $row['hour'];
		$charge[ $key ] = $row;
		if ( (string) ( $row['date'] ?? $date ) !== $date ) {
			$extra[] = $row;
		}
	}

	$slots = array();
	for ( $h = 0; $h < 24; $h++ ) {
		$key      = $date . '-' . $h;
		$is_chg   = isset( $charge[ $key ] );
		$is_past  = $is_today && $h < $now;
		$km       = (float) ( $drive_km[ $h ] ?? 0 );
		$mode     = 'idle';
		if ( $is_past ) {
			$mode = $is_chg ? 'charge' : ( $km >= 0.2 ? 'drive' : 'past' );
		} elseif ( $is_chg ) {
			$mode = 'charge';
		} elseif ( $km >= 0.2 ) {
			$mode = 'drive';
		}

		$slots[] = array(
			'id'      => $date . 'T' . sprintf( '%02d', $h ),
			'date'    => $date,
			'hour'    => $h,
			'label'   => gaming_hub_tesla_plan_hour_range_label( $h, $h ),
			'mode'    => $mode,
			'watts'   => $is_chg && ! $is_past ? GAMING_HUB_TESLA_PLAN_CHARGE_W : ( $is_chg ? GAMING_HUB_TESLA_PLAN_CHARGE_W : null ),
			'drive_km'=> $km > 0 ? $km : null,
			'yen'     => isset( $yen_map[ $h ] ) ? (float) $yen_map[ $h ] : null,
			'past'    => $is_past,
		);
	}

	foreach ( $extra as $row ) {
		$h = (int) $row['hour'];
		$slots[] = array(
			'id'       => (string) $row['date'] . 'T' . sprintf( '%02d', $h ),
			'date'     => (string) $row['date'],
			'hour'     => $h,
			'label'    => gaming_hub_tesla_plan_hour_range_label( $h, $h ),
			'mode'     => 'charge',
			'watts'    => GAMING_HUB_TESLA_PLAN_CHARGE_W,
			'drive_km' => null,
			'yen'      => (float) ( $row['yen'] ?? 0 ),
			'past'     => false,
		);
	}

	return $slots;
}

/**
 * One-day plan slice.
 *
 * @param string               $day     yesterday|today|tomorrow.
 * @param array<string, mixed> $ctx     Shared context.
 * @param float                $start_soc Start SOC.
 */
function gaming_hub_tesla_plan_build_day( $day, array $ctx, $start_soc ) {
	$dates      = $ctx['dates'];
	$date       = $dates[ $day ];
	$now_hour   = (int) $ctx['now_hour'];
	$target     = (int) $ctx['target_soc'];
	$battery    = gaming_hub_tesla_plan_battery_kwh();
	$wh_km      = gaming_hub_tesla_plan_wh_per_km();
	$drive      = gaming_hub_tesla_plan_drive_profile( $day, $date, (float) $ctx['today_km'] );
	$from_hour  = 'today' === $day ? $now_hour : 0;
	$yen_map    = gaming_hub_tesla_plan_price_map( $day );

	$future_km = 0.0;
	for ( $h = $from_hour; $h < 24; $h++ ) {
		$future_km += (float) ( $drive['hours'][ $h ] ?? 0 );
	}
	if ( 'today' === $day ) {
		$future_km = max( $future_km, (float) $drive['remaining_km'] );
	}

	$start_kwh    = max( 0, ( (float) $start_soc / 100.0 ) * $battery );
	$target_kwh   = ( $target / 100.0 ) * $battery;
	$drive_kwh    = $future_km * $wh_km / 1000.0;
	$projected    = $start_kwh - $drive_kwh;
	$deficit_kwh  = max( 0, $target_kwh - $projected );
	if ( $deficit_kwh < 0.05 ) {
		$deficit_kwh = 0.0;
	}

	$pick     = gaming_hub_tesla_plan_pick_hours( $from_hour, $deficit_kwh, $day );
	$charge_w = array_fill( 0, 24, 0 );
	foreach ( $pick['picked'] as $row ) {
		if ( (string) ( $row['date'] ?? $date ) === $date ) {
			$charge_w[ (int) $row['hour'] ] = GAMING_HUB_TESLA_PLAN_CHARGE_W;
		}
	}

	$soc_series = gaming_hub_tesla_plan_soc_series( $start_soc, $drive['hours'], $charge_w, $from_hour );
	$soc_end    = null;
	for ( $h = 23; $h >= 0; $h-- ) {
		if ( null !== $soc_series[ $h ] ) {
			$soc_end = (float) $soc_series[ $h ];
			break;
		}
	}

	$needs_grid = $deficit_kwh >= 0.05;
	$titles     = array(
		'yesterday' => __( '昨日の充電計画', 'gaming-hub' ),
		'today'     => __( '今日の充電計画', 'gaming-hub' ),
		'tomorrow'  => __( '明日の充電計画', 'gaming-hub' ),
	);
	$window     = $needs_grid
		? ( $pick['windows'] ? implode( '、', $pick['windows'] ) : '—' )
		: __( 'グリッド充電不要', 'gaming-hub' );

	if ( 'yesterday' === $day ) {
		$note = $needs_grid
			? sprintf(
				/* translators: 1: km, 2: charge kWh */
				__( '昨日の走行 %1$s km をまかなうなら、その日の最安時間に %2$s kWh 充電する計画です。', 'gaming-hub' ),
				number_format_i18n( $drive['km'], 1 ),
				number_format_i18n( $deficit_kwh, 1 )
			)
			: __( '昨日の走行見込みでは追加のグリッド充電は不要でした。', 'gaming-hub' );
		$km_label      = __( '走行実績', 'gaming-hub' );
		$deficit_label = __( '推奨だった充電', 'gaming-hub' );
	} elseif ( 'tomorrow' === $day ) {
		$note = $needs_grid
			? sprintf(
				/* translators: 1: watts, 2: window */
				__( '明日の %1$s km 走行と目標残量を踏まえ、スマートタイムONEの最安時間（%2$s）に 200V 普通充電します。', 'gaming-hub' ),
				number_format_i18n( $drive['km'], 0 ),
				$window
			)
			: __( '明日の走行見込みでは追加のグリッド充電は不要です。', 'gaming-hub' );
		$km_label      = __( '予想走行', 'gaming-hub' );
		$deficit_label = sprintf(
			/* translators: %s: target SOC */
			__( '%s%%までの充電', 'gaming-hub' ),
			number_format_i18n( $target )
		);
	} else {
		$note = $needs_grid
			? sprintf(
				/* translators: 1: charge watts, 2: remaining km */
				__( '残りの走行 %2$s km と目標残量を踏まえ、スマートタイムONEの最安時間に 200V 普通充電（%1$s kW）します。Tesla アプリの予約充電と合わせて使ってください。', 'gaming-hub' ),
				number_format_i18n( GAMING_HUB_TESLA_PLAN_CHARGE_W / 1000, 1 ),
				number_format_i18n( $drive['remaining_km'], 1 )
			)
			: __( 'いまの残量と残りの走行では、追加のグリッド充電は不要です。', 'gaming-hub' );
		$km_label      = __( '残り走行', 'gaming-hub' );
		$deficit_label = sprintf(
			/* translators: %s: target SOC */
			__( '%s%%までの充電', 'gaming-hub' ),
			number_format_i18n( $target )
		);
	}

	$charge_keys = array();
	foreach ( $pick['picked'] as $row ) {
		$charge_keys[ (string) $row['date'] . '-' . (int) $row['hour'] ] = true;
	}

	$drive_w = array();
	for ( $h = 0; $h < 24; $h++ ) {
		$drive_w[ $h ] = (int) round( ( (float) ( $drive['hours'][ $h ] ?? 0 ) ) * $wh_km );
	}

	$gas = function_exists( 'gaming_hub_tesla_gas_metrics_from_km' )
		? gaming_hub_tesla_gas_metrics_from_km( $drive['km'] )
		: array();

	$plan_id = md5(
		$date . '|' . $target . '|' . implode(
			',',
			array_map(
				static function ( $row ) {
					return ( $row['date'] ?? '' ) . ':' . ( $row['hour'] ?? '' );
				},
				$pick['picked']
			)
		)
	);

	return array(
		'plan_date'         => $date,
		'plan_day'          => $day,
		'plan_id'           => $plan_id,
		'title'             => $titles[ $day ] ?? $titles['today'],
		'note'              => $note,
		'needs_grid'        => $needs_grid,
		'deficit_kwh'       => round( $deficit_kwh, 1 ),
		'target_soc'        => $target,
		'start_soc'         => round( (float) $start_soc, 1 ),
		'projected_soc'     => null !== $soc_end ? round( $soc_end, 1 ) : null,
		'window_label'      => $window,
		'window_avg_yen'    => $pick['avg_yen'],
		'km'                => $drive['km'],
		'remaining_km'      => $drive['remaining_km'],
		'km_hud'            => 'today' === $day ? $drive['remaining_km'] : $drive['km'],
		'km_hud_label'      => $km_label,
		'deficit_hud_label' => $deficit_label,
		'slots'             => gaming_hub_tesla_plan_slots( $date, $day, $drive['hours'], $pick['picked'], $yen_map ),
		'soc_series'        => $soc_series,
		'soc_now'           => 'today' === $day ? round( (float) $start_soc, 1 ) : ( $soc_series[0] ?? null ),
		'soc_end'           => $soc_end,
		'drive_hours'       => $drive['hours'],
		'drive_chart'       => $drive_w,
		'drive_chart_cap'   => max( 1, (int) ceil( max( $drive_w ?: array( 0 ) ) / 100 ) * 100 ),
		'charge_w'          => GAMING_HUB_TESLA_PLAN_CHARGE_W,
		'charge_v'          => GAMING_HUB_TESLA_PLAN_VOLTS,
		'charge_a'          => GAMING_HUB_TESLA_PLAN_AMPS,
		'charge_label'      => gaming_hub_tesla_plan_charge_label(),
		'price_provider'    => $ctx['price_meta']['provider'],
		'price_note'        => $ctx['price_meta']['note'],
		'saved_yen'         => isset( $gas['saved_yen'] ) ? (int) $gas['saved_yen'] : 0,
		'gas_l'             => isset( $gas['gas_l'] ) ? (float) $gas['gas_l'] : 0,
		'ev_yen'            => isset( $gas['ev_yen'] ) ? (int) $gas['ev_yen'] : 0,
		'live'              => ! empty( $ctx['live'] ),
	);
}

/**
 * Build today + neighbor days.
 *
 * @param array<string, mixed>|null $status Live status.
 * @return array<string, mixed>
 */
function gaming_hub_tesla_get_charge_plan( $status = null ) {
	$status  = is_array( $status ) ? $status : array();
	$model3  = is_array( $status['model3'] ?? null ) ? $status['model3'] : array();
	$flow    = is_array( $status['tesla_flow'] ?? null ) ? $status['tesla_flow'] : array();
	$live    = 'tesla' === (string) ( $status['model3_source'] ?? '' ) && ! empty( $flow['live'] );
	$soc     = isset( $model3['battery_percent'] ) && is_numeric( $model3['battery_percent'] )
		? max( 0, min( 100, (float) $model3['battery_percent'] ) )
		: ( $live ? 50.0 : 55.0 );
	$limit   = isset( $model3['charge_limit_percent'] ) && is_numeric( $model3['charge_limit_percent'] )
		? max( 50, min( 100, (int) $model3['charge_limit_percent'] ) )
		: GAMING_HUB_TESLA_PLAN_TARGET_SOC;
	$today_km = isset( $model3['today_km'] ) && is_numeric( $model3['today_km'] )
		? max( 0, (float) $model3['today_km'] )
		: 0.0;
	if ( ! $live && $today_km <= 0 && function_exists( 'gaming_hub_tesla_gas_log_month_days' ) ) {
		$logged = gaming_hub_tesla_plan_logged_drive( wp_date( 'Y-m-d' ) );
		$today_km = $logged['km'];
	}

	$now_hour = (int) wp_date( 'G' );
	$cache_key = GAMING_HUB_TESLA_PLAN_CACHE_PREFIX . wp_date( 'Y-m-d' ) . '_' . $now_hour . '_' . (int) floor( $soc / 5 ) . '_' . (int) round( $today_km ) . '_' . $limit . '_' . ( $live ? '1' : '0' );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) && ! empty( $cached['plan_id'] ) ) {
		return $cached;
	}

	$ctx = array(
		'dates'      => gaming_hub_tesla_plan_dates(),
		'now_hour'   => $now_hour,
		'target_soc' => $limit,
		'today_km'   => $today_km,
		'live'       => $live,
		'price_meta' => gaming_hub_tesla_plan_price_meta(),
	);

	$today     = gaming_hub_tesla_plan_build_day( 'today', $ctx, $soc );
	$end_soc   = isset( $today['soc_end'] ) && is_numeric( $today['soc_end'] ) ? (float) $today['soc_end'] : $soc;
	$yesterday = gaming_hub_tesla_plan_build_day( 'yesterday', $ctx, (float) $limit );
	$tomorrow  = gaming_hub_tesla_plan_build_day( 'tomorrow', $ctx, $end_soc );

	$today['view_days'] = array(
		'yesterday' => $yesterday,
		'tomorrow'  => $tomorrow,
	);
	$today['updated_at'] = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );

	set_transient( $cache_key, $today, GAMING_HUB_TESLA_PLAN_CACHE_TTL );

	return $today;
}

/**
 * Render Tesla AI PLAN.
 *
 * @param array<string, mixed>|null $status Status.
 */
function gaming_hub_render_tesla_plan( $status = null ) {
	$plan = is_array( $status['tesla_plan'] ?? null )
		? $status['tesla_plan']
		: gaming_hub_tesla_get_charge_plan( $status );

	get_template_part(
		'template-parts/tesla',
		'plan',
		array(
			'plan' => $plan,
		)
	);
}

/**
 * REST: GET /gaming-hub/v1/tesla/plan
 */
function gaming_hub_register_tesla_plan_rest() {
	register_rest_route(
		'gaming-hub/v1',
		'/tesla/plan',
		array(
			'methods'             => 'GET',
			'callback'            => 'gaming_hub_rest_tesla_plan',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'gaming_hub_register_tesla_plan_rest' );

/**
 * REST callback.
 */
function gaming_hub_rest_tesla_plan() {
	$status = function_exists( 'gaming_hub_get_powerwall_flow_status' )
		? gaming_hub_get_powerwall_flow_status()
		: array();

	return new WP_REST_Response(
		array(
			'success' => true,
			'data'    => isset( $status['tesla_plan'] ) && is_array( $status['tesla_plan'] )
				? $status['tesla_plan']
				: gaming_hub_tesla_get_charge_plan( $status ),
		),
		200
	);
}

/**
 * Enqueue Tesla plan script.
 */
function gaming_hub_tesla_plan_scripts() {
	if ( ! is_tag( 'tesla' ) && ! is_page( 'powerwall' ) ) {
		return;
	}

	wp_enqueue_script(
		'gaming-hub-tesla-plan',
		get_template_directory_uri() . '/assets/js/tesla-plan.js',
		array( 'gaming-hub-i18n' ),
		GAMING_HUB_VERSION,
		true
	);

	wp_localize_script(
		'gaming-hub-tesla-plan',
		'gamingHubTeslaPlan',
		array(
			'url' => (string) wp_parse_url( rest_url( 'gaming-hub/v1/tesla/plan' ), PHP_URL_PATH ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'gaming_hub_tesla_plan_scripts' );
