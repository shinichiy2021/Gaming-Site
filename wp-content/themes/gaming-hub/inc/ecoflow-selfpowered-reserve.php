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
 * Whether a calendar day is a “charge day” from LOOOP day/peak spread.
 *
 * @param string $ymd Y-m-d.
 * @return array{charge_day: bool, day_min: float, peak_max: float, spread: float}
 */
function gaming_hub_ecoflow_selfpowered_spread_for_date( $ymd ) {
	$ymd = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $ymd ) ? (string) $ymd : wp_date( 'Y-m-d' );
	$map = function_exists( 'gaming_hub_ecoflow_day_price_map' )
		? gaming_hub_ecoflow_day_price_map( $ymd )
		: array();

	$day_vals  = array();
	$peak_vals = array();
	if ( is_array( $map ) && $map ) {
		// Half-hour index approx from hourly map: hour h → slots 2h and 2h+1.
		for ( $h = 10; $h <= 14; $h++ ) {
			if ( isset( $map[ $h ] ) && is_numeric( $map[ $h ] ) ) {
				$day_vals[] = (float) $map[ $h ];
			}
		}
		for ( $h = 15; $h <= 21; $h++ ) {
			if ( isset( $map[ $h ] ) && is_numeric( $map[ $h ] ) ) {
				$peak_vals[] = (float) $map[ $h ];
			}
		}
	}

	$day_min  = $day_vals ? min( $day_vals ) : 0.0;
	$peak_max = $peak_vals ? max( $peak_vals ) : 0.0;
	$spread   = $peak_max - $day_min;
	$threshold = defined( 'GAMING_HUB_ECOFLOW_SELFPOWERED_SPREAD_YEN' )
		? (float) GAMING_HUB_ECOFLOW_SELFPOWERED_SPREAD_YEN
		: 15.0;

	return array(
		'charge_day' => $spread >= $threshold,
		'day_min'    => round( $day_min, 2 ),
		'peak_max'   => round( $peak_max, 2 ),
		'spread'     => round( $spread, 2 ),
	);
}

/**
 * Aggregate measured Wh for a list of hours on a plan date.
 *
 * @param string     $plan_date Y-m-d.
 * @param array<int> $hours     Hours 0–23.
 * @return array{has: bool, label: string|null, grid_kwh: float, solar_kwh: float, out_kwh: float}
 */
function gaming_hub_ecoflow_selfpowered_sum_hours_actual( $plan_date, array $hours ) {
	$empty = array(
		'has'       => false,
		'label'     => null,
		'grid_kwh'  => 0.0,
		'solar_kwh' => 0.0,
		'out_kwh'   => 0.0,
	);
	if ( ! function_exists( 'gaming_hub_ecoflow_energy_hours_for_date' ) ) {
		return $empty;
	}

	$log   = gaming_hub_ecoflow_energy_hours_for_date( $plan_date );
	$log   = is_array( $log ) ? $log : array();
	$grid  = 0.0;
	$solar = 0.0;
	$out   = 0.0;
	$has   = false;

	foreach ( $hours as $h ) {
		$h   = (int) $h;
		$row = isset( $log[ $h ] ) && is_array( $log[ $h ] ) ? $log[ $h ] : null;
		if ( ! $row ) {
			continue;
		}
		$pro   = max( 0.0, (float) ( $row['pro_ac_in_wh'] ?? 0 ) );
		$delta = max( 0.0, (float) ( $row['delta_ac_in_wh'] ?? 0 ) );
		if ( $delta <= 0 && isset( $row['ac_in_wh'] ) ) {
			$delta = max( 0.0, (float) $row['ac_in_wh'] );
		}
		$pv = max( 0.0, (float) ( $row['solar_wh'] ?? 0 ) );
		$load = max( 0.0, (float) ( $row['room_out_wh'] ?? 0 ) ) + max( 0.0, (float) ( $row['ups_out_wh'] ?? 0 ) );
		if ( $load <= 0 && isset( $row['output_wh'] ) ) {
			$load = max( 0.0, (float) $row['output_wh'] );
		}
		if ( $pro > 0 || $delta > 0 || $pv > 0 || $load > 0 || isset( $row['soc'] ) ) {
			$has = true;
		}
		$grid  += $pro + $delta;
		$solar += $pv;
		$out   += $load;
	}

	if ( ! $has ) {
		return $empty;
	}

	$grid_kwh  = round( $grid / 1000.0, 2 );
	$solar_kwh = round( $solar / 1000.0, 2 );
	$out_kwh   = round( $out / 1000.0, 2 );
	$bits      = array();
	if ( $grid_kwh > 0 ) {
		$bits[] = sprintf(
			/* translators: %s: kWh */
			__( 'Grid %s kWh', 'gaming-hub' ),
			number_format_i18n( $grid_kwh, 2 )
		);
	}
	if ( $solar_kwh > 0 ) {
		$bits[] = sprintf(
			/* translators: %s: kWh */
			__( 'PV %s kWh', 'gaming-hub' ),
			number_format_i18n( $solar_kwh, 2 )
		);
	}
	if ( $out_kwh > 0 ) {
		$bits[] = sprintf(
			/* translators: %s: kWh */
			__( 'OUT %s kWh', 'gaming-hub' ),
			number_format_i18n( $out_kwh, 2 )
		);
	}
	if ( ! $bits ) {
		$bits[] = __( '計測あり', 'gaming-hub' );
	}

	return array(
		'has'       => true,
		'label'     => implode( ' · ', $bits ),
		'grid_kwh'  => $grid_kwh,
		'solar_kwh' => $solar_kwh,
		'out_kwh'   => $out_kwh,
	);
}

