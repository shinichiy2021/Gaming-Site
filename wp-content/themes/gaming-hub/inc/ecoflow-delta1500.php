<?php
/**
 * Delta 1500 AC grid charge: approved plan hours + low-SOC rescue floor.
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_OPTION', 'gaming_hub_delta1500_grid_rescue_v1' );
define( 'GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_LOCK', 'gaming_hub_delta1500_grid_rescue_lock' );
define( 'GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_START_SOC', 5 );
define( 'GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_STOP_SOC', 6 );
define( 'GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_FLOOR_SOC', 5 );
define( 'GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_HEADROOM_W', 200 );
define( 'GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_MIN_W', 100 );
define( 'GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_MAX_W', 1500 );

/**
 * Clamp Delta 1500 AC charge watts.
 *
 * @param int $watts Requested watts.
 */
function gaming_hub_ecoflow_clamp_delta1500_charge_watts( $watts ) {
	$max = defined( 'GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_MAX_W' )
		? (int) GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_MAX_W
		: 1500;

	return max( 0, min( $max, (int) $watts ) );
}

/**
 * Approved-plan charge watts for Delta 1500 this hour (0 when idle / not approved).
 *
 * @param array<string, mixed>|null $plan Live charge plan with slots.
 */
function gaming_hub_ecoflow_delta1500_plan_charge_watts( $plan = null ) {
	if ( ! function_exists( 'gaming_hub_ecoflow_get_saved_schedule' )
		|| ! function_exists( 'gaming_hub_ecoflow_current_approved_slot' ) ) {
		return 0;
	}

	$saved = gaming_hub_ecoflow_get_saved_schedule();
	if ( ( $saved['status'] ?? '' ) !== 'approved' ) {
		return 0;
	}

	$slots = ( is_array( $plan ) && isset( $plan['slots'] ) && is_array( $plan['slots'] ) )
		? $plan['slots']
		: null;
	$slot  = gaming_hub_ecoflow_current_approved_slot( $saved, $slots );

	if ( ! $slot || null === ( $slot['watts'] ?? null ) ) {
		return 0;
	}

	return gaming_hub_ecoflow_clamp_delta1500_charge_watts( (int) $slot['watts'] );
}

