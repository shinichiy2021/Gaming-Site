<?php
/**
 * EcoFlow Pro 3 charge schedule: auto-send when the charge command changes.
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_ECOFLOW_SCHEDULE_OPTION', 'gaming_hub_ecoflow_approved_schedule' );
define( 'GAMING_HUB_ECOFLOW_SCHEDULE_LOCK', 'gaming_hub_ecoflow_schedule_lock' );
define( 'GAMING_HUB_ECOFLOW_SCHEDULE_CRON', 'gaming_hub_ecoflow_run_schedule' );

/**
 * Whether the current user may approve Pro 3 commands.
 */
function gaming_hub_ecoflow_can_control() {
	return current_user_can( 'manage_options' );
}

/**
 * Overlay approval state onto a proposed plan (not cached).
 *
 * @param array<string, mixed> $plan Proposed plan.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_attach_schedule_state( array $plan ) {
	$saved     = gaming_hub_ecoflow_get_saved_schedule();
	$status    = $saved['status'] ?? '';
	$saved_id  = $saved['plan_id'] ?? '';
	$plan_id   = $plan['plan_id'] ?? '';
	$is_auto   = ! empty( $saved['auto'] ) && 'approved' === $status;
	$is_match  = ( ! $is_auto && 'approved' === $status && $saved_id && $saved_id === $plan_id );
	$stale     = ( ! $is_auto && 'approved' === $status && $saved_id && $saved_id !== $plan_id );

	$plan['can_approve']         = $is_auto ? false : gaming_hub_ecoflow_can_control();
	$plan['auto_send']           = $is_auto;
	$plan['approval_status']     = $status ? $status : 'proposed';
	$plan['approved_plan_id']    = $saved_id;
	$plan['is_approved_current'] = $is_auto || $is_match;
	$plan['needs_reapprove']     = $stale;
	$plan['last_applied_w']         = isset( $saved['last_applied_w'] ) && null !== $saved['last_applied_w']
		? gaming_hub_ecoflow_clamp_charge_watts( (int) $saved['last_applied_w'] )
		: null;
	$plan['last_applied_reserve']   = isset( $saved['last_applied_reserve'] ) ? (int) $saved['last_applied_reserve'] : null;
	$plan['last_applied_at']        = $saved['last_applied_at'] ?? '';
	$plan['last_apply_error']    = $saved['last_apply_error'] ?? '';
	$plan['send_notice']         = isset( $saved['send_notice'] ) && is_array( $saved['send_notice'] )
		? $saved['send_notice']
		: null;
	$plan['approval_note']       = gaming_hub_ecoflow_schedule_note( $plan );

	return $plan;
}

/**
 * Human-readable approval line.
 *
 * @param array<string, mixed> $plan Plan with overlay.
 */
