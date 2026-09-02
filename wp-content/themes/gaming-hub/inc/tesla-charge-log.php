<?php
/**
 * Tesla charge session history (home AC + Supercharger).
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_TESLA_CHARGE_LOG_OPTION', 'gaming_hub_tesla_charge_sessions_v1' );
define( 'GAMING_HUB_TESLA_CHARGE_LOG_MAX', 90 );
define( 'GAMING_HUB_TESLA_CHARGE_HISTORY_SYNC_KEY', 'gaming_hub_tesla_charge_history_sync' );
define( 'GAMING_HUB_TESLA_CHARGE_HISTORY_SYNC_TTL', 6 * HOUR_IN_SECONDS );

/**
 * Load stored charge sessions (newest first).
 *
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_tesla_charge_log_sessions() {
	$raw = get_option( GAMING_HUB_TESLA_CHARGE_LOG_OPTION, array() );
	if ( ! is_array( $raw ) ) {
		return array();
	}

	$out = array();
	foreach ( $raw as $row ) {
		if ( is_array( $row ) ) {
			$out[] = $row;
		}
	}

	return $out;
}

/**
 * Persist charge sessions (newest first, capped).
 *
 * @param array<int, array<string, mixed>> $sessions Sessions.
 */
function gaming_hub_tesla_charge_log_save( array $sessions ) {
	$sessions = array_values( array_slice( $sessions, 0, GAMING_HUB_TESLA_CHARGE_LOG_MAX ) );
	update_option( GAMING_HUB_TESLA_CHARGE_LOG_OPTION, $sessions, false );
}

/**
 * Shape one session for the UI / REST.
 *
 * @param array<string, mixed> $row Raw session.
 * @return array<string, mixed>
 */
function gaming_hub_tesla_charge_log_shape( array $row ) {
	$start_ts = (int) ( $row['start_ts'] ?? 0 );
	$end_ts   = (int) ( $row['end_ts'] ?? 0 );
	$kwh      = max( 0, (float) ( $row['kwh'] ?? 0 ) );
	$yen_raw = $row['yen'] ?? null;
	$supply  = (string) ( $row['supply'] ?? 'home' );
	if ( 'supercharger' !== $supply && 'home' !== $supply ) {
		$supply = 'home';
	}
	// Home LOOOP yen is always known when present; Supercharger yen is known after Fleet history sync (incl. ¥0 free).
	$yen_known = null !== $yen_raw && is_numeric( $yen_raw );
	if ( 'supercharger' === $supply && empty( $row['fleet_session_id'] ) && ! $yen_known ) {
		$yen_known = false;
		$yen_raw   = null;
	}
	$yen = $yen_known ? max( 0, (int) round( (float) $yen_raw ) ) : null;
	$minutes  = ( $start_ts > 0 && $end_ts > $start_ts )
		? (int) max( 1, round( ( $end_ts - $start_ts ) / MINUTE_IN_SECONDS ) )
		: (int) ( $row['duration_min'] ?? 0 );

	$start_soc = isset( $row['start_soc'] ) && is_numeric( $row['start_soc'] )
		? max( 0, min( 100, (int) round( (float) $row['start_soc'] ) ) )
		: null;
	$end_soc = isset( $row['end_soc'] ) && is_numeric( $row['end_soc'] )
		? max( 0, min( 100, (int) round( (float) $row['end_soc'] ) ) )
		: null;
	$limit = isset( $row['limit_soc'] ) && is_numeric( $row['limit_soc'] )
		? max( 0, min( 100, (int) round( (float) $row['limit_soc'] ) ) )
		: null;

	$yen_per_kwh = ( $yen_known && $kwh >= 0.05 ) ? round( $yen / $kwh, 1 ) : null;

	$range = '';
	if ( null !== $start_soc && null !== $end_soc ) {
		$range = $start_soc . '% → ' . $end_soc . '%';
	} elseif ( null !== $end_soc ) {
		$range = $end_soc . '%';
	}

	$when = '';
	if ( $start_ts > 0 ) {
		$when = wp_date( 'n/j H:i', $start_ts );
		if ( $end_ts > $start_ts ) {
			$same_day = wp_date( 'Y-m-d', $start_ts ) === wp_date( 'Y-m-d', $end_ts );
			$when    .= '–' . wp_date( $same_day ? 'H:i' : 'n/j H:i', $end_ts );
		}
	}

	$duration = '';
	if ( $minutes >= 60 ) {
		$h = (int) floor( $minutes / 60 );
		$m = $minutes % 60;
		$duration = $m > 0
			? sprintf(
				/* translators: 1: hours, 2: minutes */
				__( '%1$s時間%2$s分', 'gaming-hub' ),
				number_format_i18n( $h ),
				number_format_i18n( $m )
			)
			: sprintf(
				/* translators: %s: hours */
				__( '%s時間', 'gaming-hub' ),
				number_format_i18n( $h )
			);
	} elseif ( $minutes > 0 ) {
		$duration = sprintf(
			/* translators: %s: minutes */
			__( '%s分', 'gaming-hub' ),
			number_format_i18n( $minutes )
		);
	}

	$supply_label = 'supercharger' === $supply
		? __( '急速充電', 'gaming-hub' )
		: __( '自宅充電', 'gaming-hub' );

	return array(
		'id'             => (string) ( $row['id'] ?? (string) $start_ts ),
		'start_ts'       => $start_ts,
		'end_ts'         => $end_ts,
		'start_date'     => (string) ( $row['start_date'] ?? ( $start_ts ? wp_date( 'Y-m-d', $start_ts ) : '' ) ),
		'end_date'       => (string) ( $row['end_date'] ?? ( $end_ts ? wp_date( 'Y-m-d', $end_ts ) : '' ) ),
		'kwh'            => round( $kwh, 2 ),
		'yen'            => $yen,
		'yen_known'      => $yen_known,
		'yen_per_kwh'    => $yen_per_kwh,
		'start_soc'      => $start_soc,
		'end_soc'        => $end_soc,
		'limit_soc'      => $limit,
		'duration_min'   => $minutes,
		'when_label'     => $when,
		'range_label'    => $range,
		'duration_label' => $duration,
		'supply'         => $supply,
		'supply_label'   => $supply_label,
		'site_name'      => (string) ( $row['site_name'] ?? '' ),
		'fleet_session_id' => (string) ( $row['fleet_session_id'] ?? '' ),
		'peak_w'         => isset( $row['peak_w'] ) && is_numeric( $row['peak_w'] )
			? max( 0, (int) round( (float) $row['peak_w'] ) )
			: null,
		'active'         => ! empty( $row['active'] ),
	);
}

