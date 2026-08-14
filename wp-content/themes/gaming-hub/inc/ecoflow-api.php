<?php
/**
 * EcoFlow Developer API client
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gaming_Hub_Ecoflow_Api {

	/** @var string */
	private $access_key;

	/** @var string */
	private $secret_key;

	/** @var string */
	private $base_url;

	/**
	 * @param string $access_key API access key.
	 * @param string $secret_key API secret key.
	 * @param string $region     API region: us or eu.
	 */
	public function __construct( $access_key, $secret_key, $region = 'us' ) {
		$this->access_key = $access_key;
		$this->secret_key = $secret_key;
		$this->base_url   = ( 'eu' === $region ) ? 'https://api-e.ecoflow.com' : 'https://api.ecoflow.com';
	}

	/**
	 * Get bound devices.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_device_list() {
		$data = $this->request( 'GET', '/iot-open/sign/device/list' );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Get all quota values for a device.
	 *
	 * @param string $device_sn Device serial number.
	 * @return array<string, mixed>
	 */
	public function get_device_quota( $device_sn ) {
		$data = $this->request(
			'GET',
			'/iot-open/sign/device/quota/all',
			array( 'sn' => $device_sn )
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Send a Delta Pro 3 set command.
	 *
	 * @param string               $device_sn Device serial.
	 * @param array<string, mixed> $params    Command params (e.g. cfgBypassOutDisable).
	 * @return mixed
	 */
	public function set_device_quota( $device_sn, $params ) {
		return $this->put_quota(
			array(
				'sn'      => $device_sn,
				'cmdId'   => 17,
				'cmdFunc' => 254,
				'dest'    => 2,
				'dirDest' => 1,
				'dirSrc'  => 1,
				'needAck' => true,
				'params'  => $params,
			)
		);
	}

	/**
	 * PUT a raw quota command body.
	 *
	 * @param array<string, mixed> $body Request JSON.
	 * @return mixed
	 */
	public function put_quota( $body ) {
		return $this->request( 'PUT', '/iot-open/sign/device/quota', $body );
	}

	/**
	 * Set Delta Pro 3 AC input charge power (watts).
	 *
	 * @param string $device_sn Device serial.
	 * @param int    $watts     Target watts.
	 * @return mixed
	 */
	public function set_ac_charge_power( $device_sn, $watts ) {
		return $this->set_device_quota(
			$device_sn,
			array(
				'cfgPlugInInfoAcInChgPowMax' => (int) $watts,
			)
		);
	}

	/**
	 * Set Delta Pro 3 Energy Backup reserve SOC.
	 *
	 * @param string $device_sn Device serial.
	 * @param int    $soc       Backup reserve 5–100.
	 * @param bool   $enabled   Enable Energy Backup.
	 * @return mixed
	 */
	public function set_energy_backup( $device_sn, $soc, $enabled = true ) {
		$soc = max( 5, min( 100, (int) $soc ) );

		return $this->set_device_quota(
			$device_sn,
			array(
				'cfgEnergyBackup' => array(
					'energyBackupEn'       => (bool) $enabled,
					'energyBackupStartSoc' => $soc,
				),
			)
		);
	}

	/**
	 * Make signed API request.
	 *
	 * @param string               $method   HTTP method.
	 * @param string               $endpoint API path.
	 * @param array<string, mixed> $params   Query or body params.
	 * @return mixed
	 */
	private function request( $method, $endpoint, $params = array() ) {
		$result = $this->signed_request( $method, $endpoint, $params, true );

		if ( is_wp_error( $result ) && 'PUT' === $method ) {
			$code = (string) ( $result->get_error_data()['code'] ?? '' );
			$msg  = strtolower( $result->get_error_message() );
			if ( in_array( $code, array( '401', '130' ), true ) || false !== strpos( $msg, 'sign' ) ) {
				$result = $this->signed_request( $method, $endpoint, $params, false );
			}
		}

		return $result;
	}

	/**
	 * Signed HTTP request.
	 *
	 * @param string               $method      HTTP method.
	 * @param string               $endpoint    API path.
	 * @param array<string, mixed> $params      Query or body params.
	 * @param bool                 $sign_params Include flattened body in signature.
	 * @return mixed
	 */
	private function signed_request( $method, $endpoint, $params, $sign_params ) {
		$timestamp  = (string) round( microtime( true ) * 1000 );
		$nonce      = (string) wp_rand( 100000, 999999 );
		$params_str = $sign_params ? $this->sort_and_concat_params( $params ) : '';

		$auth_str = 'accessKey=' . $this->access_key . '&nonce=' . $nonce . '&timestamp=' . $timestamp;
		$sign_str = $params_str ? $params_str . '&' . $auth_str : $auth_str;
		$sign     = hash_hmac( 'sha256', $sign_str, $this->secret_key );

		$headers = array(
			'accessKey' => $this->access_key,
			'timestamp' => $timestamp,
			'nonce'     => $nonce,
			'sign'      => $sign,
		);

		if ( 'GET' === $method ) {
			$url = $this->base_url . $endpoint;
			if ( $params_str ) {
				$url .= '?' . $params_str;
			}

			$response = wp_remote_get(
				$url,
				array(
					'headers' => $headers,
					'timeout' => 20,
				)
			);
		} else {
			$headers['Content-Type'] = 'application/json;charset=UTF-8';
			$response = wp_remote_request(
				$this->base_url . $endpoint,
				array(
					'method'  => $method,
					'headers' => $headers,
					'body'    => wp_json_encode( $params ),
					'timeout' => 20,
				)
			);
		}

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ecoflow_request_failed', $response->get_error_message() );
		}

		$raw  = wp_remote_retrieve_body( $response );
		$http = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( $raw, true );
		if ( ! is_array( $body ) ) {
			return new WP_Error(
				'ecoflow_invalid_response',
				__( 'Invalid EcoFlow API response.', 'gaming-hub' ),
				array(
					'code' => (string) $http,
					'raw'  => substr( (string) $raw, 0, 300 ),
				)
			);
		}

		$code = isset( $body['code'] ) ? (string) $body['code'] : '';
		if ( ! in_array( $code, array( '0', '200' ), true ) ) {
			$message = isset( $body['message'] ) ? $body['message'] : __( 'EcoFlow API error.', 'gaming-hub' );
			return new WP_Error(
				'ecoflow_api_error',
				$message,
				array(
					'code' => $code,
					'http' => $http,
				)
			);
		}

		return isset( $body['data'] ) ? $body['data'] : array();
	}

	/**
	 * Flatten and sort params for signature.
	 *
	 * @param array<string, mixed> $params Request params.
	 * @return string
	 */
	private function sort_and_concat_params( $params ) {
		$flat = $this->flatten_params( $params );
		ksort( $flat );

		$parts = array();
		foreach ( $flat as $key => $value ) {
			$parts[] = $key . '=' . $value;
		}

		return implode( '&', $parts );
	}

	/**
	 * Flatten nested params.
	 *
	 * @param array<string, mixed> $params  Params.
	 * @param string               $prefix Key prefix.
	 * @return array<string, string>
	 */
	private function flatten_params( $params, $prefix = '' ) {
		$items = array();

		foreach ( $params as $key => $value ) {
			$new_key = $prefix ? $prefix . '.' . $key : $key;

			if ( is_array( $value ) ) {
				$items = array_merge( $items, $this->flatten_params( $value, $new_key ) );
			} elseif ( is_bool( $value ) ) {
				$items[ $new_key ] = $value ? 'true' : 'false';
			} else {
				$items[ $new_key ] = (string) $value;
			}
		}

		return $items;
	}
}