/**
 * Apply plan + rescue AC charge to Delta 1500 via the MQTT bridge.
 *
 * @param array<string, mixed> $status EcoFlow status (charge_plan when available).
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_apply_delta1500_grid_rescue( array $status ) {
	$delta = ( isset( $status['secondary'] ) && is_array( $status['secondary'] ) )
		? $status['secondary']
		: array();

	$soc = isset( $delta['battery_percent'] ) && is_numeric( $delta['battery_percent'] )
		? (float) $delta['battery_percent']
		: null;

	if ( null === $soc && isset( $delta['_model_soc'] ) && is_numeric( $delta['_model_soc'] ) ) {
		$soc = (float) $delta['_model_soc'];
	}

	$load_w = 0.0;
	if ( function_exists( 'gaming_hub_ecoflow_ups_source' )
		&& 'ecoflow' === gaming_hub_ecoflow_ups_source( $status )
		&& isset( $status['ups_plug']['watts'] )
		&& is_numeric( $status['ups_plug']['watts'] ) ) {
		$load_w = max( 0, (float) $status['ups_plug']['watts'] );
	} elseif ( isset( $delta['ac_out'] ) && is_numeric( $delta['ac_out'] ) ) {
		$load_w = max( 0, (float) $delta['ac_out'] );
	}

	$saved  = get_option( GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_OPTION, array() );
	$saved  = is_array( $saved ) ? $saved : array();
	$active = ! empty( $saved['active'] );

	$plan_w = gaming_hub_ecoflow_delta1500_plan_charge_watts(
		isset( $status['charge_plan'] ) && is_array( $status['charge_plan'] )
			? $status['charge_plan']
			: null
	);

	$rescue_want = false;
	$rescue_w    = 0;
	$was_rescue  = ! empty( $saved['rescue'] );
	if ( null !== $soc ) {
		if ( $soc <= GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_START_SOC ) {
			$rescue_want = true;
		} elseif ( $soc >= GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_STOP_SOC ) {
			$rescue_want = false;
		} else {
			// Hysteresis between start/stop: keep rescue only if it was already on.
			$rescue_want = $was_rescue;
		}
		if ( $rescue_want ) {
			$rescue_w = gaming_hub_ecoflow_delta1500_rescue_watts( $load_w );
		}
	}

	$watts      = max( $plan_w, $rescue_w );
	$want       = $watts > 0;
	$from_plan  = $plan_w > 0 && $plan_w >= $rescue_w;
	$from_rescue = $rescue_w > 0 && $rescue_w > $plan_w;

	$should_send = false;
	if ( $want && ! $active ) {
		$should_send = true;
	} elseif ( ! $want && $active ) {
		$should_send = true;
	} elseif ( $want && abs( $watts - (int) ( $saved['last_sent_w'] ?? 0 ) ) >= 40 ) {
		$should_send = true;
	} elseif ( $want && ! empty( $saved['last_error'] ) && ( time() - (int) ( $saved['last_sent_at'] ?? 0 ) ) >= 30 ) {
		$should_send = true;
	}

	if ( $should_send && ! get_transient( GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_LOCK ) ) {
		set_transient( GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_LOCK, 1, 8 );
		$queued = gaming_hub_ecoflow_queue_delta1500_ac_charge( $watts );
		$saved['active']       = $want;
		$saved['watts']        = $watts;
		$saved['plan_w']       = $plan_w;
		$saved['rescue']       = $rescue_want;
		$saved['load_w']       = (int) round( $load_w );
		$saved['last_sent_w']  = $watts;
		$saved['last_sent_at'] = time();
		$saved['last_error']   = is_wp_error( $queued ) ? $queued->get_error_message() : '';
		$saved['source']       = $from_plan ? 'plan' : ( $from_rescue ? 'rescue' : ( $want ? 'plan' : '' ) );
		if ( $want ) {
			$saved['started_at'] = wp_date( 'c' );
			$saved['stopped_at'] = '';
		} else {
			$saved['stopped_at'] = wp_date( 'c' );
		}
		update_option( GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_OPTION, $saved, false );
		$active = $want;
	}

	$result = gaming_hub_ecoflow_read_delta1500_command_result();
	if ( is_array( $result ) && ( $result['id'] ?? '' ) && ( $result['id'] ?? '' ) !== ( $saved['result_id'] ?? '' ) ) {
		$saved['result_id']  = (string) $result['id'];
		$saved['last_error'] = empty( $result['ok'] ) ? (string) ( $result['error'] ?? __('MQTT send failed', 'gaming-hub') ) : '';
		update_option( GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_OPTION, $saved, false );
	}

	$plan = isset( $status['charge_plan'] ) && is_array( $status['charge_plan'] )
		? $status['charge_plan']
		: array();

	$delta['grid_rescue'] = gaming_hub_ecoflow_delta1500_rescue_view(
		$saved,
		$load_w,
		$active && $from_rescue,
		$plan_w,
		$watts
	);
	$status['delta_grid_charge'] = gaming_hub_ecoflow_delta1500_grid_charge_view(
		$plan,
		$status,
		$saved,
		$plan_w,
		$rescue_w,
		$watts,
		$active
	);

	if ( $active && $watts > 0 ) {
		$delta['charge_state'] = $from_plan
			? sprintf(
				/* translators: %s: charge watts */
				__('Grid charging per plan. Charge cap %s W', 'gaming-hub'),
				number_format_i18n( $watts )
			)
			: sprintf(
				/* translators: %s: charge watts */
				__('Grid top-up %s W', 'gaming-hub'),
				number_format_i18n( $watts )
			);
		$delta['is_charging'] = true;
	}

	$delta = gaming_hub_ecoflow_delta1500_apply_rescue_floor( $delta, $load_w, $active && ( $from_rescue || $soc <= GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_START_SOC ), $soc );

	$status['secondary'] = $delta;
	return $status;
}

