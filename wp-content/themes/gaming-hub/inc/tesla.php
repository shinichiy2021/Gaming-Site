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
function gaming_hub_tesla_get_refresh_token() {
	$refresh = get_option( GAMING_HUB_TESLA_REFRESH_TOKEN_OPTION, '' );

	if ( is_string( $refresh ) && '' !== $refresh ) {
		return $refresh;
	}

	$refresh = get_theme_mod( 'tesla_refresh_token', '' );
	if ( is_string( $refresh ) && '' !== $refresh ) {
		return $refresh;
	}

	return gaming_hub_tesla_env( 'TESLA_REFRESH_TOKEN' );
}

function gaming_hub_get_tesla_config() {
	return array(
		'client_id'      => gaming_hub_tesla_env( 'TESLA_CLIENT_ID' ) ?: get_theme_mod( 'tesla_client_id', '' ),
		'client_secret'  => gaming_hub_tesla_env( 'TESLA_CLIENT_SECRET' ) ?: get_theme_mod( 'tesla_client_secret', '' ),
		'refresh_token'  => gaming_hub_tesla_get_refresh_token(),
		'vehicle_vin'    => gaming_hub_tesla_env( 'TESLA_VEHICLE_VIN' ) ?: get_theme_mod( 'tesla_vehicle_vin', '' ),
		'fleet_base_url' => gaming_hub_tesla_default_fleet_base_url(),
		'redirect_uri'   => gaming_hub_tesla_oauth_redirect_uri(),
	);
}

/**
 * Whether a host is local development.
 *
 * @param string $host Hostname.
 */
function gaming_hub_tesla_is_local_host( $host = '' ) {
	if ( '' === $host ) {
		$parsed = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host   = is_string( $parsed ) ? $parsed : '';
	}

	$host = strtolower( (string) $host );

	return in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true )
		|| (bool) preg_match( '/\.local$/', $host );
}

/**
 * Registered Tesla OAuth redirect URI (production callback).
 */
function gaming_hub_tesla_oauth_redirect_uri() {
	$configured = gaming_hub_tesla_env( 'TESLA_REDIRECT_URI' );
	if ( $configured ) {
		return $configured;
	}

	if ( ! gaming_hub_tesla_is_local_host() ) {
		return rest_url( 'gaming-hub/v1/tesla/oauth/callback' );
	}

	$origin = gaming_hub_tesla_env( 'GAMING_HUB_ENERGY_ORIGIN' );
	$host   = $origin ? wp_parse_url( $origin, PHP_URL_HOST ) : gaming_hub_tesla_partner_domain();

	if ( ! is_string( $host ) || '' === $host || gaming_hub_tesla_is_local_host( $host ) ) {
		$host = 'shinichiy-gaming-hub.com';
	}

	return 'https://' . strtolower( $host ) . '/wp-json/gaming-hub/v1/tesla/oauth/callback';
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

	if ( false !== stripos( $message, 'user session flushed' ) ) {
		return __(
			'Tesla の refresh token が無効です。Powerwall ページの「Tesla で認証」から本番ドメインで再連携してください。',
			'gaming-hub'
		);
	}

	if ( 'tesla_missing_charge_state' === $code || false !== stripos( $message, 'charge_state' ) ) {
		return __(
			'Tesla から充電データ（charge_state）が返りませんでした。車がスリープ中のことがあります。Tesla アプリで車両を起こしてから再読み込みしてください。',
			'gaming-hub'
		);
	}

	return $message;
}

/**
 * Root domain used for Tesla partner registration.
 */
function gaming_hub_tesla_partner_domain() {
	$domain = gaming_hub_tesla_env( 'TESLA_PARTNER_DOMAIN' );
	if ( $domain ) {
		return strtolower( preg_replace( '#^https?://#', '', rtrim( $domain, '/' ) ) );
	}

	$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

	return is_string( $host ) ? strtolower( $host ) : '';
}

/**
 * URL where Tesla expects the partner EC public key.
 */
function gaming_hub_tesla_public_key_url( $domain = '' ) {
	$domain = $domain ?: gaming_hub_tesla_partner_domain();

	if ( '' === $domain ) {
		return '';
	}

	return 'https://' . $domain . '/.well-known/appspecific/com.tesla.3p.public-key.pem';
}

