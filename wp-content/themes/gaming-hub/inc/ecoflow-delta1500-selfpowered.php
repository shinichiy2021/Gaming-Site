<?php
/**
 * DELTA 3 1500 — winter self-powered style AC controller (MQTT via bridge).
 *
 * Charge day (day spread ≥ 15 ¥/kWh): grid-charge only in the cheapest contiguous
 * 3 hours (6 half-hour slots, peak 15:00–21:59 excluded) while SOC < 100%.
 * Backup reserve (Energy Management): 100% while charging, 5% otherwise
 * (peak discharge, standby, spread too small, or SOC already 100%).
 *
 * Control path: bridge-command.json → ecoflow-bridge → watthConfig (bpPowerSoc
 * 100 / 5) / bypassBan / acChgCfg. Never sends maxChgSoc / minDsgSoc.
 *
 * CLI:
 *   docker exec gaming-site-wp php /var/www/html/wp-content/themes/gaming-hub/scripts/ecoflow-delta1500-selfpowered-reserve.php
 *   … --dry-run
 *
 * Cron: every 5 minutes (hook gaming_hub_ecoflow_delta1500_selfpowered_cron).
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** AC top-up watts when the 14:00 charge window is active (fixed grid charge). */
define( 'GAMING_HUB_ECOFLOW_DELTA1500_SELFPOWERED_CHARGE_W', 1000 );
/** Start AC top-up when SOC is below this (no PV headroom → aim for full). */
define( 'GAMING_HUB_ECOFLOW_DELTA1500_SELFPOWERED_CHARGE_BELOW_SOC', 100.0 );
/** Emergency floor: always AC-charge at/under this SOC (no solar recovery). */
define( 'GAMING_HUB_ECOFLOW_DELTA1500_SELFPOWERED_RESCUE_SOC', 5.0 );

/**
 * Cache path for last Delta AC setpoint (skip duplicate sends).
 */
function gaming_hub_ecoflow_delta1500_selfpowered_cache_path() {
	if ( function_exists( 'gaming_hub_ecoflow_bridge_cache_dir' ) ) {
		return trailingslashit( gaming_hub_ecoflow_bridge_cache_dir() ) . 'delta1500-selfpowered.json';
	}

	$dir = WP_CONTENT_DIR . '/ecoflow-cache';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	return trailingslashit( $dir ) . 'delta1500-selfpowered.json';
}

/**
 * Whether this controller owns Delta 1500 AC charge (AI schedule / plan must not overwrite).
 */
function gaming_hub_ecoflow_delta1500_selfpowered_control_enabled() {
	$env = getenv( 'ECOFLOW_DELTA1500_SELFPOWERED_CONTROL' );
	if ( false !== $env && '' !== $env ) {
		return ! in_array( strtolower( (string) $env ), array( '0', 'false', 'off', 'no' ), true );
	}

	return (bool) get_option( 'gaming_hub_ecoflow_delta1500_selfpowered_control', true );
}

/**
 * Live Delta secondary slice from status / MQTT origin (quota often denies SN_2).
 *
 * @return array<string, mixed>|null
 */
function gaming_hub_ecoflow_delta1500_selfpowered_secondary_status() {
	if ( function_exists( 'gaming_hub_get_ecoflow_status' ) ) {
		$status = gaming_hub_get_ecoflow_status( false );
		if ( ! is_wp_error( $status ) && isset( $status['secondary'] ) && is_array( $status['secondary'] ) ) {
			return $status['secondary'];
		}
	}

	return null;
}

/**
 * Live Delta 1500 SOC.
 *
 * Prefer MQTT/status secondary — Open API quota frequently returns
 * "device is not allowed" for ECOFLOW_DEVICE_SN_2.
 *
 * @return float|WP_Error
 */
