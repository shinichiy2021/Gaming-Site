<?php
/**
 * Tesla gasoline-savings log (daily / hourly charts, EcoFlow-style).
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_TESLA_GAS_LOG_PREFIX', 'gaming_hub_tesla_gas_v1_' );

/**
 * Option key for one month of Tesla driving / gas-savings days.
 *
 * @param string $ym Y-m.
 */
function gaming_hub_tesla_gas_log_key( $ym ) {
	return GAMING_HUB_TESLA_GAS_LOG_PREFIX . $ym;
}

/**
 * Pull lat/lon from Tesla vehicle_data (before GPS is stripped).
 *
 * @param array<string, mixed> $data Vehicle data.
 * @return array{lat: float, lon: float}|null
 */
function gaming_hub_tesla_extract_coords( array $data ) {
	$sources = array();
	if ( isset( $data['drive_state'] ) && is_array( $data['drive_state'] ) ) {
		$sources[] = $data['drive_state'];
	}
	if ( isset( $data['location_data'] ) && is_array( $data['location_data'] ) ) {
		$sources[] = $data['location_data'];
	}

	foreach ( $sources as $slice ) {
		$pairs = array(
			array( 'latitude', 'longitude' ),
			array( 'native_latitude', 'native_longitude' ),
			array( 'corrected_latitude', 'corrected_longitude' ),
		);
		foreach ( $pairs as $pair ) {
			$lat = $slice[ $pair[0] ] ?? null;
			$lon = $slice[ $pair[1] ] ?? null;
			if ( is_numeric( $lat ) && is_numeric( $lon ) ) {
				$lat = (float) $lat;
				$lon = (float) $lon;
				if ( abs( $lat ) > 0.01 || abs( $lon ) > 0.01 ) {
					return array(
						'lat' => $lat,
						'lon' => $lon,
					);
				}
			}
		}
	}

	return null;
}

/**
 * Short Japanese-friendly label from Nominatim address parts.
 *
 * @param array<string, mixed> $addr Address map.
 * @return string
 */
function gaming_hub_tesla_format_place_label( array $addr ) {
	$city = (string) ( $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['municipality'] ?? $addr['county'] ?? '' );
	$ward = (string) ( $addr['suburb'] ?? $addr['city_district'] ?? $addr['quarter'] ?? $addr['neighbourhood'] ?? '' );
	$road = (string) ( $addr['road'] ?? $addr['pedestrian'] ?? '' );

	$bits = array_filter( array( $city, $ward ) );
	if ( $road && count( $bits ) < 2 ) {
		$bits[] = $road;
	}

	$label = implode( ' ', $bits );
	if ( '' === $label ) {
		$label = (string) ( $addr['state'] ?? '' );
	}

	$label = preg_replace( '/\s+/u', ' ', trim( $label ) );

	return is_string( $label ) ? $label : '';
}

/**
 * Reverse-geocode coordinates to a short place label (cached).
 *
 * Stores only the label — raw GPS is not kept in the driving log.
 *
 * @param float $lat Latitude.
 * @param float $lon Longitude.
 * @return string
 */
function gaming_hub_tesla_reverse_geocode( $lat, $lon ) {
	$lat = (float) $lat;
	$lon = (float) $lon;
	if ( abs( $lat ) < 0.01 && abs( $lon ) < 0.01 ) {
		return '';
	}

	$key = 'gh_tesla_geo_' . md5( sprintf( '%.3f,%.3f', $lat, $lon ) );
	$hit = get_transient( $key );
	if ( is_string( $hit ) ) {
		return $hit;
	}

	$url = add_query_arg(
		array(
			'format'         => 'json',
			'lat'            => sprintf( '%.6f', $lat ),
			'lon'            => sprintf( '%.6f', $lon ),
			'zoom'           => 16,
			'addressdetails' => 1,
			'accept-language'=> 'ja',
		),
		'https://nominatim.openstreetmap.org/reverse'
	);

	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 3,
			'headers' => array(
				'User-Agent' => 'GamingHubTeslaDriveLog/1.0 (https://shinichiy-gaming-hub.com; personal use)',
			),
		)
	);

	$label = '';
	if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( is_array( $body ) ) {
			$addr = isset( $body['address'] ) && is_array( $body['address'] ) ? $body['address'] : array();
			$label = gaming_hub_tesla_format_place_label( $addr );
			if ( '' === $label && ! empty( $body['display_name'] ) ) {
				$parts = array_map( 'trim', explode( ',', (string) $body['display_name'] ) );
				$label = implode( ' ', array_slice( $parts, 0, 2 ) );
			}
		}
	}

	$label = sanitize_text_field( $label );
	set_transient( $key, $label, WEEK_IN_SECONDS );

	return $label;
}

