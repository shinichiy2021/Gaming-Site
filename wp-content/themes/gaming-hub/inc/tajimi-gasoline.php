<?php
/**
 * Tajimi regular-gasoline retail price (gogo.gs city average).
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_TAJIMI_GAS_CACHE_KEY', 'gaming_hub_tajimi_gas_v1' );
define( 'GAMING_HUB_TAJIMI_GAS_LAST_OPTION', 'gaming_hub_tajimi_gas_last' );
define( 'GAMING_HUB_TAJIMI_GAS_TTL', 12 * HOUR_IN_SECONDS );
define( 'GAMING_HUB_TAJIMI_GAS_URL', 'https://gogo.gs/21204' );
define( 'GAMING_HUB_TAJIMI_GAS_FALLBACK_YEN', 171.7 );
define( 'GAMING_HUB_GAS_COMPARE_KM_PER_L', 15.0 );

/**
 * Latest Tajimi regular gasoline yen/L.
 *
 * @param bool $force_refresh Skip cache.
 * @return array{yen_per_l: float, as_of: string, source: string, location: string}
 */
function gaming_hub_tajimi_gasoline_price( $force_refresh = false ) {
	if ( ! $force_refresh ) {
		$cached = get_transient( GAMING_HUB_TAJIMI_GAS_CACHE_KEY );
		if ( is_array( $cached ) && ! empty( $cached['yen_per_l'] ) ) {
			return $cached;
		}
	}

	$fetched = gaming_hub_tajimi_gasoline_fetch();
	if ( is_array( $fetched ) ) {
		set_transient( GAMING_HUB_TAJIMI_GAS_CACHE_KEY, $fetched, GAMING_HUB_TAJIMI_GAS_TTL );
		update_option( GAMING_HUB_TAJIMI_GAS_LAST_OPTION, $fetched, false );

		return $fetched;
	}

	$last = get_option( GAMING_HUB_TAJIMI_GAS_LAST_OPTION, array() );
	if ( is_array( $last ) && ! empty( $last['yen_per_l'] ) ) {
		return $last;
	}

	return array(
		'yen_per_l' => (float) GAMING_HUB_TAJIMI_GAS_FALLBACK_YEN,
		'as_of'     => '2026-08-22',
		'source'    => 'gogo.gs',
		'location'  => 'tajimi',
	);
}

/**
 * Fetch Tajimi regular price from gogo.gs.
 *
 * @return array<string, mixed>|null
 */