function gaming_hub_ecoflow_delta1500_selfpowered_fetch_soc() {
	$secondary = gaming_hub_ecoflow_delta1500_selfpowered_secondary_status();
	if ( is_array( $secondary ) ) {
		if ( isset( $secondary['battery_percent'] ) && is_numeric( $secondary['battery_percent'] ) ) {
			return (float) $secondary['battery_percent'];
		}
		if ( isset( $secondary['_model_soc'] ) && is_numeric( $secondary['_model_soc'] ) ) {
			return (float) $secondary['_model_soc'];
		}
	}

	$config = gaming_hub_get_ecoflow_config();
	$sn     = (string) ( $config['device_sn_2'] ?? '' );
	if ( '' === $sn ) {
		return new WP_Error( 'ecoflow_no_sn_2', 'ECOFLOW_DEVICE_SN_2 missing.' );
	}

	$api  = new Gaming_Hub_Ecoflow_Api( $config['access_key'], $config['secret_key'], $config['region'] );
	$data = $api->get_device_quota( $sn );
	if ( is_wp_error( $data ) ) {
		return $data;
	}

	$keys = function_exists( 'gaming_hub_ecoflow_delta1500_main_soc_keys' )
		? gaming_hub_ecoflow_delta1500_main_soc_keys()
		: array( 'bms_bmsStatus.f32ShowSoc', 'bms_bmsStatus.soc', 'pd.soc', 'bms.soc' );

	foreach ( $keys as $key ) {
		if ( isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ) {
			return (float) $data[ $key ];
		}
	}
	foreach ( array( 'cmsBattSoc', 'bmsBattSoc', 'soc', 'bmsSoc', 'lcdShowSoc' ) as $key ) {
		if ( isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ) {
			return (float) $data[ $key ];
		}
	}

	return new WP_Error( 'ecoflow_no_soc', 'Delta 1500 SOC not found in status or quota.' );
}

/**
 * Optional live UPS / AC-out load for sizing charge watts.
 *
 * @return float Watts (≥ 0).
 */
function gaming_hub_ecoflow_delta1500_selfpowered_load_w() {
	$secondary = gaming_hub_ecoflow_delta1500_selfpowered_secondary_status();
	if ( is_array( $secondary ) ) {
		if ( isset( $secondary['ac_out'] ) && is_numeric( $secondary['ac_out'] ) ) {
			return max( 0.0, (float) $secondary['ac_out'] );
		}
		if ( isset( $secondary['output_total'] ) && is_numeric( $secondary['output_total'] ) ) {
			return max( 0.0, (float) $secondary['output_total'] );
		}
	}

	$path = function_exists( 'gaming_hub_ecoflow_bridge_cache_dir' )
		? trailingslashit( gaming_hub_ecoflow_bridge_cache_dir() ) . 'bridge-status.json'
		: '';
	if ( $path && file_exists( $path ) ) {
		$raw = json_decode( (string) file_get_contents( $path ), true );
		if ( is_array( $raw ) ) {
			$keys = isset( $raw['keys'] ) && is_array( $raw['keys'] ) ? $raw['keys'] : $raw;
			foreach ( array( 'pd.wattsOutSum', 'pd.watts_out_sum', 'inv.outputWatts', 'ac_out' ) as $key ) {
				if ( isset( $keys[ $key ] ) && is_numeric( $keys[ $key ] ) ) {
					return max( 0.0, (float) $keys[ $key ] );
				}
			}
		}
	}

	return 0.0;
}

/**
 * Charge watts for an active top-up window (fixed 1000 W with Pro).
 *
 * @param float $load_w Unused (kept for call-site compatibility).
 */
function gaming_hub_ecoflow_delta1500_selfpowered_charge_watts( $load_w = 0.0 ) {
	unset( $load_w );
	$watts = defined( 'GAMING_HUB_ECOFLOW_DELTA1500_SELFPOWERED_CHARGE_W' )
		? (int) GAMING_HUB_ECOFLOW_DELTA1500_SELFPOWERED_CHARGE_W
		: ( defined( 'GAMING_HUB_ECOFLOW_PLAN_CHARGE_W' ) ? (int) GAMING_HUB_ECOFLOW_PLAN_CHARGE_W : 1000 );
	if ( function_exists( 'gaming_hub_ecoflow_clamp_delta1500_charge_watts' ) ) {
		return gaming_hub_ecoflow_clamp_delta1500_charge_watts( $watts );
	}

	return max( 0, min( 1500, $watts ) );
}

