/**
 * QAHM Assistant Conversation Exporter (Issue #1535)
 *
 * 「この会話を保存」— 会話 DOM のスナップショット＋実行メタ（期間・入力・版）を
 * 単一 HTML ファイルとしてダウンロードする（Blob 方式・サーバー保存なし）。
 *
 * 設計原則（第1段・記録型＝忠実スナップショット。2026-07-31 今井方針「画面ごと保存」で改訂）:
 * - 「再生」はしない。保存物は静的 HTML ＝ manifest / エンジンがどの版になっても読める。
 * - DOM はアシスタント画面ブロック（.qahm-assistant-container＝キャラ画像・talk-box ヘッダー帯込み）を
 *   丸ごと clone し、実ページの祖先チェーン（#class-qahm-admin-page-assistant まで）ごと保存する
 *   ＝合成ラッパーで再構成しない（「画面ごと保存」の要件・コスギさん 7/17）。
 * - CSS はページ上の全 stylesheet（<style> と <link>）を文書順に丸ごと取り込む。
 *   許可リスト方式をやめる＝「選び漏れ＝表示崩れ」という故障モードを構造的に消す。
 *   fetch できないシート（CORS 不許可の CDN 等）は絶対 URL の <link> として残す縮退
 *   ＝オンラインで開けば効く・オフラインではそのシートだけ素落ち。
 * - JavaScript は一切入れない（保存直前の描画済み DOM を撮るので見た目に JS は不要。
 *   実行コードを同梱しない＝共有されるファイルとして安全・エンジン版依存も持ち込まない）。
 * - メタの期間・入力は runtime.runLog（fetch/form の append-only ログ）から採る。
 *   保存時点の vars を使わない（会話中の条件変更で上書きされ取り違えるため）。
 *   可視メタは折りたたみ（<details>）＝画面の再現を主役にする。機械可読 JSON 島は従来どおり。
 * - グラフ（ECharts canvas）は保存直前に getDataURL で <img> へ差し替える
 *   （前例＝qa-media-dashboard の PDF 出力）。canvas は DOM シリアライズに載らない。
 * - 画像（キャラ画像等）は fetch→data URL で埋め込む（失敗・巨大時は絶対 URL 縮退）。
 * - JSON 埋め込み島は '<' を \u003c にエスケープ（</script> 破壊封じ・JSON.parse で完全に戻る）。
 * - エクスポート失敗は会話を壊さない（すべて内部 catch・ボタン近傍と console に出すだけ）。
 *
 * ボタンは manifest launcher（assistant-ai-manifest.js）だけが attach() を呼ぶ
 * ＝ legacy Brains 4種の会話には出ない（メタ無しファイルを作らせない）。
 *
 * Design spec: docs/specs/assistant/overview.md「会話の保存（HTML エクスポート）」
 *
 * @since Issue #1535
 */

var qahm = qahm || {};

