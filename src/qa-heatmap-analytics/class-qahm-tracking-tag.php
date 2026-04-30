<?php
defined( 'ABSPATH' ) || exit;
/**
 * トラッキングタグ生成クラス
 * 2025/08/29現在 QA Assistantしか使っていません。
 *
 * @package qa_zero
 */

// クラスのインスタンス化
$GLOBALS['qahm_tracking_tag'] = new QAHM_Tracking_Tag();

class QAHM_Tracking_Tag extends QAHM_File_Base {

	/**
	 * コンストラクタ
	 */
	public function __construct() {
		// enqueueされたスクリプトが先に出力される（優先度20）
		add_action( 'wp_enqueue_scripts', array( $this, 'output_cookie_scripts' ) );

		// echoは後ろに出したい（優先度30）
		add_action( 'wp_head', array( $this, 'output_tracking_tag' ) );
	}

	/**
	 * クッキースクリプトを出力
	 *
	 */
	public function output_cookie_scripts() {
		if ( $this->is_bot() ) {
			return;
		}

		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return;
		}

		$cb_sup_mode = $this->wrap_get_option( 'cb_sup_mode' );
		if ( $cb_sup_mode == 'yes' ) {
			$cookie_mode = true;
		} else {
			$cookie_mode = false;
		}

		$js_dir_url = $this->get_js_dir_url();
		wp_enqueue_script( QAHM_NAME . '-polyfill-object-assign', $js_dir_url . 'polyfill/object_assign.js', null, QAHM_PLUGIN_VERSION, false );
		if ( $cookie_mode ) {
			wp_enqueue_script( QAHM_NAME . '-cookie-consent-qtag', plugin_dir_url( __FILE__ ) . 'cookie-consent-qtag.php?cookie_consent=yes', null, QAHM_PLUGIN_VERSION, false );
		}
	}

	/**
	 * トラッキングタグを出力する
	 */
	public function output_tracking_tag() {
		if ( $this->is_bot() ) {
			return;
		}

		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return;
		}

		// サイトのトラッキングIDを取得
		$sitemanage  = $this->wrap_get_option( 'sitemanage' );
		$tracking_id = $sitemanage[0]['tracking_id'];

		if ( ! empty( $tracking_id ) ) {
			// ホスト名を取得
			$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';

			// Cookie同意モードの設定を取得
			$cb_sup_mode = $this->wrap_get_option( 'cb_sup_mode', 'false' );
			$c_mode      = $cb_sup_mode === 'yes' ? 'true' : 'false';

			$qa_tag_url = $this->get_qtag_dir_url( $tracking_id ) . 'qtag.js';
			if ( ! $qa_tag_url ) {
				return;
			}

			// qtag.jsで使用する変数を準備
			$debug_mode = false;
			if ( QAHM_DEBUG >= QAHM_DEBUG_LEVEL['debug'] ) {
				$debug_mode = true;
			}
			$debug_mode_json = $this->wrap_json_encode( $debug_mode );
			$send_interval   = QAHM_CONFIG_BEHAVIORAL_SEND_INTERVAL;

			$qahm_file     = new QAHM_File_Base();
			$tracking_hash = $qahm_file->get_tracking_hash_array( $tracking_id )[0]['tracking_hash'];
			$ajax_url      = plugin_dir_url( __FILE__ ) . 'qahm-ajax.php';

			// クロスドメイン設定
			$xdm_value = '';
			if ( $host ) {
				$host_parts = $this->wrap_explode( '.', $host );
				$count      = $this->wrap_count( $host_parts );
				if ( $count >= 3 ) {
					$last_two = $host_parts[ $count - 2 ] . '.' . $host_parts[ $count - 1 ];
					$cc_tlds  = array( 'co.jp', 'or.jp', 'ne.jp', 'ac.jp', 'go.jp', 'ad.jp', 'ed.jp', 'gr.jp', 'lg.jp', 'co.uk', 'co.kr', 'co.nz', 'com.au', 'com.br', 'com.cn', 'com.hk', 'com.sg', 'com.tw' );
					if ( in_array( $last_two, $cc_tlds, true ) ) {
						$xdm_value = $host_parts[ $count - 3 ] . '.' . $last_two;
					} else {
						$xdm_value = $last_two;
					}
				} else {
					$xdm_value = $host;
				}
			}

			?>  
		<script>  
		var qahmz  = qahmz || {};  
		qahmz.initDate   = new Date();  
		qahmz.domloaded = false;  
		document.addEventListener("DOMContentLoaded",function() {  
			qahmz.domloaded = true;  
		});  
		qahmz.xdm        = "<?php echo esc_js( $xdm_value ); ?>";  
		qahmz.cookieMode = <?php echo esc_js( $c_mode ); ?>;  
		qahmz.debug = 
			<?php
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe: $this->wrap_json_encode() outputs valid JS literal (true/false)
						echo $debug_mode_json;
			?>
		;  
		qahmz.tracking_id = "<?php echo esc_js( $tracking_id ); ?>";  
		qahmz.send_interval = 
			<?php
                                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Safe: integer value
								echo $send_interval;
			?>
		;  
		qahmz.ajaxurl = "<?php echo esc_js( $ajax_url ); ?>";  
		qahmz.tracking_hash = "<?php echo esc_js( $tracking_hash ); ?>";  
		</script>
			<?php
			// Plugin Check exclusion: Outputs inline tracking tag intentionally (cannot use wp_enqueue_script() for external embedding)
        // phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript
			?>
					<script src="<?php echo esc_url( $qa_tag_url ); ?>" async></script>  
			<?php
        // phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript 
		}
	}
}
