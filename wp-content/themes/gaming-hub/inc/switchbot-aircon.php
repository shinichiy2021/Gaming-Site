<?php
/**
 * Room A/C control via SwitchBot Hub Mini (IR). Fan speed is always Auto.
 *
 * IR is one-way, so the last sent power / mode / temperature is remembered here.
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_AIRCON_STATE_OPTION', 'gaming_hub_aircon_state' );
define( 'GAMING_HUB_AIRCON_IR_CACHE', 'gaming_hub_switchbot_ir_remotes' );
define( 'GAMING_HUB_AIRCON_TEMP_MIN', 16 );
define( 'GAMING_HUB_AIRCON_TEMP_MAX', 30 );

/**
 * SwitchBot setAll mode codes.
 *
 * @return array<string, int>
 */
function gaming_hub_aircon_mode_codes() {
	return array(
		'cool' => 2,
		'heat' => 5,
	);
}

/**
 * Default target temperature per mode.
 *
 * @param string $mode cool|heat.
 */
function gaming_hub_aircon_default_temp( $mode ) {
	return 'heat' === $mode ? 22 : 26;
}

/**
 * Who may press the A/C buttons. Sample mode never sends IR, so anyone may try it locally.
 */
function gaming_hub_aircon_can_control() {
	if ( function_exists( 'gaming_hub_switchbot_ac_sample_enabled' ) && gaming_hub_switchbot_ac_sample_enabled() ) {
		return true;
	}

	return current_user_can( 'manage_options' ) && gaming_hub_switchbot_is_configured();
}

/**
 * Last sent A/C state.
 *
 * @return array{power: string, mode: string, temp: int, updated_at: int}|null
 */
function gaming_hub_aircon_saved_state() {
	$state = get_option( GAMING_HUB_AIRCON_STATE_OPTION );
	if ( ! is_array( $state ) || empty( $state['updated_at'] ) ) {
		return null;
	}

	$mode = in_array( $state['mode'] ?? '', array( 'cool', 'heat' ), true ) ? $state['mode'] : 'cool';

	return array(
		'power'      => 'on' === ( $state['power'] ?? '' ) ? 'on' : 'off',
		'mode'       => $mode,
		'temp'       => gaming_hub_aircon_clamp_temp( $state['temp'] ?? gaming_hub_aircon_default_temp( $mode ) ),
		'updated_at' => (int) $state['updated_at'],
	);
}

/**
 * @param mixed $temp Requested temperature.
 */
function gaming_hub_aircon_clamp_temp( $temp ) {
	return max( GAMING_HUB_AIRCON_TEMP_MIN, min( GAMING_HUB_AIRCON_TEMP_MAX, (int) round( (float) $temp ) ) );
}

/**
 * IR remote ID of the A/C learned on the Hub Mini.
 *
 * @param Gaming_Hub_Switchbot_Api $api API client.
 * @return string|WP_Error
 */
function gaming_hub_aircon_ir_id( Gaming_Hub_Switchbot_Api $api ) {
	$configured = getenv( 'SWITCHBOT_AC_IR_ID' ) ?: get_theme_mod( 'switchbot_ac_ir_id', '' );
	if ( '' !== (string) $configured ) {
		return (string) $configured;
	}

	$remotes = get_transient( GAMING_HUB_AIRCON_IR_CACHE );
	if ( ! is_array( $remotes ) ) {
		$remotes = $api->get_ir_remotes();
		if ( is_wp_error( $remotes ) ) {
			return $remotes;
		}
		set_transient( GAMING_HUB_AIRCON_IR_CACHE, $remotes, GAMING_HUB_SWITCHBOT_DEVICES_TTL );
	}

	foreach ( $remotes as $remote ) {
		if ( is_array( $remote ) && false !== stripos( (string) ( $remote['remoteType'] ?? '' ), 'Air Conditioner' ) ) {
			return (string) ( $remote['deviceId'] ?? '' );
		}
	}

	return new WP_Error(
		'switchbot_no_ac_remote',
		__('No air conditioner remote found on the SwitchBot Hub. Add it in the SwitchBot app or set its ID.', 'gaming-hub')
	);
}

