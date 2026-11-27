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
/** Reserve % for grid top-up in the 13:30–14:59 window (also the SOC threshold: top up only below this). */
define( 'GAMING_HUB_ECOFLOW_SELFPOWERED_CHARGE_RESERVE', 80 );
/** Reserve % for peak discharge (use the pack down to the floor). */
define( 'GAMING_HUB_ECOFLOW_SELFPOWERED_IDLE_RESERVE', 5 );
/** Hold / bypass reserve = max(5, SOC − this offset) so the pack is kept and PV still raises SOC. */
define( 'GAMING_HUB_ECOFLOW_SELFPOWERED_HOLD_OFFSET', 2 );
/** Pro top-up window start (13:30) and end (15:00) as minutes since midnight. */
define( 'GAMING_HUB_ECOFLOW_SELFPOWERED_TOPUP_START_MIN', 810 );
define( 'GAMING_HUB_ECOFLOW_SELFPOWERED_TOPUP_END_MIN', 900 );
/** Delta 1500 cheapest charge window length in half-hour slots (3 h). */
define( 'GAMING_HUB_ECOFLOW_SELFPOWERED_CHEAP_WINDOW_SLOTS', 6 );
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

	$slots = gaming_hub_ecoflow_selfpowered_price_slots_from_forecast( $forecast, 'today' );
	if ( ! $slots ) {
		return new WP_Error( 'looop_no_slots', 'LOOOP slots unavailable for today.' );
	}

	$stats = gaming_hub_ecoflow_selfpowered_slot_spread( $slots );

	return array(
		'slots'      => $slots,
		'charge_day' => $stats['charge_day'],
		'day_min'    => $stats['day_min'],
		'peak_max'   => $stats['peak_max'],
		'spread'     => $stats['spread'],
	);
}

/**
 * 48 half-hour ¥/kWh values (index 0 = 00:00) for yesterday / today / tomorrow.
 *
 * @param array<string, mixed> $forecast LOOOP forecast payload.
 * @param string               $day_key  yesterday|today|tomorrow.
 * @return array<int, float>|array{} Empty when no data.
 */
function gaming_hub_ecoflow_selfpowered_price_slots_from_forecast( array $forecast, $day_key = 'today' ) {
	$day_key   = in_array( $day_key, array( 'yesterday', 'today', 'tomorrow' ), true ) ? $day_key : 'today';
	$day       = isset( $forecast['days'][ $day_key ] ) && is_array( $forecast['days'][ $day_key ] )
		? $forecast['days'][ $day_key ]
		: array();
	$raw_slots = isset( $day['slots'] ) && is_array( $day['slots'] ) ? $day['slots'] : array();
	$slots     = array_fill( 0, 48, null );
	$have      = false;

	foreach ( $raw_slots as $row ) {
		if ( ! is_array( $row ) || ! isset( $row['hour'] ) ) {
			continue;
		}
		$hour = (int) $row['hour'];
		$min  = isset( $row['minute'] ) ? (int) $row['minute'] : 0;
		$idx  = ( $hour * 2 ) + ( $min >= 30 ? 1 : 0 );
		if ( $idx < 0 || $idx > 47 ) {
			continue;
		}
		$slots[ $idx ] = (float) ( $row['total_price'] ?? 0 );
		$have          = true;
	}

	// Fill gaps from the hourly aggregate for that day.
	$hourly = array();
	$hourly_rows = isset( $day['hourly'] ) && is_array( $day['hourly'] ) ? $day['hourly'] : array();
	if ( 'today' === $day_key && ! $hourly_rows && isset( $forecast['hourly_today'] ) && is_array( $forecast['hourly_today'] ) ) {
		$hourly_rows = $forecast['hourly_today'];
	}
	foreach ( $hourly_rows as $row ) {
		if ( is_array( $row ) && isset( $row['hour'] ) ) {
			$hourly[ (int) $row['hour'] ] = (float) ( $row['total_price'] ?? 0 );
		}
	}
	if ( ! $have && ! $hourly ) {
		return array();
	}
	for ( $i = 0; $i < 48; $i++ ) {
		if ( null !== $slots[ $i ] ) {
			continue;
		}
		$h = (int) floor( $i / 2 );
		if ( ! isset( $hourly[ $h ] ) ) {
			return array();
		}
		$slots[ $i ] = (float) $hourly[ $h ];
	}

	return array_map( 'floatval', $slots );
}