/**
 * Usable Delta 1500 pack Wh for full-charge planning.
 *
 * @param array<string, mixed> $status Optional EcoFlow status.
 */
function gaming_hub_ecoflow_delta1500_selfpowered_full_wh( array $status = array() ) {
	if ( $status && function_exists( 'gaming_hub_ecoflow_plan_delta_pack' ) ) {
		$pack = gaming_hub_ecoflow_plan_delta_pack( $status );
		if ( isset( $pack['full_wh'] ) && is_numeric( $pack['full_wh'] ) && (float) $pack['full_wh'] > 0 ) {
			return (float) $pack['full_wh'];
		}
	}

	$cap = defined( 'GAMING_HUB_ECOFLOW_DELTA1500_CAPACITY_WH' )
		? (float) GAMING_HUB_ECOFLOW_DELTA1500_CAPACITY_WH
		: 2500.0;
	$extra = defined( 'GAMING_HUB_ECOFLOW_DELTA1500_EXTRA_WH' )
		? (float) GAMING_HUB_ECOFLOW_DELTA1500_EXTRA_WH
		: 1000.0;

	return max( 500.0, $cap - $extra );
}

/**
 * Whether this hour is in the peak discharge window (no free grid charge).
 *
 * @param int $hour 0–23.
 */
function gaming_hub_ecoflow_delta1500_selfpowered_is_peak_hour( $hour ) {
	$h = (int) $hour;
	return $h >= 15 && $h <= 21;
}

/**
 * Cheapest contiguous 3-hour (6 half-hour slots) window for a date, peak 15:00–21:59 excluded.
 *
 * @param string $plan_date Y-m-d.
 * @return array<string, mixed>|null Window + spread info, or null when prices are unavailable.
 */
function gaming_hub_ecoflow_delta1500_cheapest_window( $plan_date ) {
	if ( ! function_exists( 'gaming_hub_ecoflow_selfpowered_price_slots_for_date' )
		|| ! function_exists( 'gaming_hub_ecoflow_selfpowered_cheapest_window' ) ) {
		return null;
	}
	$slots = gaming_hub_ecoflow_selfpowered_price_slots_for_date( $plan_date );
	if ( ! $slots ) {
		return null;
	}
	$window = gaming_hub_ecoflow_selfpowered_cheapest_window( $slots );
	if ( ! $window ) {
		return null;
	}
	$stats = gaming_hub_ecoflow_selfpowered_slot_spread( $slots );

	return array_merge( $window, $stats );
}

/**
 * Plan the Delta 1500 charge window: cheapest 3 contiguous hours on charge days.
 *
 * Charge only when spread ≥ 15 ¥/kWh and SOC < 100%. $from_hour hides a window that has
 * already finished today. Pack deficit is informational (3 h at 1000 W covers a full pack).
 *
 * @param string               $plan_date Y-m-d.
 * @param int                  $from_hour Inclusive hour (0 for a full day).
 * @param float|null           $soc       Live / start SOC %.
 * @param float|null           $full_wh   Pack Wh (null → default usable).
 * @param array<string, mixed> $status    Optional status for capacity.
 * @return array{
 *   deficit_kwh: float,
 *   hours_needed: int,
 *   charge_hours: array<int, int>,
 *   slots_per_hour: array<int, int>,
 *   windows: array<int, string>,
 *   avg_yen: float|null,
 *   day_min: float|null,
 *   picked: array<int, array{hour: int, index: int, yen: float}>,
 *   window?: array<string, mixed>
 * }
 */
