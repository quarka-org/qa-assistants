<?php
defined( 'ABSPATH' ) || exit;
/**
 *
 *
 * @package qa_heatmap
 */

$GLOBALS['qahm_google_api'] = new QAHM_Google_Api();

class QAHM_Google_Api extends QAHM_File_Base {
	/**
	 * Google_Client
	 */
	public $client         = null;
	public $prop_url       = null;
	public $tracking_id    = null;
	public $sc_url         = null;
	public $first_dir_name = null;

	public function set_tracking_id( $tracking_id, $url ) {

		$this->first_dir_name = null;
		$this->tracking_id    = $tracking_id;

		if ( $this->wrap_strpos( $url, 'https://' ) !== 0 ) {
			$url = 'https://' . $url;
		}

		$url_parts = wp_parse_url( $url );

		// wp_parse_urlがfalseを返した場合（URLが不正または空の場合）
		if ( ! is_array( $url_parts ) || ! isset( $url_parts['scheme'] ) || ! isset( $url_parts['host'] ) ) {
			$this->sc_url = null;
			$this->client = null;
			return;
		}

		if ( isset( $url_parts['path'] ) ) {
			$url_path             = $url_parts['path'];
			$directories          = $this->wrap_explode( '/', $this->wrap_trim( $url_path, '/' ) );
			$this->first_dir_name = isset( $directories[0] ) ? $directories[0] : null;
		}

		$this->sc_url = $url_parts['scheme'] . '://' . $url_parts['host'] . '/';
		$this->client = null;
	}

	// 認証済みか判定
	public function is_auth() {
		if ( ! $this->client || $this->client->isAccessTokenExpired() ) {
			return false;
		}
		return true;
	}

	public function get_client_id() {
		if ( ! $this->client ) {
			return null;
		}
		return $this->client->getClientId();
	}

	public function get_client_secret() {
		if ( ! $this->client ) {
			return null;
		}
		return $this->client->getClientSecret();
	}

	public function get_redirect_uri() {
		if ( ! $this->client ) {
			return null;
		}
		return $this->client->getRedirectUri();
	}

	public function get_access_token() {
		if ( ! $this->client ) {
			return null;
		}
		return $this->client->getAccessToken();
	}

	public function set_credentials( $client_id, $client_secret, $token, $tracking_id ) {

		global $qahm_data_enc;

		$credentials = $this->wrap_get_option( 'google_credentials' );
		if ( ! $credentials ) {
			$credentials = array();
		} else {
			$credentials = $qahm_data_enc->decrypt( $credentials );
			$credentials = json_decode( $credentials, true );
		}

		$credential                  = array(
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
			'token'         => $token,
		);
		$credentials[ $tracking_id ] = $credential;
		$credentials                 = $this->wrap_json_encode( $credentials );
		$credentials                 = $qahm_data_enc->encrypt( $credentials );
		$this->wrap_update_option( 'google_credentials', $credentials );
	}

	public function get_credentials( $tracking_id ) {
		global $qahm_data_enc;
		$credentials = $this->wrap_get_option( 'google_credentials' );

		if ( $credentials ) {
			$credentials = $qahm_data_enc->decrypt( $credentials );
			$credentials = json_decode( $credentials, true );

			if ( json_last_error() === JSON_ERROR_NONE ) {
				return isset( $credentials[ $tracking_id ] ) ? $credentials[ $tracking_id ] : null;
			} else {
				// JSONのデコードに失敗した場合のエラーハンドリング
				return null;
			}
		} else {
			// 認証情報が保存されていない場合のエラーハンドリング
			return null;
		}
	}

	/**
	 * Initialize Google API client for admin/UI context with redirect capability
	 *
	 * @param string $app_name Application name for Google Client
	 * @param array $scopes Required OAuth scopes
	 * @param string $redirect_uri Redirect URI for OAuth flow
	 * @param bool $redirect_flag Whether to perform redirect to Google OAuth
	 * @param array|null $token Optional access token
	 * @return bool True if authentication successful, false otherwise
	 */
	public function init_for_admin( $app_name, $scopes, $redirect_uri, $redirect_flag = false, $token = null ) {
		// $this->wrap_get_option( 'google_credentials' )はapiの認証画面で設定することを前提に
		$credentials = $this->wrap_get_option( 'google_credentials' );
		if ( ! $credentials ) {
			return false;
		}

		global $qahm_data_enc;
		$credentials = $qahm_data_enc->decrypt( $credentials );
		$credentials = json_decode( $credentials, true );

		if ( ! $this->tracking_id || ! isset( $credentials[ $this->tracking_id ] ) ) {
			return false;
		}

		$this->client  = new Google_Client();
		$client_id     = $credentials[ $this->tracking_id ]['client_id'];
		$client_secret = $credentials[ $this->tracking_id ]['client_secret'];
		$this->client->setApplicationName( $app_name );
		$this->client->setClientId( $client_id );
		$this->client->setClientSecret( $client_secret );
		$this->client->setRedirectUri( $redirect_uri );
		$this->client->setScopes( $scopes );
		$this->client->setAccessType( 'offline' );
		$this->client->setApprovalPrompt( 'force' );

		if ( ! $token ) {
			if ( $redirect_flag ) {
				$this->wrap_update_option( 'google_is_redirect', true );
				$authUrl = $this->client->createAuthUrl();
				wp_redirect( $authUrl );
				exit;
			}

			$code = $this->wrap_filter_input( INPUT_GET, 'code' );
			if ( $code && $this->client->fetchAccessTokenWithAuthCode( $code ) ) {
				$token                                      = $this->client->getAccessToken();
				$credentials[ $this->tracking_id ]['token'] = $token;
				$this->set_credentials( $credentials[ $this->tracking_id ]['client_id'], $credentials[ $this->tracking_id ]['client_secret'], $credentials[ $this->tracking_id ]['token'], $this->tracking_id );

			} elseif ( $credentials[ $this->tracking_id ]['token'] ) {
				$token = $credentials[ $this->tracking_id ]['token'];
			}

			/*
			if ( ! $token ) {
				if ( $redirect_flag ) {
					$this->wrap_update_option( 'google_is_redirect', true );
					$authUrl = $this->client->createAuthUrl();
					wp_redirect( $authUrl );
					exit;
				} else {
					return false;
				}
			}
			*/
			if ( ! $token ) {
				return false;
			}
		}

		// アクセストークンの有効期限を確認する
		// 有効期限切れなら再取得して保存する
		$this->client->setAccessToken( $token );
		if ( $this->client->isAccessTokenExpired() ) {  // if token expired
			$this->client->refreshToken( $token['refresh_token'] );
			$credentials[ $this->tracking_id ]['token'] = $this->client->getAccessToken();
			$this->set_credentials( $credentials[ $this->tracking_id ]['client_id'], $credentials[ $this->tracking_id ]['client_secret'], $credentials[ $this->tracking_id ]['token'], $this->tracking_id );
		}

		return $this->is_auth();
	}

