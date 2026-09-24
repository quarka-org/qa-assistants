<?php
defined( 'ABSPATH' ) || exit;

class QAHM_Api_Gateway_Client extends QAHM_Base {

	private $relay_url    = '';
	private $token_url    = '';
	private $site_id      = '';
	private $api_key      = '';
	private $timeout      = 120;
	private $option_key   = 'api_gateway_jwt';

	public function __construct( $config = array() ) {
		$this->relay_url = isset( $config['relay_url'] ) ? $config['relay_url'] : '';
		$this->token_url = isset( $config['token_url'] ) ? $config['token_url'] : '';
		$this->site_id   = isset( $config['site_id'] ) ? $config['site_id'] : '';
		$this->api_key   = isset( $config['api_key'] ) ? $config['api_key'] : '';
		if ( isset( $config['timeout'] ) ) {
			$this->timeout = (int) $config['timeout'];
		}
	}

	public function send_message( $messages, $max_tokens = 1024, $system = '' ) {
		$body = array(
			'messages'   => $messages,
			'max_tokens' => $max_tokens,
		);
		if ( $system !== '' ) {
			$body['system'] = $system;
		}

		$result = $this->relay_request( 'anthropic', $body );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! is_array( $result ) || ! isset( $result['content'][0]['text'] ) ) {
			return new WP_Error( 'qahm_gw_parse', 'Failed to parse AI response.' );
		}

		return $result['content'][0]['text'];
	}

	public function relay_request( $target, $body ) {
		$jwt = $this->get_valid_jwt();
		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}

		$payload = array(
			'target' => $target,
		);
		foreach ( $body as $k => $v ) {
			$payload[ $k ] = $v;
		}

		$response = wp_remote_post( $this->relay_url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $jwt,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => $this->timeout,
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'qahm_gw_request', $response->get_error_message() );
		}

		$result = $this->unwrap_response( $response );

		if ( is_wp_error( $result ) && $result->get_error_code() === 'qahm_gw_auth' ) {
			$this->clear_jwt_cache();
			$jwt = $this->request_jwt();
			if ( is_wp_error( $jwt ) ) {
				return $jwt;
			}

			$response = wp_remote_post( $this->relay_url, array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $jwt,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => $this->timeout,
			) );

			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'qahm_gw_request', $response->get_error_message() );
			}

			$result = $this->unwrap_response( $response );
		}

		return $result;
	}

	private function unwrap_response( $response ) {
		$status_code   = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		$outer = json_decode( $response_body, true );
		if ( ! is_array( $outer ) ) {
			return new WP_Error( 'qahm_gw_parse', 'Failed to parse gateway response.' );
		}

		$inner_status = isset( $outer['statusCode'] ) ? (int) $outer['statusCode'] : $status_code;

		if ( $inner_status === 401 ) {
			return new WP_Error( 'qahm_gw_auth', 'JWT token invalid or expired.' );
		}
		if ( $inner_status === 402 ) {
			return new WP_Error( 'qahm_gw_payment', 'Upstream payment required (credit exhausted).' );
		}
		if ( $inner_status === 403 ) {
			return new WP_Error( 'qahm_gw_forbidden', 'Account suspended or target not enabled.' );
		}
		if ( $inner_status === 429 ) {
			return new WP_Error( 'qahm_gw_limit', 'Monthly usage limit exceeded.' );
		}
		if ( $inner_status === 502 ) {
			return new WP_Error( 'qahm_gw_upstream', 'Upstream service unavailable.' );
		}
		if ( $inner_status !== 200 ) {
			return new WP_Error( 'qahm_gw_api', 'Gateway error (HTTP ' . $inner_status . ').' );
		}

		if ( isset( $outer['body'] ) ) {
			$decoded = json_decode( $outer['body'], true );
			return is_array( $decoded ) ? $decoded : $outer['body'];
		}

		return $outer;
	}

	private function get_valid_jwt() {
		$cached = $this->wrap_get_option( $this->option_key, '' );
		if ( $cached !== '' ) {
			$data = json_decode( $cached, true );
			if ( is_array( $data ) && isset( $data['token'] ) && isset( $data['expires_at'] ) ) {
				if ( $data['expires_at'] > time() + 60 ) {
					return $data['token'];
				}
			}
		}
		return $this->request_jwt();
	}

	private function request_jwt() {
		if ( $this->token_url === '' || $this->site_id === '' || $this->api_key === '' ) {
			return new WP_Error( 'qahm_gw_config', 'Gateway configuration incomplete.' );
		}

		$response = wp_remote_post( $this->token_url, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'site_id' => $this->site_id,
				'api_key' => $this->api_key,
			) ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'qahm_gw_token_request', $response->get_error_message() );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code === 403 ) {
			return new WP_Error( 'qahm_gw_forbidden', 'Account suspended.' );
		}
		if ( $status_code !== 200 || ! is_array( $body ) || ! isset( $body['token'] ) ) {
			return new WP_Error( 'qahm_gw_token_fail', 'Failed to obtain JWT (HTTP ' . $status_code . ').' );
		}

		$expires_in = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;
		$cache_data = wp_json_encode( array(
			'token'      => $body['token'],
			'expires_at' => time() + $expires_in,
		) );
		$this->wrap_update_option( $this->option_key, $cache_data );

		return $body['token'];
	}

	private function clear_jwt_cache() {
		$this->wrap_update_option( $this->option_key, '' );
	}
}
