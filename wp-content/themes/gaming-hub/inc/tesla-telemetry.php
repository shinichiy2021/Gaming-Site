<?php
/**
 * Phase 2+4: Fleet Telemetry → WordPress one-way bridge.
 *
 * POST /wp-json/gaming-hub/v1/tesla/telemetry (bridge token required)
 * merges SOC / charge fields into GAMING_HUB_TESLA_STATUS_CACHE_KEY,
 * drives CHARGE LOG / SOC log from telemetry events, and keeps AI PLAN
 * inputs cache-first. Commands stay on REST + wake budget.
 * Location is intentionally not subscribed (privacy).
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
 * Pack power in watts from PackVoltage × PackCurrent.
 *
 * PackCurrent convention: negative = discharging, positive = charging.
 *
 * @param array<string, mixed> $fields Telemetry fields.
 * @return array{watts: int|null, discharge_w: int, charge_w: int}|null
 */
function gaming_hub_tesla_telemetry_pack_watts( array $fields ) {
	$volts = gaming_hub_tesla_telemetry_num( $fields, 'PackVoltage' );
	$amps  = gaming_hub_tesla_telemetry_num( $fields, 'PackCurrent' );
	if ( null === $volts || null === $amps || $volts < 50 ) {
		return null;
	}

	$raw = $volts * $amps;
	$w   = (int) round( abs( $raw ) );

	return array(
		'watts'       => $w,
		'discharge_w' => $raw < -0.08 ? $w : 0,
		'charge_w'    => $raw > 0.08 ? $w : 0,
	);
}

/**
 * Normalize Gear / ShiftState enum to P|R|N|D|''.
 *
 * @param mixed $raw Gear field.
 * @return string
 */
function gaming_hub_tesla_telemetry_gear( $raw ) {
	$s = strtoupper( trim( (string) $raw ) );
	$s = preg_replace( '/^(GEAR|SHIFTSTATE|SHIFT_STATE)/', '', $s );
	$s = preg_replace( '/[^PRND]/', '', $s );
	if ( in_array( $s, array( 'P', 'R', 'N', 'D' ), true ) ) {
		return $s;
	}

	return '';
}

/**
 * Whether the car looks like it is moving (drive / reverse / speed).
 *
 * @param array<string, mixed> $cached Existing model3 cache.
 * @param array<string, mixed> $fields Telemetry fields.
 * @return bool
 */
