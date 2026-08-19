<?php
/**
 * EcoFlow daily energy log: integrate live watts into kWh and calendar savings.
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_ECOFLOW_ENERGY_STATE', 'gaming_hub_ecoflow_energy_state' );
define( 'GAMING_HUB_ECOFLOW_ENERGY_LOCK', 'gaming_hub_ecoflow_energy_lock' );
define( 'GAMING_HUB_ECOFLOW_ENERGY_CRON', 'gaming_hub_ecoflow_sample_energy' );
define( 'GAMING_HUB_ECOFLOW_ENERGY_MAX_DT', 15 * MINUTE_IN_SECONDS );
define( 'GAMING_HUB_ECOFLOW_ENERGY_MIN_DT', 8 );
define( 'GAMING_HUB_ECOFLOW_ENERGY_ORIGIN_DEFAULT', 'https://shinichiy-gaming-hub.com' );
define( 'GAMING_HUB_ECOFLOW_ENERGY_ORIGIN_TTL', 8 );

/**
 * Whether this WordPress instance is a local / LAN copy (not production).
 */
function gaming_hub_ecoflow_energy_is_local_site() {
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	if ( '' === $host ) {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$host = preg_replace( '/:\\d+$/', '', $host );
	}

	if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
		return true;
	}

	return (bool) preg_match( '/^(192\\.168\\.|10\\.|172\\.(1[6-9]|2[0-9]|3[0-1])\\.)/', $host );
}

/**
 * Production origin for shared today totals, or empty when this server is canonical.
 */
function gaming_hub_ecoflow_energy_origin_base() {
	$origin = getenv( 'GAMING_HUB_ENERGY_ORIGIN' );
	if ( ! is_string( $origin ) ) {
		$origin = '';
	}
	$origin = untrailingslashit( trim( $origin ) );

	if ( '' === $origin && gaming_hub_ecoflow_energy_is_local_site() ) {
		$origin = untrailingslashit( GAMING_HUB_ECOFLOW_ENERGY_ORIGIN_DEFAULT );
	}

	if ( '' === $origin ) {
		return '';
	}

	$home_host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$origin_host = (string) wp_parse_url( $origin, PHP_URL_HOST );
	if ( $home_host && $origin_host && 0 === strcasecmp( $home_host, $origin_host ) ) {
		return '';
	}

	return $origin;
}

/**
 * Fetch today's yen / solar display payload from the canonical server.
 *
 * @return array<string, mixed>|null
 */
function gaming_hub_ecoflow_energy_fetch_origin_today() {
	static $done = false;
	static $memo = null;

	if ( $done ) {
		return $memo;
	}
	$done = true;

	if ( ! empty( $GLOBALS['gaming_hub_energy_skip_origin'] ) ) {
		return null;
	}

	$base = gaming_hub_ecoflow_energy_origin_base();
	if ( '' === $base ) {
		return null;
	}

	$cached = get_transient( 'gaming_hub_ecoflow_today_origin' );
	if ( is_array( $cached ) && ( $cached['date'] ?? '' ) === wp_date( 'Y-m-d' ) ) {
		$memo = $cached;
		return $cached;
	}

	$url = $base . '/wp-json/gaming-hub/v1/ecoflow/today';
	$res = wp_remote_get(
		$url,
		array(
			'timeout' => 8,
			'headers' => array( 'Accept' => 'application/json' ),
		)
	);
	if ( is_wp_error( $res ) ) {
		return null;
	}

	$code = (int) wp_remote_retrieve_response_code( $res );
	$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( 200 !== $code || ! is_array( $body ) || empty( $body['success'] ) ) {
		return null;
	}

	$payload = array(
		'date'  => (string) ( $body['date'] ?? wp_date( 'Y-m-d' ) ),
		'yen'   => isset( $body['yen'] ) && is_array( $body['yen'] ) ? $body['yen'] : array(),
		'solar' => isset( $body['solar'] ) && is_array( $body['solar'] ) ? $body['solar'] : array(),
		'usage' => isset( $body['usage'] ) && is_array( $body['usage'] ) ? $body['usage'] : array(),
	);
	set_transient( 'gaming_hub_ecoflow_today_origin', $payload, GAMING_HUB_ECOFLOW_ENERGY_ORIGIN_TTL );
	$memo = $payload;

	return $payload;
}

/**
 * Overlay canonical display fields onto a locally computed today payload.
 *
 * @param array<string, mixed>      $local  Local computation.
 * @param array<string, mixed>|null $remote Remote slice (yen or solar).
 * @param array<int, string>        $keys   Display keys to copy.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_energy_apply_origin_display( array $local, $remote, array $keys ) {
	if ( ! is_array( $remote ) || empty( $remote ) ) {
		return $local;
	}

	foreach ( $keys as $key ) {
		if ( isset( $remote[ $key ] ) && is_numeric( $remote[ $key ] ) ) {
			$local[ $key ] = (int) round( (float) $remote[ $key ] );
		}
	}

	return $local;
}

/**
 * Option key for one month of daily totals.
 *
 * @param string $ym Y-m.
 */
function gaming_hub_ecoflow_energy_option_key( $ym ) {
	return 'gaming_hub_ecoflow_energy_v1_' . $ym;
}

/**
 * Site-level watts: Pro 3 + Delta 1500 combined.
 *
 * Generation = Pro HV + 1500 LV.
 * Input     = Pro powInSumW + 1500 input (LV when MQTT is empty).
 * Output    = Pro powOutSumW + UPS / 1500 AC out.
 *
 * @param array<string, mixed> $status Normalized device status.
 * @return array{input: float, output: float, solar: float, ac_in: float, hv: float, lv: float}
 */
function gaming_hub_ecoflow_combined_site_watts( array $status ) {
	$pro_in  = max( 0, (float) ( $status['input_total'] ?? 0 ) );
	$pro_out = max( 0, (float) ( $status['output_total'] ?? 0 ) );
	$pro_ac  = max( 0, (float) ( $status['ac_in'] ?? 0 ) );
	$hv      = max( 0, (float) ( $status['hv_in'] ?? 0 ) );

	$secondary = ( isset( $status['secondary'] ) && is_array( $status['secondary'] ) )
		? $status['secondary']
		: array();

	$lv = max( 0, (float) ( $secondary['solar_in'] ?? $status['solar_in'] ?? 0 ) );
	$d_ac = max( 0, (float) ( $secondary['ac_in'] ?? 0 ) );

	$d_in = max( 0, (float) ( $secondary['input_total'] ?? 0 ) );
	$d_in = max( $d_in, $lv + $d_ac );

	$d_out = 0.0;
	if ( isset( $status['ups_plug']['watts'] ) && is_numeric( $status['ups_plug']['watts'] ) ) {
		$d_out = max( 0, (float) $status['ups_plug']['watts'] );
	} else {
		$d_out = max(
			0,
			(float) ( $secondary['output_total'] ?? 0 ),
			(float) ( $secondary['ac_out'] ?? 0 )
		);
	}

	return array(
		'input'  => $pro_in + $d_in,
		'output' => $pro_out + $d_out,
		'solar'  => $hv + $lv,
		'ac_in'  => $pro_ac + $d_ac,
		'hv'     => $hv,
		'lv'     => $lv,
	);
}