/**
 * Archive a finished charge session from wall/super energy counters.
 *
 * @param array<string, mixed> $saved   Energy option row (pre-clear).
 * @param array<string, mixed> $meta    Optional end meta (soc, limit_soc).
 * @param string               $supply  home|supercharger.
 */
function gaming_hub_tesla_charge_log_archive_session( array $saved, array $meta = array(), $supply = 'home' ) {
	$supply = 'supercharger' === $supply ? 'supercharger' : 'home';
	$kwh    = max( 0, (float) ( $saved['session_wh'] ?? 0 ) ) / 1000.0;
	$yen    = max( 0, (float) ( $saved['session_yen'] ?? 0 ) );

	// Ignore false starts / tiny trickle noise.
	if ( $kwh < 0.05 && ( 'supercharger' === $supply || $yen < 1 ) ) {
		return;
	}

	$now      = time();
	$start_ts = isset( $saved['session_start_ts'] ) ? (int) $saved['session_start_ts'] : 0;
	if ( $start_ts <= 0 ) {
		$start_ts = isset( $saved['last_ts'] ) ? (int) $saved['last_ts'] : $now;
	}
	$end_ts = $now;

	$start_soc = isset( $saved['session_start_soc'] ) && is_numeric( $saved['session_start_soc'] )
		? (int) round( (float) $saved['session_start_soc'] )
		: null;
	$end_soc = isset( $meta['soc'] ) && is_numeric( $meta['soc'] )
		? (int) round( (float) $meta['soc'] )
		: ( isset( $saved['session_end_soc'] ) && is_numeric( $saved['session_end_soc'] )
			? (int) round( (float) $saved['session_end_soc'] )
			: null );
	$limit = isset( $meta['limit_soc'] ) && is_numeric( $meta['limit_soc'] )
		? (int) round( (float) $meta['limit_soc'] )
		: ( isset( $saved['session_limit_soc'] ) && is_numeric( $saved['session_limit_soc'] )
			? (int) round( (float) $saved['session_limit_soc'] )
			: null );

	$id_prefix = 'supercharger' === $supply ? 's' : 'c';
	$session   = array(
		'id'         => $id_prefix . $start_ts,
		'start_ts'   => $start_ts,
		'end_ts'     => $end_ts,
		'start_date' => (string) ( $saved['session_date'] ?? wp_date( 'Y-m-d', $start_ts ) ),
		'end_date'   => (string) ( $saved['session_end_date'] ?? wp_date( 'Y-m-d', $end_ts ) ),
		'kwh'        => round( $kwh, 2 ),
		'yen'        => 'supercharger' === $supply ? null : round( $yen, 2 ),
		'start_soc'  => $start_soc,
		'end_soc'    => $end_soc,
		'limit_soc'  => $limit,
		'supply'     => $supply,
		'peak_w'     => isset( $saved['session_peak_w'] ) ? max( 0, (int) $saved['session_peak_w'] ) : null,
	);

	$sessions = gaming_hub_tesla_charge_log_sessions();

	// Deduplicate if the same start was archived twice (double poll).
	if ( isset( $sessions[0] ) && (string) ( $sessions[0]['id'] ?? '' ) === $session['id'] ) {
		$sessions[0] = $session;
	} else {
		array_unshift( $sessions, $session );
	}

	gaming_hub_tesla_charge_log_save( $sessions );
}