function gaming_hub_tesla_telemetry_is_moving( array $cached, array $fields ) {
	$gear = '';
	if ( isset( $fields['Gear'] ) ) {
		$gear = gaming_hub_tesla_telemetry_gear( $fields['Gear'] );
	}
	if ( '' === $gear ) {
		$gear = strtoupper( (string) ( $cached['shift_state'] ?? '' ) );
	}

	$speed = gaming_hub_tesla_telemetry_num( $fields, 'VehicleSpeed' );
	if ( null === $speed && isset( $cached['speed_km'] ) && is_numeric( $cached['speed_km'] ) ) {
		$speed = (float) $cached['speed_km'];
	}

	if ( in_array( $gear, array( 'D', 'R' ), true ) ) {
		return true;
	}
	if ( null !== $speed && abs( $speed ) >= 3 ) {
		return true;
	}

	return false;
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

	if ( isset( $fields['Gear'] ) ) {
		$gear = gaming_hub_tesla_telemetry_gear( $fields['Gear'] );
		if ( '' !== $gear ) {
			$cached['shift_state'] = $gear;
			$cached['drive_ready'] = true;
			$updated[]             = 'shift_state';
		}
	}

	$speed = gaming_hub_tesla_telemetry_num( $fields, 'VehicleSpeed' );
	if ( null !== $speed ) {
		// Fleet VehicleSpeed is typically mph; values already in km/h are usually larger.
		$speed_km = abs( $speed ) <= 200 ? abs( $speed ) * 1.60934 : abs( $speed );
		$cached['speed_km'] = (int) round( $speed_km );
		$updated[]          = 'speed_km';
	}

	if ( isset( $fields['HvacPower'] ) ) {
		$hvac = gaming_hub_tesla_telemetry_strip_enum( $fields['HvacPower'] );
		$hvac = preg_replace( '/^HvacPowerState/i', '', (string) $hvac );
		$cached['climate_on'] = in_array( $hvac, array( 'On', 'Precondition', 'OverheatProtect' ), true );
		$updated[]            = 'climate_on';
	}

	$moving = gaming_hub_tesla_telemetry_is_moving( $cached, $fields );
	$pack   = gaming_hub_tesla_telemetry_pack_watts( $fields );

	// Parked cabin approximation: pack discharge only while climate is on.
	if ( null !== $pack ) {
		if ( ! $charging && ! $moving ) {
			$cabin_w = ( ! empty( $cached['climate_on'] ) && $pack['discharge_w'] >= 80 )
				? $pack['discharge_w']
				: 0;
			$cached['cabin_w'] = $cabin_w;
			$cached['drive_w'] = 0;
			$cached['regen_w'] = 0;
			$updated[]         = 'cabin_w';

			if ( function_exists( 'gaming_hub_tesla_record_cabin_energy' ) ) {
				gaming_hub_tesla_record_cabin_energy( $cabin_w, true );
			}
		} elseif ( $moving && ! $charging ) {
			// Drive vs regen from pack sign; cabin left to prior cache / poll.
			if ( $pack['discharge_w'] >= 80 ) {
				$cached['drive_w'] = $pack['discharge_w'];
				$cached['regen_w'] = 0;
				$cached['cabin_w'] = 0;
				$updated[]         = 'drive_w';
			} elseif ( $pack['charge_w'] >= 80 ) {
				$cached['regen_w'] = $pack['charge_w'];
				$cached['drive_w'] = 0;
				$updated[]         = 'regen_w';
			}
		} elseif ( $charging ) {
			$cached['cabin_w'] = 0;
			$cached['drive_w'] = 0;
			$cached['regen_w'] = 0;
		}
	}

	if ( $charging || ! empty( $cached['plugged'] ) ) {
		if ( ! empty( $cached['fast_charger_present'] ) || ( null !== $dc_kw && $dc_kw > 1 ) ) {
			$cached['supply_kind']  = 'supercharger';
			$cached['supply_label'] = function_exists( '__' ) ? __( 'Supercharger', 'gaming-hub' ) : 'Supercharger';
			$cached['vehicle_mode'] = 'supercharger';
		} else {
			// supply_kind matches vehicle_data path ('home'); vehicle_mode stays 'wall' for flow art.
			$cached['supply_kind']  = 'home';
			$cached['supply_label'] = function_exists( 'gaming_hub_tesla_plan_charge_label' )
				? gaming_hub_tesla_plan_charge_label()
				: ( function_exists( '__' ) ? __( 'Home charging', 'gaming-hub' ) : 'Home charging' );
			$cached['vehicle_mode'] = $charging ? 'wall' : ( $cached['vehicle_mode'] ?? 'idle' );
		}
		$updated[] = 'supply_kind';
	} elseif ( $moving ) {
		$cached['vehicle_mode'] = ( (int) ( $cached['regen_w'] ?? 0 ) >= 80 ) ? 'regen' : 'drive';
	} elseif ( ! empty( $cached['climate_on'] ) && (int) ( $cached['cabin_w'] ?? 0 ) >= 80 ) {
		$cached['vehicle_mode'] = 'cabin';
	} elseif ( ! $charging ) {
		$cached['vehicle_mode'] = 'idle';
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

	// Phase 4: sticky at-home without Location — clear on drive / Supercharger only.
	if ( function_exists( 'gaming_hub_tesla_apply_cached_at_home' ) ) {
		$cached = gaming_hub_tesla_apply_cached_at_home( $cached );
		$updated[] = 'at_home';
	}

	// Phase 4: SOC hourly log from telemetry (same option as vehicle_data path).
	if ( isset( $cached['battery_percent'] ) && is_numeric( $cached['battery_percent'] )
		&& function_exists( 'gaming_hub_tesla_soc_log_record' ) ) {
		gaming_hub_tesla_soc_log_record( (float) $cached['battery_percent'] );
		$updated[] = 'soc_log';
	}

	// Phase 4: CHARGE LOG sessions from telemetry start/stop (watt integrate; no Location).
	$charge_w = $charging ? max( 0, (int) ( null !== $watts ? $watts : ( $cached['watts'] ?? 0 ) ) ) : 0;
	$kind     = (string) ( $cached['supply_kind'] ?? '' );
	$at_home  = function_exists( 'gaming_hub_tesla_model3_input_at_home' )
		? gaming_hub_tesla_model3_input_at_home( $cached )
		: ( array_key_exists( 'at_home', $cached ) ? $cached['at_home'] : null );
	$charge_meta = array(
		'soc'       => isset( $cached['battery_percent'] ) ? (int) $cached['battery_percent'] : null,
		'limit_soc' => isset( $cached['charge_limit_percent'] ) ? (int) $cached['charge_limit_percent'] : null,
		'at_home'   => $at_home,
	);

	$is_super = 'supercharger' === $kind
		|| ! empty( $cached['fast_charger_present'] )
		|| ( null !== $dc_kw && $dc_kw > 1 );
	$wall_on  = $charging && ! $is_super;
	$super_on = $charging && $is_super;

	if ( function_exists( 'gaming_hub_tesla_record_wall_energy' ) ) {
		gaming_hub_tesla_record_wall_energy( $wall_on ? $charge_w : 0, $wall_on, null, $charge_meta );
		$updated[] = 'wall_energy';
	}
	if ( function_exists( 'gaming_hub_tesla_record_super_energy' ) ) {
		gaming_hub_tesla_record_super_energy( $super_on ? $charge_w : 0, $super_on, null, $charge_meta );
		$updated[] = 'super_energy';
	}

	$received = isset( $payload['received_at'] ) ? (int) $payload['received_at'] : time();
	if ( $received > 0 && ( time() - $received ) > ( 3 * MINUTE_IN_SECONDS ) ) {
		return new WP_Error( 'tesla_telemetry_stale', 'Stale telemetry payload ignored.' );
	}
	$cached['telemetry']    = true;
	$cached['telemetry_at'] = $received > 0 ? $received : time();
	$cached['live']         = true;
	$cached['source']       = 'telemetry';

	// Streaming telemetry means the car is online, but don't clear Fleet sleep backoff
	// on empty heartbeats — that forces vehicle_data polls and keeps the car awake.
	// is_charging is written on every apply; only real field changes count as a signal.
	$signal_fields = array_values(
		array_filter(
			$updated,
			static function ( $key ) {
				return 'is_charging' !== $key && 'charge_state' !== $key;
			}
		)
	);
	$has_signal = ! empty( $signal_fields ) || $charging || $moving;
	if ( $has_signal ) {
		$cached['asleep'] = false;
		if ( function_exists( 'gaming_hub_tesla_clear_api_skip' ) ) {
			gaming_hub_tesla_clear_api_skip();
		}
	}

	if ( function_exists( 'gaming_hub_tesla_store_model3' ) ) {
		if ( $has_signal ) {
			gaming_hub_tesla_store_model3( $cached );
		} elseif ( function_exists( 'gaming_hub_tesla_store_model3_snapshot' ) ) {
			gaming_hub_tesla_store_model3_snapshot( $cached, ! empty( $cached['asleep'] ) );
		} else {
			set_transient( GAMING_HUB_TESLA_STATUS_CACHE_KEY, $cached, GAMING_HUB_TESLA_STATUS_KEEP_TTL );
		}
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
