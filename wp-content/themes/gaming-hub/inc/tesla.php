<?php
/**
 * Tesla Fleet API integration for Model 3 on the Powerwall dashboard.
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require get_template_directory() . '/inc/tesla-api.php';

define( 'GAMING_HUB_TESLA_ACCESS_TOKEN_KEY', 'gaming_hub_tesla_access_token' );
define( 'GAMING_HUB_TESLA_REFRESH_TOKEN_OPTION', 'gaming_hub_tesla_refresh_token' );
define( 'GAMING_HUB_TESLA_FLEET_URL_OPTION', 'gaming_hub_tesla_fleet_base_url' );
define( 'GAMING_HUB_TESLA_FLEET_DEFAULT_URL', 'https://fleet-api.prd.na.vn.cloud.tesla.com' );

/**
 * Read Tesla-related environment variables (Docker / .env).
 *
 * @param string $key Environment variable name.
 */
function gaming_hub_tesla_env( $key ) {
	if ( defined( $key ) ) {
		$value = constant( $key );
		if ( is_string( $value ) && '' !== $value ) {
			return $value;
		}
	}

	if ( isset( $_ENV[ $key ] ) && is_string( $_ENV[ $key ] ) && '' !== $_ENV[ $key ] ) {
		return $_ENV[ $key ];
	}

	if ( isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) && '' !== $_SERVER[ $key ] ) {
		return $_SERVER[ $key ];
	}

	$value = getenv( $key );

	return ( false !== $value && '' !== $value ) ? (string) $value : '';
}

/**
 * Default Fleet API base URL (Japan / APAC uses NA region).
 */
function gaming_hub_tesla_default_fleet_base_url() {
	$configured = gaming_hub_tesla_env( 'TESLA_FLEET_API_BASE_URL' );

	if ( $configured ) {
		return untrailingslashit( $configured );
	}

	$saved = get_option( GAMING_HUB_TESLA_FLEET_URL_OPTION, '' );
	if ( is_string( $saved ) && '' !== $saved ) {
		return untrailingslashit( $saved );
	}

	return GAMING_HUB_TESLA_FLEET_DEFAULT_URL;
}

/**
 * Tesla API credentials and vehicle settings.
 *
 * @return array<string, string>
 */
function gaming_hub_get_tesla_config() {
	$refresh = gaming_hub_tesla_env( 'TESLA_REFRESH_TOKEN' ) ?: get_option( GAMING_HUB_TESLA_REFRESH_TOKEN_OPTION, '' );

	if ( ! $refresh ) {
		$refresh = get_theme_mod( 'tesla_refresh_token', '' );
	}

	return array(
		'client_id'      => gaming_hub_tesla_env( 'TESLA_CLIENT_ID' ) ?: get_theme_mod( 'tesla_client_id', '' ),
		'client_secret'  => gaming_hub_tesla_env( 'TESLA_CLIENT_SECRET' ) ?: get_theme_mod( 'tesla_client_secret', '' ),
		'refresh_token'  => (string) $refresh,
		'vehicle_vin'    => gaming_hub_tesla_env( 'TESLA_VEHICLE_VIN' ) ?: get_theme_mod( 'tesla_vehicle_vin', '' ),
		'fleet_base_url' => gaming_hub_tesla_default_fleet_base_url(),
		'redirect_uri'   => gaming_hub_tesla_env( 'TESLA_REDIRECT_URI' ) ?: rest_url( 'gaming-hub/v1/tesla/oauth/callback' ),
	);
}

/**
 * Candidate Fleet API base URLs (Japan uses NA/APAC endpoint).
 *
 * @return array<int, string>
 */
function gaming_hub_tesla_fleet_url_candidates() {
	$candidates = array(
		gaming_hub_tesla_default_fleet_base_url(),
		GAMING_HUB_TESLA_FLEET_DEFAULT_URL,
		'https://fleet-api.prd.eu.vn.cloud.tesla.com',
	);

	return array_values( array_unique( $candidates ) );
}

/**
 * Persist discovered Fleet API base URL.
 *
 * @param string $url Fleet API base URL.
 */
function gaming_hub_tesla_save_fleet_base_url( $url ) {
	$url = untrailingslashit( (string) $url );

	if ( '' !== $url ) {
		update_option( GAMING_HUB_TESLA_FLEET_URL_OPTION, $url, false );
	}
}