/**
 * Archive a finished home AC charge session from wall-energy counters.
 *
 * @param array<string, mixed> $saved Wall-energy option row (pre-clear).
 * @param array<string, mixed> $meta  Optional end meta (soc, limit_soc).
 */
function gaming_hub_tesla_charge_log_archive_from_wall( array $saved, array $meta = array() ) {
	gaming_hub_tesla_charge_log_archive_session( $saved, $meta, 'home' );
}

/**
 * Live / in-progress charge slice (home or Supercharger).
 *
 * @return array<string, mixed>|null
 */
function gaming_hub_tesla_charge_log_current() {
	$candidates = array(
		array(
			'key'    => defined( 'GAMING_HUB_TESLA_SUPER_ENERGY_OPTION' ) ? GAMING_HUB_TESLA_SUPER_ENERGY_OPTION : '',
			'supply' => 'supercharger',
			'id'     => 'live-super',
		),
		array(
			'key'    => defined( 'GAMING_HUB_TESLA_WALL_ENERGY_OPTION' ) ? GAMING_HUB_TESLA_WALL_ENERGY_OPTION : '',
			'supply' => 'home',
			'id'     => 'live-home',
		),
	);

	foreach ( $candidates as $cand ) {
		if ( '' === $cand['key'] ) {
			continue;
		}
		$saved = get_option( $cand['key'], array() );
		if ( ! is_array( $saved ) || empty( $saved['last_on'] ) ) {
			continue;
		}

		$kwh = max( 0, (float) ( $saved['session_wh'] ?? 0 ) ) / 1000.0;
		$yen = max( 0, (float) ( $saved['session_yen'] ?? 0 ) );
		$start_ts = isset( $saved['session_start_ts'] ) ? (int) $saved['session_start_ts'] : 0;
		if ( $start_ts <= 0 ) {
			$start_ts = isset( $saved['last_ts'] ) ? (int) $saved['last_ts'] : time();
		}

		$row = array(
			'id'         => $cand['id'],
			'start_ts'   => $start_ts,
			'end_ts'     => time(),
			'start_date' => (string) ( $saved['session_date'] ?? wp_date( 'Y-m-d', $start_ts ) ),
			'end_date'   => wp_date( 'Y-m-d' ),
			'kwh'        => $kwh,
			'yen'        => 'supercharger' === $cand['supply'] ? null : $yen,
			'start_soc'  => $saved['session_start_soc'] ?? null,
			'end_soc'    => $saved['session_end_soc'] ?? null,
			'limit_soc'  => $saved['session_limit_soc'] ?? null,
			'supply'     => $cand['supply'],
			'peak_w'     => $saved['session_peak_w'] ?? null,
			'active'     => true,
		);

		return gaming_hub_tesla_charge_log_shape( $row );
	}

	return null;
}

/**
 * Sum known billing yen for a supply type on one calendar day (archived sessions).
 *
 * @param string $supply home|supercharger.
 * @param string $date   Y-m-d (empty = today).
 * @return array{yen: int, yen_known: bool}
 */
