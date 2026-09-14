<?php
/**
 * Phase 2: Fleet Telemetry → WordPress one-way bridge.
 *
 * POST /wp-json/gaming-hub/v1/tesla/telemetry (bridge token required)
 * merges SOC / charge fields into GAMING_HUB_TESLA_STATUS_CACHE_KEY.
 * Polling remains as fallback (Phase 3 will reduce it).
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared secret for the telemetry bridge container.
 *
 * @return string
 */
function gaming_hub_tesla_telemetry_bridge_token() {
	$token = getenv( 'TESLA_TELEMETRY_BRIDGE_TOKEN' );
	if ( is_string( $token ) && '' !== $token ) {
		return $token;
	}

	return (string) get_option( 'gaming_hub_tesla_telemetry_bridge_token', '' );
}

/**
 * REST permission: bridge token header.
 *
 * @param WP_REST_Request $request Request.
 * @return bool
 */
function gaming_hub_tesla_telemetry_bridge_can( WP_REST_Request $request ) {
	$expected = gaming_hub_tesla_telemetry_bridge_token();
	if ( '' === $expected ) {
		return false;
	}

	$got = (string) $request->get_header( 'X-Gaming-Hub-Telemetry-Token' );
	if ( '' === $got ) {
		$auth = (string) $request->get_header( 'Authorization' );
		if ( 0 === stripos( $auth, 'Bearer ' ) ) {
			$got = trim( substr( $auth, 7 ) );
		}
	}

	return '' !== $got && hash_equals( $expected, $got );
}

/**
 * Strip DetailedChargeState / ChargePortLatch enum prefixes.
 *
 * @param mixed $raw Raw enum/string.
 * @return string
 */
function gaming_hub_tesla_telemetry_strip_enum( $raw ) {
	$s = trim( (string) $raw );
	$s = preg_replace( '/^DetailedChargeState/i', '', $s );
	$s = preg_replace( '/^ChargePortLatch/i', '', $s );
	$s = preg_replace( '/^FastCharger/i', '', $s );

	return is_string( $s ) ? $s : '';
}

/**
 * Map telemetry DetailedChargeState to vehicle_data-style charging_state.
 *
 * @param mixed $raw Enum or string.
 * @return string
 */
function gaming_hub_tesla_telemetry_charge_state( $raw ) {
	$s = gaming_hub_tesla_telemetry_strip_enum( $raw );
	$map = array(
		'Charging'             => 'Charging',
		'Starting'             => 'Starting',
		'Complete'             => 'Complete',
		'Stopped'              => 'Stopped',
		'Disconnected'         => 'Disconnected',
		'NoPower'              => 'NoPower',
		'Enable'               => 'Charging',
		'Idle'                 => 'Stopped',
		'Schedule'             => 'Stopped',
		'Scheduling'           => 'Stopped',
		'Unknown'              => '',
		'SNA'                  => '',
	);

	return $map[ $s ] ?? ( $s !== '' ? $s : '' );
}

/**
 * Whether the latch / charge state implies plugged in.
 *
 * @param string $charge_state Normalized charge state.
 * @param mixed  $latch        ChargePortLatch raw.
 * @return bool|null Null when unknown.
 */
function gaming_hub_tesla_telemetry_plugged( $charge_state, $latch = null ) {
	$latch_s = gaming_hub_tesla_telemetry_strip_enum( $latch );
	if ( in_array( $latch_s, array( 'Engaged', 'Blocking' ), true ) ) {
		return true;
	}
	if ( 'Disengaged' === $latch_s ) {
		return false;
	}

	if ( in_array( $charge_state, array( 'Charging', 'Starting', 'Complete', 'Stopped', 'NoPower' ), true ) ) {
		return true;
	}
	if ( 'Disconnected' === $charge_state ) {
		return false;
	}

	return null;
}

/**
 * Read a numeric field from the telemetry fields map.
 *
 * @param array<string, mixed> $fields Fields.
 * @param string               $key    Key.
 * @return float|null
 */
function gaming_hub_tesla_telemetry_num( array $fields, $key ) {
	if ( ! array_key_exists( $key, $fields ) || null === $fields[ $key ] || '' === $fields[ $key ] ) {
		return null;
	}
	if ( ! is_numeric( $fields[ $key ] ) ) {
		return null;
	}

	return (float) $fields[ $key ];
}

/**
 * Charge watts from AC/DC power (kW) or amps × voltage.
 *
 * @param array<string, mixed> $fields Telemetry fields.
 * @return int|null
 */
function gaming_hub_tesla_telemetry_watts( array $fields ) {
	$ac_kw = gaming_hub_tesla_telemetry_num( $fields, 'ACChargingPower' );
	if ( null !== $ac_kw && $ac_kw > 0.05 ) {
		return (int) round( $ac_kw * 1000 );
	}

	$dc_kw = gaming_hub_tesla_telemetry_num( $fields, 'DCChargingPower' );
	if ( null !== $dc_kw && $dc_kw > 0.05 ) {
		return (int) round( $dc_kw * 1000 );
	}

	$amps = gaming_hub_tesla_telemetry_num( $fields, 'ChargeAmps' );
	$volts = gaming_hub_tesla_telemetry_num( $fields, 'ChargerVoltage' );
	if ( null !== $amps && $amps > 0.5 ) {
		$v = ( null !== $volts && $volts > 50 ) ? $volts : 240.0;

		return (int) round( $amps * $v );
	}

	return null;
}