function gaming_hub_ecoflow_schedule_note( array $plan ) {
	if ( ! empty( $plan['last_apply_error'] ) ) {
		return sprintf(
			/* translators: %s: error message */
			! empty( $plan['auto_send'] ) ? __( '自動送信エラー: %s', 'gaming-hub' ) : __( '送信エラー: %s', 'gaming-hub' ),
			$plan['last_apply_error']
		);
	}

	if ( ! empty( $plan['auto_send'] ) ) {
		$watts = $plan['last_applied_w'];
		if ( $watts ) {
			$reserve = isset( $plan['last_applied_reserve'] ) ? (int) $plan['last_applied_reserve'] : null;
			if ( $reserve ) {
				return sprintf(
					/* translators: 1: watts, 2: backup reserve percent */
					__( '自動承認中。充電スケジュールが変わったときだけ送ります。直近は充電上限 %1$s W · 予備残量 %2$s%% です。', 'gaming-hub' ),
					number_format_i18n( (int) $watts ),
					number_format_i18n( $reserve )
				);
			}

			return sprintf(
				/* translators: %s: watts */
				__( '自動承認中。充電スケジュールが変わったときだけ送ります。直近は充電上限 %s W です。', 'gaming-hub' ),
				number_format_i18n( (int) $watts )
			);
		}

		return __( '自動承認中。充電スケジュールが変わったときだけ Pro 3 に送ります。', 'gaming-hub' );
	}

	if ( ! empty( $plan['is_approved_current'] ) ) {
		$watts = $plan['last_applied_w'];
		if ( $watts ) {
			$reserve = isset( $plan['last_applied_reserve'] ) ? (int) $plan['last_applied_reserve'] : null;
			if ( $reserve ) {
				return sprintf(
					/* translators: 1: watts, 2: backup reserve percent */
					__( '承認済み。直近の送信は充電上限 %1$s W · 予備残量 %2$s%% です。', 'gaming-hub' ),
					number_format_i18n( (int) $watts ),
					number_format_i18n( $reserve )
				);
			}

			return sprintf(
				/* translators: %s: watts */
				__( '承認済み。直近の送信は充電上限 %s W です。', 'gaming-hub' ),
				number_format_i18n( (int) $watts )
			);
		}

		return __( '承認済み。時間どおりに Pro 3 へ充電上限を送ります。', 'gaming-hub' );
	}

	if ( ! empty( $plan['needs_reapprove'] ) ) {
		return __( '提案が更新されました。再承認するまで前回のスケジュールを送ります。', 'gaming-hub' );
	}

	if ( 'cancelled' === ( $plan['approval_status'] ?? '' ) ) {
		return __( '承認を取り消しました。API は送りません。', 'gaming-hub' );
	}

	return __( '未承認です。承認するまで Pro 3 には送りません。', 'gaming-hub' );
}

/**
 * Whether the current hour is a charge-plan grid-charge slot.
 *
 * @param array<string, mixed> $plan Charge plan.
 */
function gaming_hub_ecoflow_plan_slot_is_charging( array $plan ) {
	$hour = (int) wp_date( 'G' );
	$date = wp_date( 'Y-m-d' );

	foreach ( $plan['slots'] ?? array() as $slot ) {
		if ( ! is_array( $slot ) ) {
			continue;
		}

		if ( (int) ( $slot['hour'] ?? -1 ) !== $hour ) {
			continue;
		}

		if ( (string) ( $slot['date'] ?? '' ) !== $date ) {
			continue;
		}

		return 'charge' === ( $slot['mode'] ?? '' );
	}

	return false;
}