/**
 * Remember today's first / last driving places from a live GPS sample.
 *
 * Disabled — driving log no longer records or shows addresses.
 *
 * @param array{lat: float, lon: float} $coords Coordinates.
 * @param bool                          $moving Car is driving.
 */
function gaming_hub_tesla_gas_log_record_place( array $coords, $moving ) {
	unset( $coords, $moving );
}

/**
 * Convert today's km into gasoline liters and yen saved vs a 15 km/L car.
 *
 * @param float      $km          Distance.
 * @param float|null $metered_yen Already-metered electricity cost, if known.
 * @return array<string, float|int|string>
 */
function gaming_hub_tesla_gas_metrics_from_km( $km, $metered_yen = null ) {
	$km         = max( 0, (float) $km );
	$yen_per_l  = 171.7;
	$as_of      = '';
	$price_label = '';

	if ( function_exists( 'gaming_hub_tajimi_gasoline_price' ) ) {
		$price     = gaming_hub_tajimi_gasoline_price();
		$yen_per_l = (float) ( $price['yen_per_l'] ?? $yen_per_l );
		$as_of     = (string) ( $price['as_of'] ?? '' );
	}

	$km_per_l  = defined( 'GAMING_HUB_GAS_COMPARE_KM_PER_L' ) ? (float) GAMING_HUB_GAS_COMPARE_KM_PER_L : 15.0;
	$wh_per_km = defined( 'GAMING_HUB_MODEL3_WH_PER_KM' ) ? (float) GAMING_HUB_MODEL3_WH_PER_KM : 150.0;
	$elec_yen  = function_exists( 'gaming_hub_tesla_electricity_yen_per_kwh' )
		? gaming_hub_tesla_electricity_yen_per_kwh()
		: 30.0;

	$gas_l   = $km >= 0.1 ? $km / $km_per_l : 0.0;
	$ev_kwh  = $km >= 0.1 ? ( $km * $wh_per_km ) / 1000.0 : 0.0;
	$gas_yen = $gas_l * $yen_per_l;
	$ev_yen  = is_numeric( $metered_yen ) ? max( 0, (float) $metered_yen ) : $ev_kwh * $elec_yen;
	$saved   = max( 0, $gas_yen - $ev_yen );

	if ( function_exists( 'gaming_hub_tesla_gasoline_compare' ) ) {
		$empty = gaming_hub_tesla_gasoline_compare( array( 'today_km' => $km ), 0, 0 );
		$price_label = (string) ( $empty['price_label'] ?? '' );
	}

	return array(
		'km'              => round( $km, 1 ),
		'gas_l'           => round( $gas_l, 2 ),
		'ev_kwh'          => round( $ev_kwh, 2 ),
		'gas_yen'         => (int) round( $gas_yen ),
		'ev_yen'          => (int) round( $ev_yen ),
		'saved_yen'       => (int) round( $saved ),
		'yen_per_l'       => $yen_per_l,
		'elec_yen_per_kwh'=> round( (float) $elec_yen, 1 ),
		'as_of'           => $as_of,
		'price_label'     => $price_label,
	);
}

/**
 * Days stored for a month.
 *
 * @param string $ym Y-m.
 * @return array<string, array<string, mixed>>
 */
function gaming_hub_tesla_gas_log_month_days( $ym ) {
	$raw = get_option( gaming_hub_tesla_gas_log_key( $ym ), array() );

	return is_array( $raw ) ? $raw : array();
}

/**
 * Persist one month.
 *
 * @param string                          $ym   Y-m.
 * @param array<string, array<string, mixed>> $days Day map.
 */
function gaming_hub_tesla_gas_log_save_month( $ym, array $days ) {
	update_option( gaming_hub_tesla_gas_log_key( $ym ), $days, false );
}

/**
 * Write today's km into the monthly log (hourly delta when km grows).
 *
 * @param float                $today_km    Today's driving km.
 * @param float|null           $metered_yen Today's already-metered electricity cost.
 * @param array<string, mixed> $meta        Optional ev_kwh.
 */