/**
 * Watts used for bill savings: Pro room + UPS AC out (credit), 1500 and Pro grid AC in (debit).
 *
 * @param array<string, mixed> $status Normalized device status.
 * @return array{room_out: float, ups_out: float, delta_ac_in: float|null, pro_ac_in: float}
 */
function gaming_hub_ecoflow_savings_flow_watts( array $status ) {
	$room_out = max( 0, (float) ( $status['ac_out'] ?? 0 ) );
	$pro_ac   = function_exists( 'gaming_hub_ecoflow_pro_grid_live_watts' )
		? (float) gaming_hub_ecoflow_pro_grid_live_watts( $status )
		: max( 0, (float) ( $status['ac_in'] ?? 0 ) );

	$secondary = ( isset( $status['secondary'] ) && is_array( $status['secondary'] ) )
		? $status['secondary']
		: array();

	$delta_ac_in = null;
	$sn          = (string) ( $secondary['device_sn'] ?? '' );
	$live        = '' !== $sn
		&& function_exists( 'gaming_hub_ecoflow_bridge_is_live' )
		&& gaming_hub_ecoflow_bridge_is_live( $sn );

	if ( $live && isset( $secondary['ac_in'] ) && is_numeric( $secondary['ac_in'] ) ) {
		$delta_ac_in = max( 0, (float) $secondary['ac_in'] );
	}

	$ups_out = 0.0;
	$ups_src = function_exists( 'gaming_hub_ecoflow_ups_source' )
		? gaming_hub_ecoflow_ups_source( $status )
		: '';
	if ( in_array( $ups_src, array( 'ecoflow', 'switchbot' ), true ) && function_exists( 'gaming_hub_ecoflow_ups_watts' ) ) {
		$ups_out = max( 0, (float) gaming_hub_ecoflow_ups_watts( $status, 0 ) );
	}

	return array(
		'room_out'    => $room_out,
		'ups_out'     => $ups_out,
		'delta_ac_in' => $delta_ac_in,
		'pro_ac_in'   => max( 0, $pro_ac ),
	);
}

/**
 * Sample live EcoFlow watts into today's kWh buckets.
 *
 * @param array<string, mixed> $status Normalized device status.
 */
function gaming_hub_ecoflow_energy_sample( array $status ) {
	if ( get_transient( GAMING_HUB_ECOFLOW_ENERGY_LOCK ) ) {
		return;
	}
	set_transient( GAMING_HUB_ECOFLOW_ENERGY_LOCK, 1, 2 );

	$now_ts = time();
	$date   = wp_date( 'Y-m-d' );
	$hour   = (int) wp_date( 'G' );
	$state  = get_transient( GAMING_HUB_ECOFLOW_ENERGY_STATE );
	$state  = is_array( $state ) ? $state : array();

	$site     = gaming_hub_ecoflow_combined_site_watts( $status );
	$savings  = gaming_hub_ecoflow_savings_flow_watts( $status );
	$input_w  = $site['input'];
	$output_w = $site['output'];
	$solar_w  = $site['solar'];
	$hv_w     = $site['hv'];
	$lv_w     = $site['lv'];
	$ac_in_w  = $site['ac_in'];
	$room_w   = $savings['room_out'];
	$ups_w    = (float) ( $savings['ups_out'] ?? 0 );
	$d_ac_w   = $savings['delta_ac_in'];
	$p_ac_w   = (float) ( $savings['pro_ac_in'] ?? 0 );
	$soc      = isset( $status['battery_percent'] ) && null !== $status['battery_percent']
		? max( 0, min( 100, (float) $status['battery_percent'] ) )
		: null;
	$delta_soc = function_exists( 'gaming_hub_ecoflow_plan_delta_pack' )
		? ( gaming_hub_ecoflow_plan_delta_pack( $status )['soc'] ?? null )
		: null;

	$last_ts = isset( $state['ts'] ) ? (int) $state['ts'] : 0;
	$dt      = $last_ts > 0 ? max( 0, $now_ts - $last_ts ) : 0;
	$dt      = min( GAMING_HUB_ECOFLOW_ENERGY_MAX_DT, $dt );

	if ( $last_ts > 0 && $dt < GAMING_HUB_ECOFLOW_ENERGY_MIN_DT ) {
		return;
	}

	$add = array(
		'input_wh'       => 0.0,
		'output_wh'      => 0.0,
		'solar_wh'       => 0.0,
		'ac_in_wh'       => 0.0,
		'room_out_wh'    => 0.0,
		'ups_out_wh'     => 0.0,
		'delta_ac_in_wh' => 0.0,
		'pro_ac_in_wh'   => 0.0,
		'hv_wh'          => 0.0,
		'lv_wh'          => 0.0,
	);
	if ( $dt > 0 ) {
		$hours = $dt / 3600.0;
		$add['input_wh']    = ( ( (float) ( $state['input_w'] ?? $input_w ) + $input_w ) / 2 ) * $hours;
		$add['output_wh']   = ( ( (float) ( $state['output_w'] ?? $output_w ) + $output_w ) / 2 ) * $hours;
		$add['solar_wh']    = ( ( (float) ( $state['solar_w'] ?? $solar_w ) + $solar_w ) / 2 ) * $hours;
		$add['hv_wh']       = ( ( (float) ( $state['hv_w'] ?? $hv_w ) + $hv_w ) / 2 ) * $hours;
		$add['lv_wh']       = ( ( (float) ( $state['lv_w'] ?? $lv_w ) + $lv_w ) / 2 ) * $hours;
		$add['ac_in_wh']    = ( ( (float) ( $state['ac_in_w'] ?? $ac_in_w ) + $ac_in_w ) / 2 ) * $hours;
		$add['room_out_wh']  = ( ( (float) ( $state['room_out_w'] ?? $room_w ) + $room_w ) / 2 ) * $hours;
		$add['ups_out_wh']   = ( ( (float) ( $state['ups_out_w'] ?? $ups_w ) + $ups_w ) / 2 ) * $hours;
		$add['pro_ac_in_wh'] = ( ( (float) ( $state['pro_ac_in_w'] ?? $p_ac_w ) + $p_ac_w ) / 2 ) * $hours;

		$last_d_ac = array_key_exists( 'delta_ac_in_w', $state ) ? $state['delta_ac_in_w'] : null;
		if ( null !== $d_ac_w && null !== $last_d_ac && is_numeric( $last_d_ac ) ) {
			$add['delta_ac_in_wh'] = ( ( (float) $last_d_ac + $d_ac_w ) / 2 ) * $hours;
		} elseif ( null !== $d_ac_w ) {
			$add['delta_ac_in_wh'] = $d_ac_w * $hours;
		}
	}
	if ( null !== $soc ) {
		$add['soc'] = $soc;
	}
	if ( null !== $delta_soc ) {
		$add['delta_soc'] = $delta_soc;
	}

	if ( $dt > 0 || null !== $soc || null !== $delta_soc ) {
		gaming_hub_ecoflow_energy_add( $date, $hour, $add );
	}

	set_transient(
		GAMING_HUB_ECOFLOW_ENERGY_STATE,
		array(
			'ts'            => $now_ts,
			'date'          => $date,
			'hour'          => $hour,
			'input_w'       => $input_w,
			'output_w'      => $output_w,
			'solar_w'       => $solar_w,
			'hv_w'          => $hv_w,
			'lv_w'          => $lv_w,
			'ac_in_w'       => $ac_in_w,
			'room_out_w'    => $room_w,
			'ups_out_w'     => $ups_w,
			'delta_ac_in_w' => $d_ac_w,
			'pro_ac_in_w'   => $p_ac_w,
			'soc'           => $soc,
			'delta_soc'     => $delta_soc,
		),
		DAY_IN_SECONDS
	);
}

