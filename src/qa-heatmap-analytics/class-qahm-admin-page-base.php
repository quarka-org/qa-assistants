<?php
defined( 'ABSPATH' ) || exit;
/**
 * 管理画面のページを表示するクラスの基本クラス
 *
 * @package qa_heatmap
 */

class QAHM_Admin_Page_Base extends QAHM_File_Data {
	public $hook_suffix;

	function __construct() {
	}

	/**
	 * 共通部分のスタイル
	 */
	protected function common_enqueue_style() {
		$css_dir_url = $this->get_css_dir_url();
		wp_enqueue_style( QAHM_NAME . '-sweet-alert-2', $css_dir_url . '/lib/sweet-alert-2/sweetalert2.min.css', null, QAHM_PLUGIN_VERSION );
		wp_enqueue_style( QAHM_NAME . '-reset', $css_dir_url . 'reset.css', array( QAHM_NAME . '-sweet-alert-2' ), QAHM_PLUGIN_VERSION );
		wp_enqueue_style( QAHM_NAME . '-qa-table', $css_dir_url . '/lib/qa-table/qa-table.css', array( QAHM_NAME . '-reset' ), QAHM_PLUGIN_VERSION );
		wp_enqueue_style( QAHM_NAME . '-common', $css_dir_url . 'common.css', array( QAHM_NAME . '-reset' ), QAHM_PLUGIN_VERSION );
		// Differs between ZERO and QA - Start ----------
		// 環境によるCSSの読み込み
		if ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
			wp_enqueue_style( QAHM_NAME . '-qa-table-custom', $css_dir_url . 'qa-table-custom-zero.css', array( QAHM_NAME . '-qa-table' ), QAHM_PLUGIN_VERSION );
			wp_enqueue_style( QAHM_NAME . '-admin-page-common', $css_dir_url . 'admin-page-common-zero.css', array( QAHM_NAME . '-reset' ), QAHM_PLUGIN_VERSION );
		} elseif ( QAHM_TYPE === QAHM_TYPE_WP ) {
			wp_enqueue_style( QAHM_NAME . '-qa-table-custom', $css_dir_url . 'qa-table-custom-wp.css', array( QAHM_NAME . '-qa-table' ), QAHM_PLUGIN_VERSION );
			wp_enqueue_style( QAHM_NAME . '-admin-page-common', $css_dir_url . 'admin-page-common-wp.css', array( QAHM_NAME . '-reset' ), QAHM_PLUGIN_VERSION );
		}
		// Differs between ZERO and QA - End ----------
	}

	/**
	 * 共通部分のスクリプト
	 */
	protected function common_enqueue_script() {
		$js_dir_url = $this->get_js_dir_url();
		wp_enqueue_script( 'jquery' );
		wp_enqueue_script( QAHM_NAME . '-qa-table', $js_dir_url . 'lib/qa-table/qa-table.js', null, QAHM_PLUGIN_VERSION );
		wp_enqueue_script( QAHM_NAME . '-font-awesome', $js_dir_url . 'lib/font-awesome/all.min.js', null, QAHM_PLUGIN_VERSION );
		wp_enqueue_script( QAHM_NAME . '-sweet-alert-2', $js_dir_url . 'lib/sweet-alert-2/sweetalert2.min.js', array( 'jquery' ), QAHM_PLUGIN_VERSION );
		wp_enqueue_script( QAHM_NAME . '-alert-message', $js_dir_url . 'alert-message.js', array( QAHM_NAME . '-sweet-alert-2' ), QAHM_PLUGIN_VERSION );
		wp_enqueue_script( QAHM_NAME . '-common', $js_dir_url . 'common.js', array( 'jquery', QAHM_NAME . '-qa-table' ), QAHM_PLUGIN_VERSION );
		wp_enqueue_script( QAHM_NAME . '-load-screen', $js_dir_url . 'load-screen.js', array( QAHM_NAME . '-common' ), QAHM_PLUGIN_VERSION );
		wp_enqueue_script( QAHM_NAME . '-effect', $js_dir_url . 'effect.js', array( QAHM_NAME . '-load-screen' ), QAHM_PLUGIN_VERSION );

		// 日付範囲アダプタ（Cally）の登録のみ（画面への enqueue は各画面の移行 Phase で実施）。
		// Cally は ESM のみ配布のため IIFE 化して同梱。コア1箇所で登録し、衛星プラグインは
		// 同ハンドルを enqueue するだけで WP の重複排除が効く（customElements.define 二重定義回避）。
		$css_dir_url = $this->get_css_dir_url();
		wp_register_script( QAHM_NAME . '-cally', $js_dir_url . 'lib/cally/cally.iife.js', null, QAHM_PLUGIN_VERSION, true );
		wp_register_script( QAHM_NAME . '-daterange', $js_dir_url . 'qahm-daterange.js', array( QAHM_NAME . '-cally' ), QAHM_PLUGIN_VERSION, true );
		wp_register_style( QAHM_NAME . '-daterange', $css_dir_url . 'qahm-daterange.css', null, QAHM_PLUGIN_VERSION );
	}

	/**
	 * 共通部分のインラインスクリプト
	 */
	protected function get_common_inline_script() {
		$dev001 = defined( 'QAZR_DEV001' ) ? true : false;
		$dev002 = defined( 'QAZR_DEV002' ) ? true : false;
		$dev003 = defined( 'QAZR_DEV003' ) ? true : false;
		global $qahm_time;
		$timezone = $qahm_time->timezone_obj->getName();

		$scripts = array(
			'nonce_api'      => wp_create_nonce( QAHM_Data_Api::NONCE_API ),
			'ajax_url'       => admin_url( 'admin-ajax.php' ),
			'debug_level'    => QAHM_DEBUG_LEVEL,
			'debug'          => QAHM_DEBUG,
			'type'           => QAHM_TYPE,
			'type_zero'      => QAHM_TYPE_ZERO,
			'type_wp'        => QAHM_TYPE_WP,
			//'license_plan'         => $this->wrap_get_option( 'license_plan' ),
			//'license_plans'        => $this->get_plan(),
			'site_url'       => get_site_url(),
			'plugin_dir_url' => plugin_dir_url( __FILE__ ),
			'plugin_version' => QAHM_PLUGIN_VERSION,
			'data_dir_url'   => $this->get_data_dir_url(),
			'devices'        => QAHM_DEVICES,
			'dev001'         => $dev001,
			'dev002'         => $dev002,
			'dev003'         => $dev003,
			// common variables
			'wp_gmt_offset'  => get_option( 'gmt_offset' ),
			'wp_timezone'    => $timezone,
			'wp_lang'        => get_option( 'WPLANG' ),
			'wp_user_locale' => get_user_locale(),
			'locale_for_js'  => str_replace( '_', '-', $this->normalize_locale( get_locale() ) ),
		);

		return $scripts;
	}

	/**
	 * JS用 localization(l10n) テキスト（admin-page共通）
	 */
	protected function get_common_localize_script() {
		$localize = array(
		//  'test' => esc_html__( 'ここに共通ローカライズ単語を書いていく。このメッセージは翻訳不要', 'qa-heatmap-analytics' ),
		);

		return $localize;
	}

	/**
	 * 簡略な言語コード（例: ja, en）を完全なロケール（例: ja_JP, en_US）に変換する
	 *
	 * @param string $locale get_locale() や determine_locale() の返り値
	 * @return string 完全ロケール（未定義ならそのまま返す）
	 */
	protected function normalize_locale( $locale ) {
		$locale_map = array(
			// 日本語
			'ja'      => 'ja_JP',

			// 英語（アメリカ）
			'en'      => 'en_US',

			// 英語（イギリス）など追加も可能
			'en_GB'   => 'en_GB',

			// 中国語
			'zh'      => 'zh_CN',
			'zh-hans' => 'zh_CN', // 簡体字
			'zh-hant' => 'zh_TW', // 繁体字

			// フランス語
			'fr'      => 'fr_FR',

			// ドイツ語
			'de'      => 'de_DE',

			// スペイン語
			'es'      => 'es_ES',

			// 韓国語
			'ko'      => 'ko_KR',

			// イタリア語
			'it'      => 'it_IT',

			// ロシア語
			'ru'      => 'ru_RU',

			// ポルトガル語
			'pt'      => 'pt_PT',
			'pt-br'   => 'pt_BR',

			// その他必要に応じて追加
		);

		return $locale_map[ strtolower( $locale ) ] ?? $locale;
	}

	/**
	 * jQueryのキューイングチェック
	 */
	protected function is_enqueue_jquery() {
		if ( wp_script_is( 'jquery' ) ) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * jQueryが存在しない時のメッセージを表示
	 */
	protected function view_not_enqueue_jquery_html() {
		$msg  = '<div id="qahm-error" class="error notice is-dismissible"><p>';
		$msg .= sprintf(
			/* translators: %s is the plugin name */
			esc_html__( 'jQuery is not loaded, so %s cannot function properly.', 'qa-heatmap-analytics' ),
			QAHM_PLUGIN_NAME
		);
		$msg .= '<br>';
		$msg .= esc_html__( 'Please use "wp_enqueue_script" to load jQuery.', 'qa-heatmap-analytics' );
		$msg .= '</p></div>';
		echo wp_kses_post( $msg );
	}

	/**
	 * jQueryが存在しない時のメッセージを出力
	 */
	protected function print_not_enqueue_jquery_html() {
		$this->view_not_enqueue_jquery_html();
	}

	/**
	 * メンテナンス表示
	 */
	protected function view_maintenance_html() {
		$style = '<style>' .
				'.mainteqa {' .
				'width: 800px;' .
				'background-color: #fcfcfc;' .
				'padding: 24px;' .
				'}' .
				'.mainteqa h1 {' .
				'border-bottom: solid 2px #f9cdc5;' .
				'margin-bottom: 32px;' .
				'font-size: 1.2rem;' .
				'line-height: 2;' .
				'}' .
				'.mainteqa p {' .
				'line-height: 1.8;' .
				'margin-bottom: 1em;' .
				'}' .
				'</style>';

		$mes = '<div class="mainteqa">';

		if ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
			// ZERO ではバージョン更新に伴うデータ更新処理として提示する（日本語運用のみ）。
			$mes .= '<h1>更新処理中です</h1>';
			$mes .= '<p>現在、バージョン更新に伴う処理を行っています。データ量によっては、完了までに時間がかかる場合があります。</p>';
			$mes .= '<p>しばらく時間をおいてから、ページを再読み込みしてください。</p>';
			$mes .= '<p>長時間この画面が表示される場合は、サポートまでお問い合わせください。</p>';
		} elseif ( QAHM_TYPE === QAHM_TYPE_WP ) {
			$mes .= '<h1>' . esc_html__( 'Maintenance Notice', 'qa-heatmap-analytics' ) . '</h1>';
			$mes .= '<p>' . esc_html__( 'Your data is currently undergoing maintenance. This process may take a few minutes to complete.', 'qa-heatmap-analytics' ) . '</p>';
			$mes .= '<p>' . esc_html__( 'After updating the plugin, changes may take a few minutes to apply. Reloading the page afterward is recommended.', 'qa-heatmap-analytics' ) . '</p>';
			$mes .= '<p>' . sprintf(
				/* translators: %1$s and %2$s are anchor tags for the troubleshooting page */
				esc_html__( 'If this notice continues to appear for an extended period, please refer to our %1$sTroubleshooting page%2$s.', 'qa-heatmap-analytics' ),
				'<a href="https://mem.quarka.org/en/manual/keep-getting-data-is-under-maintenance/" target="_blank" rel="noopener">',
				'</a>'
			) . '</p>';

			$mes   .= '<hr>';
			$locale = get_locale();
			if ( $this->wrap_strpos( $locale, 'ja' ) === 0 ) {
				$mes .= '<p><strong>QA Analytics から更新された方へ</strong><br>';
				$mes .= 'これまでの計測データは、QA Assistants で利用できるよう引き継ぎ準備中です。<br>';
				$mes .= 'しばらくすると通常の画面に戻りますが、夜間処理が完了するまでレポートは「データがありません」と表示されます。<br>';
				$mes .= '計測は通常どおり継続しています。明日の反映を楽しみにお待ちください。</p>';
			} else {
				$mes .= '<p><strong>' . esc_html__( 'For users updating from QA Analytics', 'qa-heatmap-analytics' ) . '</strong><br>';
				$mes .= esc_html__( 'Your past analytics data is being carried over and prepared for use in QA Assistants.', 'qa-heatmap-analytics' ) . '<br>';
				$mes .= esc_html__( 'The normal screen will return shortly, but reports will show "No data available" until the nightly process is finished.', 'qa-heatmap-analytics' ) . '<br>';
				$mes .= esc_html__( 'Tracking continues as usual, so please look forward to seeing your data reflected tomorrow.', 'qa-heatmap-analytics' ) . '</p>';
			}
		}

		$mes .= '</div>';

		echo wp_kses( $style, array( 'style' => array() ) );
		echo wp_kses_post( $mes );
	}

	/**
	 * メンテナンス表示を出力
	 */
	protected function print_maintenance_html() {
		$this->view_maintenance_html();
	}

	/**
	 * tracking_id選択を促すメッセージを表示
	 *
	 * @param string $page_title ページタイトル
	 */
	protected function print_select_tracking_id_message( $page_title = '' ) {
		$style = '<style>' .
				'.qa-select-tracking-id {' .
				'width: 800px;' .
				'background-color: #fcfcfc;' .
				'padding: 24px;' .
				'margin: 20px 0;' .
				'}' .
				'.qa-select-tracking-id h2 {' .
				'border-bottom: solid 2px #f9cdc5;' .
				'margin-bottom: 32px;' .
				'font-size: 1.2rem;' .
				'line-height: 2;' .
				'}' .
				'.qa-select-tracking-id p {' .
				'line-height: 1.8;' .
				'margin-bottom: 1em;' .
				'}' .
				'</style>';

		$mes  = '<div class="qa-select-tracking-id">';
		$mes .= '<h2>' . esc_html( $page_title ) . '</h2>';
		$mes .= '<p>' . esc_html__( 'This page does not support viewing all sites at once.', 'qa-heatmap-analytics' ) . '</p>';
		$mes .= '<p>' . esc_html__( 'Please select a specific site from the menu above.', 'qa-heatmap-analytics' ) . '</p>';
		$mes .= '</div>';

		echo wp_kses( $style, array( 'style' => array() ) );
		echo wp_kses_post( $mes );
	}


	/**
	 * ヘッダーを展開
	 *
	 * @since #925 Base に移動（dataviewer / assistant のオーバーライドを統合）
	 *
	 * @param string $title         ページタイトル
	 * @param bool   $show_selector サイトセレクターを表示するか
	 */
	protected function create_header( $title, $show_selector = true ) {
		?>
		<div class="qa-zero-header">
			<h1 class="qa-zero-header__title"><?php echo esc_html( $title ); ?></h1>
			<?php
			if ( $show_selector ) {
				$this->render_site_selector();
			}
			?>
		</div>
		<?php
	}

	/**
	 * サイトセレクターを表示（QA ZERO のみ）
	 *
	 * ページヘッダー内（タイトル横）にサイト切替セレクトボックスを表示する。
	 * 登録サイトが複数あるときだけ <select>（切替可）を出す。1つだけのときは
	 * 切替先が無いため <span> でドメイン名を静的表示する（ドロップダウンにしない）。
	 * QA Assistants では何も出力しない。
	 *
	 * @since #901 Step 2
	 * @since #914 ヘッダー内統合、ラベル削除
	 * @since #1100 横幅を可変化（長いドメインで矢印が文字と被るのを解消）、1サイト時は静的表示
	 */
	protected function render_site_selector() {
		if ( QAHM_TYPE !== QAHM_TYPE_ZERO ) {
			return;
		}

		// 複数回呼び出し防止
		static $rendered = false;
		if ( $rendered ) {
			return;
		}
		$rendered = true;

		global $qahm_data_api;
		$sitemanage = $qahm_data_api->get_sitemanage();

		if ( empty( $sitemanage ) ) {
			return;
		}

		// 現在の tracking_id を取得
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$current_tracking_id = isset( $_GET['tracking_id'] )
			? $this->sanitize_tracking_id( wp_unslash( $_GET['tracking_id'] ) )
			: '';

		// status=255（削除済み）のサイトを除外
		$active_sites = array();
		foreach ( $sitemanage as $site ) {
			if ( 255 === (int) $site['status'] ) {
				continue;
			}
			$active_sites[] = $site;
		}

		$site_count = count( $active_sites );

		if ( 0 === $site_count ) {
			return;
		}
		?>
		<?php if ( $site_count <= 1 ) : ?>
			<?php // 切替先が無い1サイト時はドロップダウンにせず、ドメインを静的表示する。 ?>
			<span class="qa-zero-header__site-name"><?php echo esc_html( $active_sites[0]['url'] ); ?></span>
		<?php else : ?>
			<select class="qa-zero-header__site-select" id="qa-zero-site-select">
				<option value="all"<?php selected( $current_tracking_id, 'all' ); ?>>
					<?php esc_html_e( 'すべてのサイト', 'qa-heatmap-analytics' ); ?>
				</option>
				<?php foreach ( $active_sites as $site ) : ?>
					<option value="<?php echo esc_attr( $site['tracking_id'] ); ?>"<?php selected( $current_tracking_id, $site['tracking_id'] ); ?>>
						<?php echo esc_html( $site['url'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<script>
				document.getElementById( 'qa-zero-site-select' ).addEventListener( 'change', function() {
					var url = new URL( window.location.href );
					url.searchParams.set( 'tracking_id', this.value );
					window.location.href = url.toString();
				} );
			</script>
		<?php endif; ?>
		<?php
	}

	/**
	 * RSSを表示
	 */
	protected function view_rss_feed( $wrap_in_container = true ) {
		$wp_lang_set = get_bloginfo( 'language' );
		$is_japanese = $this->wrap_strpos( $wp_lang_set, 'ja' ) === 0;

		// 日本語以外の表示の段階導入フラグ。
		// 英語リリース告知の運用が追いつかない場合は false にすると
		// 日本語以外の環境で従来どおり非表示に戻せる。
		$enable_en_locale = true;

		// 日本語以外は、QA Assistants かつフラグ ON のときだけ表示
		// （ZERO は常に ja 限定）
		if ( ! $is_japanese && ( ! $enable_en_locale || QAHM_TYPE !== QAHM_TYPE_WP ) ) {
			return;
		}

		include_once ABSPATH . WPINC . '/feed.php';

		// プラグイン種別・ロケールに応じて、表示する RSS グループを構築する。
		// 1 グループ = 1 つのフィード（URL + 取得件数 + タグ絞込 + 見出し）。
		if ( QAHM_TYPE === QAHM_TYPE_ZERO ) {
			$heading_text = __( 'QA ZERO ブログ', 'qa-heatmap-analytics' );
			$feed_groups  = array(
				array(
					// ZERO は 1 グループのみ運用のため、
					// 上部の $heading_text と重複させないよう、
					// グループ見出しは空にして出力を抑制する。
					'title'       => '',
					'url'         => 'https://qazero.com/blog/feed/',
					'post_number' => 5,
					'filter_tag'  => null,
				),
			);
		} elseif ( QAHM_TYPE === QAHM_TYPE_WP ) {
			$heading_text = __( "What's New", 'qa-heatmap-analytics' );

			// Release Notes: ドキュメントサイトのリリース告知。
			// - 日本語: 1 件（下に quarka.org のブログ 3 件が並ぶ）
			// - 英語 : 3 件（英語では Blog グループを出さないため、単独で 3 件表示）
			$feed_groups = array(
				array(
					'title'       => __( 'Release Notes', 'qa-heatmap-analytics' ),
					'url'         => $is_japanese
						? 'https://docs.quarka.org/ja/blog/rss.xml'
						: 'https://docs.quarka.org/blog/rss.xml',
					'post_number' => $is_japanese ? 1 : 3,
					'filter_tag'  => 'release',
				),
			);

			// Blog: 公式サイト quarka.org の plugin-rss タグ付き記事。
			// 現時点では日本語環境のみ表示する（英語ブログは未運用のため）。
			if ( $is_japanese ) {
				$feed_groups[] = array(
					'title'       => __( 'Blog', 'qa-heatmap-analytics' ),
					'url'         => 'https://quarka.org/tag/plugin-rss/feed/',
					'post_number' => 3,
					'filter_tag'  => null,
				);
			}
		} else {
			return;
		}

		// 各グループの記事を取得。
		// 片方のフィード取得に失敗しても、もう片方は表示できる構造。
		// タグ未作成などで 0 件になったグループは、そのまま除外する。
		$rss_groups = array();
		foreach ( $feed_groups as $feed_group ) {
			$items = $this->get_rss_items(
				$feed_group['url'],
				$feed_group['post_number'],
				$feed_group['filter_tag']
			);
			if ( empty( $items ) ) {
				continue;
			}
			$rss_groups[] = array(
				'title' => $feed_group['title'],
				'items' => $items,
			);
		}

		if ( empty( $rss_groups ) ) {
			return;
		}

		$date_format = $is_japanese ? 'Y年n月j日' : 'M j, Y';
		?>

		<?php if ( $wrap_in_container ) : ?>
		<div id="qa-zero-rss" class="qa-zero-data-container">
			<div class="qa-zero-data">
				<div class="qa-zero-data__title">
					<svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" viewBox="0 0 25 24" fill="none">
						<mask id="mask0_rss" style="mask-type:luminance" maskUnits="userSpaceOnUse" x="3" y="3" width="19" height="18">
							<path fill-rule="evenodd" clip-rule="evenodd" d="M18.1589 3C17.9101 3 17.6514 3.1 17.4623 3.29L15.6413 5.12L19.3729 8.87L21.1939 7.04C21.582 6.65 21.582 6.02 21.1939 5.63L18.8654 3.29C18.6664 3.09 18.4176 3 18.1589 3ZM14.5766 9.02L15.492 9.94L6.47648 19H5.56099V18.08L14.5766 9.02ZM3.5708 17.25L14.5766 6.19L18.3082 9.94L7.30241 21H3.5708V17.25Z" fill="white"/>
						</mask>
						<g mask="url(#mask0_rss)">
							<rect x="0.586914" width="23.8823" height="24"/>
						</g>
					</svg>
					<?php echo esc_html( $heading_text ); ?>
				</div>
		<?php else : ?>
				<h3 class="qahm-help__rss-title"><?php echo esc_html( $heading_text ); ?></h3>
		<?php endif; ?>

				<div class="rss-widget">
					<?php foreach ( $rss_groups as $rss_group ) : ?>
						<div class="qa-zero-data__rss-group">
							<?php if ( '' !== $rss_group['title'] ) : ?>
								<h4 class="qa-zero-data__rss-group-title"><?php echo esc_html( $rss_group['title'] ); ?></h4>
							<?php endif; ?>
							<ul>
								<?php foreach ( $rss_group['items'] as $item ) : ?>
									<li>
										<span class="qa-zero-data__rss-date">
											<?php echo esc_html( $item->get_date( $date_format ) ); ?>
										</span>
										<a href="<?php echo esc_url( $item->get_permalink() ); ?>" target="_blank" class="rsswidget" rel="noopener">
											<?php echo esc_html( $item->get_title() ); ?>
										</a>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endforeach; ?>
				</div>
		<?php if ( $wrap_in_container ) : ?>
			</div>
		</div>
		<?php endif; ?>

		<?php
	}

	/**
	 * RSSを出力
	 */
	protected function print_rss_feed() {
		$this->view_rss_feed();
	}

	/**
	 * 指定 RSS から記事を取得する。
	 *
	 * フィード取得エラー、記事 0 件、タグ絞り込みで該当 0 件のいずれも空配列を返す。
	 * タグ未作成のフィード URL を渡した場合でも呼び出し側は例外・警告なく扱える。
	 *
	 * @param string      $rss_url     フィード URL。
	 * @param int         $post_number 最大取得件数。
	 * @param string|null $filter_tag  カテゴリ（タグ）で絞り込む場合の term。null なら絞り込みなし。
	 * @return array 取得できた記事の配列。取得できなかった場合は空配列。
	 */
	protected function get_rss_items( $rss_url, $post_number, $filter_tag = null ) {
		$rss = fetch_feed( $rss_url );
		if ( is_wp_error( $rss ) ) {
			return array();
		}

		if ( null === $filter_tag ) {
			$maxitems = $rss->get_item_quantity( $post_number );
			if ( empty( $maxitems ) || $maxitems <= 0 ) {
				return array();
			}
			return $rss->get_items( 0, $maxitems );
		}

		$all_items = $rss->get_items( 0, $rss->get_item_quantity() );
		$rss_items = array();
		foreach ( $all_items as $item ) {
			$cats = $item->get_categories();
			if ( ! $cats ) {
				continue;
			}
			foreach ( $cats as $cat ) {
				if ( $filter_tag === $cat->get_term() ) {
					$rss_items[] = $item;
					break;
				}
			}
			if ( count( $rss_items ) >= $post_number ) {
				break;
			}
		}

		return $rss_items;
	}

	/**
	 * フッターにXフォローボタンを表示（QA Assistantsのみ）
	 */
	protected function create_footer_follow() {
		// Specific to QA - Start ---------------
		if ( QAHM_TYPE !== QAHM_TYPE_WP ) {
			return;
		}
		// Specific to QA - End ---------------

		$locale      = get_locale();
		$is_japanese = $this->wrap_strpos( $locale, 'ja' ) === 0;

		$message   = __( 'Get the latest updates on QA Assistants on X (@QAAssistantsEN)', 'qa-heatmap-analytics' ) . ' - ';
		$link_text = __( 'Follow us for updates', 'qa-heatmap-analytics' );

		if ( $is_japanese ) {
			$link = 'https://x.com/QAAssistants';
		} else {
			$link = 'https://x.com/QAAssistantsEN';
		}

		$footer_html = '<div style="' .
			'margin-top:36px;' .
			'margin-bottom:48px;' .
			'padding:14px 18px;' .
			'border-top:1px solid #eaf5ff;' .
			'border-bottom:1px solid #eaf5ff;' .
			'background:#ffffff;' .
			'font-size:13px;' .
			'line-height:1.5;' .
			'color:#1d1d1d;' .
			'">' .
			esc_html( $message ) .
			'<a href="' . esc_url( $link ) . '"' . // $link変数を適用
			' target="_blank"' .
			' rel="noopener noreferrer"' .
			' style="font-weight:600; color:#1d9bf0; text-decoration:none; margin-left:2px;">' .
			esc_html( $link_text ) .
			'</a>' .
			'</div>';

		echo wp_kses_post( $footer_html );
	}
	protected function get_x_link() {

		if ( QAHM_TYPE !== QAHM_TYPE_WP ) {
			return;
		}

		$locale      = get_locale();
		$is_japanese = $this->wrap_strpos( $locale, 'ja' ) === 0;

		if ( $is_japanese ) {
			$link = 'https://x.com/QAAssistants';
		} else {
			$link = 'https://x.com/QAAssistantsEN';
		}

		return $link;

	}
} // end of class
