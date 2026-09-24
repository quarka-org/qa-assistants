/**
 * QAHM HTML Sanitizer (Issue #1426)
 *
 * 会話本文の html step（runtime）と表の列 type:"html"（qa-table）が共有する、
 * ただ一つの HTML サニタイズ実装。「許可基準は1箇所（2入口・1実体）」を保つため、
 * DOMPurify の config・許可 class・専用インスタンス・フックをここに集約する。
 * ロジックは #1424（qa-table 内実装）からの移設で、挙動は同一。
 *
 * 使い方:  var safe = qahm.sanitizeHtml( untrustedHtmlString );
 *   依存: DOMPurify（先に読み込むこと）。qa-table を単体で持ち出して列 type:"html" を HTML 描画
 *   したい場合は、qa-table.js に加えて本ファイルと DOMPurify を同梱する（無ければ escape 劣化）。
 *
 * 安全モデル:
 *   - 生 HTML を通すのはこの関数だけ。許可タグ/属性/class を allowlist で列挙し、
 *     その他はデフォルト拒否（data-* / aria-* も明示 off）。
 *   - class は明示 enum の生存トークンで再構築（混在偽装を確実に落とす）。
 *   - a には target=_blank / rel=noopener を強制（既存 link 型と同じ出口）。
 *   - DOMPurify 未ロード時は textContent→innerHTML の escape に安全劣化（＋warn once）。
 *     これにより qa-table を単体で外部へ持ち出し、このヘルパも DOMPurify も無い環境でも
 *     throw せず「type:html だけ escape 表示・他 type は無依存動作」を保てる（呼び出し側の
 *     `if (window.qahm && qahm.sanitizeHtml)` ガードと合わせて自己完結）。
 *
 * @since 1.0.0
 */

var qahm = qahm || {};

(function() {
    'use strict';

    // href 以外の全属性値にも適用される仕様のため、DOMPurify 既定の尾部（非 URI 値と相対 URL を
    // 通す alternation）を維持したまま scheme を https?/mailto に絞る。scheme 部だけの素朴な
    // 正規表現にすると colspan="2" 等まで剥がれて表が黙って壊れる。
    var PURIFY_CONFIG = {
        ALLOWED_TAGS: ['div', 'span', 'p', 'br', 'hr', 'ul', 'ol', 'li', 'b', 'strong', 'i', 'em', 'u', 's',
                       'small', 'sub', 'sup', 'code', 'pre', 'h3', 'h4', 'a', 'table', 'thead', 'tbody', 'tr', 'th', 'td'],
        ALLOWED_ATTR: ['href', 'target', 'rel', 'colspan', 'rowspan', 'class'],
        ALLOWED_URI_REGEXP: /^(?:(?:https?|mailto):|[^a-z]|[a-z+.\-]+(?:[^a-z+.\-:]|$))/i,
        // ALLOWED_ATTR を明示しても data-* / aria-* は既定で全通過するため、明示的に閉じる
        // （allowlist ＝「許可を列挙・その他は拒否」を data-*/aria-* にも成立させる）。
        ALLOW_DATA_ATTR: false,
        ALLOW_ARIA_ATTR: false
    };

    // class 属性は明示 enum のみ許可（QA の意味色部品を html 内から安全に再利用するための語彙）。
    // プレフィクス許可（qa-*）にすると qa-table の機能クラス（qa-total-row 等）の偽装を許すため enum 固定。
    // is-good/mid/bad/info は意味色 level 語彙＝schema の level enum・adapter の ALLOWED_LEVELS と
    // 三点一致が規約（assistant-schema/test/vocab-check.js が機械検査・#1438）。
    // is-note だけは level ではなく「callout 無指定時のニュートラル（blocks.js フォールバック）＋
    // 会話 html 用の文章装飾」クラスで、意図的にここ（と blocks.js）のみに存在する
    // （vocab-check の許容例外リストに収載済み＝無断ドリフトだけを検知する）。
    var ALLOWED_CLASSES = [
        'qa-callout', 'qa-callout__body', 'qa-callout__title', 'qa-callout__text',
        'qa-stat', 'qa-stat__label', 'qa-stat__value', 'qa-stat__unit',
        'qa-badge', 'qa-divider',
        'is-good', 'is-mid', 'is-bad', 'is-info', 'is-note'
    ];

    // 専用 DOMPurify インスタンス（遅延生成・1回だけ）。addHook はシングルトンへのグローバル登録に
    // なる仕様のため、DOMPurify(window) で専用インスタンスを作り、フックの効果をこの用途に
    // 恒久隔離する（素の DOMPurify を将来使う別コードへ波及させない）。
    var purifyInstance = null;
    var warned = false;

    function getPurify() {
        if ( purifyInstance ) return purifyInstance;
        if ( typeof DOMPurify === 'undefined' ) return null;
        var purify = DOMPurify( window );
        purify.addHook( 'afterSanitizeAttributes', function( node ) {
            // a は既存 link 型と同じ出口規律に揃える
            if ( node.tagName === 'A' && node.hasAttribute( 'href' ) ) {
                node.setAttribute( 'target', '_blank' );
                node.setAttribute( 'rel', 'noopener noreferrer' );
            }
            // class は生存トークンで再構築する（「許可外が混ざっていたら属性ごと削除」の条件式だと
            // 許可クラスとの混在で偽装クラスが生き残るため、削除条件でなく再構築で確実に落とす）
            if ( node.hasAttribute( 'class' ) ) {
                var survivors = node.getAttribute( 'class' ).split( /\s+/ )
                    .filter( function( c ) { return ALLOWED_CLASSES.indexOf( c ) !== -1; } );
                if ( survivors.length ) {
                    node.setAttribute( 'class', survivors.join( ' ' ) );
                } else {
                    node.removeAttribute( 'class' );
                }
            }
        } );
        purifyInstance = purify;
        return purify;
    }

    /**
     * Sanitize an untrusted HTML string into safe HTML (or escaped text if DOMPurify absent).
     *
     * @param {string} value  Untrusted HTML string.
     * @return {string} Safe HTML markup, or escaped plain text as fallback.
     */
    qahm.sanitizeHtml = function( value ) {
        var str = String( value );
        var purify = getPurify();
        if ( purify ) {
            return purify.sanitize( str, PURIFY_CONFIG );
        }
        // DOMPurify が無い場合は escape に落とす（安全側フォールバック）。
        // 無音だと「html を書いたのに素の文字列が出る」の調査に迷うため warn を1回だけ。
        if ( ! warned && typeof console !== 'undefined' && console.warn ) {
            warned = true;
            console.warn( '[qahm] sanitizeHtml requires DOMPurify; value rendered as escaped text.' );
        }
        var tempDiv = document.createElement( 'div' );
        tempDiv.textContent = str;
        return tempDiv.innerHTML;
    };

})();