/**
 * Add watt-hours into a day/hour bucket and refresh savings.
 *
 * @param string               $date Y-m-d.
 * @param int                  $hour 0–23.
 * @param array<string, float> $add  Wh deltas.
 */
function gaming_hub_ecoflow_energy_add( $date, $hour, array $add ) {
	$ym   = substr( $date, 0, 7 );
	$key  = gaming_hub_ecoflow_energy_option_key( $ym );
	$days = get_option( $key, array() );
	$days = is_array( $days ) ? $days : array();

	if ( ! isset( $days[ $date ] ) || ! is_array( $days[ $date ] ) ) {
		$days[ $date ] = gaming_hub_ecoflow_energy_empty_day();
	}

	$day = $days[ $date ];
	$h   = max( 0, min( 23, (int) $hour ) );
	if ( ! isset( $day['hours'][ $h ] ) || ! is_array( $day['hours'][ $h ] ) ) {
		$day['hours'][ $h ] = array(
			'input_wh'       => 0.0,
			'output_wh'      => 0.0,
			'solar_wh'       => 0.0,
			'ac_in_wh'       => 0.0,
			'room_out_wh'    => 0.0,
			'ups_out_wh'     => 0.0,
			'delta_ac_in_wh' => 0.0,
			'pro_ac_in_wh'   => 0.0,
			'hv_wh'          => 0.0,
			'lv_wh'          => 0.0,
			'soc'            => null,
			'delta_soc'      => null,
			'yen'            => gaming_hub_ecoflow_energy_hour_price( $h ),
		);
	}

	foreach ( array( 'input_wh', 'output_wh', 'solar_wh', 'ac_in_wh', 'room_out_wh', 'ups_out_wh', 'delta_ac_in_wh', 'pro_ac_in_wh', 'hv_wh', 'lv_wh' ) as $field ) {
		$delta                        = max( 0, (float) ( $add[ $field ] ?? 0 ) );
		$day['hours'][ $h ][ $field ] = (float) ( $day['hours'][ $h ][ $field ] ?? 0 ) + $delta;
		$day[ $field ]                = (float) ( $day[ $field ] ?? 0 ) + $delta;
	}

	if ( empty( $day['hours'][ $h ]['yen'] ) ) {
		$day['hours'][ $h ]['yen'] = gaming_hub_ecoflow_energy_hour_price( $h );
	}

	if ( isset( $add['soc'] ) && is_numeric( $add['soc'] ) ) {
		$day['hours'][ $h ]['soc'] = round( max( 0, min( 100, (float) $add['soc'] ) ), 1 );
	}
	if ( isset( $add['delta_soc'] ) && is_numeric( $add['delta_soc'] ) ) {
		$day['hours'][ $h ]['delta_soc'] = round( max( 0, min( 100, (float) $add['delta_soc'] ) ), 1 );
	}

	$day['samples']    = (int) ( $day['samples'] ?? 0 ) + 1;
	$day['updated_at'] = wp_date( 'c' );
	$day['saved_yen']  = gaming_hub_ecoflow_energy_saved_yen( $day );
	$days[ $date ]     = $day;

	update_option( $key, $days, false );
}

/**
 * Logged hour buckets for a Y-m-d date.
 *
 * @param string $date Y-m-d.
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_ecoflow_energy_hours_for_date( $date ) {
	$date  = (string) $date;
	$log   = gaming_hub_ecoflow_energy_month_days( substr( $date, 0, 7 ) );
	$hours = $log[ $date ]['hours'] ?? array();

	return is_array( $hours ) ? $hours : array();
}

/**
 * Today's logged hour buckets.
 *
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_ecoflow_energy_today_hours() {
	return gaming_hub_ecoflow_energy_hours_for_date( wp_date( 'Y-m-d' ) );
}

/**
 * Today's measured solar watts by hour (Wh in a full hour ≈ W).
 *
 * @return array<int, int|null>
 */
function gaming_hub_ecoflow_energy_today_solar_hours() {
	$hours = gaming_hub_ecoflow_energy_today_hours();
	$out   = array_fill( 0, 24, null );

	for ( $h = 0; $h < 24; $h++ ) {
		if ( ! isset( $hours[ $h ] ) || ! is_array( $hours[ $h ] ) ) {
			continue;
		}
		$out[ $h ] = (int) round( max( 0, (float) ( $hours[ $h ]['solar_wh'] ?? 0 ) ) );
	}

	return $out;
}

/**
 * Last sampled Pro SOC % by hour for a date.
 *
 * @param string|null $date Y-m-d, default today.
 * @return array<int, float|null>
 */
function gaming_hub_ecoflow_energy_soc_hours_for_date( $date = null ) {
	$hours = gaming_hub_ecoflow_energy_hours_for_date( $date ? (string) $date : wp_date( 'Y-m-d' ) );
	$out   = array_fill( 0, 24, null );

	for ( $h = 0; $h < 24; $h++ ) {
		if ( ! isset( $hours[ $h ]['soc'] ) || ! is_numeric( $hours[ $h ]['soc'] ) ) {
			continue;
		}
		$out[ $h ] = round( max( 0, min( 100, (float) $hours[ $h ]['soc'] ) ), 1 );
	}

	return $out;
}

/**
 * Today's last sampled Pro SOC % by hour.
 *
 * @return array<int, float|null>
 */
function gaming_hub_ecoflow_energy_today_soc_hours() {
	return gaming_hub_ecoflow_energy_soc_hours_for_date();
}

/**
 * Last sampled 1500 SOC % by hour for a date.
 *
 * @param string|null $date Y-m-d, default today.
 * @return array<int, float|null>
 */
