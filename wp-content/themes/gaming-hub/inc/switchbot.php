<?php
/**
 * SwitchBot Plug Mini — UPS AC output watts.
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require get_template_directory() . '/inc/switchbot-api.php';

define( 'GAMING_HUB_SWITCHBOT_STATUS_TTL', 12 );
define( 'GAMING_HUB_SWITCHBOT_STALE_TTL', 2 * MINUTE_IN_SECONDS );
define( 'GAMING_HUB_SWITCHBOT_DEVICES_TTL', 30 * MINUTE_IN_SECONDS );
define( 'GAMING_HUB_SWITCHBOT_STATUS_CACHE', 'gaming_hub_switchbot_ups_status' );
define( 'GAMING_HUB_SWITCHBOT_DEVICES_CACHE', 'gaming_hub_switchbot_devices' );

/**
 * SwitchBot credentials from env or Customizer.
 *
 * @return array{token: string, secret: string, device_id: string}
 */
function gaming_hub_get_switchbot_config() {
	return array(
		'token'     => getenv( 'SWITCHBOT_TOKEN' ) ?: get_theme_mod( 'switchbot_token', '' ),
		'secret'    => getenv( 'SWITCHBOT_SECRET' ) ?: get_theme_mod( 'switchbot_secret', '' ),
		'device_id' => getenv( 'SWITCHBOT_UPS_DEVICE_ID' ) ?: get_theme_mod( 'switchbot_ups_device_id', '' ),
	);
}

/**
 * Whether Open API credentials are set.
 */
function gaming_hub_switchbot_is_configured() {
	$config = gaming_hub_get_switchbot_config();
	return '' !== $config['token'] && '' !== $config['secret'];
}

/**
 * Whether SwitchBot UPS readings are active (temporary off — prefer EcoFlow MQTT).
 */
function gaming_hub_switchbot_is_enabled() {
	$flag = getenv( 'SWITCHBOT_ENABLED' );
	if ( false !== $flag && '' !== $flag ) {
		return filter_var( $flag, FILTER_VALIDATE_BOOLEAN );
	}

	return false;
}

/**
 * Attach SwitchBot UPS plug reading onto EcoFlow status.
 *
 * @param array<string, mixed> $status EcoFlow status.
 * @return array<string, mixed>
 */
function gaming_hub_switchbot_attach_ups( array $status ) {
	if ( ! gaming_hub_switchbot_is_enabled() ) {
		return $status;
	}

	$plug = gaming_hub_switchbot_ups_status();
	if ( is_array( $plug ) ) {
		$status['ups_plug'] = $plug;
	}

	return $status;
}

/**
 * Cached Plug Mini status for the UPS outlet.
 *
 * @return array<string, mixed>|null
 */
function gaming_hub_switchbot_ups_status() {
	if ( ! gaming_hub_switchbot_is_configured() ) {
		return null;
	}

	$cached = get_transient( GAMING_HUB_SWITCHBOT_STATUS_CACHE );
	if ( is_array( $cached ) && isset( $cached['fetched_at'] ) ) {
		$age = time() - (int) $cached['fetched_at'];
		if ( $age >= 0 && $age < GAMING_HUB_SWITCHBOT_STATUS_TTL && empty( $cached['error'] ) ) {
			return $cached;
		}
	}

	$fresh = gaming_hub_switchbot_fetch_ups_status();
	if ( is_array( $fresh ) ) {
		$fresh['fetched_at'] = time();
		set_transient( GAMING_HUB_SWITCHBOT_STATUS_CACHE, $fresh, GAMING_HUB_SWITCHBOT_STALE_TTL );
		return $fresh;
	}

	if ( is_array( $cached ) && isset( $cached['watts'] ) ) {
		$cached['stale'] = true;
		if ( is_wp_error( $fresh ) ) {
			$cached['error'] = $fresh->get_error_message();
		}
		return $cached;
	}

	return null;
}

/**
 * Fetch Plug Mini watts from Open API.
 *
 * @return array<string, mixed>|WP_Error
 */
function gaming_hub_switchbot_fetch_ups_status() {
	$config = gaming_hub_get_switchbot_config();
	$api    = new Gaming_Hub_Switchbot_Api( $config['token'], $config['secret'] );

	$device_id = $config['device_id'];
	$device    = null;

	if ( '' === $device_id ) {
		$device = gaming_hub_switchbot_resolve_ups_device( $api );
		if ( is_wp_error( $device ) ) {
			return $device;
		}
		$device_id = (string) ( $device['deviceId'] ?? '' );
	}

	if ( '' === $device_id ) {
		return new WP_Error(
			'switchbot_no_plug',
			__( 'SwitchBot Plug Mini が見つかりません。デバイス ID を Customizer に入力してください。', 'gaming-hub' )
		);
	}

	$status = $api->get_device_status( $device_id );
	if ( is_wp_error( $status ) ) {
		return $status;
	}

	$watts = null;
	if ( isset( $status['weight'] ) && is_numeric( $status['weight'] ) ) {
		$watts = round( (float) $status['weight'], 1 );
	}

	return array(
		'device_id'   => $device_id,
		'device_name' => is_array( $device )
			? ( $device['deviceName'] ?? ( $status['deviceType'] ?? 'SwitchBot Plug' ) )
			: ( $status['deviceType'] ?? 'SwitchBot Plug' ),
		'device_type' => $status['deviceType'] ?? ( $device['deviceType'] ?? '' ),
		'power'       => $status['power'] ?? '',
		'voltage'     => isset( $status['voltage'] ) && is_numeric( $status['voltage'] )
			? (float) $status['voltage']
			: null,
		'watts'       => $watts,
		'source'      => 'switchbot',
	);
}