function gaming_hub_tajimi_gasoline_fetch() {
	$response = wp_remote_get(
		GAMING_HUB_TAJIMI_GAS_URL,
		array(
			'timeout' => 12,
			'headers' => array(
				'User-Agent' => 'GamingHub/1.0 (+https://shinichiy-gaming-hub.com)',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return null;
	}

	$html = wp_remote_retrieve_body( $response );
	if ( ! is_string( $html ) || '' === $html ) {
		return null;
	}

	if ( ! preg_match( '/レギュラー<\/p>.*?class="price[^"]*"[^>]*>([0-9]+(?:\.[0-9]+)?)/s', $html, $match ) ) {
		return null;
	}

	$yen = (float) $match[1];
	if ( $yen < 80 || $yen > 400 ) {
		return null;
	}

	$as_of = wp_date( 'Y-m-d' );
	if ( preg_match( '/(\d{4})年(\d{1,2})月(\d{1,2})日[^<]{0,40}時点での/u', $html, $date ) ) {
		$as_of = sprintf( '%04d-%02d-%02d', (int) $date[1], (int) $date[2], (int) $date[3] );
	}

	return array(
		'yen_per_l' => round( $yen, 1 ),
		'as_of'     => $as_of,
		'source'    => 'gogo.gs',
		'location'  => 'tajimi',
	);
}

/**
 * Current LOOOP billed yen/kWh, or a Chubu-like fallback.
 *
 * @return float
 */
function gaming_hub_tesla_electricity_yen_per_kwh() {
	if ( ! function_exists( 'gaming_hub_looop_hourly_price_map_today' ) ) {
		return 30.0;
	}

	$prices = gaming_hub_looop_hourly_price_map_today();
	if ( is_wp_error( $prices ) ) {
		return 30.0;
	}

	$hour = (int) wp_date( 'G' );
	$yen  = $prices['map'][ $hour ] ?? $prices['fallback'];

	return ( $yen > 0 ) ? (float) $yen : 30.0;
}

/**
 * Date + hour → LOOOP billed yen/kWh, covering yesterday through tomorrow.
 *
 * @return array{days: array<string, array<int, float>>, fallback: float}
 */
function gaming_hub_looop_rate_lookup() {
	static $lookup = null;
	if ( null !== $lookup ) {
		return $lookup;
	}

	$lookup = array(
		'days'     => array(),
		'fallback' => 30.0,
	);

	if ( ! function_exists( 'gaming_hub_looop_hourly_price_map_today' ) ) {
		return $lookup;
	}

	$prices = gaming_hub_looop_hourly_price_map_today();
	if ( is_wp_error( $prices ) ) {
		return $lookup;
	}

	$fallback = (float) ( $prices['fallback'] ?? 0 );
	if ( $fallback > 0 ) {
		$lookup['fallback'] = $fallback;
	}

	$today = wp_date( 'Y-m-d' );
	foreach ( (array) ( $prices['map'] ?? array() ) as $hour => $yen ) {
		if ( (float) $yen > 0 ) {
			$lookup['days'][ $today ][ (int) $hour ] = (float) $yen;
		}
	}

	foreach ( (array) ( $prices['forecast']['days'] ?? array() ) as $day ) {
		foreach ( (array) ( $day['hourly'] ?? array() ) as $row ) {
			$date = (string) ( $row['date'] ?? '' );
			$hour = (int) ( $row['hour'] ?? -1 );
			$yen  = (float) ( $row['total_price'] ?? 0 );
			if ( '' !== $date && $hour >= 0 && $yen > 0 && ! isset( $lookup['days'][ $date ][ $hour ] ) ) {
				$lookup['days'][ $date ][ $hour ] = $yen;
			}
		}
	}

	return $lookup;
}

/**
 * LOOOP billed yen/kWh in effect at a given moment.
 *
 * @param int $timestamp Unix time.
 * @return float
 */
function gaming_hub_looop_rate_at( $timestamp ) {
	$lookup = gaming_hub_looop_rate_lookup();
	$yen    = $lookup['days'][ wp_date( 'Y-m-d', $timestamp ) ][ (int) wp_date( 'G', $timestamp ) ] ?? 0;

	return ( (float) $yen > 0 ) ? (float) $yen : $lookup['fallback'];
}

/**
 * Time-weighted average yen/kWh across a window.
 *
 * Energy metered between two polls may straddle several pricing hours, so bill it
 * at the average of the rates that were actually in effect rather than the spot
 * rate at whichever moment we happened to read the meter.
 *
 * @param int $from Window start (unix).
 * @param int $to   Window end (unix).
 * @return float
 */
function gaming_hub_looop_average_rate_between( $from, $to ) {
	$from = (int) $from;
	$to   = (int) $to;

	if ( $to <= $from ) {
		return gaming_hub_looop_rate_at( $to );
	}

	// Rates are only known for a few days, so never average over a longer window.
	$from = max( $from, $to - ( 2 * DAY_IN_SECONDS ) );

	$weighted = 0.0;
	$cursor   = $from;
	while ( $cursor < $to ) {
		$into_hour = ( (int) wp_date( 'i', $cursor ) * MINUTE_IN_SECONDS ) + (int) wp_date( 's', $cursor );
		$next      = min( $to, $cursor + max( 1, HOUR_IN_SECONDS - $into_hour ) );
		$weighted += gaming_hub_looop_rate_at( $cursor ) * ( $next - $cursor );
		$cursor    = $next;
	}

	return $weighted / ( $to - $from );
}

/**
 * Gasoline-equivalent liters and yen saved vs a 15 km/L car in Tajimi.
 *
 * @param array<string, mixed> $model3  Model 3 HUD.
 * @param int|null             $drive_w Live propulsion watts.
 * @param int                  $speed_km Speed km/h.
 * @return array<string, mixed>
 */
function gaming_hub_tesla_gasoline_compare( array $model3, $drive_w, $speed_km ) {
	$price     = gaming_hub_tajimi_gasoline_price();
	$yen_per_l = (float) $price['yen_per_l'];
	$km_per_l  = (float) GAMING_HUB_GAS_COMPARE_KM_PER_L;
	$elec_yen  = gaming_hub_tesla_electricity_yen_per_kwh();
	$wh_per_km = defined( 'GAMING_HUB_MODEL3_WH_PER_KM' )
		? (float) GAMING_HUB_MODEL3_WH_PER_KM
		: 150.0;

	$today_km = isset( $model3['today_km'] ) && is_numeric( $model3['today_km'] )
		? max( 0, (float) $model3['today_km'] )
		: 0.0;

	$today_l = $today_km >= 0.1 ? $today_km / $km_per_l : 0.0;
	$gas_yen = $today_l * $yen_per_l;

	// Prefer the odometer log: it meters each leg at the rate that was in effect,
	// where deriving the whole day from the current rate made the cost drift with
	// the time of day.
	$today_kwh = isset( $model3['drive_kwh'] ) && is_numeric( $model3['drive_kwh'] )
		? max( 0, (float) $model3['drive_kwh'] )
		: ( $today_km >= 0.1 ? ( $today_km * $wh_per_km ) / 1000.0 : 0.0 );
	$ev_yen    = isset( $model3['drive_yen'] ) && is_numeric( $model3['drive_yen'] )
		? max( 0, (float) $model3['drive_yen'] )
		: $today_kwh * $elec_yen;
	$saved     = max( 0, $gas_yen - $ev_yen );

	$l_per_h        = 0.0;
	$saved_yen_h    = 0.0;
	$driving        = $speed_km >= 3 && (int) ( $drive_w ?? 0 ) >= 80;
	if ( $driving ) {
		$l_per_h     = $speed_km / $km_per_l;
		$gas_yen_h   = $l_per_h * $yen_per_l;
		$ev_yen_h    = ( $speed_km * $wh_per_km / 1000.0 ) * $elec_yen;
		$saved_yen_h = max( 0, $gas_yen_h - $ev_yen_h );
	}

	return array(
		'today_km'        => round( $today_km, 1 ),
		'today_kwh'       => round( $today_kwh, 2 ),
		'gas_l'           => round( $today_l, 2 ),
		'gas_l_per_h'     => round( $l_per_h, 2 ),
		'gas_yen'         => (int) round( $gas_yen ),
		'ev_yen'          => (int) round( $ev_yen ),
		'saved_yen'       => (int) round( $saved ),
		'saved_yen_per_h' => (int) round( $saved_yen_h ),
		'yen_per_l'       => $yen_per_l,
		'km_per_l'        => $km_per_l,
		'elec_yen_per_kwh'=> round( $elec_yen, 1 ),
		'as_of'           => (string) ( $price['as_of'] ?? '' ),
		'price_label'     => sprintf(
			/* translators: 1: city, 2: yen per liter */
			__( '%1$s %2$s 円/L', 'gaming-hub' ),
			__( '多治見', 'gaming-hub' ),
			number_format_i18n( $yen_per_l, 1 )
		),
	);
}
