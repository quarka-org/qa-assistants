<?php
/**
 * Assistant Manifest Schema Validator (server-side, Phase 2.2 / Issue #1250).
 *
 * Validates a manifest against the shared JSON Schema (Draft 7) with
 * justinrainbow/json-schema — the PHP counterpart of the JS (Ajv) validator in
 * src/core/assistant-schema/validator/. This is the runtime defense line: the
 * RuntimeHandler validates a manifest right after loading it and rejects
 * structurally invalid manifests before delivering / running them, so a client
 * that skips the JS validation cannot feed a malformed manifest to the runtime.
 *
 * Scope: structural checks (E_SCHEMA_*) AND reference-integrity checks (E_REF_*).
 * Issue #1581（検査 段3・親 #1578「門番はホストの PHP に1人」）で、JS の生成時 validator
 * （src/core/assistant-schema/validator/ref-checks.js）が持つ E_REF_* を移植し、翻訳
 * （lang/*.json）も validate() が受け取るようにした。JS の検査をスキップして PHP 経路
 * （ZIP 直配布・maker を通らない作者）に投げても、同じものが同じ理由で止まる。
 * ★JS と PHP の判定は「同じ入力集合で注視コードの集合が一致する」ことをテストで突合する
 *   （tasks/issue-1581-php-parity/parity-check.js）。片方だけ直したら必ず他方も直すこと。
 *
 * Error codes mirror src/core/assistant-schema/validator/error-adapter.js so the
 * server and the generator speak the same vocabulary.
 *
 * Fail-open policy (Issue #1478): a broken schema bundle must not brick every
 * assistant, so validate() still returns valid on an internal fault — but it says
 * so. "Cannot verify" and "verified clean" are different answers: the unverified
 * result carries `unverified => true`, and callers that must not trust a silent
 * pass (e.g. an author-side CLI) can refuse it. The bundle is also loaded strictly
 * offline: an unresolvable $ref must fail here, never turn into an outbound HTTP
 * request from the customer's WordPress.
 *
 * @package qa_heatmap_analytics
 */

defined( 'ABSPATH' ) || exit;

use JsonSchema\Validator;
use JsonSchema\SchemaStorage;
use JsonSchema\Constraints\Factory;
use JsonSchema\Uri\UriResolver;
use JsonSchema\Uri\UriRetriever;
use JsonSchema\Uri\Retrievers\PredefinedArray;

class QAHM_Assistant_Schema_Validator {

	const ROOT_ID = 'https://quarka.org/schema/assistant/manifest.schema.json';

	/** @var SchemaStorage|null cached schema storage (per request) */
	private static $storage = null;

	/** @var object|null cached root schema (per request) */
	private static $root_schema = null;

	/** @var string|null why the bundle is unusable (null = bundle is complete) */
	private static $bundle_fault = null;

	/** @var UriRetriever|null offline retriever (bundle-only; never touches the network) */
	private static $offline = null;

	/**
	 * justinrainbow constraint name -> structured E_SCHEMA_* code.
	 * Mirrors error-adapter.js KEYWORD_CODE.
	 *
	 * @param string $name constraint name.
	 * @return string
	 */
	private static function keyword_code( $name ) {
		$map = array(
			'required'       => 'E_SCHEMA_REQUIRED',
			// 'additionalProp' is justinrainbow's constraint name for the JSON Schema
			// keyword "additionalProperties" (ConstraintError::ADDITIONAL_PROPERTIES).
			'additionalProp' => 'E_SCHEMA_UNKNOWN_PROPERTY',
			'enum'           => 'E_SCHEMA_ENUM',
			'const'          => 'E_SCHEMA_CONST',
			'type'           => 'E_SCHEMA_TYPE',
			'pattern'        => 'E_SCHEMA_PATTERN',
			'oneOf'          => 'E_SCHEMA_ONEOF',
			'minimum'        => 'E_SCHEMA_RANGE',
			'maximum'        => 'E_SCHEMA_RANGE',
			'minItems'       => 'E_SCHEMA_CONSTRAINT',
			'minProperties'  => 'E_SCHEMA_CONSTRAINT',
			'minLength'      => 'E_SCHEMA_CONSTRAINT',
			'uniqueItems'    => 'E_SCHEMA_CONSTRAINT',
		);
		return isset( $map[ $name ] ) ? $map[ $name ] : 'E_SCHEMA_INVALID';
	}

	/**
	 * Load the schema bundle into a SchemaStorage once per request.
	 *
	 * @return void
	 */
	private static function build_storage() {
		if ( null !== self::$storage ) {
			return;
		}
		$dir   = __DIR__ . '/assistant-schema';
		$files = array_merge(
			array( $dir . '/manifest.schema.json' ),
			(array) glob( $dir . '/definitions/*.json' )
		);

		$raw     = array();
		$decoded = array();
		foreach ( $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled read-only schema file.
			$json   = file_get_contents( $file );
			$schema = json_decode( $json );
			if ( is_object( $schema ) && isset( $schema->{'$id'} ) ) {
				$raw[ $schema->{'$id'} ]     = $json;
				$decoded[ $schema->{'$id'} ] = $schema;
			}
		}

		// Offline by construction: the bundle is the only source. An unknown $ref
		// raises ResourceNotFoundException here instead of becoming an outbound
		// HTTP request from the site (Issue #1478). PredefinedArray answers only
		// from $raw; UriRetriever is the front the SchemaStorage expects.
		$offline = new UriRetriever();
		$offline->setUriRetriever( new PredefinedArray( $raw ) );
		$storage = new SchemaStorage( $offline );
		$root    = null;
		foreach ( $decoded as $id => $schema ) {
			$storage->addSchema( $id, $schema );
			if ( self::ROOT_ID === $id ) {
				$root = $schema;
			}
		}

		// Decide the verdict BEFORE publishing the storage: a half-built state
		// (storage cached, fault still null) would read as "bundle is complete"
		// on the next call in this process.
		try {
			$fault = self::find_bundle_fault( $root, $decoded );
		} catch ( \Throwable $e ) {
			$fault = 'schema bundle check failed: ' . get_class( $e ) . ': ' . $e->getMessage();
		}

		self::$bundle_fault = $fault;
		self::$root_schema  = $root;
		self::$storage      = $storage;
		self::$offline      = $offline;
	}

	/**
	 * Is the loaded bundle usable? Returns the reason it is not, or null when it is.
	 *
	 * A partially deployed bundle (root present, some definitions missing) is the
	 * dangerous case: the root schema loads, so a naive check passes, and every
	 * $ref into a missing file would silently degrade validation.
	 *
	 * @param object|null $root    root schema (or null when absent).
	 * @param array       $decoded id => schema map of everything that loaded.
	 * @return string|null reason, or null when the bundle is complete.
	 */
	private static function find_bundle_fault( $root, $decoded ) {
		if ( null === $root ) {
			return 'schema bundle missing or unreadable in ' . __DIR__ . '/assistant-schema';
		}
		$resolver = new UriResolver();
		$missing  = array();
		foreach ( $decoded as $id => $schema ) {
			foreach ( self::collect_refs( $schema ) as $ref ) {
				if ( '' === $ref || '#' === $ref[0] ) {
					continue; // internal pointer: resolved inside its own document.
				}
				$target = strtok( $resolver->resolve( $ref, $id ), '#' );
				if ( ! isset( $decoded[ $target ] ) ) {
					$missing[ $target ] = true;
				}
			}
		}
		if ( ! empty( $missing ) ) {
			return 'schema bundle incomplete — unresolvable $ref target(s): ' . implode( ', ', array_keys( $missing ) );
		}
		return null;
	}

	/**
	 * Every $ref string in a decoded schema tree.
	 *
	 * @param mixed $node schema node.
	 * @return array<string>
	 */
	private static function collect_refs( $node ) {
		$out = array();
		if ( is_object( $node ) ) {
			foreach ( get_object_vars( $node ) as $key => $value ) {
				if ( '$ref' === $key && is_string( $value ) ) {
					$out[] = $value;
					continue;
				}
				if ( 'enum' === $key || 'const' === $key ) {
					// Literal values, not schemas: a '$ref' key inside them is data.
					// SchemaStorage skips these too (SchemaStorage::resolveRefSchema).
					continue;
				}
				$out = array_merge( $out, self::collect_refs( $value ) );
			}
		} elseif ( is_array( $node ) ) {
			foreach ( $node as $value ) {
				$out = array_merge( $out, self::collect_refs( $value ) );
			}
		}
		return $out;
	}

