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
/** Daily charge cap: lithium-ion health band (do not sit at 100%). */
define( 'GAMING_HUB_TESLA_PLAN_TARGET_SOC', 80 );
/** Avoid regular deep discharge. */
define( 'GAMING_HUB_TESLA_PLAN_MIN_SOC', 20 );
/** Weekly calibration / weekend full charge. */
define( 'GAMING_HUB_TESLA_PLAN_SATURDAY_SOC', 100 );
/** Hit 100% by this hour on Saturday (charge during 00:00–06:00). */
define( 'GAMING_HUB_TESLA_PLAN_SATURDAY_HOUR', 6 );
/** Friday hour when the overnight boost window opens. */
define( 'GAMING_HUB_TESLA_PLAN_BOOST_START_HOUR', 22 );
define( 'GAMING_HUB_TESLA_PLAN_CACHE_TTL', 10 * MINUTE_IN_SECONDS );
define( 'GAMING_HUB_TESLA_PLAN_CACHE_PREFIX', 'gaming_hub_tesla_plan_v9_' );
define( 'GAMING_HUB_TESLA_PLAN_AUTO_OPTION', 'gaming_hub_tesla_plan_auto_v1' );
define( 'GAMING_HUB_TESLA_PLAN_AUTO_LOCK', 'gaming_hub_tesla_plan_auto_lock' );
/** Max automatic wakes per day (AI PLAN cron). Manual ON/OFF is not limited. */
define( 'GAMING_HUB_TESLA_WAKE_BUDGET_KEY', 'gaming_hub_tesla_wake_budget_v1' );
/** Frozen SOC while the vehicle is asleep (do not drift the chart). */
define( 'GAMING_HUB_TESLA_SLEEP_SOC_OPTION', 'gaming_hub_tesla_sleep_soc_v1' );
define( 'GAMING_HUB_TESLA_WAKE_BUDGET_MAX', 4 );

/** Measured hourly SOC, so the plan chart can show today's past hours. */
define( 'GAMING_HUB_TESLA_SOC_LOG_OPTION', 'gaming_hub_tesla_soc_log_v1' );
define( 'GAMING_HUB_TESLA_SOC_LOG_DAYS', 4 );
/** Hourly charge input type for past bands on the AI PLAN chart. */
define( 'GAMING_HUB_TESLA_CHARGE_INPUT_LOG_OPTION', 'gaming_hub_tesla_charge_input_log_v1' );
define( 'GAMING_HUB_TESLA_CHARGE_INPUT_LOG_DAYS', 4 );

/**
 * Record the vehicle SOC against the hour it was measured in.
 *
 * The plan simulates forward from the SOC we can see right now, so it has no way
 * to work out what the battery was at 08:00. Logging each reading gives those
 * past hours a real value instead of a hole in the chart.
 *
 * @param int|float $soc Battery percent.
 */
function gaming_hub_tesla_soc_log_record( $soc ) {
	if ( ! is_numeric( $soc ) ) {
		return;
	}

	$soc = max( 0, min( 100, (float) $soc ) );
	if ( $soc <= 0 ) {
		return;
	}

	$today = wp_date( 'Y-m-d' );
	$hour  = (int) wp_date( 'G' );
	$log   = get_option( GAMING_HUB_TESLA_SOC_LOG_OPTION, array() );
	$log   = is_array( $log ) ? $log : array();

	// Latest reading in the hour wins, matching the simulation's end-of-hour SOC.
	$log[ $today ][ $hour ] = round( $soc, 1 );

	krsort( $log );
	$log = array_slice( $log, 0, GAMING_HUB_TESLA_SOC_LOG_DAYS, true );

	update_option( GAMING_HUB_TESLA_SOC_LOG_OPTION, $log, false );
}

/**
 * Valid charge-input keys for the plan chart.
 *
 * @return array<int, string>
 */
function gaming_hub_tesla_charge_input_types() {
	return array( 'home_ac', 'away_ac', 'dc' );
}

/**
 * Human label for a charge-input key.
 *
 * @param string $type home_ac|away_ac|dc.
 */
function gaming_hub_tesla_charge_input_label( $type ) {
	switch ( (string) $type ) {
		case 'away_ac':
			return __( '外出先 AC', 'gaming-hub' );
		case 'dc':
			return __( 'DC 入力', 'gaming-hub' );
		case 'home_ac':
		default:
			return __( '自宅 AC', 'gaming-hub' );
	}
}

/**
 * Record which input type was charging during this hour.
 *
 * @param string $type home_ac|away_ac|dc.
 */
function gaming_hub_tesla_charge_input_log_record( $type ) {
	$type = (string) $type;
	if ( ! in_array( $type, gaming_hub_tesla_charge_input_types(), true ) ) {
		return;
	}

	$today = wp_date( 'Y-m-d' );
	$hour  = (int) wp_date( 'G' );
	$log   = get_option( GAMING_HUB_TESLA_CHARGE_INPUT_LOG_OPTION, array() );
	$log   = is_array( $log ) ? $log : array();

	if ( ! isset( $log[ $today ] ) || ! is_array( $log[ $today ] ) ) {
		$log[ $today ] = array();
	}

	$log[ $today ][ $hour ] = $type;

	krsort( $log );
	$log = array_slice( $log, 0, GAMING_HUB_TESLA_CHARGE_INPUT_LOG_DAYS, true );

	update_option( GAMING_HUB_TESLA_CHARGE_INPUT_LOG_OPTION, $log, false );
}

/**
 * Whether a charge session overlaps an hour on a date.
 *
 * @param string $date   Y-m-d.
 * @param int    $hour   0–23.
 * @param int    $start  Session start (unix).
 * @param int    $end    Session end (unix).
 */
function gaming_hub_tesla_charge_input_hour_overlaps( $date, $hour, $start, $end ) {
	if ( $start <= 0 || $end <= $start ) {
		return false;
	}

	try {
		$tz         = wp_timezone();
		$hour_start = new DateTimeImmutable( $date . ' ' . sprintf( '%02d:00:00', max( 0, min( 23, (int) $hour ) ) ), $tz );
		$hour_end   = $hour_start->modify( '+1 hour' );
		$sess_start = ( new DateTimeImmutable( '@' . $start ) )->setTimezone( $tz );
		$sess_end   = ( new DateTimeImmutable( '@' . $end ) )->setTimezone( $tz );

		return $sess_start < $hour_end && $sess_end > $hour_start;
	} catch ( Exception $e ) {
		return false;
	}
}

/**
 * Infer hourly input types from archived charge sessions.
 *
 * @param string $date Y-m-d.
 * @return array<int, string>
 */
function gaming_hub_tesla_charge_input_log_from_sessions( $date ) {
	$map = array();
	if ( ! function_exists( 'gaming_hub_tesla_charge_log_sessions' ) ) {
		return $map;
	}

	$sessions = gaming_hub_tesla_charge_log_sessions();
	$current  = function_exists( 'gaming_hub_tesla_charge_log_current' )
		? gaming_hub_tesla_charge_log_current()
		: null;
	if ( is_array( $current ) && ! empty( $current['start_ts'] ) ) {
		array_unshift( $sessions, $current );
	}

	foreach ( $sessions as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$start_ts = (int) ( $row['start_ts'] ?? 0 );
		$end_ts   = (int) ( $row['end_ts'] ?? 0 );
		if ( $start_ts <= 0 ) {
			continue;
		}
		if ( $end_ts <= $start_ts ) {
			$end_ts = time();
		}

		$supply = (string) ( $row['supply'] ?? 'home' );
		if ( 'supercharger' === $supply ) {
			$type = 'dc';
		} elseif ( 'home' === $supply ) {
			$type = 'home_ac';
		} else {
			continue;
		}

		for ( $h = 0; $h < 24; $h++ ) {
			if ( gaming_hub_tesla_charge_input_hour_overlaps( $date, $h, $start_ts, $end_ts ) ) {
				$map[ $h ] = $type;
			}
		}
	}

	return $map;
}

/**
 * Hourly charge-input map for a date (logged polls + session backfill).
 *
 * @param string $date Y-m-d.
 * @return array<int, string>
 */
function gaming_hub_tesla_charge_input_log_for_date( $date ) {
	$date = (string) $date;
	$out  = array();
	$log  = get_option( GAMING_HUB_TESLA_CHARGE_INPUT_LOG_OPTION, array() );
	$log  = is_array( $log ) ? $log : array();

	if ( isset( $log[ $date ] ) && is_array( $log[ $date ] ) ) {
		foreach ( $log[ $date ] as $hour => $type ) {
			$type = (string) $type;
			if ( in_array( $type, gaming_hub_tesla_charge_input_types(), true ) ) {
				$out[ (int) $hour ] = $type;
			}
		}
	}

	foreach ( gaming_hub_tesla_charge_input_log_from_sessions( $date ) as $hour => $type ) {
		if ( ! isset( $out[ $hour ] ) ) {
			$out[ $hour ] = $type;
		}
	}

	ksort( $out );

	return $out;
}