/**
 * Pro 3 grid charge display: live AC in for the flow, plan text as a note.
 *
 * @param array<string, mixed> $plan   Charge plan with schedule overlay.
 * @param array<string, mixed> $status EcoFlow status (Pro 3 live watts).
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_pro_grid_charge_view( array $plan, array $status = array() ) {
	$charge_w = defined( 'GAMING_HUB_ECOFLOW_PLAN_CHARGE_W' ) ? (int) GAMING_HUB_ECOFLOW_PLAN_CHARGE_W : 1000;
	$idle_w   = defined( 'GAMING_HUB_ECOFLOW_PLAN_IDLE_W' ) ? (int) GAMING_HUB_ECOFLOW_PLAN_IDLE_W : 0;
	$applied  = isset( $plan['last_applied_w'] ) ? (int) $plan['last_applied_w'] : 0;
	$planned  = $applied > $idle_w || gaming_hub_ecoflow_plan_slot_is_charging( $plan );
	$approved = ! empty( $plan['is_approved_current'] ) || ! empty( $plan['needs_reapprove'] );
	$live_w   = function_exists( 'gaming_hub_ecoflow_pro_grid_live_watts' )
		? gaming_hub_ecoflow_pro_grid_live_watts( $status )
		: 0;
	$threshold = defined( 'GAMING_HUB_ECOFLOW_FLOW_THRESHOLD_W' )
		? (int) GAMING_HUB_ECOFLOW_FLOW_THRESHOLD_W
		: 8;
	$active    = $live_w >= $threshold;
	$watts     = $active ? $live_w : 0;

	if ( ! empty( $plan['last_apply_error'] ) ) {
		$message = sprintf(
			/* translators: %s: error message */
			__( '送信エラー: %s', 'gaming-hub' ),
			$plan['last_apply_error']
		);
	} elseif ( $active ) {
		$message = $approved && $planned
			? sprintf(
				/* translators: %s: live watts */
				__( '充電計画どおりグリッド充電中。実測 %s W', 'gaming-hub' ),
				number_format_i18n( $live_w )
			)
			: sprintf(
				/* translators: %s: live watts */
				__( '実測グリッド入力 %s W', 'gaming-hub' ),
				number_format_i18n( $live_w )
			);
	} elseif ( ! $approved ) {
		$message = __( '未承認のため、グリッド充電は送りません。', 'gaming-hub' );
	} elseif ( $planned ) {
		$message = sprintf(
			/* translators: %s: charge watts */
			__( '充電計画どおりグリッド充電中。充電上限 %s W', 'gaming-hub' ),
			number_format_i18n( $charge_w )
		);
	} else {
		$message = __( '承認済みの計画どおり。グリッド充電時間外は待機です。', 'gaming-hub' );
	}

	return array(
		'active'  => $active,
		'watts'   => $watts,
		'message' => $message,
	);
}

/**
 * Saved schedule option.
 *
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_get_saved_schedule() {
	$saved = get_option( GAMING_HUB_ECOFLOW_SCHEDULE_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		return array();
	}

	$dirty = false;
	if ( isset( $saved['slots'] ) && is_array( $saved['slots'] ) && function_exists( 'gaming_hub_ecoflow_normalize_plan_slots' ) ) {
		$normalized = gaming_hub_ecoflow_normalize_plan_slots( $saved['slots'] );
		if ( $normalized !== $saved['slots'] ) {
			$saved['slots'] = $normalized;
			$dirty          = true;
		}
	}

	if ( isset( $saved['last_applied_w'] ) && null !== $saved['last_applied_w'] ) {
		$clamped = gaming_hub_ecoflow_clamp_charge_watts( (int) $saved['last_applied_w'] );
		if ( $clamped !== (int) $saved['last_applied_w'] ) {
			$saved['last_applied_w'] = $clamped;
			$dirty                   = true;
		}
	}

	foreach ( array( 'charge_w' => GAMING_HUB_ECOFLOW_PLAN_CHARGE_W, 'idle_w' => GAMING_HUB_ECOFLOW_PLAN_IDLE_W ) as $key => $value ) {
		if ( (int) ( $saved[ $key ] ?? -1 ) !== (int) $value ) {
			$saved[ $key ] = (int) $value;
			$dirty         = true;
		}
	}

	if ( $dirty ) {
		update_option( GAMING_HUB_ECOFLOW_SCHEDULE_OPTION, $saved, false );
	}

	return $saved;
}

/**
 * Adopt the current proposed plan and send only when the charge command changes.
 *
 * @param array<string, mixed> $plan Proposed plan.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_autosync_charge_plan( array $plan ) {
	$plan_id = $plan['plan_id'] ?? '';
	if ( '' === $plan_id ) {
		return gaming_hub_ecoflow_attach_schedule_state( $plan );
	}

	$saved = gaming_hub_ecoflow_get_saved_schedule();
	if ( ( $saved['plan_id'] ?? '' ) === $plan_id && ( $saved['status'] ?? '' ) === 'approved' && ! empty( $saved['auto'] ) ) {
		return gaming_hub_ecoflow_attach_schedule_state( $plan );
	}

	$record = array(
		'status'               => 'approved',
		'plan_id'              => $plan_id,
		'charge_w'             => defined( 'GAMING_HUB_ECOFLOW_PLAN_CHARGE_W' ) ? GAMING_HUB_ECOFLOW_PLAN_CHARGE_W : 1000,
		'idle_w'               => defined( 'GAMING_HUB_ECOFLOW_PLAN_IDLE_W' ) ? GAMING_HUB_ECOFLOW_PLAN_IDLE_W : 0,
		'slots'                => $plan['slots'] ?? array(),
		'approved_at'          => wp_date( 'c' ),
		'approved_by'          => 0,
		'auto'                 => true,
		'last_applied_w'       => $saved['last_applied_w'] ?? null,
		'last_applied_reserve' => $saved['last_applied_reserve'] ?? null,
		'last_applied_hour'    => $saved['last_applied_hour'] ?? '',
		'last_applied_at'      => $saved['last_applied_at'] ?? '',
		'last_apply_error'     => $saved['last_apply_error'] ?? '',
		'send_notice'          => $saved['send_notice'] ?? null,
	);

	update_option( GAMING_HUB_ECOFLOW_SCHEDULE_OPTION, $record, false );

	return gaming_hub_ecoflow_attach_schedule_state( $plan );
}

/**
 * Notice shown on the EcoFlow page after a Pro 3 send.
 *
 * @param int    $watts   Charge watts.
 * @param int    $reserve Backup reserve percent.
 * @param string $error   Empty when successful.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_schedule_send_notice( $watts, $reserve, $error = '' ) {
	$ok = '' === $error;

	if ( $ok ) {
		$message = sprintf(
			/* translators: 1: watts, 2: backup reserve percent */
			__( '充電計画を Pro 3 に送りました。充電上限 %1$s W · 予備残量 %2$s%%', 'gaming-hub' ),
			number_format_i18n( (int) $watts ),
			number_format_i18n( (int) $reserve )
		);
	} else {
		$message = sprintf(
			/* translators: %s: error message */
			__( '充電計画の送信に失敗しました: %s', 'gaming-hub' ),
			$error
		);
	}

	return array(
		'id'       => wp_date( 'U' ) . '-' . wp_generate_password( 6, false, false ),
		'at'       => wp_date( 'c' ),
		'at_label' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
		'watts'    => (int) $watts,
		'reserve'  => (int) $reserve,
		'ok'       => $ok,
		'message'  => $message,
	);
}

