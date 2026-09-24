<?php
/**
 * QAHM Page Type Detector
 *
 * HTMLとURLからページタイプをビットフラグで判定するクラス。
 * 設計書: docs/qal/12-page-type-detection.md
 *
 * @package qa_heatmap
 */
class QAHM_Page_Type_Detector extends QAHM_WP_Base {

	// --- ビットフラグ定数 ---
	const TYPE_ARTICLE    = 1;       // 2^0
	const TYPE_PRODUCT    = 2;       // 2^1
	const TYPE_LIST       = 4;       // 2^2
	const TYPE_FORM       = 8;       // 2^3
	const TYPE_TRUST_INFO = 16;      // 2^4
	const TYPE_FAQ        = 32;      // 2^5
	const TYPE_LANDING    = 64;      // 2^6
	const TYPE_SEARCH     = 128;     // 2^7
	const TYPE_ACCOUNT    = 256;     // 2^8
	const TYPE_CART       = 512;     // 2^9
	const TYPE_CHECKOUT   = 1024;    // 2^10
	const TYPE_CONFIRM    = 2048;    // 2^11
	const TYPE_THANKS     = 4096;    // 2^12
	const TYPE_TOP_PAGE   = 8192;    // 2^13
	const TYPE_EVENT      = 16384;   // 2^14
	const TYPE_RECIPE     = 32768;   // 2^15
	const TYPE_JOB        = 65536;   // 2^16
	const TYPE_VIDEO      = 131072;  // 2^17
	const TYPE_HOWTO      = 262144;  // 2^18
	const TYPE_QA_FORUM   = 524288;  // 2^19

	// --- JSON-LD @type → ビットフラグ マッピング ---
	const STRUCTURED_TYPE_MAP = array(
		'Article'                => self::TYPE_ARTICLE,
		'BlogPosting'            => self::TYPE_ARTICLE,
		'NewsArticle'            => self::TYPE_ARTICLE,
		'Product'                => self::TYPE_PRODUCT,
		'CollectionPage'         => self::TYPE_LIST,
		'ItemList'               => self::TYPE_LIST,
		'AboutPage'              => self::TYPE_TRUST_INFO,
		'ContactPage'            => self::TYPE_TRUST_INFO,
		'FAQPage'                => self::TYPE_FAQ,
		// 'WebSite' は除外: Yoast/RankMath等が全ページの@graphに含めるため誤検知する。URL判定で十分
		'Event'                  => self::TYPE_EVENT,
		'Recipe'                 => self::TYPE_RECIPE,
		'JobPosting'             => self::TYPE_JOB,
		'VideoObject'            => self::TYPE_VIDEO,
		'HowTo'                  => self::TYPE_HOWTO,
		'QAPage'                 => self::TYPE_QA_FORUM,
		'DiscussionForumPosting' => self::TYPE_QA_FORUM,
	);

	// --- キーワード辞書定義（英語がソースキー、__()で翻訳） ---
	const KEYWORD_DEFS = array(
		'article_date'     => array( 'Posted date', 'Published date' ),
		'currency_symbol'  => array( '¥', '€', '£', '₩' ),
		'add_to_cart'      => array( 'Add to cart' ),
		'thanks_phrase'    => array( 'Thank you for your', 'Thank you for' ),
		'list_title'       => array( 'List', 'Category' ),
		'trust_info_title' => array( 'About us', 'Privacy policy', 'Terms of service' ),
		'faq_title'        => array( 'Frequently asked questions', 'Frequently asked questions (polite)', 'FAQ' ),
		'account_title'    => array( 'My page', 'My account' ),
		'checkout_title'   => array( 'Payment', 'Checkout' ),
		'confirm_title'    => array( 'Confirmation', 'Confirm' ),
	);

	/**
	 * ロード済みキーワード辞書のキャッシュ
	 * @var array|null
	 */
	private $keywords = null;