/**
 * Dashboard overlay for Delta 1500 grid charge (plan + rescue).
 *
 * @param array<string, mixed> $plan     Charge plan.
 * @param array<string, mixed> $status   Full status.
 * @param array<string, mixed> $saved    Saved Delta charge option.
 * @param int                  $plan_w   Plan watts.
 * @param int                  $rescue_w Rescue watts.
 * @param int                  $watts    Applied setpoint.
 * @param bool                 $active   Command active.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_delta1500_grid_charge_view( array $plan, array $status, array $saved, $plan_w, $rescue_w, $watts, $active ) {
	$idle_w    = defined( 'GAMING_HUB_ECOFLOW_PLAN_IDLE_W' ) ? (int) GAMING_HUB_ECOFLOW_PLAN_IDLE_W : 0;
	$approved  = ! empty( $plan['is_approved_current'] ) || ! empty( $plan['needs_reapprove'] );
	$planned   = (int) $plan_w > $idle_w;
	$live_w    = 0;
	$secondary = isset( $status['secondary'] ) && is_array( $status['secondary'] ) ? $status['secondary'] : array();
	if ( isset( $secondary['ac_in'] ) && is_numeric( $secondary['ac_in'] ) ) {
		$live_w = max( 0, (int) round( (float) $secondary['ac_in'] ) );
	}
	$threshold = defined( 'GAMING_HUB_ECOFLOW_FLOW_THRESHOLD_W' )
		? (int) GAMING_HUB_ECOFLOW_FLOW_THRESHOLD_W
		: 8;
	$live_active = $live_w >= $threshold;

	if ( ! empty( $saved['last_error'] ) ) {
		$message = sprintf(
			/* translators: %s: error message */
			__('Send error: %s', 'gaming-hub'),
			$saved['last_error']
		);
	} elseif ( $live_active ) {
		$message = $approved && $planned
			? sprintf(
				/* translators: %s: live watts */
				__('Grid charging per plan. Live %s W', 'gaming-hub'),
				number_format_i18n( $live_w )
			)
			: sprintf(
				/* translators: %s: live watts */
				__('Live grid in %s W', 'gaming-hub'),
				number_format_i18n( $live_w )
			);
	} elseif ( (int) $rescue_w > (int) $plan_w && $active ) {
		$message = sprintf(
			/* translators: 1: load watts, 2: charge watts, 3: floor soc */
			__('SOC at or below %3$s%%, so grid charging. UPS load %1$s W + 200 W = %2$s W. Will not go lower.', 'gaming-hub'),
			number_format_i18n( (int) ( $saved['load_w'] ?? 0 ) ),
			number_format_i18n( (int) $watts ),
			number_format_i18n( GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_FLOOR_SOC )
		);
	} elseif ( ! $approved ) {
		$message = __('Not approved. Grid charge is not sent.', 'gaming-hub');
	} elseif ( $planned ) {
		$message = sprintf(
			/* translators: %s: charge watts */
			__('Grid charging per plan. Charge cap %s W', 'gaming-hub'),
			number_format_i18n( (int) $plan_w )
		);
	} else {
		$message = __('Per the approved plan. Idle outside grid-charge hours.', 'gaming-hub');
	}

	return array(
		'active'   => $live_active || ( $active && (int) $watts > 0 ),
		'watts'    => $live_active ? $live_w : (int) $watts,
		'plan_w'   => (int) $plan_w,
		'rescue_w' => (int) $rescue_w,
		'message'  => $message,
	);
}

/**
 * Keep simulated/live 1500 SOC from dropping below the rescue floor while UPS loads run.
 *
 * @param array<string, mixed> $delta   Secondary device status.
 * @param float                $load_w UPS watts.
 * @param bool                 $active Rescue charging active.
 * @param float|null           $soc    Current SOC percent.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_delta1500_apply_rescue_floor( array $delta, $load_w, $active, $soc = null ) {
	$full = (int) ( $delta['capacity_wh'] ?? GAMING_HUB_ECOFLOW_DELTA1500_CAPACITY_WH );
	if ( $full <= 0 ) {
		$full = (int) GAMING_HUB_ECOFLOW_DELTA1500_CAPACITY_WH;
	}

	$floor_soc = (int) GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_FLOOR_SOC;
	$floor_wh  = (int) round( $full * ( $floor_soc / 100.0 ) );
	$hold      = $active
		|| ( null !== $soc && $soc <= GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_START_SOC && $load_w > 0 );

	if ( ! $hold ) {
		return $delta;
	}

	if ( function_exists( 'gaming_hub_ecoflow_delta1500_has_live_soc' ) && gaming_hub_ecoflow_delta1500_has_live_soc( $delta ) ) {
		return $delta;
	}

	$remain = isset( $delta['remain_capacity'] ) && is_numeric( $delta['remain_capacity'] )
		? (int) round( (float) $delta['remain_capacity'] )
		: null;

	if ( null === $remain || $remain < $floor_wh ) {
		$delta['remain_capacity'] = $floor_wh;
		$delta['battery_percent'] = $floor_soc;
		$delta['extra']           = gaming_hub_ecoflow_extra_battery_slice( $floor_soc );
	}

	return $delta;
}

/**
 * Charge setpoint: UPS load + 200 W.
 *
 * @param float $load_w UPS watts.
 */