function gaming_hub_ecoflow_energy_delta_soc_hours_for_date( $date = null ) {
	$hours = gaming_hub_ecoflow_energy_hours_for_date( $date ? (string) $date : wp_date( 'Y-m-d' ) );
	$out   = array_fill( 0, 24, null );

	for ( $h = 0; $h < 24; $h++ ) {
		if ( ! isset( $hours[ $h ]['delta_soc'] ) || ! is_numeric( $hours[ $h ]['delta_soc'] ) ) {
			continue;
		}
		$out[ $h ] = round( max( 0, min( 100, (float) $hours[ $h ]['delta_soc'] ) ), 1 );
	}

	return $out;
}

/**
 * Today's last sampled 1500 (main + Extra) SOC % by hour.
 *
 * @return array<int, float|null>
 */
function gaming_hub_ecoflow_energy_today_delta_soc_hours() {
	return gaming_hub_ecoflow_energy_delta_soc_hours_for_date();
}

/**
 * Measured HV / LV solar watts by hour for a date.
 *
 * Falls back to splitting combined solar by Pro 800 : 1500 500.
 *
 * @param string|null $date Y-m-d, default today.
 * @return array{pro: array<int, int|null>, delta: array<int, int|null>}
 */
function gaming_hub_ecoflow_energy_split_solar_hours_for_date( $date = null ) {
	$hours   = gaming_hub_ecoflow_energy_hours_for_date( $date ? (string) $date : wp_date( 'Y-m-d' ) );
	$pro_cap = defined( 'GAMING_HUB_ECOFLOW_SOLAR_PRO_W' ) ? (int) GAMING_HUB_ECOFLOW_SOLAR_PRO_W : 800;
	$d_cap   = defined( 'GAMING_HUB_ECOFLOW_SOLAR_DELTA1500_W' ) ? (int) GAMING_HUB_ECOFLOW_SOLAR_DELTA1500_W : 500;
	$total   = max( 1, $pro_cap + $d_cap );
	$pro     = array_fill( 0, 24, null );
	$delta   = array_fill( 0, 24, null );

	for ( $h = 0; $h < 24; $h++ ) {
		if ( ! isset( $hours[ $h ] ) || ! is_array( $hours[ $h ] ) ) {
			continue;
		}
		$row = $hours[ $h ];
		$has_hv = array_key_exists( 'hv_wh', $row ) && is_numeric( $row['hv_wh'] );
		$has_lv = array_key_exists( 'lv_wh', $row ) && is_numeric( $row['lv_wh'] );
		if ( $has_hv || $has_lv ) {
			$pro[ $h ]   = $has_hv ? (int) round( max( 0, (float) $row['hv_wh'] ) ) : 0;
			$delta[ $h ] = $has_lv ? (int) round( max( 0, (float) $row['lv_wh'] ) ) : 0;
			continue;
		}
		if ( ! isset( $row['solar_wh'] ) || ! is_numeric( $row['solar_wh'] ) ) {
			continue;
		}
		$combined    = max( 0, (float) $row['solar_wh'] );
		$pro[ $h ]   = (int) round( $combined * $pro_cap / $total );
		$delta[ $h ] = (int) round( $combined - $pro[ $h ] );
	}

	return array(
		'pro'   => $pro,
		'delta' => $delta,
	);
}

/**
 * Today's measured HV / LV solar watts by hour.
 *
 * @return array{pro: array<int, int|null>, delta: array<int, int|null>}
 */
function gaming_hub_ecoflow_energy_today_split_solar_hours() {
	return gaming_hub_ecoflow_energy_split_solar_hours_for_date();
}

/**
 * Empty day record.
 *
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_energy_empty_day() {
	return array(
		'input_wh'       => 0.0,
		'output_wh'      => 0.0,
		'solar_wh'       => 0.0,
		'ac_in_wh'       => 0.0,
		'room_out_wh'    => 0.0,
		'ups_out_wh'     => 0.0,
		'delta_ac_in_wh' => 0.0,
		'pro_ac_in_wh'   => 0.0,
		'hv_wh'          => 0.0,
		'lv_wh'          => 0.0,
		'saved_yen'      => 0.0,
		'samples'   => 0,
		'hours'     => array(),
		'updated_at'=> '',
	);
}

/**
 * Smart Time ONE yen/kWh for the current hour (cached with LOOOP forecast).
 *
 * @param int $hour 0–23.
 */
function gaming_hub_ecoflow_energy_hour_price( $hour ) {
	$fallback = 40.0;
	if ( ! function_exists( 'gaming_hub_looop_hourly_price_map_today' ) ) {
		return $fallback;
	}

	$price = gaming_hub_looop_hourly_price_map_today();
	if ( is_wp_error( $price ) ) {
		return $fallback;
	}

	$map = is_array( $price['map'] ?? null ) ? $price['map'] : array();
	if ( isset( $map[ (int) $hour ] ) ) {
		return (float) $map[ (int) $hour ];
	}

	return isset( $price['fallback'] ) ? (float) $price['fallback'] : $fallback;
}

/**
 * Bill savings: (Pro room + UPS AC out) × that hour’s 買電 rate, minus 1500 and Pro grid AC in.
 *
 * Hours logged before these fields existed keep the old solar × rate formula.
 *
 * @param array<string, mixed> $day Day record.
 */
function gaming_hub_ecoflow_energy_saved_yen( array $day ) {
	$saved = 0.0;
	$hours = is_array( $day['hours'] ?? null ) ? $day['hours'] : array();

	if ( $hours ) {
		foreach ( $hours as $row ) {
			$yen      = (float) ( $row['yen'] ?? 0 );
			$has_room = array_key_exists( 'room_out_wh', $row );
			$has_ups  = array_key_exists( 'ups_out_wh', $row );
			$has_grid = array_key_exists( 'delta_ac_in_wh', $row );
			$has_pro  = array_key_exists( 'pro_ac_in_wh', $row );

			if ( $has_room || $has_ups || $has_grid || $has_pro ) {
				$room_kwh = max( 0, (float) ( $row['room_out_wh'] ?? 0 ) ) / 1000.0;
				$ups_kwh  = max( 0, (float) ( $row['ups_out_wh'] ?? 0 ) ) / 1000.0;
				$grid_kwh = max( 0, (float) ( $row['delta_ac_in_wh'] ?? 0 ) ) / 1000.0;
				$pro_kwh  = max( 0, (float) ( $row['pro_ac_in_wh'] ?? 0 ) ) / 1000.0;
				$saved   += ( ( $room_kwh + $ups_kwh ) * $yen ) - ( $grid_kwh * $yen ) - ( $pro_kwh * $yen );
				continue;
			}

			$kwh    = max( 0, (float) ( $row['solar_wh'] ?? 0 ) ) / 1000.0;
			$saved += $kwh * $yen;
		}

		return round( $saved, 1 );
	}

	$avg = gaming_hub_ecoflow_energy_hour_price( (int) wp_date( 'G' ) );
	if ( array_key_exists( 'room_out_wh', $day ) || array_key_exists( 'ups_out_wh', $day ) || array_key_exists( 'delta_ac_in_wh', $day ) || array_key_exists( 'pro_ac_in_wh', $day ) ) {
		$room_kwh = max( 0, (float) ( $day['room_out_wh'] ?? 0 ) ) / 1000.0;
		$ups_kwh  = max( 0, (float) ( $day['ups_out_wh'] ?? 0 ) ) / 1000.0;
		$grid_kwh = max( 0, (float) ( $day['delta_ac_in_wh'] ?? 0 ) ) / 1000.0;
		$pro_kwh  = max( 0, (float) ( $day['pro_ac_in_wh'] ?? 0 ) ) / 1000.0;
		return round( ( ( $room_kwh + $ups_kwh ) * $avg ) - ( $grid_kwh * $avg ) - ( $pro_kwh * $avg ), 1 );
	}

	return round( max( 0, (float) ( $day['solar_wh'] ?? 0 ) ) / 1000.0 * $avg, 1 );
}

