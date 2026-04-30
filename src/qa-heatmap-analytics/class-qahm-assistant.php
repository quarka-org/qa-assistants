<?php
defined( 'ABSPATH' ) || exit;
/**
 * Assistant base class (Legacy)
 *
 * FROZEN: このファイルは凍結済み。今後一切の機能追加・変更を行わないこと。
 * レガシー（main.php方式）アシスタントの基底クラス。
 * 新規アシスタントはマニフェスト方式（class-qahm-assistant-runtime-handler.php）を使用すること。
 *
 * Design spec: docs/specs/assistant-manifest.md
 *
 * @package qa_heatmap_analytics
 */

class QAHM_Assistant extends QAHM_File_Data {

	// Public variables
	public $tracking_id  = 'all';
	public $translations = array();

	public $state     = 'start'; // Add a state variable
	public $exit_flag = false; // Change to $exit_flag
	public $execute   = array();

	public $debug_logs = array();

	//user variables
	public $result = array();

	// session keep variables
	public $session_commands = array();
	public $session_free     = '';

	//  // Constructor
	public function __construct( $tracking_id, $dir_path, $state, $session_variables = null ) {
		$this->tracking_id = $tracking_id;
		if ( $state ) {
			$this->state = $state;
		} else {
			$this->state = 'start';
		}

		// translations.jsonの読み込み
		$locale    = get_locale(); // 例: en_US
		$file_path = $dir_path . "/translations-{$locale}.json";

		// フォールバック（例: en だけ指定された場合に translations-en.json を見る）
		if ( ! file_exists( $file_path ) ) {
			$lang      = $this->wrap_substr( $locale, 0, 2 );
			$file_path = $dir_path . "/translations-{$lang}.json";
		}

		// さらにフォールバック（translations.json）
		if ( ! file_exists( $file_path ) ) {
			$file_path = $dir_path . '/translations.json';
		}

		// JSON をデコードして連想配列に
		if ( file_exists( $file_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- WP_Filesystem may use FTP mode.
			$json               = file_get_contents( $file_path );
			$this->translations = json_decode( $json, true );
		}

		// エラー対策
		if ( ! is_array( $this->translations ) ) {
			$this->translations = array();
		}

		// If session variables are provided, set them
		if ( is_array( $session_variables ) ) {
			foreach ( $session_variables as $key => $value ) {
				if ( property_exists( $this, $key ) ) {
					$this->$key = $value;
				}
			}
		}

		if ( empty( $this->session_commands ) ) {
			$file_path = $dir_path . '/commands.json';

			// JSONをデコードして連想配列に
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- WP_Filesystem may use FTP mode.
			$json          = file_get_contents( $file_path );
			$commands_data = json_decode( $json, true );

			// コマンドのtext部分を翻訳に差し替える
			if ( is_array( $commands_data ) ) {
				foreach ( $commands_data as &$command_group ) {
					if ( isset( $command_group['commands'] ) && is_array( $command_group['commands'] ) ) {
						foreach ( $command_group['commands'] as &$cmd ) {
							if ( isset( $cmd['text'] ) ) {
								$cmd['text'] = $this->resolve_translation( $cmd['text'], $this->translations );
							}
						}
					}
				}
				unset( $command_group, $cmd ); // 参照の開放
			}

			$this->session_commands = $commands_data;

			// エラー対策
			if ( ! is_array( $this->session_commands ) ) {
				$this->session_commands = array();
			}
		}
	}
	public function run_ai( $instruction, $content ) {
		// Plugin Check exclusion: Uses cURL for controlled AI request; wp_remote_post() not available in current context
		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_close

		// Initialize cURL
		$ch = curl_init();

		// Set the URL
		curl_setopt( $ch, CURLOPT_URL, 'https://kensyo.caddy.jp/ajajax.php' );

		// Set the HTTP method to POST
		curl_setopt( $ch, CURLOPT_POST, 1 );

		// Set the POST fields
		$postData = array(
			'instructions' => $instruction,
			'content'      => $content,
		);
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $postData );

		// Set option to return the result instead of outputting it
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

		// Execute the cURL request
		$response = curl_exec( $ch );

		// Close the cURL resource
		curl_close( $ch );

		// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_init, WordPress.WP.AlternativeFunctions.curl_curl_setopt, WordPress.WP.AlternativeFunctions.curl_curl_exec, WordPress.WP.AlternativeFunctions.curl_curl_close

		// Convert the response from JSON to an associative array
		$result = json_decode( $response, true );

		// Return the result
		return $result;
	}
	public function show_message( $message_number ) {
		global $qahm_log;

		if ( $this->exit_flag ) {
			$qahm_log->warning(
				array(
					'message'        => 'show_message() called after exit_flag was set',
					'message_number' => $message_number,
					'state'          => $this->state,
					'class'          => get_class( $this ),
				)
			);
			return;
		}

		$message         = $this->translations['messages'][ $message_number ];
		$message         = $this->add_session_values( $message );
		$message_html    = nl2br( $message );
		$this->execute[] = array( 'msg' => $message_html );
	}
	public function show_data( $data_ary ) {
		global $qahm_log;

		if ( $this->exit_flag ) {
			$qahm_log->warning(
				array(
					'message' => 'show_data() called after exit_flag was set',
					'state'   => $this->state,
					'class'   => get_class( $this ),
				)
			);
			return;
		}

		$this->execute[] = array( 'data' => $data_ary );
	}
	public function add_session_values( $message ) {
		// Extract variable names from the message
		$pattern = '/\{(\$.*?)\}/';
		preg_match_all( $pattern, $message, $matches );

		// Loop through the variable names
		foreach ( $matches[1] as $var_name ) {
			// Check if $var_name is referencing an array
			if ( $this->wrap_strpos( $var_name, '[' ) !== false ) {
				// Extract the array name and keys from $var_name
				preg_match( '/([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)(\[.*\])?/', $var_name, $match );
				$array_name = $match[1];
				$array_part = isset( $match[2] ) ? $match[2] : '';
				// Set the session variable name to the array name
				$session_var_name = 'session_' . $array_name;
				// Check if the array exists in this class
				if ( isset( $this->$session_var_name ) ) {
					if ( $array_part !== '' ) {
						//$keys = $this->wrap_explode("']['", $this->wrap_trim($array_part, "[]'"));
						// 余計な文字を取り除くために trim 関数を使用
						$cleaned_array_part = $this->wrap_trim( $array_part, "[]'" );
						// 配列のキーを分割するための正規表現
						$keys = preg_split( '/\]\[|\[|\]/', $cleaned_array_part );
						// 各キーから余分なアポストロフィーを取り除く
						$keys = $this->wrap_array_map(
							function ( $key ) {
								return $this->wrap_trim( $key, "'" );
							},
							$keys
						);
						// Start with the main array
						$current = $this->$session_var_name;
						foreach ( $keys as $key ) {
							// Check if the key exists in the current array
							if ( isset( $current[ $key ] ) ) {
								// Move the current array down to the next level
								$current = $current[ $key ];
							} else {
								// If the key does not exist, stop checking
								break;
							}
						}
						$message = str_replace( '{' . $var_name . '}', $current, $message );
					} else {
						$message = str_replace( '{' . $var_name . '}', $this->$session_var_name, $message );
					}
				} else {
					$message = str_replace( '{' . $var_name . '}', '*Sorry not worked', $message );
				}
			} else {
				// Set the session variable name to $var_name
				$new_var_name     = $this->wrap_ltrim( $var_name, '$' );
				$session_var_name = 'session_' . $new_var_name;
				// Check if the variable exists in this class
				if ( isset( $this->$new_var_name ) ) {
					// Replace the variable in the message with its value
					$message = str_replace( '{' . $var_name . '}', $this->$new_var_name, $message );
				}
				if ( isset( $this->$session_var_name ) ) {
					// Replace the variable in the message with its value
					$message = str_replace( '{' . $var_name . '}', $this->$session_var_name, $message );
				}
			}
		}
		return $message;
	}
	public function show_command( $command_id ) {
		global $qahm_log;

		if ( $this->exit_flag ) {
			$qahm_log->warning(
				array(
					'message'    => 'show_command() called after exit_flag was set',
					'command_id' => $command_id,
					'state'      => $this->state,
					'class'      => get_class( $this ),
				)
			);
			return;
		}

		foreach ( $this->session_commands as $command ) {
			if ( $command['id'] == $command_id ) {
				$this->execute[] = array( 'cmd' => $command['commands'] );
				break;
			}
		}
		$this->exit_flag = true;
	}
	// command「次へ」専用
	public function show_command_nextstr( $action_next ) {
		$next_text = isset( $this->translations['commands']['next'] )
			? $this->translations['commands']['next']
			: esc_html__( 'Next', 'qa-heatmap-analytics' );

		$command_data    = array(
			array(
				'text'   => $next_text,
				'action' => array(
					'next' => $action_next,
				),
			),
		);
		$this->execute[] = array( 'cmd' => $command_data );
		$this->exit_flag = true;
	}

