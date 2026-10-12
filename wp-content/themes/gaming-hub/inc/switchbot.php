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
require get_template_directory() . '/inc/switchbot-aircon.php';

define( 'GAMING_HUB_SWITCHBOT_STATUS_TTL', 12 );
define( 'GAMING_HUB_SWITCHBOT_STALE_TTL', 2 * MINUTE_IN_SECONDS );
define( 'GAMING_HUB_SWITCHBOT_DEVICES_TTL', 30 * MINUTE_IN_SECONDS );
define( 'GAMING_HUB_SWITCHBOT_STATUS_CACHE', 'gaming_hub_switchbot_ups_status' );
define( 'GAMING_HUB_SWITCHBOT_DEVICES_CACHE', 'gaming_hub_switchbot_devices' );
// Open API allows 10,000 calls/day; 30 s keeps the A/C plug under ~2,900.
define( 'GAMING_HUB_SWITCHBOT_AC_TTL', 30 );
define( 'GAMING_HUB_SWITCHBOT_AC_CACHE', 'gaming_hub_switchbot_ac_status' );
// The A/C counts as ON at or above this plug wattage, OFF below (IR state is unknowable).
define( 'GAMING_HUB_SWITCHBOT_AC_RUNNING_W', 200 );
define( 'GAMING_HUB_SWITCHBOT_AC_HEAT_BELOW_C', 18 );

/**
 * SwitchBot credentials from env or Customizer.
 *
 * @return array{token: string, secret: string, device_id: string, ac_plug_id: string}
 */
function gaming_hub_get_switchbot_config() {
	return array(
		'token'      => getenv( 'SWITCHBOT_TOKEN' ) ?: get_theme_mod( 'switchbot_token', '' ),
		'secret'     => getenv( 'SWITCHBOT_SECRET' ) ?: get_theme_mod( 'switchbot_secret', '' ),
		'device_id'  => getenv( 'SWITCHBOT_UPS_DEVICE_ID' ) ?: get_theme_mod( 'switchbot_ups_device_id', '' ),
		'ac_plug_id' => getenv( 'SWITCHBOT_AC_PLUG_ID' ) ?: get_theme_mod( 'switchbot_ac_plug_id', '' ),
	);
}

/**
 * Whether a plug's name marks it as the room A/C plug.
 *
 * @param array<string, mixed> $device SwitchBot device.
 */