/**
 * Ensure the API client has a regional Fleet API base URL.
 *
 * @param Gaming_Hub_Tesla_Api $api Tesla API client with access token set.
 * @return true|WP_Error
 */
function gaming_hub_tesla_ensure_fleet_base_url( Gaming_Hub_Tesla_Api $api ) {
	$fleet_url = $api->get_fleet_base_url();

	if ( '' === $fleet_url ) {
		$fleet_url = gaming_hub_tesla_default_fleet_base_url();
		$api->set_fleet_base_url( $fleet_url );
	}

	gaming_hub_tesla_save_fleet_base_url( $fleet_url );

	return true;
}

/**
 * User-friendly Tesla API error text for the Powerwall dashboard.
 *
 * @param WP_Error $error API error.
 */
function gaming_hub_tesla_user_facing_error( WP_Error $error ) {
	$code    = $error->get_error_code();
	$message = $error->get_error_message();

	if ( 'tesla_partner_not_registered' === $code || false !== stripos( $message, 'must be registered in the current region' ) ) {
		return __(
			'Tesla Fleet API: アプリのリージョン登録（partner_accounts）が未完了です。Fleet API を使うには本番ドメインで公開鍵を設置し、developer.tesla.com の Allowed Origins と同じドメインを Tesla に登録する必要があります（localhost のみでは取得できません）。',
			'gaming-hub'
		);
	}

	return $message;
}

/**
 * Whether Tesla Model 3 polling is configured.
 */
function gaming_hub_tesla_model3_is_configured() {
	$config = gaming_hub_get_tesla_config();

	return ! empty( $config['client_id'] )
		&& ! empty( $config['client_secret'] )
		&& ! empty( $config['refresh_token'] )
		&& ! empty( $config['vehicle_vin'] );
}

/**
 * Build Tesla OAuth authorize URL.
 */
function gaming_hub_tesla_oauth_authorize_url() {
	$config = gaming_hub_get_tesla_config();

	if ( empty( $config['client_id'] ) || empty( $config['redirect_uri'] ) ) {
		return '';
	}

	$state = wp_generate_password( 24, false );
	set_transient( 'gaming_hub_tesla_oauth_state_' . $state, 1, 15 * MINUTE_IN_SECONDS );

	$params = array(
		'client_id'     => $config['client_id'],
		'redirect_uri'  => $config['redirect_uri'],
		'response_type' => 'code',
		'scope'         => 'openid offline_access vehicle_device_data',
		'state'         => $state,
	);

	return add_query_arg( $params, 'https://auth.tesla.com/oauth2/v3/authorize' );
}

/**
 * Persist rotated refresh token when Tesla returns a new one.
 *
 * @param array<string, mixed> $tokens Token payload.
 */
function gaming_hub_tesla_store_tokens( array $tokens ) {
	if ( ! empty( $tokens['refresh_token'] ) ) {
		update_option( GAMING_HUB_TESLA_REFRESH_TOKEN_OPTION, (string) $tokens['refresh_token'], false );
	}

	if ( ! empty( $tokens['access_token'] ) ) {
		$expires = isset( $tokens['expires_in'] ) ? max( 300, (int) $tokens['expires_in'] - 120 ) : 45 * MINUTE_IN_SECONDS;
		set_transient( GAMING_HUB_TESLA_ACCESS_TOKEN_KEY, (string) $tokens['access_token'], $expires );
	}
}

/**
 * @return Gaming_Hub_Tesla_Api|WP_Error
 */
function gaming_hub_tesla_get_api() {
	$config = gaming_hub_get_tesla_config();

	if ( ! gaming_hub_tesla_model3_is_configured() ) {
		return new WP_Error( 'tesla_not_configured', __( 'Tesla API is not configured.', 'gaming-hub' ) );
	}

	$api = new Gaming_Hub_Tesla_Api(
		$config['client_id'],
		$config['client_secret'],
		$config['fleet_base_url']
	);

	$access_token = get_transient( GAMING_HUB_TESLA_ACCESS_TOKEN_KEY );

	if ( ! $access_token ) {
		$tokens = $api->refresh_access_token( $config['refresh_token'] );

		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		gaming_hub_tesla_store_tokens( $tokens );
		$access_token = $tokens['access_token'];
	}

	$api->set_access_token( $access_token );

	$fleet_ready = gaming_hub_tesla_ensure_fleet_base_url( $api );
	if ( is_wp_error( $fleet_ready ) ) {
		return $fleet_ready;
	}

	return $api;
}

