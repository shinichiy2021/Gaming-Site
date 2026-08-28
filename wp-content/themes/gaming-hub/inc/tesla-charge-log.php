<?php
/**
 * Tesla home AC charge session history.
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_TESLA_CHARGE_LOG_OPTION', 'gaming_hub_tesla_charge_sessions_v1' );
define( 'GAMING_HUB_TESLA_CHARGE_LOG_MAX', 60 );

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
	$yen      = max( 0, (int) round( (float) ( $row['yen'] ?? 0 ) ) );
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

	$yen_per_kwh = $kwh >= 0.05 ? round( $yen / $kwh, 1 ) : null;

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

	return array(
		'id'           => (string) ( $row['id'] ?? (string) $start_ts ),
		'start_ts'     => $start_ts,
		'end_ts'       => $end_ts,
		'start_date'   => (string) ( $row['start_date'] ?? ( $start_ts ? wp_date( 'Y-m-d', $start_ts ) : '' ) ),
		'end_date'     => (string) ( $row['end_date'] ?? ( $end_ts ? wp_date( 'Y-m-d', $end_ts ) : '' ) ),
		'kwh'          => round( $kwh, 2 ),
		'yen'          => $yen,
		'yen_per_kwh'  => $yen_per_kwh,
		'start_soc'    => $start_soc,
		'end_soc'      => $end_soc,
		'limit_soc'    => $limit,
		'duration_min' => $minutes,
		'when_label'   => $when,
		'range_label'  => $range,
		'duration_label' => $duration,
		'supply'       => (string) ( $row['supply'] ?? 'home' ),
		'active'       => ! empty( $row['active'] ),
	);
}

/**
 * Archive a finished home AC charge session from wall-energy counters.
 *
 * @param array<string, mixed> $saved Wall-energy option row (pre-clear).
 * @param array<string, mixed> $meta  Optional end meta (soc, limit_soc).
 */
function gaming_hub_tesla_charge_log_archive_from_wall( array $saved, array $meta = array() ) {
	$kwh = max( 0, (float) ( $saved['session_wh'] ?? 0 ) ) / 1000.0;
	$yen = max( 0, (float) ( $saved['session_yen'] ?? 0 ) );

	// Ignore false starts / tiny trickle noise.
	if ( $kwh < 0.05 && $yen < 1 ) {
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

	$session = array(
		'id'         => 'c' . $start_ts,
		'start_ts'   => $start_ts,
		'end_ts'     => $end_ts,
		'start_date' => (string) ( $saved['session_date'] ?? wp_date( 'Y-m-d', $start_ts ) ),
		'end_date'   => (string) ( $saved['session_end_date'] ?? wp_date( 'Y-m-d', $end_ts ) ),
		'kwh'        => round( $kwh, 2 ),
		'yen'        => round( $yen, 2 ),
		'start_soc'  => $start_soc,
		'end_soc'    => $end_soc,
		'limit_soc'  => $limit,
		'supply'     => 'home',
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
 * Live / in-progress home charge slice from wall-energy counters.
 *
 * @return array<string, mixed>|null
 */
function gaming_hub_tesla_charge_log_current() {
	if ( ! defined( 'GAMING_HUB_TESLA_WALL_ENERGY_OPTION' ) ) {
		return null;
	}

	$saved = get_option( GAMING_HUB_TESLA_WALL_ENERGY_OPTION, array() );
	if ( ! is_array( $saved ) || empty( $saved['last_on'] ) ) {
		return null;
	}

	$kwh = max( 0, (float) ( $saved['session_wh'] ?? 0 ) ) / 1000.0;
	$yen = max( 0, (float) ( $saved['session_yen'] ?? 0 ) );
	$start_ts = isset( $saved['session_start_ts'] ) ? (int) $saved['session_start_ts'] : 0;
	if ( $start_ts <= 0 ) {
		$start_ts = isset( $saved['last_ts'] ) ? (int) $saved['last_ts'] : time();
	}

	$row = array(
		'id'         => 'live',
		'start_ts'   => $start_ts,
		'end_ts'     => time(),
		'start_date' => (string) ( $saved['session_date'] ?? wp_date( 'Y-m-d', $start_ts ) ),
		'end_date'   => wp_date( 'Y-m-d' ),
		'kwh'        => $kwh,
		'yen'        => $yen,
		'start_soc'  => $saved['session_start_soc'] ?? null,
		'end_soc'    => $saved['session_end_soc'] ?? null,
		'limit_soc'  => $saved['session_limit_soc'] ?? null,
		'supply'     => 'home',
		'active'     => true,
	);

	return gaming_hub_tesla_charge_log_shape( $row );
}

/**
 * Month payload for the charge session history UI.
 *
 * @param string $ym Y-m (empty = this month).
 * @return array<string, mixed>
 */
function gaming_hub_tesla_charge_log_payload( $ym = '' ) {
	if ( ! preg_match( '/^\d{4}-\d{2}$/', (string) $ym ) ) {
		$ym = wp_date( 'Y-m' );
	}

	$parts = explode( '-', $ym );
	$y     = (int) $parts[0];
	$m     = (int) $parts[1];
	$prev  = wp_date( 'Y-m', strtotime( sprintf( '%04d-%02d-15 -1 month', $y, $m ) ) );
	$next  = wp_date( 'Y-m', strtotime( sprintf( '%04d-%02d-15 +1 month', $y, $m ) ) );

	$all      = gaming_hub_tesla_charge_log_sessions();
	$sessions = array();
	$total_kwh = 0.0;
	$total_yen = 0;

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
		$total_yen += (int) $shaped['yen'];
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
			'count' => count( $sessions ),
			'kwh'   => round( $total_kwh, 2 ),
			'yen'   => $total_yen,
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