/**
 * Room-save, UPS-save, 1500-grid-buy, and Pro-grid-buy yen from a day's hour buckets (no live remainder).
 *
 * @param array<string, mixed> $day Day record.
 * @return array{room: float, ups: float, grid: float, pro: float}
 */
function gaming_hub_ecoflow_energy_day_yen_parts( array $day ) {
	$room  = 0.0;
	$ups   = 0.0;
	$grid  = 0.0;
	$pro   = 0.0;
	$hours = is_array( $day['hours'] ?? null ) ? $day['hours'] : array();

	foreach ( $hours as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$yen   = (float) ( $row['yen'] ?? 0 );
		$room += max( 0, (float) ( $row['room_out_wh'] ?? 0 ) ) / 1000.0 * $yen;
		$ups  += max( 0, (float) ( $row['ups_out_wh'] ?? 0 ) ) / 1000.0 * $yen;
		$grid += max( 0, (float) ( $row['delta_ac_in_wh'] ?? 0 ) ) / 1000.0 * $yen;
		$pro  += max( 0, (float) ( $row['pro_ac_in_wh'] ?? 0 ) ) / 1000.0 * $yen;
	}

	return array(
		'room' => $room,
		'ups'  => $ups,
		'grid' => $grid,
		'pro'  => $pro,
	);
}