function gaming_hub_tesla_gas_log_record_today( $today_km, $metered_yen = null, array $meta = array() ) {
	$today_km = max( 0, (float) $today_km );
	$today    = wp_date( 'Y-m-d' );
	$ym       = substr( $today, 0, 7 );
	$hour     = (int) wp_date( 'G' );
	$days     = gaming_hub_tesla_gas_log_month_days( $ym );
	$prev     = isset( $days[ $today ] ) && is_array( $days[ $today ] ) ? $days[ $today ] : array();
	$prev_km  = isset( $prev['km'] ) && is_numeric( $prev['km'] ) ? (float) $prev['km'] : null;
	$metrics  = gaming_hub_tesla_gas_metrics_from_km( $today_km, $metered_yen );
	$hours    = isset( $prev['hours'] ) && is_array( $prev['hours'] ) ? $prev['hours'] : array();

	if ( isset( $meta['ev_kwh'] ) && is_numeric( $meta['ev_kwh'] ) ) {
		$metrics['ev_kwh'] = round( max( 0, (float) $meta['ev_kwh'] ), 2 );
	}

	if ( null !== $prev_km ) {
		$delta = $today_km - $prev_km;
		if ( $delta >= 0.05 ) {
			$slot          = isset( $hours[ $hour ] ) && is_array( $hours[ $hour ] ) ? $hours[ $hour ] : array();
			$slice         = gaming_hub_tesla_gas_metrics_from_km( $delta );
			$slot['km']    = round( (float) ( $slot['km'] ?? 0 ) + $delta, 1 );
			$slot['gas_l'] = round( (float) ( $slot['gas_l'] ?? 0 ) + (float) $slice['gas_l'], 2 );
			$slot['ev_kwh'] = round( (float) ( $slot['ev_kwh'] ?? 0 ) + (float) $slice['ev_kwh'], 2 );
			$slot['saved_yen'] = (int) round( (float) ( $slot['saved_yen'] ?? 0 ) + (float) $slice['saved_yen'] );
			$hours[ $hour ] = $slot;
		}
	}

	// Drop place labels — driving log no longer shows or records addresses.
	unset( $prev['start_address'], $prev['end_address'], $prev['_moving'] );

	$days[ $today ] = array_merge(
		$prev,
		$metrics,
		array(
			'date'       => $today,
			'hours'      => $hours,
			'updated_at' => time(),
		)
	);

	gaming_hub_tesla_gas_log_save_month( $ym, $days );
}

/**
 * Keep today's row in sync with the latest odometer snapshot.
 */
function gaming_hub_tesla_gas_log_sync_from_odo() {
	if ( ! defined( 'GAMING_HUB_MODEL3_ODO_OPTION' ) ) {
		return;
	}

	$odo = get_option( GAMING_HUB_MODEL3_ODO_OPTION, array() );
	if ( ! is_array( $odo ) || (string) ( $odo['date'] ?? '' ) !== wp_date( 'Y-m-d' ) ) {
		return;
	}

	if ( ! isset( $odo['today_km'] ) || ! is_numeric( $odo['today_km'] ) ) {
		return;
	}

	$meta = array();
	if ( isset( $odo['wh'] ) && is_numeric( $odo['wh'] ) ) {
		$meta['ev_kwh'] = round( max( 0, (float) $odo['wh'] ) / 1000.0, 2 );
	}
	$yen = isset( $odo['yen'] ) && is_numeric( $odo['yen'] ) ? (float) $odo['yen'] : null;

	gaming_hub_tesla_gas_log_record_today( (float) $odo['today_km'], $yen, $meta );
}

/**
 * Chart axis ticks (reuse EcoFlow helper when present).
 *
 * @param float $max Raw max.
 * @return array{0: float, 1: array<int, float>}
 */
function gaming_hub_tesla_gas_axis_ticks( $max ) {
	if ( function_exists( 'gaming_hub_ecoflow_energy_axis_ticks' ) ) {
		return gaming_hub_ecoflow_energy_axis_ticks( $max );
	}

	$max = max( 0, (float) $max );
	$top = $max > 0 ? $max : 1.0;

	return array( $top, array( $top, $top * 0.75, $top * 0.5, $top * 0.25, 0 ) );
}