/**
 * Day min / max spread (whole day) and charge-day flag (spread ≥ threshold).
 *
 * @param array<int, float> $slots 48 half-hour prices.
 * @return array{charge_day: bool, day_min: float, peak_max: float, spread: float}
 */
function gaming_hub_ecoflow_selfpowered_slot_spread( array $slots ) {
	$vals      = array_map( 'floatval', array_values( $slots ) );
	$day_min   = $vals ? min( $vals ) : 0.0;
	$day_max   = $vals ? max( $vals ) : 0.0;
	$spread    = $day_max - $day_min;
	$threshold = defined( 'GAMING_HUB_ECOFLOW_SELFPOWERED_SPREAD_YEN' )
		? (float) GAMING_HUB_ECOFLOW_SELFPOWERED_SPREAD_YEN
		: 15.0;

	return array(
		'charge_day' => $vals && $spread >= $threshold,
		'day_min'    => round( $day_min, 2 ),
		'peak_max'   => round( $day_max, 2 ),
		'spread'     => round( $spread, 2 ),
	);
}

/**
 * Half-hour price slots for a calendar date (today uses live half-hour data;
 * other days fall back to the hourly map duplicated into two slots).
 *
 * @param string $ymd Y-m-d.
 * @return array<int, float>|array{} Empty when unavailable.
 */
function gaming_hub_ecoflow_selfpowered_price_slots_for_date( $ymd ) {
	$ymd = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $ymd ) ? (string) $ymd : wp_date( 'Y-m-d' );

	if ( function_exists( 'gaming_hub_get_looop_forecast' ) && function_exists( 'gaming_hub_ecoflow_plan_dates' ) ) {
		$dates = gaming_hub_ecoflow_plan_dates();
		$key   = $ymd === $dates['yesterday'] ? 'yesterday' : ( $ymd === $dates['tomorrow'] ? 'tomorrow' : 'today' );
		$fc    = gaming_hub_get_looop_forecast( false );
		if ( ! is_wp_error( $fc ) && is_array( $fc ) ) {
			$slots = gaming_hub_ecoflow_selfpowered_price_slots_from_forecast( $fc, $key );
			if ( $slots ) {
				return $slots;
			}
		}
	}

	$map = function_exists( 'gaming_hub_ecoflow_day_price_map' )
		? gaming_hub_ecoflow_day_price_map( $ymd )
		: array();
	if ( ! is_array( $map ) || count( $map ) < 24 ) {
		return array();
	}
	$slots = array();
	for ( $h = 0; $h < 24; $h++ ) {
		if ( ! isset( $map[ $h ] ) ) {
			return array();
		}
		$slots[] = (float) $map[ $h ];
		$slots[] = (float) $map[ $h ];
	}

	return $slots;
}

/**
 * Whether a calendar day is a “charge day”: whole-day (max − min) ≥ 15 ¥/kWh.
 *
 * @param string $ymd Y-m-d.
 * @return array{charge_day: bool, day_min: float, peak_max: float, spread: float}
 */
function gaming_hub_ecoflow_selfpowered_spread_for_date( $ymd ) {
	$slots = gaming_hub_ecoflow_selfpowered_price_slots_for_date( $ymd );
	if ( ! $slots ) {
		return array(
			'charge_day' => false,
			'day_min'    => 0.0,
			'peak_max'   => 0.0,
			'spread'     => 0.0,
		);
	}

	return gaming_hub_ecoflow_selfpowered_slot_spread( $slots );
}

/**
 * Cheapest contiguous window (default 6 half-hour slots = 3 h), peak 15:00–21:59 excluded.
 *
 * @param array<int, float> $slots 48 half-hour prices.
 * @param int               $len   Window length in slots.
 * @return array{start: int, end: int, avg: float, label: string, hours: array<int, int>, slots_per_hour: array<int, int>}|null
 */