function gaming_hub_ecoflow_delta1500_rescue_watts( $load_w ) {
	$watts = (int) round( max( 0, (float) $load_w ) + GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_HEADROOM_W );
	return max(
		GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_MIN_W,
		min( GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_MAX_W, $watts )
	);
}

/**
 * Dashboard overlay for the 1500 rescue state.
 *
 * @param array<string, mixed> $saved   Saved option.
 * @param float                $load_w  Current UPS watts.
 * @param bool                 $active  Whether rescue (not plan) is the driver.
 * @param int                  $plan_w  Plan watts this hour.
 * @param int                  $set_w   Combined setpoint.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_delta1500_rescue_view( array $saved, $load_w, $active, $plan_w = 0, $set_w = 0 ) {
	$watts = $active
		? (int) ( $saved['watts'] ?? gaming_hub_ecoflow_delta1500_rescue_watts( $load_w ) )
		: (int) $set_w;
	$note  = $active
		? sprintf(
			/* translators: 1: load watts, 2: charge watts, 3: floor soc */
			__('SOC at or below %3$s%%, so grid charging. UPS load %1$s W + 200 W = %2$s W. Will not go lower.', 'gaming-hub'),
			number_format_i18n( (int) round( $load_w ) ),
			number_format_i18n( max( $watts, (int) $set_w ) ),
			number_format_i18n( GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_FLOOR_SOC )
		)
		: (
			(int) $plan_w > 0
				? sprintf(
					/* translators: %s: charge watts */
					__('Grid charging per plan. Charge cap %s W', 'gaming-hub'),
					number_format_i18n( (int) $plan_w )
				)
				: sprintf(
					/* translators: %s: floor soc percent */
					__('At %s%% or below, start grid charge at UPS load + 200 W and hold that floor.', 'gaming-hub'),
					number_format_i18n( GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_FLOOR_SOC )
				)
		);

	if ( ! empty( $saved['last_error'] ) ) {
		$note = sprintf(
			/* translators: %s: error */
			__('Grid charge error: %s', 'gaming-hub'),
			$saved['last_error']
		);
	}

	return array(
		'active'  => $active,
		'watts'   => (int) ( $active ? max( $watts, (int) $set_w ) : (int) $set_w ),
		'load_w'  => (int) round( $load_w ),
		'plan_w'  => (int) $plan_w,
		'start'   => GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_START_SOC,
		'stop'    => GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_STOP_SOC,
		'floor'   => GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_FLOOR_SOC,
		'message' => $note,
	);
}

/**
 * Queue an MQTT AC-charge setpoint for the bridge daemon.
 *
 * @param int $watts Watts (0 = stop).
 * @return true|WP_Error
 */
function gaming_hub_ecoflow_queue_delta1500_ac_charge( $watts ) {
	if ( ! function_exists( 'gaming_hub_ecoflow_bridge_cache_dir' ) ) {
		return new WP_Error( 'ecoflow_no_bridge', __('MQTT bridge is missing.', 'gaming-hub') );
	}

	$dir  = gaming_hub_ecoflow_bridge_cache_dir();
	$path = trailingslashit( $dir ) . 'bridge-command.json';
	$cmd  = array(
		'id'     => wp_date( 'U' ) . '-' . wp_generate_password( 6, false, false ),
		'action' => 'ac_charge',
		'watts'  => (int) $watts,
		'at'     => wp_date( 'c' ),
	);

	$ok = file_put_contents( $path, wp_json_encode( $cmd ) );
	if ( false === $ok ) {
		return new WP_Error( 'ecoflow_command_write', __('Could not write the charge command.', 'gaming-hub') );
	}

	return true;
}

/**
 * Last MQTT set result from the bridge.
 *
 * @return array<string, mixed>|null
 */
function gaming_hub_ecoflow_read_delta1500_command_result() {
	if ( ! function_exists( 'gaming_hub_ecoflow_bridge_cache_dir' ) ) {
		return null;
	}

	$path = trailingslashit( gaming_hub_ecoflow_bridge_cache_dir() ) . 'bridge-command-result.json';
	if ( ! file_exists( $path ) ) {
		return null;
	}

	$raw = json_decode( (string) file_get_contents( $path ), true );
	return is_array( $raw ) ? $raw : null;
}