function gaming_hub_tesla_charge_log_supply_yen_on_date( $supply, $date = '' ) {
	$supply = 'supercharger' === $supply ? 'supercharger' : 'home';
	$date   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ? (string) $date : wp_date( 'Y-m-d' );
	$yen    = 0;
	$known  = false;

	foreach ( gaming_hub_tesla_charge_log_sessions() as $row ) {
		if ( (string) ( $row['supply'] ?? 'home' ) !== $supply ) {
			continue;
		}
		if ( (string) ( $row['end_date'] ?? '' ) !== $date ) {
			continue;
		}

		$shaped = gaming_hub_tesla_charge_log_shape( $row );
		if ( empty( $shaped['yen_known'] ) ) {
			continue;
		}

		$known = true;
		$yen  += (int) ( $shaped['yen'] ?? 0 );
	}

	return array(
		'yen'       => $yen,
		'yen_known' => $known,
	);
}

/**
 * Sum Supercharger fees into yen (JPY only).
 *
 * @param mixed $fees fees[] from charging history.
 * @return int|null Yen total, or null when currency is not JPY / missing.
 */
function gaming_hub_tesla_charge_history_fee_yen( $fees ) {
	if ( ! is_array( $fees ) ) {
		return null;
	}

	$total    = 0.0;
	$saw_jpy  = false;
	$saw_any  = false;

	foreach ( $fees as $fee ) {
		if ( ! is_array( $fee ) ) {
			continue;
		}
		$type = strtoupper( (string) ( $fee['feeType'] ?? '' ) );
		if ( ! in_array( $type, array( 'CHARGING', 'PARKING', 'IDLE', 'CONGESTION' ), true ) ) {
			continue;
		}
		$currency = strtoupper( (string) ( $fee['currencyCode'] ?? '' ) );
		if ( 'JPY' !== $currency && 'YEN' !== $currency && '円' !== $currency ) {
			// Non-JPY invoice — skip pricing rather than mis-label as yen.
			if ( '' !== $currency ) {
				return null;
			}
			continue;
		}
		$amount = null;
		foreach ( array( 'netDue', 'totalDue', 'totalBase' ) as $key ) {
			if ( isset( $fee[ $key ] ) && is_numeric( $fee[ $key ] ) ) {
				$amount = (float) $fee[ $key ];
				break;
			}
		}
		if ( null === $amount ) {
			continue;
		}
		$saw_any = true;
		$saw_jpy = true;
		$total  += $amount;
	}

	if ( ! $saw_jpy && ! $saw_any ) {
		// Free Supercharging often has NO_CHARGE / zero fees — treat as ¥0 when fees array exists.
		foreach ( $fees as $fee ) {
			if ( ! is_array( $fee ) ) {
				continue;
			}
			$pricing = strtoupper( (string) ( $fee['pricingType'] ?? '' ) );
			if ( 'NO_CHARGE' === $pricing || 'FREE' === $pricing ) {
				return 0;
			}
		}

		return null;
	}

	return (int) round( max( 0, $total ) );
}

/**
 * Parse one Fleet charging_history row into a normalized event.
 *
 * @param array<string, mixed> $row Raw history row.
 * @return array<string, mixed>|null
 */
