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
define( 'GAMING_HUB_TESLA_SCOPES_OPTION', 'gaming_hub_tesla_token_scopes' );
define( 'GAMING_HUB_TESLA_LOCATION_DENIED_OPTION', 'gaming_hub_tesla_location_denied' );
define( 'GAMING_HUB_TESLA_FLEET_URL_OPTION', 'gaming_hub_tesla_fleet_base_url' );
define( 'GAMING_HUB_TESLA_FLEET_DEFAULT_URL', 'https://fleet-api.prd.na.vn.cloud.tesla.com' );
define( 'GAMING_HUB_TESLA_STATUS_CACHE_KEY', 'gaming_hub_tesla_model3_status_v5' );
define( 'GAMING_HUB_TESLA_SKIP_KEY', 'gaming_hub_tesla_api_skip' );
define( 'GAMING_HUB_TESLA_POLL_IDLE_TTL', 5 * MINUTE_IN_SECONDS );
define( 'GAMING_HUB_TESLA_POLL_ACTIVE_TTL', 2 * MINUTE_IN_SECONDS );
define( 'GAMING_HUB_TESLA_SLEEP_SKIP_TTL', 2 * MINUTE_IN_SECONDS );
define( 'GAMING_HUB_TESLA_ERROR_SKIP_TTL', 2 * MINUTE_IN_SECONDS );
define( 'GAMING_HUB_TESLA_STATUS_KEEP_TTL', 6 * HOUR_IN_SECONDS );
define( 'GAMING_HUB_TESLA_TAG_SLUG', 'tesla' );

/**
 * Register the Tesla tag used as the vehicle screen.
 */
function gaming_hub_setup_tesla_tag() {
	if ( get_option( 'gaming_hub_tesla_tag_created' ) ) {
		if ( term_exists( GAMING_HUB_TESLA_TAG_SLUG, 'post_tag' ) ) {
			return;
		}
		delete_option( 'gaming_hub_tesla_tag_created' );
	}

	if ( ! term_exists( GAMING_HUB_TESLA_TAG_SLUG, 'post_tag' ) ) {
		wp_insert_term(
			'Tesla',
			'post_tag',
			array(
				'slug'        => GAMING_HUB_TESLA_TAG_SLUG,
				'description' => __( 'Tesla Model 3 の電力フローと充電', 'gaming-hub' ),
			)
		);
	}

	update_option( 'gaming_hub_tesla_tag_created', 1 );
}
add_action( 'init', 'gaming_hub_setup_tesla_tag' );

/**
 * Tesla tag URL.
 *
 * @param array<string, mixed> $query Optional query args.
 */
function gaming_hub_tesla_url( $query = array() ) {
	return function_exists( 'gaming_hub_tag_url' )
		? gaming_hub_tag_url( GAMING_HUB_TESLA_TAG_SLUG, $query )
		: home_url( '/tag/' . GAMING_HUB_TESLA_TAG_SLUG . '/' );
}

/**
 * Cached snapshot says the car is driving (never wakes the vehicle).
 */