function gaming_hub_ecoflow_delta1500_pick_cheap_full_hours( $plan_date, $from_hour, $soc = null, $full_wh = null, array $status = array() ) {
	$empty = array(
		'deficit_kwh'    => 0.0,
		'hours_needed'   => 0,
		'charge_hours'   => array(),
		'slots_per_hour' => array(),
		'windows'        => array(),
		'avg_yen'        => null,
		'day_min'        => null,
		'picked'         => array(),
	);

	$plan_date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $plan_date ) ? (string) $plan_date : wp_date( 'Y-m-d' );
	$from_hour = max( 0, min( 23, (int) $from_hour ) );
	$full_wh   = null !== $full_wh && is_numeric( $full_wh ) && (float) $full_wh > 0
		? (float) $full_wh
		: gaming_hub_ecoflow_delta1500_selfpowered_full_wh( $status );
	$target    = defined( 'GAMING_HUB_ECOFLOW_PLAN_DELTA_TARGET_SOC' )
		? (float) GAMING_HUB_ECOFLOW_PLAN_DELTA_TARGET_SOC
		: 100.0;
	$below     = defined( 'GAMING_HUB_ECOFLOW_DELTA1500_SELFPOWERED_CHARGE_BELOW_SOC' )
		? (float) GAMING_HUB_ECOFLOW_DELTA1500_SELFPOWERED_CHARGE_BELOW_SOC
		: 100.0;
	$soc_val   = null !== $soc && is_numeric( $soc ) ? (float) $soc : null;

	$win = gaming_hub_ecoflow_delta1500_cheapest_window( $plan_date );
	if ( ! $win ) {
		return $empty;
	}
	$empty['day_min'] = (float) $win['day_min'];

	// Spread below 15 ¥ → no Delta grid charge today.
	if ( empty( $win['charge_day'] ) ) {
		return $empty;
	}
	if ( null !== $soc_val && $soc_val >= $below ) {
		return $empty;
	}

	// Window already finished (today only; from_hour 0 means "full day").
	$dates = function_exists( 'gaming_hub_ecoflow_plan_dates' ) ? gaming_hub_ecoflow_plan_dates() : array( 'today' => wp_date( 'Y-m-d' ) );
	if ( $plan_date === ( $dates['today'] ?? '' ) && $from_hour > 0 && ( (int) $win['end'] + 1 ) <= ( $from_hour * 2 ) ) {
		return $empty;
	}

	$deficit = 0.0;
	if ( null !== $soc_val && function_exists( 'gaming_hub_ecoflow_plan_charge_to_target_kwh' ) ) {
		$deficit = (float) gaming_hub_ecoflow_plan_charge_to_target_kwh( $soc_val, $full_wh, $target );
	} elseif ( null === $soc_val ) {
		$deficit = max( 0.5, $full_wh / 1000.0 );
	}
	if ( null !== $soc_val && $deficit < 0.05 ) {
		return $empty;
	}

	$slots  = gaming_hub_ecoflow_selfpowered_price_slots_for_date( $plan_date );
	$clean  = array();
	foreach ( $win['hours'] as $h ) {
		$yens = array();
		for ( $i = $win['start']; $i <= $win['end']; $i++ ) {
			if ( (int) floor( $i / 2 ) === (int) $h ) {
				$yens[] = (float) $slots[ $i ];
			}
		}
		$clean[] = array(
			'hour'  => (int) $h,
			'index' => (int) $h - $from_hour,
			'yen'   => $yens ? round( array_sum( $yens ) / count( $yens ), 2 ) : 0.0,
		);
	}

	return array(
		'deficit_kwh'    => round( $deficit, 2 ),
		'hours_needed'   => 3,
		'charge_hours'   => array_values( array_map( 'intval', $win['hours'] ) ),
		'slots_per_hour' => $win['slots_per_hour'],
		'windows'        => array( (string) $win['label'] ),
		'avg_yen'        => (float) $win['avg'],
		'day_min'        => (float) $win['day_min'],
		'picked'         => $clean,
		'window'         => $win,
	);
}

/**
 * Decide the Delta 1500 setpoint for the current local time + SOC.
 *
 * Peak 15:00–21:59 → backup reserve 5% (discharge). Inside the cheapest 3 h window,
 * on a charge day with SOC < 100% → reserve 100% + AC charge (grid). Otherwise 5%.
 *
 * @param int                       $hour       0–23 Asia/Tokyo.
 * @param float                     $soc        Live Delta SOC %.
 * @param bool                      $charge_day Spread ≥ 15 ¥/kWh.
 * @param int                       $minute     0–59.
 * @param array<string, mixed>|null $window     Cheapest-window info (start/end slot idx).
 * @return array{watts: int, reserve: int, phase: string, reason: string, charge_hours?: array<int, int>}
 */