/**
 * Pick the UPS Plug Mini from the account device list.
 *
 * @param Gaming_Hub_Switchbot_Api $api API client.
 * @return array<string, mixed>|WP_Error
 */
function gaming_hub_switchbot_resolve_ups_device( Gaming_Hub_Switchbot_Api $api ) {
	$devices = get_transient( GAMING_HUB_SWITCHBOT_DEVICES_CACHE );
	if ( ! is_array( $devices ) ) {
		$devices = $api->get_devices();
		if ( is_wp_error( $devices ) ) {
			return $devices;
		}
		set_transient( GAMING_HUB_SWITCHBOT_DEVICES_CACHE, $devices, GAMING_HUB_SWITCHBOT_DEVICES_TTL );
	}

	$config = gaming_hub_get_switchbot_config();
	$picked = gaming_hub_switchbot_pick_plug( $devices, $config['device_id'] );

	if ( ! $picked ) {
		return new WP_Error(
			'switchbot_no_plug',
			__( 'SwitchBot Plug Mini が見つかりません。', 'gaming-hub' )
		);
	}

	return $picked;
}

/**
 * Choose a metering plug, preferring an explicit ID or UPS-like name.
 *
 * @param array<int, array<string, mixed>> $devices      Device list.
 * @param string                           $preferred_id Optional device ID.
 * @return array<string, mixed>|null
 */
function gaming_hub_switchbot_pick_plug( array $devices, $preferred_id = '' ) {
	$plugs = array();

	foreach ( $devices as $device ) {
		if ( ! is_array( $device ) ) {
			continue;
		}

		$type = (string) ( $device['deviceType'] ?? '' );
		if ( false === stripos( $type, 'Plug' ) ) {
			continue;
		}

		$plugs[] = $device;
	}

	if ( $preferred_id ) {
		foreach ( $plugs as $plug ) {
			if ( (string) ( $plug['deviceId'] ?? '' ) === (string) $preferred_id ) {
				return $plug;
			}
		}
	}

	$keywords = array( 'ups', '常時', '1500', 'ecoflow', 'delta' );
	foreach ( $plugs as $plug ) {
		$name = (string) ( $plug['deviceName'] ?? '' );
		foreach ( $keywords as $keyword ) {
			if ( function_exists( 'mb_stripos' ) ) {
				if ( false !== mb_stripos( $name, $keyword ) ) {
					return $plug;
				}
			} elseif ( false !== stripos( $name, $keyword ) ) {
				return $plug;
			}
		}
	}

	foreach ( $plugs as $plug ) {
		if ( false !== stripos( (string) ( $plug['deviceType'] ?? '' ), 'Plug Mini' ) ) {
			return $plug;
		}
	}

	return $plugs[0] ?? null;
}

/**
 * Customizer fields for SwitchBot Open API.
 *
 * @param WP_Customize_Manager $wp_customize Customizer.
 */
function gaming_hub_customize_register_switchbot( $wp_customize ) {
	$wp_customize->add_section(
		'gaming_hub_switchbot_api',
		array(
			'title'       => __( 'SwitchBot API (UPS Plug)', 'gaming-hub' ),
			'priority'    => 38,
			'description' => __( '常時稼働エリア (UPS) の AC 出力 W に Plug Mini の実測を使います。Token と Secret は開発者向けオプションから。デバイス ID は空なら Plug Mini を自動選択します。', 'gaming-hub' ),
		)
	);

	$wp_customize->add_setting(
		'switchbot_token',
		array(
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
	$wp_customize->add_control(
		'switchbot_token',
		array(
			'label'   => __( 'Token', 'gaming-hub' ),
			'section' => 'gaming_hub_switchbot_api',
			'type'    => 'password',
		)
	);

	$wp_customize->add_setting(
		'switchbot_secret',
		array(
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
	$wp_customize->add_control(
		'switchbot_secret',
		array(
			'label'   => __( 'Secret Key', 'gaming-hub' ),
			'section' => 'gaming_hub_switchbot_api',
			'type'    => 'password',
		)
	);

	$wp_customize->add_setting(
		'switchbot_ups_device_id',
		array(
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
	$wp_customize->add_control(
		'switchbot_ups_device_id',
		array(
			'label'       => __( 'UPS Plug device ID (optional)', 'gaming-hub' ),
			'description' => __( '空欄ならアカウント内の Plug Mini を自動選択します。', 'gaming-hub' ),
			'section'     => 'gaming_hub_switchbot_api',
			'type'        => 'text',
		)
	);
}
add_action( 'customize_register', 'gaming_hub_customize_register_switchbot' );

/**
 * Drop SwitchBot caches after Customizer save so a new token is used immediately.
 */
function gaming_hub_switchbot_clear_cache() {
	delete_transient( GAMING_HUB_SWITCHBOT_STATUS_CACHE );
	delete_transient( GAMING_HUB_SWITCHBOT_DEVICES_CACHE );
}
add_action( 'customize_save_after', 'gaming_hub_switchbot_clear_cache' );