/**
 * Live NOW slice for the savings HUD.
 *
 * @param array<string, mixed>|null $status Powerwall/Tesla status.
 * @return array<string, mixed>
 */
function gaming_hub_tesla_gas_now_slice( $status = null ) {
	if ( ! is_array( $status ) && function_exists( 'gaming_hub_get_powerwall_flow_status' ) ) {
		$status = gaming_hub_get_powerwall_flow_status();
	}

	$status = is_array( $status ) ? $status : array();
	$flow   = isset( $status['tesla_flow'] ) && is_array( $status['tesla_flow'] )
		? $status['tesla_flow']
		: array();
	$gas    = isset( $flow['gas'] ) && is_array( $flow['gas'] ) ? $flow['gas'] : array();
	$asleep = ! empty( $flow['asleep'] ) || ! empty( $status['tesla_asleep'] );
	$live   = ! empty( $flow['live'] );

	return array(
		'asleep'          => $asleep,
		'speed_km'        => ( $asleep || ! $live ) ? 0 : (int) ( $flow['speed_km'] ?? 0 ),
		'saved_yen_per_h' => ( $asleep || ! $live ) ? 0 : (int) ( $gas['saved_yen_per_h'] ?? 0 ),
		'today_km'        => $live ? (float) ( $gas['today_km'] ?? 0 ) : 0.0,
		'saved_yen'       => $live ? (int) ( $gas['saved_yen'] ?? 0 ) : 0,
		'gas_l'           => $live ? (float) ( $gas['gas_l'] ?? 0 ) : 0.0,
		'price_label'     => (string) ( $gas['price_label'] ?? '' ),
	);
}

/**
 * 24 hourly chart rows.
 *
 * @param array<int, mixed> $hours Hour map.
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_tesla_gas_hour_rows( $hours ) {
	$hours = is_array( $hours ) ? $hours : array();
	$rows  = array();

	for ( $h = 0; $h < 24; $h++ ) {
		$row = isset( $hours[ $h ] ) && is_array( $hours[ $h ] ) ? $hours[ $h ] : array();
		$km  = isset( $row['km'] ) ? (float) $row['km'] : null;
		$rows[] = array(
			'hour'      => $h,
			'km'        => $km,
			'gas_l'     => isset( $row['gas_l'] ) ? (float) $row['gas_l'] : null,
			'saved_yen' => isset( $row['saved_yen'] ) ? (int) $row['saved_yen'] : null,
			'has_data'  => null !== $km && $km > 0,
		);
	}

	return $rows;
}

/**
 * Empty aggregate for a gas-log date range.
 *
 * @return array<string, float|int|null>
 */
function gaming_hub_tesla_gas_empty_aggregate() {
	return array(
		'km'             => 0.0,
		'gas_l'          => 0.0,
		'ev_kwh'         => 0.0,
		'gas_yen'        => 0,
		'ev_yen'         => 0,
		'saved_yen'      => 0,
		'avg_yen_per_km' => null,
		'days_with_data' => 0,
	);
}

/**
 * Sum gas-log rows between two inclusive Y-m-d dates.
 *
 * @param string $from Start date.
 * @param string $to   End date.
 * @return array<string, float|int|null>
 */
function gaming_hub_tesla_gas_aggregate_range( $from, $to ) {
	$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $from ) ? (string) $from : '';
	$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $to ) ? (string) $to : '';
	$out  = gaming_hub_tesla_gas_empty_aggregate();

	if ( ! $from || ! $to || $from > $to ) {
		return $out;
	}

	$cursor = strtotime( $from . ' 12:00:00' );
	$end    = strtotime( $to . ' 12:00:00' );
	if ( ! $cursor || ! $end ) {
		return $out;
	}

	$month_cache = array();

	while ( $cursor <= $end ) {
		$date = wp_date( 'Y-m-d', $cursor );
		$ym   = substr( $date, 0, 7 );

		if ( ! isset( $month_cache[ $ym ] ) ) {
			$month_cache[ $ym ] = gaming_hub_tesla_gas_log_month_days( $ym );
		}

		$row = isset( $month_cache[ $ym ][ $date ] ) && is_array( $month_cache[ $ym ][ $date ] )
			? $month_cache[ $ym ][ $date ]
			: null;

		if ( $row ) {
			$out['km']        += (float) ( $row['km'] ?? 0 );
			$out['gas_l']     += (float) ( $row['gas_l'] ?? 0 );
			$out['ev_kwh']    += (float) ( $row['ev_kwh'] ?? 0 );
			$out['gas_yen']   += (int) ( $row['gas_yen'] ?? 0 );
			$out['ev_yen']    += (int) ( $row['ev_yen'] ?? 0 );
			$out['saved_yen'] += (int) ( $row['saved_yen'] ?? 0 );
			$out['days_with_data']++;
		}

		$cursor = strtotime( '+1 day', $cursor );
	}

	$out['km']     = round( $out['km'], 1 );
	$out['gas_l']  = round( $out['gas_l'], 2 );
	$out['ev_kwh'] = round( $out['ev_kwh'], 2 );
	$out['avg_yen_per_km'] = $out['km'] >= 0.1
		? round( $out['ev_yen'] / $out['km'], 1 )
		: null;

	return $out;
}

