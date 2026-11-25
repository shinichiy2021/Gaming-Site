<?php
/**
 * DELTA 3 1500 — winter self-powered style AC controller (MQTT via bridge).
 *
 * Plans cheapest non-peak LOOOP hours to fill toward 100% (no solar headroom).
 * Peak 15:00–21:59 stays pack-first. Outside cheap windows keep AC charge off
 * (bypassBan + Energy Management off via bridge ac_charge 0 W).
 *
 * Control path: bridge-command.json → ecoflow-bridge → watthConfig / bypassBan /
 * acChgCfg. Never sends maxChgSoc / minDsgSoc.
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
 * Pick cheapest non-peak hours to fill Delta to 100% at fixed AC watts.
 *
 * Prefers pre-peak hours so the UPS pack is full before the evening discharge
 * window. Does not require Pro’s day/peak spread gate — Delta has no PV.
 *
 * @param string               $plan_date Y-m-d.
 * @param int                  $from_hour Inclusive start hour (0 for full day).
 * @param float|null           $soc       Live / start SOC %.
 * @param float|null           $full_wh   Pack Wh (null → default usable).
 * @param array<string, mixed> $status    Optional status for capacity.
 * @return array{
 *   deficit_kwh: float,
 *   hours_needed: int,
 *   charge_hours: array<int, int>,
 *   windows: array<int, string>,
 *   avg_yen: float|null,
 *   day_min: float|null,
 *   picked: array<int, array{hour: int, index: int, yen: float}>
 * }
 */
