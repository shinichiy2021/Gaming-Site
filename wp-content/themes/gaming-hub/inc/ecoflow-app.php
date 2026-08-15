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
define( 'GAMING_HUB_ECOFLOW_BRIDGE_CACHE_STALE_TTL', 86400 );

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
		return __(
			'Googleログインのみのアカウントです。EcoFlowアプリで「ログインパスワード」を設定し、そのメールアドレスとパスワードを Customizer に入力してください。（Googleログインそのものは MQTT では使えません）',
			'gaming-hub'
		);
	}

	if ( false !== stripos( $error, 'server is too busy' ) || false !== stripos( $error, 'too busy' ) ) {
		return __(
			'EcoFlow ログイン API が混雑しています（1日10個までの MQTT client ID 制限の可能性）。5〜30分おきに自動再試行します。直前の MQTT 計測値があれば表示を継続します。',
			'gaming-hub'
		);
	}

	if ( false !== stripos( $error, 'not authorized' ) || false !== stripos( $error, 'MQTT 認証' ) ) {
		return __(
			'MQTT 認証に失敗しました。日本の EcoFlow アカウントは「外観 → カスタマイズ → EcoFlow API → API Region」を Asia にしてください。保存後、docker compose restart ecoflow-bridge を実行してください。',
			'gaming-hub'
		);
	}

	if ( false !== stripos( $error, 'Waiting for' ) || false !== stripos( $error, 'bridge-config' ) ) {
		return __(
			'MQTT ブリッジの設定待ちです。外観 → カスタマイズ → EcoFlow API に App Login を入力し、docker compose up -d ecoflow-bridge を実行してください。',
			'gaming-hub'
		);
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
 * Read fresh quota map from the App Login bridge cache file.
 *
 * @param string $device_sn Device serial.
 * @return array<string, mixed>|null
 */
function gaming_hub_ecoflow_read_bridge_quota( $device_sn ) {
	gaming_hub_ecoflow_sync_bridge_config();

	$path = gaming_hub_ecoflow_bridge_cache_path( $device_sn );

	if ( ! file_exists( $path ) ) {
		return null;
	}

	$age = time() - (int) filemtime( $path );
	if ( $age > GAMING_HUB_ECOFLOW_BRIDGE_CACHE_STALE_TTL ) {
		return null;
	}

	$stale = $age > GAMING_HUB_ECOFLOW_BRIDGE_CACHE_TTL;

	$raw = json_decode( (string) file_get_contents( $path ), true );
	if ( ! is_array( $raw ) || empty( $raw ) ) {
		return null;
	}

	if ( isset( $raw['error'] ) ) {
		return null;
	}

	return $raw;
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
		$bridge_hint = __( 'MQTT ブリッジ待機中 — docker compose up -d ecoflow-bridge', 'gaming-hub' );
	}

	$api_note = $reason ?: __( 'Developer API 非対応 — Delta 3 は App Login (MQTT) が必要です。', 'gaming-hub' );
	$note     = $api_note;
	if ( '' !== $bridge_hint ) {
		$note .= ' / MQTT: ' . $bridge_hint;
	}

	$inferred = array(
		'device_sn'       => $device_sn,
		'device_name'     => $device_name,
		'online'          => $online,
		'battery_percent' => null,
		'solar_in'        => 0,
		'hv_in'           => 0,
		'input_total'     => 0,
		'output_total'    => 0,
		'ac_in'           => 0,
		'ac_out'          => 0,
		'dc_out'          => 0,
		'battery_temp'    => null,
		'remain_time'     => null,
		'is_charging'     => false,
		'is_discharging'  => false,
		'charge_state'    => __( '独立運転', 'gaming-hub' ),
		'inferred'        => true,
		'inferred_note'   => $note,
		'updated_at'      => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
		'extra'           => gaming_hub_ecoflow_extra_battery_slice(),
	);

	return gaming_hub_ecoflow_merge_bridge_quota(
		array_merge( $inferred, gaming_hub_ecoflow_main_pack_defaults( $inferred['battery_percent'] ) )
	);
}