	/**
	 * Initialize Google API client for background/cron context without redirect
	 *
	 * @param string $app_name Application name for Google Client
	 * @param array $scopes Required OAuth scopes
	 * @param array|null $token Optional access token
	 * @return bool True if authentication successful, false otherwise
	 */
	public function init_for_background( $app_name, $scopes, $token = null ) {
		// $this->wrap_get_option( 'google_credentials' )はapiの認証画面で設定することを前提に
		$credentials = $this->wrap_get_option( 'google_credentials' );
		if ( ! $credentials ) {
			return false;
		}

		global $qahm_data_enc;
		$credentials = $qahm_data_enc->decrypt( $credentials );
		$credentials = json_decode( $credentials, true );

		if ( ! $this->tracking_id || ! isset( $credentials[ $this->tracking_id ] ) ) {
			return false;
		}

		$this->client  = new Google_Client();
		$client_id     = $credentials[ $this->tracking_id ]['client_id'];
		$client_secret = $credentials[ $this->tracking_id ]['client_secret'];
		$this->client->setApplicationName( $app_name );
		$this->client->setClientId( $client_id );
		$this->client->setClientSecret( $client_secret );
		$this->client->setScopes( $scopes );
		$this->client->setAccessType( 'offline' );
		$this->client->setApprovalPrompt( 'force' );

		if ( ! $token ) {
			if ( $credentials[ $this->tracking_id ]['token'] ) {
				$token = $credentials[ $this->tracking_id ]['token'];
			}

			if ( ! $token ) {
				return false;
			}
		}

		// アクセストークンの有効期限を確認する
		// 有効期限切れなら再取得して保存する
		$this->client->setAccessToken( $token );
		if ( $this->client->isAccessTokenExpired() ) {  // if token expired
			$this->client->refreshToken( $token['refresh_token'] );
			$credentials[ $this->tracking_id ]['token'] = $this->client->getAccessToken();
			$this->set_credentials( $credentials[ $this->tracking_id ]['client_id'], $credentials[ $this->tracking_id ]['client_secret'], $credentials[ $this->tracking_id ]['token'], $this->tracking_id );
		}

		return $this->is_auth();
	}

	/**
	 * Initialize Google API client (legacy method)
	 *
	 * @deprecated Use init_for_admin() or init_for_background() instead
	 * @param string $app_name Application name for Google Client
	 * @param array $scopes Required OAuth scopes
	 * @param string $redirect_uri Redirect URI for OAuth flow
	 * @param bool $redirect_flag Whether to perform redirect to Google OAuth
	 * @param array|null $token Optional access token
	 * @return bool True if authentication successful, false otherwise
	 */
	public function init( $app_name, $scopes, $redirect_uri, $redirect_flag = false, $token = null ) {
		// $this->wrap_get_option( 'google_credentials' )はapiの認証画面で設定することを前提に
		$credentials = $this->wrap_get_option( 'google_credentials' );
		if ( ! $credentials ) {
			return false;
		}

		global $qahm_data_enc;
		$credentials = $qahm_data_enc->decrypt( $credentials );
		$credentials = json_decode( $credentials, true );

		if ( ! $this->tracking_id || ! isset( $credentials[ $this->tracking_id ] ) ) {
			return false;
		}

		$this->client  = new Google_Client();
		$client_id     = $credentials[ $this->tracking_id ]['client_id'];
		$client_secret = $credentials[ $this->tracking_id ]['client_secret'];
		$this->client->setApplicationName( $app_name );
		$this->client->setClientId( $client_id );
		$this->client->setClientSecret( $client_secret );
		$this->client->setRedirectUri( $redirect_uri );
		$this->client->setScopes( $scopes );
		$this->client->setAccessType( 'offline' );
		$this->client->setApprovalPrompt( 'force' );

		if ( ! $token ) {
			if ( $redirect_flag ) {
				$this->wrap_update_option( 'google_is_redirect', true );
				$authUrl = $this->client->createAuthUrl();
				wp_redirect( $authUrl );
				exit;
			}

			$code = $this->wrap_filter_input( INPUT_GET, 'code' );
			if ( $code && $this->client->fetchAccessTokenWithAuthCode( $code ) ) {
				$token                                      = $this->client->getAccessToken();
				$credentials[ $this->tracking_id ]['token'] = $token;
				$this->set_credentials( $credentials[ $this->tracking_id ]['client_id'], $credentials[ $this->tracking_id ]['client_secret'], $credentials[ $this->tracking_id ]['token'], $this->tracking_id );

			} elseif ( $credentials[ $this->tracking_id ]['token'] ) {
				$token = $credentials[ $this->tracking_id ]['token'];
			}

			/*
			if ( ! $token ) {
				if ( $redirect_flag ) {
					$this->wrap_update_option( 'google_is_redirect', true );
					$authUrl = $this->client->createAuthUrl();
					wp_redirect( $authUrl );
					exit;
				} else {
					return false;
				}
			}
			*/
			if ( ! $token ) {
				return false;
			}
		}

		// アクセストークンの有効期限を確認する
		// 有効期限切れなら再取得して保存する
		$this->client->setAccessToken( $token );
		if ( $this->client->isAccessTokenExpired() ) {  // if token expired
			$this->client->refreshToken( $token['refresh_token'] );
			$credentials[ $this->tracking_id ]['token'] = $this->client->getAccessToken();
			$this->set_credentials( $credentials[ $this->tracking_id ]['client_id'], $credentials[ $this->tracking_id ]['client_secret'], $credentials[ $this->tracking_id ]['token'], $this->tracking_id );
		}

		return $this->is_auth();
	}


	/**
	 * サーチコンソールに接続するURLを返す
	 *
	 * 処理の流れとしては
	 * 1. urlプレフィックスで接続
	 * 2. 1.に失敗した場合、ドメインで接続
	 * 3. 1, 2, において成功したurlを返す。失敗した場合はnullを返す
	 */
	public function get_property_url() {
		global $qahm_time;

		if ( $this->prop_url ) {
			return $this->prop_url;
		}

		if ( $this->client === null ) {
			return null;
		}

		$token = $this->client->getAccessToken();

		// URLをパースする
		$parse_url = wp_parse_url( $this->sc_url );
		$sc_domain = 'sc-domain:' . urlencode( $parse_url['host'] );

		$url_ary = array(
			'https://searchconsole.googleapis.com/webmasters/v3/sites/' . urlencode( $this->sc_url ) . '/searchAnalytics/query',
			'https://searchconsole.googleapis.com/webmasters/v3/sites/' . $sc_domain . '/searchAnalytics/query',
		);

		foreach ( $url_ary as $ep_url ) {
			$headers = array(
				'Authorization' => 'Bearer ' . $token['access_token'],
				'Content-Type'  => 'application/json',
			);

			$date = $qahm_time->xday_str( -5 );
			$body = $this->wrap_json_encode(
				array(
					'startDate'  => $date,
					'endDate'    => $date,
					'type'       => 'web',
					'dimensions' => array( 'query' ),
					'rowLimit'   => 1,
					'startRow'   => 0,
				)
			);

			$args     = array(
				'headers' => $headers,
				'body'    => $body,
			);
			$response = wp_remote_post( $ep_url, $args );
			if ( is_wp_error( $response ) ) {
				continue;
			}

			$query_ary = json_decode( $response['body'], true );
			if ( $this->wrap_array_key_exists( 'error', $query_ary ) ) {
				continue;
			}

			$this->prop_url = $ep_url;
			return $this->prop_url;
		}

		return null;
	}