/**
 * Approve the current proposed plan and send this hour immediately.
 *
 * @param string $plan_id Client plan id.
 * @return array<string, mixed>|WP_Error
 */
function gaming_hub_ecoflow_approve_schedule( $plan_id ) {
	$status = gaming_hub_get_ecoflow_status( true );
	if ( is_wp_error( $status ) ) {
		return $status;
	}

	$plan = $status['charge_plan'] ?? array();
	if ( empty( $plan['plan_id'] ) || $plan['plan_id'] !== $plan_id ) {
		return new WP_Error( 'ecoflow_plan_stale', __( '表示中の計画が更新されています。再読み込みしてから承認してください。', 'gaming-hub' ) );
	}

	$record = array(
		'status'           => 'approved',
		'plan_id'          => $plan['plan_id'],
		'charge_w'         => GAMING_HUB_ECOFLOW_PLAN_CHARGE_W,
		'idle_w'           => GAMING_HUB_ECOFLOW_PLAN_IDLE_W,
		'slots'            => $plan['slots'] ?? array(),
		'approved_at'      => wp_date( 'c' ),
		'approved_by'      => get_current_user_id(),
		'auto'             => false,
		'last_applied_w'   => null,
		'last_applied_hour'=> '',
		'last_applied_at'  => '',
		'last_apply_error' => '',
	);

	update_option( GAMING_HUB_ECOFLOW_SCHEDULE_OPTION, $record, false );

	$applied = gaming_hub_ecoflow_apply_approved_schedule( true );
	if ( is_wp_error( $applied ) ) {
		return $applied;
	}

	return gaming_hub_ecoflow_attach_schedule_state( $plan );
}