function gaming_hub_ecoflow_delta1500_pick_cheap_full_hours( $plan_date, $from_hour, $soc = null, $full_wh = null, array $status = array() ) {
	$empty = array(
		'deficit_kwh'  => 0.0,
		'hours_needed' => 0,
		'charge_hours' => array(),
		'windows'      => array(),
		'avg_yen'      => null,
		'day_min'      => null,
		'picked'       => array(),
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

	if ( null !== $soc_val && $soc_val >= $below ) {
		return $empty;
	}

	$deficit = 0.0;
	if ( null !== $soc_val && function_exists( 'gaming_hub_ecoflow_plan_charge_to_target_kwh' ) ) {
		$deficit = (float) gaming_hub_ecoflow_plan_charge_to_target_kwh( $soc_val, $full_wh, $target );
	} elseif ( null === $soc_val ) {
		// Unknown SOC → reserve enough for a full pack top-up.
		$deficit = max( 0.5, $full_wh / 1000.0 );
	}
	if ( $deficit < 0.05 ) {
		return $empty;
	}

	$charge_w = gaming_hub_ecoflow_delta1500_selfpowered_charge_watts( 0 );
	$kwh_h    = max( 0.1, $charge_w / 1000.0 );
	$needed   = max( 1, (int) ceil( $deficit / $kwh_h ) );

	$map = function_exists( 'gaming_hub_ecoflow_day_price_map' )
		? gaming_hub_ecoflow_day_price_map( $plan_date )
		: array();
	if ( ! is_array( $map ) || ! $map ) {
		return array_merge(
			$empty,
			array(
				'deficit_kwh'  => round( $deficit, 2 ),
				'hours_needed' => $needed,
			)
		);
	}

	$candidates = array();
	for ( $h = $from_hour; $h < 24; $h++ ) {
		if ( gaming_hub_ecoflow_delta1500_selfpowered_is_peak_hour( $h ) ) {
			continue;
		}
		if ( ! isset( $map[ $h ] ) || ! is_numeric( $map[ $h ] ) ) {
			continue;
		}
		$candidates[] = array(
			'hour'     => $h,
			'index'    => $h - $from_hour,
			'yen'      => (float) $map[ $h ],
			'pre_peak' => $h < 15 ? 0 : 1,
		);
	}
	if ( ! $candidates ) {
		return array_merge(
			$empty,
			array(
				'deficit_kwh'  => round( $deficit, 2 ),
				'hours_needed' => $needed,
			)
		);
	}

	$min_yen = min( array_column( $candidates, 'yen' ) );
	$premium = defined( 'GAMING_HUB_ECOFLOW_PLAN_CHEAP_YEN_PREMIUM' )
		? (float) GAMING_HUB_ECOFLOW_PLAN_CHEAP_YEN_PREMIUM
		: 8.0;
	$ceiling = $min_yen + max( 0.0, $premium );
	$eligible = array();
	foreach ( $candidates as $row ) {
		if ( (float) $row['yen'] <= $ceiling + 0.05 ) {
			$eligible[] = $row;
		}
	}
	if ( ! $eligible ) {
		$eligible = $candidates;
	}

	usort(
		$eligible,
		static function ( $a, $b ) {
			if ( $a['pre_peak'] !== $b['pre_peak'] ) {
				return $a['pre_peak'] <=> $b['pre_peak'];
			}
			if ( $a['yen'] !== $b['yen'] ) {
				return $a['yen'] <=> $b['yen'];
			}
			return $a['index'] <=> $b['index'];
		}
	);

	$picked = array_slice( $eligible, 0, min( $needed, count( $eligible ) ) );
	usort(
		$picked,
		static function ( $a, $b ) {
			return $a['hour'] <=> $b['hour'];
		}
	);

	$clean = array();
	$hours = array();
	foreach ( $picked as $row ) {
		$h       = (int) $row['hour'];
		$hours[] = $h;
		$clean[] = array(
			'hour'  => $h,
			'index' => (int) $row['index'],
			'yen'   => (float) $row['yen'],
		);
	}

	$windows = function_exists( 'gaming_hub_ecoflow_group_hour_windows' )
		? gaming_hub_ecoflow_group_hour_windows( $clean )
		: array();
	$avg     = $clean
		? round( array_sum( array_column( $clean, 'yen' ) ) / count( $clean ), 1 )
		: null;

	return array(
		'deficit_kwh'  => round( $deficit, 2 ),
		'hours_needed' => $needed,
		'charge_hours' => $hours,
		'windows'      => $windows,
		'avg_yen'      => $avg,
		'day_min'      => round( $min_yen, 2 ),
		'picked'       => $clean,
	);
}

/**
 * Decide AC setpoint for the current local hour + SOC.
 *
 * No solar: fill toward 100% in the cheapest non-peak hours; peak stays pack-first;
 * a deep-discharge floor always requests AC charge.
 *
 * @param int        $hour         0–23 Asia/Tokyo.
 * @param float      $soc          Live Delta SOC %.
 * @param bool       $charge_day   Unused (kept for call-site compatibility).
 * @param float      $load_w       UPS / AC-out watts.
 * @param array<int> $charge_hours Planned cheap charge hours (empty → recompute).
 * @return array{watts: int, phase: string, reason: string, charge_hours?: array<int, int>}
 */
function gaming_hub_ecoflow_delta1500_selfpowered_decide( $hour, $soc, $charge_day, $load_w = 0.0, array $charge_hours = array() ) {
	unset( $charge_day );
	$idle_w   = 0;
	$charge_w = gaming_hub_ecoflow_delta1500_selfpowered_charge_watts( $load_w );
	$below    = defined( 'GAMING_HUB_ECOFLOW_DELTA1500_SELFPOWERED_CHARGE_BELOW_SOC' )
		? (float) GAMING_HUB_ECOFLOW_DELTA1500_SELFPOWERED_CHARGE_BELOW_SOC
		: 98.0;
	$rescue   = defined( 'GAMING_HUB_ECOFLOW_DELTA1500_SELFPOWERED_RESCUE_SOC' )
		? (float) GAMING_HUB_ECOFLOW_DELTA1500_SELFPOWERED_RESCUE_SOC
		: 5.0;
	$hour     = (int) $hour;

	// Emergency floor — no PV to recover from a drained UPS pack.
	if ( (float) $soc <= $rescue ) {
		return array(
			'watts'         => $charge_w,
			'phase'         => 'rescue_floor',
			'charge_hours'  => $charge_hours,
			'reason'        => sprintf(
				'SOC %.1f%% ≤ %.0f%% floor (no solar) → AC charge %d W.',
				$soc,
				$rescue,
				$charge_w
			),
		);
	}

	if ( ! $charge_hours ) {
		$pick          = gaming_hub_ecoflow_delta1500_pick_cheap_full_hours(
			wp_date( 'Y-m-d' ),
			$hour,
			(float) $soc
		);
		$charge_hours  = is_array( $pick['charge_hours'] ?? null ) ? $pick['charge_hours'] : array();
	}
	$charge_hours = array_values( array_map( 'intval', $charge_hours ) );

	if ( in_array( $hour, $charge_hours, true ) && (float) $soc < $below ) {
		return array(
			'watts'        => $charge_w,
			'phase'        => 'ac_topup',
			'charge_hours' => $charge_hours,
			'reason'       => sprintf(
				'Cheapest-hour window and SOC %.1f%% < %.0f%% (no solar) → AC charge %d W.',
				$soc,
				$below,
				$charge_w
			),
		);
	}

	// 15:00–21:59 — peak: pack supplies UPS, no free grid charge.
	if ( gaming_hub_ecoflow_delta1500_selfpowered_is_peak_hour( $hour ) ) {
		return array(
			'watts'        => $idle_w,
			'phase'        => 'peak_discharge',
			'charge_hours' => $charge_hours,
			'reason'       => 'Peak window → AC idle (prefer pack; no solar refill).',
		);
	}

	if ( $charge_hours ) {
		$labels = array();
		foreach ( $charge_hours as $h ) {
			$labels[] = sprintf( '%02d:00', (int) $h );
		}
		return array(
			'watts'        => $idle_w,
			'phase'        => 'await_cheap',
			'charge_hours' => $charge_hours,
			'reason'       => sprintf(
				'Waiting for cheap full-charge hours (%s); SOC %.1f%%.',
				implode( ', ', $labels ),
				$soc
			),
		);
	}

	return array(
		'watts'        => $idle_w,
		'phase'        => 'standby_full',
		'charge_hours' => array(),
		'reason'       => sprintf( 'SOC %.1f%% already ≥ %.0f%% → AC idle (no grid charge).', $soc, $below ),
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
	$decision = gaming_hub_ecoflow_delta1500_selfpowered_decide(
		$hour,
		(float) $soc,
		! empty( $prices['charge_day'] ),
		$load_w,
		is_array( $pick['charge_hours'] ?? null ) ? $pick['charge_hours'] : array()
	);
	$watts   = (int) $decision['watts'];
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