function gaming_hub_tesla_charge_history_parse_event( array $row ) {
	$start_raw = (string) ( $row['chargeStartDateTime'] ?? $row['startDateTime'] ?? $row['start_time'] ?? '' );
	$stop_raw  = (string) ( $row['chargeStopDateTime'] ?? $row['unlatchDateTime'] ?? $row['stopDateTime'] ?? $row['end_time'] ?? '' );
	$start_ts  = $start_raw ? (int) strtotime( $start_raw ) : 0;
	$end_ts    = $stop_raw ? (int) strtotime( $stop_raw ) : 0;
	if ( $start_ts <= 0 ) {
		return null;
	}
	if ( $end_ts < $start_ts ) {
		$end_ts = $start_ts;
	}

	$session_id = (string) ( $row['sessionId'] ?? $row['chargeSessionId'] ?? $row['id'] ?? '' );
	$vin        = strtoupper( (string) ( $row['vin'] ?? '' ) );
	$site       = (string) ( $row['siteLocationName'] ?? $row['siteLocalizedName'] ?? $row['locationName'] ?? '' );

	$kwh = null;
	foreach ( array( 'energyAdded', 'energyUsed', 'chargeSessionEnergyAdded', 'sessionEnergy', 'kwh' ) as $key ) {
		if ( isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) ) {
			$val = (float) $row[ $key ];
			// Some payloads use Wh.
			if ( $val > 200 && $val < 200000 ) {
				$val = $val / 1000;
			}
			if ( $val >= 0 && $val < 200 ) {
				$kwh = $val;
				break;
			}
		}
	}

	$yen = gaming_hub_tesla_charge_history_fee_yen( $row['fees'] ?? null );
	if ( null === $yen ) {
		foreach ( array( 'chargeCost', 'totalCost', 'cost', 'amountDue' ) as $key ) {
			if ( isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) ) {
				$currency = strtoupper( (string) ( $row['currencyCode'] ?? $row['currency'] ?? 'JPY' ) );
				if ( in_array( $currency, array( 'JPY', 'YEN', '' ), true ) ) {
					$yen = (int) round( max( 0, (float) $row[ $key ] ) );
				}
				break;
			}
		}
	}

	return array(
		'session_id' => $session_id,
		'vin'        => $vin,
		'start_ts'   => $start_ts,
		'end_ts'     => $end_ts,
		'start_date' => wp_date( 'Y-m-d', $start_ts ),
		'end_date'   => wp_date( 'Y-m-d', $end_ts ),
		'kwh'        => null !== $kwh ? round( $kwh, 2 ) : null,
		'yen'        => $yen,
		'site_name'  => $site,
	);
}

/**
 * Whether a local session overlaps a Fleet history event.
 *
 * @param array<string, mixed> $session Local session.
 * @param array<string, mixed> $event   Parsed Fleet event.
 */
function gaming_hub_tesla_charge_log_session_matches_event( array $session, array $event ) {
	if ( 'supercharger' !== (string) ( $session['supply'] ?? '' ) ) {
		return false;
	}

	$fleet_id = (string) ( $session['fleet_session_id'] ?? '' );
	if ( '' !== $fleet_id && '' !== (string) ( $event['session_id'] ?? '' ) && $fleet_id === (string) $event['session_id'] ) {
		return true;
	}

	$s_start = (int) ( $session['start_ts'] ?? 0 );
	$s_end   = (int) ( $session['end_ts'] ?? $s_start );
	$e_start = (int) ( $event['start_ts'] ?? 0 );
	$e_end   = (int) ( $event['end_ts'] ?? $e_start );
	if ( $s_start <= 0 || $e_start <= 0 ) {
		return false;
	}

	$pad = 20 * MINUTE_IN_SECONDS;
	$overlap = min( $s_end + $pad, $e_end + $pad ) - max( $s_start - $pad, $e_start - $pad );

	return $overlap > 0;
}

/**
 * Apply Fleet charging history costs onto local Supercharger sessions (and import missing ones).
 *
 * @param bool $force Bypass sync throttle.
 * @return bool True when a sync attempt ran.
 */