	/**
	 * HTMLとURLからページタイプを判定する（純粋関数・副作用なし）
	 *
	 * @param string $html 取得済みHTML
	 * @param string $url  ページURL
	 * @return int ビットフラグ（0=該当なし）
	 */
	public function detect( $html, $url ) {
		$type = 0;
		$keywords = $this->load_keywords();
		$title = $this->extract_tag_content( $html, 'title' );

		// --- 1. JSON-LD（全ブロック走査） ---
		$offset = 0;
		while ( ( $pos = strpos( $html, 'application/ld+json', $offset ) ) !== false ) {
			$json_str = $this->extract_script_content( $html, $pos );
			if ( $json_str !== false ) {
				$data = json_decode( $json_str, true );
				if ( $data !== null ) {
					$types = $this->get_at_types( $data );
					foreach ( $types as $t ) {
						if ( isset( self::STRUCTURED_TYPE_MAP[ $t ] ) ) {
							$type |= self::STRUCTURED_TYPE_MAP[ $t ];
						}
					}
				}
			}
			$offset = $pos + 1;
		}

		// --- 2. HTML文字列ベース（1で未確定のタイプのみ） ---
		$has_form = ( strpos( $html, '<form' ) !== false );

		// body本文の文字数（LIST/LANDING判定で共用）
		$body_text_len = null;
		$body_start = strpos( $html, '<body' );
		if ( $body_start !== false ) {
			$body_text_len = mb_strlen( wp_strip_all_tags( substr( $html, $body_start ) ) );
		}

		// TYPE_ARTICLE: 「投稿日」「公開日」「datePublished」 + <article タグ AND条件
		if ( ! ( $type & self::TYPE_ARTICLE ) ) {
			if ( strpos( $html, '<article' ) !== false ) {
				if ( $this->has_keyword( $html, 'article_date' )
					|| strpos( $html, 'datePublished' ) !== false ) {
					$type |= self::TYPE_ARTICLE;
				}
			}
		}

		// TYPE_PRODUCT: 通貨記号 + EC操作フレーズ
		if ( ! ( $type & self::TYPE_PRODUCT ) ) {
			if ( $this->has_keyword( $html, 'currency_symbol' ) ) {
				if ( $this->has_keyword( $html, 'add_to_cart' )
					|| strpos( $html, 'add-to-cart' ) !== false
					|| strpos( $html, 'addtocart' ) !== false ) {
					$type |= self::TYPE_PRODUCT;
				}
			}
		}

		// TYPE_LIST: <title>内に「一覧」「カテゴリ」 + 本文が薄い（2000文字未満）
		if ( ! ( $type & self::TYPE_LIST ) ) {
			if ( $title !== false && $this->has_keyword( $title, 'list_title' ) ) {
				if ( $body_text_len !== null && $body_text_len < 2000 ) {
					$type |= self::TYPE_LIST;
				}
			}
		}

		// TYPE_FORM: <form + (<textarea / <select / type="email" / type="tel")
		if ( $has_form ) {
			if ( strpos( $html, '<textarea' ) !== false
				|| strpos( $html, '<select' ) !== false
				|| strpos( $html, 'type="email"' ) !== false
				|| strpos( $html, 'type="tel"' ) !== false ) {
				$type |= self::TYPE_FORM;
			}
		}

		// TYPE_TRUST_INFO: <title>内に「会社概要」「プライバシーポリシー」「利用規約」
		if ( ! ( $type & self::TYPE_TRUST_INFO ) ) {
			if ( $title !== false && $this->has_keyword( $title, 'trust_info_title' ) ) {
				$type |= self::TYPE_TRUST_INFO;
			}
		}

		// TYPE_FAQ: <title>内に「よくある質問」「FAQ」
		if ( ! ( $type & self::TYPE_FAQ ) ) {
			if ( $title !== false && $this->has_keyword( $title, 'faq_title' ) ) {
				$type |= self::TYPE_FAQ;
			}
		}

		// TYPE_SEARCH: URL判定のみ（HTML判定廃止）
		$parsed_url = wp_parse_url( $url );
		$url_path  = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';
		$url_query = isset( $parsed_url['query'] ) ? $parsed_url['query'] : '';

		if ( strpos( $url_query, 's=' ) === 0
			|| strpos( $url_query, '&s=' ) !== false
			|| strpos( $url_query, 'q=' ) === 0
			|| strpos( $url_query, '&q=' ) !== false
			|| strpos( $url_path, '/search' ) !== false
			|| strpos( $url_query, 'search=' ) !== false ) {
			$type |= self::TYPE_SEARCH;
		}

		// TYPE_ACCOUNT: URLパスに /my-account /mypage or <title>内に「マイページ」
		if ( strpos( $url_path, '/my-account' ) !== false
			|| strpos( $url_path, '/mypage' ) !== false ) {
			$type |= self::TYPE_ACCOUNT;
		} elseif ( $title !== false && $this->has_keyword( $title, 'account_title' ) ) {
			$type |= self::TYPE_ACCOUNT;
		}

		// TYPE_CART: URLパス判定のみ（HTML判定廃止）
		if ( strpos( $url_path, '/cart' ) !== false
			|| strpos( $url_path, '/basket' ) !== false ) {
			$type |= self::TYPE_CART;
		}

		// TYPE_CHECKOUT: URLパスに /checkout or <title>内に「決済」「お支払い」
		if ( strpos( $url_path, '/checkout' ) !== false ) {
			$type |= self::TYPE_CHECKOUT;
		} elseif ( $title !== false && $this->has_keyword( $title, 'checkout_title' ) ) {
			$type |= self::TYPE_CHECKOUT;
		}

		// TYPE_CONFIRM: <title>内に「確認」or URLに confirm
		if ( $title !== false && $this->has_keyword( $title, 'confirm_title' ) ) {
			$type |= self::TYPE_CONFIRM;
		} elseif ( strpos( $url_path, 'confirm' ) !== false ) {
			$type |= self::TYPE_CONFIRM;
		}

		// TYPE_THANKS: お礼フレーズ + (本文短い OR URLにthank含む)
		// Q&Aサイト等の長いページでお礼コメントがある場合の誤検知を防止
		if ( $this->has_keyword( $html, 'thanks_phrase' ) ) {
			$is_short_page   = ( $body_text_len !== null && $body_text_len < 3000 );
			$url_has_thanks  = ( strpos( $url_path, 'thank' ) !== false );
			if ( $is_short_page || $url_has_thanks ) {
				$type |= self::TYPE_THANKS;
			}
		}

		// TYPE_VIDEO: embed URL限定
		if ( ! ( $type & self::TYPE_VIDEO ) ) {
			if ( strpos( $html, 'youtube.com/embed' ) !== false
				|| strpos( $html, 'player.vimeo.com' ) !== false
				|| strpos( $html, '<video' ) !== false ) {
				$type |= self::TYPE_VIDEO;
			}
		}

		// --- 3. landing判定（TYPE_ARTICLE排他） ---
		if ( ! ( $type & self::TYPE_ARTICLE ) ) {
			if ( $body_text_len !== null && $body_text_len >= 7000 ) {
				$type |= self::TYPE_LANDING;
			}
		}

		// --- 4. URL判定（トップページ） ---
		if ( $url_path === '' || $url_path === '/' || $url_path === '/index.html' || $url_path === '/index.php' ) {
			$type |= self::TYPE_TOP_PAGE;
		}

		return $type;
	}