/**
 * Merge Fleet Telemetry fields into the Model 3 status cache.
 *
 * @param array<string, mixed> $payload Bridge body (vin, fields, received_at).
 * @return array{ok: bool, model3: array<string, mixed>, updated: array<int, string>}|WP_Error
 */
function gaming_hub_tesla_apply_telemetry_payload( array $payload ) {
	$fields = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
	if ( ! $fields ) {
		return new WP_Error( 'tesla_telemetry_empty', 'No telemetry fields in payload.' );
	}

	$expected_vin = strtoupper( (string) ( getenv( 'TESLA_VEHICLE_VIN' ) ?: get_theme_mod( 'tesla_vehicle_vin', '' ) ) );
	$vin          = strtoupper( (string) ( $payload['vin'] ?? '' ) );
	if ( '' !== $expected_vin && '' !== $vin && $expected_vin !== $vin ) {
		return new WP_Error( 'tesla_telemetry_vin', 'VIN does not match configured vehicle.' );
	}

	$cached = get_transient( GAMING_HUB_TESLA_STATUS_CACHE_KEY );
	if ( ! is_array( $cached ) ) {
		$cached = function_exists( 'gaming_hub_powerwall_model3_present' )
			? gaming_hub_powerwall_model3_present( array() )
			: array();
	}

	$updated = array();

	$soc = gaming_hub_tesla_telemetry_num( $fields, 'Soc' );
	if ( null === $soc ) {
		$soc = gaming_hub_tesla_telemetry_num( $fields, 'BatteryLevel' );
	}
	if ( null !== $soc ) {
		$cached['battery_percent'] = max( 0, min( 100, (int) round( $soc ) ) );
		$updated[]                 = 'battery_percent';
	}

	$energy = gaming_hub_tesla_telemetry_num( $fields, 'EnergyRemaining' );
	if ( null !== $energy && $energy >= 0 ) {
		$cached['battery_kwh_estimate'] = round( $energy, 2 );
		$updated[]                      = 'battery_kwh_estimate';
	}

	$limit = gaming_hub_tesla_telemetry_num( $fields, 'ChargeLimitSoc' );
	if ( null !== $limit ) {
		$cached['charge_limit_percent'] = max( 0, min( 100, (int) round( $limit ) ) );
		$updated[]                      = 'charge_limit_percent';
	}

	$charge_state = '';
	if ( isset( $fields['DetailedChargeState'] ) ) {
		$charge_state = gaming_hub_tesla_telemetry_charge_state( $fields['DetailedChargeState'] );
	} elseif ( isset( $fields['ChargeState'] ) ) {
		$charge_state = gaming_hub_tesla_telemetry_charge_state( $fields['ChargeState'] );
	}
	if ( '' !== $charge_state ) {
		$cached['charging_state_raw'] = $charge_state;
		$updated[]                    = 'charging_state_raw';
	}

	$watts = gaming_hub_tesla_telemetry_watts( $fields );
	$charging = in_array( $charge_state, array( 'Charging', 'Starting' ), true );
	if ( null !== $watts && $watts >= 80 ) {
		$charging = true;
	} elseif ( null !== $watts && $watts < 80 && in_array( $charge_state, array( 'Complete', 'Stopped', 'Disconnected', 'NoPower' ), true ) ) {
		$charging = false;
	}

	$cached['is_charging'] = $charging;
	$updated[]             = 'is_charging';

	if ( null !== $watts ) {
		$cached['watts']          = $charging ? max( 0, $watts ) : 0;
		$cached['charge_rate_kw'] = $charging ? round( $watts / 1000, 1 ) : 0;
		$updated[]                = 'watts';
	} elseif ( ! $charging ) {
		$cached['watts']          = 0;
		$cached['charge_rate_kw'] = 0;
	}

	if ( function_exists( 'gaming_hub_tesla_model3_hud_state' ) ) {
		$cached['charge_state'] = gaming_hub_tesla_model3_hud_state( $charge_state, $charging );
		$updated[]              = 'charge_state';
	}

	$plugged = gaming_hub_tesla_telemetry_plugged( $charge_state, $fields['ChargePortLatch'] ?? null );
	if ( null !== $plugged ) {
		$cached['plugged'] = $plugged;
		$updated[]         = 'plugged';
	}

	$fast = null;
	if ( array_key_exists( 'FastChargerPresent', $fields ) ) {
		$fast = filter_var( $fields['FastChargerPresent'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		if ( null === $fast && is_string( $fields['FastChargerPresent'] ) ) {
			$fast = in_array( strtolower( $fields['FastChargerPresent'] ), array( 'true', '1', 'yes' ), true );
		}
	}
	$dc_kw = gaming_hub_tesla_telemetry_num( $fields, 'DCChargingPower' );
	if ( null !== $dc_kw && $dc_kw > 1 ) {
		$fast = true;
	}
	if ( null !== $fast ) {
		$cached['fast_charger_present'] = (bool) $fast;
		$updated[]                      = 'fast_charger_present';
	}

	if ( $charging || ! empty( $cached['plugged'] ) ) {
		if ( ! empty( $cached['fast_charger_present'] ) || ( null !== $dc_kw && $dc_kw > 1 ) ) {
			$cached['supply_kind']  = 'supercharger';
			$cached['supply_label'] = function_exists( '__' ) ? __( 'Supercharger', 'gaming-hub' ) : 'Supercharger';
			$cached['vehicle_mode'] = 'supercharger';
		} else {
			$cached['supply_kind']  = 'wall';
			$cached['supply_label'] = function_exists( 'gaming_hub_tesla_plan_charge_label' )
				? gaming_hub_tesla_plan_charge_label()
				: ( function_exists( '__' ) ? __( 'Home charging', 'gaming-hub' ) : 'Home charging' );
			$cached['vehicle_mode'] = $charging ? 'wall' : ( $cached['vehicle_mode'] ?? 'idle' );
		}
		$updated[] = 'supply_kind';
	} elseif ( ! $charging ) {
		$cached['vehicle_mode'] = $cached['vehicle_mode'] ?? 'idle';
	}

	$ttf = gaming_hub_tesla_telemetry_num( $fields, 'TimeToFullCharge' );
	if ( null !== $ttf && $charging ) {
		$cached['time_to_full_charge_hours'] = max( 0, $ttf );
		$cached['minutes_to_full']           = (int) round( $ttf * 60 );
		$updated[]                           = 'time_to_full_charge_hours';
	} elseif ( ! $charging ) {
		$cached['time_to_full_charge_hours'] = 0;
		$cached['minutes_to_full']           = 0;
	}

	$range = gaming_hub_tesla_telemetry_num( $fields, 'EstBatteryRange' );
	if ( null === $range ) {
		$range = gaming_hub_tesla_telemetry_num( $fields, 'RatedRange' );
	}
	if ( null !== $range && $range > 0 ) {
		// Fleet telemetry ranges are often miles; treat > 800 as already km-ish wrong — prefer miles→km when < 500.
		$km = $range < 500 ? $range * 1.60934 : $range;
		$cached['range_km'] = (int) round( $km );
		$updated[]          = 'range_km';
	}

	$received = isset( $payload['received_at'] ) ? (int) $payload['received_at'] : time();
	$cached['telemetry']    = true;
	$cached['telemetry_at'] = $received > 0 ? $received : time();
	$cached['live']         = true;
	$cached['asleep']       = false;
	$cached['source']       = 'telemetry';

	if ( function_exists( 'gaming_hub_tesla_clear_api_skip' ) ) {
		gaming_hub_tesla_clear_api_skip();
	}

	if ( function_exists( 'gaming_hub_tesla_store_model3' ) ) {
		gaming_hub_tesla_store_model3( $cached );
	} else {
		set_transient( GAMING_HUB_TESLA_STATUS_CACHE_KEY, $cached, GAMING_HUB_TESLA_STATUS_KEEP_TTL );
	}

	if ( defined( 'GAMING_HUB_POWERWALL_FLOW_CACHE_KEY' ) ) {
		delete_transient( GAMING_HUB_POWERWALL_FLOW_CACHE_KEY );
	}

	return array(
		'ok'      => true,
		'model3'  => $cached,
		'updated' => array_values( array_unique( $updated ) ),
	);
}

/**
 * REST: POST /gaming-hub/v1/tesla/telemetry
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function gaming_hub_rest_tesla_telemetry( WP_REST_Request $request ) {
	$body = $request->get_json_params();
	if ( ! is_array( $body ) ) {
		$body = array();
	}

	$result = gaming_hub_tesla_apply_telemetry_payload( $body );
	if ( is_wp_error( $result ) ) {
		return new WP_REST_Response(
			array(
				'success' => false,
				'message' => $result->get_error_message(),
			),
			400
		);
	}

	return new WP_REST_Response(
		array(
			'success' => true,
			'updated' => $result['updated'],
			'soc'     => $result['model3']['battery_percent'] ?? null,
			'charging'=> ! empty( $result['model3']['is_charging'] ),
			'at'      => $result['model3']['telemetry_at'] ?? time(),
		),
		200
	);
}

/**
 * Register Phase 2 telemetry REST route.
 */
function gaming_hub_register_tesla_telemetry_rest_routes() {
	register_rest_route(
		'gaming-hub/v1',
		'/tesla/telemetry',
		array(
			'methods'             => 'POST',
			'callback'            => 'gaming_hub_rest_tesla_telemetry',
			'permission_callback' => 'gaming_hub_tesla_telemetry_bridge_can',
		)
	);
}
add_action( 'rest_api_init', 'gaming_hub_register_tesla_telemetry_rest_routes' );