function gaming_hub_tesla_charge_log_sync_from_fleet( $force = false ) {
	if ( ! $force && get_transient( GAMING_HUB_TESLA_CHARGE_HISTORY_SYNC_KEY ) ) {
		return false;
	}

	set_transient( GAMING_HUB_TESLA_CHARGE_HISTORY_SYNC_KEY, 1, GAMING_HUB_TESLA_CHARGE_HISTORY_SYNC_TTL );

	if ( ! function_exists( 'gaming_hub_tesla_has_charging_scope' ) || ! gaming_hub_tesla_has_charging_scope() ) {
		return false;
	}

	if ( ! function_exists( 'gaming_hub_tesla_get_api' ) || ! function_exists( 'gaming_hub_get_tesla_config' ) ) {
		return false;
	}

	$api = gaming_hub_tesla_get_api();
	if ( is_wp_error( $api ) ) {
		return false;
	}

	$config = gaming_hub_get_tesla_config();
	$vin    = strtoupper( (string) ( $config['vehicle_vin'] ?? '' ) );
	$query  = array(
		'pageSize' => 50,
		'pageNo'   => 1,
	);
	if ( '' !== $vin ) {
		$query['vin'] = $vin;
	}

	$history = $api->get_charging_history( $query );
	if ( is_wp_error( $history ) ) {
		return false;
	}

	$events = array();
	foreach ( (array) ( $history['data'] ?? array() ) as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$event = gaming_hub_tesla_charge_history_parse_event( $row );
		if ( ! $event ) {
			continue;
		}
		if ( '' !== $vin && '' !== $event['vin'] && $event['vin'] !== $vin ) {
			continue;
		}
		$events[] = $event;
	}

	if ( empty( $events ) ) {
		return true;
	}

	$sessions = gaming_hub_tesla_charge_log_sessions();
	$changed  = false;
	$matched  = array();

	foreach ( $sessions as $i => $session ) {
		if ( ! is_array( $session ) || 'supercharger' !== (string) ( $session['supply'] ?? '' ) ) {
			continue;
		}
		foreach ( $events as $ei => $event ) {
			if ( ! empty( $matched[ $ei ] ) ) {
				continue;
			}
			if ( ! gaming_hub_tesla_charge_log_session_matches_event( $session, $event ) ) {
				continue;
			}
			$matched[ $ei ] = true;
			if ( null !== $event['yen'] ) {
				$session['yen'] = $event['yen'];
			}
			if ( null !== $event['kwh'] && (float) $event['kwh'] > 0 ) {
				$session['kwh'] = $event['kwh'];
			}
			if ( '' !== $event['site_name'] ) {
				$session['site_name'] = $event['site_name'];
			}
			if ( '' !== $event['session_id'] ) {
				$session['fleet_session_id'] = $event['session_id'];
			}
			$sessions[ $i ] = $session;
			$changed        = true;
			break;
		}
	}

	foreach ( $events as $ei => $event ) {
		if ( ! empty( $matched[ $ei ] ) ) {
			continue;
		}
		// Skip tiny / incomplete events.
		if ( ( null === $event['kwh'] || $event['kwh'] < 0.05 ) && ( null === $event['yen'] || $event['yen'] <= 0 ) ) {
			continue;
		}

		$id = '' !== $event['session_id'] ? ( 'f' . $event['session_id'] ) : ( 'f' . $event['start_ts'] );
		$exists = false;
		foreach ( $sessions as $session ) {
			if ( (string) ( $session['id'] ?? '' ) === $id || (string) ( $session['fleet_session_id'] ?? '' ) === (string) $event['session_id'] ) {
				$exists = true;
				break;
			}
		}
		if ( $exists ) {
			continue;
		}

		array_unshift(
			$sessions,
			array(
				'id'               => $id,
				'start_ts'         => $event['start_ts'],
				'end_ts'           => $event['end_ts'],
				'start_date'       => $event['start_date'],
				'end_date'         => $event['end_date'],
				'kwh'              => null !== $event['kwh'] ? $event['kwh'] : 0,
				'yen'              => $event['yen'],
				'start_soc'        => null,
				'end_soc'          => null,
				'limit_soc'        => null,
				'supply'           => 'supercharger',
				'site_name'        => $event['site_name'],
				'fleet_session_id' => $event['session_id'],
				'peak_w'           => null,
			)
		);
		$changed = true;
	}

	if ( $changed ) {
		usort(
			$sessions,
			static function ( $a, $b ) {
				return (int) ( $b['start_ts'] ?? 0 ) <=> (int) ( $a['start_ts'] ?? 0 );
			}
		);
		gaming_hub_tesla_charge_log_save( $sessions );
	}

	return true;
}

/**
 * Month payload for the charge session history UI.
 *
 * @param string $ym Y-m (empty = this month).
 * @return array<string, mixed>
 */