/**
 * Cancel an approved schedule and drop charge to idle watts.
 *
 * @return array<string, mixed>|WP_Error
 */
function gaming_hub_ecoflow_cancel_schedule() {
	$saved = gaming_hub_ecoflow_get_saved_schedule();
	$saved['status']          = 'cancelled';
	$saved['cancelled_at']    = wp_date( 'c' );
	$saved['last_apply_error'] = '';
	update_option( GAMING_HUB_ECOFLOW_SCHEDULE_OPTION, $saved, false );

	$result = gaming_hub_ecoflow_set_pro_charge_watts( GAMING_HUB_ECOFLOW_PLAN_IDLE_W );
	$backup = gaming_hub_ecoflow_set_pro_backup_reserve( GAMING_HUB_ECOFLOW_BACKUP_RESERVE_GRID_OFF );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	if ( is_wp_error( $backup ) ) {
		return $backup;
	}

	$status = gaming_hub_get_ecoflow_status( true );
	if ( is_wp_error( $status ) ) {
		return $status;
	}

	return $status['charge_plan'] ?? array();
}

/**
 * Send the current hour's approved watts to Pro 3.
 *
 * @param bool $force Ignore last-applied cache.
 * @return true|WP_Error
 */
function gaming_hub_ecoflow_apply_approved_schedule( $force = false ) {
	$saved = gaming_hub_ecoflow_get_saved_schedule();
	if ( ( $saved['status'] ?? '' ) !== 'approved' ) {
		return true;
	}

	$is_auto = ! empty( $saved['auto'] );
	$slot    = gaming_hub_ecoflow_current_approved_slot( $saved );
	$missing = ! $slot || null === ( $slot['watts'] ?? null );
	$expire  = $missing && ! $is_auto;

	if ( $missing ) {
		$hour_key = wp_date( 'Y-m-d' ) . 'T' . sprintf( '%02d', (int) wp_date( 'G' ) );
		$watts    = GAMING_HUB_ECOFLOW_PLAN_IDLE_W;
		$reserve  = GAMING_HUB_ECOFLOW_BACKUP_RESERVE_GRID_OFF;
	} else {
		$hour_key = (string) ( $slot['id'] ?? '' );
		$watts    = gaming_hub_ecoflow_clamp_charge_watts( (int) $slot['watts'] );
		$reserve  = gaming_hub_ecoflow_backup_reserve_for_watts( $watts );
	}

	$same_command = (int) ( $saved['last_applied_w'] ?? 0 ) === (int) $watts
		&& (int) ( $saved['last_applied_reserve'] ?? 0 ) === (int) $reserve
		&& null !== ( $saved['last_applied_w'] ?? null );

	if ( ! $force && $same_command ) {
		if ( $expire ) {
			$saved['status'] = 'expired';
			update_option( GAMING_HUB_ECOFLOW_SCHEDULE_OPTION, $saved, false );
		}

		return true;
	}

	if ( ! $force && get_transient( GAMING_HUB_ECOFLOW_SCHEDULE_LOCK ) ) {
		return true;
	}

	set_transient( GAMING_HUB_ECOFLOW_SCHEDULE_LOCK, 1, 25 );

	$result = gaming_hub_ecoflow_apply_charge_and_backup( $watts, $reserve );
	$saved  = gaming_hub_ecoflow_get_saved_schedule();

	if ( is_wp_error( $result ) ) {
		$saved['last_apply_error'] = $result->get_error_message();
		$saved['send_notice']      = gaming_hub_ecoflow_schedule_send_notice( $watts, $reserve, $result->get_error_message() );
		update_option( GAMING_HUB_ECOFLOW_SCHEDULE_OPTION, $saved, false );
		return $result;
	}

	$saved['last_applied_w']       = $watts;
	$saved['last_applied_reserve'] = $reserve;
	$saved['last_applied_hour']    = $hour_key;
	$saved['last_applied_at']      = wp_date( 'c' );
	$saved['last_apply_error']     = '';
	$saved['send_notice']          = gaming_hub_ecoflow_schedule_send_notice( $watts, $reserve );
	if ( $expire ) {
		$saved['status'] = 'expired';
	}
	update_option( GAMING_HUB_ECOFLOW_SCHEDULE_OPTION, $saved, false );

	return true;
}