/**
 * Today's room/UPS savings and grid import cost (1500 + Pro), including live remainder.
 *
 * @param array<string, mixed>|null $status Live status, if available.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_energy_compute_today_yen( $status = null ) {
	$date = wp_date( 'Y-m-d' );
	$log  = gaming_hub_ecoflow_energy_month_days( substr( $date, 0, 7 ) );
	$day  = isset( $log[ $date ] ) && is_array( $log[ $date ] )
		? $log[ $date ]
		: gaming_hub_ecoflow_energy_empty_day();
	$parts = gaming_hub_ecoflow_energy_day_yen_parts( $day );

	$room_w     = 0.0;
	$ups_w      = 0.0;
	$grid_w     = null;
	$pro_grid_w = 0.0;
	$sample_ts  = time();
	$state      = get_transient( GAMING_HUB_ECOFLOW_ENERGY_STATE );
	$state      = is_array( $state ) ? $state : array();

	if ( is_array( $status ) ) {
		$flow       = gaming_hub_ecoflow_savings_flow_watts( $status );
		$room_w     = (float) $flow['room_out'];
		$ups_w      = (float) ( $flow['ups_out'] ?? 0 );
		$grid_w     = $flow['delta_ac_in'];
		$pro_grid_w = (float) ( $flow['pro_ac_in'] ?? 0 );
	} elseif ( array_key_exists( 'room_out_w', $state ) ) {
		$room_w     = (float) ( $state['room_out_w'] ?? 0 );
		$ups_w      = (float) ( $state['ups_out_w'] ?? 0 );
		$grid_w     = array_key_exists( 'delta_ac_in_w', $state ) ? $state['delta_ac_in_w'] : null;
		$grid_w     = null !== $grid_w && is_numeric( $grid_w ) ? (float) $grid_w : null;
		$pro_grid_w = (float) ( $state['pro_ac_in_w'] ?? 0 );
	}

	if ( ! empty( $state['ts'] ) ) {
		$sample_ts = (int) $state['ts'];
	}

	$yen        = gaming_hub_ecoflow_energy_hour_price( (int) wp_date( 'G' ) );
	$dt         = min( GAMING_HUB_ECOFLOW_ENERGY_MAX_DT, max( 0, time() - $sample_ts ) );
	$hours      = $dt / 3600.0;
	$room_live  = ( $room_w / 1000.0 ) * $yen * $hours;
	$ups_live   = ( $ups_w / 1000.0 ) * $yen * $hours;
	$grid_live  = ( null === $grid_w ? 0.0 : ( $grid_w / 1000.0 ) * $yen * $hours );
	$pro_live   = ( $pro_grid_w / 1000.0 ) * $yen * $hours;
	$room_total = $parts['room'] + $room_live;
	$ups_total  = ( $parts['ups'] ?? 0 ) + $ups_live;
	$grid_total = $parts['grid'] + $grid_live;
	$pro_total  = ( $parts['pro'] ?? 0 ) + $pro_live;
	$buy_total  = $grid_total + $pro_total;

	return array(
		'room_yen'             => (int) round( $room_total ),
		'ups_yen'              => (int) round( $ups_total ),
		'grid_yen'             => (int) round( $grid_total ),
		'pro_grid_yen'         => (int) round( $pro_total ),
		'buy_yen'              => (int) round( $buy_total ),
		'net_yen'              => (int) round( $room_total + $ups_total - $buy_total ),
		'logged_room_yen'      => round( $parts['room'], 4 ),
		'logged_ups_yen'       => round( $parts['ups'] ?? 0, 4 ),
		'logged_grid_yen'      => round( $parts['grid'], 4 ),
		'logged_pro_grid_yen'  => round( $parts['pro'] ?? 0, 4 ),
		'room_w'               => $room_w,
		'ups_w'                => $ups_w,
		'grid_w'               => $grid_w,
		'pro_grid_w'           => $pro_grid_w,
		'yen_per_kwh'          => $yen,
		'sample_ts'            => $sample_ts,
	);
}

/**
 * Today's room/UPS savings and grid import cost (canonical display numbers).
 *
 * Local / LAN copies overlay production totals so every terminal shows the same yen.
 *
 * @param array<string, mixed>|null $status Live status, if available.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_energy_today_yen( $status = null ) {
	$local  = gaming_hub_ecoflow_energy_compute_today_yen( $status );
	$remote = gaming_hub_ecoflow_energy_fetch_origin_today();

	return gaming_hub_ecoflow_energy_apply_origin_display(
		$local,
		is_array( $remote ) ? ( $remote['yen'] ?? null ) : null,
		array( 'room_yen', 'ups_yen', 'grid_yen', 'pro_grid_yen', 'buy_yen', 'net_yen' )
	);
}

/**
 * Today's Pro HV and 1500 LV generation (Wh), including live remainder.
 *
 * @param array<string, mixed>|null $status Live status, if available.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_energy_compute_today_solar( $status = null ) {
	$date = wp_date( 'Y-m-d' );
	$log  = gaming_hub_ecoflow_energy_month_days( substr( $date, 0, 7 ) );
	$day  = isset( $log[ $date ] ) && is_array( $log[ $date ] )
		? $log[ $date ]
		: gaming_hub_ecoflow_energy_empty_day();

	$logged_pro   = max( 0.0, (float) ( $day['hv_wh'] ?? 0 ) );
	$logged_delta = max( 0.0, (float) ( $day['lv_wh'] ?? 0 ) );
	$logged_all   = max( 0.0, (float) ( $day['solar_wh'] ?? 0 ) );
	if ( $logged_pro <= 0 && $logged_delta <= 0 && $logged_all > 0 ) {
		$pro_cap = defined( 'GAMING_HUB_ECOFLOW_SOLAR_PRO_W' ) ? (int) GAMING_HUB_ECOFLOW_SOLAR_PRO_W : 800;
		$d_cap   = defined( 'GAMING_HUB_ECOFLOW_SOLAR_DELTA1500_W' ) ? (int) GAMING_HUB_ECOFLOW_SOLAR_DELTA1500_W : 500;
		$total   = max( 1, $pro_cap + $d_cap );
		$logged_pro   = $logged_all * $pro_cap / $total;
		$logged_delta = max( 0.0, $logged_all - $logged_pro );
	}

	$pro_w     = 0.0;
	$delta_w   = 0.0;
	$sample_ts = time();
	$state     = get_transient( GAMING_HUB_ECOFLOW_ENERGY_STATE );
	$state     = is_array( $state ) ? $state : array();

	if ( is_array( $status ) && function_exists( 'gaming_hub_ecoflow_combined_site_watts' ) ) {
		$site    = gaming_hub_ecoflow_combined_site_watts( $status );
		$pro_w   = max( 0.0, (float) ( $site['hv'] ?? 0 ) );
		$delta_w = max( 0.0, (float) ( $site['lv'] ?? 0 ) );
	} else {
		$pro_w   = max( 0.0, (float) ( $state['hv_w'] ?? 0 ) );
		$delta_w = max( 0.0, (float) ( $state['lv_w'] ?? 0 ) );
	}

	if ( ! empty( $state['ts'] ) ) {
		$sample_ts = (int) $state['ts'];
	}

	$dt         = min( GAMING_HUB_ECOFLOW_ENERGY_MAX_DT, max( 0, time() - $sample_ts ) );
	$hours      = $dt / 3600.0;
	$pro_total  = $logged_pro + ( $pro_w * $hours );
	$delta_total = $logged_delta + ( $delta_w * $hours );

	return array(
		'pro_wh'         => (int) round( $pro_total ),
		'delta_wh'       => (int) round( $delta_total ),
		'logged_pro_wh'  => round( $logged_pro, 4 ),
		'logged_delta_wh'=> round( $logged_delta, 4 ),
		'pro_w'          => $pro_w,
		'delta_w'        => $delta_w,
		'sample_ts'      => $sample_ts,
	);
}

/**
 * Today's Pro HV and 1500 LV generation for display (canonical on production).
 *
 * @param array<string, mixed>|null $status Live status, if available.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_energy_today_solar( $status = null ) {
	$local  = gaming_hub_ecoflow_energy_compute_today_solar( $status );
	$remote = gaming_hub_ecoflow_energy_fetch_origin_today();

	return gaming_hub_ecoflow_energy_apply_origin_display(
		$local,
		is_array( $remote ) ? ( $remote['solar'] ?? null ) : null,
		array( 'pro_wh', 'delta_wh' )
	);
}

/**
 * Today's Pro room and UPS AC-out usage (Wh), including live remainder.
 *
 * @param array<string, mixed>|null $status Live status, if available.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_energy_compute_today_usage( $status = null ) {
	$date = wp_date( 'Y-m-d' );
	$log  = gaming_hub_ecoflow_energy_month_days( substr( $date, 0, 7 ) );
	$day  = isset( $log[ $date ] ) && is_array( $log[ $date ] )
		? $log[ $date ]
		: gaming_hub_ecoflow_energy_empty_day();

	$logged_room = max( 0.0, (float) ( $day['room_out_wh'] ?? 0 ) );
	$logged_ups  = max( 0.0, (float) ( $day['ups_out_wh'] ?? 0 ) );

	$room_w    = 0.0;
	$ups_w     = 0.0;
	$sample_ts = time();
	$state     = get_transient( GAMING_HUB_ECOFLOW_ENERGY_STATE );
	$state     = is_array( $state ) ? $state : array();

	if ( is_array( $status ) && function_exists( 'gaming_hub_ecoflow_savings_flow_watts' ) ) {
		$flow   = gaming_hub_ecoflow_savings_flow_watts( $status );
		$room_w = max( 0.0, (float) ( $flow['room_out'] ?? 0 ) );
		$ups_w  = max( 0.0, (float) ( $flow['ups_out'] ?? 0 ) );
	} else {
		$room_w = max( 0.0, (float) ( $state['room_out_w'] ?? 0 ) );
		$ups_w  = max( 0.0, (float) ( $state['ups_out_w'] ?? 0 ) );
	}

	if ( ! empty( $state['ts'] ) ) {
		$sample_ts = (int) $state['ts'];
	}

	$dt        = min( GAMING_HUB_ECOFLOW_ENERGY_MAX_DT, max( 0, time() - $sample_ts ) );
	$hours     = $dt / 3600.0;
	$room_total = $logged_room + ( $room_w * $hours );
	$ups_total  = $logged_ups + ( $ups_w * $hours );

	return array(
		'room_wh'        => (int) round( $room_total ),
		'ups_wh'         => (int) round( $ups_total ),
		'logged_room_wh' => round( $logged_room, 4 ),
		'logged_ups_wh'  => round( $logged_ups, 4 ),
		'room_w'         => $room_w,
		'ups_w'          => $ups_w,
		'sample_ts'      => $sample_ts,
	);
}

/**
 * Today's AC-out usage for display (canonical on production).
 *
 * @param array<string, mixed>|null $status Live status, if available.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_energy_today_usage( $status = null ) {
	$local  = gaming_hub_ecoflow_energy_compute_today_usage( $status );
	$remote = gaming_hub_ecoflow_energy_fetch_origin_today();

	return gaming_hub_ecoflow_energy_apply_origin_display(
		$local,
		is_array( $remote ) ? ( $remote['usage'] ?? null ) : null,
		array( 'room_wh', 'ups_wh' )
	);
}

/**
 * Month log from options.
 *
 * @param string $ym Y-m.
 * @return array<string, array<string, mixed>>
 */