function gaming_hub_ecoflow_delta1500_selfpowered_decide( $hour, $soc, $charge_day, $minute = 0, $window = null ) {
	$idle_w   = 0;
	$idle_r   = 5;
	$charge_w = gaming_hub_ecoflow_delta1500_selfpowered_charge_watts( 0 );
	$below    = defined( 'GAMING_HUB_ECOFLOW_DELTA1500_SELFPOWERED_CHARGE_BELOW_SOC' )
		? (float) GAMING_HUB_ECOFLOW_DELTA1500_SELFPOWERED_CHARGE_BELOW_SOC
		: 100.0;
	$hour     = (int) $hour;
	$idx      = ( $hour * 2 ) + ( (int) $minute >= 30 ? 1 : 0 );
	$hours    = is_array( $window['hours'] ?? null ) ? array_values( array_map( 'intval', $window['hours'] ) ) : array();

	// Peak: reserve 5% so the pack discharges to the floor.
	if ( gaming_hub_ecoflow_delta1500_selfpowered_is_peak_hour( $hour ) ) {
		return array(
			'watts'        => $idle_w,
			'reserve'      => $idle_r,
			'phase'        => 'peak_discharge',
			'charge_hours' => $hours,
			'reason'       => 'Peak window → backup reserve 5% · AC idle (discharge).',
		);
	}

	$in_window = is_array( $window ) && isset( $window['start'], $window['end'] )
		&& $idx >= (int) $window['start'] && $idx <= (int) $window['end'];

	if ( $in_window ) {
		if ( $charge_day && (float) $soc < $below ) {
			return array(
				'watts'        => $charge_w,
				'reserve'      => 100,
				'phase'        => 'ac_topup',
				'charge_hours' => $hours,
				'reason'       => sprintf(
					'Cheapest 3 h window (%s), charge day and SOC %.1f%% < %.0f%% → backup reserve 100%% · AC charge %d W.',
					(string) ( $window['label'] ?? '' ),
					$soc,
					$below,
					$charge_w
				),
			);
		}

		return array(
			'watts'        => $idle_w,
			'reserve'      => $idle_r,
			'phase'        => 'standby_full',
			'charge_hours' => $hours,
			'reason'       => $charge_day
				? sprintf( 'Cheapest window but SOC %.1f%% ≥ %.0f%% → backup reserve 5%% · AC idle.', $soc, $below )
				: 'Cheapest window but spread below threshold → backup reserve 5% · AC idle.',
		);
	}

	return array(
		'watts'        => $idle_w,
		'reserve'      => $idle_r,
		'phase'        => $hours && $charge_day ? 'await_cheap' : 'standby',
		'charge_hours' => $hours,
		'reason'       => $hours && $charge_day
			? sprintf( 'Waiting for cheapest 3 h window (%s); SOC %.1f%% → backup reserve 5%%.', (string) ( $window['label'] ?? '' ), $soc )
			: sprintf( 'Standby → backup reserve 5%% · AC idle (grid charge off). SOC %.1f%%.', $soc ),
	);
}

/**
 * Queue Delta AC charge via MQTT bridge (0 W = stop / bypassBan).
 *
 * @param int $watts Charge watts.
 * @return true|WP_Error
 */
function gaming_hub_ecoflow_delta1500_selfpowered_apply( $watts ) {
	$watts = function_exists( 'gaming_hub_ecoflow_clamp_delta1500_charge_watts' )
		? gaming_hub_ecoflow_clamp_delta1500_charge_watts( $watts )
		: max( 0, min( 1500, (int) $watts ) );

	if ( ! function_exists( 'gaming_hub_ecoflow_queue_delta1500_ac_charge' ) ) {
		return new WP_Error( 'ecoflow_no_queue', 'Delta AC charge queue missing.' );
	}

	return gaming_hub_ecoflow_queue_delta1500_ac_charge( $watts );
}