function gaming_hub_ecoflow_selfpowered_cheapest_window( array $slots, $len = 0 ) {
	$len = (int) $len > 0
		? (int) $len
		: ( defined( 'GAMING_HUB_ECOFLOW_SELFPOWERED_CHEAP_WINDOW_SLOTS' ) ? (int) GAMING_HUB_ECOFLOW_SELFPOWERED_CHEAP_WINDOW_SLOTS : 6 );
	if ( count( $slots ) < 48 || $len < 1 ) {
		return null;
	}
	$peak_start = (int) GAMING_HUB_ECOFLOW_SELFPOWERED_PEAK_IDX_START;
	$peak_end   = (int) GAMING_HUB_ECOFLOW_SELFPOWERED_PEAK_IDX_END;

	$best_start = null;
	$best_avg   = null;
	for ( $start = 0; $start + $len <= 48; $start++ ) {
		$sum = 0.0;
		$ok  = true;
		for ( $i = $start; $i < $start + $len; $i++ ) {
			if ( $i >= $peak_start && $i <= $peak_end ) {
				$ok = false;
				break;
			}
			$sum += (float) $slots[ $i ];
		}
		if ( ! $ok ) {
			continue;
		}
		$avg = $sum / $len;
		if ( null === $best_avg || $avg < $best_avg - 0.0001 ) {
			$best_avg   = $avg;
			$best_start = $start;
		}
	}
	if ( null === $best_start ) {
		return null;
	}

	$end            = $best_start + $len - 1;
	$hours          = array();
	$slots_per_hour = array();
	for ( $i = $best_start; $i <= $end; $i++ ) {
		$h = (int) floor( $i / 2 );
		if ( ! isset( $slots_per_hour[ $h ] ) ) {
			$hours[]            = $h;
			$slots_per_hour[ $h ] = 0;
		}
		++$slots_per_hour[ $h ];
	}

	return array(
		'start'          => $best_start,
		'end'            => $end,
		'avg'            => round( (float) $best_avg, 1 ),
		'label'          => gaming_hub_ecoflow_selfpowered_slot_range_label( $best_start, $end + 1 ),
		'hours'          => $hours,
		'slots_per_hour' => $slots_per_hour,
	);
}

/**
 * Label for half-hour slot range [start, end_exclusive).
 *
 * @param int $start         Start slot index.
 * @param int $end_exclusive End slot index (exclusive).
 */
