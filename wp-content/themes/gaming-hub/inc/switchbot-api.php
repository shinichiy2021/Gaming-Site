<?php
/**
 * SwitchBot Open API v1.1 client.
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gaming_Hub_Switchbot_Api {

	const BASE_URL = 'https://api.switch-bot.com/v1.1';

	/** @var string */
	private $token;

	/** @var string */
	private $secret;

	/**
	 * @param string $token  Open token.
	 * @param string $secret Secret key.
	 */
	public function __construct( $token, $secret ) {
		$this->token  = $token;
		$this->secret = $secret;
	}

	/**
	 * List physical devices.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function get_devices() {
		$body = $this->request( 'GET', '/devices' );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$list = isset( $body['deviceList'] ) && is_array( $body['deviceList'] )
			? $body['deviceList']
			: array();

		return $list;
	}

	/**
	 * Get live status for one device.
	 *
	 * @param string $device_id Device ID.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_device_status( $device_id ) {
		return $this->request( 'GET', '/devices/' . rawurlencode( $device_id ) . '/status' );
	}

	/**
	 * List virtual IR remotes learned on a Hub.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function get_ir_remotes() {
		$body = $this->request( 'GET', '/devices' );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		return isset( $body['infraredRemoteList'] ) && is_array( $body['infraredRemoteList'] )
			? $body['infraredRemoteList']
			: array();
	}

	/**
	 * Send a control command to a device or IR remote.
	 *
	 * @param string $device_id Device ID.
	 * @param string $command   Command name (e.g. setAll).
	 * @param string $parameter Command parameter.
	 * @return array<string, mixed>|WP_Error
	 */
	public function send_command( $device_id, $command, $parameter = 'default' ) {
		return $this->request(
			'POST',
			'/devices/' . rawurlencode( $device_id ) . '/commands',
			array(
				'command'     => $command,
				'parameter'   => $parameter,
				'commandType' => 'command',
			)
		);
	}

	/**
	 * Signed GET/POST against SwitchBot Open API.
	 *
	 * @param string                    $method   HTTP method.
	 * @param string                    $endpoint Path starting with /.
	 * @param array<string, mixed>|null $payload  JSON body for POST.
	 * @return array<string, mixed>|WP_Error
	 */
	private function request( $method, $endpoint, $payload = null ) {
		$t     = (string) round( microtime( true ) * 1000 );
		$nonce = wp_generate_uuid4();
		$sign  = base64_encode(
			hash_hmac( 'sha256', $this->token . $t . $nonce, $this->secret, true )
		);

		$args = array(
			'method'  => $method,
			'timeout' => 12,
			'headers' => array(
				'Authorization' => $this->token,
				'sign'          => $sign,
				't'             => $t,
				'nonce'         => $nonce,
				'Content-Type'  => 'application/json; charset=utf-8',
			),
		);
		if ( null !== $payload ) {
			$args['body'] = wp_json_encode( $payload );
		}

		$response = wp_remote_request( self::BASE_URL . $endpoint, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'switchbot_request_failed', $response->get_error_message() );
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$body = json_decode( $raw, true );

		if ( ! is_array( $body ) ) {
			return new WP_Error(
				'switchbot_invalid_response',
				__('Could not parse the SwitchBot API response.', 'gaming-hub'),
				array( 'http' => $http )
			);
		}

		$code = isset( $body['statusCode'] ) ? (int) $body['statusCode'] : 0;
		if ( 401 === $http ) {
			return new WP_Error(
				'switchbot_unauthorized',
				__('SwitchBot token or secret is invalid.', 'gaming-hub')
			);
		}

		if ( 100 !== $code ) {
			$message = isset( $body['message'] ) ? (string) $body['message'] : __('SwitchBot API error', 'gaming-hub');
			return new WP_Error(
				'switchbot_api_error',
				$message,
				array(
					'code' => $code,
					'http' => $http,
				)
			);
		}

		return isset( $body['body'] ) && is_array( $body['body'] ) ? $body['body'] : array();
	}
}
