<?php
/**
 * DELTA Pro 3 — winter self-powered backup-reserve controller (Open API only).
 *
 * Wall-outlet AC only. Grid charge / discharge preference is controlled solely by
 * self-powered backup reserve % (cfgEnergyBackup + self-powered strategy).
 * Never sends maxChgSoc / minDsgSoc.
 *
 * CLI:
 *   docker exec gaming-site-wp php /var/www/html/wp-content/themes/gaming-hub/scripts/ecoflow-pro3-selfpowered-reserve.php
 *   … --dry-run
 *
 * Cron: every 5 minutes (hook gaming_hub_ecoflow_selfpowered_reserve_cron).
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Price spread (¥/kWh) that marks a “charge day”. */
define( 'GAMING_HUB_ECOFLOW_SELFPOWERED_SPREAD_YEN', 15.0 );
/** Reserve % that triggers grid top-up in the 14:00 window. */
define( 'GAMING_HUB_ECOFLOW_SELFPOWERED_CHARGE_RESERVE', 90 );
/** Reserve % for discharge / PV-only / hold-off grid charge. */
define( 'GAMING_HUB_ECOFLOW_SELFPOWERED_IDLE_RESERVE', 5 );
/** Daytime window used for cheapest slot scan (10:00–15:00 → idx 20–29). */
define( 'GAMING_HUB_ECOFLOW_SELFPOWERED_DAY_IDX_START', 20 );
define( 'GAMING_HUB_ECOFLOW_SELFPOWERED_DAY_IDX_END', 29 );
/** Evening peak window (15:00–22:00 → idx 30–43). */
define( 'GAMING_HUB_ECOFLOW_SELFPOWERED_PEAK_IDX_START', 30 );
define( 'GAMING_HUB_ECOFLOW_SELFPOWERED_PEAK_IDX_END', 43 );

/**
 * Cache path for last PUT reserve (skip duplicate sends).
 */
function gaming_hub_ecoflow_selfpowered_reserve_cache_path() {
	if ( function_exists( 'gaming_hub_ecoflow_bridge_cache_dir' ) ) {
		return trailingslashit( gaming_hub_ecoflow_bridge_cache_dir() ) . 'pro3-selfpowered-reserve.json';
	}

	$dir = WP_CONTENT_DIR . '/ecoflow-cache';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	return trailingslashit( $dir ) . 'pro3-selfpowered-reserve.json';
}

/**
 * Whether this controller owns Pro backup reserve (AI schedule must not overwrite).
 */
function gaming_hub_ecoflow_selfpowered_reserve_control_enabled() {
	$env = getenv( 'ECOFLOW_SELFPOWERED_RESERVE_CONTROL' );
	if ( false !== $env && '' !== $env ) {
		return ! in_array( strtolower( (string) $env ), array( '0', 'false', 'off', 'no' ), true );
	}

	return (bool) get_option( 'gaming_hub_ecoflow_selfpowered_reserve_control', true );
}

/**
 * Open API host. Prefer ECOFLOW_API_BASE; otherwise same host as Gaming_Hub_Ecoflow_Api.
 *
 * Spec mentions api-a.ecoflow.com for Asia — set ECOFLOW_API_BASE if your key
 * authenticates there. This site’s keys validate on api.ecoflow.com even when REGION=a.
 *
 * @param string $region Config region code.
 */
function gaming_hub_ecoflow_selfpowered_api_base( $region ) {
	$override = getenv( 'ECOFLOW_API_BASE' );
	if ( is_string( $override ) && '' !== $override ) {
		return rtrim( $override, '/' );
	}
	$region = strtolower( (string) $region );
	return ( 'eu' === $region ) ? 'https://api-e.ecoflow.com' : 'https://api.ecoflow.com';
}

/**
 * Flatten nested params for HMAC signing (EcoFlow Open API).
 *
 * @param array<string, mixed> $params Params.
 * @param string               $prefix Key prefix.
 * @return array<string, string>
 */
