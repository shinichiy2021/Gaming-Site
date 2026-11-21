<?php
/**
 * EcoFlow App Login bridge helpers (Delta 3 / D361 series).
 *
 * Developer API does not expose quota for D361/D362/D381 devices.
 * The Node bridge reads bridge-config.json from wp-content/ecoflow-cache/.
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_ECOFLOW_BRIDGE_CACHE_TTL', 90 );
define( 'GAMING_HUB_ECOFLOW_EXTRA_CACHE_TTL', 600 );
define( 'GAMING_HUB_ECOFLOW_BRIDGE_CACHE_STALE_TTL', 86400 );
define( 'GAMING_HUB_ECOFLOW_MQTT_ORIGIN_TTL', 8 );
define( 'GAMING_HUB_ECOFLOW_MQTT_ORIGIN_TRANSIENT', 'gaming_hub_ecoflow_mqtt_origin_v1' );

/**
 * Production origin for Delta 1500 MQTT (same host as energy origin), or empty on the canonical server.
 */
function gaming_hub_ecoflow_mqtt_origin_base() {
	if ( function_exists( 'gaming_hub_ecoflow_energy_origin_base' ) ) {
		return gaming_hub_ecoflow_energy_origin_base();
	}

	return '';
}

/**
 * Whether this site should read Delta 1500 live data from Release MQTT (via origin status API).
 */
function gaming_hub_ecoflow_mqtt_origin_enabled() {
	return '' !== gaming_hub_ecoflow_mqtt_origin_base();
}

/**
 * Fetch EcoFlow status from the Release origin (includes Delta 1500 MQTT fields).
 *
 * @return array<string, mixed>|null
 */
function gaming_hub_ecoflow_fetch_origin_mqtt_status() {
	static $done = false;
	static $memo = null;

	if ( $done ) {
		return $memo;
	}
	$done = true;

	$base = gaming_hub_ecoflow_mqtt_origin_base();
	if ( '' === $base ) {
		return null;
	}

	$cached = get_transient( GAMING_HUB_ECOFLOW_MQTT_ORIGIN_TRANSIENT );
	if ( is_array( $cached ) && isset( $cached['secondary'] ) && is_array( $cached['secondary'] ) ) {
		$memo = $cached;
		return $cached;
	}

	$url = $base . '/wp-json/gaming-hub/v1/ecoflow/status';
	$res = wp_remote_get(
		$url,
		array(
			'timeout' => 10,
			'headers' => array( 'Accept' => 'application/json' ),
		)
	);
	if ( is_wp_error( $res ) ) {
		return null;
	}

	$code = (int) wp_remote_retrieve_response_code( $res );
	$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( 200 !== $code || ! is_array( $body ) || empty( $body['success'] ) || ! is_array( $body['data'] ?? null ) ) {
		return null;
	}

	$memo = $body['data'];
	set_transient( GAMING_HUB_ECOFLOW_MQTT_ORIGIN_TRANSIENT, $memo, GAMING_HUB_ECOFLOW_MQTT_ORIGIN_TTL );

	return $memo;
}

/**
 * Replace local Delta 1500 / LV fields with Release MQTT values.
 *
 * @param array<string, mixed> $status Local EcoFlow status.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_apply_origin_mqtt( array $status ) {
	if ( ! gaming_hub_ecoflow_mqtt_origin_enabled() ) {
		return $status;
	}

	$remote = gaming_hub_ecoflow_fetch_origin_mqtt_status();
	if ( ! is_array( $remote ) || ! isset( $remote['secondary'] ) || ! is_array( $remote['secondary'] ) ) {
		return $status;
	}

	$secondary = $remote['secondary'];
	$secondary['mqtt_origin'] = gaming_hub_ecoflow_mqtt_origin_base();
	if ( ! array_key_exists( 'mqtt_live', $secondary ) ) {
		$secondary['mqtt_live'] = ! empty( $secondary['online'] );
	}

	$status['secondary'] = $secondary;

	if ( isset( $remote['solar_delta'] ) && is_numeric( $remote['solar_delta'] ) ) {
		$status['solar_delta'] = max( 0, (int) round( (float) $remote['solar_delta'] ) );
		$status['solar_in']    = $status['solar_delta'];
	} elseif ( isset( $secondary['solar_in'] ) && is_numeric( $secondary['solar_in'] ) ) {
		$status['solar_delta'] = max( 0, (int) round( (float) $secondary['solar_in'] ) );
		$status['solar_in']    = $status['solar_delta'];
	}

	if ( isset( $remote['ups_plug'] ) && is_array( $remote['ups_plug'] ) ) {
		$status['ups_plug'] = $remote['ups_plug'];
	}

	return $status;
}

/**
 * Serial prefixes that require App Login instead of Developer API quota.
 *
 * @param string $device_sn Device serial.
 */