	/**
	 * Fail-open result: we could not verify, so we do not block — but we do not
	 * claim the manifest is clean either (Issue #1478).
	 *
	 * @param string $reason why verification was impossible.
	 * @return array
	 */
	private static function unverified( $reason ) {
		// Raw error_log(), not $qahm_log: this path means the validator's own
		// prerequisites are broken, so depend on as little as possible.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional last-resort observability for the fail-open path.
		error_log( 'QAHM assistant schema validator fail-open (unverified): ' . $reason );
		return array(
			'valid'      => true,
			'errors'     => array(),
			'unverified' => true,
			'reason'     => $reason,
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Package check (Issue #1580 / 検査 段2) — E_PKG_*
	//
	// 「危険関数を探す」のではなく「許可された構成であること」を確認する（丸山さん 8/13 方針の芯）。
	// アシスタントプラグインは WP プラグインである以上、同梱 PHP に何でも書けてしまう。実行時に
	// 止める手段は WP には無い（権限境界が無い）＝**配布前・一覧に出す前に止める**のが唯一の手。
	//
	// 門番はホストの PHP に1人（親 Issue #1578）＝manifest の構造検査（E_SCHEMA_*）・参照整合性
	// （E_REF_*）と**同じクラス**に置く。エラーコードは同系列（E_PKG_*）。
	//
	// ★これはセキュリティ境界ではなく**品質ゲート**（「安全」とは言わない）。既定は「警告のみ」＝
	//   不合格でも一覧から消さず理由を出す。ブロックは QAHM_ASSISTANT_PKG_STRICT で切替（既定 OFF）。
	// ─────────────────────────────────────────────────────────────────────────

	/** パッケージに在ってよいファイル（相対パス・正規表現）。これ以外は E_PKG_UNEXPECTED_FILE. */
	const PKG_ALLOWED = array(
		'#^manifest\.json$#',
		'#^lang/[a-z]{2}(?:[_-][A-Za-z0-9]{2,8})?\.json$#',
		'#^icon\.png$#',
		'#^[a-z0-9][a-z0-9-]*\.php$#',            // スタブ PHP（プラグイン本体）＝ちょうど1本
		'#^readme\.txt$#i',                        // WP 慣行（配布物に付きうる・中身は見ない）
		'#^(LICENSE|LICENSE\.txt|LICENSE\.md)$#',
	);

	/**
	 * 走査で無視するもの（VCS・OS のゴミ＝ホスト側の都合で紛れる・作者の責任ではない）
	 *
	 * ★ここへ追加するときは **PKG_UNSCANNED_DIRS も要るか**を一緒に考えること（Issue #1605）。
	 *   「1ファイルずつの無害なゴミ」なら不要だが、「配下にいくつでも抱えられる場所」なら
	 *   件数の報告が要る＝そうしないとホワイトリスト方式に見ない場所が増える。
	 *   （例＝`.svn/` は現状ここに無いのでファイルごとに E_PKG_UNEXPECTED_FILE になる。
	 *   ここへ足すなら、同時に PKG_UNSCANNED_DIRS へも足すのが筋）
	 */
	const PKG_IGNORE = array( '#^\.git(/|$)#', '#(^|/)\.DS_Store$#', '#(^|/)Thumbs\.db$#', '#(^|/)\.gitkeep$#' );

	/**
	 * Issue #1605: PKG_IGNORE のうち「中身をいくつでも抱えられる場所」＝走査しないディレクトリ。
	 * 他の3つ（.DS_Store / Thumbs.db / .gitkeep）は1ファイルずつの無害なゴミだが、`.git/` だけは
	 * **配下に任意のファイルを何個でも置ける**。ホワイトリスト方式（挙げられなかったものは全部報告する）で
	 * 「丸ごと見ない場所」が1つあると方式の前提が崩れるため、無視は続けたまま**件数だけ報告**する。
	 *
	 * ★末尾のスラッシュ必須＝**ディレクトリの配下だけ**を数える。`.git` という名前の
	 * **ファイル**（git worktree 形式のポインタ）は中身を持てないので数えない。
	 */
	const PKG_UNSCANNED_DIRS = array( '#^\.git/#' );

	/**
	 * スタブ PHP の正規形＝トークン列（T_INLINE_HTML／空白／コメントを除いた「意味のある」トークンだけ）。
	 * 正本＝maker が生成するスタブ＝`<?php` ＋ ヘッダ docblock ＋ `defined( 'ABSPATH' ) || exit;`。
	 * ヘッダ docblock はコメント＝トークン照合の対象外（名称・版数・説明の差分はここで吸収される）。
	 *
	 * @return array<array{0:int|string,1:string}> [token_id_or_char, normalized text]
	 */
	private static function pkg_stub_canonical_tokens() {
		static $canon = null;
		if ( null === $canon ) {
			$canon = self::pkg_significant_tokens( "<?php\n/** Plugin Name: x */\ndefined( 'ABSPATH' ) || exit;\n" );
		}
		return $canon;
	}

	/**
	 * PHP ソースを「意味のあるトークン列」に落とす（空白・コメント・open tag の改行差を吸収）。
	 *
	 * @param string $src PHP source.
	 * @return array<array{0:int|string,1:string}>
	 */
	private static function pkg_significant_tokens( $src ) {
		$out = array();
		foreach ( token_get_all( $src ) as $t ) {
			if ( is_array( $t ) ) {
				list( $id, $text ) = $t;
				// 空白・コメント・inline HTML に加えて、末尾の閉じタグ（T_CLOSE_TAG・PR #1583 レビュー 🟡-1）も
				// 意味ゼロなので落とす＝PHP は最後の閉じタグを無視する（body には影響しない）。
				if ( T_WHITESPACE === $id || T_COMMENT === $id || T_DOC_COMMENT === $id || T_INLINE_HTML === $id || T_CLOSE_TAG === $id ) {
					continue;
				}
				if ( T_OPEN_TAG === $id ) {
					$text = '<?php';
				}
				// 文字列リテラルはクォート種を正規化（'ABSPATH' と "ABSPATH" を同一視）。
				// ★中身は case-sensitive のまま（'ABSPATH' と 'abspath' は別物＝小文字化しない）。
				if ( T_CONSTANT_ENCAPSED_STRING === $id ) {
					$text = "'" . trim( $text, '\'"' ) . "'";
				} elseif ( T_STRING === $id || T_EXIT === $id ) {
					// 関数名（defined）・言語構造（exit/die）は PHP 自身が case-insensitive なので
					// 字句照合だけ厳しくしない＝小文字化して比較する（PR #1583 レビュー 🟡-1・DEFINED/EXIT を許す）。
					$text = strtolower( $text );
				}
				$out[] = array( $id, $text );
			} else {
				$out[] = array( $t, $t );
			}
		}
		return $out;
	}

	/**
	 * アシスタントプラグインのディレクトリを検査する（E_PKG_*）.
	 *
	 * @param string $dir  プラグインディレクトリ（絶対パス）.
	 * @param string $slug プラグイン slug（スタブ PHP の期待名＝<slug>.php）.
	 * @return array { valid: bool, errors: array<array{code,path,message}>, strict: bool }
	 *               strict = ブロックモードか（QAHM_ASSISTANT_PKG_STRICT）。valid=false でも
	 *               strict=false なら呼び出し側は一覧に出してよい（警告のみ）。
	 */
	public static function validate_package( $dir, $slug ) {
		$errors = array();
		$strict = defined( 'QAHM_ASSISTANT_PKG_STRICT' ) && QAHM_ASSISTANT_PKG_STRICT;
		$dir    = rtrim( str_replace( '\\', '/', (string) $dir ), '/' );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return array( 'valid' => false, 'strict' => $strict, 'errors' => array( self::pkg_err( 'E_PKG_MISSING', '/', 'assistant directory not found' ) ) );
		}

		// 1) 走査（再帰・相対パス）
		$files = array();
		// Issue #1605: 走査しない場所（.git/）に何個入っていたかを数える。中身は見ないが「見なかった」ことは言う。
		$unscanned = 0;
		try {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
			foreach ( $it as $f ) {
				$rel = ltrim( str_replace( '\\', '/', substr( $f->getPathname(), strlen( $dir ) ) ), '/' );
				$ignored = false;
				foreach ( self::PKG_IGNORE as $re ) {
					if ( 1 === preg_match( $re, $rel ) ) { $ignored = true; break; }
				}
				if ( $ignored ) {
					// 走査しないディレクトリの配下にある「ファイル」だけを数える（ディレクトリ自身は数えない）。
					if ( $f->isFile() ) {
						foreach ( self::PKG_UNSCANNED_DIRS as $re ) {
							if ( 1 === preg_match( $re, $rel ) ) { $unscanned++; break; }
						}
					}
					continue;
				}
				if ( $f->isDir() ) {
					// 許すディレクトリは lang/ だけ
					if ( 'lang' !== $rel ) {
						$errors[] = self::pkg_err( 'E_PKG_UNEXPECTED_FILE', '/' . $rel . '/', 'unexpected directory "' . $rel . '/" (only lang/ is allowed)' );
					}
					continue;
				}
				$files[] = $rel;
			}
		} catch ( \Throwable $e ) {
			return array( 'valid' => false, 'strict' => $strict, 'errors' => array( self::pkg_err( 'E_PKG_INTERNAL', '/', 'scan failed: ' . $e->getMessage() ) ) );
		}
		sort( $files );

		// 1b) Issue #1605: 走査しなかった場所を黙って通さない（中身は見ないが、あることは言う）。
		if ( $unscanned > 0 ) {
			$errors[] = self::pkg_err(
				'E_PKG_UNSCANNED',
				'/.git/',
				'".git/" contains ' . $unscanned . ' file(s) that were not inspected — remove the VCS directory from the distributed package'
			);
		}

		// 2) ホワイトリスト
		$php_files = array();
		foreach ( $files as $rel ) {
			$ok = false;
			foreach ( self::PKG_ALLOWED as $re ) {
				if ( 1 === preg_match( $re, $rel ) ) { $ok = true; break; }
			}
			if ( ! $ok ) {
				$errors[] = self::pkg_err( 'E_PKG_UNEXPECTED_FILE', '/' . $rel, 'unexpected file "' . $rel . '" (allowed: manifest.json, lang/*.json, icon.png, one plugin stub .php, readme.txt, LICENSE)' );
				continue;
			}
			if ( '.php' === substr( $rel, -4 ) ) {
				$php_files[] = $rel;
			}
		}

		// 3) 必須と PHP の本数
		if ( ! in_array( 'manifest.json', $files, true ) ) {
			$errors[] = self::pkg_err( 'E_PKG_MISSING', '/manifest.json', 'manifest.json is missing' );
		}
		if ( 0 === count( $php_files ) ) {
			$errors[] = self::pkg_err( 'E_PKG_MISSING', '/' . $slug . '.php', 'plugin stub ' . $slug . '.php is missing' );
		} elseif ( count( $php_files ) > 1 ) {
			$errors[] = self::pkg_err( 'E_PKG_MULTIPLE_PHP', '/', 'exactly one .php (the plugin stub) is allowed, found ' . count( $php_files ) . ': ' . implode( ', ', $php_files ) );
		} else {
			// 4) スタブ照合＝名前が <slug>.php で、トークン列が正規形と一致
			$stub = $php_files[0];
			if ( $stub !== $slug . '.php' ) {
				$errors[] = self::pkg_err( 'E_PKG_STUB_MISMATCH', '/' . $stub, 'plugin stub must be named ' . $slug . '.php (found ' . $stub . ')' );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file, read-only.
			$src = file_get_contents( $dir . '/' . $stub );
			if ( false === $src ) {
				$errors[] = self::pkg_err( 'E_PKG_STUB_MISMATCH', '/' . $stub, 'plugin stub is unreadable' );
			} else {
				$got   = self::pkg_significant_tokens( $src );
				$canon = self::pkg_stub_canonical_tokens();
				if ( ! self::pkg_tokens_equal( $got, $canon ) ) {
					$errors[] = self::pkg_err( 'E_PKG_STUB_MISMATCH', '/' . $stub,
						'plugin stub differs from the canonical stub (only the header docblock may vary; the body must be exactly `defined( \'ABSPATH\' ) || exit;`). ' .
						'Assistant logic belongs in manifest.json, not in PHP' );
				}
				// ヘッダ docblock の存在（get_plugin_data が読む）＝無いと WP のプラグイン一覧にも出ない
				if ( 1 !== preg_match( '/^\s*<\?php\s*\/\*\*.*?Plugin Name\s*:/s', $src ) ) {
					$errors[] = self::pkg_err( 'E_PKG_STUB_MISMATCH', '/' . $stub, 'plugin header (Plugin Name:) is missing' );
				}
				// Issue #1605: 閉じタグの後ろに置かれた出力を見逃さない。
				// pkg_significant_tokens は T_CLOSE_TAG と T_INLINE_HTML を「意味ゼロ」として落とすため、
				// 閉じタグの後ろに何を書いてもトークン列は正規形と一致してしまう（＝本検査を素通りする）。
				// 落とす判断そのものは **末尾の閉じタグ** については正しい（PHP は最後の閉じタグを無視する）が、
				// 後ろに続きがある場合は成立しない＝プラグインが有効な間、**すべてのページの読み込みで
				// そのまま出力される**（HTML も script タグも）。ここで独立に見る。
				$stray = self::pkg_inline_output( $src );
				if ( null !== $stray ) {
					$errors[] = self::pkg_err( 'E_PKG_STUB_MISMATCH', '/' . $stub,
						'plugin stub emits output outside of PHP tags (' . $stray . ') — it would be printed on every page load. ' .
						'The stub must contain nothing but the header docblock and `defined( \'ABSPATH\' ) || exit;`' );
				}
			}
		}

		// 5) アイコン＝PNG のみ（SVG はスクリプトを埋め込めるため不可）
		foreach ( $files as $rel ) {
			if ( 1 === preg_match( '#^icon\.(svg|SVG)$#', $rel ) ) {
				// ホワイトリストで既に E_PKG_UNEXPECTED_FILE になっているが、理由を明示する
				$errors[] = self::pkg_err( 'E_PKG_ICON', '/' . $rel, 'SVG icons are not allowed (SVG can carry scripts) — use icon.png' );
			}
		}
		if ( in_array( 'icon.png', $files, true ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file, read-only.
			$head = file_get_contents( $dir . '/icon.png', false, null, 0, 8 );
			if ( false === $head || "\x89PNG\r\n\x1a\n" !== $head ) {
				$errors[] = self::pkg_err( 'E_PKG_ICON', '/icon.png', 'icon.png is not a PNG file (magic bytes mismatch)' );
			}
		}

		return array( 'valid' => 0 === count( $errors ), 'strict' => $strict, 'errors' => $errors );
	}

	/**
	 * Issue #1605: スタブ PHP が **PHP タグの外に出力を持っていないか**を見る。
	 *
	 * `pkg_significant_tokens()` は T_INLINE_HTML を落とす（正規形との照合では docblock の差分等を
	 * 吸収したいため）。その結果、閉じタグの後ろに書かれたものは照合に一切現れず、
	 * 「本文は `defined( 'ABSPATH' ) || exit;` だけ」という保証をすり抜けていた。
	 * PHP タグの外の文字はスクリプトではなく **出力** なので、プラグインが有効な間、
	 * すべてのページの読み込みでそのまま印字される（HTML も script タグも）。
	 *
	 * 空白だけの T_INLINE_HTML（閉じタグの直後の改行など）は出力としては無害なので許す。
	 *
	 * ※ このコメントに閉じタグそのものを書かないこと。PHP パーサは**コメントの中でも**
	 *    閉じタグを終了タグとして解釈し、その先が inline HTML になってパースが壊れる。
	 *
	 * @param string $src PHP source.
	 * @return string|null 見つかった出力の先頭（表示用に短縮・安全化したもの）。無ければ null.
	 */
	private static function pkg_inline_output( $src ) {
		foreach ( token_get_all( $src ) as $t ) {
			if ( ! is_array( $t ) || T_INLINE_HTML !== $t[0] ) {
				continue;
			}
			if ( '' === trim( $t[1] ) ) {
				continue;
			}
			// 表示用＝1行にまとめて 40 バイトで切り、UTF-8 として妥当な位置まで戻す。
			$snippet = preg_replace( '/\s+/', ' ', trim( $t[1] ) );
			// Issue #1605（レビュー ⚪-1）: 目に見える文字が1つも残らないとき（BOM だけ等）は、
			// そのまま出すと**括弧の中が空に見えて何が入っているのか分からない**。実務でほぼ唯一の
			// 形である BOM は名前で、それ以外はバイト列で示す（判定そのものは変えない＝表示だけ）。
			if ( '' === preg_replace( '/[\p{C}\p{Z}]/u', '', $snippet ) ) {
				return ( "\xEF\xBB\xBF" === $snippet )
					? 'U+FEFF, a byte order mark'
					: 'invisible bytes ' . strtoupper( bin2hex( substr( $snippet, 0, 12 ) ) );
			}
			if ( strlen( $snippet ) > 40 ) {
				$snippet = self::safe_snippet( substr( $snippet, 0, 40 ) ) . '…';
			}
			return $snippet;
		}
		return null;
	}

	/**
	 * @param array $a significant tokens.
	 * @param array $b significant tokens.
	 * @return bool
	 */
	private static function pkg_tokens_equal( $a, $b ) {
		if ( count( $a ) !== count( $b ) ) {
			return false;
		}
		foreach ( $a as $i => $t ) {
			if ( $t[0] !== $b[ $i ][0] || $t[1] !== $b[ $i ][1] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param string $code E_PKG_*.
	 * @param string $path pointer-ish path within the package.
	 * @param string $message message.
	 * @return array
	 */
	private static function pkg_err( $code, $path, $message ) {
		return array( 'code' => $code, 'path' => $path, 'message' => $message );
	}

	/**
	 * Validate a manifest against the schema.
	 *
	 * On an internal error (missing/corrupt schema bundle, library exception)
	 * this fails open and returns valid: validation is defense-in-depth, not the
	 * sole guard, so a validator fault must not brick every assistant.
	 *
	 * @param array|object|string $manifest     decoded manifest (array/object) or raw JSON string.
	 * @param array|null          $translations Issue #1581: 翻訳。`load_translations( $dir )` の戻り値
	 *                                          （`merged`＝`t:` キーの存在検査用の1枚／`by_locale`＝中身を見る
	 *                                          検査用の locale 別）。null＝翻訳なし（`t:` 検査と翻訳側の
	 *                                          テンプレート検査はスキップ＝JS の lang 無しと同じ縮退）。
	 * @return array { valid: bool, errors: array<array{code:string,path:string,message:string,did_you_mean?:string}> }
	 */
	public static function validate( $manifest, $translations = null ) {
		try {
			self::build_storage();
			if ( null !== self::$bundle_fault ) {
				// Fail-open by design, but never silently and never as "VALID":
				// a missing or partially deployed schema bundle would otherwise
				// disable this defense line unnoticed (Issue #1478).
				return self::unverified( self::$bundle_fault );
			}
			$data      = self::to_object( $manifest );
			$validator = new Validator( new Factory( self::$storage, self::$offline ) );
			$validator->validate( $data, self::$root_schema );
			// 翻訳＝中身を見る検査は locale ごと（$sources）／`t:` キーの存在検査は merged 1枚。
			$sources = self::translation_sources( $translations );
			$merged  = ( is_array( $translations ) && isset( $translations['merged'] ) ) ? self::to_object( $translations['merged'] ) : null;
			// 参照整合性検査＝JS 側 ref-checks.js の runRefChecks と**同じ順・同じ判定**（Issue #1581 で移植）。
			// schema の if/then に書けないもの（justinrainbow のキーワードサポート差＝#1460 W-8 の教訓）と、
			// schema が見ない文字列の**中身**・クロス参照は、すべてここ（コード側）にある。
			$conditional = array_merge(
				self::check_data_sources( $data ),
				self::check_tables( $data ),
				self::check_charts( $data ),
				self::check_scorecards( $data ),
				self::check_scenes( $data ),
				self::check_permissions( $data ),
				self::check_var_refs( $data ),
				self::check_if_vars( $data ),
				// Issue #1543: `if` の `in` オペランド検査。schema に書けない（`is` 依存の条件付き）ためコード側。
				self::check_if_in_operands( $data ),
				self::check_lookups( $data ),
				// Issue #1510: 条件検査（tally / count_distinct 使用時の min_core_version 必須）。
				self::check_min_core_version( $data, $sources ),
				// Issue #1575: テンプレートの書き間違い検査（manifest 本体＋翻訳の locale ごと）。
				self::check_template_syntax( $data, $sources ),
				// `t:` キーの存在検査（翻訳を渡したときのみ）。
				self::check_translations( $data, $merged ),
				// Issue #1577: 計算式（calc / set_var の expr）の書き間違い検査。同じ理由でコード側。
				self::check_expressions( $data )
			);
			if ( $validator->isValid() && empty( $conditional ) ) {
				return array( 'valid' => true, 'errors' => array() );
			}
			$errors = $validator->isValid() ? array() : self::adapt( $validator->getErrors(), $data );
			return array(
				'valid'  => false,
				'errors' => array_merge( $errors, $conditional ),
			);
		} catch ( \Throwable $e ) {
			// Fail-open by design, but never silently (see above). The validator
			// itself faulted, so we could not verify this manifest.
			return self::unverified( get_class( $e ) . ': ' . $e->getMessage() );
		}
	}

	/**
	 * Issue #1510 / #1308: 新しいコアでしか正しく動かない書き方には min_core_version の
	 * 宣言を必須にする。未宣言だと旧コアで語彙が黙って無視される／比較が意図と違う結果に
	 * なる（宣言があれば配信ゲートが E_CORE_TOO_OLD を返す＝#1433 の既存機構が効く）。
	 * JS 側の同検査＝validator/ref-checks.js（両側で同じ判定になることをテストで突合）。
	 *
	 * 要求（正本の一覧＝docs/specs/assistant/validation.md）:
	 * - 2.10.0 … tally / agg count_distinct（#1510 で追加した語彙）
	 * - 2.11.0 … filter の gt/gte/lt/lte/contains の "$var" オペランド・if の value の "$var"
	 *            （#1308 で初めて解決されるようになった＝旧コアでは無言で効かない）
	 * - 2.12.0 … extract_host（#1307・宣言が入るのが 2.12.0 で初）
	 * - 2.13.0 … if の器の拡張＝all / else / then.set / is:"in"（#1543）
	 * - 2.14.0 … テンプレートの書式指定子 round / before / after（#1575・旧コアは新指定子を
	 *            素通りさせ、引数つきの形は展開すらしない）
	 *
	 * @param mixed $data    stdClass tree（to_object 済み）.
	 * @param array $sources 翻訳の並び（translation_sources() の戻り値・locale ごと）。Issue #1581.
	 * @return array<array> エラー配列（問題なしなら空）.
	 */
	private static function check_min_core_version( $data, $sources = array() ) {
		$format_use  = self::find_format_specifier_use( $data, $sources );
		$if_use      = self::find_if_container_use( $data );
		$extract_use = self::find_extract_host_use( $data );
		$compare_use = self::find_compare_rules_use( $data );
		$tally_use   = self::find_tally_vocab_use( $data );

		// 一番高い要求で判定する（低い方だけ満たして通るのを防ぐ）。
		if ( null !== $format_use ) {
			// Issue #1575: 新しい書式指定子（round / before / after）。旧コアは新指定子を
			// 素通りさせて未整形の値を出し、引数つきの形は展開すらしない。
			$need_min = '2.14.0';
			$need_use = $format_use;
		} elseif ( null !== $if_use ) {
			$need_min = '2.13.0';
			$need_use = $if_use;
		} elseif ( null !== $extract_use ) {
			$need_min = '2.12.0';
			$need_use = $extract_use;
		} elseif ( null !== $compare_use ) {
			$need_min = '2.11.0';
			$need_use = $compare_use;
		} elseif ( null !== $tally_use ) {
			$need_min = '2.10.0';
			$need_use = $tally_use;
		} else {
			return array();
		}

		$mcv = isset( $data->min_core_version ) ? $data->min_core_version : null;
		if ( ! is_string( $mcv ) || 1 !== preg_match( '/^\d+\.\d+\.\d+$/', $mcv ) ) {
			return array(
				array(
					'code'    => 'E_REF_MIN_CORE_VERSION',
					'path'    => '/min_core_version',
					'message' => 'manifest uses ' . $need_use . ' but does not declare min_core_version ("' . $need_min . '" or higher required - without it, older cores silently ignore it)',
				),
			);
		}
		if ( version_compare( $mcv, $need_min, '<' ) ) {
			return array(
				array(
					'code'    => 'E_REF_MIN_CORE_VERSION',
					'path'    => '/min_core_version',
					'message' => 'min_core_version "' . $mcv . '" is lower than "' . $need_min . '" required by ' . $need_use,
				),
			);
		}
		return array();
	}

	/**
	 * Issue #1307: extract_host の使用箇所を1つ返す。
	 *
	 * runtime には 2026-06-12 から実装があるが **schema の宣言は spec 2.12.0 が初**。
	 * 配信を止めるのは runtime ではなく schema validator なので、2.9.0〜2.11.0 のコアでは
	 * 「実装はあるのに schema が弾く」＝400 E_SCHEMA_VALIDATION になる。よって宣言必須。
	 * JS 側 findExtractHostUse と同一判定。
	 *
	 * @param mixed $data stdClass tree.
	 * @return string|null
	 */
	private static function find_extract_host_use( $data ) {
		if ( ! is_object( $data ) || ! isset( $data->data_sources ) || ! is_object( $data->data_sources ) ) {
			return null;
		}
		foreach ( get_object_vars( $data->data_sources ) as $dk => $ds ) {
			if ( ! is_object( $ds ) || ! isset( $ds->transform ) || ! is_array( $ds->transform ) ) {
				continue;
			}
			foreach ( $ds->transform as $i => $t ) {
				if ( is_object( $t ) && isset( $t->extract_host ) ) {
					return '/data_sources/' . $dk . '/transform/' . $i . ' (extract_host)';
				}
			}
		}
		return null;
	}

	/**
	 * Issue #1510 の語彙（tally / agg count_distinct）の使用箇所を1つ返す。
	 *
	 * @param mixed $data stdClass tree.
	 * @return string|null
	 */
	private static function find_tally_vocab_use( $data ) {
		if ( ! is_object( $data ) || ! isset( $data->data_sources ) || ! is_object( $data->data_sources ) ) {
			return null;
		}
		foreach ( get_object_vars( $data->data_sources ) as $dk => $ds ) {
			if ( ! is_object( $ds ) || ! isset( $ds->transform ) || ! is_array( $ds->transform ) ) {
				continue;
			}
			foreach ( $ds->transform as $i => $t ) {
				if ( ! is_object( $t ) ) {
					continue;
				}
				if ( isset( $t->tally ) ) {
					return '/data_sources/' . $dk . '/transform/' . $i . ' (tally)';
				}
				if ( isset( $t->group_by ) && isset( $t->agg ) && is_object( $t->agg ) && in_array( 'count_distinct', get_object_vars( $t->agg ), true ) ) {
					return '/data_sources/' . $dk . '/transform/' . $i . ' (agg count_distinct)';
				}
			}
		}
		return null;
	}

	/**
	 * Issue #1308 で初めて効くようになる書き方（旧コアでは無言で意図と違う結果になる）の
	 * 使用箇所を1つ返す。
	 *
	 * @param mixed $data stdClass tree.
	 * @return string|null
	 */
	private static function find_compare_rules_use( $data ) {
		$ops = array( 'gt', 'gte', 'lt', 'lte', 'contains' );

		if ( is_object( $data ) && isset( $data->data_sources ) && is_object( $data->data_sources ) ) {
			foreach ( get_object_vars( $data->data_sources ) as $dk => $ds ) {
				if ( ! is_object( $ds ) || ! isset( $ds->transform ) || ! is_array( $ds->transform ) ) {
					continue;
				}
				foreach ( $ds->transform as $i => $t ) {
					if ( ! is_object( $t ) || ! isset( $t->filter ) || ! is_object( $t->filter ) ) {
						continue;
					}
					foreach ( get_object_vars( $t->filter ) as $field => $cond ) {
						if ( ! is_object( $cond ) ) {
							continue;
						}
						foreach ( $ops as $op ) {
							if ( isset( $cond->$op ) && self::is_var_ref( $cond->$op ) ) {
								return '/data_sources/' . $dk . '/transform/' . $i . '/filter/' . $field . '/' . $op . ' ($var operand)';
							}
						}
					}
				}
			}
		}

		if ( is_object( $data ) && isset( $data->scenes ) && is_object( $data->scenes ) ) {
			foreach ( get_object_vars( $data->scenes ) as $sn => $steps ) {
				if ( ! is_array( $steps ) ) {
					continue;
				}
				foreach ( $steps as $i => $step ) {
					if ( ! is_object( $step ) || ! isset( $step->if ) || ! is_object( $step->if ) ) {
						continue;
					}
					$cond = $step->if;
					if ( isset( $cond->value ) && self::is_var_ref( $cond->value ) ) {
						return '/scenes/' . $sn . '/' . $i . '/if/value ($var operand)';
					}
					// Issue #1308 本文そのもの: 旧コアの if eq/neq は厳密比較（===）で、
					// フォーム値は必ず文字列。非文字列リテラルとの比較は 2.10.0 以前では
					// 永遠に不成立・警告もなし＝この PR で初めて成立するようになる書き方。
					// null は除外（旧コアでも成立し得たため）。JS 側 findCompareRulesUse と同一判定。
					$is = isset( $cond->is ) ? $cond->is : null;
					if ( ( 'eq' === $is || 'neq' === $is ) && isset( $cond->value ) && ! is_string( $cond->value ) ) {
						return '/scenes/' . $sn . '/' . $i . '/if/' . $is . ' (non-string literal - strict-equal on older cores)';
					}
				}
			}
		}

		return null;
	}

	/**
	 * Issue #1543 (B-6): `if` の器の拡張（`all` / `else` / `then.set` / `is:"in"`）の
	 * 使用箇所を1つ返す。
	 *
	 * 旧コアの handleIf は `then.goto` しか見ないので `else` と `then.set` は無視され、
	 * `all` 形は `var` 欠落で条件が不成立、`in` は未知の演算子として throw する
	 * ＝**分岐が黙って死ぬ**。よって min_core_version 2.13.0 の宣言必須。
	 * JS 側 findIfContainerUse と同一判定。
	 *
	 * @param mixed $data stdClass tree.
	 * @return string|null
	 */
	private static function find_if_container_use( $data ) {
		if ( ! is_object( $data ) || ! isset( $data->scenes ) || ! is_object( $data->scenes ) ) {
			return null;
		}
		foreach ( get_object_vars( $data->scenes ) as $sn => $steps ) {
			if ( ! is_array( $steps ) ) {
				continue;
			}
			foreach ( $steps as $i => $step ) {
				if ( ! is_object( $step ) || ! isset( $step->if ) || ! is_object( $step->if ) ) {
					continue;
				}
				$cond = $step->if;
				$base = '/scenes/' . $sn . '/' . $i;
				if ( isset( $cond->all ) && is_array( $cond->all ) ) {
					return $base . '/if/all (all-of condition)';
				}
				if ( isset( $cond->is ) && 'in' === $cond->is ) {
					return $base . '/if (is "in")';
				}
				if ( isset( $step->else ) ) {
					return $base . '/else (else branch)';
				}
				if ( isset( $step->then ) && is_object( $step->then ) && isset( $step->then->set ) ) {
					return $base . '/then/set (set in an if branch)';
				}
			}
		}
		return null;
	}

	/**
	 * Issue #1543: `if` の `is:"in"` のオペランドは配列か `"$var"` でなければならない。
	 *
	 * `transform.filter` の `in` は schema（`filterCondition.in` の oneOf）が同じ制約を掛けて
	 * いるが、`if` では制約が `is` の値に依存する条件付きになる。**justinrainbow は if/then を
	 * 評価しない**（#1460 W-8・本 PR で実測＝schema に書くと JS だけ厳しく PHP は素通り）ため、
	 * 両 validator のコード側で同じ判定を持つ。JS 側 checkIfInOperands と同一判定。
	 *
	 * 放置すると素の文字列が配信を通り、runtime の resolveForEachIn が null を返して
	 * console 警告 1 本だけで**常に不成立**＝静かに間違える形になる。
	 *
	 * @param mixed $data stdClass tree.
	 * @return array<array> エラー配列（問題なしなら空）.
	 */
	private static function check_if_in_operands( $data ) {
		$errs = array();
		if ( ! is_object( $data ) || ! isset( $data->scenes ) || ! is_object( $data->scenes ) ) {
			return $errs;
		}
		foreach ( get_object_vars( $data->scenes ) as $sn => $steps ) {
			if ( ! is_array( $steps ) ) {
				continue;
			}
			foreach ( $steps as $i => $step ) {
				if ( ! is_object( $step ) || ! isset( $step->if ) || ! is_object( $step->if ) ) {
					continue;
				}
				$base  = '/scenes/' . $sn . '/' . $i . '/if';
				$conds = array();
				if ( isset( $step->if->all ) && is_array( $step->if->all ) ) {
					foreach ( $step->if->all as $ai => $sub ) {
						if ( is_object( $sub ) ) {
							$conds[ $base . '/all/' . $ai ] = $sub;
						}
					}
				} else {
					$conds[ $base ] = $step->if;
				}
				foreach ( $conds as $path => $cond ) {
					if ( ! isset( $cond->is ) || 'in' !== $cond->is ) {
						continue;
					}
					$v = isset( $cond->value ) ? $cond->value : null;
					if ( is_array( $v ) ) {
						continue;
					}
					if ( self::is_var_ref( $v ) ) {
						continue;
					}
					$errs[] = array(
						'code'    => 'E_REF_IF_IN_OPERAND',
						'path'    => $path . '/value',
						'message' => 'if is:"in" requires an array value (or a "$var" that resolves to one); a plain value never matches',
					);
				}
			}
		}
		return $errs;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Issue #1581（検査 段3）: 参照整合性検査（E_REF_*）の移植。
	// ★以下は src/core/assistant-schema/validator/ref-checks.js の各 check* と**同じ判定・同じ
	//   コード・同じ path 形・同じ did_you_mean の出し方**にしてある。片方だけ直したら必ず他方も
	//   直すこと（突合＝tasks/issue-1581-php-parity/parity-check.js）。
	// 型の見方は JS のまま＝`typeof x === 'string'` → is_string／`Array.isArray` → is_array／
	// `x && typeof x === 'object'` → is_object（JSON 配列は PHP でも配列に decode されるので区別できる）。
	// ─────────────────────────────────────────────────────────────────────────

	/** $sys.* の固定3種（JS 側 SYS_VARS と同一）。 */
	const SYS_VARS = array( 'tracking_id', 'locale', 'tz' );

	/**
	 * scenes の全 step を [step, path] の並びで返す（JS 側 eachStep と同じ走査順・同じ path）。
	 *
	 * @param mixed $data stdClass tree.
	 * @return array<array{0:object,1:string}>
	 */
	private static function steps( $data ) {
		$out = array();
		if ( ! is_object( $data ) || ! isset( $data->scenes ) || ! is_object( $data->scenes ) ) {
			return $out;
		}
		foreach ( get_object_vars( $data->scenes ) as $sn => $steps ) {
			if ( ! is_array( $steps ) ) {
				continue;
			}
			foreach ( $steps as $i => $step ) {
				if ( is_object( $step ) ) {
					$out[] = array( $step, '/scenes/' . $sn . '/' . $i );
				}
			}
		}
		return $out;
	}

	/**
	 * data_sources の各 transform を [ds_key, index, transform] で返す（JS 側の走査と同じ）。
	 *
	 * @param mixed $data stdClass tree.
	 * @return array<array{0:string,1:int,2:object}>
	 */
	private static function transforms( $data ) {
		$out = array();
		if ( ! is_object( $data ) || ! isset( $data->data_sources ) || ! is_object( $data->data_sources ) ) {
			return $out;
		}
		foreach ( get_object_vars( $data->data_sources ) as $dk => $ds ) {
			if ( ! is_object( $ds ) || ! isset( $ds->transform ) || ! is_array( $ds->transform ) ) {
				continue;
			}
			foreach ( $ds->transform as $i => $t ) {
				if ( is_object( $t ) ) {
					$out[] = array( $dk, $i, $t );
				}
			}
		}
		return $out;
	}

	/**
	 * セクション（data_sources / tables / charts / scorecards / scenes / lookups）のキー一覧。
	 * JS 側 `Object.keys(manifest.X || {})` と同じ（無ければ空）。
	 *
	 * @param mixed  $data stdClass tree.
	 * @param string $sec  セクション名.
	 * @return array<string>
	 */
	private static function section_keys( $data, $sec ) {
		if ( ! is_object( $data ) || ! isset( $data->$sec ) || ! is_object( $data->$sec ) ) {
			return array();
		}
		return array_map( 'strval', array_keys( get_object_vars( $data->$sec ) ) );
	}

	/**
	 * エラー1件を組み立てる（JS 側 E() と同じ形。did_you_mean は値があるときだけ載せる）。
	 *
	 * @param string      $code    エラーコード.
	 * @param string      $path    JSON ポインタ.
	 * @param string      $message メッセージ.
	 * @param string|null $dym     did_you_mean.
	 * @return array
	 */
	private static function ref_error( $code, $path, $message, $dym = null ) {
		$e = array(
			'code'    => $code,
			'path'    => $path,
			'message' => $message,
		);
		if ( null !== $dym && '' !== $dym ) {
			$e['did_you_mean'] = $dym;
		}
		return $e;
	}

	/**
	 * step の <key> が <section> に存在するか（fetch→data_sources / table→tables / chart→charts /
	 * scorecard→scorecards）。JS 側 checkDataSources / checkTables / checkCharts / checkScorecards と同一。
	 *
	 * @param mixed  $data    stdClass tree.
	 * @param string $key     step のキー（fetch / table / chart / scorecard）.
	 * @param string $section セクション名.
	 * @param string $code    エラーコード.
	 * @param string $noun    メッセージ中の名詞（data_source / table / chart / scorecard）.
	 * @return array<array>
	 */
	private static function check_named_refs( $data, $key, $section, $code, $noun ) {
		$errs  = array();
		$names = self::section_keys( $data, $section );
		foreach ( self::steps( $data ) as $pair ) {
			list( $step, $path ) = $pair;
			if ( isset( $step->$key ) && is_string( $step->$key ) && ! in_array( $step->$key, $names, true ) ) {
				$errs[] = self::ref_error(
					$code,
					$path . '/' . $key,
					$key . ' references undefined ' . $noun . ' "' . $step->$key . '"',
					self::closest( $step->$key, $names )
				);
			}
		}
		return $errs;
	}

	/** E_REF_DATA_SOURCE＝step.fetch → data_sources（JS 側 checkDataSources）。 */
	private static function check_data_sources( $data ) {
		return self::check_named_refs( $data, 'fetch', 'data_sources', 'E_REF_DATA_SOURCE', 'data_source' );
	}

	/** E_REF_TABLE＝step.table → tables（JS 側 checkTables）。 */
	private static function check_tables( $data ) {
		return self::check_named_refs( $data, 'table', 'tables', 'E_REF_TABLE', 'table' );
	}

	/** E_REF_CHART＝step.chart → charts（JS 側 checkCharts）。 */
	private static function check_charts( $data ) {
		return self::check_named_refs( $data, 'chart', 'charts', 'E_REF_CHART', 'chart' );
	}

	/** E_REF_SCORECARD＝step.scorecard → scorecards（JS 側 checkScorecards）。 */
	private static function check_scorecards( $data ) {
		return self::check_named_refs( $data, 'scorecard', 'scorecards', 'E_REF_SCORECARD', 'scorecard' );
	}

	/**
	 * E_REF_SCENE＝goto / on_error の全経路が scenes に存在するか（JS 側 checkScenes と同一）。
	 * 経路＝step.goto / then.goto / else.goto（#1543）/ fetch step の on_error / choices[].goto /
	 * 動的 choices.goto と choices.extra[].goto / row_action.goto / form.cancel.goto /
	 * config_read.on_error / config_write.on_error。
	 *
	 * @param mixed $data stdClass tree.
	 * @return array<array>
	 */
	private static function check_scenes( $data ) {
		$errs  = array();
		$names = self::section_keys( $data, 'scenes' );
		$push  = function ( $target, $path ) use ( &$errs, $names ) {
			if ( is_string( $target ) && ! in_array( $target, $names, true ) ) {
				$errs[] = self::ref_error(
					'E_REF_SCENE',
					$path,
					'goto references undefined scene "' . $target . '"',
					self::closest( $target, $names )
				);
			}
		};
		foreach ( self::steps( $data ) as $pair ) {
			list( $step, $path ) = $pair;
			if ( isset( $step->goto ) ) {
				$push( $step->goto, $path . '/goto' );
			}
			if ( isset( $step->then ) && is_object( $step->then ) && isset( $step->then->goto ) ) {
				$push( $step->then->goto, $path . '/then/goto' );
			}
			// Issue #1543 (B-6): else の goto も同じ検査を受ける（then だけ検査すると片側だけ配信される）。
			if ( isset( $step->else ) && is_object( $step->else ) && isset( $step->else->goto ) ) {
				$push( $step->else->goto, $path . '/else/goto' );
			}
			if ( property_exists( $step, 'fetch' ) && isset( $step->on_error ) ) {
				$push( $step->on_error, $path . '/on_error' );
			}
			if ( isset( $step->choices ) && is_array( $step->choices ) ) {
				foreach ( $step->choices as $j => $ch ) {
					if ( is_object( $ch ) && isset( $ch->goto ) ) {
						$push( $ch->goto, $path . '/choices/' . $j . '/goto' );
					}
				}
			} elseif ( isset( $step->choices ) && is_object( $step->choices ) ) {
				if ( isset( $step->choices->goto ) ) {
					$push( $step->choices->goto, $path . '/choices/goto' );
				}
				if ( isset( $step->choices->extra ) && is_array( $step->choices->extra ) ) {
					foreach ( $step->choices->extra as $j => $ch ) {
						if ( is_object( $ch ) && isset( $ch->goto ) ) {
							$push( $ch->goto, $path . '/choices/extra/' . $j . '/goto' );
						}
					}
				}
			}
			if ( isset( $step->row_action ) && is_object( $step->row_action ) && isset( $step->row_action->goto ) ) {
				$push( $step->row_action->goto, $path . '/row_action/goto' );
			}
			if ( isset( $step->form ) && is_object( $step->form ) && isset( $step->form->cancel ) && is_object( $step->form->cancel ) && isset( $step->form->cancel->goto ) ) {
				$push( $step->form->cancel->goto, $path . '/form/cancel/goto' );
			}
			if ( isset( $step->config_read ) && is_object( $step->config_read ) && isset( $step->config_read->on_error ) ) {
				$push( $step->config_read->on_error, $path . '/config_read/on_error' );
			}
			if ( isset( $step->config_write ) && is_object( $step->config_write ) && isset( $step->config_write->on_error ) ) {
				$push( $step->config_write->on_error, $path . '/config_write/on_error' );
			}
		}
		return $errs;
	}

	/**
	 * E_REF_PERMISSION＝config_read / config_write の category（custom_data は store）が
	 * permissions に宣言済みか（JS 側 checkPermissions と同一）。
	 *
	 * @param mixed $data stdClass tree.
	 * @return array<array>
	 */
	private static function check_permissions( $data ) {
		$errs  = array();
		$perms = ( is_object( $data ) && isset( $data->permissions ) && is_object( $data->permissions ) ) ? $data->permissions : new \stdClass();
		$declared = function ( $op ) use ( $perms ) {
			return ( isset( $perms->$op ) && is_array( $perms->$op ) ) ? $perms->$op : array();
		};
		$verify = function ( $def, $op, $path ) use ( &$errs, $declared ) {
			if ( ! is_object( $def ) || ! isset( $def->category ) || ! is_string( $def->category ) ) {
				return;
			}
			// custom_data permission target is the store name.
			$need = ( 'custom_data' === $def->category ) ? ( isset( $def->store ) ? $def->store : null ) : $def->category;
			if ( 'custom_data' === $def->category && ! is_string( $need ) ) {
				return; // schema reports missing store
			}
			if ( ! in_array( $need, $declared( $op ), true ) ) {
				$errs[] = self::ref_error(
					'E_REF_PERMISSION',
					$path,
					$op . ' requires permissions.' . $op . ' to declare "' . $need . '" (category ' . $def->category . ')'
				);
			}
		};
		foreach ( self::steps( $data ) as $pair ) {
			list( $step, $path ) = $pair;
			if ( isset( $step->config_read ) ) {
				$verify( $step->config_read, 'config_read', $path . '/config_read' );
			}
			if ( isset( $step->config_write ) ) {
				$verify( $step->config_write, 'config_write', $path . '/config_write' );
			}
		}
		return $errs;
	}

	/**
	 * 宣言済み変数の集合（JS 側 collectDeclaredVars と**同じ収集順**）。
	 * ★順序も合わせる理由＝did_you_mean は候補列の先頭勝ち（同点のとき）なので、順が違うと
	 *   JS と別の候補が出る。
	 *
	 * @param mixed $data stdClass tree.
	 * @return array<string> 宣言名の並び（重複なし）.
	 */
	private static function collect_declared_vars( $data ) {
		$vars = array();
		$add  = function ( $name ) use ( &$vars ) {
			if ( is_string( $name ) && ! isset( $vars[ $name ] ) ) {
				$vars[ $name ] = true;
			}
		};
		if ( is_object( $data ) && isset( $data->vars ) && is_object( $data->vars ) ) {
			foreach ( array_keys( get_object_vars( $data->vars ) ) as $k ) {
				$add( (string) $k );
			}
		}
		if ( is_object( $data ) && isset( $data->data_sources ) && is_object( $data->data_sources ) ) {
			foreach ( get_object_vars( $data->data_sources ) as $d ) {
				if ( ! is_object( $d ) ) {
					continue;
				}
				if ( isset( $d->into ) && is_string( $d->into ) ) {
					$add( $d->into );
				}
				if ( isset( $d->transform ) && is_array( $d->transform ) ) {
					foreach ( $d->transform as $t ) {
						if ( ! is_object( $t ) ) {
							continue;
						}
						if ( isset( $t->set_var ) && is_string( $t->set_var ) ) {
							$add( $t->set_var );
						}
						if ( isset( $t->calc ) && is_string( $t->calc ) && isset( $t->scope ) && 'global' === $t->scope ) {
							$add( $t->calc );
						}
					}
				}
			}
		}
		foreach ( self::steps( $data ) as $pair ) {
			$step = $pair[0];
			if ( isset( $step->form ) && is_object( $step->form ) && isset( $step->form->fields ) && is_array( $step->form->fields ) ) {
				foreach ( $step->form->fields as $f ) {
					if ( is_object( $f ) && isset( $f->key ) && is_string( $f->key ) ) {
						$add( $f->key );
					}
				}
			}
			if ( isset( $step->set ) && is_object( $step->set ) ) {
				foreach ( array_keys( get_object_vars( $step->set ) ) as $k ) {
					$add( (string) $k );
				}
			}
			// Issue #1543 (B-6): if の then / else の set も変数を宣言する（set step と同じ）。
			foreach ( array( 'then', 'else' ) as $bk ) {
				if ( isset( $step->$bk ) && is_object( $step->$bk ) && isset( $step->$bk->set ) && is_object( $step->$bk->set ) ) {
					foreach ( array_keys( get_object_vars( $step->$bk->set ) ) as $k ) {
						$add( (string) $k );
					}
				}
			}
			if ( isset( $step->choices ) && is_array( $step->choices ) ) {
				foreach ( $step->choices as $ch ) {
					if ( is_object( $ch ) && isset( $ch->set ) && is_object( $ch->set ) ) {
						foreach ( array_keys( get_object_vars( $ch->set ) ) as $k ) {
							$add( (string) $k );
						}
					}
				}
			}
			if ( isset( $step->choices ) && is_object( $step->choices ) && isset( $step->choices->set ) && is_object( $step->choices->set ) ) {
				foreach ( array_keys( get_object_vars( $step->choices->set ) ) as $k ) {
					$add( (string) $k );
				}
			}
			if ( isset( $step->row_action ) && is_object( $step->row_action ) && isset( $step->row_action->set ) && is_object( $step->row_action->set ) ) {
				foreach ( array_keys( get_object_vars( $step->row_action->set ) ) as $k ) {
					$add( (string) $k );
				}
			}
			if ( isset( $step->config_read ) && is_object( $step->config_read ) && isset( $step->config_read->into ) && is_string( $step->config_read->into ) ) {
				$into = $step->config_read->into;
				$add( $into );
				if ( isset( $step->config_read->category ) && 'goals' === $step->config_read->category ) {
					$add( $into . '_count' );
					$add( $into . '_next_id' );
					$add( $into . '_is_max' );
				}
			}
			if ( isset( $step->config_write ) && is_object( $step->config_write ) && isset( $step->config_write->into ) && is_string( $step->config_write->into ) ) {
				$add( $step->config_write->into );
			}
		}
		return array_keys( $vars );
	}

	/**
	 * E_REF_VAR / E_REF_VAR_UNDEFINED / E_REF_ROW_SCOPE＝$var / $sys.* / $row.* の参照検査
	 * （JS 側 checkVarRefs と同一）。3つの見方＝
	 *  1. `/transform/N/expr` の中の `$token`（式は識別子を含むので $ 付きだけ見る）
	 *  2. 値全体が "$..." の直接参照（resolveValue 形）
	 *  3. 文字列中のテンプレート `{$x}`（式の中は対象外）
	 *
	 * @param mixed $data stdClass tree.
	 * @return array<array>
	 */
	private static function check_var_refs( $data ) {
		$errs     = array();
		$declared = self::collect_declared_vars( $data );
		$has      = function ( $name ) use ( $declared ) {
			return in_array( $name, $declared, true );
		};
		$strings = array();
		self::deep_strings( $data, '', $strings );

		foreach ( $strings as $pair ) {
			list( $str, $path ) = $pair;

			// 1. transform.calc / set_var の expr＝算術式。$ 付きトークンだけ検査する（$ 無しの識別子は
			//    QAL 列名でここでは検査できない）。data-sources.md §4.7。
			if ( 1 === preg_match( '#/transform/\d+/expr$#', $path ) ) {
				if ( preg_match_all( '/\$([a-zA-Z0-9_.]+)/', $str, $mm ) ) {
					foreach ( $mm[1] as $ref ) {
						if ( 0 === strpos( $ref, 'sys.' ) ) {
							$k = substr( $ref, 4 );
							if ( ! in_array( $k, self::SYS_VARS, true ) ) {
								$errs[] = self::ref_error( 'E_REF_VAR_UNDEFINED', $path, 'unknown system variable "$' . $ref . '" in expr' );
							}
						} elseif ( 0 === strpos( $ref, 'row.' ) ) {
							$errs[] = self::ref_error( 'E_REF_ROW_SCOPE', $path, '$row.* is not valid in expr ("$' . $ref . '")' );
						} else {
							$parts = explode( '.', $ref );
							$base  = $parts[0];
							if ( ! $has( $ref ) && ! $has( $base ) ) {
								$errs[] = self::ref_error( 'E_REF_VAR_UNDEFINED', $path, 'expr references undeclared variable "$' . $ref . '"', self::closest( $base, $declared ) );
							}
						}
					}
				}
				continue;
			}

			// 2. 直接参照（resolveValue 形）＝値全体が "$..."
			if ( '' !== $str && '$' === $str[0] ) {
				if ( 0 === strpos( $str, '$sys.' ) ) {
					$k = substr( $str, 5 );
					if ( ! in_array( $k, self::SYS_VARS, true ) ) {
						$errs[] = self::ref_error( 'E_REF_VAR_UNDEFINED', $path, 'unknown system variable "' . $str . '" (allowed: $sys.' . implode( ' / $sys.', self::SYS_VARS ) . ')' );
					}
				} elseif ( 0 === strpos( $str, '$row.' ) ) {
					// $row.* は「クリック／反復中の行」がスコープにある場所でだけ解決される＝
					// 動的 choices の set（/choices/set/<key>）と table の row_action.set（/row_action/set/<key>）。
					// 静的 choices（/choices/<index>/set/...）は resolveValue 経由で $row を解決しない＝誤用。
					$in_dynamic_choices_set = ( 1 === preg_match( '#/choices/set/[^/]+$#', $path ) );
					$in_row_action_set      = ( 1 === preg_match( '#/row_action/set/[^/]+$#', $path ) );
					if ( ! $in_dynamic_choices_set && ! $in_row_action_set ) {
						$errs[] = self::ref_error( 'E_REF_ROW_SCOPE', $path, '$row.* is only valid inside a dynamic choices "set" or a table row_action "set" ("' . $str . '")' );
					}
				} else {
					$name = substr( $str, 1 );
					if ( ! $has( $name ) ) {
						$is_source_like = ( 1 === preg_match( '#/source$|/from_data$#', $path ) );
						$errs[]         = self::ref_error(
							$is_source_like ? 'E_REF_VAR' : 'E_REF_VAR_UNDEFINED',
							$path,
							'reference to undeclared variable "' . $str . '" (declare it in vars)',
							self::closest( $name, $declared )
						);
					}
				}
			}

			// 3. メッセージ等の文字列中のテンプレート参照 {$var}
			if ( false !== strpos( $str, '{$' ) && preg_match_all( self::template_regex(), $str, $mm, PREG_SET_ORDER ) ) {
				foreach ( $mm as $hit ) {
					$ref = $hit[1];
					if ( 0 === strpos( $ref, 'sys.' ) ) {
						$k = substr( $ref, 4 );
						if ( ! in_array( $k, self::SYS_VARS, true ) ) {
							$errs[] = self::ref_error( 'E_REF_VAR_UNDEFINED', $path, 'unknown system variable "{$' . $ref . '}"' );
						}
					} else {
						$parts = explode( '.', $ref );
						$base  = $parts[0];
						if ( ! $has( $ref ) && ! $has( $base ) ) {
							$errs[] = self::ref_error( 'E_REF_VAR_UNDEFINED', $path, 'template references undeclared variable "{$' . $ref . '}"', self::closest( $base, $declared ) );
						}
					}
				}
			}
		}
		return $errs;
	}

	/**
	 * E_REF_VAR_UNDEFINED＝if.var（$ 無しの生の変数名）が宣言済みか（JS 側 checkIfVars と同一）。
	 * runtime は this.vars[var] を直接読むので typo は黙って空になる＝捕まえる。`all` の要素も対象。
	 *
	 * @param mixed $data stdClass tree.
	 * @return array<array>
	 */
	private static function check_if_vars( $data ) {
		$errs     = array();
		$declared = self::collect_declared_vars( $data );
		$check    = function ( $cond, $path ) use ( &$errs, $declared ) {
			if ( is_object( $cond ) && isset( $cond->var ) && is_string( $cond->var ) && ! in_array( $cond->var, $declared, true ) ) {
				$errs[] = self::ref_error( 'E_REF_VAR_UNDEFINED', $path, 'if.var references undeclared variable "' . $cond->var . '"', self::closest( $cond->var, $declared ) );
			}
		};
		foreach ( self::steps( $data ) as $pair ) {
			list( $step, $p ) = $pair;
			if ( ! isset( $step->if ) || ! is_object( $step->if ) ) {
				continue;
			}
			$cond = $step->if;
			if ( isset( $cond->all ) && is_array( $cond->all ) ) {
				foreach ( $cond->all as $i => $sub ) {
					$check( $sub, $p . '/if/all/' . $i . '/var' );
				}
				continue;
			}
			$check( $cond, $p . '/if/var' );
		}
		return $errs;
	}

	/**
	 * E_REF_LOOKUP＝transform.lookup が manifest.lookups に存在するか（JS 側 checkLookups と同一）。
	 *
	 * @param mixed $data stdClass tree.
	 * @return array<array>
	 */
	private static function check_lookups( $data ) {
		$errs  = array();
		$names = self::section_keys( $data, 'lookups' );
		foreach ( self::transforms( $data ) as $tr ) {
			list( $dk, $i, $t ) = $tr;
			if ( isset( $t->lookup ) && is_string( $t->lookup ) && ! in_array( $t->lookup, $names, true ) ) {
				$errs[] = self::ref_error(
					'E_REF_LOOKUP',
					'/data_sources/' . $dk . '/transform/' . $i . '/lookup',
					'lookup references undefined lookups entry "' . $t->lookup . '"',
					self::closest( $t->lookup, $names )
				);
			}
		}
		return $errs;
	}

	/**
	 * E_REF_TRANSLATION＝`t:` キーが翻訳（merged 1枚）に実在するか（JS 側 checkTranslations と同一）。
	 * 翻訳が無ければ検査しない（JS の「lang 無し＝skip」と同じ縮退）。
	 *
	 * @param mixed      $data   stdClass tree.
	 * @param mixed|null $merged 翻訳の merged 1枚（stdClass）。null＝翻訳なし.
	 * @return array<array>
	 */
	private static function check_translations( $data, $merged ) {
		$errs = array();
		if ( null === $merged ) {
			return $errs;
		}
		$strings = array();
		self::deep_strings( $data, '', $strings );
		foreach ( $strings as $pair ) {
			list( $str, $path ) = $pair;
			if ( 0 === strpos( $str, 't:' ) ) {
				$key = substr( $str, 2 );
				if ( ! self::resolve_translation_key( $merged, $key ) ) {
					$errs[] = self::ref_error( 'E_REF_TRANSLATION', $path, 'translation key "' . $str . '" not found in lang file' );
				}
			}
		}
		return $errs;
	}

	/**
	 * ドット区切りキーを翻訳ツリーで辿り、末端が文字列なら true（JS 側 resolveTranslationKey と同一）。
	 *
	 * @param mixed  $translations stdClass tree.
	 * @param string $key          ドット区切りキー.
	 * @return bool
	 */
	private static function resolve_translation_key( $translations, $key ) {
		$cur = $translations;
		foreach ( explode( '.', $key ) as $p ) {
			if ( is_object( $cur ) && property_exists( $cur, $p ) ) {
				$cur = $cur->$p;
			} elseif ( is_array( $cur ) && array_key_exists( $p, $cur ) ) {
				$cur = $cur[ $p ];
			} else {
				return false;
			}
		}
		return is_string( $cur );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Issue #1581: 翻訳（lang/*.json）の読み込み＝**PHP 側の唯一の正本**。
	// JS 側 validator/load-translations.js と同じ約束＝
	//   - by_locale … lang/ 配下の locale 形ファイルを**全部**（en/ja 決め打ちにしない）
	//   - merged    … en → ja の順に deep merge（後が勝つ＝従来どおり）。en/ja が無ければ在るものを union
	// ★読み込みロジックを他所に複製しないこと（複製が en.json を検査から落としていた＝PR #1576 🔴-1）。
	// ─────────────────────────────────────────────────────────────────────────

	/** merged を作る locale と順序（後が勝つ）。JS 側 LOCALES と同一。 */
	const LOCALES = array( 'en', 'ja' );

	/**
	 * プラグインディレクトリから翻訳を読む。
	 *
	 * @param string $dir プラグインディレクトリ（`lang/` を含みうる）.
	 * @return array|null { merged: stdClass, by_locale: array<string,stdClass> }。lang/*.json が1つも無ければ null.
	 */
	public static function load_translations( $dir ) {
		$lang_dir  = rtrim( (string) $dir, '/\\' ) . '/lang';
		$by_locale = array();
		if ( ! is_dir( $lang_dir ) ) {
			return null;
		}
		$names = scandir( $lang_dir );
		if ( ! is_array( $names ) ) {
			return null;
		}
		sort( $names, SORT_STRING );
		foreach ( $names as $name ) {
			// JS 側 LOCALE_FILE と同一＝`ja.json` / `en.json` / `pt_BR.json` 等。設定ファイル等は拾わない。
			if ( 1 !== preg_match( '/^([a-z]{2}(?:[_-][A-Za-z0-9]{2,8})?)\.json$/', $name, $m ) ) {
				continue;
			}
			if ( ! is_file( $lang_dir . '/' . $name ) || ! is_readable( $lang_dir . '/' . $name ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled read-only lang file of an assistant plugin.
			$json    = file_get_contents( $lang_dir . '/' . $name );
			$decoded = json_decode( (string) $json );
			if ( ! is_object( $decoded ) ) {
				// 壊れた／空の翻訳ファイルは「無い」扱い（JS 側は JSON.parse で throw＝呼び出し側で止まる。
				// PHP の配信経路では翻訳の破損で全体を止めない＝縮退して manifest 検査は続ける）。
				continue;
			}
			$by_locale[ $m[1] ] = $decoded;
		}
		if ( empty( $by_locale ) ) {
			return null;
		}
		$merged = null;
		foreach ( self::LOCALES as $loc ) {
			if ( ! isset( $by_locale[ $loc ] ) ) {
				continue;
			}
			$merged = ( null === $merged ) ? $by_locale[ $loc ] : self::deep_merge( $merged, $by_locale[ $loc ] );
		}
		// en/ja が1つも無く他 locale だけが在る構成でも `t:` 検査を素通りさせない
		if ( null === $merged ) {
			foreach ( $by_locale as $obj ) {
				$merged = ( null === $merged ) ? $obj : self::deep_merge( $merged, $obj );
			}
		}
		return array(
			'merged'    => $merged,
			'by_locale' => $by_locale,
		);
	}

	/**
	 * オブジェクト同士を deep merge する（後が勝つ）。JS 側 deepMerge と同一（両方がオブジェクトのときだけ再帰）。
	 *
	 * @param mixed $a 先.
	 * @param mixed $b 後（勝つ）.
	 * @return object
	 */
	private static function deep_merge( $a, $b ) {
		$out = new \stdClass();
		if ( is_object( $a ) ) {
			foreach ( get_object_vars( $a ) as $k => $v ) {
				$out->$k = $v;
			}
		}
		if ( is_object( $b ) ) {
			foreach ( get_object_vars( $b ) as $k => $v ) {
				if ( '__proto__' === $k || 'constructor' === $k || 'prototype' === $k ) {
					continue;
				}
				if ( is_object( $v ) && isset( $out->$k ) && is_object( $out->$k ) ) {
					$out->$k = self::deep_merge( $out->$k, $v );
				} else {
					$out->$k = $v;
				}
			}
		}
		return $out;
	}

	/**
	 * 翻訳を「locale ごとに1つずつ」の並び [locale, stdClass] にする（JS 側 translationSources と同一）。
	 * `by_locale` があればそれを、無ければ `merged` を locale 不明の1枚として使う（単体テスト互換）。
	 *
	 * @param array|null $translations validate() の第2引数.
	 * @return array<array{0:string,1:object}>
	 */
	private static function translation_sources( $translations ) {
		if ( ! is_array( $translations ) ) {
			return array();
		}
		$out = array();
		if ( isset( $translations['by_locale'] ) && is_array( $translations['by_locale'] ) ) {
			foreach ( $translations['by_locale'] as $loc => $obj ) {
				if ( $obj ) {
					$out[] = array( (string) $loc, self::to_object( $obj ) );
				}
			}
			return $out;
		}
		if ( isset( $translations['merged'] ) && $translations['merged'] ) {
			$out[] = array( '', self::to_object( $translations['merged'] ) );
		}
		return $out;
	}

	/**
	 * manifest 本体＋翻訳（locale ごと）の文字列を1本の列にする（JS 側 allTemplateStrings と同一）。
	 * 翻訳側の path は `lang/<locale>/...`（locale 不明なら `lang/...`）＝どのファイルを直せばよいかが分かる。
	 *
	 * @param mixed $data    stdClass tree.
	 * @param array $sources translation_sources() の戻り値.
	 * @return array<array{0:string,1:string}>
	 */
	private static function all_template_strings( $data, $sources ) {
		$out = array();
		self::deep_strings( $data, '', $out );
		foreach ( (array) $sources as $src ) {
			list( $locale, $obj ) = $src;
			$t = array();
			self::deep_strings( $obj, '', $t );
			$prefix = ( '' !== $locale ) ? 'lang/' . $locale : 'lang';
			foreach ( $t as $pair ) {
				$out[] = array( $pair[0], $prefix . $pair[1] );
			}
		}
		return $out;
	}

	/**
	 * Issue #1575 (B-5): テンプレートの文法。
	 *
	 * ★この3つは src/core/js/qahm-assistant-runtime.js の expandTemplate と
	 *   src/core/assistant-schema/validator/ref-checks.js の TEMPLATE_SRC / FORMAT_ARITY と
	 *   **同一**に保つこと（片方だけ新形を知っていると、検査が甘い側から素通りする）。
	 *
	 * @return string
	 */
	private static function template_regex() {
		return '/\{\$([a-zA-Z0-9_.]+)(?:\|([a-zA-Z_]+)(?::(-?\d+|"(?:[^"\\\\]|\\\\.)*"))?)?\}/';
	}

	/**
	 * 「テンプレートに見えるもの」を広く拾う網（near-miss 検出用）。
	 *
	 * @return string
	 */
	private static function template_loose_regex() {
		return '/\{\$[^{}]{0,200}\}?/';
	}

	/**
	 * エラーメッセージに載せる断片を安全にする（Issue #1575 / PR #1576 レビュー 🟡-2）.
	 *
	 * near-miss の網の上限 `{0,200}` は **バイト単位**（`/u` を付けていない＝JS 側の
	 * UTF-16 単位とは意味が違う）。日本語のような多バイト文字列では、拾った断片が
	 * **文字の途中で切れる**ことがある。この断片は `ajax_get_assistant_manifest` の
	 * 400 応答（`json_encode`）に載るため、不正な UTF-8 が混ざると**エラー内容ごと
	 * 応答から落ちる**＝「何が悪いのか」が伝わらなくなる。
	 *
	 * ★検出ロジックには触らない（span 判定はバイトオフセットで行うので元から正しい）。
	 *   **表示に出す直前で整えるだけ**にしてあるのは、副作用を最小にするため。
	 *
	 * @param string $s 断片.
	 * @return string 妥当な UTF-8 に整えた断片.
	 */
	private static function safe_snippet( $s ) {
		// 末尾の不完全なシーケンスを1バイトずつ削り、妥当な UTF-8 になったところで止める.
		while ( '' !== $s && 1 !== preg_match( '//u', $s ) ) {
			$s = substr( $s, 0, -1 );
		}
		return $s;
	}

	/**
	 * Issue #1577（第3弾①・A-2 / B-4）: 計算式（calc / set_var の expr）を runtime と同じ字句規則で読み、
	 * 文法エラーを返す（無ければ null）。
	 *
	 * ★字句規則は次の3つと同一に保つこと:
	 *   - src/core/js/qahm-assistant-runtime.js  "Expression parser"（tokenize / parsePrimary）
	 *   - src/core/assistant-schema/validator/ref-checks.js  lintExpression()
	 * 片方だけ広いと「配布できたのに画面で空になる」か「正しい式が配布できない」のどちらかになる。
	 * 突合＝tasks/issue-1577-expr-fail-loud/php-parity-test.php。
	 *
	 * @param string $expr 式.
	 * @return array{msg:?string,at:int,has_field:bool,has_var:bool}
	 */
	private static function lint_expression( $expr ) {
		$s         = (string) $expr;
		$len       = strlen( $s );
		$i         = 0;
		$depth     = 0;
		$has_field = false;
		$has_var   = false;
		$prev      = 'start'; // start | value | op | open
		$aggs      = array( 'sum', 'avg', 'count', 'count_distinct', 'min', 'max', 'first' );
		$err       = function ( $msg ) use ( &$i ) {
			return array( 'msg' => $msg, 'at' => $i, 'has_field' => false, 'has_var' => false );
		};
		while ( $i < $len ) {
			$ch = $s[ $i ];
			if ( ' ' === $ch ) { $i++; continue; }
			if ( '(' === $ch ) {
				if ( 'value' === $prev ) { return $err( 'missing operator before "("' ); }
				$depth++; $prev = 'open'; $i++; continue;
			}
			if ( ')' === $ch ) {
				$depth--;
				if ( $depth < 0 ) { return $err( 'unmatched ")"' ); }
				if ( 'value' !== $prev ) { return $err( 'empty parentheses or dangling operator before ")"' ); }
				$prev = 'value'; $i++; continue;
			}
			if ( '+' === $ch || '*' === $ch || '/' === $ch ) {
				if ( 'value' !== $prev ) { return $err( 'operator "' . $ch . '" without a left operand' ); }
				$prev = 'op'; $i++; continue;
			}
			if ( '-' === $ch ) { $prev = 'op'; $i++; continue; }
			if ( 1 === preg_match( '/[0-9.]/', $ch ) ) {
				if ( 'value' === $prev ) { return $err( 'missing operator before number' ); }
				$num = '';
				while ( $i < $len && 1 === preg_match( '/[0-9.]/', $s[ $i ] ) ) { $num .= $s[ $i ]; $i++; }
				if ( 1 !== preg_match( '/^(\d+\.?\d*|\.\d+)$/', $num ) ) {
					return array( 'msg' => 'malformed number "' . $num . '"', 'at' => $i - strlen( $num ), 'has_field' => false, 'has_var' => false );
				}
				$prev = 'value'; continue;
			}
			if ( 1 === preg_match( '/[a-zA-Z_]/', $ch ) ) {
				if ( 'value' === $prev ) { return $err( 'missing operator before identifier' ); }
				$id = '';
				while ( $i < $len && 1 === preg_match( '/[a-zA-Z0-9_]/', $s[ $i ] ) ) { $id .= $s[ $i ]; $i++; }
				$j = $i;
				while ( $j < $len && ' ' === $s[ $j ] ) { $j++; }
				if ( $j < $len && '(' === $s[ $j ] ) {
					$at = $i - strlen( $id );
					if ( in_array( $id, $aggs, true ) ) {
						return array( 'msg' => 'aggregate "' . $id . '(...)" cannot be mixed into an expression - use a pure aggregate like "' . $id . '(field)" as the whole expression, or compute with $var references', 'at' => $at, 'has_field' => false, 'has_var' => false );
					}
					return array( 'msg' => 'unknown function "' . $id . '(" (the expression engine has no functions)', 'at' => $at, 'has_field' => false, 'has_var' => false );
				}
				$has_field = true; $prev = 'value'; continue;
			}
			if ( '$' === $ch ) {
				if ( 'value' === $prev ) { return $err( 'missing operator before "$"' ); }
				$i++;
				$v = '';
				while ( $i < $len && 1 === preg_match( '/[a-zA-Z0-9_.]/', $s[ $i ] ) ) { $v .= $s[ $i ]; $i++; }
				if ( '' === $v ) { return $err( '"$" must be followed by a variable name' ); }
				$has_var = true; $prev = 'value'; continue;
			}
			// 走査はバイト単位なので $ch は多バイト文字の1バイト目だけ＝そのまま載せると safe_snippet で
			// 削られて「unexpected character ""」になる（PR #1579 レビュー ⚪-1）。1文字ぶん取り出して載せる
			// （日本語環境で最も起きやすい式のタイポは全角演算子＝何が悪いか言えないと直せない）。
			$one = '';
			if ( 1 === preg_match( '/^./us', substr( $s, $i ), $mm ) ) {
				$one = $mm[0];
			}
			return $err( 'unexpected character "' . self::safe_snippet( '' !== $one ? $one : $ch ) . '"' );
		}
		if ( $depth > 0 ) { return array( 'msg' => 'missing closing parenthesis', 'at' => $len, 'has_field' => false, 'has_var' => false ); }
		if ( 'value' !== $prev ) { return array( 'msg' => 'expression ends with an operator or is empty', 'at' => $len, 'has_field' => false, 'has_var' => false ); }
		return array( 'msg' => null, 'at' => $len, 'has_field' => $has_field, 'has_var' => $has_var );
	}

	/**
	 * Issue #1577: calc / set_var の expr を配信前に検査する（E_REF_EXPR_SYNTAX）.
	 *  (a) 文法エラー（未知文字・括弧不整合・演算子の連続・壊れた数値・関数呼び出し）
	 *  (b) set_var / calc scope:"global" で行フィールドを含む式＝先頭行の値で評価される形（B-4）
	 *
	 * @param mixed $data stdClass tree.
	 * @return array<array>
	 */
	private static function check_expressions( $data ) {
		$errors = array();
		if ( ! is_object( $data ) || ! isset( $data->data_sources ) || ! is_object( $data->data_sources ) ) {
			return $errors;
		}
		foreach ( get_object_vars( $data->data_sources ) as $dk => $ds ) {
			if ( ! is_object( $ds ) || ! isset( $ds->transform ) || ! is_array( $ds->transform ) ) {
				continue;
			}
			foreach ( $ds->transform as $i => $step ) {
				if ( ! is_object( $step ) || ! isset( $step->expr ) || ! is_string( $step->expr ) ) {
					continue;
				}
				$is_calc    = isset( $step->calc );
				$is_set_var = isset( $step->set_var );
				if ( ! $is_calc && ! $is_set_var ) {
					continue;
				}
				$path      = '/data_sources/' . $dk . '/transform/' . $i . '/expr';
				$src       = trim( $step->expr );
				$is_global = $is_set_var || ( isset( $step->scope ) && 'global' === $step->scope );
				// 純集計形は式エンジンを通らない＝global スコープでだけ OK。行スコープ（calc の既定）に書くと
				// runtime は行ごとに評価して必ず失敗する（PR #1579 レビュー 🟡-1）＝案内して止める。JS と同一判定。
				if ( 1 === preg_match( '/^(sum|avg|count|count_distinct|min|max|first)\(([a-zA-Z_][a-zA-Z0-9_]*)\)$/', $src ) ) {
					if ( $is_global ) {
						continue;
					}
					$errors[] = array(
						'code'    => 'E_REF_EXPR_SYNTAX',
						'path'    => $path,
						'message' => 'aggregate "' . self::safe_snippet( $step->expr ) . '" in a row-scoped calc - it would run per row and fail. Add "scope": "global" to aggregate over all rows, or use set_var',
					);
					continue;
				}
				$r = self::lint_expression( $src );
				if ( null !== $r['msg'] ) {
					$errors[] = array(
						'code'    => 'E_REF_EXPR_SYNTAX',
						'path'    => $path,
						'message' => 'invalid expression "' . self::safe_snippet( $step->expr ) . '": ' . $r['msg'] . ' at ' . $r['at'] . ' (the runtime would show it as empty)',
					);
					continue;
				}
				if ( $is_global && $r['has_field'] ) {
					$errors[] = array(
						'code'    => 'E_REF_EXPR_SYNTAX',
						'path'    => $path,
						'message' => 'global expression "' . self::safe_snippet( $step->expr ) . '" references row fields - it would silently use the first row only. Use a pure aggregate (sum(field) etc.) or $var references (e.g. "$total / $count * 100")',
					);
				}
			}
		}
		return $errors;
	}

	/**
	 * 指定子 → 引数の要求。0=引数を取らない / 1=整数引数（任意）/ 2=文字列引数（必須）.
	 *
	 * @return array<string,int>
	 */
	private static function format_arity() {
		return array(
			'integer'    => 0,
			'float'      => 0,
			'percentage' => 0,
			'duration'   => 0,
			'round'      => 1,
			'before'     => 2,
			'after'      => 2,
		);
	}

	/**
	 * 木の中の文字列を全部集める（値のみ）.
	 *
	 * @param mixed  $node  対象.
	 * @param string $path  JSON ポインタ.
	 * @param array  $out   収集先（参照）.
	 * @return void
	 */
	private static function deep_strings( $node, $path, &$out ) {
		if ( is_string( $node ) ) {
			$out[] = array( $node, $path );
			return;
		}
		if ( is_array( $node ) ) {
			foreach ( $node as $i => $v ) {
				self::deep_strings( $v, $path . '/' . $i, $out );
			}
			return;
		}
		if ( is_object( $node ) ) {
			foreach ( get_object_vars( $node ) as $k => $v ) {
				self::deep_strings( $v, $path . '/' . $k, $out );
			}
		}
	}

	/**
	 * Issue #1575: 新しい書式指定子（round / before / after）の使用箇所を1つ返す.
	 *
	 * Issue #1581 で対象を **manifest 本体＋翻訳（locale ごと）** に広げた（JS 側
	 * findFormatSpecifierUse と同じ）。実測では書式指定子つきテンプレの大半が lang 側にある
	 * ため、manifest だけ見ると「新指定子を使っているのに min_core_version を要求しない」穴が
	 * 空く（PR #1576 レビュー 🔴-1 の型）。
	 *
	 * @param mixed $data    stdClass tree.
	 * @param array $sources 翻訳の並び（translation_sources() の戻り値）.
	 * @return string|null
	 */
	private static function find_format_specifier_use( $data, $sources = array() ) {
		$new     = array( 'round', 'before', 'after' );
		$strings = self::all_template_strings( $data, $sources );
		foreach ( $strings as $pair ) {
			list( $str, $path ) = $pair;
			if ( false === strpos( $str, '{$' ) ) {
				continue;
			}
			if ( preg_match_all( self::template_regex(), $str, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $hit ) {
					if ( isset( $hit[2] ) && '' !== $hit[2] && in_array( $hit[2], $new, true ) ) {
						return $path . ' (format specifier "' . $hit[2] . '")';
					}
				}
			}
		}
		return null;
	}

	/**
	 * Issue #1575: テンプレートの書き間違いを配信前に止める（E_REF_TEMPLATE_SYNTAX）.
	 *
	 * 2形態を見る:
	 *  (a) 文法に一致しない … 展開されず、波かっこごと画面に出る
	 *  (b) 文法には一致するが指定子が使えない … 黙って未整形のまま出る
	 * どちらも「画面には出るが正しくない」＝声を出さない失敗なので配信前に弾く。
	 *
	 * 対象＝manifest 本体＋翻訳（locale ごと・path は `lang/<locale>/...`）。Issue #1581 で JS 側
	 * checkTemplateSyntax と同じ範囲に広げた（1枚に潰すと en.json の値が検査に届かないため locale ごと）。
	 *
	 * @param mixed $data    stdClass tree.
	 * @param array $sources 翻訳の並び（translation_sources() の戻り値）.
	 * @return array<array>
	 */
	private static function check_template_syntax( $data, $sources = array() ) {
		$errors  = array();
		$arity   = self::format_arity();
		$strings = self::all_template_strings( $data, $sources );

		foreach ( $strings as $pair ) {
			list( $str, $path ) = $pair;
			if ( false === strpos( $str, '{$' ) ) {
				continue;
			}

			// 「文法に一致した範囲」を持つ（一致した文字列そのものでは足りない）。
			// 網は [^{}] で止まるので、区切りに { や } を含む正しい書き方では網のほうが
			// 短く切れる＝文字列比較だと**正しい manifest を弾く誤検知**になる。
			$spans = array();
			$canon = array();
			if ( preg_match_all( self::template_regex(), $str, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				foreach ( $matches as $hit ) {
					$spans[] = array( $hit[0][1], $hit[0][1] + strlen( $hit[0][0] ) );
					$canon[] = array(
						'text' => $hit[0][0],
						'name' => isset( $hit[2] ) && -1 !== $hit[2][1] ? $hit[2][0] : null,
						'arg'  => isset( $hit[3] ) && -1 !== $hit[3][1] ? $hit[3][0] : null,
					);
				}
			}

			// (a) 文法に一致しない
			if ( preg_match_all( self::template_loose_regex(), $str, $loose, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				foreach ( $loose as $hit ) {
					$inside = false;
					foreach ( $spans as $span ) {
						if ( $hit[0][1] >= $span[0] && $hit[0][1] < $span[1] ) {
							$inside = true;
							break;
						}
					}
					if ( $inside ) {
						continue;
					}
					$errors[] = array(
						'code'    => 'E_REF_TEMPLATE_SYNTAX',
						'path'    => $path,
						// 断片は多バイト文字の途中で切れうる＝応答へ載せる前に整える（safe_snippet の docblock 参照）.
						'message' => 'malformed template "' . self::safe_snippet( $hit[0][0] ) . '" - it is not expanded and would be printed literally (an argument must be an integer or a double-quoted string)',
					);
				}
			}

			// (b) 文法には一致するが指定子が使えない
			foreach ( $canon as $c ) {
				if ( null === $c['name'] ) {
					continue;
				}
				if ( ! array_key_exists( $c['name'], $arity ) ) {
					// Issue #1581: did_you_mean も JS 側 checkTemplateSyntax と揃える（対称化の突合で見つかった差）.
					$errors[] = self::ref_error(
						'E_REF_TEMPLATE_SYNTAX',
						$path,
						'unknown format specifier "' . $c['name'] . '" in "' . $c['text'] . '" (the value would be printed unformatted)',
						self::closest( $c['name'], array_keys( $arity ) )
					);
					continue;
				}
				$need     = $arity[ $c['name'] ];
				$has_arg  = null !== $c['arg'];
				$isquoted = $has_arg && '"' === substr( $c['arg'], 0, 1 );
				if ( 0 === $need && $has_arg ) {
					$errors[] = array(
						'code'    => 'E_REF_TEMPLATE_SYNTAX',
						'path'    => $path,
						'message' => 'format specifier "' . $c['name'] . '" takes no argument (in "' . $c['text'] . '")',
					);
				} elseif ( 1 === $need ) {
					if ( $isquoted ) {
						$errors[] = array(
							'code'    => 'E_REF_TEMPLATE_SYNTAX',
							'path'    => $path,
							'message' => '"' . $c['name'] . '" expects an integer number of decimals, not a string (in "' . $c['text'] . '")',
						);
					} elseif ( $has_arg && ( (int) $c['arg'] < 0 || (int) $c['arg'] > 6 ) ) {
						$errors[] = array(
							'code'    => 'E_REF_TEMPLATE_SYNTAX',
							'path'    => $path,
							'message' => '"' . $c['name'] . '" accepts 0-6 decimals (got ' . (int) $c['arg'] . ' in "' . $c['text'] . '")',
						);
					}
				} elseif ( 2 === $need ) {
					if ( ! $has_arg ) {
						$errors[] = array(
							'code'    => 'E_REF_TEMPLATE_SYNTAX',
							'path'    => $path,
							'message' => '"' . $c['name'] . '" requires a quoted separator (in "' . $c['text'] . '")',
						);
					} elseif ( ! $isquoted ) {
						$errors[] = array(
							'code'    => 'E_REF_TEMPLATE_SYNTAX',
							'path'    => $path,
							'message' => '"' . $c['name'] . '" needs its separator in double quotes (in "' . $c['text'] . '")',
						);
					} elseif ( strlen( $c['arg'] ) <= 2 ) {
						$errors[] = array(
							'code'    => 'E_REF_TEMPLATE_SYNTAX',
							'path'    => $path,
							'message' => '"' . $c['name'] . '" needs a non-empty separator (in "' . $c['text'] . '")',
						);
					}
				}
			}
		}
		return $errors;
	}

	/**
	 * "$var" / "$sys.*" 参照かどうか（JS 側 isVarRef と同一判定）。
	 *
	 * @param mixed $v value.
	 * @return bool
	 */
	private static function is_var_ref( $v ) {
		return is_string( $v ) && '' !== $v && '$' === $v[0];
	}

	/**
	 * Normalize input to a stdClass tree (justinrainbow needs objects, not assoc arrays).
	 *
	 * @param array|object|string $manifest input.
	 * @return mixed
	 */
	private static function to_object( $manifest ) {
		if ( is_string( $manifest ) ) {
			return json_decode( $manifest );
		}
		if ( is_array( $manifest ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- internal re-decode of already-parsed data, not output.
			return json_decode( json_encode( $manifest ) );
		}
		return $manifest;
	}

	/**
	 * justinrainbow errors -> structured E_SCHEMA_* list.
	 *
	 * oneOf failures are collapsed to one error per failing container (step /
	 * transform / choices), suppressing the per-branch discrimination noise that
	 * justinrainbow emits for every branch. This mirrors the JS adapter's intent;
	 * the server only needs a correct reject + a de-noised reason, while rich
	 * per-field guidance is the JS generation-time validator's job.
	 *
	 * @param array $raw  justinrainbow error list.
	 * @param mixed $data validated data (for resolving enum values).
	 * @return array
	 */
	private static function adapt( $raw, $data ) {
		// 1) collect oneOf container pointers.
		$one_of = array();
		foreach ( $raw as $e ) {
			if ( 'oneOf' === self::cname( $e ) ) {
				$one_of[] = isset( $e['pointer'] ) ? $e['pointer'] : '';
			}
		}
		$one_of = array_values( array_unique( $one_of ) );

		// 2) top-level oneOf pointers = those not nested under another oneOf.
		$top = array();
		foreach ( $one_of as $p ) {
			$nested = false;
			foreach ( $one_of as $q ) {
				if ( $q !== $p && '' !== $q && 0 === strpos( $p, $q . '/' ) ) {
					$nested = true;
					break;
				}
			}
			if ( ! $nested ) {
				$top[] = $p;
			}
		}

		$out     = array();
		$emitted = array();
		foreach ( $raw as $e ) {
			$name = self::cname( $e );
			$ptr  = isset( $e['pointer'] ) ? $e['pointer'] : '';
			$path = '' === $ptr ? '(root)' : $ptr;

			if ( 'oneOf' === $name ) {
				if ( in_array( $ptr, $top, true ) && ! isset( $emitted[ $ptr ] ) ) {
					$emitted[ $ptr ] = true;
					$out[]           = array(
						'code'    => 'E_SCHEMA_ONEOF',
						'path'    => $path,
						'message' => 'must match exactly one allowed shape (step / transform / choices type). Check the discriminating key and that no unknown keys are present.',
					);
				}
				continue;
			}
			// suppress every error inside a oneOf subtree (branch noise).
			if ( self::in_subtree( $ptr, $one_of ) ) {
				continue;
			}

			$err = array(
				'code'    => self::keyword_code( $name ),
				'path'    => $path,
				'message' => isset( $e['message'] ) ? $e['message'] : 'schema violation',
			);
			if ( 'enum' === $name ) {
				$allowed = isset( $e['constraint']['params']['enum'] ) ? $e['constraint']['params']['enum'] : array();
				$actual  = self::resolve_pointer( $data, $ptr );
				if ( is_string( $actual ) && ! empty( $allowed ) ) {
					$dym = self::closest( $actual, array_map( 'strval', $allowed ) );
					if ( null !== $dym ) {
						$err['did_you_mean'] = $dym;
					}
				}
			}
			$out[] = $err;
		}
		return $out;
	}

	/**
	 * Extract a justinrainbow error's constraint name (6.x nests it in an array).
	 *
	 * @param array $e error.
	 * @return string
	 */
	private static function cname( $e ) {
		if ( ! isset( $e['constraint'] ) ) {
			return '';
		}
		if ( is_array( $e['constraint'] ) ) {
			return isset( $e['constraint']['name'] ) ? $e['constraint']['name'] : '';
		}
		return $e['constraint'];
	}

	/**
	 * Is $ptr equal to, or nested under, any pointer in $paths?
	 *
	 * @param string $ptr   JSON pointer.
	 * @param array  $paths pointers.
	 * @return bool
	 */
	private static function in_subtree( $ptr, $paths ) {
		foreach ( $paths as $q ) {
			if ( $ptr === $q || ( '' !== $q && 0 === strpos( $ptr, $q . '/' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve a JSON pointer against a decoded data tree.
	 *
	 * @param mixed  $data data tree.
	 * @param string $ptr  JSON pointer (e.g. /tables/x/columns/0/type).
	 * @return mixed|null
	 */
	private static function resolve_pointer( $data, $ptr ) {
		if ( '' === $ptr ) {
			return $data;
		}
		$segments = explode( '/', ltrim( $ptr, '/' ) );
		$cur      = $data;
		foreach ( $segments as $seg ) {
			$seg = str_replace( array( '~1', '~0' ), array( '/', '~' ), $seg );
			if ( is_object( $cur ) && isset( $cur->{$seg} ) ) {
				$cur = $cur->{$seg};
			} elseif ( is_array( $cur ) && isset( $cur[ (int) $seg ] ) ) {
				$cur = $cur[ (int) $seg ];
			} else {
				return null;
			}
		}
		return $cur;
	}

	/**
	 * Closest candidate by Levenshtein distance, threshold scaled to length.
	 * Mirrors error-adapter.js closest().
	 *
	 * @param string $target     misspelled value.
	 * @param array  $candidates allowed values.
	 * @return string|null
	 */
	private static function closest( $target, $candidates ) {
		$best      = null;
		$best_dist = PHP_INT_MAX;
		foreach ( $candidates as $c ) {
			$d = levenshtein( $target, $c );
			if ( $d < $best_dist ) {
				$best_dist = $d;
				$best      = $c;
			}
		}
		if ( null === $best ) {
			return null;
		}
		$threshold = max( 2, (int) ceil( strlen( $target ) / 3 ) );
		return $best_dist <= $threshold ? $best : null;
	}
}
