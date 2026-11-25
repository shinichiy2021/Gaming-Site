<?php
/**
 * DELTA 3 1500 — winter self-powered style AC controller (MQTT via bridge).
 *
 * Same price-spread / hour windows as Pro’s backup-reserve controller, but the
 * 1500 has no solar input: never leave PV headroom. On charge days fill toward
 * 100% via AC in the 14:00 window; otherwise keep AC charge off (bypassBan +
 * Energy Management off via bridge ac_charge 0 W) so UPS draws from the pack.
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

/** AC top-up watts when the 14:00 charge window is active (clamped to device max). */
define( 'GAMING_HUB_ECOFLOW_DELTA1500_SELFPOWERED_CHARGE_W', 600 );
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
 * Charge watts for an active top-up window (covers UPS load + pack surplus).
 *
 * @param float $load_w Live AC-out / UPS watts.
 */
function gaming_hub_ecoflow_delta1500_selfpowered_charge_watts( $load_w = 0.0 ) {
	$base = defined( 'GAMING_HUB_ECOFLOW_DELTA1500_SELFPOWERED_CHARGE_W' )
		? (int) GAMING_HUB_ECOFLOW_DELTA1500_SELFPOWERED_CHARGE_W
		: 600;
	$head = defined( 'GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_HEADROOM_W' )
		? (int) GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_HEADROOM_W
		: 200;
	$watts = max( $base, (int) round( (float) $load_w + $head ) );
	if ( function_exists( 'gaming_hub_ecoflow_clamp_delta1500_charge_watts' ) ) {
		return gaming_hub_ecoflow_clamp_delta1500_charge_watts( $watts );
	}

	return max( 0, min( 1500, $watts ) );
}

/**
 * Decide AC setpoint for the current local hour + SOC.
 *
 * No solar: never assume PV will refill — charge-day top-up aims near 100%,
 * and a deep-discharge floor always requests AC charge.
 *
 * @param int   $hour       0–23 Asia/Tokyo.
 * @param float $soc        Live Delta SOC %.
 * @param bool  $charge_day Price-spread charge day.
 * @param float $load_w     UPS / AC-out watts.
 * @return array{watts: int, phase: string, reason: string}
 */
function gaming_hub_ecoflow_delta1500_selfpowered_decide( $hour, $soc, $charge_day, $load_w = 0.0 ) {
	$idle_w   = 0;
	$charge_w = gaming_hub_ecoflow_delta1500_selfpowered_charge_watts( $load_w );
	$below    = defined( 'GAMING_HUB_ECOFLOW_DELTA1500_SELFPOWERED_CHARGE_BELOW_SOC' )
		? (float) GAMING_HUB_ECOFLOW_DELTA1500_SELFPOWERED_CHARGE_BELOW_SOC
		: 98.0;
	$rescue   = defined( 'GAMING_HUB_ECOFLOW_DELTA1500_SELFPOWERED_RESCUE_SOC' )
		? (float) GAMING_HUB_ECOFLOW_DELTA1500_SELFPOWERED_RESCUE_SOC
		: 5.0;

	// Emergency floor — no PV to recover from a drained UPS pack.
	if ( (float) $soc <= $rescue ) {
		return array(
			'watts'  => $charge_w,
			'phase'  => 'rescue_floor',
			'reason' => sprintf(
				'SOC %.1f%% ≤ %.0f%% floor (no solar) → AC charge %d W.',
				$soc,
				$rescue,
				$charge_w
			),
		);
	}

	// 14:00–14:59 — concentrated AC top-up (same window as Pro). No PV headroom → near 100%.
	if ( 14 === (int) $hour ) {
		if ( $charge_day && (float) $soc < $below ) {
			return array(
				'watts'  => $charge_w,
				'phase'  => 'ac_topup',
				'reason' => sprintf(
					'Charge day and SOC %.1f%% < %.0f%% (no solar headroom) → AC charge %d W.',
					$soc,
					$below,
					$charge_w
				),
			);
		}

		return array(
			'watts'  => $idle_w,
			'phase'  => 'ac_topup_skip',
			'reason' => $charge_day
				? sprintf( 'SOC %.1f%% already ≥ %.0f%% → AC idle (no grid charge).', $soc, $below )
				: 'Spread below threshold → AC idle (no grid charge).',
		);
	}

	// 15:00–21:59 — peak: pack supplies UPS, no free grid charge.
	if ( (int) $hour >= 15 && (int) $hour <= 21 ) {
		return array(
			'watts'  => $idle_w,
			'phase'  => 'peak_discharge',
			'reason' => 'Peak window → AC idle (prefer pack; no solar refill).',
		);
	}

	// 22:00–13:59 — standby. Unlike Pro there is no PV-priority charge path.
	return array(
		'watts'  => $idle_w,
		'phase'  => 'standby_no_pv',
		'reason' => 'Standby (no solar input) → AC idle (no free grid charge).',
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

	$load_w   = gaming_hub_ecoflow_delta1500_selfpowered_load_w();
	$decision = gaming_hub_ecoflow_delta1500_selfpowered_decide(
		$hour,
		(float) $soc,
		! empty( $prices['charge_day'] ),
		$load_w
	);
	$watts  = (int) $decision['watts'];
	$cache  = gaming_hub_ecoflow_delta1500_selfpowered_load_cache();
	$last   = isset( $cache['watts'] ) ? (int) $cache['watts'] : null;
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
				'watts'      => $watts,
				'phase'      => $decision['phase'],
				'soc'        => round( (float) $soc, 2 ),
				'load_w'     => (int) round( $load_w ),
				'charge_day' => ! empty( $prices['charge_day'] ),
				'spread'     => $prices['spread'],
				'at'         => $now->format( 'c' ),
				'reason'     => $decision['reason'],
			);
			gaming_hub_ecoflow_delta1500_selfpowered_save_cache( $cache );
			update_option( 'gaming_hub_ecoflow_delta1500_selfpowered_control', 1, false );
		}
	} elseif ( $skipped && ! $dry_run ) {
		$cache['skipped_at'] = $now->format( 'c' );
		$cache['soc']        = round( (float) $soc, 2 );
		$cache['load_w']     = (int) round( $load_w );
		gaming_hub_ecoflow_delta1500_selfpowered_save_cache( $cache );
	}

	return array(
		'ok'         => '' === $error,
		'error'      => $error,
		'at'         => $now->format( 'c' ),
		'hour'       => $hour,
		'soc'        => round( (float) $soc, 2 ),
		'load_w'     => (int) round( $load_w ),
		'charge_day' => ! empty( $prices['charge_day'] ),
		'day_min'    => $prices['day_min'],
		'peak_max'   => $prices['peak_max'],
		'spread'     => $prices['spread'],
		'phase'      => $decision['phase'],
		'reason'     => $decision['reason'],
		'watts'      => $watts,
		'last'       => $last,
		'skipped'    => $skipped,
		'sent'       => $sent,
		'dry_run'    => (bool) $dry_run,
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