function gaming_hub_ecoflow_energy_month_days( $ym ) {
	$days = get_option( gaming_hub_ecoflow_energy_option_key( $ym ), array() );
	return is_array( $days ) ? $days : array();
}

/**
 * Round a chart max up to 1 / 2 / 5 × 10^n.
 *
 * @param float $value Raw max.
 */
function gaming_hub_ecoflow_energy_nice_max( $value ) {
	$value = max( 0, (float) $value );
	if ( $value <= 0 ) {
		return 1.0;
	}

	$exp = pow( 10, (int) floor( log10( $value ) ) );
	$n   = $value / $exp;
	if ( $n <= 1 ) {
		$nice = 1;
	} elseif ( $n <= 2 ) {
		$nice = 2;
	} elseif ( $n <= 5 ) {
		$nice = 5;
	} else {
		$nice = 10;
	}

	return (float) ( $nice * $exp );
}

/**
 * Tick labels from a max, high to low.
 *
 * @param float $max   Raw max.
 * @param int   $steps Divisions.
 * @return array{0: float, 1: array<int, float>}
 */
function gaming_hub_ecoflow_energy_axis_ticks( $max, $steps = 4 ) {
	$top   = gaming_hub_ecoflow_energy_nice_max( $max );
	$ticks = array();
	for ( $i = 0; $i <= $steps; $i++ ) {
		$ticks[] = $top - ( $top * $i / $steps );
	}

	return array( $top, $ticks );
}

/**
 * 24h chart rows from a day's hour buckets.
 *
 * @param array<int, mixed> $hours Hour map.
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_ecoflow_energy_hour_chart_rows( $hours ) {
	$hours = is_array( $hours ) ? $hours : array();
	$rows  = array();

	for ( $h = 0; $h < 24; $h++ ) {
		$row   = isset( $hours[ $h ] ) && is_array( $hours[ $h ] ) ? $hours[ $h ] : array();
		$solar = isset( $row['solar_wh'] ) ? round( (float) $row['solar_wh'] / 1000, 3 ) : null;
		$out   = isset( $row['output_wh'] ) ? round( (float) $row['output_wh'] / 1000, 3 ) : null;
		$rows[] = array(
			'hour'       => $h,
			'solar_kwh'  => $solar,
			'output_kwh' => $out,
			'has_data'   => null !== $solar || null !== $out,
		);
	}

	return $rows;
}

/**
 * Dashboard / REST payload for one month.
 *
 * @param string                    $ym     Y-m.
 * @param array<string, mixed>|null $status Live status for NOW watts.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_energy_month_payload( $ym, $status = null ) {
	$ym    = preg_match( '/^\d{4}-\d{2}$/', (string) $ym ) ? (string) $ym : wp_date( 'Y-m' );
	$today = wp_date( 'Y-m-d' );
	$start = $ym . '-01';
	$ts    = strtotime( $start . ' 00:00:00' );
	if ( ! $ts ) {
		$ym    = wp_date( 'Y-m' );
		$start = $ym . '-01';
		$ts    = strtotime( $start . ' 00:00:00' );
	}

	$days_in_month = (int) gmdate( 't', $ts );
	$start_wday    = (int) wp_date( 'w', $ts );
	$log           = gaming_hub_ecoflow_energy_month_days( $ym );
	$cells         = array();
	$totals        = array(
		'input_kwh'  => 0.0,
		'output_kwh' => 0.0,
		'solar_kwh'  => 0.0,
		'saved_yen'  => 0.0,
	);

	for ( $d = 1; $d <= $days_in_month; $d++ ) {
		$date = $ym . '-' . sprintf( '%02d', $d );
		$row  = isset( $log[ $date ] ) && is_array( $log[ $date ] ) ? $log[ $date ] : null;
		$cell = array(
			'date'       => $date,
			'day'        => $d,
			'input_kwh'  => $row ? round( (float) $row['input_wh'] / 1000, 2 ) : null,
			'output_kwh' => $row ? round( (float) $row['output_wh'] / 1000, 2 ) : null,
			'solar_kwh'  => $row ? round( (float) $row['solar_wh'] / 1000, 2 ) : null,
			'saved_yen'  => $row ? round( (float) $row['saved_yen'], 0 ) : null,
			'has_data'   => (bool) $row,
			'is_today'   => $date === $today,
		);
		$cells[] = $cell;

		if ( $row ) {
			$totals['input_kwh']  += (float) $cell['input_kwh'];
			$totals['output_kwh'] += (float) $cell['output_kwh'];
			$totals['solar_kwh']  += (float) $cell['solar_kwh'];
			$totals['saved_yen']  += (float) $cell['saved_yen'];
		}
	}

	$max_kwh = 0.0;
	$max_yen = 0.0;
	foreach ( $cells as $cell ) {
		$max_kwh = max(
			$max_kwh,
			(float) ( $cell['solar_kwh'] ?? 0 ),
			(float) ( $cell['output_kwh'] ?? 0 )
		);
		$max_yen = max( $max_yen, abs( (float) ( $cell['saved_yen'] ?? 0 ) ) );
	}
	list( $kwh_max, $kwh_ticks ) = gaming_hub_ecoflow_energy_axis_ticks( $max_kwh );
	list( $yen_max, $yen_ticks ) = gaming_hub_ecoflow_energy_axis_ticks( $max_yen );

	$today_hours      = array();
	$today_kwh_max    = 1.0;
	$today_kwh_ticks  = array( 1, 0.75, 0.5, 0.25, 0 );
	if ( $ym === substr( $today, 0, 7 ) ) {
		$today_hours = gaming_hub_ecoflow_energy_hour_chart_rows( $log[ $today ]['hours'] ?? array() );
		$today_max   = 0.0;
		foreach ( $today_hours as $hour_row ) {
			$today_max = max(
				$today_max,
				(float) ( $hour_row['solar_kwh'] ?? 0 ),
				(float) ( $hour_row['output_kwh'] ?? 0 )
			);
		}
		list( $today_kwh_max, $today_kwh_ticks ) = gaming_hub_ecoflow_energy_axis_ticks( $today_max );
	}

	$now = array(
		'input'  => null,
		'output' => null,
		'solar'  => null,
	);
	if ( is_array( $status ) ) {
		$site          = gaming_hub_ecoflow_combined_site_watts( $status );
		$now['input']  = (int) round( $site['input'] );
		$now['output'] = (int) round( $site['output'] );
		$now['solar']  = (int) round( $site['solar'] );
	} else {
		$state = get_transient( GAMING_HUB_ECOFLOW_ENERGY_STATE );
		if ( is_array( $state ) ) {
			$now['input']  = isset( $state['input_w'] ) ? (int) $state['input_w'] : null;
			$now['output'] = isset( $state['output_w'] ) ? (int) $state['output_w'] : null;
			$now['solar']  = isset( $state['solar_w'] ) ? (int) $state['solar_w'] : null;
		}
	}

	$prev = wp_date( 'Y-m', strtotime( $start . ' -1 month' ) );
	$next = wp_date( 'Y-m', strtotime( $start . ' +1 month' ) );

	return array(
		'month'      => $ym,
		'label'      => wp_date( 'Y年n月', $ts ),
		'today'      => $today,
		'start_wday' => $start_wday,
		'days'       => $cells,
		'totals'     => array(
			'input_kwh'  => round( $totals['input_kwh'], 2 ),
			'output_kwh' => round( $totals['output_kwh'], 2 ),
			'solar_kwh'  => round( $totals['solar_kwh'], 2 ),
			'saved_yen'  => round( $totals['saved_yen'], 0 ),
		),
		'now'        => $now,
		'prev'       => $prev,
		'next'       => $next,
		'weekdays'   => array( '日', '月', '火', '水', '木', '金', '土' ),
		'kwh_max'    => $kwh_max,
		'kwh_ticks'  => $kwh_ticks,
		'yen_max'    => $yen_max,
		'yen_ticks'  => $yen_ticks,
		'today_hours'     => $today_hours,
		'today_kwh_max'   => $today_kwh_max,
		'today_kwh_ticks' => $today_kwh_ticks,
		'today_yen'       => gaming_hub_ecoflow_energy_today_yen( $status ),
		'today_solar'     => gaming_hub_ecoflow_energy_today_solar( $status ),
		'today_usage'     => gaming_hub_ecoflow_energy_today_usage( $status ),
	);
}

/**
 * Attach current-month energy onto a status payload.
 *
 * @param array<string, mixed> $status Status.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_energy_attach( array $status ) {
	$status['energy']     = gaming_hub_ecoflow_energy_month_payload( wp_date( 'Y-m' ), $status );
	$status['today_yen']  = isset( $status['energy']['today_yen'] ) && is_array( $status['energy']['today_yen'] )
		? $status['energy']['today_yen']
		: gaming_hub_ecoflow_energy_today_yen( $status );
	$status['today_solar'] = isset( $status['energy']['today_solar'] ) && is_array( $status['energy']['today_solar'] )
		? $status['energy']['today_solar']
		: gaming_hub_ecoflow_energy_today_solar( $status );
	$status['today_usage'] = isset( $status['energy']['today_usage'] ) && is_array( $status['energy']['today_usage'] )
		? $status['energy']['today_usage']
		: gaming_hub_ecoflow_energy_today_usage( $status );

	return $status;
}

/**
 * Render the energy calendar.
 *
 * @param array<string, mixed> $extra Args.
 */