function gaming_hub_ecoflow_selfpowered_flatten_params( array $params, $prefix = '' ) {
	$items = array();
	foreach ( $params as $key => $value ) {
		$new_key = '' !== $prefix ? $prefix . '.' . $key : (string) $key;
		if ( is_array( $value ) ) {
			$items = array_merge( $items, gaming_hub_ecoflow_selfpowered_flatten_params( $value, $new_key ) );
		} elseif ( is_bool( $value ) ) {
			$items[ $new_key ] = $value ? 'true' : 'false';
		} else {
			$items[ $new_key ] = (string) $value;
		}
	}

	return $items;
}

/**
 * Signed Open API request against api-a (or configured region host).
 *
 * @param string               $method   GET|PUT.
 * @param string               $endpoint Path.
 * @param array<string, mixed> $params   Query or JSON body.
 * @param bool                 $sign_body Include flattened body in signature.
 * @return array<string, mixed>|WP_Error
 */
function gaming_hub_ecoflow_selfpowered_signed_request( $method, $endpoint, array $params = array(), $sign_body = true ) {
	$config = gaming_hub_get_ecoflow_config();
	$base   = gaming_hub_ecoflow_selfpowered_api_base( $config['region'] ?? 'a' );
	$key    = (string) ( $config['access_key'] ?? '' );
	$secret = (string) ( $config['secret_key'] ?? '' );

	if ( '' === $key || '' === $secret ) {
		return new WP_Error( 'ecoflow_not_configured', 'EcoFlow API keys missing.' );
	}

	$timestamp  = (string) round( microtime( true ) * 1000 );
	$nonce      = (string) wp_rand( 100000, 999999 );
	$params_str = '';
	if ( $sign_body && $params ) {
		$flat = gaming_hub_ecoflow_selfpowered_flatten_params( $params );
		ksort( $flat );
		$parts = array();
		foreach ( $flat as $k => $v ) {
			$parts[] = $k . '=' . $v;
		}
		$params_str = implode( '&', $parts );
	}

	$auth_str = 'accessKey=' . $key . '&nonce=' . $nonce . '&timestamp=' . $timestamp;
	$sign_str = $params_str ? $params_str . '&' . $auth_str : $auth_str;
	$sign     = hash_hmac( 'sha256', $sign_str, $secret );

	$headers = array(
		'accessKey' => $key,
		'timestamp' => $timestamp,
		'nonce'     => $nonce,
		'sign'      => $sign,
	);

	if ( 'GET' === strtoupper( $method ) ) {
		$url = $base . $endpoint;
		if ( $params_str ) {
			$url .= '?' . $params_str;
		}
		$response = wp_remote_get(
			$url,
			array(
				'headers' => $headers,
				'timeout' => 20,
			)
		);
	} else {
		$headers['Content-Type'] = 'application/json;charset=UTF-8';
		$response                = wp_remote_request(
			$base . $endpoint,
			array(
				'method'  => strtoupper( $method ),
				'headers' => $headers,
				'body'    => wp_json_encode( $params ),
				'timeout' => 20,
			)
		);
	}

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$raw  = wp_remote_retrieve_body( $response );
	$http = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( (string) $raw, true );
	if ( ! is_array( $body ) ) {
		return new WP_Error( 'ecoflow_invalid_response', 'Invalid EcoFlow API response.', array( 'http' => $http ) );
	}

	$code = isset( $body['code'] ) ? (string) $body['code'] : '';
	if ( ! in_array( $code, array( '0', '200' ), true ) ) {
		return new WP_Error(
			'ecoflow_api_error',
			(string) ( $body['message'] ?? 'EcoFlow API error' ),
			array(
				'code' => $code,
				'http' => $http,
			)
		);
	}

	return isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
}

/**
 * Live Pro SOC from quota/all (via shared API client host).
 *
 * @return float|WP_Error
 */
function gaming_hub_ecoflow_selfpowered_fetch_soc() {
	$config = gaming_hub_get_ecoflow_config();
	$sn     = (string) ( $config['device_sn'] ?? '' );
	if ( '' === $sn ) {
		return new WP_Error( 'ecoflow_no_sn', 'ECOFLOW_DEVICE_SN missing.' );
	}

	$api  = new Gaming_Hub_Ecoflow_Api( $config['access_key'], $config['secret_key'], $config['region'] );
	$data = $api->get_device_quota( $sn );
	if ( is_wp_error( $data ) ) {
		return $data;
	}

	foreach ( array( 'cmsBattSoc', 'bmsBattSoc', 'soc', 'bmsSoc' ) as $key ) {
		if ( isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ) {
			return (float) $data[ $key ];
		}
	}

	return new WP_Error( 'ecoflow_no_soc', 'cmsBattSoc not found in quota.' );
}