/**
 * Monday–Sunday bounds for the week containing a date (site timezone).
 *
 * @param string|null $ref Y-m-d.
 * @return array{0: string, 1: string}
 */
function gaming_hub_tesla_gas_week_bounds( $ref = null ) {
	$ref = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $ref ) ? (string) $ref : wp_date( 'Y-m-d' );
	$ts  = strtotime( $ref . ' 12:00:00' );
	if ( ! $ts ) {
		$ref = wp_date( 'Y-m-d' );
		$ts  = strtotime( $ref . ' 12:00:00' );
	}

	$iso_dow = (int) wp_date( 'N', $ts ); // 1=Mon … 7=Sun.
	$monday  = wp_date( 'Y-m-d', strtotime( $ref . ' -' . ( $iso_dow - 1 ) . ' days' ) );
	$sunday  = wp_date( 'Y-m-d', strtotime( $monday . ' +6 days' ) );

	return array( $monday, $sunday );
}

/**
 * Day / week summary card payload (gas-log digest).
 *
 * @param array<string, mixed>|null $status Live status.
 * @return array<string, mixed>
 */
function gaming_hub_tesla_gas_summary_payload( $status = null ) {
	gaming_hub_tesla_gas_log_sync_from_odo();

	$today           = wp_date( 'Y-m-d' );
	list( $week_from, $week_to ) = gaming_hub_tesla_gas_week_bounds( $today );
	$day             = gaming_hub_tesla_gas_aggregate_range( $today, $today );
	$week            = gaming_hub_tesla_gas_aggregate_range( $week_from, $week_to );
	$now             = gaming_hub_tesla_gas_now_slice( $status );

	// Prefer live odometer when today's row is still empty.
	if ( $day['days_with_data'] <= 0 && (float) ( $now['today_km'] ?? 0 ) > 0 ) {
		$metrics = gaming_hub_tesla_gas_metrics_from_km( (float) $now['today_km'] );
		$day     = array(
			'km'             => (float) $metrics['km'],
			'gas_l'          => (float) $metrics['gas_l'],
			'ev_kwh'         => (float) $metrics['ev_kwh'],
			'gas_yen'        => (int) $metrics['gas_yen'],
			'ev_yen'         => (int) $metrics['ev_yen'],
			'saved_yen'      => (int) ( $now['saved_yen'] ?? $metrics['saved_yen'] ),
			'avg_yen_per_km' => (float) $metrics['km'] >= 0.1
				? round( (int) $metrics['ev_yen'] / (float) $metrics['km'], 1 )
				: null,
			'days_with_data' => 1,
		);
	}

	$week_from_ts = strtotime( $week_from . ' 12:00:00' );
	$week_to_ts   = strtotime( $week_to . ' 12:00:00' );

	return array(
		'day'  => array_merge(
			$day,
			array(
				'period' => 'day',
				'from'   => $today,
				'to'     => $today,
				'label'  => __( '今日', 'gaming-hub' ),
			)
		),
		'week' => array_merge(
			$week,
			array(
				'period' => 'week',
				'from'   => $week_from,
				'to'     => $week_to,
				'label'  => $week_from_ts && $week_to_ts
					? sprintf(
						/* translators: 1: week start n/j, 2: week end n/j */
						__( '%1$s〜%2$s', 'gaming-hub' ),
						wp_date( 'n/j', $week_from_ts ),
						wp_date( 'n/j', $week_to_ts )
					)
					: __( '今週', 'gaming-hub' ),
			)
		),
	);
}