function gaming_hub_ecoflow_selfpowered_slot_range_label( $start, $end_exclusive ) {
	$fmt = static function ( $idx ) {
		$idx = (int) $idx;
		return sprintf( '%d:%02d', intdiv( $idx, 2 ), ( $idx % 2 ) * 30 );
	};

	return $fmt( $start ) . '–' . $fmt( $end_exclusive );
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
		: 80;
	$hold_off    = defined( 'GAMING_HUB_ECOFLOW_SELFPOWERED_HOLD_OFFSET' )
		? (int) GAMING_HUB_ECOFLOW_SELFPOWERED_HOLD_OFFSET
		: 2;
	$charge_w    = defined( 'GAMING_HUB_ECOFLOW_PLAN_CHARGE_W' )
		? (int) GAMING_HUB_ECOFLOW_PLAN_CHARGE_W
		: 1000;
	$delta_w     = defined( 'GAMING_HUB_ECOFLOW_DELTA1500_SELFPOWERED_CHARGE_W' )
		? (int) GAMING_HUB_ECOFLOW_DELTA1500_SELFPOWERED_CHARGE_W
		: $charge_w;
	$pro_soc     = isset( $status['battery_percent'] ) && is_numeric( $status['battery_percent'] )
		? (float) $status['battery_percent']
		: null;
	$delta_soc   = isset( $status['secondary']['battery_percent'] ) && is_numeric( $status['secondary']['battery_percent'] )
		? (float) $status['secondary']['battery_percent']
		: null;
	$pro_needs   = null === $pro_soc || $pro_soc < (float) $charge_r;
	$dates       = function_exists( 'gaming_hub_ecoflow_plan_dates' ) ? gaming_hub_ecoflow_plan_dates() : array( 'today' => wp_date( 'Y-m-d' ) );
	$from_hour   = ( $plan_date === ( $dates['today'] ?? wp_date( 'Y-m-d' ) ) ) ? (int) wp_date( 'G' ) : 0;
	$delta_pick  = ( $delta_on && function_exists( 'gaming_hub_ecoflow_delta1500_pick_cheap_full_hours' ) )
		? gaming_hub_ecoflow_delta1500_pick_cheap_full_hours( $plan_date, $from_hour, $delta_soc, null, $status )
		: array(
			'charge_hours'   => array(),
			'slots_per_hour' => array(),
			'windows'        => array(),
			'avg_yen'        => null,
			'deficit_kwh'    => 0.0,
			'day_min'        => null,
		);
	$delta_hours = is_array( $delta_pick['charge_hours'] ?? null )
		? array_values( array_map( 'intval', $delta_pick['charge_hours'] ) )
		: array();
	$delta_sph   = is_array( $delta_pick['slots_per_hour'] ?? null ) ? $delta_pick['slots_per_hour'] : array();
	$pro_will    = $pro_on && $charge_day && $pro_needs;
	$delta_will  = $delta_on && ! empty( $delta_hours );
	$will_charge = $pro_will || $delta_will;

	// Pro top-up 13:30–14:59 = half-hour slots 27–29. Hold (SOC−2) 22:00–13:29; peak 15:00–21:59.
	$topup_start_idx = (int) floor( GAMING_HUB_ECOFLOW_SELFPOWERED_TOPUP_START_MIN / 30 );
	$topup_end_idx   = (int) floor( GAMING_HUB_ECOFLOW_SELFPOWERED_TOPUP_END_MIN / 30 ) - 1;
	$pro_chg_slots   = array_fill( 0, 24, 0 );
	$pro_hold_slots  = array_fill( 0, 48, false );
	for ( $i = 0; $i < 48; $i++ ) {
		$is_peak = $i >= GAMING_HUB_ECOFLOW_SELFPOWERED_PEAK_IDX_START && $i <= GAMING_HUB_ECOFLOW_SELFPOWERED_PEAK_IDX_END;
		if ( $is_peak ) {
			continue;
		}
		if ( $i >= $topup_start_idx && $i <= $topup_end_idx && $pro_will ) {
			++$pro_chg_slots[ (int) floor( $i / 2 ) ];
			continue;
		}
		if ( $pro_on ) {
			$pro_hold_slots[ $i ] = true;
		}
	}

	$pro_bypass_hours = array();
	$hours            = array();
	for ( $h = 0; $h < 24; $h++ ) {
		$pro_n     = (int) $pro_chg_slots[ $h ];
		$delta_n   = $delta_will && isset( $delta_sph[ $h ] ) ? (int) $delta_sph[ $h ] : ( $delta_will && in_array( $h, $delta_hours, true ) ? 2 : 0 );
		$pro_w_h   = (int) round( $charge_w * $pro_n / 2 );
		$delta_w_h = (int) round( $delta_w * $delta_n / 2 );
		$pro_hold  = $pro_on && ( $pro_hold_slots[ $h * 2 ] || $pro_hold_slots[ $h * 2 + 1 ] );

		if ( $pro_n > 0 || $delta_n > 0 ) {
			$hours[ $h ] = array(
				'mode'        => 'charge',
				'phase'       => 'ac_topup',
				'pro_reserve' => $pro_on ? ( $pro_n > 0 ? $charge_r : ( $pro_hold ? null : $idle_r ) ) : null,
				'pro_hold'    => $pro_on && 0 === $pro_n && $pro_hold,
				'pro_watts'   => $pro_on ? $pro_w_h : null,
				'delta_watts' => $delta_on ? $delta_w_h : null,
				'watts'       => max( $pro_w_h, $delta_w_h, $charge_w ),
			);
		} elseif ( $h >= 15 && $h <= 21 ) {
			$hours[ $h ] = array(
				'mode'        => 'use',
				'phase'       => 'peak_discharge',
				'pro_reserve' => $pro_on ? $idle_r : null,
				'delta_watts' => $delta_on ? 0 : null,
				'watts'       => 0,
			);
		} elseif ( $pro_hold ) {
			$pro_bypass_hours[] = $h;
			$hours[ $h ]        = array(
				'mode'        => 'bypass',
				'phase'       => 'hold_soc',
				'pro_reserve' => null,
				'pro_hold'    => true,
				'pro_watts'   => 0,
				'delta_watts' => $delta_on ? 0 : null,
				'watts'       => 0,
			);
		} else {
			$hours[ $h ] = array(
				'mode'        => 'idle',
				'phase'       => 'standby',
				'pro_reserve' => $pro_on ? $idle_r : null,
				'delta_watts' => $delta_on ? 0 : null,
				'watts'       => 0,
			);
		}
	}

	$pro_charge_hours = array_values(
		array_filter(
			range( 0, 23 ),
			static function ( $h ) use ( $pro_chg_slots ) {
				return $pro_chg_slots[ $h ] > 0;
			}
		)
	);
	$actual_windows = array(
		'pro_charge'   => $pro_charge_hours ? $pro_charge_hours : array( 13, 14 ),
		'pro_bypass'   => $pro_bypass_hours ? $pro_bypass_hours : array(),
		'delta_charge' => $delta_hours ? $delta_hours : array( 14 ),
		'peak'         => range( 15, 21 ),
		'standby'      => array_values(
			array_diff(
				array_merge( range( 22, 23 ), range( 0, 14 ) ),
				$delta_hours,
				$pro_bypass_hours,
				$pro_charge_hours
			)
		),
	);
	$actuals = gaming_hub_ecoflow_selfpowered_window_actuals( $plan_date, $actual_windows );

	// Hold windows at half-hour precision (e.g. 0:00–13:30 · 22:00–24:00).
	$hold_windows = array();
	$run_start    = null;
	for ( $i = 0; $i <= 48; $i++ ) {
		$on = $i < 48 && $pro_hold_slots[ $i ];
		if ( $on && null === $run_start ) {
			$run_start = $i;
		} elseif ( ! $on && null !== $run_start ) {
			$hold_windows[] = gaming_hub_ecoflow_selfpowered_slot_range_label( $run_start, $i );
			$run_start      = null;
		}
	}
	$hold_label = $hold_windows ? implode( ' · ', $hold_windows ) : '—';

	$rows = array();
	if ( $pro_on ) {
		if ( $pro_will ) {
			$rows[] = array(
				'time'       => '13:30–15:00',
				'action'     => sprintf(
					/* translators: 1: AC charge watts, 2: backup reserve percent */
					__( 'Pro グリッド充電（AC %1$d W · 予備残量 %2$d%%）', 'gaming-hub' ),
					$charge_w,
					$charge_r
				),
				'mode'       => 'charge',
				'active'     => true,
				'actual'     => $actuals['pro_charge']['label'] ?? null,
				'has_actual' => ! empty( $actuals['pro_charge']['has'] ),
			);
		} elseif ( $charge_day ) {
			$rows[] = array(
				'time'       => '13:30–15:00',
				'action'     => __( 'Pro グリッド充電スキップ（目標SOC到達済み）', 'gaming-hub' ),
				'mode'       => 'idle',
				'active'     => false,
				'actual'     => $actuals['pro_charge']['label'] ?? null,
				'has_actual' => ! empty( $actuals['pro_charge']['has'] ),
			);
		} else {
			$rows[] = array(
				'time'       => '13:30–15:00',
				'action'     => __( 'Pro グリッド充電なし（価格スプレッド不足）', 'gaming-hub' ),
				'mode'       => 'idle',
				'active'     => false,
				'actual'     => $actuals['pro_charge']['label'] ?? null,
				'has_actual' => ! empty( $actuals['pro_charge']['has'] ),
			);
		}

		$rows[] = array(
			'time'       => $hold_label,
			'action'     => sprintf(
				/* translators: %d: SOC offset percent */
				__( 'Pro バイパス待機（予備残量 = 現在SOC − %d%% · 残量温存）', 'gaming-hub' ),
				$hold_off
			),
			'mode'       => 'bypass',
			'active'     => true,
			'actual'     => $actuals['pro_bypass']['label'] ?? null,
			'has_actual' => ! empty( $actuals['pro_bypass']['has'] ),
		);
	}

	if ( $delta_on ) {
		if ( $delta_will ) {
			$delta_time = ! empty( $delta_pick['windows'] )
				? implode( ' · ', array_map( 'strval', $delta_pick['windows'] ) )
				: implode(
					' · ',
					array_map(
						static function ( $h ) {
							return sprintf( '%02d:00', (int) $h );
						},
						$delta_hours
					)
				);
			$delta_action = sprintf(
				/* translators: 1: AC watts, 2: deficit kWh, 3: avg yen */
				__( 'Delta 最安3時間充電（予備残量 100%% · AC %1$d W · 不足 %2$s kWh · 平均 %3$s 円）', 'gaming-hub' ),
				$delta_w,
				number_format_i18n( (float) ( $delta_pick['deficit_kwh'] ?? 0 ), 1 ),
				null !== ( $delta_pick['avg_yen'] ?? null )
					? number_format_i18n( (float) $delta_pick['avg_yen'], 1 )
					: '—'
			);
			$rows[] = array(
				'time'       => $delta_time,
				'action'     => $delta_action,
				'mode'       => 'charge',
				'active'     => true,
				'actual'     => $actuals['delta_charge']['label'] ?? null,
				'has_actual' => ! empty( $actuals['delta_charge']['has'] ),
			);
		} else {
			$rows[] = array(
				'time'       => '—',
				'action'     => $charge_day
					? __( 'Delta 充電なし（SOC到達済み・最安3時間枠は終了）', 'gaming-hub' )
					: __( 'Delta 充電なし（価格スプレッド不足）', 'gaming-hub' ),
				'mode'       => 'idle',
				'active'     => false,
				'actual'     => $actuals['delta_charge']['label'] ?? null,
				'has_actual' => ! empty( $actuals['delta_charge']['has'] ),
			);
		}
	}

	$rows[] = array(
		'time'       => '15:00–22:00',
		'action'     => $delta_on
			? __( 'ピーク放電（Pro/1500 予備残量 5%）', 'gaming-hub' )
			: __( 'ピーク放電（パック優先・グリッド充電オフ）', 'gaming-hub' ),
		'mode'       => 'use',
		'active'     => true,
		'actual'     => $actuals['peak']['label'] ?? null,
		'has_actual' => ! empty( $actuals['peak']['has'] ),
	);
	if ( $delta_on ) {
		$rows[] = array(
			'time'       => 'その他',
			'action'     => __( 'Delta 1500 待機（予備残量 5% · グリッド充電オフ）', 'gaming-hub' ),
			'mode'       => 'idle',
			'active'     => true,
			'actual'     => $actuals['standby']['label'] ?? null,
			'has_actual' => ! empty( $actuals['standby']['has'] ),
		);
	}

	$window_bits = array();
	if ( $pro_will ) {
		$window_bits[] = 'Pro 13:30';
	}
	if ( $delta_will && ! empty( $delta_pick['windows'] ) ) {
		$window_bits[] = 'Delta ' . implode( ', ', array_map( 'strval', $delta_pick['windows'] ) );
	} elseif ( $delta_will ) {
		$window_bits[] = 'Delta';
	}
	$window_label = $window_bits ? implode( ' · ', $window_bits ) : __( '充電枠なし', 'gaming-hub' );

	$summary_bits = array( 'self-powered' );
	if ( $pro_on ) {
		$summary_bits[] = $charge_day
			? sprintf(
				/* translators: %s: spread yen */
				__( 'Pro charge day（スプレッド %s 円）', 'gaming-hub' ),
				number_format_i18n( (float) $spread_info['spread'], 1 )
			)
			: sprintf(
				/* translators: %s: spread yen */
				__( 'Pro 非charge day（スプレッド %s 円）', 'gaming-hub' ),
				number_format_i18n( (float) $spread_info['spread'], 1 )
			);
	}
	if ( $delta_on ) {
		$summary_bits[] = $delta_will
			? sprintf(
				/* translators: 1: windows, 2: deficit kWh */
				__( 'Delta 最安3時間充電 %1$s（不足 %2$s kWh）', 'gaming-hub' ),
				! empty( $delta_pick['windows'] ) ? implode( ', ', array_map( 'strval', $delta_pick['windows'] ) ) : '—',
				number_format_i18n( (float) ( $delta_pick['deficit_kwh'] ?? 0 ), 1 )
			)
			: __( 'Delta 充電なし', 'gaming-hub' );
	}
	$summary = implode( ' · ', $summary_bits );

	return array(
		'enabled'            => true,
		'pro'                => $pro_on,
		'delta'              => $delta_on,
		'charge_day'         => $charge_day,
		'will_charge'        => $will_charge,
		'pro_will_charge'    => $pro_will,
		'delta_will_charge'  => $delta_will,
		'day_min'            => $spread_info['day_min'],
		'peak_max'           => $spread_info['peak_max'],
		'spread'             => $spread_info['spread'],
		'charge_hour'        => 14,
		'charge_start'       => '13:30',
		'delta_charge_hours' => $delta_hours,
		'delta_windows'      => $delta_pick['windows'] ?? array(),
		'delta_deficit_kwh'  => $delta_pick['deficit_kwh'] ?? 0,
		'delta_avg_yen'      => $delta_pick['avg_yen'] ?? null,
		'pro_reserve'        => $charge_r,
		'idle_reserve'       => $idle_r,
		'hold_offset'        => $hold_off,
		'pro_bypass_hours'   => $pro_bypass_hours,
		'pro_watts'          => $charge_w,
		'delta_watts'        => $delta_w,
		'charge_w'           => $charge_w,
		'window_label'       => $window_label,
		'summary'            => $summary,
		'rows'               => $rows,
		'hours'              => $hours,
	);
}