function gaming_hub_switchbot_is_ac_plug_name( array $device ) {
	$name = (string) ( $device['deviceName'] ?? '' );
	foreach ( array( 'a/c', 'aircon', 'air con', 'エアコン' ) as $keyword ) {
		$hit = function_exists( 'mb_stripos' ) ? mb_stripos( $name, $keyword ) : stripos( $name, $keyword );
		if ( false !== $hit ) {
			return true;
		}
	}

	return false;
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
			__('SwitchBot Plug Mini not found. Enter the device ID in the Customizer.', 'gaming-hub')
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
	$devices = gaming_hub_switchbot_cached_devices( $api );
	if ( is_wp_error( $devices ) ) {
		return $devices;
	}

	$config = gaming_hub_get_switchbot_config();
	$picked = gaming_hub_switchbot_pick_plug( $devices, $config['device_id'] );

	if ( ! $picked ) {
		return new WP_Error(
			'switchbot_no_plug',
			__('SwitchBot Plug Mini not found.', 'gaming-hub')
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
	$plugs = gaming_hub_switchbot_list_plugs( $devices );

	if ( $preferred_id ) {
		foreach ( $plugs as $plug ) {
			if ( (string) ( $plug['deviceId'] ?? '' ) === (string) $preferred_id ) {
				return $plug;
			}
		}
	}

	// Never auto-pick the room A/C plug as the UPS meter.
	$ac_id = gaming_hub_get_switchbot_config()['ac_plug_id'];
	$plugs = array_values( array_filter( $plugs, static function ( $plug ) use ( $ac_id ) {
		if ( '' !== $ac_id && (string) ( $plug['deviceId'] ?? '' ) === (string) $ac_id ) {
			return false;
		}
		return ! gaming_hub_switchbot_is_ac_plug_name( $plug );
	} ) );

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
 * Plug devices from the account device list.
 *
 * @param array<int, array<string, mixed>> $devices Device list.
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_switchbot_list_plugs( array $devices ) {
	$plugs = array();
	foreach ( $devices as $device ) {
		if ( is_array( $device ) && false !== stripos( (string) ( $device['deviceType'] ?? '' ), 'Plug' ) ) {
			$plugs[] = $device;
		}
	}

	return $plugs;
}

/**
 * Account device list, cached.
 *
 * @param Gaming_Hub_Switchbot_Api $api API client.
 * @return array<int, array<string, mixed>>|WP_Error
 */
function gaming_hub_switchbot_cached_devices( Gaming_Hub_Switchbot_Api $api ) {
	$devices = get_transient( GAMING_HUB_SWITCHBOT_DEVICES_CACHE );
	if ( is_array( $devices ) ) {
		return $devices;
	}

	$devices = $api->get_devices();
	if ( ! is_wp_error( $devices ) ) {
		set_transient( GAMING_HUB_SWITCHBOT_DEVICES_CACHE, $devices, GAMING_HUB_SWITCHBOT_DEVICES_TTL );
	}

	return $devices;
}

/**
 * Whether the room A/C card shows fake readings (local preview only).
 */
function gaming_hub_switchbot_ac_sample_enabled() {
	return filter_var( getenv( 'SWITCHBOT_AC_SAMPLE' ), FILTER_VALIDATE_BOOLEAN );
}

/**
 * Fake A/C reading that drifts over time so the card looks live.
 *
 * @return array<string, mixed>
 */
function gaming_hub_switchbot_ac_sample_status() {
	$now   = time();
	$saved = function_exists( 'gaming_hub_aircon_saved_state' ) ? gaming_hub_aircon_saved_state() : null;
	$watts = ( $saved && 'off' === $saved['power'] )
		? 0.8
		: round( 520 + 180 * sin( $now / 90 ), 1 );

	return array(
		'device_id'   => 'SAMPLE',
		'device_name' => 'shinichiy room A/C',
		'device_type' => 'Plug Mini (JP)',
		'power'       => 'on',
		'voltage'     => 100.4,
		'watts'       => $watts,
		'running'     => $watts >= GAMING_HUB_SWITCHBOT_AC_RUNNING_W,
		'source'      => 'switchbot',
		'sample'      => true,
		'fetched_at'  => $now,
	);
}

/**
 * Fake "today" A/C energy (Wh) for the sample mode, growing through the day.
 */
function gaming_hub_switchbot_ac_sample_today_wh() {
	$midnight = new DateTimeImmutable( 'today', wp_timezone() );
	$seconds  = time() - $midnight->getTimestamp();
	return (int) round( max( 0, $seconds ) / 3600 * 380 );
}

/**
 * Whether the room A/C plug reading is on (default on once SwitchBot is configured).
 */
function gaming_hub_switchbot_ac_is_enabled() {
	if ( gaming_hub_switchbot_ac_sample_enabled() ) {
		return true;
	}

	if ( ! gaming_hub_switchbot_is_configured() ) {
		return false;
	}

	$flag = getenv( 'SWITCHBOT_AC_ENABLED' );
	if ( false !== $flag && '' !== $flag ) {
		return filter_var( $flag, FILTER_VALIDATE_BOOLEAN );
	}

	return true;
}

/**
 * A/C mode (cool / heat). The plug cannot report it, so use SWITCHBOT_AC_MODE
 * when set, else infer from Tajimi outdoor temperature.
 *
 * @return array{mode: string, mode_source: string, outdoor_temp: float|null}
 */
function gaming_hub_switchbot_ac_mode() {
	$saved = function_exists( 'gaming_hub_aircon_saved_state' ) ? gaming_hub_aircon_saved_state() : null;
	if ( $saved ) {
		return array(
			'mode'         => $saved['mode'],
			'mode_source'  => 'remote',
			'outdoor_temp' => null,
		);
	}

	$forced = strtolower( (string) getenv( 'SWITCHBOT_AC_MODE' ) );
	if ( in_array( $forced, array( 'cool', 'heat' ), true ) ) {
		return array(
			'mode'         => $forced,
			'mode_source'  => 'manual',
			'outdoor_temp' => null,
		);
	}

	$temp = null;
	if ( function_exists( 'gaming_hub_tajimi_fetch_open_meteo' ) ) {
		$payload = gaming_hub_tajimi_fetch_open_meteo();
		if ( is_array( $payload ) && isset( $payload['current']['temperature_2m'] ) && is_numeric( $payload['current']['temperature_2m'] ) ) {
			$temp = (float) $payload['current']['temperature_2m'];
		}
	}

	if ( null === $temp ) {
		$month = (int) wp_date( 'n' );
		$mode  = ( $month >= 11 || $month <= 4 ) ? 'heat' : 'cool';
	} else {
		$mode = $temp < GAMING_HUB_SWITCHBOT_AC_HEAT_BELOW_C ? 'heat' : 'cool';
	}

	return array(
		'mode'         => $mode,
		'mode_source'  => null === $temp ? 'season' : 'outdoor',
		'outdoor_temp' => $temp,
	);
}

/**
 * Attach the room A/C plug reading onto EcoFlow status as `ac_plug`.
 *
 * @param array<string, mixed> $status EcoFlow status.
 * @return array<string, mixed>
 */
function gaming_hub_switchbot_attach_ac( array $status ) {
	if ( ! gaming_hub_switchbot_ac_is_enabled() ) {
		return $status;
	}

	$plug = gaming_hub_switchbot_ac_status();
	if ( is_array( $plug ) ) {
		$mode  = gaming_hub_switchbot_ac_mode();
		$saved = gaming_hub_aircon_saved_state();
		$status['ac_plug'] = array_merge(
			$plug,
			$mode,
			array(
				'set_power'   => $saved ? $saved['power'] : null,
				'target_temp' => $saved ? $saved['temp'] : gaming_hub_aircon_default_temp( $mode['mode'] ),
			)
		);
	}

	return $status;
}

/**
 * Cached Plug Mini status for the room A/C (on the Delta 1500 UPS output).
 *
 * @return array<string, mixed>|null
 */
function gaming_hub_switchbot_ac_status() {
	if ( gaming_hub_switchbot_ac_sample_enabled() ) {
		return gaming_hub_switchbot_ac_sample_status();
	}

	$cached = get_transient( GAMING_HUB_SWITCHBOT_AC_CACHE );
	if ( is_array( $cached ) && isset( $cached['fetched_at'] ) ) {
		$age = time() - (int) $cached['fetched_at'];
		if ( $age >= 0 && $age < GAMING_HUB_SWITCHBOT_AC_TTL && empty( $cached['error'] ) ) {
			return $cached;
		}
	}

	$fresh = gaming_hub_switchbot_fetch_ac_status();
	if ( is_array( $fresh ) ) {
		$fresh['fetched_at'] = time();
		set_transient( GAMING_HUB_SWITCHBOT_AC_CACHE, $fresh, GAMING_HUB_SWITCHBOT_STALE_TTL );
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
 * Fetch the room A/C Plug Mini reading from Open API.
 *
 * @return array<string, mixed>|WP_Error
 */
function gaming_hub_switchbot_fetch_ac_status() {
	$config = gaming_hub_get_switchbot_config();
	$api    = new Gaming_Hub_Switchbot_Api( $config['token'], $config['secret'] );

	$device_id = (string) $config['ac_plug_id'];
	$device    = null;

	if ( '' === $device_id ) {
		$devices = gaming_hub_switchbot_cached_devices( $api );
		if ( is_wp_error( $devices ) ) {
			return $devices;
		}
		foreach ( gaming_hub_switchbot_list_plugs( $devices ) as $plug ) {
			if ( gaming_hub_switchbot_is_ac_plug_name( $plug ) ) {
				$device = $plug;
				break;
			}
		}
		if ( ! $device ) {
			return new WP_Error(
				'switchbot_no_ac_plug',
				__('SwitchBot A/C plug not found. Name it with "A/C" or set its device ID.', 'gaming-hub')
			);
		}
		$device_id = (string) ( $device['deviceId'] ?? '' );
	}

	$status = $api->get_device_status( $device_id );
	if ( is_wp_error( $status ) ) {
		return $status;
	}

	$watts = isset( $status['weight'] ) && is_numeric( $status['weight'] )
		? round( max( 0, (float) $status['weight'] ), 1 )
		: null;
	$power = strtolower( (string) ( $status['power'] ?? '' ) );

	return array(
		'device_id'   => $device_id,
		'device_name' => is_array( $device ) ? (string) ( $device['deviceName'] ?? '' ) : '',
		'device_type' => (string) ( $status['deviceType'] ?? ( $device['deviceType'] ?? '' ) ),
		'power'       => $power,
		'voltage'     => isset( $status['voltage'] ) && is_numeric( $status['voltage'] ) ? (float) $status['voltage'] : null,
		'watts'       => $watts,
		'running'     => 'off' !== $power && null !== $watts && $watts >= GAMING_HUB_SWITCHBOT_AC_RUNNING_W,
		'source'      => 'switchbot',
	);
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
			'description' => __('Use Plug Mini measured watts for always-on UPS AC output. Token and Secret come from developer options. Leave device ID empty to auto-pick a Plug Mini.', 'gaming-hub'),
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
			'description' => __('Leave blank to auto-select a Plug Mini on the account.', 'gaming-hub'),
			'section'     => 'gaming_hub_switchbot_api',
			'type'        => 'text',
		)
	);

	$wp_customize->add_setting(
		'switchbot_ac_plug_id',
		array(
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
	$wp_customize->add_control(
		'switchbot_ac_plug_id',
		array(
			'label'       => __('Room A/C Plug device ID (optional)', 'gaming-hub'),
			'description' => __('Leave blank to use the plug whose name contains "A/C".', 'gaming-hub'),
			'section'     => 'gaming_hub_switchbot_api',
			'type'        => 'text',
		)
	);

	$wp_customize->add_setting(
		'switchbot_ac_ir_id',
		array(
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
	$wp_customize->add_control(
		'switchbot_ac_ir_id',
		array(
			'label'       => __('Room A/C IR remote ID (optional)', 'gaming-hub'),
			'description' => __('Leave blank to use the first air conditioner remote on the Hub Mini.', 'gaming-hub'),
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
	delete_transient( GAMING_HUB_SWITCHBOT_AC_CACHE );
	delete_transient( GAMING_HUB_AIRCON_IR_CACHE );
}
add_action( 'customize_save_after', 'gaming_hub_switchbot_clear_cache' );