/**
 * Month payload for the Tesla savings calendar.
 *
 * @param string                    $ym     Y-m.
 * @param array<string, mixed>|null $status Live status.
 * @return array<string, mixed>
 */
function gaming_hub_tesla_gas_month_payload( $ym, $status = null ) {
	gaming_hub_tesla_gas_log_sync_from_odo();

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
	$log           = gaming_hub_tesla_gas_log_month_days( $ym );
	$cells         = array();
	$totals        = array(
		'km'        => 0.0,
		'gas_l'     => 0.0,
		'ev_kwh'    => 0.0,
		'saved_yen' => 0.0,
	);

	for ( $d = 1; $d <= $days_in_month; $d++ ) {
		$date = $ym . '-' . sprintf( '%02d', $d );
		$row  = isset( $log[ $date ] ) && is_array( $log[ $date ] ) ? $log[ $date ] : null;
		$cell = array(
			'date'          => $date,
			'day'           => $d,
			'km'            => $row ? (float) ( $row['km'] ?? 0 ) : null,
			'gas_l'         => $row ? (float) ( $row['gas_l'] ?? 0 ) : null,
			'ev_kwh'        => $row ? (float) ( $row['ev_kwh'] ?? 0 ) : null,
			'gas_yen'       => $row ? (int) ( $row['gas_yen'] ?? 0 ) : null,
			'ev_yen'        => $row ? (int) ( $row['ev_yen'] ?? 0 ) : null,
			'saved_yen'     => $row ? (int) ( $row['saved_yen'] ?? 0 ) : null,
			'has_data'      => (bool) $row,
			'is_today'      => $date === $today,
		);
		$cells[] = $cell;

		if ( $row ) {
			$totals['km']        += (float) ( $cell['km'] ?? 0 );
			$totals['gas_l']     += (float) ( $cell['gas_l'] ?? 0 );
			$totals['ev_kwh']    += (float) ( $cell['ev_kwh'] ?? 0 );
			$totals['saved_yen'] += (float) ( $cell['saved_yen'] ?? 0 );
		}
	}

	$max_km  = 0.0;
	$max_yen = 0.0;
	foreach ( $cells as $cell ) {
		$max_km  = max( $max_km, (float) ( $cell['km'] ?? 0 ) );
		$max_yen = max( $max_yen, abs( (float) ( $cell['saved_yen'] ?? 0 ) ) );
	}
	list( $km_max, $km_ticks )   = gaming_hub_tesla_gas_axis_ticks( $max_km );
	list( $yen_max, $yen_ticks ) = gaming_hub_tesla_gas_axis_ticks( $max_yen );

	$today_hours     = array();
	$today_km_max    = 1.0;
	$today_km_ticks  = array( 1, 0.75, 0.5, 0.25, 0 );
	$today_yen_max   = 1.0;
	$today_yen_ticks = array( 1, 0.75, 0.5, 0.25, 0 );
	if ( $ym === substr( $today, 0, 7 ) ) {
		$today_hours = gaming_hub_tesla_gas_hour_rows( $log[ $today ]['hours'] ?? array() );
		$hour_km     = 0.0;
		$hour_yen    = 0.0;
		foreach ( $today_hours as $hour_row ) {
			$hour_km  = max( $hour_km, (float) ( $hour_row['km'] ?? 0 ) );
			$hour_yen = max( $hour_yen, (float) ( $hour_row['saved_yen'] ?? 0 ) );
		}
		list( $today_km_max, $today_km_ticks )   = gaming_hub_tesla_gas_axis_ticks( $hour_km );
		list( $today_yen_max, $today_yen_ticks ) = gaming_hub_tesla_gas_axis_ticks( $hour_yen );
	}

	$now  = gaming_hub_tesla_gas_now_slice( $status );
	$prev = wp_date( 'Y-m', strtotime( $start . ' -1 month' ) );
	$next = wp_date( 'Y-m', strtotime( $start . ' +1 month' ) );
	$today_row = isset( $log[ $today ] ) && is_array( $log[ $today ] ) ? $log[ $today ] : null;

	return array(
		'month'           => $ym,
		'label'           => wp_date( 'Y年n月', $ts ),
		'today'           => $today,
		'start_wday'      => $start_wday,
		'days'            => $cells,
		'totals'          => array(
			'km'        => round( $totals['km'], 1 ),
			'gas_l'     => round( $totals['gas_l'], 2 ),
			'ev_kwh'    => round( $totals['ev_kwh'], 2 ),
			'saved_yen' => (int) round( $totals['saved_yen'] ),
		),
		'now'             => $now,
		'today_stats'     => array(
			'km'        => $today_row ? (float) ( $today_row['km'] ?? 0 ) : (float) ( $now['today_km'] ?? 0 ),
			'gas_l'     => $today_row ? (float) ( $today_row['gas_l'] ?? 0 ) : (float) ( $now['gas_l'] ?? 0 ),
			'ev_kwh'    => $today_row ? (float) ( $today_row['ev_kwh'] ?? 0 ) : 0.0,
			'gas_yen'   => $today_row ? (int) ( $today_row['gas_yen'] ?? 0 ) : 0,
			'ev_yen'    => $today_row ? (int) ( $today_row['ev_yen'] ?? 0 ) : 0,
			'saved_yen' => $today_row ? (int) ( $today_row['saved_yen'] ?? 0 ) : (int) ( $now['saved_yen'] ?? 0 ),
		),
		'summary'         => gaming_hub_tesla_gas_summary_payload( $status ),
		'prev'            => $prev,
		'next'            => $next,
		'weekdays'        => array( '日', '月', '火', '水', '木', '金', '土' ),
		'km_max'          => $km_max,
		'km_ticks'        => $km_ticks,
		'yen_max'         => $yen_max,
		'yen_ticks'       => $yen_ticks,
		'today_hours'     => $today_hours,
		'today_km_max'    => $today_km_max,
		'today_km_ticks'  => $today_km_ticks,
		'today_yen_max'   => $today_yen_max,
		'today_yen_ticks' => $today_yen_ticks,
	);
}