/**
 * Hold reserve: max(5, SOC − 2) %. Keeps the pack (and follows PV charging) so
 * loads fall back to grid without trickle-charging the pack.
 *
 * @param float $soc Live Pro SOC %.
 */
function gaming_hub_ecoflow_selfpowered_hold_reserve( $soc ) {
	$off = defined( 'GAMING_HUB_ECOFLOW_SELFPOWERED_HOLD_OFFSET' )
		? (int) GAMING_HUB_ECOFLOW_SELFPOWERED_HOLD_OFFSET
		: 2;
	$idle = (int) GAMING_HUB_ECOFLOW_SELFPOWERED_IDLE_RESERVE;

	return max( $idle, min( 100, (int) floor( (float) $soc - $off ) ) );
}

/**
 * Decide target backup reserve for the current local time + SOC.
 *
 * A) 13:30–14:59 — charge day (spread ≥ 15 ¥) and SOC < 80% → reserve 80% + AC 1000 W;
 *    otherwise hold at max(5, SOC − 2).
 * B) 15:00–21:59 — reserve 5% (discharge to the floor).
 * C) 22:00–13:29 — hold at max(5, SOC − 2) (bypass, keep pack).
 *
 * @param int         $hour       0–23 Asia/Tokyo.
 * @param float       $soc        Live Pro SOC %.
 * @param bool        $charge_day Price-spread charge day.
 * @param string|null $plan_date  Unused (kept for call-site compatibility).
 * @param int         $minute     0–59.
 * @return array{reserve: int, watts: int, phase: string, reason: string}
 */