/**
 * Today's LOOOP billed ¥/kWh as 48 half-hour slots (index 0 = 00:00).
 *
 * @return array{slots: array<int, float>, charge_day: bool, day_min: float, peak_max: float, spread: float}|WP_Error
 */
function gaming_hub_ecoflow_selfpowered_today_price_slots() {
	if ( ! function_exists( 'gaming_hub_get_looop_forecast' ) ) {
		return new WP_Error( 'looop_missing', 'LOOOP forecast unavailable.' );
	}

	$forecast = gaming_hub_get_looop_forecast( false );
	if ( is_wp_error( $forecast ) ) {
		return $forecast;
	}

	$raw_slots = $forecast['days']['today']['slots'] ?? array();
	$slots     = array_fill( 0, 48, null );

	foreach ( $raw_slots as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$hour = isset( $row['hour'] ) ? (int) $row['hour'] : null;
		$min  = isset( $row['minute'] ) ? (int) $row['minute'] : 0;
		if ( null === $hour ) {
			continue;
		}
		$idx = ( $hour * 2 ) + ( $min >= 30 ? 1 : 0 );
		if ( $idx < 0 || $idx > 47 ) {
			continue;
		}
		$slots[ $idx ] = (float) ( $row['total_price'] ?? 0 );
	}

	// Fill gaps from hourly map if a half-hour is missing.
	$hourly = array();
	foreach ( $forecast['hourly_today'] ?? array() as $row ) {
		$hourly[ (int) ( $row['hour'] ?? -1 ) ] = (float) ( $row['total_price'] ?? 0 );
	}
	for ( $i = 0; $i < 48; $i++ ) {
		if ( null !== $slots[ $i ] ) {
			continue;
		}
		$h = (int) floor( $i / 2 );
		$slots[ $i ] = isset( $hourly[ $h ] ) ? (float) $hourly[ $h ] : 0.0;
	}

	$day_vals  = array();
	$peak_vals = array();
	for ( $i = GAMING_HUB_ECOFLOW_SELFPOWERED_DAY_IDX_START; $i <= GAMING_HUB_ECOFLOW_SELFPOWERED_DAY_IDX_END; $i++ ) {
		$day_vals[] = (float) $slots[ $i ];
	}
	for ( $i = GAMING_HUB_ECOFLOW_SELFPOWERED_PEAK_IDX_START; $i <= GAMING_HUB_ECOFLOW_SELFPOWERED_PEAK_IDX_END; $i++ ) {
		$peak_vals[] = (float) $slots[ $i ];
	}

	$day_min  = $day_vals ? min( $day_vals ) : 0.0;
	$peak_max = $peak_vals ? max( $peak_vals ) : 0.0;
	$spread   = $peak_max - $day_min;

	return array(
		'slots'      => array_map( 'floatval', $slots ),
		'charge_day' => $spread >= GAMING_HUB_ECOFLOW_SELFPOWERED_SPREAD_YEN,
		'day_min'    => round( $day_min, 2 ),
		'peak_max'   => round( $peak_max, 2 ),
		'spread'     => round( $spread, 2 ),
	);
}

/**
 * Decide target backup reserve for the current local hour + SOC.
 *
 * @param int   $hour       0–23 Asia/Tokyo.
 * @param float $soc        Live Pro SOC %.
 * @param bool  $charge_day Price-spread charge day.
 * @return array{reserve: int, phase: string, reason: string}
 */