function gaming_hub_ecoflow_is_app_only_device( $device_sn ) {
	$prefixes = array( 'D361', 'D362', 'D381', 'R641', 'R651' );

	foreach ( $prefixes as $prefix ) {
		if ( 0 === strpos( $device_sn, $prefix ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Get optional App Login credentials.
 *
 * @return array{email: string, password: string}
 */
function gaming_hub_get_ecoflow_app_config() {
	return array(
		'email'    => getenv( 'ECOFLOW_APP_EMAIL' ) ?: get_theme_mod( 'ecoflow_app_email', '' ),
		'password' => getenv( 'ECOFLOW_APP_PASSWORD' ) ?: get_theme_mod( 'ecoflow_app_password', '' ),
	);
}

/**
 * Human-readable bridge/MQTT error for the dashboard.
 *
 * @param string $error Raw bridge error.
 */
function gaming_hub_ecoflow_format_bridge_error( $error ) {
	$error = trim( (string) $error );

	if ( '' === $error ) {
		return '';
	}

	if (
		false !== stripos( $error, "account doesn't exist" )
		|| false !== stripos( $error, 'incorrect password' )
		|| false !== stripos( $error, 'Googleログインのみ' )
	) {
		return __('This account is Google-login only. Set a login password in the EcoFlow app and enter that email and password in the Customizer. Google login itself cannot be used for MQTT.', 'gaming-hub');
	}

	if ( false !== stripos( $error, 'server is too busy' ) || false !== stripos( $error, 'too busy' ) ) {
		return __('EcoFlow login API is busy (possible 10 MQTT client IDs / day limit). Retrying every 5–30 minutes. Shows n/a while live MQTT is unavailable.', 'gaming-hub');
	}

	if ( false !== stripos( $error, 'not authorized' ) || false !== stripos( $error, 'MQTT 認証' ) ) {
		return __('MQTT auth failed. For Japan EcoFlow accounts set Appearance → Customize → EcoFlow API → API Region to Asia, save, then run docker compose restart ecoflow-bridge.', 'gaming-hub');
	}

	if ( false !== stripos( $error, 'Waiting for' ) || false !== stripos( $error, 'bridge-config' ) ) {
		return __('Waiting for MQTT bridge setup. Enter App Login under Appearance → Customize → EcoFlow API, then run docker compose up -d ecoflow-bridge.', 'gaming-hub');
	}

	return $error;
}

/**
 * Directory shared with the Node MQTT bridge container.
 */
function gaming_hub_ecoflow_bridge_cache_dir() {
	$dir = WP_CONTENT_DIR . '/ecoflow-cache';

	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	return $dir;
}

/**
 * Sync WordPress / Customizer credentials for the Node bridge.
 */
function gaming_hub_ecoflow_sync_bridge_config() {
	// Local reads Delta MQTT from Release; do not refresh credentials for a competing local bridge.
	if ( gaming_hub_ecoflow_mqtt_origin_enabled() ) {
		return;
	}

	$app    = gaming_hub_get_ecoflow_app_config();
	$config = gaming_hub_get_ecoflow_config();

	if ( empty( $app['email'] ) || empty( $app['password'] ) || empty( $config['device_sn_2'] ) ) {
		return;
	}

	$payload = array(
		'email'       => $app['email'],
		'password'    => $app['password'],
		'device_sn_2' => $config['device_sn_2'],
		'region'      => $config['region'] ?: 'us',
		'updated_at'  => gmdate( 'c' ),
	);

	$path = trailingslashit( gaming_hub_ecoflow_bridge_cache_dir() ) . 'bridge-config.json';

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $path, wp_json_encode( $payload ) );
}

/**
 * Path to JSON cache written by the App Login bridge.
 *
 * @param string $device_sn Device serial.
 */
function gaming_hub_ecoflow_bridge_cache_path( $device_sn ) {
	return trailingslashit( gaming_hub_ecoflow_bridge_cache_dir() ) . sanitize_file_name( $device_sn ) . '.json';
}

/**
 * Read bridge status for dashboard diagnostics.
 *
 * @return array<string, mixed>|null
 */
function gaming_hub_ecoflow_read_bridge_status() {
	$path = trailingslashit( gaming_hub_ecoflow_bridge_cache_dir() ) . 'bridge-status.json';

	if ( ! file_exists( $path ) ) {
		return null;
	}

	$raw = json_decode( (string) file_get_contents( $path ), true );

	return is_array( $raw ) ? $raw : null;
}

/**
 * Whether the App Login MQTT bridge cache is fresh enough to display.
 *
 * @param string $device_sn Device serial.
 */
function gaming_hub_ecoflow_bridge_is_live( $device_sn = '' ) {
	if ( gaming_hub_ecoflow_mqtt_origin_enabled() ) {
		$remote = gaming_hub_ecoflow_fetch_origin_mqtt_status();
		if ( ! is_array( $remote ) || ! isset( $remote['secondary'] ) || ! is_array( $remote['secondary'] ) ) {
			return false;
		}

		$sec = $remote['secondary'];
		$device_sn = (string) $device_sn;
		if ( '' !== $device_sn && ! empty( $sec['device_sn'] ) && (string) $sec['device_sn'] !== $device_sn ) {
			return false;
		}

		return ! empty( $sec['online'] ) || ! empty( $sec['mqtt_live'] );
	}

	$status = gaming_hub_ecoflow_read_bridge_status();
	if ( is_array( $status ) ) {
		if ( empty( $status['ok'] ) ) {
			return false;
		}

		if ( ! empty( $status['updated_at'] ) ) {
			$ts = strtotime( (string) $status['updated_at'] );
			if ( $ts && ( time() - $ts ) > GAMING_HUB_ECOFLOW_BRIDGE_CACHE_TTL ) {
				return false;
			}
		}
	}

	$device_sn = (string) $device_sn;
	if ( '' === $device_sn ) {
		return is_array( $status ) && ! empty( $status['ok'] );
	}

	$path = gaming_hub_ecoflow_bridge_cache_path( $device_sn );
	if ( ! file_exists( $path ) ) {
		return false;
	}

	return ( time() - (int) filemtime( $path ) ) <= GAMING_HUB_ECOFLOW_BRIDGE_CACHE_TTL;
}

/**
 * Read fresh quota map from the App Login bridge cache file.
 *
 * @param string $device_sn Device serial.
 * @return array<string, mixed>|null
 */
function gaming_hub_ecoflow_read_bridge_quota_file( $device_sn ) {
	// Local uses Release MQTT; a stale on-disk cache must not override live origin SOC.
	if ( gaming_hub_ecoflow_mqtt_origin_enabled() ) {
		return null;
	}

	gaming_hub_ecoflow_sync_bridge_config();

	$device_sn = (string) $device_sn;
	if ( '' === $device_sn ) {
		return null;
	}

	$path = gaming_hub_ecoflow_bridge_cache_path( $device_sn );
	if ( ! file_exists( $path ) ) {
		return null;
	}

	$raw = json_decode( (string) file_get_contents( $path ), true );
	if ( ! is_array( $raw ) || empty( $raw ) || isset( $raw['error'] ) ) {
		return null;
	}

	return $raw;
}

/**
 * Unix timestamp of the last Extra Battery (bms_slave) MQTT packet.
 */
function gaming_hub_ecoflow_bridge_extra_updated_ts() {
	$status = gaming_hub_ecoflow_read_bridge_status();
	if ( ! is_array( $status ) || empty( $status['extra_updated_at'] ) ) {
		return 0;
	}

	$ts = strtotime( (string) $status['extra_updated_at'] );

	return $ts ? (int) $ts : 0;
}

/**
 * Age in seconds of Extra Battery MQTT, or null if never received.
 *
 * @return int|null
 */
function gaming_hub_ecoflow_extra_cache_age() {
	$ts = gaming_hub_ecoflow_bridge_extra_updated_ts();

	return $ts > 0 ? time() - $ts : null;
}

/**
 * Whether Extra Battery MQTT is fresh enough to show (live or last known).
 */
function gaming_hub_ecoflow_extra_is_fresh() {
	$age = gaming_hub_ecoflow_extra_cache_age();

	return null !== $age && $age <= GAMING_HUB_ECOFLOW_EXTRA_CACHE_TTL;
}

/**
 * Whether Extra Battery MQTT arrived within the live bridge window.
 */
function gaming_hub_ecoflow_extra_is_live() {
	$age = gaming_hub_ecoflow_extra_cache_age();

	return null !== $age && $age <= GAMING_HUB_ECOFLOW_BRIDGE_CACHE_TTL;
}

/**
 * Read fresh quota map from the App Login bridge cache file.
 *
 * @param string $device_sn Device serial.
 * @return array<string, mixed>|null
 */
function gaming_hub_ecoflow_read_bridge_quota( $device_sn ) {
	if ( gaming_hub_ecoflow_mqtt_origin_enabled() ) {
		return null;
	}

	if ( ! gaming_hub_ecoflow_bridge_is_live( $device_sn ) ) {
		return null;
	}

	return gaming_hub_ecoflow_read_bridge_quota_file( $device_sn );
}

/**
 * Independent Delta 3 placeholder when App Login / MQTT quota is unavailable.
 *
 * @param array<string, mixed> $primary     Primary (Pro 3) status.
 * @param string               $device_sn   Secondary serial.
 * @param string               $device_name Secondary name.
 * @param bool                 $online      Online flag.
 * @param string               $reason      Why inference was used.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_infer_secondary_from_primary( array $primary, $device_sn, $device_name, $online, $reason = '' ) {
	$bridge_status = gaming_hub_ecoflow_read_bridge_status();
	$bridge_hint   = '';

	if ( is_array( $bridge_status ) && empty( $bridge_status['ok'] ) && ! empty( $bridge_status['error'] ) ) {
		$bridge_hint = gaming_hub_ecoflow_format_bridge_error( $bridge_status['error'] );
	} elseif ( ! gaming_hub_ecoflow_read_bridge_quota( $device_sn ) ) {
		$bridge_hint = __('Waiting for MQTT bridge — docker compose up -d ecoflow-bridge', 'gaming-hub');
	}

	$api_note = $reason ?: __('Developer API unsupported — Delta 3 needs App Login (MQTT).', 'gaming-hub');
	$note     = $api_note;
	if ( '' !== $bridge_hint ) {
		$note .= ' / MQTT: ' . $bridge_hint;
	}

	$inferred = array(
		'device_sn'       => $device_sn,
		'device_name'     => $device_name,
		'online'          => $online,
		'battery_percent' => null,
		'solar_in'        => null,
		'hv_in'           => 0,
		'input_total'     => null,
		'output_total'    => null,
		'ac_in'           => null,
		'ac_out'          => null,
		'dc_out'          => null,
		'battery_temp'    => null,
		'remain_time'     => null,
		'is_charging'     => false,
		'is_discharging'  => false,
		'mqtt_live'       => false,
		'soc_source'      => 'unavailable',
		'solar_in_source' => 'unavailable',
		'charge_state'    => __('n/a', 'gaming-hub'),
		'inferred'        => true,
		'inferred_note'   => $note,
		'updated_at'      => gaming_hub_format_date( null, 'datetime' ),
		'extra'           => gaming_hub_ecoflow_extra_battery_slice(),
	);

	return gaming_hub_ecoflow_merge_bridge_quota(
		array_merge( $inferred, gaming_hub_ecoflow_main_pack_defaults( $inferred['battery_percent'] ) )
	);
}