/**
 * Slot that matches the current local hour.
 *
 * @param array<string, mixed> $saved Saved schedule.
 * @return array<string, mixed>|null
 */
function gaming_hub_ecoflow_current_approved_slot( array $saved ) {
	$date = wp_date( 'Y-m-d' );
	$hour = (int) wp_date( 'G' );
	$id   = $date . 'T' . sprintf( '%02d', $hour );

	foreach ( $saved['slots'] ?? array() as $slot ) {
		if ( ( $slot['id'] ?? '' ) === $id ) {
			return $slot;
		}
	}

	return null;
}

/**
 * Clamp to the idle floor (0 W) and the site charge cap.
 *
 * @param int $watts Requested watts.
 */
function gaming_hub_ecoflow_clamp_charge_watts( $watts ) {
	return max( GAMING_HUB_ECOFLOW_PLAN_IDLE_W, min( GAMING_HUB_ECOFLOW_PLAN_CHARGE_W, (int) $watts ) );
}

/**
 * Backup reserve SOC for a charge-limit setting.
 *
 * Grid charge on → 100%. Grid charge off → 5%.
 *
 * @param int $watts Charge watts.
 */
function gaming_hub_ecoflow_backup_reserve_for_watts( $watts ) {
	return (int) $watts > GAMING_HUB_ECOFLOW_PLAN_IDLE_W
		? GAMING_HUB_ECOFLOW_BACKUP_RESERVE_GRID_ON
		: GAMING_HUB_ECOFLOW_BACKUP_RESERVE_GRID_OFF;
}

/**
 * SET Pro 3 AC charge watts and Energy Backup reserve together.
 *
 * @param int $watts   Charge watts.
 * @param int $reserve Backup reserve percent.
 * @return true|WP_Error
 */
function gaming_hub_ecoflow_apply_charge_and_backup( $watts, $reserve ) {
	$watts   = gaming_hub_ecoflow_clamp_charge_watts( $watts );
	$reserve = max( 5, min( 100, (int) $reserve ) );

	if ( $watts > GAMING_HUB_ECOFLOW_PLAN_IDLE_W ) {
		$backup = gaming_hub_ecoflow_set_pro_backup_reserve( $reserve );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}
		return gaming_hub_ecoflow_set_pro_charge_watts( $watts );
	}

	$charge = gaming_hub_ecoflow_set_pro_charge_watts( $watts );
	if ( is_wp_error( $charge ) ) {
		return $charge;
	}

	return gaming_hub_ecoflow_set_pro_backup_reserve( $reserve );
}

/**
 * SET Pro 3 AC charge watts.
 *
 * @param int $watts Watts.
 * @return true|WP_Error
 */
function gaming_hub_ecoflow_set_pro_charge_watts( $watts ) {
	if ( ! gaming_hub_ecoflow_is_configured() ) {
		return new WP_Error( 'ecoflow_not_configured', __( 'EcoFlow API が未設定です。', 'gaming-hub' ) );
	}

	$config = gaming_hub_get_ecoflow_config();
	$api    = new Gaming_Hub_Ecoflow_Api( $config['access_key'], $config['secret_key'], $config['region'] );
	$result = $api->set_ac_charge_power( $config['device_sn'], gaming_hub_ecoflow_clamp_charge_watts( $watts ) );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return true;
}