function gaming_hub_tesla_is_driving_now() {
	$skip   = gaming_hub_tesla_api_skip_reason();
	$model3 = gaming_hub_tesla_cached_model3( 'asleep' === $skip );

	if ( ! is_array( $model3 ) || ! empty( $model3['asleep'] ) ) {
		return false;
	}

	$shift = strtoupper( (string) ( $model3['shift_state'] ?? '' ) );
	if ( in_array( $shift, array( 'D', 'R' ), true ) ) {
		return true;
	}

	if ( (int) ( $model3['speed_km'] ?? 0 ) >= 3 ) {
		return true;
	}

	return (int) ( $model3['drive_w'] ?? 0 ) >= 80;
}

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
		'command_proxy_url' => rtrim( (string) gaming_hub_tesla_env( 'TESLA_COMMAND_PROXY_URL' ), '/' ),
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

	if ( 'tesla_vehicle_asleep' === $code || 'tesla_missing_charge_state' === $code || false !== stripos( $message, 'asleep' ) ) {
		return __(
			'車はスリープ中です。起こさず、起きたら自動で更新します。',
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
 * Tesla app deep link to enroll this site's virtual key on the car.
 */
function gaming_hub_tesla_virtual_key_url() {
	$domain = gaming_hub_tesla_partner_domain();
	if ( '' === $domain ) {
		return '';
	}

	return 'https://tesla.com/_ak/' . $domain;
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
 * User OAuth scopes for Tesla Fleet (read + charge start/stop).
 */
function gaming_hub_tesla_user_oauth_scopes() {
	return 'openid offline_access vehicle_device_data vehicle_location vehicle_charging_cmds';
}

/**
 * Build Tesla OAuth authorize URL.
 */
function gaming_hub_tesla_oauth_authorize_url( $force_login = false, $require_all_scopes = false ) {
	$config = gaming_hub_get_tesla_config();

	if ( empty( $config['client_id'] ) || empty( $config['redirect_uri'] ) ) {
		return '';
	}

	$params = array(
		'client_id'              => $config['client_id'],
		'redirect_uri'           => $config['redirect_uri'],
		'response_type'          => 'code',
		'scope'                  => gaming_hub_tesla_user_oauth_scopes(),
		'state'                  => gaming_hub_tesla_oauth_state(),
		'locale'                 => 'ja-JP',
		'prompt_missing_scopes'  => 'true',
	);

	if ( $force_login ) {
		$params['prompt'] = 'login';
	}

	if ( $require_all_scopes ) {
		$params['require_requested_scopes'] = 'true';
	}

	$pairs = array();
	foreach ( $params as $key => $value ) {
		$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
	}

	return 'https://auth.tesla.com/oauth2/v3/authorize?' . implode( '&', $pairs );
}

/**
 * Tesla page to revoke or edit this app's granted scopes.
 */
function gaming_hub_tesla_revoke_consent_url() {
	$config = gaming_hub_get_tesla_config();

	if ( empty( $config['client_id'] ) ) {
		return '';
	}

	$back = gaming_hub_tesla_url( array( 'tesla_revoked' => '1' ) );

	return 'https://auth.tesla.com/user/revoke/consent?' . rawurlencode( 'revoke_client_id' ) . '=' . rawurlencode( $config['client_id'] )
		. '&' . rawurlencode( 'back_url' ) . '=' . rawurlencode( $back );
}

/**
 * Render the Tesla OAuth button.
 *
 * @param bool $force_login        Force Tesla login so new scopes can be granted.
 * @param bool $require_all_scopes Block continue unless Vehicle Location is granted.
 */
function gaming_hub_render_tesla_oauth_button( $force_login = false, $require_all_scopes = false ) {
	$authorize = gaming_hub_tesla_oauth_authorize_url( $force_login, $require_all_scopes );

	if ( ! $authorize ) {
		echo '<span class="pw-flow-oauth-missing">' . esc_html__( 'Client ID を設定すると認証リンクが表示されます', 'gaming-hub' ) . '</span>';
		return;
	}
	?>
	<a href="<?php echo esc_url( $authorize ); ?>" class="btn btn-outline btn-sm pw-tesla-oauth-btn">
		<?php
		echo $require_all_scopes
			? esc_html__( '不足スコープを追加', 'gaming-hub' )
			: esc_html__( 'Tesla で認証', 'gaming-hub' );
		?>
	</a>
	<?php
}

/**
 * Render the Tesla revoke-consent button.
 *
 * Hidden: owners should not unlink from the public Tesla tag.
 */
function gaming_hub_render_tesla_revoke_button() {
}

/**
 * Drop stored Tesla tokens after the owner revokes the app.
 */
function gaming_hub_tesla_disconnect_local() {
	delete_transient( GAMING_HUB_TESLA_ACCESS_TOKEN_KEY );
	delete_option( GAMING_HUB_TESLA_REFRESH_TOKEN_OPTION );
	delete_option( GAMING_HUB_TESLA_SCOPES_OPTION );
	if ( defined( 'GAMING_HUB_TESLA_LOCATION_DENIED_OPTION' ) ) {
		delete_option( GAMING_HUB_TESLA_LOCATION_DENIED_OPTION );
	}
	if ( function_exists( 'gaming_hub_tesla_invalidate_status_caches' ) ) {
		gaming_hub_tesla_invalidate_status_caches();
	}
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

	gaming_hub_tesla_remember_token_scopes(
		isset( $tokens['access_token'] ) ? (string) $tokens['access_token'] : '',
		isset( $tokens['scope'] ) ? (string) $tokens['scope'] : ''
	);
}

/**
 * Decode OAuth scopes from a Tesla access token JWT payload.
 * Does not log or return the token.
 *
 * @param string $token Access token.
 * @return array<int, string>
 */
function gaming_hub_tesla_decode_token_scopes( $token ) {
	if ( ! is_string( $token ) || '' === $token ) {
		return array();
	}

	$parts = explode( '.', $token );
	if ( count( $parts ) < 2 ) {
		return array();
	}

	$b64 = $parts[1];
	$pad = strlen( $b64 ) % 4;
	if ( $pad ) {
		$b64 .= str_repeat( '=', 4 - $pad );
	}

	$payload = base64_decode( strtr( $b64, '-_', '+/' ), true );
	if ( ! is_string( $payload ) || '' === $payload ) {
		return array();
	}

	$json = json_decode( $payload, true );
	if ( ! is_array( $json ) ) {
		return array();
	}

	$raw = $json['scp'] ?? $json['scope'] ?? array();
	if ( is_string( $raw ) ) {
		$raw = preg_split( '/\s+/', trim( $raw ) );
	}

	if ( ! is_array( $raw ) ) {
		return array();
	}

	return array_values(
		array_unique(
			array_filter(
				array_map( 'strval', $raw ),
				static function ( $scope ) {
					return '' !== $scope;
				}
			)
		)
	);
}

/**
 * Persist granted Tesla OAuth scopes (never stores the token).
 *
 * @param string $access_token Access token JWT.
 * @param string $scope_string Space-separated scopes from the token response.
 */
function gaming_hub_tesla_normalize_scope_list( $scopes ) {
	if ( is_string( $scopes ) ) {
		$scopes = preg_split( '/\s+/', trim( $scopes ) );
	}

	if ( ! is_array( $scopes ) ) {
		return array();
	}

	return array_values(
		array_unique(
			array_filter(
				array_map( 'strval', $scopes ),
				static function ( $scope ) {
					return '' !== $scope;
				}
			)
		)
	);
}

/**
 * Persist granted Tesla OAuth scopes (never stores the token).
 *
 * @param string $access_token Access token JWT.
 * @param string $scope_string Space-separated scopes from the token response.
 */
function gaming_hub_tesla_remember_token_scopes( $access_token = '', $scope_string = '' ) {
	$scopes = array_merge(
		gaming_hub_tesla_normalize_scope_list( $scope_string ),
		gaming_hub_tesla_decode_token_scopes( $access_token )
	);
	$scopes = gaming_hub_tesla_normalize_scope_list( $scopes );

	if ( empty( $scopes ) ) {
		return;
	}

	$joined = implode( ' ', $scopes );

	if ( get_option( GAMING_HUB_TESLA_SCOPES_OPTION, '' ) === $joined ) {
		return;
	}

	update_option( GAMING_HUB_TESLA_SCOPES_OPTION, $joined, false );
}

/**
 * Granted Tesla OAuth scopes for the current tokens.
 *
 * @return array<int, string>
 */
function gaming_hub_tesla_token_scopes() {
	$saved = get_option( GAMING_HUB_TESLA_SCOPES_OPTION, '' );
	$token = get_transient( GAMING_HUB_TESLA_ACCESS_TOKEN_KEY );

	return gaming_hub_tesla_normalize_scope_list(
		array_merge(
			gaming_hub_tesla_normalize_scope_list( $saved ),
			gaming_hub_tesla_decode_token_scopes( is_string( $token ) ? $token : '' )
		)
	);
}

/**
 * Whether the current Tesla token can read drive_state (speed / power / gear).
 */
function gaming_hub_tesla_has_location_scope() {
	$scopes = gaming_hub_tesla_token_scopes();

	foreach ( array( 'vehicle_location', 'vehicle_locs', 'location' ) as $name ) {
		if ( in_array( $name, $scopes, true ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether the current Tesla token can start/stop charging.
 */
function gaming_hub_tesla_has_charging_scope() {
	$scopes = gaming_hub_tesla_token_scopes();

	foreach ( array( 'vehicle_charging_cmds', 'vehicle_cmds' ) as $name ) {
		if ( in_array( $name, $scopes, true ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether this poll should ask Tesla for location_data (unlocks drive_state).
 */
function gaming_hub_tesla_should_request_location_data() {
	if ( gaming_hub_tesla_has_location_scope() ) {
		$cached = get_transient( GAMING_HUB_TESLA_STATUS_CACHE_KEY );

		// Scope already unlocked drive_state — do not keep polling GPS.
		if ( is_array( $cached ) && ! empty( $cached['drive_ready'] ) ) {
			return false;
		}

		return true;
	}

	return ! get_option( GAMING_HUB_TESLA_LOCATION_DENIED_OPTION, false );
}

/**
 * Remember that Tesla rejected location_data so we do not double-call.
 */
function gaming_hub_tesla_mark_location_denied() {
	update_option( GAMING_HUB_TESLA_LOCATION_DENIED_OPTION, 1, false );
}

/**
 * Allow another location_data attempt after a new OAuth grant.
 */
function gaming_hub_tesla_clear_location_denied() {
	delete_option( GAMING_HUB_TESLA_LOCATION_DENIED_OPTION );
}

/**
 * Drop GPS fields. location_data is requested only to unlock drive_state.
 *
 * @param array<string, mixed> $data Tesla vehicle_data.
 * @return array<string, mixed>
 */
function gaming_hub_tesla_strip_location( array $data ) {
	$keys = array(
		'latitude',
		'longitude',
		'native_latitude',
		'native_longitude',
		'corrected_latitude',
		'corrected_longitude',
		'active_route_latitude',
		'active_route_longitude',
		'active_route_destination',
		'heading',
		'gps_as_of',
		'native_type',
		'native_location_supported',
	);

	if ( isset( $data['drive_state'] ) && is_array( $data['drive_state'] ) ) {
		foreach ( $keys as $key ) {
			unset( $data['drive_state'][ $key ] );
		}
	}

	unset( $data['location_data'] );

	return $data;
}

/**
 * Live Tesla data is present but drive_state (speed / gear / pack power) is missing.
 *
 * @param array<string, mixed> $status Powerwall flow status.
 */
function gaming_hub_tesla_needs_drive_scope( array $status ) {
	if ( 'tesla' !== (string) ( $status['model3_source'] ?? '' ) ) {
		return false;
	}

	$model3 = is_array( $status['model3'] ?? null ) ? $status['model3'] : array();
	if ( ! empty( $model3['drive_ready'] ) ) {
		return false;
	}

	return ! gaming_hub_tesla_has_location_scope();
}

/**
 * Re-auth notice when Tesla omitted drive_state.
 */
function gaming_hub_render_tesla_drive_scope_notice() {
	?>
	<div class="pw-flow-error-action" data-pw-field="tesla_drive_scope">
		<p class="pw-flow-error">
			<?php esc_html_e( 'Tesla は前回の許可内容を使い回すため、同じ認証を繰り返しても位置スコープは増えません。developer.tesla.com のアプリで Vehicle Location を有効にしたうえで、「不足スコープを追加」を押してください。Tesla の画面で車両位置を許可する必要があります。まだ付かないときは一度連携を解除してから再認証してください。位置情報は保存しません。', 'gaming-hub' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Short Tesla link status for the dashboard.
 *
 * @param array<string, mixed> $status Powerwall flow status.
 */
function gaming_hub_tesla_link_note( array $status ) {
	if ( 'tesla' !== (string) ( $status['model3_source'] ?? '' ) ) {
		return '';
	}

	$model3 = is_array( $status['model3'] ?? null ) ? $status['model3'] : array();
	$parts  = array( __( 'Tesla 連携済み（充電・車内）', 'gaming-hub' ) );

	$fetched = isset( $model3['fetched_at'] ) ? (int) $model3['fetched_at'] : 0;
	if ( $fetched > 0 ) {
		$parts[] = sprintf(
			/* translators: %s: last Tesla fetch time */
			__( '最終取得 %s', 'gaming-hub' ),
			wp_date( get_option( 'time_format' ), $fetched )
		);
	}

	if ( ! empty( $model3['drive_ready'] ) ) {
		$shift = strtoupper( (string) ( $model3['shift_state'] ?? '' ) );
		if ( in_array( $shift, array( 'D', 'R' ), true ) ) {
			$parts[] = sprintf(
				/* translators: %s: gear D/R */
				__( '走行データ取得中 · シフト %s', 'gaming-hub' ),
				$shift
			);
		} elseif ( 'N' === $shift ) {
			$parts[] = __( '走行データ取得中 · ニュートラル', 'gaming-hub' );
		} else {
			$parts[] = __( '走行データ取得中 · 駐車中', 'gaming-hub' );
		}
	} elseif ( gaming_hub_tesla_has_location_scope() ) {
		$parts[] = __( '位置スコープあり · 走行スライス待ち', 'gaming-hub' );
	} else {
		$parts[] = __( '走行用の位置スコープは未許可', 'gaming-hub' );
	}

	return implode( ' · ', $parts );
}

/**
 * Live Tesla link status + optional re-auth.
 *
 * @param array<string, mixed> $status Powerwall flow status.
 */
function gaming_hub_render_tesla_link_status( array $status ) {
	if ( 'tesla' !== (string) ( $status['model3_source'] ?? '' ) ) {
		return;
	}

	$note = gaming_hub_tesla_link_note( $status );
	if ( '' !== $note ) {
		echo '<p class="pw-flow-live-note" data-pw-field="tesla_link_note">' . esc_html( $note ) . '</p>';
	}

	if ( gaming_hub_tesla_needs_drive_scope( $status ) ) {
		echo '<div class="pw-tesla-link-actions">';
		gaming_hub_render_tesla_oauth_button( true, true );
		echo '</div>';
	}

	gaming_hub_render_tesla_asleep_notice( $status );

	if ( gaming_hub_tesla_needs_drive_scope( $status ) ) {
		gaming_hub_render_tesla_drive_scope_notice();
	}
}

/**
 * Drop Tesla / Powerwall caches after a new OAuth grant.
 */
function gaming_hub_tesla_invalidate_status_caches() {
	gaming_hub_tesla_clear_api_skip();
	gaming_hub_tesla_clear_location_denied();
	delete_transient( GAMING_HUB_TESLA_STATUS_CACHE_KEY );
	if ( defined( 'GAMING_HUB_POWERWALL_FLOW_CACHE_KEY' ) ) {
		delete_transient( GAMING_HUB_POWERWALL_FLOW_CACHE_KEY );
	}
}

/**
 * Last successful Model 3 snapshot, if any.
 *
 * @param bool $asleep Mark the snapshot as sleep-mode.
 * @return array<string, mixed>|null
 */
function gaming_hub_tesla_cached_model3( $asleep = false ) {
	$cached = get_transient( GAMING_HUB_TESLA_STATUS_CACHE_KEY );
	if ( ! is_array( $cached ) ) {
		return null;
	}

	$cached['asleep'] = (bool) $asleep;

	return $cached;
}

/**
 * Current Tesla Fleet skip reason, if any.
 *
 * @return string asleep|error|
 */
function gaming_hub_tesla_api_skip_reason() {
	$reason = get_transient( GAMING_HUB_TESLA_SKIP_KEY );

	return is_string( $reason ) ? $reason : '';
}

/**
 * Whether Tesla Fleet vehicle_data should be skipped right now.
 */
function gaming_hub_tesla_is_api_skip() {
	return '' !== gaming_hub_tesla_api_skip_reason();
}

/**
 * Pause Tesla Fleet calls (sleep or error backoff).
 *
 * @param int    $ttl    Seconds to skip.
 * @param string $reason asleep|error.
 */
function gaming_hub_tesla_mark_api_skip( $ttl, $reason = 'error' ) {
	$reason = 'asleep' === $reason ? 'asleep' : 'error';
	set_transient( GAMING_HUB_TESLA_SKIP_KEY, $reason, max( 30, (int) $ttl ) );
}

/**
 * Clear the Tesla Fleet skip flag after a live response.
 */
function gaming_hub_tesla_clear_api_skip() {
	delete_transient( GAMING_HUB_TESLA_SKIP_KEY );
}

/**
 * Persist last live Model 3 snapshot.
 *
 * @param array<string, mixed> $model3 Mapped Model 3 payload.
 */
function gaming_hub_tesla_store_model3( array $model3 ) {
	$model3['asleep']     = false;
	$model3['fetched_at'] = time();
	set_transient( GAMING_HUB_TESLA_STATUS_CACHE_KEY, $model3, GAMING_HUB_TESLA_STATUS_KEEP_TTL );
}

/**
 * Charging or driving — poll more often while the car is already awake.
 *
 * @param array<string, mixed> $model3 Mapped Model 3 payload.
 */
function gaming_hub_tesla_snapshot_is_active( array $model3 ) {
	if ( ! empty( $model3['is_charging'] ) ) {
		return true;
	}

	if ( (int) ( $model3['drive_w'] ?? 0 ) >= 80 || (int) ( $model3['regen_w'] ?? 0 ) >= 80 || (int) ( $model3['cabin_w'] ?? 0 ) >= 80 ) {
		return true;
	}

	$shift = strtoupper( (string) ( $model3['shift_state'] ?? '' ) );
	if ( in_array( $shift, array( 'D', 'R' ), true ) ) {
		return true;
	}

	return (int) ( $model3['speed_km'] ?? 0 ) >= 3;
}

/**
 * Seconds to reuse a live snapshot before the next Fleet call.
 *
 * @param array<string, mixed> $model3 Mapped Model 3 payload.
 */
function gaming_hub_tesla_snapshot_ttl( array $model3 ) {
	return gaming_hub_tesla_snapshot_is_active( $model3 )
		? GAMING_HUB_TESLA_POLL_ACTIVE_TTL
		: GAMING_HUB_TESLA_POLL_IDLE_TTL;
}

/**
 * Sleep-mode note under the Tesla flow.
 *
 * @param array<string, mixed> $status Powerwall flow status.
 */
function gaming_hub_render_tesla_asleep_notice( array $status ) {
	if ( 'tesla' !== (string) ( $status['model3_source'] ?? '' ) ) {
		return;
	}

	$asleep = ! empty( $status['tesla_asleep'] ) || ! empty( $status['model3']['asleep'] );
	?>
	<p class="pw-flow-sleep-note" data-pw-field="tesla_asleep_note"<?php echo $asleep ? '' : ' hidden'; ?>>
		<?php esc_html_e( '車はスリープ中です。API は送らず、前回のデータを表示しています。', 'gaming-hub' ); ?>
	</p>
	<?php
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
	} else {
		gaming_hub_tesla_remember_token_scopes( (string) $access_token );
	}

	$api->set_access_token( $access_token );

	$proxy = (string) ( $config['command_proxy_url'] ?? '' );
	if ( '' !== $proxy ) {
		$api->set_command_base_url( $proxy );
	}

	$fleet_ready = gaming_hub_tesla_ensure_fleet_base_url( $api );
	if ( is_wp_error( $fleet_ready ) ) {
		return $fleet_ready;
	}

	return $api;
}

/**
 * Explain a Tesla charge-command failure in Japanese.
 *
 * @param WP_Error $error Tesla API error.
 */
function gaming_hub_tesla_charge_command_error_message( WP_Error $error ) {
	$raw = $error->get_error_message();
	$low = strtolower( $raw );
	$config = function_exists( 'gaming_hub_get_tesla_config' ) ? gaming_hub_get_tesla_config() : array();
	$has_proxy = '' !== (string) ( $config['command_proxy_url'] ?? '' );

	if ( false !== strpos( $low, 'virtual key' ) || false !== strpos( $low, 'not been paired' ) || false !== strpos( $low, 'key_not_paired' ) || false !== strpos( $low, 'incorrect_key' ) ) {
		return __( '車に仮想キーがありません。下の「仮想キーを追加」から Tesla アプリで許可してください。', 'gaming-hub' );
	}

	if ( false !== strpos( $low, 'connection refused' ) || false !== strpos( $low, 'could not resolve' ) || false !== strpos( $low, 'failed to connect' ) ) {
		return __( '充電コマンド用の tesla-http-proxy に接続できません。', 'gaming-hub' );
	}

	if ( false !== strpos( $low, 'unsigned' ) || false !== strpos( $low, 'command protocol' ) ) {
		if ( $has_proxy ) {
			return $raw;
		}

		return __( '充電コマンドは署名が必要です。tesla-http-proxy を立てて TESLA_COMMAND_PROXY_URL を設定してください。', 'gaming-hub' );
	}

	if ( false !== strpos( $low, 'not_charging' ) ) {
		return __( '充電していません。', 'gaming-hub' );
	}

	if ( false !== strpos( $low, 'complete' ) ) {
		return __( 'すでに充電完了です。', 'gaming-hub' );
	}

	if ( false !== strpos( $low, 'disconnected' ) || false !== strpos( $low, 'unplugged' ) ) {
		return __( '充電ケーブルがつながっていません。', 'gaming-hub' );
	}

	if ( 'tesla_vehicle_asleep' === $error->get_error_code() ) {
		return __( '車はスリープ中です。起こしてからもう一度押してください。', 'gaming-hub' );
	}

	return $raw;
}

/**
 * Start or stop Model 3 charging via Fleet API.
 *
 * @param string $action start|stop.
 * @return array<string, mixed>|WP_Error
 */
function gaming_hub_tesla_run_charge_command( $action ) {
	$action  = 'start' === $action ? 'start' : 'stop';
	$command = 'start' === $action ? 'charge_start' : 'charge_stop';

	if ( ! gaming_hub_tesla_model3_is_configured() ) {
		return new WP_Error( 'tesla_not_configured', __( 'Tesla API is not configured.', 'gaming-hub' ) );
	}

	if ( ! gaming_hub_tesla_has_charging_scope() ) {
		return new WP_Error(
			'tesla_missing_charge_scope',
			__( '充電操作の権限がありません。「Tesla で認証」で充電コマンドを許可してください。', 'gaming-hub' )
		);
	}

	$api = gaming_hub_tesla_get_api();
	if ( is_wp_error( $api ) ) {
		return $api;
	}

	$config = gaming_hub_get_tesla_config();
	$vin    = (string) ( $config['vehicle_vin'] ?? '' );
	if ( '' === $vin ) {
		return new WP_Error( 'tesla_missing_vin', __( 'Tesla VIN is not configured.', 'gaming-hub' ) );
	}

	$result = $api->send_vehicle_command( $vin, $command );
	if ( is_wp_error( $result ) && 'tesla_vehicle_asleep' === $result->get_error_code() ) {
		$wake = $api->wake_vehicle( $vin );
		if ( is_wp_error( $wake ) ) {
			return new WP_Error( $wake->get_error_code(), gaming_hub_tesla_charge_command_error_message( $wake ) );
		}

		return new WP_Error(
			'tesla_waking',
			__( '車を起こしています。数秒後にもう一度押してください。', 'gaming-hub' )
		);
	}

	if ( is_wp_error( $result ) ) {
		return new WP_Error( $result->get_error_code(), gaming_hub_tesla_charge_command_error_message( $result ) );
	}

	$reason = strtolower( (string) ( $result['reason'] ?? '' ) );
	$ok     = ! isset( $result['result'] ) || ! empty( $result['result'] );
	if ( ! $ok ) {
		if ( 'not_charging' === $reason && 'stop' === $action ) {
			$ok = true;
		} else {
			$err = new WP_Error( 'tesla_command_rejected', $reason ? $reason : __( 'Tesla が充電コマンドを拒否しました。', 'gaming-hub' ) );

			return new WP_Error( $err->get_error_code(), gaming_hub_tesla_charge_command_error_message( $err ) );
		}
	}

	gaming_hub_tesla_invalidate_status_caches();
	$status = function_exists( 'gaming_hub_get_powerwall_flow_status' )
		? gaming_hub_get_powerwall_flow_status( true )
		: array();

	return array(
		'action'  => $action,
		'message' => 'start' === $action
			? __( '充電オンを送りました。', 'gaming-hub' )
			: __( '充電オフを送りました。', 'gaming-hub' ),
		'tesla'   => is_array( $status['tesla_flow'] ?? null ) ? $status['tesla_flow'] : array(),
		'status'  => $status,
	);
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

	if ( function_exists( 'gaming_hub_tesla_gas_log_record_today' ) ) {
		gaming_hub_tesla_gas_log_record_today( $today_km );
	}

	return array(
		'today_km'       => $today_km,
		'today_start_km' => $start_km,
		'odometer_km'    => $odometer_km,
	);
}

/**
 * Today's parked cabin energy from the last live snapshots.
 *
 * @return array{today_kwh: float, today_yen: int}
 */
function gaming_hub_tesla_cabin_energy_today() {
	$today = wp_date( 'Y-m-d' );
	$saved = get_option( GAMING_HUB_TESLA_CABIN_ENERGY_OPTION, array() );
	if ( ! is_array( $saved ) || (string) ( $saved['date'] ?? '' ) !== $today ) {
		return array(
			'today_kwh' => 0.0,
			'today_yen' => 0,
		);
	}

	return array(
		'today_kwh' => round( max( 0, (float) ( $saved['wh'] ?? 0 ) ) / 1000.0, 2 ),
		'today_yen' => (int) round( max( 0, (float) ( $saved['yen'] ?? 0 ) ) ),
	);
}

/**
 * Integrate parked cabin watts between Tesla polls.
 *
 * Gaps longer than 8 minutes (sleep / errors) are skipped so we do not invent load.
 *
 * @param int  $watts      Latest cabin watts.
 * @param bool $accumulate Whether this snapshot is parked cabin load.
 * @return array{today_kwh: float, today_yen: int}
 */
function gaming_hub_tesla_record_cabin_energy( $watts, $accumulate ) {
	$today = wp_date( 'Y-m-d' );
	$now   = time();
	$watts = max( 0, (int) round( (float) $watts ) );
	$saved = get_option( GAMING_HUB_TESLA_CABIN_ENERGY_OPTION, array() );
	$saved = is_array( $saved ) ? $saved : array();

	if ( (string) ( $saved['date'] ?? '' ) !== $today ) {
		$saved = array(
			'date' => $today,
			'wh'   => 0.0,
			'yen'  => 0.0,
		);
	}

	$last_ts = isset( $saved['last_ts'] ) ? (int) $saved['last_ts'] : 0;
	$last_w  = isset( $saved['last_w'] ) ? max( 0, (int) $saved['last_w'] ) : 0;

	if ( ! empty( $saved['last_on'] ) && $last_ts > 0 ) {
		$delta = $now - $last_ts;
		if ( $delta > 0 && $delta <= GAMING_HUB_TESLA_CABIN_INTEGRATE_MAX ) {
			$hours = $delta / HOUR_IN_SECONDS;
			$saved['wh'] = (float) ( $saved['wh'] ?? 0 ) + ( $last_w * $hours );
			$yen_per_kwh = function_exists( 'gaming_hub_tesla_electricity_yen_per_kwh' )
				? gaming_hub_tesla_electricity_yen_per_kwh()
				: 30.0;
			$saved['yen'] = (float) ( $saved['yen'] ?? 0 ) + ( $last_w / 1000.0 ) * $hours * $yen_per_kwh;
		}
	}

	$saved['last_ts']    = $now;
	$saved['last_w']     = $accumulate ? $watts : 0;
	$saved['last_on']    = $accumulate;
	$saved['updated_at'] = $now;

	update_option( GAMING_HUB_TESLA_CABIN_ENERGY_OPTION, $saved, false );

	return array(
		'today_kwh' => round( max( 0, (float) $saved['wh'] ) / 1000.0, 2 ),
		'today_yen' => (int) round( max( 0, (float) $saved['yen'] ) ),
	);
}

/**
 * Today's home AC charging energy from the last live snapshots.
 *
 * @return array{today_kwh: float, today_yen: int}
 */
function gaming_hub_tesla_wall_energy_today() {
	$today = wp_date( 'Y-m-d' );
	$saved = get_option( GAMING_HUB_TESLA_WALL_ENERGY_OPTION, array() );
	if ( ! is_array( $saved ) || (string) ( $saved['date'] ?? '' ) !== $today ) {
		return array(
			'today_kwh' => 0.0,
			'today_yen' => 0,
		);
	}

	return array(
		'today_kwh' => round( max( 0, (float) ( $saved['wh'] ?? 0 ) ) / 1000.0, 2 ),
		'today_yen' => (int) round( max( 0, (float) ( $saved['yen'] ?? 0 ) ) ),
	);
}

/**
 * Integrate home AC charge watts between Tesla polls.
 *
 * @param int  $watts      Latest wall watts.
 * @param bool $accumulate Whether this snapshot is home AC charging.
 * @return array{today_kwh: float, today_yen: int}
 */
function gaming_hub_tesla_record_wall_energy( $watts, $accumulate ) {
	$today = wp_date( 'Y-m-d' );
	$now   = time();
	$watts = max( 0, (int) round( (float) $watts ) );
	$saved = get_option( GAMING_HUB_TESLA_WALL_ENERGY_OPTION, array() );
	$saved = is_array( $saved ) ? $saved : array();

	if ( (string) ( $saved['date'] ?? '' ) !== $today ) {
		$saved = array(
			'date' => $today,
			'wh'   => 0.0,
			'yen'  => 0.0,
		);
	}

	$last_ts = isset( $saved['last_ts'] ) ? (int) $saved['last_ts'] : 0;
	$last_w  = isset( $saved['last_w'] ) ? max( 0, (int) $saved['last_w'] ) : 0;
	$max_gap = defined( 'GAMING_HUB_TESLA_CABIN_INTEGRATE_MAX' ) ? GAMING_HUB_TESLA_CABIN_INTEGRATE_MAX : ( 8 * MINUTE_IN_SECONDS );

	if ( ! empty( $saved['last_on'] ) && $last_ts > 0 ) {
		$delta = $now - $last_ts;
		if ( $delta > 0 && $delta <= $max_gap ) {
			$hours = $delta / HOUR_IN_SECONDS;
			$saved['wh'] = (float) ( $saved['wh'] ?? 0 ) + ( $last_w * $hours );
			$yen_per_kwh = function_exists( 'gaming_hub_tesla_electricity_yen_per_kwh' )
				? gaming_hub_tesla_electricity_yen_per_kwh()
				: 30.0;
			$saved['yen'] = (float) ( $saved['yen'] ?? 0 ) + ( $last_w / 1000.0 ) * $hours * $yen_per_kwh;
		}
	}

	$saved['last_ts']    = $now;
	$saved['last_w']     = $accumulate ? $watts : 0;
	$saved['last_on']    = $accumulate;
	$saved['updated_at'] = $now;

	update_option( GAMING_HUB_TESLA_WALL_ENERGY_OPTION, $saved, false );

	return array(
		'today_kwh' => round( max( 0, (float) $saved['wh'] ) / 1000.0, 2 ),
		'today_yen' => (int) round( max( 0, (float) $saved['yen'] ) ),
	);
}

/**
 * Live pack power from drive_state (or charge_state fallbacks).
 *
 * Tesla Fleet `drive_state.power` is usually a whole-number kW. Parked HVAC
 * often arrives as 1 (= 1000 W). Watt-scale or fractional readings are marked
 * precise for drive math; parked cabin still shows the live kW as watts.
 *
 * @param array<string, mixed> $drive_state  Tesla drive_state.
 * @param array<string, mixed> $charge_state Tesla charge_state.
 * @return array{kw: float|null, precise: bool}
 */
function gaming_hub_tesla_pack_power( array $drive_state, array $charge_state = array() ) {
	$candidates = array(
		$drive_state['power'] ?? null,
		$drive_state['power_w'] ?? null,
		$charge_state['battery_power'] ?? null,
	);

	$coarse_kw = null;

	foreach ( $candidates as $raw ) {
		if ( ! is_numeric( $raw ) ) {
			continue;
		}

		$value = (float) $raw;
		if ( abs( $value ) > 80 ) {
			return array(
				'kw'      => $value / 1000.0,
				'precise' => true,
			);
		}

		if ( abs( $value - round( $value ) ) > 0.04 ) {
			return array(
				'kw'      => $value,
				'precise' => true,
			);
		}

		if ( null === $coarse_kw ) {
			$coarse_kw = $value;
		}
	}

	if ( null === $coarse_kw ) {
		return array(
			'kw'      => null,
			'precise' => false,
		);
	}

	return array(
		'kw'      => $coarse_kw,
		'precise' => false,
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
	$pack = gaming_hub_tesla_pack_power( $drive_state, $charge_state );

	return $pack['kw'];
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
 * Cabin watts only from Tesla climate power fields. No temp/fan estimates.
 *
 * @param array<string, mixed> $climate Tesla climate_state.
 * @return int|null
 */
function gaming_hub_tesla_cabin_watts_from_climate( array $climate ) {
	foreach ( array( 'hvac_power', 'climate_power', 'cabin_power' ) as $key ) {
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

	return null;
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
 * Live AC/DC charge watts from Tesla charge_state.
 *
 * charger_power is kW when small, W when already > 50.
 *
 * @param array<string, mixed> $charge_state Tesla charge_state.
 */
function gaming_hub_tesla_charge_watts( array $charge_state ) {
	$power_raw = (float) ( $charge_state['charger_power'] ?? 0 );
	$from_kw   = $power_raw > 50
		? (int) round( $power_raw )
		: (int) round( $power_raw * 1000 );

	$voltage = (float) ( $charge_state['charger_voltage'] ?? 0 );
	$amps    = (float) ( $charge_state['charger_actual_current'] ?? 0 );
	$from_va = ( $voltage >= 80 && $amps >= 1 )
		? (int) round( $voltage * $amps )
		: 0;

	return max( 0, $from_kw, $from_va );
}

/**
 * Usable pack kWh from Tesla charge_state, with Model 3 fallback.
 *
 * @param array<string, mixed> $charge_state Tesla charge_state.
 * @param int|float            $soc          Battery percent.
 * @return array{full_kwh: float, remain_kwh: float}
 */
function gaming_hub_tesla_pack_kwh( array $charge_state, $soc ) {
	$full = null;
	foreach ( array( 'nominal_full_pack_energy', 'pack_full_kwh' ) as $key ) {
		if ( ! isset( $charge_state[ $key ] ) || ! is_numeric( $charge_state[ $key ] ) ) {
			continue;
		}
		$val = (float) $charge_state[ $key ];
		if ( $val > 200 && $val < 200000 ) {
			$val = $val / 1000;
		}
		if ( $val > 20 && $val < 200 ) {
			$full = $val;
			break;
		}
	}
	if ( null === $full ) {
		$full = defined( 'GAMING_HUB_MODEL3_BATTERY_KWH' ) ? (float) GAMING_HUB_MODEL3_BATTERY_KWH : 60.0;
	}

	$remain = null;
	foreach ( array( 'energy_remaining', 'expected_energy_remaining' ) as $key ) {
		if ( ! isset( $charge_state[ $key ] ) || ! is_numeric( $charge_state[ $key ] ) ) {
			continue;
		}
		$val = (float) $charge_state[ $key ];
		if ( $val > 200 && $val < 200000 ) {
			$val = $val / 1000;
		}
		if ( $val >= 0 && $val <= ( $full * 1.25 ) ) {
			$remain = $val;
			break;
		}
	}
	if ( null === $remain ) {
		$remain = $full * ( max( 0, min( 100, (float) $soc ) ) / 100.0 );
	}

	return array(
		'full_kwh'   => round( $full, 1 ),
		'remain_kwh' => round( max( 0, $remain ), 1 ),
	);
}

/**
 * Map Tesla vehicle_data to dashboard model3 payload.
 *
 * @param array<string, mixed> $data Tesla vehicle_data response.
 * @return array<string, mixed>
 */
function gaming_hub_tesla_model3_from_vehicle_data( array $data ) {
	$data = gaming_hub_tesla_strip_location( $data );

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

	$has_drive_slice = array_key_exists( 'power', $drive_state )
		|| array_key_exists( 'power_w', $drive_state )
		|| array_key_exists( 'speed', $drive_state )
		|| array_key_exists( 'shift_state', $drive_state );

	$state    = (string) ( $charge_state['charging_state'] ?? '' );
	$charging = in_array( $state, array( 'Charging', 'Starting' ), true );
	$power_w  = $charging ? gaming_hub_tesla_charge_watts( $charge_state ) : 0;

	$battery_level = $charge_state['battery_level'] ?? $charge_state['usable_battery_level'] ?? null;
	$range_km      = gaming_hub_tesla_miles_to_km( $charge_state['est_battery_range'] ?? null );
	$ideal_km      = gaming_hub_tesla_miles_to_km( $charge_state['ideal_battery_range'] ?? null );
	$soc           = null !== $battery_level && is_numeric( $battery_level )
		? max( 0, min( 100, (int) round( (float) $battery_level ) ) )
		: 0;
	$pack_kwh      = gaming_hub_tesla_pack_kwh( $charge_state, $soc );
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
	$speed_km  = $has_drive_slice ? (int) round( $speed_mph * 1.60934 ) : 0;
	$pack      = gaming_hub_tesla_pack_power( $drive_state, $charge_state );
	$pack_kw   = $pack['kw'];
	$has_pack  = null !== $pack_kw;
	$moving    = $has_drive_slice && ( $speed_km >= 3 || in_array( $shift, array( 'D', 'R' ), true ) );
	$sentry    = ! empty( $vehicle_state['sentry_mode'] );
	$climate_on = gaming_hub_tesla_climate_is_on( $climate_state );

	if ( $has_drive_slice && '' === $shift ) {
		$shift = 'P';
	}

	$drive_w = null;
	$cabin_w = gaming_hub_tesla_cabin_watts_from_climate( $climate_state );
	$regen_w = 0;

	if ( null === $cabin_w && ! $charging && ! $moving && $has_pack && $pack_kw > 0.08 ) {
		$cabin_w = max( 0, (int) round( $pack_kw * 1000 ) );
	} elseif ( null === $cabin_w ) {
		$cabin_w = ( $has_pack || $has_drive_slice ) ? 0 : null;
	}

	$cabin_energy = null !== $cabin_w
		? gaming_hub_tesla_record_cabin_energy( $cabin_w, ! $charging && ! $moving )
		: gaming_hub_tesla_cabin_energy_today();

	if ( $charging ) {
		$drive_w = 0;
		$regen_w = 0;
	} elseif ( $moving && $has_pack && $pack_kw < -0.08 ) {
		$regen_w = max( 0, (int) round( abs( $pack_kw ) * 1000 ) );
		$drive_w = 0;
	} elseif ( $moving && $has_pack && $pack_kw > 0.08 ) {
		$pack_discharge = max( 0, (int) round( $pack_kw * 1000 ) );
		$cabin_live     = (int) ( $cabin_w ?? 0 );
		$drive_w        = $cabin_live >= 80 && $pack_discharge > $cabin_live
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
			'battery_kwh_nominal'       => $pack_kwh['full_kwh'],
			'battery_kwh_estimate'      => $pack_kwh['remain_kwh'],
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
			'cabin_today_kwh'           => $cabin_energy['today_kwh'],
			'cabin_today_yen'           => $cabin_energy['today_yen'],
			'regen_w'                   => $regen_w,
			'shift_state'               => $has_drive_slice ? ( $shift ? $shift : 'P' ) : '',
			'speed_km'                  => $speed_km,
			'climate_on'                => $climate_on,
			'drive_ready'               => $has_drive_slice,
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
 * Never wakes the vehicle. Sleep / errors skip further Fleet calls.
 *
 * @return array<string, mixed>|WP_Error
 */
function gaming_hub_fetch_tesla_model3_status() {
	$cached = gaming_hub_tesla_cached_model3();

	$skip_reason = gaming_hub_tesla_api_skip_reason();
	if ( '' !== $skip_reason ) {
		$keep_charging = is_array( $cached ) && ! empty( $cached['is_charging'] );
		if ( $cached && ( ! $keep_charging || 'error' === $skip_reason ) ) {
			$cached['asleep'] = ( 'asleep' === $skip_reason ) && ! $keep_charging;

			return $cached;
		}

		if ( ! $cached ) {
			return new WP_Error(
				'asleep' === $skip_reason ? 'tesla_vehicle_asleep' : 'tesla_request_failed',
				'asleep' === $skip_reason
					? __( '車はスリープ中です。起こさず、起きたら自動で更新します。', 'gaming-hub' )
					: __( 'Tesla Fleet API request failed.', 'gaming-hub' )
			);
		}
	}

	if ( is_array( $cached ) ) {
		$age = time() - (int) ( $cached['fetched_at'] ?? 0 );
		if ( $age >= 0 && $age < gaming_hub_tesla_snapshot_ttl( $cached ) ) {
			return $cached;
		}
	}

	$config = gaming_hub_get_tesla_config();
	$api    = gaming_hub_tesla_get_api();

	if ( is_wp_error( $api ) ) {
		gaming_hub_tesla_mark_api_skip( GAMING_HUB_TESLA_ERROR_SKIP_TTL, 'error' );

		if ( $cached ) {
			return $cached;
		}

		return $api;
	}

	$base_endpoints = 'charge_state;vehicle_state;drive_state;climate_state';
	$with_location  = gaming_hub_tesla_should_request_location_data();
	$endpoints      = $with_location ? $base_endpoints . ';location_data' : $base_endpoints;

	$data = $api->get_vehicle_data( $config['vehicle_vin'], $endpoints );

	if (
		is_wp_error( $data )
		&& $with_location
		&& 'tesla_vehicle_asleep' !== $data->get_error_code()
	) {
		$retry = $api->get_vehicle_data( $config['vehicle_vin'], $base_endpoints );
		if ( ! is_wp_error( $retry ) ) {
			gaming_hub_tesla_mark_location_denied();
			$data = $retry;
		} else {
			$data = $retry;
		}
	}

	if ( is_wp_error( $data ) ) {
		$asleep = in_array(
			$data->get_error_code(),
			array( 'tesla_vehicle_asleep', 'tesla_missing_charge_state' ),
			true
		);
		gaming_hub_tesla_mark_api_skip(
			$asleep ? GAMING_HUB_TESLA_SLEEP_SKIP_TTL : GAMING_HUB_TESLA_ERROR_SKIP_TTL,
			$asleep ? 'asleep' : 'error'
		);

		if ( $cached ) {
			$cached['asleep'] = $asleep && empty( $cached['is_charging'] );

			return $cached;
		}

		return $data;
	}

	gaming_hub_tesla_clear_api_skip();
	$model3 = gaming_hub_tesla_model3_from_vehicle_data( $data );
	gaming_hub_tesla_store_model3( $model3 );

	return $model3;
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
		<li><?php esc_html_e( 'developer.tesla.com でアプリを作成し Client ID / Secret を取得。Vehicle Location（車両位置）スコープも有効にする。既存連携では位置スコープは増えないので、不足スコープの追加か連携解除が必要', 'gaming-hub' ); ?></li>
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
	gaming_hub_tesla_invalidate_status_caches();

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

	wp_safe_redirect( gaming_hub_tesla_url( array( 'tesla_connected' => '1' ) ) );
	exit;
}

/**
 * Whether this request may send Tesla charge start/stop.
 *
 * Admins always can. The Tesla page also sends a wp_rest nonce so the
 * home dashboard can tap ON/OFF without a WordPress login.
 *
 * @param WP_REST_Request|null $request Request.
 */
function gaming_hub_tesla_can_control( $request = null ) {
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}

	$nonce = '';
	if ( $request instanceof WP_REST_Request ) {
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
	}
	if ( '' === $nonce && isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
		$nonce = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) );
	}

	return '' !== $nonce && (bool) wp_verify_nonce( $nonce, 'wp_rest' );
}

/**
 * REST: POST /gaming-hub/v1/tesla/charge
 *
 * @param WP_REST_Request $request Request.
 */
function gaming_hub_rest_tesla_charge( WP_REST_Request $request ) {
	$lock_key = 'gaming_hub_tesla_charge_lock';
	if ( get_transient( $lock_key ) ) {
		return new WP_REST_Response(
			array(
				'success' => false,
				'message' => __( '少し待ってからもう一度押してください。', 'gaming-hub' ),
			),
			429
		);
	}

	set_transient( $lock_key, 1, 3 );

	$action = sanitize_text_field( (string) $request->get_param( 'action' ) );
	if ( ! in_array( $action, array( 'start', 'stop' ), true ) ) {
		return new WP_REST_Response(
			array(
				'success' => false,
				'message' => __( '充電オンかオフを指定してください。', 'gaming-hub' ),
			),
			400
		);
	}

	$result = gaming_hub_tesla_run_charge_command( $action );
	if ( is_wp_error( $result ) ) {
		$code = 'tesla_missing_charge_scope' === $result->get_error_code() ? 403 : 400;

		return new WP_REST_Response(
			array(
				'success'    => false,
				'message'    => $result->get_error_message(),
				'needs_auth' => 'tesla_missing_charge_scope' === $result->get_error_code(),
			),
			$code
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

	register_rest_route(
		'gaming-hub/v1',
		'/tesla/charge',
		array(
			'methods'             => 'POST',
			'callback'            => 'gaming_hub_rest_tesla_charge',
			'permission_callback' => 'gaming_hub_tesla_can_control',
			'args'                => array(
				'action' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'gaming_hub_register_tesla_rest_routes' );

/**
 * Show admin notice after successful Tesla OAuth.
 */
function gaming_hub_tesla_oauth_admin_notice() {
	if ( ! is_tag( 'tesla' ) && ! is_page( 'powerwall' ) ) {
		return;
	}

	if ( ! empty( $_GET['tesla_revoked'] ) ) {
		echo '<div class="pw-flow-oauth-notice">' . esc_html__( 'Tesla 連携を解除しました。位置スコープを付けるには、もう一度「不足スコープを追加」または「Tesla で認証」してください。', 'gaming-hub' ) . '</div>';
		return;
	}

	if ( empty( $_GET['tesla_connected'] ) ) {
		return;
	}

	echo '<div class="pw-flow-oauth-notice">' . esc_html__( 'Tesla アカウントを連携しました。Model 3 の実データを取得します。', 'gaming-hub' ) . '</div>';
}
add_action( 'wp_body_open', 'gaming_hub_tesla_oauth_admin_notice', 20 );

/**
 * Clear local Tesla tokens after the owner revokes the app at Tesla.
 */
function gaming_hub_tesla_maybe_disconnect() {
	if ( empty( $_GET['tesla_revoked'] ) ) {
		return;
	}

	gaming_hub_tesla_disconnect_local();
}
add_action( 'init', 'gaming_hub_tesla_maybe_disconnect', 20 );