/**
 * Logged charge-input key for a past hour, if any.
 *
 * @param array<string, mixed> $slot    Slot row.
 * @param bool                 $is_past Hour is before now on today.
 * @return string home_ac|away_ac|dc|''
 */
function gaming_hub_tesla_plan_slot_charge_history( array $slot, $is_past ) {
	if ( ! $is_past ) {
		return '';
	}

	$logged = (string) ( $slot['charge_input'] ?? '' );

	return in_array( $logged, gaming_hub_tesla_charge_input_types(), true ) ? $logged : '';
}

/**
 * Whether to draw a charge band on this hour.
 *
 * @param array<string, mixed> $slot              Slot row.
 * @param bool                 $plan_charge         Hour is in the AI charge plan.
 * @param bool                 $is_past             Hour is before now on today.
 * @param bool                 $is_live_now_charge  Charging right now in this hour.
 */
function gaming_hub_tesla_plan_show_charge_bar( array $slot, $plan_charge, $is_past, $is_live_now_charge ) {
	if ( '' !== gaming_hub_tesla_plan_slot_charge_history( $slot, $is_past ) ) {
		return true;
	}

	if ( $is_live_now_charge ) {
		return true;
	}

	return $plan_charge && ! $is_past;
}

/**
 * Charge-bar tone for a plan slot (plan = future gold, input types = past actual).
 *
 * @param array<string, mixed> $slot         Slot row.
 * @param bool                 $is_charge    Whether the bar is shown.
 * @param bool                 $is_past      Hour is before now on today.
 * @param bool                 $is_live_now  Charging right now in this hour.
 * @param string               $live_input   Live input_type from status.
 * @return string plan|home_ac|away_ac|dc
 */
function gaming_hub_tesla_plan_charge_bar_tone( array $slot, $is_charge, $is_past, $is_live_now, $live_input = '' ) {
	if ( ! $is_charge ) {
		return 'plan';
	}

	if ( $is_live_now && in_array( $live_input, gaming_hub_tesla_charge_input_types(), true ) ) {
		return $live_input;
	}

	$history = gaming_hub_tesla_plan_slot_charge_history( $slot, $is_past );
	if ( '' !== $history ) {
		return $history;
	}

	return 'plan';
}

/**
 * Frozen sleep SOC state.
 *
 * @return array{soc: float, date: string, hour: int, at: int}|null
 */
function gaming_hub_tesla_sleep_soc_state() {
	$raw = get_option( GAMING_HUB_TESLA_SLEEP_SOC_OPTION, null );
	if ( ! is_array( $raw ) || ! isset( $raw['soc'] ) || ! is_numeric( $raw['soc'] ) ) {
		return null;
	}

	$soc = max( 0, min( 100, (float) $raw['soc'] ) );
	if ( $soc <= 0 ) {
		return null;
	}

	return array(
		'soc'  => round( $soc, 1 ),
		'date' => (string) ( $raw['date'] ?? '' ),
		'hour' => isset( $raw['hour'] ) ? max( 0, min( 23, (int) $raw['hour'] ) ) : 0,
		'at'   => isset( $raw['at'] ) ? (int) $raw['at'] : 0,
	);
}

/**
 * Freeze battery % when the car first goes to sleep.
 *
 * @param int|float $soc Last known percent.
 */
function gaming_hub_tesla_sleep_soc_freeze( $soc ) {
	if ( ! is_numeric( $soc ) ) {
		return;
	}

	$soc = max( 0, min( 100, (float) $soc ) );
	if ( $soc <= 0 ) {
		return;
	}

	$existing = gaming_hub_tesla_sleep_soc_state();
	$today    = wp_date( 'Y-m-d' );
	// Keep the first freeze for this sleep session so the plateau hour does not drift.
	if ( $existing && $existing['date'] === $today && $existing['soc'] > 0 ) {
		return;
	}

	update_option(
		GAMING_HUB_TESLA_SLEEP_SOC_OPTION,
		array(
			'soc'  => round( $soc, 1 ),
			'date' => $today,
			'hour' => (int) wp_date( 'G' ),
			'at'   => time(),
		),
		false
	);
}

/**
 * Clear frozen sleep SOC after a live wake reading.
 */
function gaming_hub_tesla_sleep_soc_clear() {
	delete_option( GAMING_HUB_TESLA_SLEEP_SOC_OPTION );
}

/**
 * SOC to display / plan from while asleep (frozen) or live.
 *
 * @param int|float|null $fallback Last known live SOC.
 * @return float|null
 */
function gaming_hub_tesla_plan_held_soc( $fallback = null ) {
	$frozen = gaming_hub_tesla_sleep_soc_state();
	if ( $frozen ) {
		return (float) $frozen['soc'];
	}

	if ( null !== $fallback && is_numeric( $fallback ) ) {
		$soc = max( 0, min( 100, (float) $fallback ) );
		return $soc > 0 ? $soc : null;
	}

	return null;
}

/**
 * While asleep, pin measured hours to the frozen SOC so the chart does not drift.
 *
 * @param string            $date     Y-m-d.
 * @param array<int, float> $measured Logged hours.
 * @return array<int, float>
 */
function gaming_hub_tesla_plan_measured_with_sleep_hold( $date, array $measured ) {
	$frozen = gaming_hub_tesla_sleep_soc_state();
	if ( ! $frozen || $frozen['date'] !== $date ) {
		return $measured;
	}

	$today = wp_date( 'Y-m-d' );
	$end   = ( $date === $today ) ? (int) wp_date( 'G' ) : 23;
	$from  = (int) $frozen['hour'];
	for ( $h = $from; $h <= $end; $h++ ) {
		$measured[ $h ] = (float) $frozen['soc'];
	}

	return $measured;
}

/**
 * Measured hourly SOC for a date.
 *
 * @param string $date Y-m-d.
 * @return array<int, float>
 */
function gaming_hub_tesla_soc_log_for_date( $date ) {
	$log = get_option( GAMING_HUB_TESLA_SOC_LOG_OPTION, array() );
	if ( ! is_array( $log ) || ! is_array( $log[ $date ] ?? null ) ) {
		return array();
	}

	$hours = array();
	foreach ( $log[ $date ] as $hour => $soc ) {
		if ( is_numeric( $soc ) ) {
			$hours[ (int) $hour ] = (float) $soc;
		}
	}

	return $hours;
}

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
 * Live charge input type for AI PLAN (home AC / away AC / DC).
 *
 * @param array<string, mixed>|null $status Live status bundle.
 * @return array{type: string, label: string, watts: int, plugged: bool, charging: bool}
 */
function gaming_hub_tesla_plan_input_state( $status = null ) {
	$status   = is_array( $status ) ? $status : array();
	$flow     = is_array( $status['tesla_flow'] ?? null ) ? $status['tesla_flow'] : array();
	$model3   = is_array( $status['model3'] ?? null ) ? $status['model3'] : array();
	$kind     = (string) ( $flow['supply_kind'] ?? ( $model3['supply_kind'] ?? '' ) );
	$at_home  = array_key_exists( 'at_home', $model3 ) ? $model3['at_home'] : null;
	$plugged  = ! empty( $model3['plugged'] ) || in_array( $kind, array( 'home', 'supercharger' ), true );
	$charging = ! empty( $flow['is_charging'] ) || ! empty( $model3['is_charging'] );
	$wall_w   = (int) ( $flow['wall_w'] ?? 0 );
	$super_w  = (int) ( $flow['super_w'] ?? 0 );
	$watts    = max( $wall_w, $super_w, (int) ( $model3['watts'] ?? 0 ) );

	if ( 'supercharger' === $kind ) {
		return array(
			'type'     => 'dc',
			'label'    => __( 'DC 入力', 'gaming-hub' ),
			'watts'    => $charging ? max( $super_w, $watts ) : 0,
			'plugged'  => true,
			'charging' => $charging,
		);
	}

	if ( $plugged || 'home' === $kind ) {
		if ( false === $at_home ) {
			return array(
				'type'     => 'away_ac',
				'label'    => __( '外出先 AC', 'gaming-hub' ),
				'watts'    => $charging ? max( $wall_w, $watts ) : 0,
				'plugged'  => true,
				'charging' => $charging,
			);
		}

		if ( true === $at_home ) {
			return array(
				'type'     => 'home_ac',
				'label'    => __( '自宅 AC', 'gaming-hub' ),
				'watts'    => $charging ? max( $wall_w, $watts ) : 0,
				'plugged'  => true,
				'charging' => $charging,
			);
		}

		return array(
			'type'     => 'home_ac',
			'label'    => __( '拠点補給', 'gaming-hub' ),
			'watts'    => $charging ? max( $wall_w, $watts ) : 0,
			'plugged'  => $plugged || 'home' === $kind,
			'charging' => $charging,
		);
	}

	return array(
		'type'     => 'none',
		'label'    => __( '未接続', 'gaming-hub' ),
		'watts'    => 0,
		'plugged'  => false,
		'charging' => false,
	);
}