/**
 * Send power / temperature / mode to the A/C and remember it.
 *
 * @param string      $power on|off.
 * @param int|null    $temp  Target °C (null keeps the last one).
 * @param string|null $mode  cool|heat (null keeps the last one).
 * @return array<string, mixed>|WP_Error New state.
 */
function gaming_hub_aircon_apply( $power, $temp = null, $mode = null ) {
	$prev = gaming_hub_aircon_saved_state();
	if ( null === $mode || ! in_array( $mode, array( 'cool', 'heat' ), true ) ) {
		$mode = $prev ? $prev['mode'] : gaming_hub_switchbot_ac_mode()['mode'];
	}
	if ( null === $temp ) {
		$temp = ( $prev && $prev['mode'] === $mode ) ? $prev['temp'] : gaming_hub_aircon_default_temp( $mode );
	}

	$state = array(
		'power'      => 'on' === $power ? 'on' : 'off',
		'mode'       => $mode,
		'temp'       => gaming_hub_aircon_clamp_temp( $temp ),
		'updated_at' => time(),
	);

	// Changing the set point while off only updates the remembered state.
	$send_ir = ! ( 'off' === $state['power'] && ( ! $prev || 'off' === $prev['power'] ) );

	if ( $send_ir && ! gaming_hub_switchbot_ac_sample_enabled() ) {
		$config = gaming_hub_get_switchbot_config();
		$api    = new Gaming_Hub_Switchbot_Api( $config['token'], $config['secret'] );
		$ir_id  = gaming_hub_aircon_ir_id( $api );
		if ( is_wp_error( $ir_id ) ) {
			return $ir_id;
		}

		$parameter = sprintf(
			'%d,%d,1,%s',
			$state['temp'],
			gaming_hub_aircon_mode_codes()[ $state['mode'] ],
			$state['power']
		);
		$result = $api->send_command( $ir_id, 'setAll', $parameter );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	update_option( GAMING_HUB_AIRCON_STATE_OPTION, $state, false );
	delete_transient( GAMING_HUB_SWITCHBOT_AC_CACHE );

	return $state;
}

/**
 * Register POST /gaming-hub/v1/aircon.
 */
function gaming_hub_register_aircon_rest_route() {
	register_rest_route(
		'gaming-hub/v1',
		'/aircon',
		array(
			'methods'             => 'POST',
			'callback'            => 'gaming_hub_rest_aircon_apply',
			'permission_callback' => 'gaming_hub_aircon_can_control',
			'args'                => array(
				'power' => array(
					'required' => true,
					'type'     => 'string',
					'enum'     => array( 'on', 'off' ),
				),
				'temp'  => array(
					'type'    => 'integer',
					'minimum' => GAMING_HUB_AIRCON_TEMP_MIN,
					'maximum' => GAMING_HUB_AIRCON_TEMP_MAX,
				),
				'mode'  => array(
					'type' => 'string',
					'enum' => array( 'cool', 'heat' ),
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'gaming_hub_register_aircon_rest_route' );

/**
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function gaming_hub_rest_aircon_apply( WP_REST_Request $request ) {
	$temp  = $request->get_param( 'temp' );
	$state = gaming_hub_aircon_apply(
		(string) $request->get_param( 'power' ),
		null === $temp ? null : (int) $temp,
		$request->get_param( 'mode' )
	);

	if ( is_wp_error( $state ) ) {
		return new WP_REST_Response(
			array(
				'success' => false,
				'message' => $state->get_error_message(),
			),
			200
		);
	}

	return new WP_REST_Response(
		array(
			'success' => true,
			'state'   => $state,
		),
		200
	);
}