	/**
	 * 1ページのHTMLを判定してqa_pagesを更新する
	 *
	 * @param int    $page_id qa_pages.page_id
	 * @param string $html    取得済みHTML（falseの場合は取得失敗扱い）
	 * @param string $url     ページURL
	 * @return int|null 判定結果のビットフラグ。HTML取得失敗時はnull
	 */
	public function detect_and_update( $page_id, $html, $url ) {
		global $wpdb;

		$table = $wpdb->prefix . 'qa_pages';

		if ( $html === false ) {
			// HTML取得失敗: page_fetch_status=-1, page_typeはNULLのまま
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET page_fetch_status = -1 WHERE page_id = %d",
					$page_id
				)
			);
			return null;
		}

		$page_type = $this->detect( $html, $url );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET page_type = %d, page_fetch_status = 1 WHERE page_id = %d",
				$page_type,
				$page_id
			)
		);

		return $page_type;
	}

	/**
	 * 複数ページを一括判定してqa_pagesを更新する
	 * [スタブ] 現時点ではdetect_and_update()の繰り返し呼び出しで十分なため未実装。
	 *
	 * @param array $pages [ ['page_id' => int, 'html' => string|false, 'url' => string], ... ]
	 * @return array [ page_id => int|null, ... ] 各ページの判定結果
	 */
	public function detect_and_update_bulk( array $pages ) {
		// スタブ: DB操作は行わない（T43で実装）
		$results = array();
		foreach ( $pages as $page ) {
			$page_id = $page['page_id'];
			$html    = $page['html'];
			$url     = $page['url'];
			if ( $html === false ) {
				$results[ $page_id ] = null;
			} else {
				$results[ $page_id ] = $this->detect( $html, $url );
			}
		}
		return $results;
	}

	/**
	 * KEYWORD_DEFSを__()経由でロードしキャッシュする
	 *
	 * @return array [ group => [ translated_keyword, ... ], ... ]
	 */
	private function load_keywords() {
		if ( $this->keywords !== null ) {
			return $this->keywords;
		}

		$this->keywords = array();
		foreach ( self::KEYWORD_DEFS as $group => $keys ) {
			$this->keywords[ $group ] = array();
			foreach ( $keys as $key ) {
				$translated = __( $key, 'qa-heatmap-analytics' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- $key is from KEYWORD_DEFS constant array
				$this->keywords[ $group ][] = $translated;
				if ( $translated !== $key ) {
					$this->keywords[ $group ][] = $key;
				}
			}
		}
		return $this->keywords;
	}

	/**
	 * 指定グループのキーワードのいずれかが $haystack に含まれるか
	 *
	 * @param string $haystack 検索対象文字列
	 * @param string $group    KEYWORD_DEFSのグループ名
	 * @return bool
	 */
	private function has_keyword( $haystack, $group ) {
		$keywords = $this->load_keywords();
		if ( ! isset( $keywords[ $group ] ) ) {
			return false;
		}
		foreach ( $keywords[ $group ] as $kw ) {
			if ( strpos( $haystack, $kw ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * HTMLから指定タグの内容を抽出する
	 *
	 * @param string $html HTML文字列
	 * @param string $tag  タグ名（例: 'title'）
	 * @return string|false タグ内テキスト。タグがなければfalse
	 */
	private function extract_tag_content( $html, $tag ) {
		$open = '<' . $tag;
		$close = '</' . $tag . '>';

		$start = strpos( $html, $open );
		if ( $start === false ) {
			return false;
		}

		$gt = strpos( $html, '>', $start + strlen( $open ) );
		if ( $gt === false ) {
			return false;
		}

		$end = strpos( $html, $close, $gt + 1 );
		if ( $end === false ) {
			return false;
		}

		return substr( $html, $gt + 1, $end - $gt - 1 );
	}

	/**
	 * JSON-LDスクリプトブロックの内容を抽出する
	 * $pos は 'application/ld+json' が見つかった位置
	 *
	 * @param string $html HTML文字列
	 * @param int    $pos  'application/ld+json' の開始位置
	 * @return string|false JSON文字列。抽出失敗時はfalse
	 */
	private function extract_script_content( $html, $pos ) {
		$gt = strpos( $html, '>', $pos );
		if ( $gt === false ) {
			return false;
		}

		$end = strpos( $html, '</script>', $gt + 1 );
		if ( $end === false ) {
			return false;
		}

		$content = substr( $html, $gt + 1, $end - $gt - 1 );
		$content = trim( $content );
		if ( $content === '' ) {
			return false;
		}

		return $content;
	}

	/**
	 * JSON-LDデータから@type値を配列で取得する
	 * @typeが文字列の場合も配列の場合も対応。@graphにも対応。
	 *
	 * @param array $data json_decodeした連想配列
	 * @return array @type文字列の配列
	 */
	private function get_at_types( $data ) {
		$types = array();

		// トップレベル配列: [{"@type":"Article"}, {"@type":"BreadcrumbList"}]
		if ( isset( $data[0] ) && is_array( $data[0] ) ) {
			foreach ( $data as $item ) {
				if ( isset( $item['@type'] ) ) {
					if ( is_array( $item['@type'] ) ) {
						foreach ( $item['@type'] as $v ) {
							$types[] = $v;
						}
					} else {
						$types[] = $item['@type'];
					}
				}
			}
			return $types;
		}

		if ( isset( $data['@type'] ) ) {
			if ( is_array( $data['@type'] ) ) {
				foreach ( $data['@type'] as $v ) {
					$types[] = $v;
				}
			} else {
				$types[] = $data['@type'];
			}
		}

		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			foreach ( $data['@graph'] as $item ) {
				if ( isset( $item['@type'] ) ) {
					if ( is_array( $item['@type'] ) ) {
						foreach ( $item['@type'] as $v ) {
							$types[] = $v;
						}
					} else {
						$types[] = $item['@type'];
					}
				}
			}
		}

		return $types;
	}
}