/**
 * Sub-label for the AI PLAN input HUD stat.
 *
 * @param array<string, mixed> $input Input state from gaming_hub_tesla_plan_input_state().
 */
function gaming_hub_tesla_plan_input_sub_label( array $input ) {
	if ( ! empty( $input['charging'] ) && ! empty( $input['watts'] ) ) {
		return number_format_i18n( (int) $input['watts'] ) . ' W';
	}

	if ( ! empty( $input['plugged'] ) ) {
		return __( '接続中', 'gaming-hub' );
	}

	return '—';
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
 * Next calendar date in the site timezone.
 *
 * @param string $date Y-m-d.
 */
function gaming_hub_tesla_plan_next_date( $date ) {
	try {
		$dt = new DateTimeImmutable( $date . ' 12:00:00', wp_timezone() );

		return $dt->modify( '+1 day' )->format( 'Y-m-d' );
	} catch ( Exception $e ) {
		return $date;
	}
}

/**
 * Weekday number for a date in the site timezone (0 = Sunday … 6 = Saturday).
 *
 * @param string $date Y-m-d.
 */
function gaming_hub_tesla_plan_date_w( $date ) {
	try {
		$dt = new DateTimeImmutable( $date . ' 12:00:00', wp_timezone() );

		return (int) $dt->format( 'w' );
	} catch ( Exception $e ) {
		return (int) wp_date( 'w' );
	}
}

/**
 * Friday (overnight boost into Saturday).
 *
 * @param string $date Y-m-d.
 */
function gaming_hub_tesla_plan_is_friday( $date ) {
	return 5 === gaming_hub_tesla_plan_date_w( $date );
}

/**
 * Saturday.
 *
 * @param string $date Y-m-d.
 */
function gaming_hub_tesla_plan_is_saturday( $date ) {
	return 6 === gaming_hub_tesla_plan_date_w( $date );
}

/**
 * Operating charge cap for a date/hour.
 *
 * Weekdays stay in the 20–80% health band. Saturday before 07:00 aims at 100%.
 *
 * @param string $date      Y-m-d.
 * @param int    $from_hour First hour still in play.
 */
function gaming_hub_tesla_plan_target_soc_for_date( $date, $from_hour = 0 ) {
	if ( gaming_hub_tesla_plan_is_saturday( $date ) && (int) $from_hour < GAMING_HUB_TESLA_PLAN_SATURDAY_HOUR ) {
		return GAMING_HUB_TESLA_PLAN_SATURDAY_SOC;
	}

	return GAMING_HUB_TESLA_PLAN_TARGET_SOC;
}

/**
 * Whether this date still needs the Friday-night → Saturday-morning 100% boost.
 *
 * @param string $date      Y-m-d.
 * @param int    $from_hour First hour still in play.
 */
function gaming_hub_tesla_plan_needs_saturday_boost( $date, $from_hour = 0 ) {
	if ( gaming_hub_tesla_plan_is_friday( $date ) ) {
		return true;
	}

	return gaming_hub_tesla_plan_is_saturday( $date ) && (int) $from_hour < GAMING_HUB_TESLA_PLAN_SATURDAY_HOUR;
}

/**
 * Yen/kWh for one hour on a calendar date.
 *
 * @param string $date Y-m-d.
 * @param int    $hour 0–23.
 */
function gaming_hub_tesla_plan_yen_for_date( $date, $hour ) {
	$dates = gaming_hub_tesla_plan_dates();
	$which = 'today';
	if ( $date === ( $dates['yesterday'] ?? '' ) ) {
		$which = 'yesterday';
	} elseif ( $date === ( $dates['tomorrow'] ?? '' ) ) {
		$which = 'tomorrow';
	} elseif ( $date !== ( $dates['today'] ?? '' ) ) {
		$which = 'tomorrow';
	}

	$map = gaming_hub_tesla_plan_price_map( $which );

	return (float) ( $map[ (int) $hour ] ?? 30 );
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
 * Charge-hour candidates for daily 80% fill or the Saturday 100% boost.
 *
 * Daily fill stays in the health band. Boost hours are Friday 22:00–Saturday 07:00
 * only, so the pack does not sit at 100% all Friday.
 *
 * @param string             $date      Y-m-d.
 * @param string             $day       yesterday|today|tomorrow.
 * @param int                $from_hour First hour still in play (today).
 * @param string             $mode      daily|boost.
 * @param array<string,bool> $exclude   Keys "Y-m-d-H" already picked.
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_tesla_plan_charge_candidates( $date, $day, $from_hour, $mode, array $exclude = array() ) {
	$dates      = gaming_hub_tesla_plan_dates();
	$from_hour  = (int) $from_hour;
	$candidates = array();
	$add        = static function ( $slot_date, $hour, $index ) use ( &$candidates, $exclude ) {
		$key = (string) $slot_date . '-' . (int) $hour;
		if ( isset( $exclude[ $key ] ) ) {
			return;
		}
		$candidates[] = array(
			'hour'  => (int) $hour,
			'index' => (int) $index,
			'yen'   => gaming_hub_tesla_plan_yen_for_date( $slot_date, $hour ),
			'date'  => (string) $slot_date,
		);
	};

	if ( 'boost' === $mode ) {
		if ( gaming_hub_tesla_plan_is_friday( $date ) ) {
			$sat   = gaming_hub_tesla_plan_next_date( $date );
			$index = 0;
			for ( $h = GAMING_HUB_TESLA_PLAN_BOOST_START_HOUR; $h < 24; $h++ ) {
				if ( 'today' === $day && $h < $from_hour ) {
					continue;
				}
				$add( $date, $h, $index );
				$index++;
			}
			for ( $h = 0; $h < GAMING_HUB_TESLA_PLAN_SATURDAY_HOUR; $h++ ) {
				$add( $sat, $h, $index );
				$index++;
			}
		} elseif ( gaming_hub_tesla_plan_is_saturday( $date ) ) {
			$start = ( 'today' === $day ) ? $from_hour : 0;
			$index = 0;
			for ( $h = $start; $h < GAMING_HUB_TESLA_PLAN_SATURDAY_HOUR; $h++ ) {
				$add( $date, $h, $index );
				$index++;
			}
		}

		return $candidates;
	}

	if ( gaming_hub_tesla_plan_is_saturday( $date ) && ( 'today' !== $day || $from_hour < GAMING_HUB_TESLA_PLAN_SATURDAY_HOUR ) ) {
		return array();
	}

	if ( 'today' === $day ) {
		if ( gaming_hub_tesla_plan_is_friday( $date ) ) {
			$index = 0;
			for ( $h = $from_hour; $h < 24; $h++ ) {
				$add( $date, $h, $index );
				$index++;
			}

			return $candidates;
		}

		for ( $i = 0; $i < 18; $i++ ) {
			$abs        = $from_hour + $i;
			$h          = $abs % 24;
			$tomorrow_h = $abs >= 24;
			$slot_date  = $tomorrow_h ? ( $dates['tomorrow'] ?? $date ) : $date;
			$add( $slot_date, $h, $i );
		}

		return $candidates;
	}

	for ( $h = 0; $h < 24; $h++ ) {
		$add( $date, $h, $h );
	}

	return $candidates;
}

/**
 * Sort picked hours and group consecutive windows (including overnight).
 *
 * @param array<int, array<string, mixed>> $picked Charge hours.
 * @return array{windows: array<int, string>, avg_yen: float|null, picked: array<int, array<string, mixed>>}
 */
function gaming_hub_tesla_plan_finalize_picks( array $picked ) {
	if ( empty( $picked ) ) {
		return array(
			'windows' => array(),
			'avg_yen' => null,
			'picked'  => array(),
		);
	}

	usort(
		$picked,
		static function ( $a, $b ) {
			$cmp = strcmp( (string) ( $a['date'] ?? '' ), (string) ( $b['date'] ?? '' ) );
			if ( 0 !== $cmp ) {
				return $cmp;
			}

			return (int) $a['hour'] <=> (int) $b['hour'];
		}
	);

	$ranges = array();
	$start  = $picked[0];
	$prev   = $picked[0];
	$n      = count( $picked );
	for ( $i = 1; $i < $n; $i++ ) {
		$row       = $picked[ $i ];
		$same_day  = (string) ( $row['date'] ?? '' ) === (string) ( $prev['date'] ?? '' );
		$next_hour = $same_day && (int) $row['hour'] === (int) $prev['hour'] + 1;
		$overnight = 23 === (int) $prev['hour']
			&& 0 === (int) $row['hour']
			&& (string) ( $row['date'] ?? '' ) === gaming_hub_tesla_plan_next_date( (string) ( $prev['date'] ?? '' ) );
		if ( $next_hour || $overnight ) {
			$prev = $row;
			continue;
		}
		$ranges[] = gaming_hub_tesla_plan_hour_range_label( (int) $start['hour'], (int) $prev['hour'] );
		$start    = $row;
		$prev     = $row;
	}
	$ranges[] = gaming_hub_tesla_plan_hour_range_label( (int) $start['hour'], (int) $prev['hour'] );

	foreach ( $picked as $i => $row ) {
		$picked[ $i ]['index'] = $i;
	}

	$avg = array_sum( array_column( $picked, 'yen' ) ) / count( $picked );

	return array(
		'windows' => $ranges,
		'avg_yen' => round( $avg, 1 ),
		'picked'  => $picked,
	);
}

/**
 * Pick cheapest parked hours from a candidate list.
 *
 * @param array<int, array<string, mixed>> $candidates Hours to consider.
 * @param float                            $deficit_kwh Energy to buy.
 * @return array{windows: array<int, string>, avg_yen: float|null, picked: array<int, array<string, mixed>>}
 */
function gaming_hub_tesla_plan_pick_from_candidates( array $candidates, $deficit_kwh ) {
	$kwh_per_h = GAMING_HUB_TESLA_PLAN_CHARGE_W / 1000.0;
	$needed    = (int) ceil( max( 0, (float) $deficit_kwh ) / max( $kwh_per_h, 0.1 ) );

	if ( $needed < 1 || empty( $candidates ) ) {
		return array(
			'windows' => array(),
			'avg_yen' => null,
			'picked'  => array(),
		);
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

	return gaming_hub_tesla_plan_finalize_picks( array_slice( $candidates, 0, $needed ) );
}

/**
 * Pick cheapest parked hours.
 *
 * @param int                $from_hour   Current hour for today, else 0.
 * @param float              $deficit_kwh Energy to buy.
 * @param string             $day         yesterday|today|tomorrow.
 * @param string             $date        Y-m-d.
 * @param string             $mode        daily|boost.
 * @param array<string,bool> $exclude     Already picked keys.
 * @return array{windows: array<int, string>, avg_yen: float|null, picked: array<int, array<string, mixed>>}
 */
/**
 * Drop the dearest hours if simulated SOC would climb past the health cap.
 *
 * @param float                            $start_soc Start %.
 * @param array<int, float>                $drive_km  Hourly km.
 * @param array<int, array<string, mixed>> $picked    Charge hours.
 * @param string                           $date      Y-m-d.
 * @param int                              $from_hour First hour to simulate.
 * @param int                              $cap       Max SOC %.
 * @return array{windows: array<int, string>, avg_yen: float|null, picked: array<int, array<string, mixed>>}
 */
function gaming_hub_tesla_plan_trim_picked_to_soc( $start_soc, array $drive_km, array $picked, $date, $from_hour, $cap ) {
	$picked   = array_values( $picked );
	$measured = gaming_hub_tesla_plan_measured_with_sleep_hold( $date, gaming_hub_tesla_soc_log_for_date( $date ) );
	$cap      = (float) $cap;

	while ( $picked ) {
		$charge_w = array_fill( 0, 24, 0 );
		foreach ( $picked as $row ) {
			if ( (string) ( $row['date'] ?? $date ) === $date ) {
				$charge_w[ (int) $row['hour'] ] = GAMING_HUB_TESLA_PLAN_CHARGE_W;
			}
		}
		$series = gaming_hub_tesla_plan_soc_series( $start_soc, $drive_km, $charge_w, $from_hour, $measured );
		$vals   = array_values( array_filter( $series, 'is_numeric' ) );
		$peak   = $vals ? max( $vals ) : 0.0;
		if ( $peak <= $cap + 0.5 ) {
			break;
		}

		$drop_i   = null;
		$drop_yen = -1.0;
		foreach ( $picked as $i => $row ) {
			if ( (string) ( $row['date'] ?? $date ) !== $date ) {
				continue;
			}
			if ( (float) $row['yen'] >= $drop_yen ) {
				$drop_yen = (float) $row['yen'];
				$drop_i   = $i;
			}
		}
		if ( null === $drop_i ) {
			break;
		}
		array_splice( $picked, $drop_i, 1 );
	}

	return gaming_hub_tesla_plan_finalize_picks( $picked );
}

function gaming_hub_tesla_plan_pick_hours( $from_hour, $deficit_kwh, $day, $date = '', $mode = 'daily', array $exclude = array() ) {
	if ( '' === $date ) {
		$dates = gaming_hub_tesla_plan_dates();
		$date  = $dates[ $day ] ?? $dates['today'];
	}

	return gaming_hub_tesla_plan_pick_from_candidates(
		gaming_hub_tesla_plan_charge_candidates( $date, $day, $from_hour, $mode, $exclude ),
		$deficit_kwh
	);
}

/**
 * Simulate hourly SOC.
 *
 * @param float                $start_soc Start %.
 * @param array<int, float>    $drive_km  Hourly km.
 * @param array<int, int>      $charge_w  Hourly charge watts.
 * @param int                  $from_hour First hour to simulate (today).
 * @param array<int, float>    $measured  Logged SOC for hours before $from_hour.
 * @return array<int, float|null>
 */
function gaming_hub_tesla_plan_soc_series( $start_soc, array $drive_km, array $charge_w, $from_hour = 0, array $measured = array() ) {
	$battery = max( 1, gaming_hub_tesla_plan_battery_kwh() );
	$wh_km   = gaming_hub_tesla_plan_wh_per_km();
	$soc     = max( 0, min( 100, (float) $start_soc ) );
	$series  = array();

	for ( $h = 0; $h < 24; $h++ ) {
		if ( $h < $from_hour ) {
			// Hours already gone cannot be simulated, but they may have been logged.
			$series[ $h ] = isset( $measured[ $h ] ) ? round( (float) $measured[ $h ], 1 ) : null;
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
	$input_log = gaming_hub_tesla_charge_input_log_for_date( $date );
	for ( $h = 0; $h < 24; $h++ ) {
		$key      = $date . '-' . $h;
		$is_chg   = isset( $charge[ $key ] );
		$is_past  = ( $is_today && $h < $now ) || ( 'yesterday' === $day );
		$logged   = $input_log[ $h ] ?? null;
		$km       = (float) ( $drive_km[ $h ] ?? 0 );
		$mode     = 'idle';
		if ( $is_past ) {
			$mode = $is_chg ? 'charge' : ( $km >= 0.2 ? 'drive' : 'past' );
		} elseif ( $is_chg ) {
			$mode = 'charge';
		} elseif ( $km >= 0.2 ) {
			$mode = 'drive';
		}

		if ( $is_past && $logged && 'charge' !== $mode ) {
			$mode   = 'charge';
			$is_chg = true;
		}

		$slots[] = array(
			'id'           => $date . 'T' . sprintf( '%02d', $h ),
			'date'         => $date,
			'hour'         => $h,
			'label'        => gaming_hub_tesla_plan_hour_range_label( $h, $h ),
			'mode'         => $mode,
			'watts'        => $is_chg && ! $is_past ? GAMING_HUB_TESLA_PLAN_CHARGE_W : ( $is_chg ? GAMING_HUB_TESLA_PLAN_CHARGE_W : null ),
			'drive_km'     => $km > 0 ? $km : null,
			'yen'          => isset( $yen_map[ $h ] ) ? (float) $yen_map[ $h ] : null,
			'past'         => $is_past,
			'charge_input' => is_string( $logged ) ? $logged : null,
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
	$battery    = gaming_hub_tesla_plan_battery_kwh();
	$wh_km      = gaming_hub_tesla_plan_wh_per_km();
	$drive      = gaming_hub_tesla_plan_drive_profile( $day, $date, (float) $ctx['today_km'] );
	$from_hour  = 'today' === $day ? $now_hour : 0;
	$yen_map    = gaming_hub_tesla_plan_price_map( $day );
	$daily_soc  = GAMING_HUB_TESLA_PLAN_TARGET_SOC;
	$sat_soc    = GAMING_HUB_TESLA_PLAN_SATURDAY_SOC;
	$is_friday  = gaming_hub_tesla_plan_is_friday( $date );
	$is_sat_am  = gaming_hub_tesla_plan_is_saturday( $date ) && $from_hour < GAMING_HUB_TESLA_PLAN_SATURDAY_HOUR;
	$boost      = gaming_hub_tesla_plan_needs_saturday_boost( $date, $from_hour );
	$car_limit  = isset( $ctx['car_limit'] ) ? (int) $ctx['car_limit'] : 0;

	$future_km = 0.0;
	for ( $h = $from_hour; $h < 24; $h++ ) {
		$future_km += (float) ( $drive['hours'][ $h ] ?? 0 );
	}
	if ( 'today' === $day ) {
		$future_km = max( $future_km, (float) $drive['remaining_km'] );
	}

	$start_kwh   = max( 0, ( (float) $start_soc / 100.0 ) * $battery );
	$daily_kwh   = ( $daily_soc / 100.0 ) * $battery;
	$sat_kwh     = ( $sat_soc / 100.0 ) * $battery;
	$drive_kwh   = $future_km * $wh_km / 1000.0;
	$projected   = $start_kwh - $drive_kwh;
	$deficit_daily = 0.0;
	$deficit_boost = 0.0;

	if ( $is_sat_am ) {
		$deficit_boost = max( 0, $sat_kwh - $projected );
		$target        = $sat_soc;
	} elseif ( $is_friday ) {
		$deficit_daily = max( 0, $daily_kwh - $projected );
		$after_daily   = max( $projected, $daily_kwh );
		$deficit_boost = max( 0, $sat_kwh - $after_daily );
		$target        = $sat_soc;
	} else {
		$deficit_daily = max( 0, $daily_kwh - $projected );
		$target        = $daily_soc;
	}

	if ( $deficit_daily < 0.05 ) {
		$deficit_daily = 0.0;
	}
	if ( $deficit_boost < 0.05 ) {
		$deficit_boost = 0.0;
	}

	$daily_pick = gaming_hub_tesla_plan_pick_hours( $from_hour, $deficit_daily, $day, $date, 'daily' );
	$exclude    = array();
	foreach ( $daily_pick['picked'] as $row ) {
		$exclude[ (string) ( $row['date'] ?? $date ) . '-' . (int) $row['hour'] ] = true;
	}
	$boost_pick = $boost
		? gaming_hub_tesla_plan_pick_hours( $from_hour, $deficit_boost, $day, $date, 'boost', $exclude )
		: array(
			'windows' => array(),
			'avg_yen' => null,
			'picked'  => array(),
		);
	$pick         = gaming_hub_tesla_plan_finalize_picks(
		array_merge( $daily_pick['picked'], $boost_pick['picked'] )
	);
	$soc_cap      = ( $is_friday || $is_sat_am )
		? GAMING_HUB_TESLA_PLAN_SATURDAY_SOC
		: GAMING_HUB_TESLA_PLAN_TARGET_SOC;
	$pick         = gaming_hub_tesla_plan_trim_picked_to_soc(
		$start_soc,
		$drive['hours'],
		$pick['picked'],
		$date,
		$from_hour,
		$soc_cap
	);
	$kwh_per_h    = GAMING_HUB_TESLA_PLAN_CHARGE_W / 1000.0;
	$deficit_kwh  = round( count( $pick['picked'] ) * $kwh_per_h, 1 );

	$charge_w = array_fill( 0, 24, 0 );
	foreach ( $pick['picked'] as $row ) {
		if ( (string) ( $row['date'] ?? $date ) === $date ) {
			$charge_w[ (int) $row['hour'] ] = GAMING_HUB_TESLA_PLAN_CHARGE_W;
		}
	}

	$soc_series = gaming_hub_tesla_plan_soc_series(
		$start_soc,
		$drive['hours'],
		$charge_w,
		// While asleep, keep the current hour on the frozen measured SOC — do not
		// apply planned charge/drive to the live remaining % until the car wakes.
		( ! empty( $ctx['asleep'] ) && 'today' === $day ) ? ( $now_hour + 1 ) : $from_hour,
		gaming_hub_tesla_plan_measured_with_sleep_hold(
			$date,
			gaming_hub_tesla_soc_log_for_date( $date )
		)
	);
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

	$cap_note = '';

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
		if ( ! $needs_grid ) {
			$note = __( '明日の走行見込みでは追加のグリッド充電は不要です。残量は 20–80% を目安にしています。', 'gaming-hub' );
		} elseif ( $is_sat_am ) {
			$note = sprintf(
				/* translators: 1: km, 2: window, 3: Saturday hour */
				__( '明日は土曜です。%1$s km 走行を踏まえ、朝 %3$s 時までに 100%% になるよう最安時間（%2$s）に 200V 普通充電します。', 'gaming-hub' ),
				number_format_i18n( $drive['km'], 0 ),
				$window,
				number_format_i18n( GAMING_HUB_TESLA_PLAN_SATURDAY_HOUR )
			);
		} elseif ( $is_friday ) {
			$note = sprintf(
				/* translators: 1: km, 2: window */
				__( '明日（金曜）は電池に負担をかけないよう約 80%% まで。金曜夜〜土曜朝の最安時間（%2$s）に 100%% へ上げます。予想走行 %1$s km。', 'gaming-hub' ),
				number_format_i18n( $drive['km'], 0 ),
				$window
			);
		} else {
			$note = sprintf(
				/* translators: 1: km, 2: window */
				__( '明日の %1$s km 走行を踏まえ、電池に負担をかけない約 80%% まで、スマートタイムONEの最安時間（%2$s）に 200V 普通充電します。', 'gaming-hub' ),
				number_format_i18n( $drive['km'], 0 ),
				$window
			);
		}
		$note         .= $cap_note;
		$km_label      = __( '予想走行', 'gaming-hub' );
		$deficit_label = sprintf(
			/* translators: %s: target SOC */
			__( '%s%%までの充電', 'gaming-hub' ),
			number_format_i18n( $target )
		);
	} else {
		if ( ! $needs_grid ) {
			$note = $is_sat_am
				? __( 'いまの残量で土曜朝 100% に届く見込みです。追加のグリッド充電は不要です。', 'gaming-hub' )
				: __( 'いまの残量と残りの走行では、追加のグリッド充電は不要です。残量は 20–80% を目安にしています。', 'gaming-hub' );
		} elseif ( $is_sat_am ) {
			$note = sprintf(
				/* translators: 1: kW, 2: Saturday hour, 3: window */
				__( '土曜朝 %2$s 時までに 100%% になるよう、残っている最安時間（%3$s）に 200V 普通充電（%1$s kW）します。この時間だけ自宅充電を自動で開始します。', 'gaming-hub' ),
				number_format_i18n( GAMING_HUB_TESLA_PLAN_CHARGE_W / 1000, 1 ),
				number_format_i18n( GAMING_HUB_TESLA_PLAN_SATURDAY_HOUR ),
				$window
			);
		} elseif ( $is_friday ) {
			$note = sprintf(
				/* translators: 1: kW, 2: remaining km, 3: window, 4: Saturday hour */
				__( '残りの走行 %2$s km。平日は約 80%% まで、金曜夜〜土曜 %4$s 時までの最安時間（%3$s）に 100%% へ上げます（200V 普通充電 %1$s kW）。この時間だけ自宅充電を自動で開始します。', 'gaming-hub' ),
				number_format_i18n( GAMING_HUB_TESLA_PLAN_CHARGE_W / 1000, 1 ),
				number_format_i18n( $drive['remaining_km'], 1 ),
				$window,
				number_format_i18n( GAMING_HUB_TESLA_PLAN_SATURDAY_HOUR )
			);
		} else {
			$note = sprintf(
				/* translators: 1: charge kW, 2: remaining km, 3: Saturday hour */
				__( '残りの走行 %2$s km を踏まえ、電池に負担をかけない約 80%% まで、スマートタイムONEの最安時間に 200V 普通充電（%1$s kW）します。土曜 %3$s 時までに 100%% になります。この時間だけ自宅充電を自動で開始します。', 'gaming-hub' ),
				number_format_i18n( GAMING_HUB_TESLA_PLAN_CHARGE_W / 1000, 1 ),
				number_format_i18n( $drive['remaining_km'], 1 ),
				number_format_i18n( GAMING_HUB_TESLA_PLAN_SATURDAY_HOUR )
			);
		}
		$note         .= $cap_note;
		$km_label      = __( '残り走行', 'gaming-hub' );
		$deficit_label = sprintf(
			/* translators: %s: target SOC */
			__( '%s%%までの充電', 'gaming-hub' ),
			number_format_i18n( $target )
		);
	}

	$target_note = $is_sat_am || $is_friday
		? sprintf(
			/* translators: %s: Saturday hour */
			__( '土曜朝 %s 時までに 100%%', 'gaming-hub' ),
			number_format_i18n( GAMING_HUB_TESLA_PLAN_SATURDAY_HOUR )
		)
		: sprintf(
			/* translators: 1: min SOC, 2: daily target */
			__( '電池ケア %1$s–%2$s%%', 'gaming-hub' ),
			number_format_i18n( GAMING_HUB_TESLA_PLAN_MIN_SOC ),
			number_format_i18n( $daily_soc )
		);

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
		'target_note'       => $target_note,
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
 * Overlay live Tesla charging onto a cached plan (NOW / current hour).
 *
 * @param array<string, mixed>      $plan   Plan payload.
 * @param array<string, mixed>|null $status Live status.
 * @return array<string, mixed>
 */
function gaming_hub_tesla_plan_apply_live( array $plan, $status = null ) {
	$flow     = is_array( $status['tesla_flow'] ?? null ) ? $status['tesla_flow'] : array();
	$model3   = is_array( $status['model3'] ?? null ) ? $status['model3'] : array();
	$asleep   = ( ! empty( $flow['asleep'] ) || ! empty( $model3['asleep'] ) || ! empty( $status['tesla_asleep'] ) )
		&& empty( $model3['is_charging'] )
		&& empty( $flow['is_charging'] );
	if ( ! $asleep && function_exists( 'gaming_hub_tesla_api_skip_reason' ) && 'asleep' === gaming_hub_tesla_api_skip_reason() ) {
		$asleep = empty( $model3['is_charging'] ) && empty( $flow['is_charging'] );
	}

	$charging = ( ! empty( $flow['live'] ) && ! empty( $flow['is_charging'] ) && ! $asleep )
		|| ( 'tesla' === (string) ( $status['model3_source'] ?? '' ) && ! empty( $model3['is_charging'] ) && ! $asleep );
	$wall_w   = (int) ( $flow['wall_w'] ?? 0 );
	$super_w  = (int) ( $flow['super_w'] ?? 0 );
	$watts    = $charging ? max( $wall_w, $super_w, (int) ( $model3['watts'] ?? 0 ) ) : 0;

	$plan['live_charging'] = $charging;
	$plan['live_charge_w'] = $watts;
	$plan['live_supply']   = (string) ( $flow['supply_kind'] ?? ( $model3['supply_kind'] ?? '' ) );
	$plan['asleep']        = $asleep;
	$plan['geofence_known'] = ! empty( $model3['geofence_known'] );
	$plan['at_home']       = array_key_exists( 'at_home', $model3 ) ? $model3['at_home'] : null;
	$plan['geofence_distance_m'] = isset( $model3['geofence_distance_m'] ) && is_numeric( $model3['geofence_distance_m'] )
		? (int) $model3['geofence_distance_m']
		: null;
	$plan['location_debug']      = (string) ( $model3['location_debug'] ?? '' );
	$input                       = gaming_hub_tesla_plan_input_state( $status );
	$plan['input_type']          = (string) $input['type'];
	$plan['input_label']         = (string) $input['label'];
	$plan['input_watts']         = (int) $input['watts'];
	$plan['input_plugged']       = ! empty( $input['plugged'] );
	$plan['input_charging']      = ! empty( $input['charging'] );
	$plan['input_sub_label']     = gaming_hub_tesla_plan_input_sub_label( $input );

	if ( $asleep ) {
		$live_soc = isset( $model3['battery_percent'] ) && is_numeric( $model3['battery_percent'] )
			? (float) $model3['battery_percent']
			: ( isset( $plan['soc_now'] ) && is_numeric( $plan['soc_now'] ) ? (float) $plan['soc_now'] : null );
		if ( null !== $live_soc && $live_soc > 0 ) {
			gaming_hub_tesla_sleep_soc_freeze( $live_soc );
		}
		$held   = gaming_hub_tesla_plan_held_soc( $live_soc );
		$frozen = gaming_hub_tesla_sleep_soc_state();
		$now_hour = (int) wp_date( 'G' );
		$from_hour = $frozen ? (int) $frozen['hour'] : $now_hour;

		if ( null !== $held ) {
			$plan['soc_now']   = $held;
			$plan['start_soc'] = $held;
			// Pin every sleep-held hour on the chart (not only NOW).
			if ( is_array( $plan['soc_series'] ?? null ) && ( $plan['plan_day'] ?? '' ) === 'today' ) {
				for ( $h = $from_hour; $h <= $now_hour; $h++ ) {
					$plan['soc_series'][ $h ] = $held;
				}
			}
			// Keep the hourly SOC log flat while asleep so rebuilds stay correct.
			if ( function_exists( 'gaming_hub_tesla_soc_log_record' ) ) {
				gaming_hub_tesla_soc_log_record( $held );
			}
		}
		$plan['sleep_held_soc']  = $held;
		$plan['sleep_from_hour'] = $from_hour;
		$plan['asleep_note']     = __( 'スリープ中です。残量は入眠時の値を固定表示し、API では更新しません。起きたら自動で再開します。', 'gaming-hub' );
	} else {
		$plan['asleep_note']     = '';
		$plan['sleep_held_soc']  = null;
		$plan['sleep_from_hour'] = null;
		if ( function_exists( 'gaming_hub_tesla_sleep_soc_clear' ) && ! empty( $flow['live'] ) ) {
			gaming_hub_tesla_sleep_soc_clear();
		}
	}

	$auto = gaming_hub_tesla_plan_auto_state();
	$plan['auto_note']         = gaming_hub_tesla_plan_auto_note( $plan, $auto );
	$plan['auto_error']        = (string) ( $auto['error'] ?? '' );
	$plan['auto_action']       = (string) ( $auto['action'] ?? '' );
	$plan['auto_applied_at']   = (string) ( $auto['applied_at'] ?? '' );
	$plan['virtual_key_url']   = function_exists( 'gaming_hub_tesla_virtual_key_url' )
		? gaming_hub_tesla_virtual_key_url()
		: '';
	$plan['needs_charge_auth'] = function_exists( 'gaming_hub_tesla_has_charging_scope' )
		&& ! gaming_hub_tesla_has_charging_scope();

	return $plan;
}

/**
 * Last auto-apply snapshot.
 *
 * @return array<string, mixed>
 */
function gaming_hub_tesla_plan_auto_state() {
	$saved = get_option( GAMING_HUB_TESLA_PLAN_AUTO_OPTION, array() );

	return is_array( $saved ) ? $saved : array();
}

/**
 * Status line for AI PLAN auto charge control.
 *
 * @param array<string, mixed> $plan Plan.
 * @param array<string, mixed> $auto Saved auto state.
 */
function gaming_hub_tesla_plan_auto_note( array $plan, array $auto ) {
	if ( ! empty( $plan['asleep'] ) ) {
		$slot         = gaming_hub_tesla_plan_current_slot( $plan );
		$charge_hour  = is_array( $slot ) && 'charge' === (string) ( $slot['mode'] ?? '' ) && empty( $slot['past'] );
		$home_context = true === ( $plan['at_home'] ?? null )
			|| ( 'home' === (string) ( $plan['live_supply'] ?? '' ) && null === ( $plan['at_home'] ?? null ) );

		if ( $charge_hour && $home_context ) {
			$error = (string) ( $auto['error'] ?? '' );
			if ( '' !== $error ) {
				return sprintf(
					/* translators: %s: error */
					__( '自動制御エラー: %s', 'gaming-hub' ),
					$error
				);
			}

			if ( 'start' === (string) ( $auto['action'] ?? '' ) ) {
				$when = '';
				$at   = (string) ( $auto['applied_at'] ?? '' );
				if ( '' !== $at ) {
					$ts = strtotime( $at );
					if ( $ts ) {
						$when = wp_date( get_option( 'time_format' ), $ts );
					}
				}

				return $when
					? sprintf(
						/* translators: %s: time */
						__( '自宅 AI PLAN 充電時間帯 — スリープから起こして充電開始しました（%s）。', 'gaming-hub' ),
						$when
					)
					: __( '自宅 AI PLAN 充電時間帯 — スリープから起こして充電開始を試みます。', 'gaming-hub' );
			}

			return __( '自宅 AI PLAN 充電時間帯 — スリープのためウェイクして充電開始を試みます。', 'gaming-hub' );
		}

		return __( 'スリープ中のため充電コマンドは送りません。残量表示は入眠時の値のままです。', 'gaming-hub' );
	}

	$plugged = ! empty( $plan['live_charging'] ) || 'home' === (string) ( $plan['live_supply'] ?? '' );
	if ( $plugged && empty( $plan['geofence_known'] ) ) {
		if ( function_exists( 'gaming_hub_tesla_has_location_scope' ) && ! gaming_hub_tesla_has_location_scope() ) {
			return __( '位置情報スコープがないため自宅判定できません。Tesla タグから再認証してください（vehicle_location が必要です）。', 'gaming-hub' );
		}

		return __( 'GPS を取得できず自宅扱いのままです。次のポーリングで外出先判定を再試行します。', 'gaming-hub' );
	}

	$error = (string) ( $auto['error'] ?? '' );
	if ( '' !== $error ) {
		return sprintf(
			/* translators: %s: error */
			__( '自動制御エラー: %s', 'gaming-hub' ),
			$error
		);
	}

	$action = (string) ( $auto['action'] ?? '' );
	$at     = (string) ( $auto['applied_at'] ?? '' );
	$when   = '';
	if ( '' !== $at ) {
		$ts = strtotime( $at );
		if ( $ts ) {
			$when = wp_date( get_option( 'time_format' ), $ts );
		}
	}

	if ( 'start' === $action ) {
		if ( 'supercharger' === (string) ( $auto['reason'] ?? '' ) ) {
			return $when
				? sprintf(
					/* translators: %s: time */
					__( 'Supercharger: 100%% まで充電開始。直近は %s に充電オンです。', 'gaming-hub' ),
					$when
				)
				: __( 'Supercharger: 100%% まで充電を開始します。', 'gaming-hub' );
		}

		if ( 'away' === (string) ( $auto['reason'] ?? '' ) ) {
			return $when
				? sprintf(
					/* translators: %s: time */
					__( '外出先充電: 100%% まで常時充電中。直近は %s に充電オンです。', 'gaming-hub' ),
					$when
				)
				: __( '外出先充電: 100%% まで常時充電中です。', 'gaming-hub' );
		}

		return $when
			? sprintf(
				/* translators: %s: time */
				__( 'AI PLAN に合わせて充電を自動制御中。直近は %s に充電オンです。', 'gaming-hub' ),
				$when
			)
			: __( 'AI PLAN に合わせて充電を自動制御中。いまは充電オンです。', 'gaming-hub' );
	}

	if ( 'stop' === $action ) {
		return $when
			? sprintf(
				/* translators: %s: time */
				__( 'AI PLAN に合わせて充電を自動制御中。直近は %s に充電オフです。', 'gaming-hub' ),
				$when
			)
			: __( 'AI PLAN に合わせて充電を自動制御中。計画時間外は充電しません。', 'gaming-hub' );
	}

	return __( 'AI PLAN に合わせて自宅充電のオン／オフとチャージキャップを自動で送ります。Tesla アプリの予約充電はオフにしてください。', 'gaming-hub' );
}

/**
 * Current-hour slot on the live plan date.
 *
 * @param array<string, mixed> $plan Plan.
 * @return array<string, mixed>|null
 */
function gaming_hub_tesla_plan_current_slot( array $plan ) {
	$date  = wp_date( 'Y-m-d' );
	$hour  = (int) wp_date( 'G' );
	$slots = is_array( $plan['slots'] ?? null ) ? $plan['slots'] : array();

	foreach ( $slots as $slot ) {
		if ( ! is_array( $slot ) ) {
			continue;
		}
		if ( (string) ( $slot['date'] ?? '' ) === $date && (int) ( $slot['hour'] ?? -1 ) === $hour ) {
			return $slot;
		}
	}

	return null;
}

/**
 * Resolve supply kind, treating fast-charger flags as Supercharger even before the label updates.
 *
 * @param array<string, mixed>|null $status Live status.
 */
function gaming_hub_tesla_status_supply_kind( $status = null ) {
	$status = is_array( $status ) ? $status : array();
	$model3 = is_array( $status['model3'] ?? null ) ? $status['model3'] : array();
	$flow   = is_array( $status['tesla_flow'] ?? null ) ? $status['tesla_flow'] : array();
	$kind   = (string) ( $flow['supply_kind'] ?? ( $model3['supply_kind'] ?? '' ) );

	if ( 'supercharger' === $kind || ! empty( $model3['fast_charger_present'] ) ) {
		return 'supercharger';
	}

	return $kind;
}

/**
 * Whether AI PLAN should treat this snapshot as Supercharger DC input.
 *
 * @param array<string, mixed>|null $status Live status.
 */
function gaming_hub_tesla_is_supercharger_context( $status = null ) {
	return 'supercharger' === gaming_hub_tesla_status_supply_kind( $status );
}

/**
 * Whether this hour's AI PLAN wants home charging.
 *
 * @param array<string, mixed>      $plan   Plan.
 * @param array<string, mixed>|null $status Live status.
 * @return array{want: bool, limit: int, hold: bool, apply_limit: bool, reason: string}
 */
function gaming_hub_tesla_plan_auto_desired( array $plan, $status = null ) {
	$status  = is_array( $status ) ? $status : array();
	$model3  = is_array( $status['model3'] ?? null ) ? $status['model3'] : array();
	$kind    = gaming_hub_tesla_status_supply_kind( $status );
	$at_home = array_key_exists( 'at_home', $model3 ) ? $model3['at_home'] : null;
	$plugged = in_array( $kind, array( 'home', 'supercharger' ), true ) || ! empty( $model3['plugged'] );
	$away_limit = GAMING_HUB_TESLA_PLAN_SATURDAY_SOC;
	$limit   = (int) ( $plan['target_soc'] ?? GAMING_HUB_TESLA_PLAN_TARGET_SOC );
	$limit   = max( GAMING_HUB_TESLA_PLAN_TARGET_SOC, min( 100, $limit ) );
	$soc     = isset( $model3['battery_percent'] ) && is_numeric( $model3['battery_percent'] )
		? (float) $model3['battery_percent']
		: null;

	if ( function_exists( 'gaming_hub_tesla_is_driving_now' ) && gaming_hub_tesla_is_driving_now() ) {
		return array(
			'want'         => false,
			'limit'        => $limit,
			'hold'         => true,
			'apply_limit'  => false,
			'reason'       => 'drive',
		);
	}

	if ( 'supercharger' === $kind ) {
		$want = $plugged;
		if ( null !== $soc && $soc >= $away_limit - 0.4 ) {
			$want = false;
		}

		return array(
			'want'         => $want,
			'limit'        => $away_limit,
			'hold'         => false,
			'apply_limit'  => true,
			'reason'       => 'supercharger',
		);
	}

	$geofence_known = ! empty( $model3['geofence_known'] );

	// Confirmed away AC only — at home (or GPS unknown) follows AI PLAN hour slots below.
	if ( $plugged && 'home' === $kind && false === $at_home && $geofence_known ) {
		$want = true;
		if ( null !== $soc && $soc >= $away_limit - 0.4 ) {
			$want = false;
		}

		return array(
			'want'         => $want,
			'limit'        => $away_limit,
			'hold'         => false,
			'apply_limit'  => true,
			'reason'       => 'away',
		);
	}

	$slot    = gaming_hub_tesla_plan_current_slot( $plan );
	$mode    = (string) ( $slot['mode'] ?? 'idle' );
	$want    = 'charge' === $mode && empty( $slot['past'] );

	if ( $want && null !== $soc && $soc >= $limit - 0.4 ) {
		$want = false;
	}

	return array(
		'want'         => $want,
		'limit'        => $limit,
		'hold'         => false,
		'apply_limit'  => true,
		'reason'       => $want ? 'charge' : 'idle',
	);
}

/**
 * Apply this hour's AI PLAN: home charge on/off and charge-limit SOC.
 *
 * @param array<string, mixed>|null $status Live status.
 * @return true|WP_Error
 */
function gaming_hub_tesla_plan_auto_apply( $status = null ) {
	if ( ! function_exists( 'gaming_hub_tesla_model3_is_configured' ) || ! gaming_hub_tesla_model3_is_configured() ) {
		return true;
	}

	$status = is_array( $status ) ? $status : array();
	if ( 'tesla' !== (string) ( $status['model3_source'] ?? '' ) ) {
		return true;
	}

	$plan = is_array( $status['tesla_plan'] ?? null )
		? $status['tesla_plan']
		: gaming_hub_tesla_get_charge_plan( $status );
	$plan = is_array( $plan ) ? $plan : array();

	$desired = gaming_hub_tesla_plan_auto_desired( $plan, $status );
	$model3   = is_array( $status['model3'] ?? null ) ? $status['model3'] : array();
	$flow     = is_array( $status['tesla_flow'] ?? null ) ? $status['tesla_flow'] : array();
	$kind     = gaming_hub_tesla_status_supply_kind( $status );
	$car_limit = isset( $model3['charge_limit_percent'] ) && is_numeric( $model3['charge_limit_percent'] )
		? (int) $model3['charge_limit_percent']
		: 0;
	$limit    = (int) $desired['limit'];
	$hold     = ! empty( $desired['hold'] );
	$apply_limit = ! empty( $desired['apply_limit'] );

	if ( $hold ) {
		if ( $apply_limit && $car_limit > 0 && $car_limit !== $limit && function_exists( 'gaming_hub_tesla_send_signed_command' ) && ! get_transient( GAMING_HUB_TESLA_PLAN_AUTO_LOCK ) ) {
			set_transient( GAMING_HUB_TESLA_PLAN_AUTO_LOCK, 1, 40 );
			gaming_hub_tesla_send_signed_command(
				'set_charge_limit',
				array( 'percent' => $limit ),
				false,
				true
			);
		}

		return true;
	}

	$asleep   = ! empty( $flow['asleep'] ) || ! empty( $model3['asleep'] );
	$charging = ( ! empty( $flow['is_charging'] ) || ! empty( $model3['is_charging'] ) ) && ! $asleep;
	$plugged  = in_array( $kind, array( 'home', 'supercharger' ), true ) || ! empty( $model3['plugged'] );
	$hour_key = wp_date( 'Y-m-d' ) . 'T' . sprintf( '%02d', (int) wp_date( 'G' ) );
	$want     = ! empty( $desired['want'] );
	$action   = $want ? 'start' : 'stop';

	// Never stop home AC logic while a DC stall is connected (misclassification window).
	if ( gaming_hub_tesla_is_supercharger_context( $status ) ) {
		$kind = 'supercharger';
	}

	$saved = gaming_hub_tesla_plan_auto_state();
	$same  = ( $saved['action'] ?? '' ) === $action
		&& (int) ( $saved['limit'] ?? 0 ) === $limit
		&& (string) ( $saved['hour_key'] ?? '' ) === $hour_key
		&& '' === (string) ( $saved['error'] ?? '' );

	if ( $same ) {
		if ( $want && $charging ) {
			return true;
		}
		if ( ! $want && ! $charging ) {
			return true;
		}
	}

	if ( $want && ! $plugged ) {
		$saved['error']     = __( '充電ケーブルがつながっていません。', 'gaming-hub' );
		$saved['hour_key']  = $hour_key;
		$saved['action']    = $action;
		$saved['limit']     = $limit;
		update_option( GAMING_HUB_TESLA_PLAN_AUTO_OPTION, $saved, false );

		return true;
	}

	if ( ! $want && ( $asleep || ! $charging || 'home' !== $kind ) ) {
		$saved['error']      = '';
		$saved['action']     = 'stop';
		$saved['limit']      = $limit;
		$saved['hour_key']   = $hour_key;
		$saved['applied_at'] = wp_date( 'c' );
		update_option( GAMING_HUB_TESLA_PLAN_AUTO_OPTION, $saved, false );

		return true;
	}

	if ( get_transient( GAMING_HUB_TESLA_PLAN_AUTO_LOCK ) ) {
		return true;
	}
	set_transient( GAMING_HUB_TESLA_PLAN_AUTO_LOCK, 1, 40 );

	if ( ! function_exists( 'gaming_hub_tesla_send_signed_command' ) ) {
		return true;
	}

	if ( $apply_limit && $car_limit > 0 && $car_limit !== $limit ) {
		$set = gaming_hub_tesla_send_signed_command(
			'set_charge_limit',
			array( 'percent' => $limit ),
			false,
			true
		);
		if ( is_wp_error( $set ) ) {
			$saved['error']    = $set->get_error_message();
			$saved['hour_key'] = $hour_key;
			$saved['action']   = $action;
			$saved['limit']    = $limit;
			update_option( GAMING_HUB_TESLA_PLAN_AUTO_OPTION, $saved, false );

			return $set;
		}
	}

	$need_start = $want && ! $charging;
	$need_stop  = ! $want && $charging && 'home' === $kind && ! gaming_hub_tesla_is_supercharger_context( $status );

	if ( $need_start || $need_stop ) {
		$sent = gaming_hub_tesla_send_signed_command(
			$need_start ? 'charge_start' : 'charge_stop',
			array(),
			true,
			true
		);
		if ( is_wp_error( $sent ) ) {
			$saved['error']    = $sent->get_error_message();
			$saved['hour_key'] = $hour_key;
			$saved['action']   = $action;
			$saved['limit']    = $limit;
			update_option( GAMING_HUB_TESLA_PLAN_AUTO_OPTION, $saved, false );

			return $sent;
		}
	}

	$saved['error']      = '';
	$saved['action']     = $action;
	$saved['limit']      = $limit;
	$saved['reason']     = (string) ( $desired['reason'] ?? '' );
	$saved['hour_key']   = $hour_key;
	$saved['applied_at'] = wp_date( 'c' );
	update_option( GAMING_HUB_TESLA_PLAN_AUTO_OPTION, $saved, false );

	return true;
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
	$asleep  = ( ! empty( $flow['asleep'] ) || ! empty( $model3['asleep'] ) || ! empty( $status['tesla_asleep'] ) )
		&& empty( $model3['is_charging'] )
		&& empty( $flow['is_charging'] );
	if ( ! $asleep && function_exists( 'gaming_hub_tesla_api_skip_reason' ) && 'asleep' === gaming_hub_tesla_api_skip_reason() ) {
		$asleep = empty( $model3['is_charging'] ) && empty( $flow['is_charging'] );
	}

	$raw_soc = isset( $model3['battery_percent'] ) && is_numeric( $model3['battery_percent'] )
		? max( 0, min( 100, (float) $model3['battery_percent'] ) )
		: null;

	if ( $asleep ) {
		if ( null !== $raw_soc && $raw_soc > 0 ) {
			gaming_hub_tesla_sleep_soc_freeze( $raw_soc );
		}
		$held = gaming_hub_tesla_plan_held_soc( $raw_soc );
		$soc  = null !== $held ? $held : ( $live ? 50.0 : 55.0 );
	} else {
		if ( $live ) {
			gaming_hub_tesla_sleep_soc_clear();
		}
		$soc = null !== $raw_soc && $raw_soc > 0
			? $raw_soc
			: ( $live ? 50.0 : 55.0 );
	}
	$car_limit = isset( $model3['charge_limit_percent'] ) && is_numeric( $model3['charge_limit_percent'] )
		? max( 50, min( 100, (int) $model3['charge_limit_percent'] ) )
		: 0;
	$today_km = isset( $model3['today_km'] ) && is_numeric( $model3['today_km'] )
		? max( 0, (float) $model3['today_km'] )
		: 0.0;
	if ( ! $live && $today_km <= 0 && function_exists( 'gaming_hub_tesla_gas_log_month_days' ) ) {
		$logged = gaming_hub_tesla_plan_logged_drive( wp_date( 'Y-m-d' ) );
		$today_km = $logged['km'];
	}

	$now_hour  = (int) wp_date( 'G' );
	$dates     = gaming_hub_tesla_plan_dates();
	$target    = gaming_hub_tesla_plan_target_soc_for_date( $dates['today'], $now_hour );
	$cache_key = GAMING_HUB_TESLA_PLAN_CACHE_PREFIX . $dates['today'] . '_' . $now_hour . '_' . (int) floor( $soc / 5 ) . '_' . (int) round( $today_km ) . '_' . $target . '_' . $car_limit . '_' . ( $live ? '1' : '0' ) . '_' . ( $asleep ? 's' : 'a' );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) && ! empty( $cached['plan_id'] ) ) {
		return gaming_hub_tesla_plan_apply_live( $cached, $status );
	}

	$ctx = array(
		'dates'      => $dates,
		'now_hour'   => $now_hour,
		'target_soc' => $target,
		'car_limit'  => $car_limit,
		'today_km'   => $today_km,
		'live'       => $live,
		'asleep'     => $asleep,
		'price_meta' => gaming_hub_tesla_plan_price_meta(),
	);

	$today     = gaming_hub_tesla_plan_build_day( 'today', $ctx, $soc );
	$end_soc   = isset( $today['soc_end'] ) && is_numeric( $today['soc_end'] ) ? (float) $today['soc_end'] : $soc;
	$yesterday = gaming_hub_tesla_plan_build_day( 'yesterday', $ctx, (float) GAMING_HUB_TESLA_PLAN_TARGET_SOC );
	$tomorrow  = gaming_hub_tesla_plan_build_day( 'tomorrow', $ctx, $end_soc );

	$today['view_days'] = array(
		'yesterday' => $yesterday,
		'tomorrow'  => $tomorrow,
	);
	$today['updated_at'] = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );

	set_transient( $cache_key, $today, GAMING_HUB_TESLA_PLAN_CACHE_TTL );

	return gaming_hub_tesla_plan_apply_live( $today, $status );
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
	$plan = gaming_hub_tesla_plan_apply_live( is_array( $plan ) ? $plan : array(), $status );

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
			'data'    => gaming_hub_tesla_plan_apply_live(
				isset( $status['tesla_plan'] ) && is_array( $status['tesla_plan'] )
					? $status['tesla_plan']
					: gaming_hub_tesla_get_charge_plan( $status ),
				$status
			),
		),
		200
	);
}

/**
 * Enqueue Tesla plan script.
 */
function gaming_hub_tesla_plan_scripts() {
	if ( ! is_tag( 'tesla' ) && ! is_page( 'powerwall' ) && ! ( function_exists( 'gaming_hub_is_hub_spa_page' ) && gaming_hub_is_hub_spa_page() ) ) {
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