/**
 * Map Tesla charge_state to dashboard model3 payload.
 *
 * @param array<string, mixed> $charge_state Tesla charge_state object.
 * @return array<string, mixed>
 */
function gaming_hub_tesla_model3_from_charge_state( array $charge_state ) {
	$state    = (string) ( $charge_state['charging_state'] ?? '' );
	$charging = in_array( $state, array( 'Charging', 'Starting' ), true );
	$power_w  = (int) round( (float) ( $charge_state['charger_power'] ?? 0 ) * 1000 );

	if ( ! $charging ) {
		$power_w = 0;
	}

	$labels = array(
		'Charging' => __( '充電中', 'gaming-hub' ),
		'Starting' => __( '充電中', 'gaming-hub' ),
		'Complete' => __( '充電完了', 'gaming-hub' ),
		'Stopped'  => __( '停止', 'gaming-hub' ),
	);

	return array(
		'battery_percent' => max( 0, min( 100, (int) round( $charge_state['battery_level'] ?? 0 ) ) ),
		'is_charging'     => $charging,
		'charge_state'    => $labels[ $state ] ?? __( '待機中', 'gaming-hub' ),
		'watts'           => $power_w,
		'vehicle_name'    => (string) ( $charge_state['vehicle_name'] ?? 'Model 3' ),
	);
}

/**
 * Fetch live Model 3 status from Tesla Fleet API.
 *
 * @return array<string, mixed>|WP_Error
 */
function gaming_hub_fetch_tesla_model3_status() {
	$config = gaming_hub_get_tesla_config();
	$api    = gaming_hub_tesla_get_api();

	if ( is_wp_error( $api ) ) {
		return $api;
	}

	$charge_state = $api->get_vehicle_charge_state( $config['vehicle_vin'] );

	if ( is_wp_error( $charge_state ) ) {
		return $charge_state;
	}

	return gaming_hub_tesla_model3_from_charge_state( $charge_state );
}

/**
 * Recalculate grid / battery flow after replacing Model 3 demo values.
 *
 * @param array<string, mixed> $status Flow status with updated model3.
 * @return array<string, mixed>
 */
function gaming_hub_powerwall_recalc_flow_load( array $status ) {
	$solar     = (float) ( $status['solar_w'] ?? 0 );
	$home      = (float) ( $status['home_w'] ?? 0 );
	$model3_w  = (float) ( $status['model3']['watts'] ?? 0 );
	$load      = $home + $model3_w;
	$powerwall = is_array( $status['powerwall'] ?? null ) ? $status['powerwall'] : array();

	$grid_import        = 0.0;
	$solar_to_powerwall = 0.0;
	$powerwall_watts    = (float) ( $powerwall['watts'] ?? 0 );
	$is_charging        = ! empty( $powerwall['is_charging'] );
	$is_discharging     = ! empty( $powerwall['is_discharging'] );
	$charge_state       = (string) ( $powerwall['charge_state'] ?? __( '待機中', 'gaming-hub' ) );

	if ( $solar >= $load ) {
		$excess             = $solar - $load;
		$solar_to_powerwall = min( $excess, 4500 );
		$powerwall_watts    = $solar_to_powerwall;
		$is_charging        = $solar_to_powerwall >= 80;
		$is_discharging     = false;
		$charge_state       = $is_charging ? __( '充電中', 'gaming-hub' ) : __( '待機中', 'gaming-hub' );
	} else {
		$deficit         = $load - $solar;
		$from_battery    = min( $deficit, 8000 );
		$grid_import     = max( 0, $deficit - $from_battery );
		$powerwall_watts = $from_battery;
		$is_charging     = false;
		$is_discharging  = $from_battery >= 80;
		$charge_state    = $is_discharging ? __( '放電中', 'gaming-hub' ) : __( '待機中', 'gaming-hub' );

		if ( $grid_import >= 80 && ! $is_discharging ) {
			$charge_state = __( 'グリッド充電', 'gaming-hub' );
		}
	}

	$status['grid_export_w']      = 0;
	$status['grid_import_w']      = (int) round( $grid_import );
	$status['solar_to_powerwall'] = (int) round( $solar_to_powerwall );
	$status['powerwall']          = array_merge(
		$powerwall,
		array(
			'is_charging'    => $is_charging,
			'is_discharging' => $is_discharging,
			'charge_state'   => $charge_state,
			'watts'          => (int) round( abs( $powerwall_watts ) ),
		)
	);

	return $status;
}

/**
 * Setup instructions for Tesla Model 3 API.
 */