function gaming_hub_ecoflow_selfpowered_decide_reserve( $hour, $soc, $charge_day ) {
	$idle   = (int) GAMING_HUB_ECOFLOW_SELFPOWERED_IDLE_RESERVE;
	$charge = (int) GAMING_HUB_ECOFLOW_SELFPOWERED_CHARGE_RESERVE;

	// 14:00–14:59 — concentrated AC top-up window (winter).
	if ( 14 === (int) $hour ) {
		if ( $charge_day && (float) $soc < 90.0 ) {
			return array(
				'reserve' => $charge,
				'phase'   => 'ac_topup',
				'reason'  => sprintf(
					'Charge day (spread ok) and SOC %.1f%% < 90%% → reserve %d%% (grid top-up).',
					$soc,
					$charge
				),
			);
		}

		return array(
			'reserve' => $idle,
			'phase'   => 'ac_topup_skip',
			'reason'  => $charge_day
				? sprintf( 'SOC %.1f%% already ≥ 90%% → reserve %d%% (no grid charge).', $soc, $idle )
				: sprintf( 'Spread below threshold → reserve %d%% (no grid charge).', $idle ),
		);
	}

	// 15:00–21:59 — peak discharge.
	if ( (int) $hour >= 15 && (int) $hour <= 21 ) {
		return array(
			'reserve' => $idle,
			'phase'   => 'peak_discharge',
			'reason'  => sprintf( 'Peak window → reserve %d%% (prefer pack until floor).', $idle ),
		);
	}

	// 22:00–13:59 — standby / PV-only.
	return array(
		'reserve' => $idle,
		'phase'   => 'pv_standby',
		'reason'  => sprintf( 'Standby / PV-priority → reserve %d%% (no grid charge).', $idle ),
	);
}

/**
 * PUT self-powered ON + backup reserve only (no maxChgSoc / minDsgSoc).
 *
 * @param int $reserve 5–100.
 * @return true|WP_Error
 */
function gaming_hub_ecoflow_selfpowered_put_reserve( $reserve ) {
	$config  = gaming_hub_get_ecoflow_config();
	$sn      = (string) ( $config['device_sn'] ?? '' );
	$reserve = max( 5, min( 100, (int) $reserve ) );
	if ( '' === $sn ) {
		return new WP_Error( 'ecoflow_no_sn', 'ECOFLOW_DEVICE_SN missing.' );
	}

	// Self-powered (自己消費) + backup reserve only — never maxChgSoc / minDsgSoc.
	$params = array(
		'cfgEnergyStrategyOperateMode' => array(
			'operateSelfPoweredOpen'             => true,
			'operateScheduledOpen'               => false,
			'operateTouModeOpen'                 => false,
			'operateIntelligentScheduleModeOpen' => false,
		),
		'cfgEnergyBackup'              => array(
			'energyBackupEn'       => true,
			'energyBackupStartSoc' => $reserve,
		),
	);

	$api    = new Gaming_Hub_Ecoflow_Api( $config['access_key'], $config['secret_key'], $config['region'] );
	$result = $api->set_device_quota( $sn, $params );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return true;
}

/**
 * Read / write last-sent reserve cache.
 *
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_selfpowered_load_cache() {
	$path = gaming_hub_ecoflow_selfpowered_reserve_cache_path();
	if ( ! file_exists( $path ) ) {
		return array();
	}
	$raw = json_decode( (string) file_get_contents( $path ), true );
	return is_array( $raw ) ? $raw : array();
}

/**
 * @param array<string, mixed> $cache Cache payload.
 */