/**
 * Actuals for the three self-powered windows on a plan date.
 *
 * @param string                    $plan_date Y-m-d.
 * @param array<string, array<int>> $windows   Named hour lists.
 * @return array<string, array{has: bool, label: string|null, grid_kwh: float, solar_kwh: float, out_kwh: float}>
 */
function gaming_hub_ecoflow_selfpowered_window_actuals( $plan_date, array $windows ) {
	$out = array();
	foreach ( $windows as $key => $hours ) {
		$out[ $key ] = gaming_hub_ecoflow_selfpowered_sum_hours_actual( $plan_date, $hours );
	}
	return $out;
}

/**
 * AI Plan overlay: self-powered grid-charge schedule (Pro reserve + Delta AC).
 *
 * @param string               $plan_date Y-m-d.
 * @param array<string, mixed> $status    Device status.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_selfpowered_plan_schedule( $plan_date, array $status = array() ) {
	$pro_on   = function_exists( 'gaming_hub_ecoflow_selfpowered_reserve_control_enabled' )
		&& gaming_hub_ecoflow_selfpowered_reserve_control_enabled();
	$delta_on = function_exists( 'gaming_hub_ecoflow_delta1500_selfpowered_control_enabled' )
		&& gaming_hub_ecoflow_delta1500_selfpowered_control_enabled();

	if ( ! $pro_on && ! $delta_on ) {
		return array(
			'enabled' => false,
		);
	}

	$spread_info = gaming_hub_ecoflow_selfpowered_spread_for_date( $plan_date );
	$charge_day  = ! empty( $spread_info['charge_day'] );
	$idle_r      = defined( 'GAMING_HUB_ECOFLOW_SELFPOWERED_IDLE_RESERVE' )
		? (int) GAMING_HUB_ECOFLOW_SELFPOWERED_IDLE_RESERVE
		: 5;
	$charge_r    = defined( 'GAMING_HUB_ECOFLOW_SELFPOWERED_CHARGE_RESERVE' )
		? (int) GAMING_HUB_ECOFLOW_SELFPOWERED_CHARGE_RESERVE
		: 90;
	$delta_w     = defined( 'GAMING_HUB_ECOFLOW_DELTA1500_SELFPOWERED_CHARGE_W' )
		? (int) GAMING_HUB_ECOFLOW_DELTA1500_SELFPOWERED_CHARGE_W
		: 600;
	$pro_soc     = isset( $status['battery_percent'] ) && is_numeric( $status['battery_percent'] )
		? (float) $status['battery_percent']
		: null;
	$delta_soc   = isset( $status['secondary']['battery_percent'] ) && is_numeric( $status['secondary']['battery_percent'] )
		? (float) $status['secondary']['battery_percent']
		: null;
	$pro_needs   = null === $pro_soc || $pro_soc < 90.0;
	$delta_needs = null === $delta_soc || $delta_soc < 100.0;
	$will_charge = $charge_day && ( ( $pro_on && $pro_needs ) || ( $delta_on && $delta_needs ) );

	$hours = array();
	for ( $h = 0; $h < 24; $h++ ) {
		if ( 14 === $h && $will_charge ) {
			$hours[ $h ] = array(
				'mode'        => 'charge',
				'phase'       => 'ac_topup',
				'pro_reserve' => $pro_on ? $charge_r : null,
				'delta_watts' => $delta_on ? $delta_w : null,
				'watts'       => $delta_on ? $delta_w : ( defined( 'GAMING_HUB_ECOFLOW_PLAN_CHARGE_W' ) ? (int) GAMING_HUB_ECOFLOW_PLAN_CHARGE_W : 1000 ),
			);
		} elseif ( $h >= 15 && $h <= 21 ) {
			$hours[ $h ] = array(
				'mode'        => 'use',
				'phase'       => 'peak_discharge',
				'pro_reserve' => $pro_on ? $idle_r : null,
				'delta_watts' => $delta_on ? 0 : null,
				'watts'       => 0,
			);
		} else {
			$hours[ $h ] = array(
				'mode'        => 'idle',
				'phase'       => ( 14 === $h ) ? 'ac_topup_skip' : 'standby',
				'pro_reserve' => $pro_on ? $idle_r : null,
				'delta_watts' => $delta_on ? 0 : null,
				'watts'       => 0,
			);
		}
	}

	$windows = array(
		'charge'  => array( 14 ),
		'peak'    => range( 15, 21 ),
		'standby' => array_merge( range( 22, 23 ), range( 0, 13 ) ),
	);
	$actuals = gaming_hub_ecoflow_selfpowered_window_actuals( $plan_date, $windows );

	$rows = array();
	if ( $will_charge ) {
		$parts = array();
		if ( $pro_on ) {
			$parts[] = sprintf(
				/* translators: %d: backup reserve percent */
				__( 'Pro 予備残量 %d%%', 'gaming-hub' ),
				$charge_r
			);
		}
		if ( $delta_on ) {
			$parts[] = sprintf(
				/* translators: %d: AC charge watts */
				__( 'Delta AC %d W', 'gaming-hub' ),
				$delta_w
			);
		}
		$rows[] = array(
			'time'   => '14:00–15:00',
			'action' => __( 'グリッド充電', 'gaming-hub' ) . '（' . implode( ' · ', $parts ) . '）',
			'mode'   => 'charge',
			'active' => true,
			'actual' => $actuals['charge']['label'] ?? null,
			'has_actual' => ! empty( $actuals['charge']['has'] ),
		);
	} elseif ( $charge_day ) {
		$rows[] = array(
			'time'   => '14:00–15:00',
			'action' => __( 'グリッド充電スキップ（目標SOC到達済み）', 'gaming-hub' ),
			'mode'   => 'idle',
			'active' => false,
			'actual' => $actuals['charge']['label'] ?? null,
			'has_actual' => ! empty( $actuals['charge']['has'] ),
		);
	} else {
		$rows[] = array(
			'time'   => '14:00–15:00',
			'action' => __( 'グリッド充電なし（価格スプレッド不足）', 'gaming-hub' ),
			'mode'   => 'idle',
			'active' => false,
			'actual' => $actuals['charge']['label'] ?? null,
			'has_actual' => ! empty( $actuals['charge']['has'] ),
		);
	}
	$rows[] = array(
		'time'   => '15:00–22:00',
		'action' => __( 'ピーク放電（パック優先・グリッド充電オフ）', 'gaming-hub' ),
		'mode'   => 'use',
		'active' => true,
		'actual' => $actuals['peak']['label'] ?? null,
		'has_actual' => ! empty( $actuals['peak']['has'] ),
	);
	$standby = __( '待機（グリッド充電オフ）', 'gaming-hub' );
	if ( $pro_on && $delta_on ) {
		$standby = __( '待機（Proは太陽光優先 / Deltaはソーラーなし・グリッド充電オフ）', 'gaming-hub' );
	} elseif ( $pro_on ) {
		$standby = __( '待機（太陽光優先・グリッド充電オフ）', 'gaming-hub' );
	} elseif ( $delta_on ) {
		$standby = __( '待機（ソーラーなし / グリッド充電オフ）', 'gaming-hub' );
	}
	$rows[] = array(
		'time'   => '22:00–14:00',
		'action' => $standby,
		'mode'   => 'idle',
		'active' => true,
		'actual' => $actuals['standby']['label'] ?? null,
		'has_actual' => ! empty( $actuals['standby']['has'] ),
	);

	$summary = $will_charge
		? sprintf(
			/* translators: 1: spread yen, 2: charge window */
			__( 'self-powered · charge day（スプレッド %1$s 円）· 充電枠 %2$s', 'gaming-hub' ),
			number_format_i18n( (float) $spread_info['spread'], 1 ),
			'14:00'
		)
		: ( $charge_day
			? sprintf(
				/* translators: %s: spread yen */
				__( 'self-powered · charge day（スプレッド %s 円）· 本日の追加充電なし', 'gaming-hub' ),
				number_format_i18n( (float) $spread_info['spread'], 1 )
			)
			: sprintf(
				/* translators: %s: spread yen */
				__( 'self-powered · 非charge day（スプレッド %s 円）· グリッド充電なし', 'gaming-hub' ),
				number_format_i18n( (float) $spread_info['spread'], 1 )
			) );

	return array(
		'enabled'       => true,
		'pro'           => $pro_on,
		'delta'         => $delta_on,
		'charge_day'    => $charge_day,
		'will_charge'   => $will_charge,
		'day_min'       => $spread_info['day_min'],
		'peak_max'      => $spread_info['peak_max'],
		'spread'        => $spread_info['spread'],
		'charge_hour'   => 14,
		'pro_reserve'   => $charge_r,
		'idle_reserve'  => $idle_r,
		'delta_watts'   => $delta_w,
		'window_label'  => $will_charge ? '14:00' : __( '充電枠なし', 'gaming-hub' ),
		'summary'       => $summary,
		'rows'          => $rows,
		'hours'         => $hours,
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