/**
 * Check whether the public key is reachable over HTTPS.
 *
 * @return true|WP_Error
 */
function gaming_hub_tesla_verify_public_key_hosted() {
	$url = gaming_hub_tesla_public_key_url();

	if ( '' === $url ) {
		return new WP_Error( 'tesla_invalid_domain', __( 'Tesla partner domain is not configured.', 'gaming-hub' ) );
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 20,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = (string) wp_remote_retrieve_body( $response );

	if ( 200 !== $code || false === strpos( $body, 'BEGIN PUBLIC KEY' ) ) {
		return new WP_Error(
			'tesla_public_key_missing',
			sprintf(
				/* translators: %s: public key URL */
				__( 'Tesla public key is not reachable at %s (HTTP %d).', 'gaming-hub' ),
				$url,
				$code
			)
		);
	}

	return true;
}

/**
 * Register partner domain + public key with Tesla Fleet API.
 *
 * @param string $domain Optional override domain.
 * @return array<string, mixed>|WP_Error
 */
function gaming_hub_tesla_register_partner_account( $domain = '' ) {
	$config = gaming_hub_get_tesla_config();

	if ( empty( $config['client_id'] ) || empty( $config['client_secret'] ) ) {
		return new WP_Error( 'tesla_not_configured', __( 'Tesla Client ID / Secret are not configured.', 'gaming-hub' ) );
	}

	$domain = $domain ?: gaming_hub_tesla_partner_domain();
	$hosted = gaming_hub_tesla_verify_public_key_hosted();

	if ( is_wp_error( $hosted ) ) {
		return $hosted;
	}

	$api = new Gaming_Hub_Tesla_Api(
		$config['client_id'],
		$config['client_secret'],
		$config['fleet_base_url']
	);

	$result = $api->register_partner_account( $domain );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	update_option( 'gaming_hub_tesla_partner_registered', $domain, false );
	update_option( 'gaming_hub_tesla_partner_registered_at', wp_date( 'c' ), false );

	return $result;
}

/**
 * Whether Tesla partner registration has been recorded locally.
 */
function gaming_hub_tesla_partner_is_registered() {
	$domain = get_option( 'gaming_hub_tesla_partner_registered', '' );

	return is_string( $domain ) && '' !== $domain && $domain === gaming_hub_tesla_partner_domain();
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
 * Shared secret for Tesla OAuth state (works across local → production callback).
 */
function gaming_hub_tesla_oauth_state_key() {
	$secret = gaming_hub_tesla_env( 'TESLA_CLIENT_SECRET' ) ?: get_theme_mod( 'tesla_client_secret', '' );

	return '' !== $secret ? $secret : wp_salt( 'auth' );
}

/**
 * Create a signed OAuth state that production can verify without a local transient.
 */
function gaming_hub_tesla_oauth_state() {
	$issued = (string) time();
	$nonce  = wp_generate_password( 16, false );
	$body   = $issued . '.' . $nonce;
	$state  = $body . '.' . hash_hmac( 'sha256', $body, gaming_hub_tesla_oauth_state_key() );

	set_transient( 'gaming_hub_tesla_oauth_state_' . $state, 1, 15 * MINUTE_IN_SECONDS );

	return $state;
}

/**
 * Whether Tesla OAuth state is valid.
 *
 * @param string $state OAuth state.
 */
function gaming_hub_tesla_oauth_state_is_valid( $state ) {
	$state = (string) $state;

	if ( '' === $state ) {
		return false;
	}

	if ( get_transient( 'gaming_hub_tesla_oauth_state_' . $state ) ) {
		return true;
	}

	$parts = explode( '.', $state );
	if ( 3 !== count( $parts ) ) {
		return false;
	}

	list( $issued, $nonce, $sig ) = $parts;

	if ( ! ctype_digit( $issued ) || ! preg_match( '/^[A-Za-z0-9]+$/', $nonce ) ) {
		return false;
	}

	if ( abs( time() - (int) $issued ) > 15 * MINUTE_IN_SECONDS ) {
		return false;
	}

	$expected = hash_hmac( 'sha256', $issued . '.' . $nonce, gaming_hub_tesla_oauth_state_key() );

	return hash_equals( $expected, $sig );
}

/**
 * Build Tesla OAuth authorize URL.
 */
function gaming_hub_tesla_oauth_authorize_url() {
	$config = gaming_hub_get_tesla_config();

	if ( empty( $config['client_id'] ) || empty( $config['redirect_uri'] ) ) {
		return '';
	}

	$params = array(
		'client_id'     => $config['client_id'],
		'redirect_uri'  => $config['redirect_uri'],
		'response_type' => 'code',
		'scope'         => 'openid offline_access vehicle_device_data vehicle_location',
		'state'         => gaming_hub_tesla_oauth_state(),
	);

	return add_query_arg( $params, 'https://auth.tesla.com/oauth2/v3/authorize' );
}

/**
 * Render the Tesla OAuth button.
 */
function gaming_hub_render_tesla_oauth_button() {
	$authorize = gaming_hub_tesla_oauth_authorize_url();

	if ( ! $authorize ) {
		echo '<span class="pw-flow-oauth-missing">' . esc_html__( 'Client ID を設定すると認証リンクが表示されます', 'gaming-hub' ) . '</span>';
		return;
	}
	?>
	<a href="<?php echo esc_url( $authorize ); ?>" class="btn btn-outline btn-sm pw-tesla-oauth-btn" target="_blank" rel="noopener noreferrer">
		<?php esc_html_e( 'Tesla で認証', 'gaming-hub' ); ?>
	</a>
	<?php
}

/**
 * Persist rotated refresh token when Tesla returns a new one.
 *
 * @param array<string, mixed> $tokens Token payload.
 */
function gaming_hub_tesla_store_tokens( array $tokens ) {
	delete_transient( GAMING_HUB_TESLA_ACCESS_TOKEN_KEY );

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
			delete_transient( GAMING_HUB_TESLA_ACCESS_TOKEN_KEY );

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
 * Convert Tesla miles to kilometres.
 *
 * @param mixed $miles Distance in miles.
 * @return float|null
 */
function gaming_hub_tesla_miles_to_km( $miles ) {
	if ( null === $miles || '' === $miles || ! is_numeric( $miles ) ) {
		return null;
	}

	return (float) $miles * 1.60934;
}

/**
 * Map Tesla charging_state to a short gaming HUD label.
 *
 * @param string $state   Tesla charging_state.
 * @param bool   $charging Whether charge power is live.
 */
function gaming_hub_tesla_model3_hud_state( $state, $charging ) {
	if ( $charging ) {
		return 'Starting' === $state
			? __( 'レイド開始', 'gaming-hub' )
			: __( 'チャージレイド', 'gaming-hub' );
	}

	$labels = array(
		'Complete'     => __( 'レイドクリア', 'gaming-hub' ),
		'Stopped'      => __( '停止', 'gaming-hub' ),
		'Disconnected' => __( '待機', 'gaming-hub' ),
		'NoPower'      => __( '待機', 'gaming-hub' ),
		'Starting'     => __( 'レイド開始', 'gaming-hub' ),
		'Charging'     => __( 'チャージレイド', 'gaming-hub' ),
	);

	return $labels[ $state ] ?? __( '待機', 'gaming-hub' );
}

/**
 * Classify charge supply for the HUD (home vs Supercharger). Never uses GPS.
 *
 * @param array<string, mixed> $charge_state Tesla charge_state.
 * @param bool                 $charging     Live charging.
 * @return array{kind: string, label: string, plugged: bool}
 */
function gaming_hub_tesla_model3_supply( array $charge_state, $charging ) {
	$cable = strtoupper( (string) ( $charge_state['conn_charge_cable'] ?? '' ) );
	$fast  = ! empty( $charge_state['fast_charger_present'] );
	$plugged = $fast || ( '' !== $cable && 'NONE' !== $cable && '<INVALID>' !== $cable );

	if ( $fast || false !== stripos( (string) ( $charge_state['fast_charger_type'] ?? '' ), 'Supercharger' ) ) {
		return array(
			'kind'    => 'supercharger',
			'label'   => __( 'フィールド補給', 'gaming-hub' ),
			'plugged' => true,
		);
	}

	if ( $plugged || $charging ) {
		return array(
			'kind'    => 'home',
			'label'   => __( '拠点補給', 'gaming-hub' ),
			'plugged' => true,
		);
	}

	return array(
		'kind'    => 'none',
		'label'   => __( '未接続', 'gaming-hub' ),
		'plugged' => false,
	);
}

/**
 * Record odometer and compute today's driving km (no location).
 *
 * @param float $odometer_km Latest odometer in km.
 * @return array{today_km: float, today_start_km: float, odometer_km: float}
 */
function gaming_hub_tesla_model3_record_odometer( $odometer_km ) {
	$odometer_km = max( 0, (float) $odometer_km );
	$today       = wp_date( 'Y-m-d' );
	$saved       = get_option( GAMING_HUB_MODEL3_ODO_OPTION, array() );
	$saved       = is_array( $saved ) ? $saved : array();
	$saved_date  = (string) ( $saved['date'] ?? '' );
	$last_km     = isset( $saved['odometer_km'] ) && is_numeric( $saved['odometer_km'] )
		? (float) $saved['odometer_km']
		: null;

	if ( $today !== $saved_date ) {
		$start_km = null !== $last_km ? $last_km : $odometer_km;
	} else {
		$start_km = isset( $saved['today_start_km'] ) && is_numeric( $saved['today_start_km'] )
			? (float) $saved['today_start_km']
			: ( null !== $last_km ? $last_km : $odometer_km );
	}

	if ( $odometer_km + 1 < $start_km ) {
		$start_km = $odometer_km;
	}

	$today_km = max( 0, round( $odometer_km - $start_km, 1 ) );

	update_option(
		GAMING_HUB_MODEL3_ODO_OPTION,
		array(
			'date'           => $today,
			'odometer_km'    => $odometer_km,
			'today_start_km' => $start_km,
			'today_km'       => $today_km,
			'updated_at'     => time(),
		),
		false
	);

	return array(
		'today_km'       => $today_km,
		'today_start_km' => $start_km,
		'odometer_km'    => $odometer_km,
	);
}

/**
 * Live pack power in kW from drive_state (or charge_state fallbacks).
 *
 * @param array<string, mixed> $drive_state  Tesla drive_state.
 * @param array<string, mixed> $charge_state Tesla charge_state.
 * @return float|null
 */
function gaming_hub_tesla_pack_kw( array $drive_state, array $charge_state = array() ) {
	$candidates = array(
		$drive_state['power'] ?? null,
		$drive_state['power_w'] ?? null,
		$charge_state['battery_power'] ?? null,
	);

	foreach ( $candidates as $raw ) {
		if ( ! is_numeric( $raw ) ) {
			continue;
		}

		$value = (float) $raw;
		if ( abs( $value ) > 80 ) {
			return $value / 1000.0;
		}

		return $value;
	}

	return null;
}

/**
 * Whether climate / HVAC is on from the live climate_state snapshot.
 *
 * @param array<string, mixed> $climate Tesla climate_state.
 */
function gaming_hub_tesla_climate_is_on( array $climate ) {
	if ( ! empty( $climate['is_climate_on'] )
		|| ! empty( $climate['is_auto_conditioning_on'] )
		|| ! empty( $climate['is_preconditioning'] )
		|| ! empty( $climate['is_front_defroster_on'] )
	) {
		return true;
	}

	$keeper = strtolower( (string) ( $climate['climate_keeper_mode'] ?? '' ) );

	return '' !== $keeper && ! in_array( $keeper, array( 'off', '0', 'false' ), true );
}

/**
 * Cabin watts from the current climate snapshot when pack power is missing.
 *
 * Uses live temps / fan / seat heaters — not a clock demo.
 *
 * @param array<string, mixed> $climate Tesla climate_state.
 * @return int|null
 */
function gaming_hub_tesla_cabin_watts_from_climate( array $climate ) {
	foreach ( array( 'hvac_power', 'climate_power', 'cabin_power', 'power' ) as $key ) {
		if ( ! isset( $climate[ $key ] ) || ! is_numeric( $climate[ $key ] ) ) {
			continue;
		}

		$value = (float) $climate[ $key ];
		if ( abs( $value ) > 80 ) {
			return max( 80, (int) round( abs( $value ) ) );
		}
		if ( abs( $value ) > 0.08 ) {
			return max( 80, (int) round( abs( $value ) * 1000 ) );
		}
	}

	if ( ! gaming_hub_tesla_climate_is_on( $climate ) ) {
		return null;
	}

	$inside  = isset( $climate['inside_temp'] ) && is_numeric( $climate['inside_temp'] )
		? (float) $climate['inside_temp']
		: null;
	$outside = isset( $climate['outside_temp'] ) && is_numeric( $climate['outside_temp'] )
		? (float) $climate['outside_temp']
		: null;
	$target  = isset( $climate['driver_temp_setting'] ) && is_numeric( $climate['driver_temp_setting'] )
		? (float) $climate['driver_temp_setting']
		: null;
	$fan     = isset( $climate['fan_status'] ) && is_numeric( $climate['fan_status'] )
		? max( 0, (int) $climate['fan_status'] )
		: 0;

	$gap = 6.0;
	if ( null !== $outside && null !== $target ) {
		$gap = abs( $outside - $target );
	} elseif ( null !== $inside && null !== $target ) {
		$gap = abs( $inside - $target );
	} elseif ( null !== $outside && null !== $inside ) {
		$gap = abs( $outside - $inside );
	}

	$compressor = 900 + min( 1800, (int) round( $gap * 160 ) );
	if ( ! empty( $climate['defrost_mode'] ) || ! empty( $climate['is_front_defroster_on'] ) ) {
		$compressor += 400;
	}

	$fan_w = min( 450, max( 80, $fan * 45 ) );
	$seats = 0;
	foreach ( array(
		'seat_heater_left',
		'seat_heater_right',
		'seat_heater_rear_left',
		'seat_heater_rear_center',
		'seat_heater_rear_right',
	) as $seat ) {
		if ( isset( $climate[ $seat ] ) && is_numeric( $climate[ $seat ] ) ) {
			$seats += max( 0, (int) $climate[ $seat ] ) * 35;
		}
	}

	if ( ! empty( $climate['steering_wheel_heater'] ) ) {
		$seats += 50;
	}

	return max( 80, $compressor + $fan_w + $seats );
}

/**
 * Propulsion watts from live speed when pack power is missing.
 *
 * @param mixed $speed_km Speed in km/h.
 * @return int|null
 */
function gaming_hub_tesla_drive_watts_from_speed( $speed_km ) {
	$speed = is_numeric( $speed_km ) ? max( 0, (float) $speed_km ) : 0.0;
	if ( $speed < 3 ) {
		return null;
	}

	$wh_per_km = 130 + min( 140, ( $speed * $speed ) / 70 );

	return max( 80, (int) round( $wh_per_km * $speed ) );
}

/**
 * Map Tesla vehicle_data to dashboard model3 payload.
 *
 * @param array<string, mixed> $data Tesla vehicle_data response.
 * @return array<string, mixed>
 */
function gaming_hub_tesla_model3_from_vehicle_data( array $data ) {
	$charge_state = isset( $data['charge_state'] ) && is_array( $data['charge_state'] )
		? $data['charge_state']
		: array();
	$vehicle_state = isset( $data['vehicle_state'] ) && is_array( $data['vehicle_state'] )
		? $data['vehicle_state']
		: array();
	$drive_state = isset( $data['drive_state'] ) && is_array( $data['drive_state'] )
		? $data['drive_state']
		: array();
	$climate_state = isset( $data['climate_state'] ) && is_array( $data['climate_state'] )
		? $data['climate_state']
		: array();

	$state    = (string) ( $charge_state['charging_state'] ?? '' );
	$charging = in_array( $state, array( 'Charging', 'Starting' ), true );

	if ( false === ( $charge_state['charge_enable_request'] ?? null ) ) {
		$charging = false;
	}

	if ( false === ( $charge_state['user_charge_enable_request'] ?? null ) ) {
		$charging = false;
	}

	$power_raw = (float) ( $charge_state['charger_power'] ?? 0 );
	$power_w   = $power_raw > 50
		? (int) round( $power_raw )
		: (int) round( $power_raw * 1000 );

	if ( ! $charging || $power_w < 50 ) {
		$charging = false;
		$power_w  = 0;
	}

	$battery_level = $charge_state['battery_level'] ?? $charge_state['usable_battery_level'] ?? null;
	$range_km      = gaming_hub_tesla_miles_to_km( $charge_state['est_battery_range'] ?? null );
	$ideal_km      = gaming_hub_tesla_miles_to_km( $charge_state['ideal_battery_range'] ?? null );
	$soc           = null !== $battery_level && is_numeric( $battery_level )
		? max( 0, min( 100, (int) round( (float) $battery_level ) ) )
		: 0;
	$range_full_km = ( $soc > 0 && null !== $range_km )
		? (int) round( $range_km / ( $soc / 100 ) )
		: ( null !== $ideal_km ? (int) round( $ideal_km ) : 450 );

	$odometer_km = gaming_hub_tesla_miles_to_km( $vehicle_state['odometer'] ?? null );
	$odo_stats   = null !== $odometer_km
		? gaming_hub_tesla_model3_record_odometer( $odometer_km )
		: array(
			'today_km'    => null,
			'odometer_km' => null,
		);

	$scheduled_ts = 0;
	if ( ! empty( $charge_state['scheduled_charging_pending'] ) && ! empty( $charge_state['scheduled_charging_start_time'] ) ) {
		$scheduled_ts = (int) $charge_state['scheduled_charging_start_time'];
	}

	$supply = gaming_hub_tesla_model3_supply( $charge_state, $charging );
	$energy_added = isset( $charge_state['charge_energy_added'] ) && is_numeric( $charge_state['charge_energy_added'] )
		? max( 0, (float) $charge_state['charge_energy_added'] )
		: 0;

	$car_version = (string) ( $vehicle_state['car_version'] ?? '' );
	if ( preg_match( '/^(\d{4}\.\d+(?:\.\d+)?)/', $car_version, $match ) ) {
		$car_version = $match[1];
	}

	$vehicle_name = (string) ( $vehicle_state['vehicle_name'] ?? $charge_state['vehicle_name'] ?? 'Model 3' );
	if ( '' === $vehicle_name ) {
		$vehicle_name = 'Model 3';
	}

	$shift     = strtoupper( (string) ( $drive_state['shift_state'] ?? '' ) );
	$speed_mph = isset( $drive_state['speed'] ) && is_numeric( $drive_state['speed'] )
		? (float) $drive_state['speed']
		: 0.0;
	$speed_km  = (int) round( $speed_mph * 1.60934 );
	$pack_kw   = gaming_hub_tesla_pack_kw( $drive_state, $charge_state );
	$has_pack  = null !== $pack_kw;
	$moving    = $speed_km >= 3 || in_array( $shift, array( 'D', 'R' ), true );
	$sentry    = ! empty( $vehicle_state['sentry_mode'] );
	$climate_on = gaming_hub_tesla_climate_is_on( $climate_state );

	if ( '' === $shift ) {
		$shift = 'P';
	}

	$drive_w = null;
	$cabin_w = null;
	$regen_w = 0;

	if ( $climate_on ) {
		$cabin_w = gaming_hub_tesla_cabin_watts_from_climate( $climate_state );
	} elseif ( ! $charging && ! $moving && $has_pack && $pack_kw > 0.08 ) {
		$cabin_w = max( 0, (int) round( $pack_kw * 1000 ) );
	} elseif ( ! $moving ) {
		$cabin_w = $has_pack ? 0 : null;
	}

	if ( $charging ) {
		$drive_w = 0;
		$regen_w = 0;
	} elseif ( $moving && $has_pack && $pack_kw < -0.08 ) {
		$regen_w = max( 0, (int) round( abs( $pack_kw ) * 1000 ) );
		$drive_w = 0;
	} elseif ( $moving && $has_pack && $pack_kw > 0.08 ) {
		$pack_discharge = max( 0, (int) round( $pack_kw * 1000 ) );
		$cabin_live     = (int) ( $cabin_w ?? 0 );
		$drive_w        = $pack_discharge > $cabin_live
			? max( 80, $pack_discharge - $cabin_live )
			: $pack_discharge;
	} elseif ( $moving ) {
		$drive_w = gaming_hub_tesla_drive_watts_from_speed( $speed_km );
	} else {
		$drive_w = 0;
	}

	return gaming_hub_powerwall_model3_present(
		array(
			'battery_percent'           => $soc,
			'is_charging'               => $charging,
			'charge_state'              => gaming_hub_tesla_model3_hud_state( $state, $charging ),
			'watts'                     => $power_w,
			'charge_rate_kw'            => $charging ? round( $power_w / 1000, 1 ) : 0,
			'charge_limit_percent'      => max(
				0,
				min( 100, (int) round( $charge_state['charge_limit_soc'] ?? 100 ) )
			),
			'time_to_full_charge_hours' => $charging ? (float) ( $charge_state['time_to_full_charge'] ?? 0 ) : 0,
			'range_km'                  => null !== $range_km ? (int) round( $range_km ) : null,
			'range_full_km'             => max( 1, $range_full_km ),
			'vehicle_name'              => $vehicle_name,
			'charge_energy_added'       => $energy_added,
			'supply_kind'               => $supply['kind'],
			'supply_label'              => $supply['label'],
			'plugged'                   => $supply['plugged'],
			'scheduled_charging_ts'     => $scheduled_ts,
			'odometer_km'               => $odo_stats['odometer_km'],
			'today_km'                  => $odo_stats['today_km'],
			'today_target_km'           => GAMING_HUB_MODEL3_DAILY_KM,
			'car_version'               => $car_version,
			'sentry_mode'               => $sentry,
			'locked'                    => ! empty( $vehicle_state['locked'] ),
			'live'                      => true,
			'drive_w'                   => $drive_w,
			'cabin_w'                   => $cabin_w,
			'regen_w'                   => $regen_w,
			'shift_state'               => $shift ? $shift : 'P',
			'speed_km'                  => $speed_km,
			'climate_on'                => $climate_on,
			'vehicle_mode'              => $charging
				? ( 'supercharger' === $supply['kind'] ? 'supercharger' : 'wall' )
				: ( $regen_w >= 80
					? 'regen'
					: ( ( $drive_w ?? 0 ) >= 80 ? 'drive' : ( ( $cabin_w ?? 0 ) >= 80 ? 'cabin' : 'idle' ) ) ),
		)
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

	$data = $api->get_vehicle_data( $config['vehicle_vin'], 'charge_state;vehicle_state;drive_state;climate_state' );

	if ( is_wp_error( $data ) ) {
		return $data;
	}

	return gaming_hub_tesla_model3_from_vehicle_data( $data );
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
	?>
	<ol class="pw-flow-setup-steps">
		<li><?php esc_html_e( 'developer.tesla.com でアプリを作成し Client ID / Secret を取得', 'gaming-hub' ); ?></li>
		<li><?php esc_html_e( '.env または 外観 → カスタマイズ → Tesla API に Client ID / Secret / VIN を設定', 'gaming-hub' ); ?></li>
		<li>
			<?php esc_html_e( 'Tesla アカウント連携:', 'gaming-hub' ); ?>
			<?php gaming_hub_render_tesla_oauth_button(); ?>
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

	if ( ! $code || ! $state || ! gaming_hub_tesla_oauth_state_is_valid( $state ) ) {
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

	wp_safe_redirect( gaming_hub_hub_section_url( 'powerwall', array( 'tesla_connected' => '1' ) ) );
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
	if ( ( ! is_front_page() && ! is_page( 'powerwall' ) ) || empty( $_GET['tesla_connected'] ) ) {
		return;
	}

	echo '<div class="pw-flow-oauth-notice">' . esc_html__( 'Tesla アカウントを連携しました。Model 3 の実データを取得します。', 'gaming-hub' ) . '</div>';
}
add_action( 'wp_body_open', 'gaming_hub_tesla_oauth_admin_notice', 20 );