/**
 * SET Pro 3 Energy Backup reserve percent.
 *
 * @param int $soc Backup reserve 5–100.
 * @return true|WP_Error
 */
function gaming_hub_ecoflow_set_pro_backup_reserve( $soc ) {
	if ( ! gaming_hub_ecoflow_is_configured() ) {
		return new WP_Error( 'ecoflow_not_configured', __( 'EcoFlow API が未設定です。', 'gaming-hub' ) );
	}

	$config = gaming_hub_get_ecoflow_config();
	$api    = new Gaming_Hub_Ecoflow_Api( $config['access_key'], $config['secret_key'], $config['region'] );
	$result = $api->set_energy_backup( $config['device_sn'], $soc, true );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return true;
}

/**
 * REST: approve.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function gaming_hub_rest_ecoflow_approve_schedule( WP_REST_Request $request ) {
	$plan_id = sanitize_text_field( (string) $request->get_param( 'plan_id' ) );
	$result  = gaming_hub_ecoflow_approve_schedule( $plan_id );

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
			'data'    => $result,
		),
		200
	);
}

/**
 * REST: cancel.
 *
 * @return WP_REST_Response
 */
function gaming_hub_rest_ecoflow_cancel_schedule() {
	$result = gaming_hub_ecoflow_cancel_schedule();

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
			'data'    => $result,
		),
		200
	);
}

/**
 * Register schedule REST + cron.
 */
function gaming_hub_register_ecoflow_schedule_rest() {
	register_rest_route(
		'gaming-hub/v1',
		'/ecoflow/plan/approve',
		array(
			'methods'             => 'POST',
			'callback'            => 'gaming_hub_rest_ecoflow_approve_schedule',
			'permission_callback' => 'gaming_hub_ecoflow_can_control',
			'args'                => array(
				'plan_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	register_rest_route(
		'gaming-hub/v1',
		'/ecoflow/plan/cancel',
		array(
			'methods'             => 'POST',
			'callback'            => 'gaming_hub_rest_ecoflow_cancel_schedule',
			'permission_callback' => 'gaming_hub_ecoflow_can_control',
		)
	);
}
add_action( 'rest_api_init', 'gaming_hub_register_ecoflow_schedule_rest' );

/**
 * Five-minute cron interval.
 *
 * @param array<string, array<string, mixed>> $schedules Schedules.
 * @return array<string, array<string, mixed>>
 */
function gaming_hub_ecoflow_cron_schedules( $schedules ) {
	if ( ! isset( $schedules['five_minutes'] ) ) {
		$schedules['five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes', 'gaming-hub' ),
		);
	}

	return $schedules;
}
add_filter( 'cron_schedules', 'gaming_hub_ecoflow_cron_schedules' );

/**
 * Ensure the apply cron is scheduled.
 */
function gaming_hub_ecoflow_schedule_cron() {
	$next = wp_next_scheduled( GAMING_HUB_ECOFLOW_SCHEDULE_CRON );
	if ( $next && $next < time() - 15 * MINUTE_IN_SECONDS ) {
		wp_clear_scheduled_hook( GAMING_HUB_ECOFLOW_SCHEDULE_CRON );
		$next = false;
	}

	if ( ! $next ) {
		wp_schedule_event( time() + 60, 'five_minutes', GAMING_HUB_ECOFLOW_SCHEDULE_CRON );
	}
}
add_action( 'init', 'gaming_hub_ecoflow_schedule_cron' );

/**
 * Cron callback.
 */
function gaming_hub_ecoflow_run_schedule_cron() {
	if ( function_exists( 'gaming_hub_get_ecoflow_status' ) ) {
		gaming_hub_get_ecoflow_status( true );
		return;
	}

	gaming_hub_ecoflow_apply_approved_schedule( false );
}
add_action( GAMING_HUB_ECOFLOW_SCHEDULE_CRON, 'gaming_hub_ecoflow_run_schedule_cron' );