	/**
	 * サーチコンソールの接続テスト
	 * エラーが発生していればエラー配列が返る
	 * 初期化していなければfalseが返る
	 * 処理に問題なければnullが返る
	 */
	public function test_search_console_connect() {
		global $qahm_time;

		if ( $this->client === null ) {
			return false;
		}
		$token = $this->client->getAccessToken();

		if ( $token === null ) {
			return false;
		}

		//$ep_url   = 'https://www.googleapis.com/webmasters/v3/sites/' . urlencode($this->sc_url) . '/searchAnalytics/query';
		$ep_url = $this->get_property_url();
		if ( $ep_url === null ) {
			$ep_url = 'https://searchconsole.googleapis.com/webmasters/v3/sites/' . urlencode( $this->sc_url ) . '/searchAnalytics/query';
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $token['access_token'],
			'Content-Type'  => 'application/json',
		);

		$date = $qahm_time->xday_str( -5 );
		$body = $this->wrap_json_encode(
			array(
				'startDate'  => $date,
				'endDate'    => $date,
				'type'       => 'web',
				'dimensions' => array( 'query' ),
				'rowLimit'   => 1,
				'startRow'   => 0,
			)
		);

		$args     = array(
			'headers' => $headers,
			'body'    => $body,
		);
		$response = wp_remote_post( $ep_url, $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'code'    => '001',
				'message' => 'is_wp_error',
			);
		}

		$query_ary = json_decode( $response['body'], true );
		if ( $this->wrap_array_key_exists( 'error', $query_ary ) ) {
			return $query_ary['error'];
		}