function gaming_hub_ecoflow_selfpowered_decide_reserve( $hour, $soc, $charge_day, $plan_date = null, $minute = 0 ) {
	unset( $plan_date );
	$idle   = (int) GAMING_HUB_ECOFLOW_SELFPOWERED_IDLE_RESERVE;
	$charge = (int) GAMING_HUB_ECOFLOW_SELFPOWERED_CHARGE_RESERVE;
	$watts  = defined( 'GAMING_HUB_ECOFLOW_PLAN_CHARGE_W' ) ? (int) GAMING_HUB_ECOFLOW_PLAN_CHARGE_W : 1000;
	$idle_w = defined( 'GAMING_HUB_ECOFLOW_PLAN_IDLE_W' ) ? (int) GAMING_HUB_ECOFLOW_PLAN_IDLE_W : 0;
	$hour   = (int) $hour;
	$mins   = ( $hour * 60 ) + max( 0, min( 59, (int) $minute ) );
	$hold   = gaming_hub_ecoflow_selfpowered_hold_reserve( $soc );

	$topup_start = (int) GAMING_HUB_ECOFLOW_SELFPOWERED_TOPUP_START_MIN;
	$topup_end   = (int) GAMING_HUB_ECOFLOW_SELFPOWERED_TOPUP_END_MIN;

	// A) 13:30–14:59 — AC top-up window.
	if ( $mins >= $topup_start && $mins < $topup_end ) {
		if ( $charge_day && (float) $soc < (float) $charge ) {
			return array(
				'reserve' => $charge,
				'watts'   => $watts,
				'phase'   => 'ac_topup',
				'reason'  => sprintf(
					'Charge day (spread ok) and SOC %.1f%% < %d%% → reserve %d%% · AC %d W (grid top-up).',
					$soc,
					$charge,
					$charge,
					$watts
				),
			);
		}

		return array(
			'reserve' => $hold,
			'watts'   => $idle_w,
			'phase'   => 'ac_topup_skip',
			'reason'  => $charge_day
				? sprintf( 'SOC %.1f%% already ≥ %d%% → hold reserve %d%% (SOC−2) · AC idle.', $soc, $charge, $hold )
				: sprintf( 'Spread below threshold → hold reserve %d%% (SOC−2) · AC idle.', $hold ),
		);
	}

	// B) 15:00–21:59 — peak discharge.
	if ( $hour >= 15 && $hour <= 21 ) {
		return array(
			'reserve' => $idle,
			'watts'   => $idle_w,
			'phase'   => 'peak_discharge',
			'reason'  => sprintf( 'Peak window → reserve %d%% · AC idle (prefer pack until floor).', $idle ),
		);
	}

	// C) 22:00–13:29 — bypass / keep remaining charge.
	return array(
		'reserve' => $hold,
		'watts'   => $idle_w,
		'phase'   => 'hold_soc',
		'reason'  => sprintf( 'Bypass standby → hold reserve %d%% (SOC %.1f%% − 2) · AC idle.', $hold, $soc ),
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

	$decision = gaming_hub_ecoflow_selfpowered_decide_reserve(
		$hour,
		(float) $soc,
		! empty( $prices['charge_day'] ),
		$now->format( 'Y-m-d' ),
		(int) $now->format( 'i' )
	);
	$reserve  = (int) $decision['reserve'];
	$watts    = isset( $decision['watts'] ) ? (int) $decision['watts'] : ( defined( 'GAMING_HUB_ECOFLOW_PLAN_IDLE_W' ) ? (int) GAMING_HUB_ECOFLOW_PLAN_IDLE_W : 0 );
	$cache    = gaming_hub_ecoflow_selfpowered_load_cache();
	$last     = isset( $cache['reserve'] ) ? (int) $cache['reserve'] : null;
	$last_w   = isset( $cache['watts'] ) ? (int) $cache['watts'] : null;
	$skipped  = ( null !== $last && $last === $reserve && null !== $last_w && $last_w === $watts );
	$sent     = false;
	$error    = '';

	if ( ! $skipped && ! $dry_run ) {
		$put = gaming_hub_ecoflow_selfpowered_put_reserve( $reserve );
		if ( is_wp_error( $put ) ) {
			$error = $put->get_error_message();
		} else {
			if ( function_exists( 'gaming_hub_ecoflow_set_pro_charge_watts' ) ) {
				$chg = gaming_hub_ecoflow_set_pro_charge_watts( $watts );
				if ( is_wp_error( $chg ) ) {
					$error = $chg->get_error_message();
				}
			}
			if ( '' === $error ) {
				$sent  = true;
				$cache = array(
					'reserve'    => $reserve,
					'watts'      => $watts,
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
		}
	} elseif ( $skipped && ! $dry_run ) {
		$cache['skipped_at'] = $now->format( 'c' );
		$cache['soc']        = round( (float) $soc, 2 );
		$cache['watts']      = $watts;
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
		'watts'      => $watts,
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