function gaming_hub_ecoflow_selfpowered_save_cache( array $cache ) {
	$path = gaming_hub_ecoflow_selfpowered_reserve_cache_path();
	$dir  = dirname( $path );
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	file_put_contents( $path, wp_json_encode( $cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
}

/**
 * Run one control cycle.
 *
 * @param bool $dry_run Skip PUT.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_selfpowered_reserve_run( $dry_run = false ) {
	$tz   = function_exists( 'gaming_hub_looop_timezone' ) ? gaming_hub_looop_timezone() : new DateTimeZone( 'Asia/Tokyo' );
	$now  = new DateTimeImmutable( 'now', $tz );
	$hour = (int) $now->format( 'G' );

	$prices = gaming_hub_ecoflow_selfpowered_today_price_slots();
	if ( is_wp_error( $prices ) ) {
		return array(
			'ok'    => false,
			'error' => $prices->get_error_message(),
			'at'    => $now->format( 'c' ),
		);
	}

	$soc = gaming_hub_ecoflow_selfpowered_fetch_soc();
	if ( is_wp_error( $soc ) ) {
		return array(
			'ok'    => false,
			'error' => $soc->get_error_message(),
			'at'    => $now->format( 'c' ),
			'prices'=> $prices,
		);
	}

	$decision = gaming_hub_ecoflow_selfpowered_decide_reserve( $hour, (float) $soc, ! empty( $prices['charge_day'] ) );
	$reserve  = (int) $decision['reserve'];
	$cache    = gaming_hub_ecoflow_selfpowered_load_cache();
	$last     = isset( $cache['reserve'] ) ? (int) $cache['reserve'] : null;
	$skipped  = ( null !== $last && $last === $reserve );
	$sent     = false;
	$error    = '';

	if ( ! $skipped && ! $dry_run ) {
		$put = gaming_hub_ecoflow_selfpowered_put_reserve( $reserve );
		if ( is_wp_error( $put ) ) {
			$error = $put->get_error_message();
		} else {
			$sent = true;
			$cache = array(
				'reserve'    => $reserve,
				'phase'      => $decision['phase'],
				'soc'        => round( (float) $soc, 2 ),
				'charge_day' => ! empty( $prices['charge_day'] ),
				'spread'     => $prices['spread'],
				'at'         => $now->format( 'c' ),
				'reason'     => $decision['reason'],
			);
			gaming_hub_ecoflow_selfpowered_save_cache( $cache );
			update_option( 'gaming_hub_ecoflow_selfpowered_reserve_control', 1, false );
		}
	} elseif ( $skipped && ! $dry_run ) {
		$cache['skipped_at'] = $now->format( 'c' );
		$cache['soc']        = round( (float) $soc, 2 );
		gaming_hub_ecoflow_selfpowered_save_cache( $cache );
	}

	return array(
		'ok'         => '' === $error,
		'error'      => $error,
		'at'         => $now->format( 'c' ),
		'hour'       => $hour,
		'soc'        => round( (float) $soc, 2 ),
		'charge_day' => ! empty( $prices['charge_day'] ),
		'day_min'    => $prices['day_min'],
		'peak_max'   => $prices['peak_max'],
		'spread'     => $prices['spread'],
		'phase'      => $decision['phase'],
		'reason'     => $decision['reason'],
		'reserve'    => $reserve,
		'last'       => $last,
		'skipped'    => $skipped,
		'sent'       => $sent,
		'dry_run'    => (bool) $dry_run,
	);
}

/**
 * WP-Cron callback.
 */
function gaming_hub_ecoflow_selfpowered_reserve_cron() {
	if ( ! gaming_hub_ecoflow_selfpowered_reserve_control_enabled() ) {
		return;
	}
	if ( ! function_exists( 'gaming_hub_ecoflow_is_configured' ) || ! gaming_hub_ecoflow_is_configured() ) {
		return;
	}
	gaming_hub_ecoflow_selfpowered_reserve_run( false );
}

/**
 * Ensure a 5-minute cron exists.
 */
function gaming_hub_ecoflow_selfpowered_reserve_schedule_cron() {
	$hook = 'gaming_hub_ecoflow_selfpowered_reserve_cron';
	if ( ! wp_next_scheduled( $hook ) ) {
		wp_schedule_event( time() + 60, 'gaming_hub_every_five_minutes', $hook );
	}
}

/**
 * Register a 5-minute cron schedule.
 *
 * @param array<string, array<string, mixed>> $schedules Schedules.
 * @return array<string, array<string, mixed>>
 */
function gaming_hub_ecoflow_selfpowered_cron_schedules( $schedules ) {
	if ( ! isset( $schedules['gaming_hub_every_five_minutes'] ) ) {
		$schedules['gaming_hub_every_five_minutes'] = array(
			'interval' => 300,
			'display'  => 'Every five minutes (EcoFlow self-powered reserve)',
		);
	}
	return $schedules;
}

add_filter( 'cron_schedules', 'gaming_hub_ecoflow_selfpowered_cron_schedules' );
add_action( 'gaming_hub_ecoflow_selfpowered_reserve_cron', 'gaming_hub_ecoflow_selfpowered_reserve_cron' );
add_action( 'init', 'gaming_hub_ecoflow_selfpowered_reserve_schedule_cron', 30 );