	/**
	 * 次の状態への遷移を延期する
	 *
	 * 現在の処理を終了し、フロントエンドに次の状態を指示します。
	 * `return 'next_state'`とは異なり、同一リクエスト内で次の状態を実行せず、
	 * フロントエンドが新しいAJAXリクエストを送信するまで遷移を延期します。
	 *
	 * これにより、以下の利点があります:
	 * - 処理を段階的に分割し、レスポンスサイズを制御
	 * - タイムアウトのリスクを軽減
	 * - フロントエンドでの処理タイミングを制御
	 *
	 * @param string $next_state 次の状態名
	 * @return void
	 *
	 * @example
	 * protected function handle_state_example() {
	 *     $this->show_message(100);
	 *     $this->defer_state('next_state');
	 *     // この後の処理は実行されません
	 * }
	 */
	public function defer_state( $next_state ) {
		global $qahm_log;

		if ( $this->exit_flag ) {
			$qahm_log->warning(
				array(
					'message'    => 'defer_state() called after exit_flag was set',
					'next_state' => $next_state,
					'state'      => $this->state,
					'class'      => get_class( $this ),
				)
			);
			return;
		}

		$this->execute[] = array( 'next' => $next_state );
		$this->exit_flag = true;
	}

	public function add_command( $command_id, $command_ary ) {
		foreach ( $this->session_commands as &$command ) {
			if ( $command['id'] == $command_id ) {
				$command['commands'][] = $command_ary;
				return true;
			}
		}
		return false;
	}
	public function exists_command( $command_id, $text ) {
		foreach ( $this->session_commands as $command ) {
			if ( $command['id'] == $command_id ) {
				// $command['commands']が配列であることを想定し、textパラメータを持つか確認
				foreach ( $command['commands'] as $cmd ) {
					if ( isset( $cmd['text'] ) && $cmd['text'] == $text ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	// Method to progress story
	public function progress_story() {
		$loop_count     = 0;
		$max_iterations = 1000;

		while ( ! $this->exit_flag && $loop_count < $max_iterations ) {
			$method_name = 'handle_state_' . $this->state;

			if ( method_exists( $this, $method_name ) ) {
				try {
					$this->add_log(
						'debug',
						'State transition',
						array(
							'from'   => $this->state,
							'method' => $method_name,
						)
					);

					$next_state = $this->$method_name();
					if ( $next_state !== null ) {
						$this->state = $next_state;
					}
				} catch ( Exception $e ) {
					$this->add_log(
						'error',
						'State handler exception: ' . $e->getMessage(),
						array(
							'state'  => $this->state,
							'method' => $method_name,
							'file'   => $e->getFile(),
							'line'   => $e->getLine(),
						)
					);
					$this->exit_flag = true;
				}
			} else {
				$this->add_log( 'error', "Undefined state: {$this->state}" );
				$this->exit_flag = true;
			}

			++$loop_count;
		}

		if ( $loop_count >= $max_iterations ) {
			$this->add_log( 'error', 'Maximum iterations reached' );
		}
	}

	public function convert_browser( $browser, $detail = false ) {
		$cnv_browser = $this->wrap_trim( $browser );
		if ( empty( $cnv_browser ) ) {
			$cnv_browser = '(not set)';
		}
		if ( ! $cnv_browser ) {
			$cnv_browser = '(not set)';
		}
		if ( $cnv_browser === '/' ) {
			$cnv_browser = '(not set)';
		}

		if ( $detail === false ) {
			// スラッシュが含まれているか確認
			if ( $this->wrap_strpos( $cnv_browser, '/' ) !== false ) {
				// スラッシュで分割
				$parts = $this->wrap_explode( '/', $cnv_browser );
				// 最初の部分を取得
				$cnv_browser = $parts[0];
			}
		}
		return htmlspecialchars( $cnv_browser, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * 日付・期間
	 */
	/**
	 * @param int $num_days ・・・取得する日数　※ 1は、1日分＝同日～同日
	 */
	public function get_dateterm_using_days( $num_days = 1 ) {
		// Get the current date
		$currentDate = new DateTime();

		// Get the date of yesterday (end date)
		$endDate = $currentDate->modify( '-1 day' )->format( 'Y-m-d' );

		// Get the date of $num_days days ago (start date)
		$startDate = $currentDate->modify( '-' . ( $num_days - 1 ) . ' day' )->format( 'Y-m-d' );

		// Create the date term string
		$dateterm = 'date = between ' . $startDate . ' and ' . $endDate;

		return $dateterm;
	}
	public function get_dateterm_using_daystr( $daystr ) {
		// Create the date term string
		$dateterm = 'date = between ' . $daystr . ' and ' . $daystr;
		return $dateterm;
	}


	public function get_social_ary() {
		// まずセミコロンで各グループを分割
		$grouped_referrers = $this->wrap_explode( ';', QAHM_CONFIG_SOCIAL_REFERRER );

		$referrer_array = array();

		foreach ( $grouped_referrers as $group ) {
			// 空の要素をスキップ
			if ( $this->wrap_trim( $group ) === '' ) {
				continue;
			}
			// コロンでキーと値に分ける
			list($key, $referrers) = $this->wrap_explode( ':', $group );
			// カンマで複数のリファラーを配列に分割
			$referrer_array[ $key ] = $this->wrap_explode( ',', $referrers );
		}

		return $referrer_array;
	}



	/**
	 * @param int $num_days ・・・取得する日数
	 * @return array ・・・[0] => from_date, [1] => to_date
	 */
	public function determine_gsc_from_and_to_dates( $num_days ) {
		global $qahm_time;
		// GSCデータは3日前が最新
		$to_date   = $qahm_time->xday_str( -3, $qahm_time->today_str() );
		$from_date = $qahm_time->xday_str( -( $num_days - 1 ), $to_date );
		return array( $from_date, $to_date );
	}
	public function determine_normal_from_and_to_dates( $num_days ) {
		global $qahm_time;
		// 通常は昨日までが最新
		$to_date   = $qahm_time->xday_str( -1, $qahm_time->today_str() );
		$from_date = $qahm_time->xday_str( -( $num_days - 1 ), $to_date );
		return array( $from_date, $to_date );
	}


	/**
	 * ディレクトリ内にファイルが存在するか確認
	 * @param string $dir_name ・・・qa-zero-dataディレクトリ下のディレクトリ名
	 */
	public function check_dir_has_file( $dir_name ) {
		global $wp_filesystem;
		global $qahm_db;
		$dir_path     = $qahm_db->get_data_dir_path( $dir_name );
		$dir_contents = $wp_filesystem->dirlist( $dir_path );
		if ( empty( $dir_contents ) ) {
			return false;
		} else {
			return true;
		}
	}

	protected function add_log( $level, $message, $context = array() ) {
		if ( defined( 'QAHM_DEBUG' ) && defined( 'QAHM_DEBUG_LEVEL' ) &&
			QAHM_DEBUG === QAHM_DEBUG_LEVEL['release'] ) {
			return;
		}

		$this->debug_logs[] = array(
			'level'     => $level,
			'message'   => $message,
			'context'   => $context,
			'timestamp' => microtime( true ),
			'class'     => get_class( $this ),
			'state'     => $this->state,
			'trace'     => debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 5 ),
		);
	}

	private function resolve_translation( $key, $translations ) {
		if ( $this->wrap_strpos( $key, '.' ) !== false ) {
			list($section, $subkey) = $this->wrap_explode( '.', $key, 2 );
			if ( isset( $translations[ $section ][ $subkey ] ) ) {
				return $translations[ $section ][ $subkey ];
			}
		}
		return $key; // 翻訳が見つからない場合はキーをそのまま返す
	}
}
