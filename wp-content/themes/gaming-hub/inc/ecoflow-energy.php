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
	$input_w  = $site['input'];
	$output_w = $site['output'];
	$solar_w  = $site['solar'];
	$ac_in_w  = $site['ac_in'];
	$soc      = isset( $status['battery_percent'] ) && null !== $status['battery_percent']
		? max( 0, min( 100, (float) $status['battery_percent'] ) )
		: null;

	$last_ts = isset( $state['ts'] ) ? (int) $state['ts'] : 0;
	$dt      = $last_ts > 0 ? max( 0, $now_ts - $last_ts ) : 0;
	$dt      = min( GAMING_HUB_ECOFLOW_ENERGY_MAX_DT, $dt );

	if ( $last_ts > 0 && $dt < GAMING_HUB_ECOFLOW_ENERGY_MIN_DT ) {
		return;
	}

	$add = array(
		'input_wh'  => 0.0,
		'output_wh' => 0.0,
		'solar_wh'  => 0.0,
		'ac_in_wh'  => 0.0,
	);
	if ( $dt > 0 ) {
		$hours = $dt / 3600.0;
		$add['input_wh']  = ( ( (float) ( $state['input_w'] ?? $input_w ) + $input_w ) / 2 ) * $hours;
		$add['output_wh'] = ( ( (float) ( $state['output_w'] ?? $output_w ) + $output_w ) / 2 ) * $hours;
		$add['solar_wh']  = ( ( (float) ( $state['solar_w'] ?? $solar_w ) + $solar_w ) / 2 ) * $hours;
		$add['ac_in_wh']  = ( ( (float) ( $state['ac_in_w'] ?? $ac_in_w ) + $ac_in_w ) / 2 ) * $hours;
	}
	if ( null !== $soc ) {
		$add['soc'] = $soc;
	}

	if ( $dt > 0 || null !== $soc ) {
		gaming_hub_ecoflow_energy_add( $date, $hour, $add );
	}

	set_transient(
		GAMING_HUB_ECOFLOW_ENERGY_STATE,
		array(
			'ts'       => $now_ts,
			'date'     => $date,
			'hour'     => $hour,
			'input_w'  => $input_w,
			'output_w' => $output_w,
			'solar_w'  => $solar_w,
			'ac_in_w'  => $ac_in_w,
			'soc'      => $soc,
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
			'input_wh'  => 0.0,
			'output_wh' => 0.0,
			'solar_wh'  => 0.0,
			'ac_in_wh'  => 0.0,
			'soc'       => null,
			'yen'       => gaming_hub_ecoflow_energy_hour_price( $h ),
		);
	}

	foreach ( array( 'input_wh', 'output_wh', 'solar_wh', 'ac_in_wh' ) as $field ) {
		$delta                 = max( 0, (float) ( $add[ $field ] ?? 0 ) );
		$day['hours'][ $h ][ $field ] = (float) $day['hours'][ $h ][ $field ] + $delta;
		$day[ $field ]         = (float) ( $day[ $field ] ?? 0 ) + $delta;
	}

	if ( empty( $day['hours'][ $h ]['yen'] ) ) {
		$day['hours'][ $h ]['yen'] = gaming_hub_ecoflow_energy_hour_price( $h );
	}

	if ( isset( $add['soc'] ) && is_numeric( $add['soc'] ) ) {
		$day['hours'][ $h ]['soc'] = round( max( 0, min( 100, (float) $add['soc'] ) ), 1 );
	}

	$day['samples']    = (int) ( $day['samples'] ?? 0 ) + 1;
	$day['updated_at'] = wp_date( 'c' );
	$day['saved_yen']  = gaming_hub_ecoflow_energy_saved_yen( $day );
	$days[ $date ]     = $day;

	update_option( $key, $days, false );
}

/**
 * Today's logged hour buckets.
 *
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_ecoflow_energy_today_hours() {
	$date  = wp_date( 'Y-m-d' );
	$log   = gaming_hub_ecoflow_energy_month_days( substr( $date, 0, 7 ) );
	$hours = $log[ $date ]['hours'] ?? array();

	return is_array( $hours ) ? $hours : array();
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
 * Today's last sampled Pro SOC % by hour.
 *
 * @return array<int, float|null>
 */
function gaming_hub_ecoflow_energy_today_soc_hours() {
	$hours = gaming_hub_ecoflow_energy_today_hours();
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
 * Empty day record.
 *
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_energy_empty_day() {
	return array(
		'input_wh'  => 0.0,
		'output_wh' => 0.0,
		'solar_wh'  => 0.0,
		'ac_in_wh'  => 0.0,
		'saved_yen' => 0.0,
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
 * Avoided retail cost of measured solar generation.
 *
 * @param array<string, mixed> $day Day record.
 */
function gaming_hub_ecoflow_energy_saved_yen( array $day ) {
	$saved = 0.0;
	$hours = is_array( $day['hours'] ?? null ) ? $day['hours'] : array();

	if ( $hours ) {
		foreach ( $hours as $row ) {
			$kwh  = max( 0, (float) ( $row['solar_wh'] ?? 0 ) ) / 1000.0;
			$yen  = (float) ( $row['yen'] ?? 0 );
			$saved += $kwh * $yen;
		}

		return round( $saved, 1 );
	}

	$avg = gaming_hub_ecoflow_energy_hour_price( (int) wp_date( 'G' ) );
	return round( max( 0, (float) ( $day['solar_wh'] ?? 0 ) ) / 1000.0 * $avg, 1 );
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
	);
}

/**
 * Attach current-month energy onto a status payload.
 *
 * @param array<string, mixed> $status Status.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_energy_attach( array $status ) {
	$status['energy'] = gaming_hub_ecoflow_energy_month_payload( wp_date( 'Y-m' ), $status );
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
}
add_action( 'rest_api_init', 'gaming_hub_register_ecoflow_energy_rest' );

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