		return null;
	}

	/**
	 * サーチコンソールから取得した検索キーワードをdbにインサート
	 * update_dateに挿入する日付は$start_dateとなる
	 * 現在1日毎にしかデータを取得していないので引数はひとつでも良いが、後々の拡張性のためこのままに
	 */
	public function insert_search_console_keyword( $start_date, $end_date ) {
		global $qahm_db;
		global $qahm_time;
		global $qahm_log;

		$db_query_error_flg = false;

		// 今日の日付の3日前のデータは溜まっていない可能性があるのでサーチしない
		$tar_date = $qahm_time->today_str();
		$tar_date = $qahm_time->xday_str( '-3', $tar_date );
		if ( $qahm_time->xday_num( $start_date, $tar_date ) > 0 ) {
			return;
		}

		// すでに該当日付のデータが作成されていれば処理を中断
		// insert関数では引数に月フラグを設けていないため、この処理は数日単位では実行できない（ファイル存在チェックが邪魔となる）
		// もしも今後拡張するならここは変更する必要あり
		$gsc_dir           = $this->get_data_dir_path( 'view/' . $this->tracking_id . '/gsc' );
		$gsc_lp_query_file = $gsc_dir . $start_date . '_gsc_lp_query.php';
		if ( $this->wrap_exists( $gsc_lp_query_file ) ) {
			return;
		}

		$token  = $this->client->getAccessToken();
		$ep_url = $this->get_property_url();
		if ( $ep_url === null ) {
			$ep_url = 'https://searchconsole.googleapis.com/webmasters/v3/sites/' . urlencode( $this->sc_url ) . '/searchAnalytics/query';
		}
		$headers = array(
			'Authorization' => 'Bearer ' . $token['access_token'],
			'Content-Type'  => 'application/json',
		);

		// その日の検索キーワード一覧を取得＆DBテーブル更新・挿入
		$query_log_table = $qahm_db->prefix . 'qa_gsc_' . $this->tracking_id . '_query_log';

		// 取得件数 total = $row_limit_per_request * $request_loop_max
		$row_limit_per_request = defined( 'QAHM_CONFIG_GSC_ROW_LIMIT_PER_REQUEST_KEYWORD' )
			? QAHM_CONFIG_GSC_ROW_LIMIT_PER_REQUEST_KEYWORD
			: 5000; // デフォルト値
		$request_loop_max      = 1;

		$requesting_types = array( 'web', 'image', 'video' );
		$types_cnt        = $this->wrap_count( $requesting_types );
		// typeごとにリクエストを分ける＝区切って処理する
		for ( $type_idx = 0; $type_idx < $types_cnt; $type_idx++ ) {
			$gsc_key_ary = array();

			// $request_loop_maxページ目まで、$row_limit_per_requestずつ取得する（GSC APIのページネーションを利用）
			$pagination_num = 0;
			while ( true ) {
				if ( $pagination_num >= $request_loop_max ) {
					break;
				}

				$start_row = $row_limit_per_request * $pagination_num; // GSC API の startRow は 0-based index (ゼロから始まる) 仕様
				// リクエスト内容をURL階層に合わせる
				if ( $this->first_dir_name == null ) {
					$body = '{
						"startDate":"' . $start_date . '",
						"endDate":"' . $end_date . '",
						"type":"' . $requesting_types[ $type_idx ] . '",
						"dimensions":["query"],
						"rowLimit":' . $row_limit_per_request . ',
						"startRow":' . $start_row . '
					}';
				} else {
					$body = '{
						"startDate":"' . $start_date . '",
						"endDate":"' . $end_date . '",
						"type":"' . $requesting_types[ $type_idx ] . '",
						"dimensions":["query"],
						"rowLimit":' . $row_limit_per_request . ',
						"startRow":' . $start_row . ',
						"dimensionFilterGroups": [{
							"filters": [{
								"dimension": "page",
								"operator": "contains",
								"expression": "/' . $this->first_dir_name . '/" 
							}]
						}]
					}';
				}

				$args = array(
					'headers' => $headers,
					'body'    => $body,
				);
				// GSC APIにリクエストを送信
				$response = wp_remote_post( $ep_url, $args );
				if ( is_wp_error( $response ) || ! isset( $response['response']['code'] ) || $response['response']['code'] !== 200 ) {
					$error_msg = '';
					if ( is_wp_error( $response ) ) {
						$error_msg = $response->get_error_message();
					} else {
						$error_msg = $this->wrap_json_encode( $response );
					}
					$qahm_log->warning( 'GSC API request failed: ' . $error_msg );
					break;
				}

				$query_ary = json_decode( $response['body'], true );
				if ( is_null( $query_ary ) ) {
					// JSONデコード失敗時
					$qahm_log->warning( 'JSON decode failed.' . $response['body'] );
					break;
				}
				if ( ! $this->wrap_array_key_exists( 'rows', $query_ary ) ) {
					// 戻りデータなし＝該当日付&&typeのGSCデータ無し
					break;
				}

				// キーワードの配列
				foreach ( $query_ary['rows'] as $query_rows ) {
					$query = $query_rows['keys'][0];
					if ( ! $this->wrap_in_array( $query, $gsc_key_ary ) ) { // SELECTはWHERE INが重複していても問題ないが、新規INSERT分で重複があるとエラーになるので重複チェック
						// 最長190バイトまでとする
						if ( $this->wrap_strlen( $query ) > 190 ) {
							$gsc_key_ary[] = $this->truncate_to_bytes( $query, 190 );
						} else {
							$gsc_key_ary[] = $query;
						}
					}
				}

				// 次のループへ
				if ( $this->wrap_count( $query_ary['rows'] ) < $row_limit_per_request ) {
					// 1リクエストで取得したデータが$row_limit_per_request未満なら終了←次ページ目へ行かずとも取り切ったことになるから。
					break;
				} else {
					++$pagination_num;
				}

				sleep( 1 );
			} // end while

			if ( empty( $gsc_key_ary ) ) {
				continue;
			}

			// DBのqa_gsc_query_logテーブルに挿入／更新
			$chunk_size     = 5000; // 5000件ずつ処理
			$gsc_key_chunks = array_chunk( $gsc_key_ary, $chunk_size );
			foreach ( $gsc_key_chunks as $gsc_key_chunk ) {

				// 既に存在するキーワード
				$existing_keywords = array();

				$placeholder_ary        = array_fill( 0, $this->wrap_count( $gsc_key_chunk ), '%s' );
				$sql_select_existing    = 'SELECT query_id, update_date, keyword FROM ' . $query_log_table . ' WHERE keyword IN (' . $this->wrap_implode( ',', $placeholder_ary ) . ')';
				$result_select_existing = $qahm_db->get_results( $qahm_db->prepare( $sql_select_existing, $gsc_key_chunk ), ARRAY_A );

				if ( $result_select_existing === null && $qahm_db->last_error !== '' ) {
					// get_resultsは失敗時にfalseを返さないのでnullで判定
					global $wpdb;
					$last_query_substr = mb_substr( $wpdb->last_query, 0, 200 );
					$qahm_log->error( 'DB Error: ' . $wpdb->last_error . ' | Last Query (substr200): ' . $last_query_substr );

					$db_query_error_flg = true;
					break;
				}

				if ( ! empty( $result_select_existing ) ) {
					$existing_keywords = array_column( $result_select_existing, 'keyword' );

					// update_dateが$start_dateより古いキーワードを更新
					$query_id_to_update = array();
					foreach ( $result_select_existing as $row ) {
						if ( $qahm_time->xday_num( $row['update_date'], $start_date ) < 0 ) {
							$query_id_to_update[] = $row['query_id'];
						}
					}
					if ( ! empty( $query_id_to_update ) ) {
						$placeholder_ary        = array_fill( 0, $this->wrap_count( $query_id_to_update ), '%d' );
						$sql_cmd                = 'UPDATE ' . $query_log_table . ' SET update_date = %s WHERE query_id IN (' . $this->wrap_implode( ',', $placeholder_ary ) . ')';
						$update_val_ary         = $this->wrap_array_merge( array( $start_date ), $query_id_to_update );
						$result_update_existing = $qahm_db->query( $qahm_db->prepare( $sql_cmd, $update_val_ary ) );
						if ( $result_update_existing === false && $qahm_db->last_error !== '' ) {
							//global $wpdb;
							$last_query_substr = mb_substr( $wpdb->last_query, 0, 200 );
							$qahm_log->error( 'DB Error: ' . $wpdb->last_error . ' | Last Query (substr200): ' . $last_query_substr );

							$db_query_error_flg = true;
							break;
						}
					}
				}

				// 新しいキーワード
				$insert_val_ary = array();
				if ( $this->wrap_count( $gsc_key_chunk ) !== $this->wrap_count( $existing_keywords ) ) {
					// 同数の場合は新しいキーワードはない

					// 既に存在するキーワードを除く
					if ( ! empty( $existing_keywords ) ) {
						foreach ( $gsc_key_chunk as $key ) {
							if ( ! $this->wrap_in_array( $key, $existing_keywords ) ) {
								$insert_val_ary[] = $start_date;
								$insert_val_ary[] = $key;
							}
						}
						// （※照らし合わせた結果、新しいキーワードはないかもしれない。empty($insert_val_ary)の場合はINSERT回避する）
					} else {
						foreach ( $gsc_key_chunk as $key ) {
							$insert_val_ary[] = $start_date;
							$insert_val_ary[] = $key;
						}
					}
					if ( ! empty( $insert_val_ary ) ) {
						// qa_gsc_query_logテーブルに挿入
						$placeholder_set_num    = $this->wrap_count( $insert_val_ary ) / 2;
						$placeholder_ary        = array_fill( 0, $placeholder_set_num, '(%s, %s)' );
						$sql_cmd                = 'INSERT INTO ' . $query_log_table . ' (update_date, keyword) VALUES ' . $this->wrap_implode( ',', $placeholder_ary );
						$result_insert_keywords = $qahm_db->query( $qahm_db->prepare( $sql_cmd, $insert_val_ary ) );
						if ( $result_insert_keywords === false && $qahm_db->last_error !== '' ) {
							global $wpdb;
							$last_query_substr = mb_substr( $wpdb->last_query, 0, 200 );
							$qahm_log->error( 'DB Error: ' . $wpdb->last_error . ' | Last Query (substr200): ' . $last_query_substr );

							$db_query_error_flg = true;
							break;
						}
					}
				}
			}
		} // end for( $requesting_types )

		if ( $db_query_error_flg ) {
			// DBクエリエラーが発生した場合はfalseを返す
			return false;
		}
	}

	/**
	 * サーチコンソールデータの作成
	 *
	 * @param string $start_date 開始日付 (YYYY-MM-DD形式)
	 * @param string $end_date 終了日付 (YYYY-MM-DD形式)
	 * @param bool $is_month_data 月データかどうかのフラグ
	 * @param int $timeout_sec タイムアウト秒数
	 * @return mixed
	 *      (string)'timed_out' ...処理がタイムアウトした場合
	 *      (bool)false ...APIエラーが発生した場合
	 *      NULL ...それ以外の場合
	 */
	public function create_search_console_data( $start_date, $end_date, $is_month_data, $timeout_sec = 80 ) {
		global $qahm_time;
		global $qahm_db;
		global $qahm_log;

		// 今日の日付の3日前のデータは溜まっていない可能性があるのでサーチしない
		$tar_date = $qahm_time->today_str();
		$tar_date = $qahm_time->xday_str( '-3', $tar_date );
		if ( $qahm_time->xday_num( $start_date, $tar_date ) > 0 ) {
			return;
		}
		if ( $is_month_data ) {
			// 今日の日付の3日前が同じ月ならサーチコンソールの月データが溜まっていない可能性があるので処理を省く
			$comp_y = $qahm_time->year( $start_date );
			$comp_m = $qahm_time->month( $start_date );
			$tar_y  = $qahm_time->year( $tar_date );
			$tar_m  = $qahm_time->month( $tar_date );
			if ( $comp_y > $tar_y ) {
				return;
			}
			if ( $comp_y === $tar_y && $comp_m >= $tar_m ) {
				return;
			}
		}

		// データファイル
		$gsc_dir = $this->get_data_dir_path( 'view/' . $this->tracking_id . '/gsc' );
		//$summary_dir = $this->get_data_dir_path( 'view/' . $this->tracking_id . '/gsc/summary' );
		if ( $is_month_data ) {
			$gsc_lp_query_file = $gsc_dir . $start_date . '_gsc_lp_query_1mon.php';
		} else {
			$gsc_lp_query_file = $gsc_dir . $start_date . '_gsc_lp_query.php';
		}

		// すでに該当日付のデータが作成されていれば処理を中断
		if ( $this->wrap_exists( $gsc_lp_query_file ) ) {
			return;
		}

		// tempディレクトリ
		$temp_dir   = $this->get_data_dir_path( 'temp/' );
		$mytemp_dir = $temp_dir . $this->tracking_id . '/';
		if ( ! $this->wrap_exists( $mytemp_dir ) ) {
			$this->wrap_mkdir( $mytemp_dir );
		}

		// GSC情報
		$token  = $this->client->getAccessToken();
		$ep_url = $this->get_property_url();
		if ( $ep_url === null ) {
			$ep_url = 'https://searchconsole.googleapis.com/webmasters/v3/sites/' . urlencode( $this->sc_url ) . '/searchAnalytics/query';
		}
		$headers = array(
			'Authorization' => 'Bearer ' . $token['access_token'],
			'Content-Type'  => 'application/json',
		);

		$gsc_api_error_flg  = false; // APIエラーが発生したか
		$gsc_api_error_code = '';    // APIエラーコード

		// 時間制限のため時刻メモ
		$me_func_start_time = time();

		// GSCデータを取得していく
		// typeごとにリクエストを分ける＝区切って処理する
		$requesting_types = array( 'web', 'image', 'video' );
		$types_cnt        = $this->wrap_count( $requesting_types );

		// メモファイル
		$tempfile_gsc_loop_memo = $mytemp_dir . $start_date . '_' . $end_date . '_gsc_loop_memo.php';
		$types_loop_memo_ary    = array();
		if ( $this->wrap_exists( $tempfile_gsc_loop_memo ) ) {
			$types_loop_memo_ary = $this->wrap_unserialize( $this->wrap_get_contents( $tempfile_gsc_loop_memo ) );
		} else {
			for ( $type_idx = 0; $type_idx < $types_cnt; $type_idx++ ) {
				$types_loop_memo_ary[ $type_idx ] = array(
					'pages_cnt'     => 0,
					'iteration_num' => 0,
					'done'          => false,
				);
			}
			$this->wrap_put_contents( $tempfile_gsc_loop_memo, $this->wrap_serialize( $types_loop_memo_ary ) );
		}

		// ページ取得
		for ( $type_idx = 0; $type_idx < $types_cnt; $type_idx++ ) {

			// ページリクエスト済みか
			$tempfile_gsc_qa_pages_bytype = $mytemp_dir . $start_date . '_' . $end_date . '_gsc_qa_pages_type' . $type_idx . '.php';
			if ( $this->wrap_exists( $tempfile_gsc_qa_pages_bytype ) || $types_loop_memo_ary[ $type_idx ]['done'] ) {
				continue;
			}

			// GSC該当ページとqa_pages情報
			$row_limit_per_request = defined( 'QAHM_CONFIG_GSC_ROW_LIMIT_PER_REQUEST_PAGE' )
				? QAHM_CONFIG_GSC_ROW_LIMIT_PER_REQUEST_PAGE
				: 1000; // デフォルト値
			if ( ! $this->first_dir_name ) {
				$body = '{
					"startDate":"' . $start_date . '",
					"endDate":"' . $end_date . '",
					"type":"' . $requesting_types[ $type_idx ] . '",
					"dimensions":["page"],
					"rowLimit":' . $row_limit_per_request . '
				}';
			} else {
				$body = '{
					"startDate":"' . $start_date . '",
					"endDate":"' . $end_date . '",
					"type":"' . $requesting_types[ $type_idx ] . '",
					"dimensions":["page"],
					"rowLimit":' . $row_limit_per_request . ',
					"dimensionFilterGroups": [{
						"filters": [{
							"dimension": "page",
							"operator": "contains",
							"expression": "/' . $this->first_dir_name . '/ 
						}]
					}]
				}';
			}
			$args = array(
				'headers' => $headers,
				'body'    => $body,
				'timeout' => 20,
			);
			// GSC APIにリクエストを送信
			$response = wp_remote_post( $ep_url, $args );
			if ( is_wp_error( $response ) ) {
				$gsc_api_error_flg = true;
				$wp_error_msg      = $response->get_error_message();
				$qahm_log->warning( 'GSC api error occurred(WP_ERROR). message:' . $wp_error_msg );
				break;
			} elseif ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
				$gsc_api_error_flg  = true;
				$gsc_api_error_code = wp_remote_retrieve_response_code( $response );
				$qahm_log->warning( 'GSC api error occurred(response code). code:' . $gsc_api_error_code );
				break;
			}

			$gsc_res_ary = json_decode( $response['body'], true );
			if ( ! $this->wrap_array_key_exists( 'rows', $gsc_res_ary ) ) {
				//$qahm_log->info( 'GSC data returned empty. date: ' . $start_date . ' type: ' . $requesting_types[$type_idx] );
				$types_loop_memo_ary[ $type_idx ]['done'] = true;
				$this->wrap_put_contents( $tempfile_gsc_loop_memo, $this->wrap_serialize( $types_loop_memo_ary ) );
				continue;
			}

			$gsc_pages_ary    = array();
			$gsc_rows_ary_cnt = $this->wrap_count( $gsc_res_ary['rows'] );
			for ( $iii = 0; $iii < $gsc_rows_ary_cnt; $iii++ ) { // 順番が大切なので念のためforにしている
				$gsc_pages_ary[] = $gsc_res_ary['rows'][ $iii ]['keys']; // 'keys': ['URL']
			}

			// DB qa_pages からpage_id等のページ情報を取得
			$qa_pages_table = $qahm_db->prefix . 'qa_pages';
			$gsc_qa_pages   = array();

			$chunk_size       = 5000; // 5000件ずつ一括処理（5000未満でも問題ないので5000のまま）
			$gsc_pages_chunks = array_chunk( $gsc_pages_ary, $chunk_size );
			foreach ( $gsc_pages_chunks as $gsc_pages_chunk ) {

				$url_hash_ary        = array();
				$gsc_pages_chunk_cnt = $this->wrap_count( $gsc_pages_chunk );
				for ( $jjj = 0; $jjj < $gsc_pages_chunk_cnt; $jjj++ ) { // 順番が大切なので念のためforにしている
					$url_hash_ary[] = hash( 'fnv164', $gsc_pages_chunk[ $jjj ][0] );
				}

				// qa_pages登録済みのページ
				$existing_qa_pages = array();
				$existing_url_hash = array();

				$placeholder_ary   = array_fill( 0, $this->wrap_count( $url_hash_ary ), '%s' );
				$sql_cmd           = 'SELECT page_id, wp_qa_type, wp_qa_id, title, update_date, url, url_hash FROM ' . $qa_pages_table . ' WHERE url_hash IN (' . $this->wrap_implode( ',', $placeholder_ary ) . ')';
				$sql_cmd           = $qahm_db->prepare( $sql_cmd, $url_hash_ary );
				$existing_qa_pages = $qahm_db->get_results( $sql_cmd, ARRAY_A );
				if ( $existing_qa_pages === null && $qahm_db->last_error !== '' ) {
					global $wpdb;
					$last_query_substr = mb_substr( $wpdb->last_query, 0, 200 );
					$qahm_log->error( 'DB Error: ' . $wpdb->last_error . ' | Last Query (substr200): ' . $last_query_substr );
				}

				if ( ! empty( $existing_qa_pages ) ) {
					$existing_url_hash = array_column( $existing_qa_pages, 'url_hash' );

					// update_dateが$start_dateより古いキーワードを更新
					$qa_pages_id_to_update = array();
					foreach ( $existing_qa_pages as $row ) {
						if ( $qahm_time->xday_num( $row['update_date'], $start_date ) < 0 ) {
							$qa_pages_id_to_update[] = $row['page_id'];
						}
					}

					if ( ! empty( $qa_pages_id_to_update ) ) {
						$placeholder_ary        = array_fill( 0, $this->wrap_count( $qa_pages_id_to_update ), '%d' );
						$sql_cmd                = 'UPDATE ' . $qa_pages_table . ' SET update_date = %s WHERE page_id IN (' . $this->wrap_implode( ',', $placeholder_ary ) . ')';
						$update_val_ary         = $this->wrap_array_merge( array( $start_date ), $qa_pages_id_to_update );
						$result_update_existing = $qahm_db->query( $qahm_db->prepare( $sql_cmd, $update_val_ary ) );
						if ( $result_update_existing === false && $qahm_db->last_error !== '' ) {
							global $wpdb;
							$last_query_substr = mb_substr( $wpdb->last_query, 0, 200 );
							$qahm_log->error( 'DB Error: ' . $wpdb->last_error . ' | Last Query (substr200): ' . $last_query_substr );
						}
					}
				}

				// ページ情報をまとめる
				$qa_pages_in_chunk = array();
				if ( $this->wrap_count( $gsc_pages_chunk ) === $this->wrap_count( $existing_qa_pages ) ) {
					// 同数の場合、すべてqa_pages登録済み
					$qa_pages_in_chunk = $existing_qa_pages;

				} else {
					// qa_pages未登録のページ
					$insert_val_ary     = array();
					$new_pages_url_hash = array();

					$qahm_time_now = $qahm_time->now_str();
					// 既に存在するページを除く
					if ( ! empty( $existing_qa_pages ) ) {
						foreach ( $gsc_pages_chunk as $page ) {
							if ( ! $this->wrap_in_array( hash( 'fnv164', $page[0] ), $existing_url_hash ) ) {
								// Differs between ZERO and QA - Start ----------
								$wp_qa_type = '';
								$wp_qa_id   = 0;
								$page_title = '(Unavailable. Inserted via GSC)';
									/*
									// QAHM用コードを未調整のまま臨時で残す Feb. 2025
									$post = get_post( url_to_postid( $base_url ) );
									if ( $post === null ) {
										continue;
									}
									if ( $post->post_type === 'post' ) {
										$wp_qa_type = 'p';
									} elseif( $post->post_type === 'page' ) {
										$wp_qa_type = 'page_id';
									}
									$wp_qa_id  = $post->ID;
									$page_title = $post->post_title;
									*/
								// Differs between ZERO and QA - End ----------
								$insert_val_ary[] = $this->tracking_id; // tracking_id
								$insert_val_ary[] = $wp_qa_type; // wp_qa_type
								$insert_val_ary[] = $wp_qa_id; // wp_qa_id
								$insert_val_ary[] = $page[0]; // url
								$insert_val_ary[] = hash( 'fnv164', $page[0] ); // url_hash
								$insert_val_ary[] = $page_title; // title
								$insert_val_ary[] = $qahm_time_now; // update_date

								$new_pages_url_hash[] = hash( 'fnv164', $page[0] );
							}
						}
						// 照らし合わせた結果、未登録ページはないかもしれない。empty($insert_val_ary)の場合はINSERT回避する
					} else {
						foreach ( $gsc_pages_chunk as $page ) {
							// Differs between ZERO and QA - Start ----------
							$wp_qa_type = '';
							$wp_qa_id   = 0;
							$page_title = '(Unavailable. Inserted via GSC)';
							// Differs between ZERO and QA - End ----------
							$insert_val_ary[] = $this->tracking_id; // tracking_id
							$insert_val_ary[] = $wp_qa_type; // wp_qa_type
							$insert_val_ary[] = $wp_qa_id; // wp_qa_id
							$insert_val_ary[] = $page[0]; // url
							$insert_val_ary[] = hash( 'fnv164', $page[0] ); // url_hash
							$insert_val_ary[] = $page_title; // title
							$insert_val_ary[] = $qahm_time_now; // update_date

							$new_pages_url_hash[] = hash( 'fnv164', $page[0] );
						}
					}
					if ( ! empty( $insert_val_ary ) ) {
						$placeholder_set_num = $this->wrap_count( $insert_val_ary ) / 7;
						$placeholder_ary     = array_fill( 0, $placeholder_set_num, '(%s, %s, %d, %s, %s, %s, %s)' );
						$sql_cmd             = 'INSERT INTO ' . $qa_pages_table . ' (tracking_id, wp_qa_type, wp_qa_id, url, url_hash, title, update_date) VALUES ' . $this->wrap_implode( ',', $placeholder_ary ) . ' ON DUPLICATE KEY UPDATE title = VALUES(title), update_date = VALUES(update_date)';
						$result_insert_pages = $qahm_db->query( $qahm_db->prepare( $sql_cmd, $insert_val_ary ) );
						if ( $result_insert_pages === false && $qahm_db->last_error !== '' ) {
							global $wpdb;
							$last_query_substr = mb_substr( $wpdb->last_query, 0, 200 );
							$qahm_log->error( 'DB Error: ' . $wpdb->last_error . ' | Last Query (substr200): ' . $last_query_substr );
						}
						// 挿入後、qa_pagesテーブルから再度取得（page_id等の情報を取得）
						$url_hash_in_chunk = $this->wrap_array_merge( $existing_url_hash, $new_pages_url_hash );
						$placeholder_ary   = array_fill( 0, $this->wrap_count( $url_hash_in_chunk ), '%s' );
						$sql_cmd           = 'SELECT page_id, wp_qa_type, wp_qa_id, title, update_date, url, url_hash FROM ' . $qa_pages_table . ' WHERE url_hash IN (' . $this->wrap_implode( ',', $placeholder_ary ) . ')';
						$sql_cmd           = $qahm_db->prepare( $sql_cmd, $url_hash_in_chunk );
						$qa_pages_in_chunk = $qahm_db->get_results( $sql_cmd, ARRAY_A );

					} else {
						// 未登録ページがない場合は、既存のページ情報をそのまま使う
						$qa_pages_in_chunk = $existing_qa_pages;
					}
				}

				// ページ情報の順番を直してマージ
				$qa_pages_keyby_urlhash = array();
				$qa_pages_keyby_urlhash = array_column( $qa_pages_in_chunk, null, 'url_hash' );

				$fixed_ordered_ary = array();
				$url_hash_ary_cnt  = $this->wrap_count( $url_hash_ary );
				for ( $jjj = 0; $jjj < $url_hash_ary_cnt; $jjj++ ) {
					$fixed_ordered_ary[] = $qa_pages_keyby_urlhash[ $url_hash_ary[ $jjj ] ];
				}
				$gsc_qa_pages = $this->wrap_array_merge( $gsc_qa_pages, $fixed_ordered_ary );

			} // end foreach( $gsc_pages_chunks )

			// tempファイルへ保存
			$this->wrap_put_contents( $tempfile_gsc_qa_pages_bytype, $this->wrap_serialize( $gsc_qa_pages ) );
			// メモ更新して保存
			$types_loop_memo_ary[ $type_idx ]['pages_cnt'] = $this->wrap_count( $gsc_qa_pages );
			$this->wrap_put_contents( $tempfile_gsc_loop_memo, $this->wrap_serialize( $types_loop_memo_ary ) );

		} // end for( $requesting_types )

		if ( $gsc_api_error_flg ) {
			// tempファイル削除
			foreach ( $requesting_types as $type_idx => $type_name ) {
				$this->wrap_delete( $mytemp_dir . $start_date . '_' . $end_date . '_gsc_qa_pages_type' . $type_idx . '.php' );
				$this->wrap_delete( $mytemp_dir . $start_date . '_' . $end_date . '_gsc_lp_query_type' . $type_idx . '.php' );
			}
			$this->wrap_delete( $mytemp_dir . $start_date . '_' . $end_date . '_gsc_loop_memo.php' );

			return false;
		}

		// 制限時間を超えていたら終了
		$me_proc_time_now = time();
		if ( ( $me_proc_time_now - $me_func_start_time ) > $timeout_sec ) {
			return 'timed_out';
		}

		// 各ページについて、サーチコンソールデータを取得

		$me_loops_timeout_sec = $timeout_sec;
		$is_timed_out         = false;
		$end_loop_flg         = false;
		for ( $type_idx = 0; $type_idx < $types_cnt; $type_idx++ ) {

			$lp_query_data_by_type         = array();
			$tempfile_lp_query_data_bytype = $mytemp_dir . $start_date . '_' . $end_date . '_gsc_lp_query_type' . $type_idx . '.php';
			if ( $this->wrap_exists( $tempfile_lp_query_data_bytype ) ) {
				$lp_query_data_by_type = $this->wrap_unserialize( $this->wrap_get_contents( $tempfile_lp_query_data_bytype ) );
			}

			//tempからgsc_qa_pages読み込み
			$tempfile_gsc_qa_pages_bytype = $mytemp_dir . $start_date . '_' . $end_date . '_gsc_qa_pages_type' . $type_idx . '.php';
			if ( ! $this->wrap_exists( $tempfile_gsc_qa_pages_bytype ) ) {
				continue;
			}
			$gsc_qa_pages = $this->wrap_unserialize( $this->wrap_get_contents( $tempfile_gsc_qa_pages_bytype ) );
			if ( empty( $gsc_qa_pages ) ) {
				continue;
			}

			$gsc_qa_pages_cnt      = $this->wrap_count( $gsc_qa_pages );
			$row_limit_per_request = defined( 'QAHM_CONFIG_GSC_ROW_LIMIT_PER_REQUEST_QUERY' )
				? QAHM_CONFIG_GSC_ROW_LIMIT_PER_REQUEST_QUERY
				: 5000; // デフォルト値
			$query_log_table       = $qahm_db->prefix . 'qa_gsc_' . $this->tracking_id . '_query_log';

			// スタート位置をメモファイルから取得
			//$tempfile_gsc_loop_memo = $mytemp_dir . $start_date . '_' . $end_date . '_gsc_loop_memo.php';
			$types_loop_memo_ary = $this->wrap_unserialize( $this->wrap_get_contents( $tempfile_gsc_loop_memo ) );
			$loop_memo           = $types_loop_memo_ary[ $type_idx ];
			if ( $loop_memo['done'] ) {
				continue;
			}
			$iteration_num = $loop_memo['iteration_num']; //初期値は0

			for ( $iii = $iteration_num; $iii < $gsc_qa_pages_cnt; $iii++ ) {

				// ループ時間制限
				$me_loops_time_now = time();
				if ( ( $me_loops_time_now - $me_func_start_time ) > $me_loops_timeout_sec ) {
					$is_timed_out = true;
					$end_loop_flg = true;
					break;
				}

				$base_url = $gsc_qa_pages[ $iii ]['url'];

				$body = '{
					"startDate":"' . $start_date . '",
					"endDate":"' . $end_date . '",
					"type":"' . $requesting_types[ $type_idx ] . '",
					"dimensions":["query"],
					"dimensionFilterGroups": [
						{
							"groupType": "and",
							"filters": [
								{
									"dimension": "page",
									"operator": "equals",
									"expression": "' . $base_url . '"
								}
							]
						}
					],
					"rowLimit":' . $row_limit_per_request . '
				}';
				$args = array(
					'headers' => $headers,
					'body'    => $body,
					'timeout' => 20,
				);

				$response = wp_remote_post( $ep_url, $args );
				if ( is_wp_error( $response ) ) {
					$gsc_api_error_flg = true;
					$wp_error_msg      = $response->get_error_message();
					$qahm_log->warning( 'GSC api error occurred(WP_ERROR). message:' . $wp_error_msg );
					$end_loop_flg = true;
					break;
				} elseif ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
					$gsc_api_error_flg  = true;
					$gsc_api_error_code = wp_remote_retrieve_response_code( $response );
					$qahm_log->warning( 'GSC api error occurred(response code). code:' . $gsc_api_error_code );
					$end_loop_flg = true;
					break;
				}

				$query_ary = json_decode( $response['body'], true );
				if ( ! $this->wrap_array_key_exists( 'rows', $query_ary ) ) {
					// 戻りデータなし＝$base_urlのGSCデータ（query情報）無し
					// iteration_num更新
					$loop_memo['iteration_num'] = $iii + 1;
					continue;
				}
				$gsc_query_ary = $query_ary['rows']; // 'keys': ['query']（検索キーワード）

				// キーワードを190バイト以内にカットした配列を作成
				$gsc_keyword_ary   = array();
				$gsc_query_ary_cnt = $this->wrap_count( $gsc_query_ary );
				for ( $jjj = 0; $jjj < $gsc_query_ary_cnt; $jjj++ ) {
					// $gsc_query_aryと$gsc_keyword_aryの順番が揃っていることが大切なので、念のためforループにしている
					if ( $this->wrap_strlen( $gsc_query_ary[ $jjj ]['keys'][0] ) > 190 ) {
						$gsc_keyword_ary[ $jjj ] = $this->truncate_to_bytes( $gsc_query_ary[ $jjj ]['keys'][0], 190 );
					} else {
						$gsc_keyword_ary[ $jjj ] = $gsc_query_ary[ $jjj ]['keys'][0];
					}
				}

				// DB qa_gsc_query_logからquery_idを取得
				$query_id_ary = array();

				$chunk_size         = 5000; // 5000件ずつ一括処理
				$gsc_keyword_chunks = array_chunk( $gsc_keyword_ary, $chunk_size );
				foreach ( $gsc_keyword_chunks as $gsc_keyword_chunk ) {
					$query_id_in_chunk = array();

					$placeholder_ary = array_fill( 0, $this->wrap_count( $gsc_keyword_chunk ), '%s' );
					$sql_cmd         = 'SELECT query_id, keyword FROM ' . $query_log_table . ' WHERE keyword IN (' . $this->wrap_implode( ',', $placeholder_ary ) . ')';
					$sql_cmd         = $qahm_db->prepare( $sql_cmd, $gsc_keyword_chunk );
					$queryid_keywds  = $qahm_db->get_results( $sql_cmd, ARRAY_A );
					if ( $queryid_keywds === null && $qahm_db->last_error !== '' ) {
						global $wpdb;
						$last_query_substr = mb_substr( $wpdb->last_query, 0, 200 );
						$qahm_log->error( 'DB Error: ' . $wpdb->last_error . ' | Last Query (substr200): ' . $last_query_substr );
					}

					$query_id_in_chunk = array_column( $queryid_keywds, 'query_id', 'keyword' ); // 'keyword' => 'query_id'

					$query_id_ary = $this->wrap_array_merge( $query_id_ary, $query_id_in_chunk );

				}

				// $gsc_query_ary をベースに、query情報をまとめる
				$query_info_ary    = array();
				$gsc_query_ary_cnt = $this->wrap_count( $gsc_query_ary );
				for ( $lll = 0; $lll < $gsc_query_ary_cnt; $lll++ ) {

					if ( ! isset( $query_id_ary[ $gsc_keyword_ary[ $lll ] ] ) ) {
						continue;
					}

					$query_info_ary[] = array(
						'query_id'    => $query_id_ary[ $gsc_keyword_ary[ $lll ] ],
						'keyword'     => $gsc_keyword_ary[ $lll ],
						'search_type' => $type_idx + 1, // (int) 1:web, 2:image, 3:video
						'impressions' => (int) $gsc_query_ary[ $lll ]['impressions'],
						'clicks'      => (int) $gsc_query_ary[ $lll ]['clicks'],
						'position'    => (float) $gsc_query_ary[ $lll ]['position'],
					);
				}

				// ページ情報まとめ
				$iii_lp_query_data       = array(
					'page_id'    => $gsc_qa_pages[ $iii ]['page_id'],
					'wp_qa_type' => $gsc_qa_pages[ $iii ]['wp_qa_type'],
					'wp_qa_id'   => $gsc_qa_pages[ $iii ]['wp_qa_id'],
					'title'      => $gsc_qa_pages[ $iii ]['title'],
					'url'        => $gsc_qa_pages[ $iii ]['url'],
					'query'      => $query_info_ary,
				);
				$lp_query_data_by_type[] = $iii_lp_query_data;

				// iteration_num更新
				$loop_memo['iteration_num'] = $iii + 1;

				//sleep(1);
			} // end for( $gsc_qa_pages )

			// tempファイルへ保存
			$this->wrap_put_contents( $tempfile_lp_query_data_bytype, $this->wrap_serialize( $lp_query_data_by_type ) );
			// メモファイル更新
			if ( $loop_memo['iteration_num'] >= $gsc_qa_pages_cnt ) {
				$loop_memo['done'] = true;
			}
			$types_loop_memo_ary[ $type_idx ] = $loop_memo;
			$this->wrap_put_contents( $tempfile_gsc_loop_memo, $this->wrap_serialize( $types_loop_memo_ary ) );

			if ( $end_loop_flg ) {
				break;
			}
		} // end for( $requesting_types )

		if ( ! $gsc_api_error_flg ) {

			// typeごとだったデータをマージ
			$can_merge = true;
			foreach ( $types_loop_memo_ary as $memo ) {
				if ( ! $memo['done'] ) {
					$can_merge = false;
					break;
				}
			}
			if ( $can_merge ) {
				$merged_gsc_lp_query_ary = array();
				foreach ( $types_loop_memo_ary as $type_idx => $memo ) {
					$lp_query_data_by_type         = array();
					$tempfile_lp_query_data_bytype = $mytemp_dir . $start_date . '_' . $end_date . '_gsc_lp_query_type' . $type_idx . '.php';
					if ( ! $this->wrap_exists( $tempfile_lp_query_data_bytype ) ) {
						continue;
					}
					$lp_query_data_by_type = $this->wrap_unserialize( $this->wrap_get_contents( $tempfile_lp_query_data_bytype ) );
					if ( $lp_query_data_by_type ) {
						$merged_gsc_lp_query_ary = $this->wrap_array_merge( $merged_gsc_lp_query_ary, $lp_query_data_by_type );
					}
				}

				// データをファイルに保存
				// APIエラーが発生しなければ、データなしでもファイル保存。ただし GSC API の遅れが出ることもあるので、empty&&直近7日以内だったらファイル保存しない。
				if ( ! empty( $merged_gsc_lp_query_ary ) || ( $qahm_time->xday_num( $start_date, $tar_date ) < -7 ) ) {
					$is_saved = $this->wrap_put_contents( $gsc_lp_query_file, $this->wrap_serialize( $merged_gsc_lp_query_ary ) );

					if ( ! $is_saved ) {
						$qahm_log->warning( 'Failed to save GSC data file. date: ' . $start_date );
					}
				}
				// tempファイル削除
				foreach ( $requesting_types as $type_idx => $type_name ) {
					$this->wrap_delete( $mytemp_dir . $start_date . '_' . $end_date . '_gsc_qa_pages_type' . $type_idx . '.php' );
					$this->wrap_delete( $mytemp_dir . $start_date . '_' . $end_date . '_gsc_lp_query_type' . $type_idx . '.php' );
				}
				$this->wrap_delete( $mytemp_dir . $start_date . '_' . $end_date . '_gsc_loop_memo.php' );
			}

			if ( $is_timed_out ) {
				return 'timed_out';
			} else {
				return;
			}
		} else {
			// APIエラーの場合
			// tempファイル削除
			foreach ( $requesting_types as $type_idx => $type_name ) {
				$this->wrap_delete( $mytemp_dir . $start_date . '_' . $end_date . '_gsc_qa_pages_type' . $type_idx . '.php' );
				$this->wrap_delete( $mytemp_dir . $start_date . '_' . $end_date . '_gsc_lp_query_type' . $type_idx . '.php' );
			}
			$this->wrap_delete( $mytemp_dir . $start_date . '_' . $end_date . '_gsc_loop_memo.php' );
			return false;
		}
	} // end of (function)create_search_console_data


	function truncate_to_bytes( $str, $max_bytes, $encoding = 'UTF-8' ) {
		$cut_str = '';
		$bytes   = 0;

		foreach ( preg_split( '//u', $str, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
			$char_bytes = $this->wrap_strlen( mb_convert_encoding( $char, 'UTF-8', $encoding ) );
			if ( ( $bytes + $char_bytes ) > $max_bytes ) {
				break;
			}
			$cut_str .= $char;
			$bytes   += $char_bytes;
		}
		return $cut_str;
	}
} // end of class
