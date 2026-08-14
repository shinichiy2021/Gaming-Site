<?php
/**
 * Delta 1500 low-SOC grid top-up: UPS load + 200 W from 5% until above floor.
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
 * When remaining is 5% or below, charge from grid at UPS load + 200 W (hold floor).
 *
 * @param array<string, mixed> $status EcoFlow status.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_apply_delta1500_grid_rescue( array $status ) {
	$delta = ( isset( $status['secondary'] ) && is_array( $status['secondary'] ) )
		? $status['secondary']
		: array();

	$soc = isset( $delta['battery_percent'] ) && is_numeric( $delta['battery_percent'] )
		? (float) $delta['battery_percent']
		: null;

	$load_w = 0.0;
	if ( isset( $status['ups_plug']['watts'] ) && is_numeric( $status['ups_plug']['watts'] ) ) {
		$load_w = max( 0, (float) $status['ups_plug']['watts'] );
	} elseif ( isset( $delta['ac_out'] ) && is_numeric( $delta['ac_out'] ) ) {
		$load_w = max( 0, (float) $delta['ac_out'] );
	}

	$saved = get_option( GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_OPTION, array() );
	$saved = is_array( $saved ) ? $saved : array();
	$active = ! empty( $saved['active'] );

	if ( null === $soc ) {
		$delta['grid_rescue'] = gaming_hub_ecoflow_delta1500_rescue_view( $saved, $load_w, false );
		$status['secondary']  = $delta;
		return $status;
	}

	if ( $soc <= GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_START_SOC ) {
		$want = true;
	} elseif ( $soc >= GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_STOP_SOC ) {
		$want = false;
	} else {
		$want = $active;
	}

	$watts = $want
		? gaming_hub_ecoflow_delta1500_rescue_watts( $load_w )
		: 0;

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
		$saved['load_w']       = (int) round( $load_w );
		$saved['last_sent_w']  = $watts;
		$saved['last_sent_at'] = time();
		$saved['last_error']   = is_wp_error( $queued ) ? $queued->get_error_message() : '';
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
		$saved['last_error'] = empty( $result['ok'] ) ? (string) ( $result['error'] ?? __( 'MQTT 送信に失敗しました', 'gaming-hub' ) ) : '';
		update_option( GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_OPTION, $saved, false );
	}

	$delta['grid_rescue'] = gaming_hub_ecoflow_delta1500_rescue_view( $saved, $load_w, $active );
	if ( $active ) {
		$delta['charge_state'] = sprintf(
			/* translators: %s: charge watts */
			__( 'グリッド補充電中 %s W', 'gaming-hub' ),
			number_format_i18n( (int) ( $saved['watts'] ?? $watts ) )
		);
		$delta['is_charging'] = true;
	}

	$delta = gaming_hub_ecoflow_delta1500_apply_rescue_floor( $delta, $load_w, $active, $soc );

	$status['secondary'] = $delta;
	return $status;
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
 * @param array<string, mixed> $saved  Saved option.
 * @param float                $load_w Current UPS watts.
 * @param bool                 $active Whether charging.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_delta1500_rescue_view( array $saved, $load_w, $active ) {
	$watts = $active ? (int) ( $saved['watts'] ?? gaming_hub_ecoflow_delta1500_rescue_watts( $load_w ) ) : 0;
	$note  = $active
		? sprintf(
			/* translators: 1: load watts, 2: charge watts, 3: floor soc */
			__( '残量 %3$s%% 以下のためグリッド充電中。UPS 負荷 %1$s W + 200 W = %2$s W。これ以上下げません。', 'gaming-hub' ),
			number_format_i18n( (int) round( $load_w ) ),
			number_format_i18n( $watts ),
			number_format_i18n( GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_FLOOR_SOC )
		)
		: sprintf(
			/* translators: %s: floor soc percent */
			__( '残量 %s%% 以下で UPS 負荷 + 200 W のグリッド充電を始め、それ以上下げません。', 'gaming-hub' ),
			number_format_i18n( GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_FLOOR_SOC )
		);

	if ( ! empty( $saved['last_error'] ) ) {
		$note = sprintf(
			/* translators: %s: error */
			__( 'グリッド充電エラー: %s', 'gaming-hub' ),
			$saved['last_error']
		);
	}

	return array(
		'active'  => $active,
		'watts'   => $watts,
		'load_w'  => (int) round( $load_w ),
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
		return new WP_Error( 'ecoflow_no_bridge', __( 'MQTT ブリッジがありません。', 'gaming-hub' ) );
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
		return new WP_Error( 'ecoflow_command_write', __( '充電コマンドを書けませんでした。', 'gaming-hub' ) );
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