(function() {
    'use strict';

    // 記録形式そのものの版（メタ JSON に埋める）。形式を変えるときに上げる。
    var SCHEMA_VERSION = '1.0';

    // 文言の集約（AssistantRuntime.ERROR_MESSAGES と同格の暫定＝本格 i18n は将来。
    // 1箇所に集約しておけば移行が1箇所で済む）。
    var LABELS = {
        button: 'この会話を保存',
        saving: '保存中…',
        failed: '保存に失敗しました。もう一度お試しください。',
        doc_note: 'このファイルは QA アシスタントの会話を保存した静的な記録です（再実行はできません）。機械可読メタはファイル末尾の JSON にあります。',
        meta_summary: '保存時の記録メタ（期間・入力・バージョン）',
        head_assistant: 'アシスタント',
        head_saved_at: '保存日時',
        head_site: 'サイト',
        head_engine: 'エンジン',
        head_periods: '分析期間',
        head_inputs: '入力内容',
        none: '—'
    };

    // CSS は許可リストで選別しない（2026-07-31 忠実スナップショット化で撤廃）。
    // ページ上の全 stylesheet を文書順に取り込む＝collectPageCss() 参照。
    // 画像を data URL 埋め込みする際のサイズ上限（超過は絶対 URL のまま＝ファイル肥大の抑制）。
    var IMG_INLINE_MAX_BYTES = 2 * 1024 * 1024;

    // ─── 純関数（DOM 非依存・node で単体テスト可能） ─────────────────────────

    /**
     * HTML テキストノード / 属性値用のエスケープ。
     */
    function escapeHtml( str ) {
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' )
            .replace( /'/g, '&#039;' );
    }

    /**
     * <script type="application/json"> 島に埋めるための JSON エスケープ。
     * JSON 構文自体に '<' は現れない（文字列リテラル内のみ）ため、< 置換は
     * JSON として合法のまま `</script>`・`<!--` の両終端誘発を封じる。JSON.parse で完全に戻る。
     * U+2028/U+2029 も併せて置換（将来 JS 文字列へ流用されても安全なように）。
     */
    function escapeJsonIsland( jsonStr ) {
        return String( jsonStr )
            .replace( /</g, '\\u003c' )
            .replace( /\u2028/g, '\\u2028' )
            .replace( /\u2029/g, '\\u2029' );
    }

    /**
     * CSS テキスト内の相対 url(...) を取得元 href 基準の絶対 URL へ書き換える（専属レビュー 🟡-1）。
     * 保存 HTML は元ページと別の場所（ローカルファイル等）で開かれる＝相対参照
     * （@font-face の '../fonts/…' 等）は解決不能になるため。data: / http(s): / '//' は触らない。
     * 解決に失敗した参照はそのまま残す（当該資産が欠けるだけ・保存自体は続行）。
     */
    function absolutizeCssUrls( cssText, baseHref ) {
        return String( cssText ).replace( /url\(\s*(['"]?)([^'")]+)\1\s*\)/g, function( whole, quote, ref ) {
            if ( /^(data:|https?:|\/\/|#)/i.test( ref ) ) {
                return whole;
            }
            try {
                return "url('" + new URL( ref, baseHref ).href + "')";
            } catch ( e ) {
                return whole;
            }
        } );
    }

    /**
     * CSS テキスト内の文字列形 @import（url() を使わない形）を取得元基準の絶対 URL へ書き換える。
     * url() 形の @import は absolutizeCssUrls が処理する。fetch の再帰はしない
     * （絶対 URL 化＝オンラインで開けば解決する、で十分と割り切る）。
     */
    function absolutizeCssImports( cssText, baseHref ) {
        return String( cssText ).replace( /@import\s+(['"])([^'"]+)\1/g, function( whole, quote, ref ) {
            if ( /^(data:|https?:|\/\/)/i.test( ref ) ) {
                return whole;
            }
            try {
                return "@import url('" + new URL( ref, baseHref ).href + "')";
            } catch ( e ) {
                return whole;
            }
        } );
    }

    /**
     * 生成する CSS コメント（/* … *​/）に外部由来の値を入れるための無害化。
     * コメント終端（*​/）と山括弧を落とす＝コメントを閉じさせない・タグに見せない。
     */
    function sanitizeCssComment( str ) {
        return String( str || '' ).replace( /\*\//g, '' ).replace( /[<>]/g, '' );
    }

    /**
     * media 属性付きシートの中身を @media でくるむ（文書上の適用条件を保存物でも維持）。
     * media 値はページ上の任意ノード由来＝ブロック構造を壊しうる文字（{ } ; < >）と
     * コメント終端を落としてから使う（残りは media クエリとして妥当な文字だけ）。
     * 落とした結果が空になったらラップしない（＝全メディア扱い＝実画面と同じ側に倒す）。
     */
    function wrapCssMedia( cssText, media ) {
        var raw = String( media || '' ).trim();
        var m = raw.replace( /[{};<>]/g, '' ).replace( /\*\//g, '' ).trim();
        // 危険文字が実際に含まれていた＝想定外の値。無理に整形して不正な media クエリを作ると
        // そのシートの CSS が丸ごと不適用になる（黙って見た目が壊れる）ので、ラップしない側
        // ＝「常に適用」に倒す（実画面と同じかそれより広いだけ・注入は起きない）。
        if ( ! m || m !== raw || 'all' === m.toLowerCase() ) {
            return cssText;
        }
        return '@media ' + m + ' {\n' + cssText + '\n}';
    }

    /**
     * 収集したシート断片（文書順）を head 用 HTML にする。
     * **1シート＝1つの `<style>`** で出す（＋fetch できなかったシートは同じ位置に `<link>`）。
     * こうすると (a) 実画面のカスケード順がそのまま保たれる〔縮退が混ざっても入れ替わらない〕
     * (b) シート先頭の `@import` が「連結後に無効な位置」へ落ちない（専属レビュー ⚪-3/⚪-5）。
     * fragments = [ {kind:'css', text} | {kind:'link', href, media} ] の配列。
     */
    function buildCssHeadHtml( fragments ) {
        var out = [];
        for ( var i = 0; i < ( fragments || [] ).length; i++ ) {
            var f = fragments[i];
            if ( ! f ) continue;
            if ( 'link' === f.kind && f.href ) {
                out.push( '<link rel="stylesheet" href="' + escapeHtml( f.href ) + '"' +
                    ( f.media ? ' media="' + escapeHtml( f.media ) + '"' : '' ) + '>' );
            } else if ( 'css' === f.kind && f.text ) {
                out.push( '<style>\n' + f.text + '\n</style>' );
            }
        }
        return out.join( '\n' );
    }

    /**
     * タイムゾーンオフセット付きのローカル ISO 文字列（例: 2026-07-30T18:22:33+09:00）。
     */
    function localIsoString( d ) {
        function p2( n ) { return ( n < 10 ? '0' : '' ) + n; }
        var off = -d.getTimezoneOffset();
        var sign = off >= 0 ? '+' : '-';
        var abs = Math.abs( off );
        return d.getFullYear() + '-' + p2( d.getMonth() + 1 ) + '-' + p2( d.getDate() ) +
            'T' + p2( d.getHours() ) + ':' + p2( d.getMinutes() ) + ':' + p2( d.getSeconds() ) +
            sign + p2( Math.floor( abs / 60 ) ) + ':' + p2( abs % 60 );
    }

    /**
     * DL ファイル名。slug は英数とハイフンへ縮退（Blob DL なのでサーバー制約はないが安全側）。
     */
    function buildFileName( slug, d ) {
        function p2( n ) { return ( n < 10 ? '0' : '' ) + n; }
        var safeSlug = String( slug || 'assistant' ).toLowerCase().replace( /[^a-z0-9_-]+/g, '-' ).replace( /^-+|-+$/g, '' ) || 'assistant';
        // アシスタント plugin slug は 'qa-assistant-' 接頭が慣例＝そのまま前置すると
        // 'qa-assistant-qa-assistant-…' に二重化するため、一度だけ剥がす（Copilot 指摘）。
        // 素の 'qa-assistant'（サニタイズで末尾ハイフンが落ちた形）も同扱い。
        if ( safeSlug.indexOf( 'qa-assistant-' ) === 0 ) {
            safeSlug = safeSlug.slice( 'qa-assistant-'.length ) || 'assistant';
        } else if ( 'qa-assistant' === safeSlug ) {
            safeSlug = 'assistant';
        }
        var stamp = d.getFullYear() + p2( d.getMonth() + 1 ) + p2( d.getDate() ) + '-' + p2( d.getHours() ) + p2( d.getMinutes() );
        return 'qa-assistant-' + safeSlug + '-' + stamp + '.html';
    }

    /**
     * runLog から人間可読サマリを作る。
     * periods: fetch クエリを deep 走査して {start,end} を持つ time オブジェクトを全部拾い、重複除去。
     * inputs:  form エントリの values を時系列のまま列挙。
     */
    function summarizeRunLog( runLog ) {
        var periods = [];
        var seen = {};
        var inputs = [];
        if ( ! Array.isArray( runLog ) ) {
            return { periods: periods, inputs: inputs };
        }
        function walk( node ) {
            if ( ! node || typeof node !== 'object' ) return;
            if ( ! Array.isArray( node ) &&
                typeof node.start === 'string' && typeof node.end === 'string' &&
                node.start.length > 0 && node.end.length > 0 ) {
                var key = node.start + '/' + node.end;
                if ( ! seen[ key ] ) {
                    seen[ key ] = true;
                    periods.push( { start: node.start, end: node.end } );
                }
            }
            var keys = Object.keys( node );
            for ( var i = 0; i < keys.length; i++ ) {
                walk( node[ keys[i] ] );
            }
        }
        for ( var i = 0; i < runLog.length; i++ ) {
            var entry = runLog[i];
            if ( ! entry || typeof entry !== 'object' ) continue;
            if ( entry.kind === 'fetch' && entry.data && entry.data.query ) {
                walk( entry.data.query );
            } else if ( entry.kind === 'form' && entry.data && entry.data.values && typeof entry.data.values === 'object' ) {
                inputs.push( entry.data.values );
            }
        }
        return { periods: periods, inputs: inputs };
    }

    /**
     * 記録メタ（機械可読 JSON）を組み立てる。
     * opts = { slug, assistantName, manifestVersion, specVersion, pluginVersion, productType,
     *          trackingId, siteLabel, wpSiteUrl, savedAt(Date), runLog }
     */
    function buildMeta( opts ) {
        return {
            schema_version: SCHEMA_VERSION,
            saved_at: localIsoString( opts.savedAt ),
            assistant: {
                slug: String( opts.slug || '' ),
                name: String( opts.assistantName || '' ),
                manifest_version: String( opts.manifestVersion || '' )
            },
            engine: {
                spec_version: String( opts.specVersion || '' ),
                plugin_version: String( opts.pluginVersion || '' ),
                product_type: String( opts.productType || '' )
            },
            site: {
                tracking_id: String( opts.trackingId || '' ),
                site_label: String( opts.siteLabel || '' ),
                wp_site_url: String( opts.wpSiteUrl || '' )
            },
            run_log: Array.isArray( opts.runLog ) ? opts.runLog : []
        };
    }

    /**
     * 記録メタブロック（1行ノート＋折りたたみ表）の HTML を組み立てる。値はすべてエスケープ。
     * 画面の再現を主役にするため、表は <details> に畳む（忠実スナップショット化・2026-07-31）。
     */
    function buildMetaBlockHtml( meta, summary ) {
        var rows = [];
        function row( label, valueHtml ) {
            rows.push( '<tr><th>' + escapeHtml( label ) + '</th><td>' + valueHtml + '</td></tr>' );
        }
        var asst = meta.assistant.name || meta.assistant.slug;
        var asstDetail = meta.assistant.slug + ( meta.assistant.manifest_version ? ' v' + meta.assistant.manifest_version : '' );
        row( LABELS.head_assistant, escapeHtml( asst ) + ' <span class="qahm-rh-sub">(' + escapeHtml( asstDetail ) + ')</span>' );
        row( LABELS.head_saved_at, escapeHtml( meta.saved_at ) );
        var site = meta.site.site_label
            ? meta.site.site_label + ' (' + meta.site.tracking_id + ')'
            : ( meta.site.tracking_id || LABELS.none );
        row( LABELS.head_site, escapeHtml( site ) );
        var product = meta.engine.product_type === 'zero' ? 'QA ZERO' : ( meta.engine.product_type === 'wp' ? 'QA Assistants' : '' );
        var engine = ( product ? product + ' ' : '' ) + ( meta.engine.plugin_version || '' ) +
            ( meta.engine.spec_version ? ' / spec ' + meta.engine.spec_version : '' );
        row( LABELS.head_engine, escapeHtml( engine || LABELS.none ) );
        var periodsHtml = LABELS.none;
        if ( summary.periods.length > 0 ) {
            var pp = [];
            for ( var i = 0; i < summary.periods.length; i++ ) {
                pp.push( escapeHtml( summary.periods[i].start + ' 〜 ' + summary.periods[i].end ) );
            }
            periodsHtml = pp.join( '<br>' );
        }
        row( LABELS.head_periods, periodsHtml );
        var inputsHtml = LABELS.none;
        if ( summary.inputs.length > 0 ) {
            var lines = [];
            for ( var f = 0; f < summary.inputs.length; f++ ) {
                var values = summary.inputs[f];
                var keys = Object.keys( values );
                for ( var k = 0; k < keys.length; k++ ) {
                    lines.push( escapeHtml( keys[k] + ': ' + String( values[ keys[k] ] ) ) );
                }
            }
            inputsHtml = lines.join( '<br>' );
        }
        row( LABELS.head_inputs, inputsHtml );
        // 「どの期間で」は要件が名指しする一次情報＝折りたたみの外に常時表示する
        // （専属レビュー ⚪-4＝フォームを使わない manifest では開かないと分からなくなるため）。
        var periodLine = '';
        if ( summary.periods.length > 0 ) {
            var pl = [];
            for ( var q = 0; q < summary.periods.length; q++ ) {
                pl.push( escapeHtml( summary.periods[q].start + ' 〜 ' + summary.periods[q].end ) );
            }
            periodLine = '<p class="qahm-rh-period"><b>' + escapeHtml( LABELS.head_periods ) + '</b>: ' +
                pl.join( ' / ' ) + '</p>';
        }
        return '<div class="qahm-rh-head">' +
            '<p class="qahm-rh-note">' + escapeHtml( LABELS.doc_note ) + '</p>' +
            periodLine +
            '<details class="qahm-rh-details"><summary>' + escapeHtml( LABELS.meta_summary ) + '</summary>' +
            '<table class="qahm-rh-meta">' + rows.join( '' ) + '</table>' +
            '</details>' +
            '</div>';
    }

    // 保存 HTML 専用の追加スタイル。「ライブ画面専用の仕掛け」だけを打ち消す最小セット
    // （忠実スナップショット化＝見た目のアレンジはしない。2026-07-31）。
    // - dialogue-box の ::after 80vh は「最後のメッセージを画面上端へ運ぶ」ためのライブ画面用の
    //   スクロール余地＝静的記録では末尾の巨大な白紙にしかならないため打ち消す。
    // - 同じく内部スクロール枠は「内容ぶんだけ伸びる」形へ（会話全体が1枚で読める記録にする）。
    // - 表の操作 UI は隠さない（disabled で「そのまま・動かない」を見せる＝画面ごと保存の方針）。
    var EXPORT_STYLE =
        'body.qahm-rh{margin:0;padding:24px;background:#f0f0f1}' +
        '.qahm-rh-head{max-width:1200px;margin:0 auto 16px;background:#fff;border:1px solid #d5d9dd;border-radius:8px;padding:12px 16px}' +
        '.qahm-rh-note{font-size:12px;color:#666;margin:0}' +
        '.qahm-rh-period{font-size:13px;color:#333;margin:6px 0 0}' +
        '.qahm-rh-details{margin-top:8px}' +
        '.qahm-rh-details summary{cursor:pointer;font-size:12px;color:#555}' +
        '.qahm-rh-meta{border-collapse:collapse;font-size:13px;width:100%;margin-top:8px}' +
        '.qahm-rh-meta th{text-align:left;white-space:nowrap;padding:4px 14px 4px 0;color:#555;font-weight:600;vertical-align:top}' +
        '.qahm-rh-meta td{padding:4px 0;word-break:break-all}' +
        '.qahm-rh-sub{color:#888;font-size:12px}' +
        // 実画面 CSS は #class-qahm-admin-page-assistant スコープ＋クラス3連鎖＝specificity が高い。
        // 上書きは !important で確実に勝たせる（セルフレビュー ⚪-4＝id を足すだけでは実 CSS の
        // クラス3連鎖に負ける）。レイアウトは #wpbody-content（flex 親・保存物には無い）前提の
        // flex/overflow が付いているため、内容高で伸びる形へ整える。
        'body.qahm-rh #class-qahm-admin-page-assistant .qahm-assistant-container{overflow:visible !important}' +
        'body.qahm-rh #class-qahm-admin-page-assistant .qahm-assistant-dialogue-box{overflow:visible !important;max-height:none !important;height:auto !important;flex:none !important}' +
        'body.qahm-rh #class-qahm-admin-page-assistant .qahm-assistant-dialogue-box::after{height:0 !important;content:none !important}' +
        'body.qahm-rh img.qahm-rh-chart{max-width:100%}';

    /**
     * 保存 HTML 全体を組み立てる。
     * opts = { lang, title, cssHeadHtml, metaBlockHtml, screenHtml, bodyClass, metaJson(オブジェクト) }
     * cssHeadHtml は buildCssHeadHtml が組んだ「文書順の <style>／<link> 群」。
     * screenHtml は実ページの祖先チェーン込みの画面ブロック（snapshotScreen が組む）。
     * bodyClass は実ページの body クラス（wp-admin 等）＝body 起点のセレクタも保存物で効かせる。
     */
    function buildExportHtml( opts ) {
        var metaIsland = escapeJsonIsland( JSON.stringify( opts.metaJson, null, 1 ) );
        var bodyClass = 'qahm-rh' + ( opts.bodyClass ? ' ' + String( opts.bodyClass ) : '' );
        return '<!DOCTYPE html>\n' +
            '<html lang="' + escapeHtml( opts.lang || 'ja' ) + '">\n' +
            '<head>\n' +
            '<meta charset="UTF-8">\n' +
            '<meta name="viewport" content="width=device-width, initial-scale=1.0">\n' +
            '<meta name="robots" content="noindex">\n' +
            '<title>' + escapeHtml( opts.title || '' ) + '</title>\n' +
            // 収集したシート（文書順・1シート=1 <style>／fetch 不可のものは同じ位置に <link>）
            ( opts.cssHeadHtml ? opts.cssHeadHtml + '\n' : '' ) +
            // 保存物専用の上書きは最後＝実画面 CSS より後に置いて確実に勝たせる
            '<style>' + EXPORT_STYLE + '</style>\n' +
            '</head>\n' +
            '<body class="' + escapeHtml( bodyClass ) + '">\n' +
            opts.metaBlockHtml + '\n' +
            opts.screenHtml + '\n' +
            '<script type="application/json" id="qahm-run-history-meta">\n' + metaIsland + '\n</' + 'script>\n' +
            '</body>\n</html>\n';
    }

    // ─── DOM 依存部 ──────────────────────────────────────────────────────────

    /**
     * ページ上の全 stylesheet を文書順に収集する（忠実スナップショット・許可リスト廃止）。
     * - <style>（WP core / 他プラグインのインラインスタイル含む）＝ textContent をそのまま取り込む。
     * - <link rel="stylesheet">＝ href を fetch してインライン化。
     *   **CDN の判定は origin 比較ではなく「fetch を試みて失敗したら縮退」**＝同一オリジンは必ず
     *   成功・CORS を許可しているクロスオリジンも自動でインライン化でき、判定ロジック自体を持たない。
     *   失敗したシートだけ絶対 URL の <link> として**同じ位置に**残す（オンラインで開けば効く）。
     * - いずれも url() / @import を取得元基準で絶対化し、'</' をエスケープして <style> 島脱出を封じる。
     * - media 属性は @media ラップで維持。disabled なシートは実画面同様に取り込まない。
     * - **戻り値は文書順の断片配列**＝出力も1シート=1 `<style>`（縮退は同位置の `<link>`）で、
     *   実画面のカスケード順を保存物でもそのまま保つ（専属レビュー ⚪-3/⚪-5）。
     * 個別失敗は保存を止めない（「CSS が一部欠けた記録」の方が「保存できない」より価値がある）。
     *
     * @returns {Promise<Array<{kind:'css',text:string}|{kind:'link',href:string,media:string}>>}
     */
    function collectPageCss( doc ) {
        var jobs = [];
        var sheets = doc.styleSheets || [];
        for ( var i = 0; i < sheets.length; i++ ) {
            var sheet = sheets[i];
            var node = sheet && sheet.ownerNode;
            if ( ! node || sheet.disabled ) continue;
            var media = ( node.getAttribute && node.getAttribute( 'media' ) ) || '';
            // '</' → '<\/' は**最終文字列**（コメントヘッダ・@media ラップ連結後）に掛ける＝
            // node.id / media 属性（他プラグイン由来の任意値）経由の <style> 島脱出も封じる
            // （セルフレビュー第2ラウンド 🟡-1。自前生成部に '</' は現れないため無害）。
            if ( 'STYLE' === node.tagName ) {
                var text = absolutizeCssImports( absolutizeCssUrls( node.textContent || '', doc.baseURI ), doc.baseURI );
                jobs.push( Promise.resolve( { kind: 'css', text: wrapCssMedia(
                    '/* style' + ( node.id ? ' id=' + sanitizeCssComment( node.id ) : '' ) + ' */\n' + text, media )
                    .replace( /<\//g, '<\\/' ) } ) );
            } else if ( 'LINK' === node.tagName && node.href ) {
                (function( href, media ) {
                    jobs.push(
                        fetch( href, { credentials: 'same-origin' } )
                            .then( function( res ) {
                                if ( ! res.ok ) { throw new Error( 'HTTP ' + res.status ); }
                                return res.text();
                            } )
                            // 相対 url(...) / @import を取得元基準で絶対化（フォント等が保存物でも解決できるように）
                            .then( function( text ) { return absolutizeCssImports( absolutizeCssUrls( text, href ), href ); } )
                            .then( function( text ) {
                                return { kind: 'css', text: wrapCssMedia(
                                    '/* ' + sanitizeCssComment( href.split( '?' )[0].split( '/' ).pop() ) + ' */\n' + text, media )
                                    .replace( /<\//g, '<\\/' ) };
                            } )
                            .catch( function( e ) {
                                if ( typeof console !== 'undefined' && console.warn ) {
                                    console.warn( 'export: css fetch failed (kept as <link>):', href, e );
                                }
                                // 同じ配列位置に残る＝文書順（カスケード順）が保たれる
                                return { kind: 'link', href: href, media: media };
                            } )
                    );
                })( node.href, media );
            }
        }
        // Promise.all は入力順＝文書順で解決値を返す（解決の速さに依らない）
        return Promise.all( jobs );
    }

    /**
     * 会話内の ECharts のアニメーションが終わるのを待つ（Issue #1535・実走検証で発見）。
     * グラフ表示直後に保存すると canvas がバー伸長アニメの途中＝「未完成の絵」が記録される。
     * 'finished' はレンダ完了ごとに発火（静止中は発火しない）ため、タイムアウトとの race にする
     * ＝アニメ中なら完了時に即進み、静止済みなら SETTLE_MS だけ待って進む（保存は「保存中…」
     * 表示のある非同期操作なので数百 ms の待ちは許容）。
     */
    function waitForChartsToSettle( container ) {
        // ECharts 既定アニメは約 1s ＝上限はそれより長く（アニメ中は finished で早く抜ける。
        // 静止済みチャートは finished が来ないので、この上限ぶんだけ待ってから進む）。
        var SETTLE_MS = 1200;
        try {
            if ( typeof window === 'undefined' || ! window.echarts || typeof window.echarts.getInstanceByDom !== 'function' ) {
                return Promise.resolve();
            }
            var chartEls = container.querySelectorAll( '.qahm-ec-chart' );
            if ( chartEls.length === 0 ) {
                return Promise.resolve();
            }
            var jobs = [];
            for ( var i = 0; i < chartEls.length; i++ ) {
                (function( el ) {
                    var inst = window.echarts.getInstanceByDom( el );
                    if ( ! inst || typeof inst.on !== 'function' ) return;
                    jobs.push( new Promise( function( resolve ) {
                        var done = false;
                        var finish = function() {
                            if ( done ) return;
                            done = true;
                            try { inst.off( 'finished', finish ); } catch ( e ) {}
                            resolve();
                        };
                        try { inst.on( 'finished', finish ); } catch ( e ) { finish(); return; }
                        setTimeout( finish, SETTLE_MS );
                    } ) );
                })( chartEls[i] );
            }
            return Promise.all( jobs );
        } catch ( e ) {
            return Promise.resolve();
        }
    }

    /**
     * 会話 DOM の静定を待つ（専属レビュー 🟡-2）。タイプライター表示（15ms/字で 1 文字ずつ
     * append）の最中に保存すると「実在しない半端な文」が記録されるため、MutationObserver で
     * 「QUIET_MS のあいだ変化なし」になるまで待ってから撮る。choices / form の入力待ちは
     * DOM が静止している＝即通過。上限 MAX_MS で諦めてそのまま撮る（現状より悪化しない）。
     * チャートのアニメ静定待ち（waitForChartsToSettle）と同じ思想の DOM 版。
     */
    function waitForDomToSettle( container ) {
        // QUIET はステップ間の演出 pause（showMessage 後 300ms・choices エコー後 600ms）より
        // 長く取る＝pause の静止を「会話が止まった」と誤検出しない（実走検証で 400ms は
        // ユーザーバブル直後の 600ms pause を静定と誤判定した）。choices / form の入力待ちや
        // 会話終了時は本当に静止している＝QUIET_MS 待ちだけで通過する。
        var QUIET_MS = 800;
        var MAX_MS = 8000;
        try {
            if ( typeof MutationObserver === 'undefined' ) {
                return Promise.resolve();
            }
            return new Promise( function( resolve ) {
                var done = false;
                var quietTimer = null;
                var observer = null;
                var finish = function() {
                    if ( done ) return;
                    done = true;
                    if ( quietTimer ) clearTimeout( quietTimer );
                    try { if ( observer ) observer.disconnect(); } catch ( e ) {}
                    resolve();
                };
                var armQuiet = function() {
                    if ( quietTimer ) clearTimeout( quietTimer );
                    quietTimer = setTimeout( finish, QUIET_MS );
                };
                observer = new MutationObserver( armQuiet );
                observer.observe( container, { childList: true, subtree: true, characterData: true } );
                armQuiet();
                setTimeout( finish, MAX_MS );
            } );
        } catch ( e ) {
            return Promise.resolve();
        }
    }

    /**
     * canvas 1 枚を PNG data URL 化する。ECharts のチャート要素（.qahm-ec-chart に init）は
     * インスタンス API の getDataURL（pixelRatio 2・白背景）を優先し、それ以外は素の toDataURL。
     * 失敗は null（その canvas はスキップ＝枠のまま・呼び出し側で警告）。
     */
    function canvasToDataUrl( canvas ) {
        try {
            var chartEl = canvas.closest ? canvas.closest( '.qahm-ec-chart' ) : null;
            if ( chartEl && typeof window !== 'undefined' && window.echarts && typeof window.echarts.getInstanceByDom === 'function' ) {
                var inst = window.echarts.getInstanceByDom( chartEl );
                if ( inst && typeof inst.getDataURL === 'function' ) {
                    return inst.getDataURL( { type: 'png', pixelRatio: 2, backgroundColor: '#fff' } );
                }
            }
            if ( typeof canvas.toDataURL === 'function' ) {
                return canvas.toDataURL( 'image/png' );
            }
        } catch ( e ) {
            if ( typeof console !== 'undefined' && console.warn ) {
                console.warn( 'export: canvas capture failed (left as-is):', e );
            }
        }
        return null;
    }

    /**
     * スナップショットの根＝会話 container から一番近い「画面ブロック」を探す。
     * 実画面では .qahm-assistant-container（キャラ画像＋talk-box ヘッダー帯＋dialogue-box）。
     * 見つからない環境（テストハーネス等）では狭い方から順に縮退。
     */
    function findSnapshotRoot( container ) {
        if ( container.closest ) {
            return container.closest( '.qahm-assistant-container' ) ||
                container.closest( '.qahm-assistant-talk-box' ) ||
                container;
        }
        return container;
    }

    /**
     * 画像を data URL で埋め込む（orig と clone の同順ペア・data: は対象外）。
     * まず絶対 URL を焼き込み（最低保証＝オンラインなら必ず表示できる）、その上で fetch できたもの
     * だけ data URL 化（オフラインでも表示できる）。巨大画像（IMG_INLINE_MAX_BYTES 超）と
     * fetch 失敗（CORS 等）は絶対 URL のまま縮退。個別失敗は保存を止めない。
     */
    function inlineImages( origRoot, cloneRoot ) {
        // orig/clone をインデックスで対応づけるため、両者の img 集合を揃える：
        // - clone 側＝canvas→img 差し替えで増えた .qahm-rh-chart（data: 済み）を除外
        // - orig 側＝clone から除去済みの要素（export-bar・typing）内の img を除外
        var origAll = origRoot.querySelectorAll( 'img' );
        var origImgs = [];
        for ( var o = 0; o < origAll.length; o++ ) {
            // clone 側から消えるもの＝③の除去対象＋②で img に置き換わったチャート要素の中身
            // （専属レビュー ⚪-1＝.qahm-ec-chart が抜けていると、将来チャート内に img が入った
            //   瞬間にインデックスが1つずれて「別の画像の src が焼かれる」静かな事故になる）
            var inRemoved = origAll[o].closest &&
                ( origAll[o].closest( '.qahm-assistant-export-bar' ) ||
                  origAll[o].closest( '.qahm-conversation-typing' ) ||
                  origAll[o].closest( '.qahm-ec-chart' ) );
            if ( ! inRemoved ) { origImgs.push( origAll[o] ); }
        }
        var cloneImgs = cloneRoot.querySelectorAll( 'img:not(.qahm-rh-chart)' );
        var jobs = [];
        for ( var i = 0; i < origImgs.length && i < cloneImgs.length; i++ ) {
            (function( oi, ci ) {
                var abs = oi.src || '';
                if ( ! abs || /^data:/i.test( abs ) ) return;
                try {
                    ci.setAttribute( 'src', abs );
                    // srcset が残っているとブラウザはそちらを選ぶ＝data URL を焼いても外部取得に戻る
                    // （保存物が外へリクエストを出す・オフラインで欠ける）。専属レビュー ⚪-6。
                    ci.removeAttribute( 'srcset' );
                    ci.removeAttribute( 'sizes' );
                } catch ( e ) { return; }
                jobs.push(
                    fetch( abs, { credentials: 'same-origin' } )
                        .then( function( res ) {
                            if ( ! res.ok ) { throw new Error( 'HTTP ' + res.status ); }
                            return res.blob();
                        } )
                        .then( function( blob ) {
                            if ( blob.size > IMG_INLINE_MAX_BYTES ) return;
                            return new Promise( function( resolve ) {
                                var fr = new FileReader();
                                fr.onload = function() {
                                    try { ci.setAttribute( 'src', String( fr.result ) ); } catch ( e ) {}
                                    resolve();
                                };
                                fr.onerror = function() { resolve(); };
                                fr.readAsDataURL( blob );
                            } );
                        } )
                        .catch( function( e ) {
                            if ( typeof console !== 'undefined' && console.warn ) {
                                console.warn( 'export: image inline failed (kept as URL):', abs, e );
                            }
                        } )
                );
            })( origImgs[i], cloneImgs[i] );
        }
        return Promise.all( jobs );
    }

    /**
     * 画面ブロックの outerHTML を、実ページの祖先チェーン（root の親〜ページラッパー
     * #class-qahm-admin-page-assistant まで・各層 shallow clone）でくるむ。
     * 会話 CSS はこの id スコープ＋実クラス連鎖を前提にしているため、合成ラッパーでなく
     * 実際の祖先の tag/class/id をそのまま写す（忠実スナップショット）。
     * ページラッパーが見つからない環境（ハーネス等）では最小の合成 id ラッパーで縮退。
     */
    function wrapWithAncestors( root, innerHtml ) {
        var openParts = [];
        var closeParts = [];
        var el = root.parentElement;
        var found = false;
        var hops = 0;
        while ( el && 'BODY' !== el.tagName && hops < 12 ) {
            var shell = el.cloneNode( false );
            var out = shell.outerHTML;
            var m = out.match( /<\/[a-zA-Z0-9-]+>$/ );
            if ( ! m ) {
                // タグ名が想定の文字種でない（`<o:p>` の類）＝open/close に割れない。
                // 中途半端に組むと中身が祖先の外に出るので、合成ラッパーへ縮退する
                // （専属レビュー ⚪-2）。
                return '<div id="class-qahm-admin-page-assistant">' + innerHtml + '</div>';
            }
            var close = m[0];
            var open = out.slice( 0, out.length - close.length );
            openParts.unshift( open );
            closeParts.push( close );
            if ( 'class-qahm-admin-page-assistant' === el.id ) {
                found = true;
                break;
            }
            el = el.parentElement;
            hops++;
        }
        if ( ! found ) {
            // 実ページ外（ハーネス等）＝スコープ id だけの最小ラッパーで CSS を効かせる
            return '<div id="class-qahm-admin-page-assistant">' + innerHtml + '</div>';
        }
        return openParts.join( '' ) + innerHtml + closeParts.join( '' );
    }

    /**
     * 画面ブロックのスナップショット（HTML 文字列の Promise）を作る。
     * - 会話 container でなく画面ブロック（.qahm-assistant-container）を丸ごと撮る
     *   ＝キャラ画像・talk-box ヘッダー帯（タイトル・アシスタント切替）込み（「画面ごと保存」）。
     * - クローンに対して加工する（実画面は 1px も触らない）。
     * - canvas → <img>（元 DOM の同順 canvas から描画内容を取得）。
     * - ローディング（typing ドット）・script 要素（防御）・保存ボタン自身（export-bar）は除去。
     * - 進行中の choices / form・表の操作 UI は見た目そのまま残し、全コントロールに disabled
     *   ＝「そのままの画面・ただし動かない」を伝える。
     * - 画像は data URL 埋め込み（失敗は絶対 URL 縮退）＝非同期。
     */
    function snapshotScreen( container ) {
        var root = findSnapshotRoot( container );
        var clone = root.cloneNode( true );

        // ★この関数は「orig と clone を同じ並びで走査して対応づける」加工を重ねる。
        //   したがって **clone の構造を変える加工（canvas 差し替え・要素の除去）は、
        //   対応づけが要る加工（コントロールの焼き込み）より後**に置くこと。
        //   順序＝①コントロール焼き込み → ②canvas→img → ③除去 → ④画像埋め込み。

        // ① フォーム状態の焼き込み＋無効化（dev5 実機確認での今井指摘 2026-07-30）。
        // cloneNode は入力の「現在値」（DOM プロパティ）を複製しない（複製されるのは属性だけ）＝
        // JS がセットした期間などがクローンで空欄になる。現在値を属性としてクローンへ焼き込む。
        // あわせて全操作 UI に disabled を付与＝静的記録では「動かない」ことを見た目でも伝える
        // （フォーム・表の操作 UI とも見た目はそのまま残す＝「画面ごと保存」）。
        var origControls = root.querySelectorAll( 'input, textarea, select, button' );
        var cloneControls = clone.querySelectorAll( 'input, textarea, select, button' );
        for ( var f = 0; f < origControls.length && f < cloneControls.length; f++ ) {
            var oc = origControls[f];
            var cc = cloneControls[f];
            try {
                if ( 'INPUT' === oc.tagName ) {
                    if ( 'checkbox' === oc.type || 'radio' === oc.type ) {
                        if ( oc.checked ) { cc.setAttribute( 'checked', '' ); } else { cc.removeAttribute( 'checked' ); }
                    } else {
                        cc.setAttribute( 'value', oc.value );
                    }
                } else if ( 'TEXTAREA' === oc.tagName ) {
                    cc.textContent = oc.value;
                } else if ( 'SELECT' === oc.tagName ) {
                    for ( var so = 0; so < oc.options.length && so < cc.options.length; so++ ) {
                        if ( oc.options[so].selected ) { cc.options[so].setAttribute( 'selected', '' ); } else { cc.options[so].removeAttribute( 'selected' ); }
                    }
                }
                cc.setAttribute( 'disabled', '' );
            } catch ( e ) { /* 個別の焼き込み失敗はその要素だけ諦める（保存自体は続行） */ }
        }

        // ② グラフ（canvas）→ img。ECharts のチャート要素は canvas 以外の描画補助ノードも持つため
        // チャート要素ごと差し替える＝clone の構造が変わる（だから①の後）。
        var origCanvases = root.querySelectorAll( 'canvas' );
        var cloneCanvases = clone.querySelectorAll( 'canvas' );
        for ( var i = 0; i < origCanvases.length && i < cloneCanvases.length; i++ ) {
            var dataUrl = canvasToDataUrl( origCanvases[i] );
            if ( ! dataUrl ) continue;
            var img = document.createElement( 'img' );
            img.className = 'qahm-rh-chart';
            img.src = dataUrl;
            var rect = origCanvases[i].getBoundingClientRect();
            if ( rect && rect.width > 0 ) {
                img.style.width = Math.round( rect.width ) + 'px';
                img.style.height = 'auto';
            }
            var cloneChartEl = cloneCanvases[i].closest ? cloneCanvases[i].closest( '.qahm-ec-chart' ) : null;
            var target = cloneChartEl || cloneCanvases[i];
            if ( target.parentNode ) {
                target.parentNode.replaceChild( img, target );
            }
        }

        // ③ 保存ボタン自身（export-bar）は記録の chrome＝画面の内容ではないので除去。
        // typing ドット・script（防御）も同時に。
        var removeSelectors = [ '.qahm-conversation-typing', 'script', '.qahm-assistant-export-bar' ];
        for ( var s = 0; s < removeSelectors.length; s++ ) {
            var nodes = clone.querySelectorAll( removeSelectors[s] );
            for ( var n = 0; n < nodes.length; n++ ) {
                if ( nodes[n].parentNode ) {
                    nodes[n].parentNode.removeChild( nodes[n] );
                }
            }
        }

        // ④ 画像埋め込み（非同期）→ 実ページの祖先チェーンでくるんで完成
        return inlineImages( root, clone ).then( function() {
            return wrapWithAncestors( root, clone.outerHTML );
        } );
    }

    /**
     * Blob をファイルとしてダウンロードさせる（qa-table のエクスポートと同じ流儀）。
     */
    function downloadHtml( content, fileName ) {
        var blob = new Blob( [ content ], { type: 'text/html;charset=utf-8' } );
        var url = URL.createObjectURL( blob );
        var a = document.createElement( 'a' );
        a.href = url;
        a.download = fileName;
        document.body.appendChild( a );
        a.click();
        document.body.removeChild( a );
        URL.revokeObjectURL( url );
    }

    /**
     * エクスポート実行（launcher のボタンから呼ばれる）。
     *
     * @param {Object} runtime     AssistantRuntime インスタンス
     * @param {string} slug        アシスタント plugin slug
     * @param {Object} manifest    manifest オブジェクト（name / version の表示用）
     * @param {Object} exportMeta  サーバー応答 export_meta（spec_version / site_label）。
     *                             system_vars と別キー＝runtime の $sys.* 解決に載らない。
     * @returns {Promise<void>}
     */
    function exportConversation( runtime, slug, manifest, exportMeta ) {
        // 同期部の throw も必ず返り値 Promise の .catch に乗せる（セルフレビュー ⚪-3＝
        // 「保存中…」のままボタンが固まる形を封じる）。
        return Promise.resolve().then( function() {
            var container = runtime.ui.getContainer();
            var savedAt = new Date();
            var em = exportMeta || {};
            var sysVars = runtime.systemVars || {};
            var assistantName = '';
            try {
                assistantName = runtime.resolveTranslation( ( manifest && manifest.name ) || '' );
            } catch ( e ) {
                assistantName = ( manifest && manifest.name ) || '';
            }

            var meta = buildMeta( {
                slug: slug,
                assistantName: assistantName,
                manifestVersion: manifest && manifest.version,
                specVersion: em.spec_version,
                pluginVersion: ( typeof qahm !== 'undefined' && qahm.plugin_version ) || '',
                productType: ( typeof qahm !== 'undefined' && qahm.type === qahm.type_zero ) ? 'zero'
                    : ( ( typeof qahm !== 'undefined' && qahm.type === qahm.type_wp ) ? 'wp' : '' ),
                trackingId: sysVars.tracking_id,
                siteLabel: em.site_label,
                wpSiteUrl: ( typeof qahm !== 'undefined' && qahm.site_url ) || '',
                savedAt: savedAt,
                runLog: runtime.runLog
            } );
            var summary = summarizeRunLog( runtime.runLog );

            return waitForDomToSettle( container ).then( function() {
                return waitForChartsToSettle( container );
            } ).then( function() {
                // CSS 収集とスナップショット（画像埋め込み込み）は独立＝並列で待つ
                return Promise.all( [ collectPageCss( document ), snapshotScreen( container ) ] );
            } ).then( function( results ) {
                var cssFragments = results[0];
                var screenHtml = results[1];
                var html = buildExportHtml( {
                    lang: ( typeof qahm !== 'undefined' && qahm.locale_for_js ) || 'ja',
                    title: ( assistantName || slug ) + ' — ' + meta.saved_at,
                    cssHeadHtml: buildCssHeadHtml( cssFragments ),
                    metaBlockHtml: buildMetaBlockHtml( meta, summary ),
                    screenHtml: screenHtml,
                    bodyClass: ( document.body && document.body.className ) || '',
                    metaJson: meta
                } );
                downloadHtml( html, buildFileName( slug, savedAt ) );
            } );
        } );
    }

    /**
     * 「この会話を保存」ボタンを会話枠の直前に取り付ける（launcher 専用の入口）。
     * 会話 container の外に置く＝スナップショットにボタン自身は混入しない。
     *
     * @param {Object} runtime     AssistantRuntime インスタンス
     * @param {HTMLElement} container  会話 container（dialogue-box）
     * @param {string} slug        アシスタント plugin slug
     * @param {Object} manifest    manifest オブジェクト
     * @param {Object} exportMeta  サーバー応答 export_meta（spec_version / site_label）
     */
    function attach( runtime, container, slug, manifest, exportMeta ) {
        try {
            if ( ! runtime || ! container || ! container.parentNode ) return;

            var bar = document.createElement( 'div' );
            bar.className = 'qahm-assistant-export-bar';

            var btn = document.createElement( 'button' );
            btn.type = 'button';
            btn.className = 'qahm-conversation-command-button qahm-assistant-export-btn';
            btn.textContent = LABELS.button;

            var msg = document.createElement( 'span' );
            msg.className = 'qahm-assistant-export-msg';

            btn.addEventListener( 'click', function() {
                btn.disabled = true;
                btn.textContent = LABELS.saving;
                msg.textContent = '';
                exportConversation( runtime, slug, manifest, exportMeta )
                    .catch( function( e ) {
                        if ( typeof console !== 'undefined' && console.error ) {
                            console.error( 'export failed:', e );
                        }
                        msg.textContent = LABELS.failed;
                    } )
                    .then( function() {
                        btn.disabled = false;
                        btn.textContent = LABELS.button;
                    } );
            } );

            bar.appendChild( btn );
            bar.appendChild( msg );
            container.parentNode.insertBefore( bar, container );
        } catch ( e ) {
            // ボタンが出せなくても会話は通常どおり（エクスポートは純オプトインの付加機能）。
            if ( typeof console !== 'undefined' && console.error ) {
                console.error( 'export button attach failed:', e );
            }
        }
    }

    // Export（attach が本入口。ほかは実走ハーネス・単体テスト用に公開）
    qahm.AssistantExporter = {
        attach: attach,
        exportConversation: exportConversation,
        // 純関数（テスト用公開）
        _absolutizeCssUrls: absolutizeCssUrls,
        _absolutizeCssImports: absolutizeCssImports,
        _wrapCssMedia: wrapCssMedia,
        _buildCssHeadHtml: buildCssHeadHtml,
        _escapeJsonIsland: escapeJsonIsland,
        _buildFileName: buildFileName,
        _localIsoString: localIsoString,
        _summarizeRunLog: summarizeRunLog,
        _buildMeta: buildMeta,
        _buildMetaBlockHtml: buildMetaBlockHtml,
        _buildExportHtml: buildExportHtml,
        _escapeHtml: escapeHtml
    };

})();