function gaming_hub_tesla_charge_log_payload( $ym = '' ) {
	gaming_hub_tesla_charge_log_sync_from_fleet();

	if ( ! preg_match( '/^\d{4}-\d{2}$/', (string) $ym ) ) {
		$ym = wp_date( 'Y-m' );
	}

	$parts = explode( '-', $ym );
	$y     = (int) $parts[0];
	$m     = (int) $parts[1];
	$prev  = wp_date( 'Y-m', strtotime( sprintf( '%04d-%02d-15 -1 month', $y, $m ) ) );
	$next  = wp_date( 'Y-m', strtotime( sprintf( '%04d-%02d-15 +1 month', $y, $m ) ) );

	$all         = gaming_hub_tesla_charge_log_sessions();
	$sessions    = array();
	$total_kwh   = 0.0;
	$total_yen   = 0;
	$home_kwh    = 0.0;
	$home_yen    = 0;
	$super_kwh   = 0.0;
	$super_count = 0;
	$super_yen   = 0;
	$home_count  = 0;

	foreach ( $all as $row ) {
		$end   = (string) ( $row['end_date'] ?? '' );
		$start = (string) ( $row['start_date'] ?? '' );
		$in_month = ( 0 === strpos( $end, $ym ) ) || ( '' === $end && 0 === strpos( $start, $ym ) );
		if ( ! $in_month ) {
			continue;
		}
		$shaped     = gaming_hub_tesla_charge_log_shape( $row );
		$sessions[] = $shaped;
		$total_kwh += (float) $shaped['kwh'];
		if ( 'supercharger' === $shaped['supply'] ) {
			$super_kwh += (float) $shaped['kwh'];
			if ( ! empty( $shaped['yen_known'] ) ) {
				$super_yen += (int) ( $shaped['yen'] ?? 0 );
				$total_yen += (int) ( $shaped['yen'] ?? 0 );
			}
			++$super_count;
		} else {
			$home_kwh += (float) $shaped['kwh'];
			$home_yen += (int) ( $shaped['yen'] ?? 0 );
			$total_yen += (int) ( $shaped['yen'] ?? 0 );
			++$home_count;
		}
	}

	$label = sprintf(
		/* translators: 1: year, 2: month */
		__( '%1$s年%2$s月', 'gaming-hub' ),
		(string) $y,
		(string) $m
	);

	return array(
		'month'    => $ym,
		'label'    => $label,
		'prev'     => $prev,
		'next'     => $next,
		'today'    => wp_date( 'Y-m-d' ),
		'sessions' => $sessions,
		'current'  => gaming_hub_tesla_charge_log_current(),
		'totals'   => array(
			'count'       => count( $sessions ),
			'kwh'         => round( $total_kwh, 2 ),
			'yen'         => $total_yen,
			'home_count'  => $home_count,
			'home_kwh'    => round( $home_kwh, 2 ),
			'home_yen'    => $home_yen,
			'super_count' => $super_count,
			'super_kwh'   => round( $super_kwh, 2 ),
			'super_yen'   => $super_yen,
		),
	);
}

/**
 * Render charge session history on the Tesla tag.
 *
 * @param array<string, mixed>|null $status Unused; kept for call-site symmetry.
 */
function gaming_hub_render_tesla_charge_log( $status = null ) {
	unset( $status );
	get_template_part(
		'template-parts/tesla',
		'charge-log',
		array(
			'log' => gaming_hub_tesla_charge_log_payload( wp_date( 'Y-m' ) ),
		)
	);
}

/**
 * REST: GET /gaming-hub/v1/tesla/charges
 */
function gaming_hub_register_tesla_charge_rest() {
	register_rest_route(
		'gaming-hub/v1',
		'/tesla/charges',
		array(
			'methods'             => 'GET',
			'callback'            => 'gaming_hub_rest_tesla_charges',
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
add_action( 'rest_api_init', 'gaming_hub_register_tesla_charge_rest' );

/**
 * REST callback for charge session history.
 *
 * @param WP_REST_Request $request Request.
 */
function gaming_hub_rest_tesla_charges( WP_REST_Request $request ) {
	$month = (string) $request->get_param( 'month' );

	return new WP_REST_Response(
		array(
			'success' => true,
			'data'    => gaming_hub_tesla_charge_log_payload( $month ),
		),
		200
	);
}

/**
 * Enqueue Tesla charge-log script.
 */
function gaming_hub_tesla_charge_log_scripts() {
	if ( ! is_tag( 'tesla' ) && ! is_page( 'powerwall' ) ) {
		return;
	}

	wp_enqueue_script(
		'gaming-hub-tesla-charge-log',
		get_template_directory_uri() . '/assets/js/tesla-charge-log.js',
		array( 'gaming-hub-i18n' ),
		GAMING_HUB_VERSION,
		true
	);

	wp_localize_script(
		'gaming-hub-tesla-charge-log',
		'gamingHubTeslaCharge',
		array(
			'url' => (string) wp_parse_url( rest_url( 'gaming-hub/v1/tesla/charges' ), PHP_URL_PATH ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'gaming_hub_tesla_charge_log_scripts' );