/**
 * Render the Tesla gasoline-savings calendar.
 *
 * @param array<string, mixed>|null $status Live status.
 */
function gaming_hub_render_tesla_gas_log( $status = null ) {
	get_template_part(
		'template-parts/tesla',
		'gas-log',
		array(
			'calendar' => gaming_hub_tesla_gas_month_payload( wp_date( 'Y-m' ), $status ),
		)
	);
}

/**
 * REST: GET /gaming-hub/v1/tesla/gas
 */
function gaming_hub_register_tesla_gas_rest() {
	register_rest_route(
		'gaming-hub/v1',
		'/tesla/gas',
		array(
			'methods'             => 'GET',
			'callback'            => 'gaming_hub_rest_tesla_gas',
			'permission_callback' => '__return_true',
			'args'                => array(
				'month' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'gaming_hub_register_tesla_gas_rest' );

/**
 * REST callback.
 *
 * @param WP_REST_Request $request Request.
 */
function gaming_hub_rest_tesla_gas( WP_REST_Request $request ) {
	$month = (string) $request->get_param( 'month' );

	return new WP_REST_Response(
		array(
			'success' => true,
			'data'    => gaming_hub_tesla_gas_month_payload( $month ),
		),
		200
	);
}

/**
 * Enqueue Tesla gas-log script on the Tesla tag.
 */
function gaming_hub_tesla_gas_log_scripts() {
	if ( ! is_tag( 'tesla' ) && ! is_page( 'powerwall' ) && ! ( function_exists( 'gaming_hub_is_hub_spa_page' ) && gaming_hub_is_hub_spa_page() ) ) {
		return;
	}

	wp_enqueue_script(
		'gaming-hub-tesla-eff-badges',
		get_template_directory_uri() . '/assets/js/tesla-eff-badges.js',
		array(),
		GAMING_HUB_VERSION,
		true
	);

	wp_enqueue_script(
		'gaming-hub-tesla-gas-log',
		get_template_directory_uri() . '/assets/js/tesla-gas-log.js',
		array( 'gaming-hub-i18n' ),
		GAMING_HUB_VERSION,
		true
	);

	wp_localize_script(
		'gaming-hub-tesla-gas-log',
		'gamingHubTeslaGas',
		array(
			'url' => (string) wp_parse_url( rest_url( 'gaming-hub/v1/tesla/gas' ), PHP_URL_PATH ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'gaming_hub_tesla_gas_log_scripts' );
