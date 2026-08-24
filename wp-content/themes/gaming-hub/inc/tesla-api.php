<?php
/**
 * Tesla Fleet API client (Model 3 / Powerwall).
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gaming_Hub_Tesla_Api {

	/** @var string */
	private $client_id;

	/** @var string */
	private $client_secret;

	/** @var string */
	private $fleet_base_url;

	/** @var string Optional tesla-http-proxy base URL for signed commands. */
	private $command_base_url = '';

	/** @var int */
	private $timeout = 25;

	/** @var string */
	private $access_token = '';

	/** @var string Tesla-documented token host for server-side exchanges. */
	private $token_url = 'https://fleet-auth.prd.vn.cloud.tesla.com/oauth2/v3/token';

	/** @var string Fallback token host. */
	private $legacy_token_url = 'https://auth.tesla.com/oauth2/v3/token';

	/** @var string Default Fleet API audience (Japan / APAC / NA). */
	private $default_audience = 'https://fleet-api.prd.na.vn.cloud.tesla.com';

	/**
	 * @param string $client_id      Tesla app client ID.
	 * @param string $client_secret  Tesla app client secret.
	 * @param string $fleet_base_url Regional Fleet API base URL.
	 */
	public function __construct( $client_id, $client_secret, $fleet_base_url = '' ) {
		$this->client_id     = $client_id;
		$this->client_secret = $client_secret;
		$this->fleet_base_url = rtrim( (string) $fleet_base_url, '/' );
	}

	/**
	 * @param string $token Bearer access token.
	 */
	public function set_access_token( $token ) {
		$this->access_token = (string) $token;
	}

	/**
	 * @param string $base_url Fleet API base URL.
	 */
	public function set_fleet_base_url( $base_url ) {
		$this->fleet_base_url = rtrim( (string) $base_url, '/' );
	}

	/**
	 * @return string
	 */
	public function get_fleet_base_url() {
		return $this->fleet_base_url;
	}

	/**
	 * @param string $base_url tesla-http-proxy origin, or empty to use Fleet API.
	 */
	public function set_command_base_url( $base_url ) {
		$this->command_base_url = rtrim( (string) $base_url, '/' );
	}

	/**
	 * Wake a sleeping vehicle. Does not send a charge command.
	 *
	 * @param string $vin Vehicle VIN.
	 * @return array<string, mixed>|WP_Error
	 */
	public function wake_vehicle( $vin ) {
		$vin = sanitize_text_field( $vin );
		if ( '' === $vin ) {
			return new WP_Error( 'tesla_missing_vin', __( 'Tesla VIN is not configured.', 'gaming-hub' ) );
		}

		$this->timeout = 40;

		return $this->fleet_request(
			'POST',
			'/api/1/vehicles/' . rawurlencode( $vin ) . '/wake_up',
			array(),
			true,
			(object) array()
		);
	}

	/**
	 * Send a Fleet vehicle command (charge_start / charge_stop).
	 *
	 * Uses TESLA_COMMAND_PROXY_URL when set so tesla-http-proxy can sign.
	 *
	 * @param string $vin     Vehicle VIN.
	 * @param string $command charge_start or charge_stop.
	 * @return array<string, mixed>|WP_Error
	 */
	public function send_vehicle_command( $vin, $command ) {
		$vin     = sanitize_text_field( $vin );
		$command = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $command ) );
		$allowed = array( 'charge_start', 'charge_stop' );

		if ( '' === $vin ) {
			return new WP_Error( 'tesla_missing_vin', __( 'Tesla VIN is not configured.', 'gaming-hub' ) );
		}

		if ( ! in_array( $command, $allowed, true ) ) {
			return new WP_Error( 'tesla_invalid_command', __( 'Unknown Tesla charge command.', 'gaming-hub' ) );
		}

		$saved_base     = $this->fleet_base_url;
		$saved_timeout  = $this->timeout;
		$this->timeout  = 40;
		if ( '' !== $this->command_base_url ) {
			$this->fleet_base_url = $this->command_base_url;
		}

		$result = $this->fleet_request(
			'POST',
			'/api/1/vehicles/' . rawurlencode( $vin ) . '/command/' . $command,
			array(),
			true,
			(object) array()
		);

		$this->fleet_base_url = $saved_base;
		$this->timeout        = $saved_timeout;

		return $result;
	}

	/**
	 * Partner authentication token (client_credentials) for register / public_key endpoints.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_partner_access_token() {
		return $this->post_token(
			array(
				'grant_type'    => 'client_credentials',
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
				'scope'         => 'openid vehicle_device_data vehicle_cmds vehicle_charging_cmds',
				'audience'      => $this->token_audience(),
			)
		);
	}

	/**
	 * Register this app's domain + public key with Tesla Fleet API.
	 *
	 * @param string $domain Root domain (must match developer.tesla.com allowed origins).
	 * @return array<string, mixed>|WP_Error
	 */
	public function register_partner_account( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );
		$domain = preg_replace( '#^https?://#', '', $domain );
		$domain = rtrim( $domain, '/' );

		if ( '' === $domain ) {
			return new WP_Error( 'tesla_invalid_domain', __( 'Tesla partner domain is empty.', 'gaming-hub' ) );
		}

		$tokens = $this->get_partner_access_token();
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		$this->set_access_token( (string) $tokens['access_token'] );

		return $this->fleet_request(
			'POST',
			'/api/1/partner_accounts',
			array(),
			true,
			array(
				'domain' => $domain,
			)
		);
	}

	/**
	 * Verify hosted public key registration for a domain.
	 *
	 * @param string $domain Root domain.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_partner_public_key( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );
		$domain = preg_replace( '#^https?://#', '', $domain );
		$domain = rtrim( $domain, '/' );

		$tokens = $this->get_partner_access_token();
		if ( is_wp_error( $tokens ) ) {
			return $tokens;
		}

		$this->set_access_token( (string) $tokens['access_token'] );

		return $this->fleet_request(
			'GET',
			'/api/1/partner_accounts/public_key',
			array(
				'domain' => $domain,
			)
		);
	}

	/**
	 * Exchange refresh token for a new access token.
	 *
	 * @param string $refresh_token Stored refresh token.
	 * @return array<string, mixed>|WP_Error
	 */
	public function refresh_access_token( $refresh_token ) {
		return $this->post_token(
			array(
				'grant_type'    => 'refresh_token',
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
				'refresh_token' => $refresh_token,
				'audience'      => $this->token_audience(),
			)
		);
	}

	/**
	 * Exchange authorization code for tokens (initial OAuth).
	 *
	 * @param string $code         Authorization code.
	 * @param string $redirect_uri Redirect URI registered with Tesla.
	 * @return array<string, mixed>|WP_Error
	 */
	public function exchange_authorization_code( $code, $redirect_uri ) {
		return $this->post_token(
			array(
				'grant_type'    => 'authorization_code',
				'client_id'     => $this->client_id,
				'client_secret' => $this->client_secret,
				'code'          => $code,
				'redirect_uri'  => $redirect_uri,
				'audience'      => $this->token_audience(),
				'scope'         => function_exists( 'gaming_hub_tesla_user_oauth_scopes' )
					? gaming_hub_tesla_user_oauth_scopes()
					: 'openid offline_access vehicle_device_data vehicle_location vehicle_charging_cmds',
			)
		);
	}

	/**
	 * POST to Tesla token endpoint, then the legacy host if needed.
	 *
	 * @param array<string, string> $body Form body.
	 * @return array<string, mixed>|WP_Error
	 */
	private function post_token( array $body ) {
		$args = array(
			'timeout' => 20,
			'headers' => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			'body'    => $body,
		);

		$parsed = $this->parse_token_response( wp_remote_post( $this->token_url, $args ) );
		if ( ! is_wp_error( $parsed ) ) {
			return $parsed;
		}

		return $this->parse_token_response( wp_remote_post( $this->legacy_token_url, $args ) );
	}

	/**
	 * Resolve regional Fleet API base URL for the account.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_users_region() {
		$data = $this->fleet_request( 'GET', '/api/1/users/region' );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Probe candidate Fleet API hosts until /users/region succeeds.
	 *
	 * @param array<int, string> $candidate_urls Regional base URLs to try.
	 * @return array<string, mixed>|WP_Error
	 */
	public function discover_users_region( array $candidate_urls ) {
		foreach ( $candidate_urls as $base_url ) {
			$this->set_fleet_base_url( $base_url );
			$data = $this->get_users_region();

			if ( ! is_wp_error( $data ) ) {
				if ( ! empty( $data['fleet_api_base_url'] ) ) {
					$this->set_fleet_base_url( (string) $data['fleet_api_base_url'] );
				}

				return $data;
			}

			if ( 'tesla_wrong_region' !== $data->get_error_code() ) {
				continue;
			}

			$error_data = $data->get_error_data();
			if ( ! is_array( $error_data ) || empty( $error_data['fleet_api_base_url'] ) ) {
				continue;
			}

			$this->set_fleet_base_url( (string) $error_data['fleet_api_base_url'] );
			$retry = $this->get_users_region();

			if ( ! is_wp_error( $retry ) ) {
				if ( ! empty( $retry['fleet_api_base_url'] ) ) {
					$this->set_fleet_base_url( (string) $retry['fleet_api_base_url'] );
				}

				return $retry;
			}
		}

		return new WP_Error(
			'tesla_region_unknown',
			__( 'Tesla Fleet API region could not be detected. Set TESLA_FLEET_API_BASE_URL in .env.', 'gaming-hub' )
		);
	}

	/**
	 * Audience for OAuth token requests.
	 */
	private function token_audience() {
		if ( '' !== $this->fleet_base_url ) {
			return $this->fleet_base_url;
		}

		return $this->default_audience;
	}

	/**
	 * Fleet API vehicle_data endpoints must be semicolon-separated and URL-encoded as %3B.
	 *
	 * @param string $endpoints Comma or semicolon separated slices.
	 */
	private function normalize_vehicle_endpoints( $endpoints ) {
		$parts = preg_split( '/[;,\s]+/', (string) $endpoints, -1, PREG_SPLIT_NO_EMPTY );
		$parts = array_values( array_unique( array_filter( array_map( 'trim', $parts ) ) ) );

		return implode( ';', $parts );
	}

	/**
	 * Unwrap Tesla vehicle_data envelopes and JSON-string slices.
	 *
	 * @param mixed $data Raw Fleet API payload.
	 * @param int   $depth Recursion guard.
	 * @return array<string, mixed>
	 */
	private function unwrap_vehicle_data( $data, $depth = 0 ) {
		if ( ! is_array( $data ) || $depth > 3 ) {
			return is_array( $data ) ? $data : array();
		}

		foreach ( array( 'charge_state', 'vehicle_state', 'drive_state', 'climate_state', 'location_data' ) as $slice ) {
			if ( isset( $data[ $slice ] ) && is_string( $data[ $slice ] ) ) {
				$decoded = json_decode( $data[ $slice ], true );
				if ( is_array( $decoded ) ) {
					$data[ $slice ] = $decoded;
				}
			}
		}

		if ( isset( $data['charge_state'] ) && is_array( $data['charge_state'] ) ) {
			return $data;
		}

		foreach ( array( 'response', 'data', 'vehicle_data' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$inner = $this->unwrap_vehicle_data( $data[ $key ], $depth + 1 );
				if ( isset( $inner['charge_state'] ) && is_array( $inner['charge_state'] ) ) {
					return $inner;
				}
			}
		}

		return $data;
	}

	/**
	 * Fetch live vehicle_data slices. Does not wake the vehicle.
	 *
	 * location_data may be requested to unlock drive_state; callers must strip GPS.
	 *
	 * @param string $vin       Vehicle VIN.
	 * @param string $endpoints Comma or semicolon separated endpoints.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_vehicle_data( $vin, $endpoints = 'charge_state;vehicle_state' ) {
		$vin       = sanitize_text_field( $vin );
		$endpoints = $this->normalize_vehicle_endpoints( sanitize_text_field( $endpoints ) );
		$query     = array(
			'endpoints' => $endpoints,
		);

		$data = $this->fleet_request(
			'GET',
			'/api/1/vehicles/' . rawurlencode( $vin ) . '/vehicle_data',
			$query
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$data = $this->unwrap_vehicle_data( $data );

		if ( empty( $data['charge_state'] ) || ! is_array( $data['charge_state'] ) ) {
			return new WP_Error(
				'tesla_missing_charge_state',
				__( 'Tesla vehicle is asleep.', 'gaming-hub' )
			);
		}

		return $data;
	}

	/**
	 * @param array<string, mixed>|WP_Error $response Token HTTP response.
	 * @return array<string, mixed>|WP_Error
	 */
	private function parse_token_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'tesla_token_failed', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 || ! is_array( $body ) || empty( $body['access_token'] ) ) {
			$message = is_array( $body ) && ! empty( $body['error_description'] )
				? (string) $body['error_description']
				: __( 'Tesla token request failed.', 'gaming-hub' );

			return new WP_Error( 'tesla_token_failed', $message );
		}

		return $body;
	}

	/**
	 * @param string               $method HTTP method.
	 * @param string               $path   API path.
	 * @param array<string, mixed> $query  Query params for GET.
	 * @param bool                 $allow_region_retry Retry once after region redirect.
	 * @param array<string, mixed> $body   JSON body for POST.
	 * @return array<string, mixed>|WP_Error
	 */
	private function fleet_request( $method, $path, $query = array(), $allow_region_retry = true, $body = null ) {
		if ( '' === $this->access_token ) {
			return new WP_Error( 'tesla_missing_token', __( 'Tesla access token is not set.', 'gaming-hub' ) );
		}

		if ( '' === $this->fleet_base_url ) {
			return new WP_Error( 'tesla_missing_fleet_url', __( 'Tesla Fleet API base URL is not configured.', 'gaming-hub' ) );
		}

		$url = $this->fleet_base_url . $path;

		if ( 'GET' === $method && ! empty( $query ) ) {
			$endpoints = isset( $query['endpoints'] ) ? $this->normalize_vehicle_endpoints( (string) $query['endpoints'] ) : '';
			unset( $query['endpoints'] );

			if ( ! empty( $query ) ) {
				$url = add_query_arg( $query, $url );
			}

			if ( '' !== $endpoints ) {
				$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'endpoints=' . rawurlencode( $endpoints );
			}
		}

		$args = array(
			'method'  => $method,
			'timeout' => $this->timeout,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->access_token,
				'Content-Type'    => 'application/json',
			),
		);

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if (
			in_array( $host, array( 'localhost', '127.0.0.1', 'tesla-proxy' ), true )
			|| (bool) preg_match( '/\.local$/', $host )
		) {
			$args['sslverify'] = false;
		}

		if ( 'POST' === $method ) {
			$args['body'] = wp_json_encode( null !== $body ? $body : (object) array() );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'tesla_request_failed', $response->get_error_message() );
		}

		$result = $this->parse_fleet_response( $response );

		if (
			$allow_region_retry
			&& is_wp_error( $result )
			&& 'tesla_wrong_region' === $result->get_error_code()
		) {
			$error_data = $result->get_error_data();
			if ( is_array( $error_data ) && ! empty( $error_data['fleet_api_base_url'] ) ) {
				$this->set_fleet_base_url( (string) $error_data['fleet_api_base_url'] );
				gaming_hub_tesla_save_fleet_base_url( $this->get_fleet_base_url() );

				return $this->fleet_request( $method, $path, $query, false, $body );
			}
		}

		return $result;
	}

	/**
	 * @param array<string, mixed>|WP_Error $response HTTP response.
	 * @return array<string, mixed>|WP_Error
	 */
	private function parse_fleet_response( $response ) {
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 412 === $code ) {
			$message = is_array( $body ) && ! empty( $body['error'] )
				? (string) $body['error']
				: __( 'Tesla partner app is not registered for this Fleet API region.', 'gaming-hub' );

			return new WP_Error( 'tesla_partner_not_registered', $message );
		}

		if ( 421 === $code ) {
			$fleet_url = '';

			if ( is_array( $body ) ) {
				if ( ! empty( $body['fleet_api_base_url'] ) ) {
					$fleet_url = (string) $body['fleet_api_base_url'];
				} elseif ( ! empty( $body['response']['fleet_api_base_url'] ) ) {
					$fleet_url = (string) $body['response']['fleet_api_base_url'];
				}
			}

			return new WP_Error(
				'tesla_wrong_region',
				__( 'Tesla Fleet API region mismatch. Retrying with correct region.', 'gaming-hub' ),
				array(
					'fleet_api_base_url' => $fleet_url,
				)
			);
		}

		if ( 408 === $code || ( is_array( $body ) && ! empty( $body['error'] ) && false !== stripos( (string) $body['error'], ' asleep' ) ) ) {
			return new WP_Error( 'tesla_vehicle_asleep', __( 'Tesla vehicle is asleep.', 'gaming-hub' ) );
		}

		if ( $code >= 400 ) {
			$message = is_array( $body ) && ! empty( $body['error'] )
				? (string) $body['error']
				: __( 'Tesla Fleet API request failed.', 'gaming-hub' );

			return new WP_Error( 'tesla_request_failed', $message );
		}

		if ( ! is_array( $body ) || ! array_key_exists( 'response', $body ) ) {
			return new WP_Error( 'tesla_invalid_response', __( 'Invalid Tesla Fleet API response.', 'gaming-hub' ) );
		}

		if ( true === $body['response'] ) {
			return array(
				'result' => true,
			);
		}

		if ( ! is_array( $body['response'] ) ) {
			return new WP_Error( 'tesla_invalid_response', __( 'Invalid Tesla Fleet API response.', 'gaming-hub' ) );
		}

		return $body['response'];
	}
}