/**
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_delta1500_selfpowered_load_cache() {
	$path = gaming_hub_ecoflow_delta1500_selfpowered_cache_path();
	if ( ! file_exists( $path ) ) {
		return array();
	}
	$raw = json_decode( (string) file_get_contents( $path ), true );
	return is_array( $raw ) ? $raw : array();
}

/**
 * @param array<string, mixed> $cache Cache payload.
 */
function gaming_hub_ecoflow_delta1500_selfpowered_save_cache( array $cache ) {
	$path = gaming_hub_ecoflow_delta1500_selfpowered_cache_path();
	$dir  = dirname( $path );
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	file_put_contents( $path, wp_json_encode( $cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
}

/**
 * Today's price slots — reuse Pro helper when available.
 *
 * @return array{slots: array<int, float>, charge_day: bool, day_min: float, peak_max: float, spread: float}|WP_Error
 */
function gaming_hub_ecoflow_delta1500_selfpowered_today_price_slots() {
	if ( function_exists( 'gaming_hub_ecoflow_selfpowered_today_price_slots' ) ) {
		return gaming_hub_ecoflow_selfpowered_today_price_slots();
	}

	return new WP_Error( 'selfpowered_prices_missing', 'Price-slot helper unavailable (load Pro self-powered first).' );
}

/**
 * Run one Delta control cycle.
 *
 * @param bool $dry_run Skip MQTT queue.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_delta1500_selfpowered_run( $dry_run = false ) {
	$tz   = function_exists( 'gaming_hub_looop_timezone' ) ? gaming_hub_looop_timezone() : new DateTimeZone( 'Asia/Tokyo' );
	$now  = new DateTimeImmutable( 'now', $tz );
	$hour = (int) $now->format( 'G' );

	$prices = gaming_hub_ecoflow_delta1500_selfpowered_today_price_slots();
	if ( is_wp_error( $prices ) ) {
		return array(
			'ok'    => false,
			'error' => $prices->get_error_message(),
			'at'    => $now->format( 'c' ),
		);
	}

	$soc = gaming_hub_ecoflow_delta1500_selfpowered_fetch_soc();
	if ( is_wp_error( $soc ) ) {
		return array(
			'ok'     => false,
			'error'  => $soc->get_error_message(),
			'at'     => $now->format( 'c' ),
			'prices' => $prices,
		);
	}

	$load_w = gaming_hub_ecoflow_delta1500_selfpowered_load_w();
	$pick   = gaming_hub_ecoflow_delta1500_pick_cheap_full_hours(
		$now->format( 'Y-m-d' ),
		$hour,
		(float) $soc
	);
	// Whole-day cheapest 3 h window (is1500Cheapest3Hours) from today's 48 half-hour slots.
	$window   = function_exists( 'gaming_hub_ecoflow_selfpowered_cheapest_window' ) && ! empty( $prices['slots'] )
		? gaming_hub_ecoflow_selfpowered_cheapest_window( $prices['slots'] )
		: null;
	$decision = gaming_hub_ecoflow_delta1500_selfpowered_decide(
		$hour,
		(float) $soc,
		! empty( $prices['charge_day'] ),
		(int) $now->format( 'i' ),
		$window
	);
	$watts   = (int) $decision['watts'];
	$reserve = (int) ( $decision['reserve'] ?? ( $watts > 0 ? 100 : 5 ) );
	$cache   = gaming_hub_ecoflow_delta1500_selfpowered_load_cache();
	$last    = isset( $cache['watts'] ) ? (int) $cache['watts'] : null;
	$skipped = ( null !== $last && $last === $watts );
	$sent    = false;
	$error   = '';

	if ( ! $skipped && ! $dry_run ) {
		$put = gaming_hub_ecoflow_delta1500_selfpowered_apply( $watts );
		if ( is_wp_error( $put ) ) {
			$error = $put->get_error_message();
		} else {
			$sent  = true;
			$cache = array(
				'watts'         => $watts,
				'reserve'       => $reserve,
				'phase'         => $decision['phase'],
				'soc'           => round( (float) $soc, 2 ),
				'load_w'        => (int) round( $load_w ),
				'charge_day'    => ! empty( $prices['charge_day'] ),
				'spread'        => $prices['spread'],
				'charge_hours'  => $pick['charge_hours'] ?? array(),
				'windows'       => $pick['windows'] ?? array(),
				'deficit_kwh'   => $pick['deficit_kwh'] ?? 0,
				'avg_yen'       => $pick['avg_yen'] ?? null,
				'at'            => $now->format( 'c' ),
				'reason'        => $decision['reason'],
			);
			gaming_hub_ecoflow_delta1500_selfpowered_save_cache( $cache );
			update_option( 'gaming_hub_ecoflow_delta1500_selfpowered_control', 1, false );
		}
	} elseif ( $skipped && ! $dry_run ) {
		$cache['skipped_at']   = $now->format( 'c' );
		$cache['soc']          = round( (float) $soc, 2 );
		$cache['load_w']       = (int) round( $load_w );
		$cache['charge_hours'] = $pick['charge_hours'] ?? array();
		$cache['windows']      = $pick['windows'] ?? array();
		gaming_hub_ecoflow_delta1500_selfpowered_save_cache( $cache );
	}

	return array(
		'ok'            => '' === $error,
		'error'         => $error,
		'at'            => $now->format( 'c' ),
		'hour'          => $hour,
		'soc'           => round( (float) $soc, 2 ),
		'load_w'        => (int) round( $load_w ),
		'charge_day'    => ! empty( $prices['charge_day'] ),
		'day_min'       => $prices['day_min'],
		'peak_max'      => $prices['peak_max'],
		'spread'        => $prices['spread'],
		'phase'         => $decision['phase'],
		'reason'        => $decision['reason'],
		'watts'         => $watts,
		'reserve'       => $reserve,
		'charge_hours'  => $pick['charge_hours'] ?? array(),
		'windows'       => $pick['windows'] ?? array(),
		'deficit_kwh'   => $pick['deficit_kwh'] ?? 0,
		'avg_yen'       => $pick['avg_yen'] ?? null,
		'last'          => $last,
		'skipped'       => $skipped,
		'sent'          => $sent,
		'dry_run'       => (bool) $dry_run,
		'no_solar'   => true,
	);
}

/**
 * WP-Cron callback.
 */
function gaming_hub_ecoflow_delta1500_selfpowered_cron() {
	if ( ! gaming_hub_ecoflow_delta1500_selfpowered_control_enabled() ) {
		return;
	}
	$config = gaming_hub_get_ecoflow_config();
	if ( empty( $config['device_sn_2'] ) || empty( $config['access_key'] ) ) {
		return;
	}
	gaming_hub_ecoflow_delta1500_selfpowered_run( false );
}

/**
 * Register 5-minute interval if Pro self-powered has not already.
 *
 * @param array<string, array<string, mixed>> $schedules Schedules.
 * @return array<string, array<string, mixed>>
 */
function gaming_hub_ecoflow_delta1500_selfpowered_cron_schedules( $schedules ) {
	if ( ! isset( $schedules['gaming_hub_every_five_minutes'] ) ) {
		$schedules['gaming_hub_every_five_minutes'] = array(
			'interval' => 300,
			'display'  => 'Every five minutes (EcoFlow Delta 1500 self-powered)',
		);
	}
	return $schedules;
}

/**
 * Ensure a 5-minute cron exists.
 */
function gaming_hub_ecoflow_delta1500_selfpowered_schedule_cron() {
	$hook = 'gaming_hub_ecoflow_delta1500_selfpowered_cron';
	if ( ! wp_next_scheduled( $hook ) ) {
		wp_schedule_event( time() + 90, 'gaming_hub_every_five_minutes', $hook );
	}
}

add_filter( 'cron_schedules', 'gaming_hub_ecoflow_delta1500_selfpowered_cron_schedules' );
add_action( 'gaming_hub_ecoflow_delta1500_selfpowered_cron', 'gaming_hub_ecoflow_delta1500_selfpowered_cron' );
add_action( 'init', 'gaming_hub_ecoflow_delta1500_selfpowered_schedule_cron', 31 );