function gaming_hub_render_ecoflow_calendar( $extra = array() ) {
	$month  = isset( $extra['month'] ) ? (string) $extra['month'] : wp_date( 'Y-m' );
	$status = isset( $extra['status'] ) && is_array( $extra['status'] ) ? $extra['status'] : null;
	$cal    = isset( $extra['energy'] ) && is_array( $extra['energy'] )
		? $extra['energy']
		: gaming_hub_ecoflow_energy_month_payload( $month, $status );

	get_template_part(
		'template-parts/ecoflow',
		'calendar',
		array(
			'calendar' => $cal,
		)
	);
}

/**
 * REST: month energy calendar.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function gaming_hub_rest_ecoflow_energy( WP_REST_Request $request ) {
	$month = sanitize_text_field( (string) $request->get_param( 'month' ) );
	return new WP_REST_Response(
		array(
			'success' => true,
			'data'    => gaming_hub_ecoflow_energy_month_payload( $month ),
		),
		200
	);
}

/**
 * REST route for calendar month fetch.
 */
function gaming_hub_register_ecoflow_energy_rest() {
	register_rest_route(
		'gaming-hub/v1',
		'/ecoflow/energy',
		array(
			'methods'             => 'GET',
			'callback'            => 'gaming_hub_rest_ecoflow_energy',
			'permission_callback' => '__return_true',
			'args'                => array(
				'month' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
	register_rest_route(
		'gaming-hub/v1',
		'/ecoflow/today',
		array(
			'methods'             => 'GET',
			'callback'            => 'gaming_hub_rest_ecoflow_today',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'gaming_hub_register_ecoflow_energy_rest' );

/**
 * REST: canonical today generation Wh and savings yen (no origin proxy).
 *
 * @return WP_REST_Response
 */
function gaming_hub_rest_ecoflow_today() {
	$GLOBALS['gaming_hub_energy_skip_origin'] = true;

	$status = function_exists( 'gaming_hub_get_ecoflow_status' )
		? gaming_hub_get_ecoflow_status( true )
		: null;
	if ( is_wp_error( $status ) ) {
		$status = null;
	}

	$yen   = gaming_hub_ecoflow_energy_compute_today_yen( is_array( $status ) ? $status : null );
	$solar = gaming_hub_ecoflow_energy_compute_today_solar( is_array( $status ) ? $status : null );
	$usage = gaming_hub_ecoflow_energy_compute_today_usage( is_array( $status ) ? $status : null );

	return new WP_REST_Response(
		array(
			'success' => true,
			'date'    => wp_date( 'Y-m-d' ),
			'yen'     => array(
				'room_yen'     => (int) ( $yen['room_yen'] ?? 0 ),
				'ups_yen'      => (int) ( $yen['ups_yen'] ?? 0 ),
				'grid_yen'     => (int) ( $yen['grid_yen'] ?? 0 ),
				'pro_grid_yen' => (int) ( $yen['pro_grid_yen'] ?? 0 ),
				'buy_yen'      => (int) ( $yen['buy_yen'] ?? 0 ),
				'net_yen'      => (int) ( $yen['net_yen'] ?? 0 ),
			),
			'solar'   => array(
				'pro_wh'   => (int) ( $solar['pro_wh'] ?? 0 ),
				'delta_wh' => (int) ( $solar['delta_wh'] ?? 0 ),
			),
			'usage'   => array(
				'room_wh' => (int) ( $usage['room_wh'] ?? 0 ),
				'ups_wh'  => (int) ( $usage['ups_wh'] ?? 0 ),
			),
		),
		200
	);
}

/**
 * Background sample when the dashboard is closed.
 */
function gaming_hub_ecoflow_energy_cron() {
	if ( ! function_exists( 'gaming_hub_ecoflow_is_configured' ) || ! gaming_hub_ecoflow_is_configured() ) {
		return;
	}

	gaming_hub_get_ecoflow_status( true );
}

/**
 * Schedule the 5-minute energy sampler.
 */
function gaming_hub_ecoflow_energy_schedule_cron() {
	if ( ! wp_next_scheduled( GAMING_HUB_ECOFLOW_ENERGY_CRON ) ) {
		wp_schedule_event( time() + 90, 'five_minutes', GAMING_HUB_ECOFLOW_ENERGY_CRON );
	}
}
add_action( 'init', 'gaming_hub_ecoflow_energy_schedule_cron' );
add_action( GAMING_HUB_ECOFLOW_ENERGY_CRON, 'gaming_hub_ecoflow_energy_cron' );