function gaming_hub_render_tesla_setup_instructions() {
	$authorize = gaming_hub_tesla_oauth_authorize_url();
	?>
	<ol class="pw-flow-setup-steps">
		<li><?php esc_html_e( 'developer.tesla.com でアプリを作成し Client ID / Secret を取得', 'gaming-hub' ); ?></li>
		<li><?php esc_html_e( '.env または 外観 → カスタマイズ → Tesla API に Client ID / Secret / VIN を設定', 'gaming-hub' ); ?></li>
		<li>
			<?php esc_html_e( 'Tesla アカウント連携:', 'gaming-hub' ); ?>
			<?php if ( $authorize ) : ?>
				<a href="<?php echo esc_url( $authorize ); ?>" class="btn btn-outline btn-sm" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Tesla で認証', 'gaming-hub' ); ?>
				</a>
			<?php else : ?>
				<?php esc_html_e( 'Client ID を設定すると認証リンクが表示されます', 'gaming-hub' ); ?>
			<?php endif; ?>
		</li>
		<li><?php esc_html_e( 'Redirect URI: /wp-json/gaming-hub/v1/tesla/oauth/callback', 'gaming-hub' ); ?></li>
		<li>
			<?php
			esc_html_e(
				'Fleet API 利用には partner_accounts 登録が必要です（本番ドメイン + 公開鍵）。localhost だけでは vehicle_data は取得できません。',
				'gaming-hub'
			);
			?>
		</li>
		<li>
			<?php
			esc_html_e(
				'日本のアカウントは NA リージョン: TESLA_FLEET_API_BASE_URL=https://fleet-api.prd.na.vn.cloud.tesla.com',
				'gaming-hub'
			);
			?>
		</li>
	</ol>
	<?php
}

/**
 * REST: Tesla OAuth callback.
 *
 * @param WP_REST_Request $request Request.
 */
function gaming_hub_rest_tesla_oauth_callback( WP_REST_Request $request ) {
	$code  = sanitize_text_field( (string) $request->get_param( 'code' ) );
	$state = sanitize_text_field( (string) $request->get_param( 'state' ) );

	if ( ! $code || ! $state || ! get_transient( 'gaming_hub_tesla_oauth_state_' . $state ) ) {
		return new WP_REST_Response(
			array(
				'success' => false,
				'message' => __( 'Invalid Tesla OAuth state.', 'gaming-hub' ),
			),
			400
		);
	}

	delete_transient( 'gaming_hub_tesla_oauth_state_' . $state );

	$config = gaming_hub_get_tesla_config();
	$api    = new Gaming_Hub_Tesla_Api( $config['client_id'], $config['client_secret'] );
	$tokens = $api->exchange_authorization_code( $code, $config['redirect_uri'] );

	if ( is_wp_error( $tokens ) ) {
		return new WP_REST_Response(
			array(
				'success' => false,
				'message' => $tokens->get_error_message(),
			),
			400
		);
	}

	gaming_hub_tesla_store_tokens( $tokens );

	$api->set_access_token( (string) $tokens['access_token'] );
	$fleet_ready = gaming_hub_tesla_ensure_fleet_base_url( $api );

	if ( is_wp_error( $fleet_ready ) ) {
		return new WP_REST_Response(
			array(
				'success' => false,
				'message' => $fleet_ready->get_error_message(),
			),
			400
		);
	}

	wp_safe_redirect( add_query_arg( 'tesla_connected', '1', gaming_hub_powerwall_url() ) );
	exit;
}

/**
 * Register Tesla REST routes.
 */
function gaming_hub_register_tesla_rest_routes() {
	register_rest_route(
		'gaming-hub/v1',
		'/tesla/oauth/callback',
		array(
			'methods'             => 'GET',
			'callback'            => 'gaming_hub_rest_tesla_oauth_callback',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'gaming_hub_register_tesla_rest_routes' );

/**
 * Show admin notice after successful Tesla OAuth.
 */
function gaming_hub_tesla_oauth_admin_notice() {
	if ( ! is_page( 'powerwall' ) || empty( $_GET['tesla_connected'] ) ) {
		return;
	}

	echo '<div class="pw-flow-oauth-notice">' . esc_html__( 'Tesla アカウントを連携しました。Model 3 の実データを取得します。', 'gaming-hub' ) . '</div>';
}
add_action( 'wp_body_open', 'gaming_hub_tesla_oauth_admin_notice', 20 );
