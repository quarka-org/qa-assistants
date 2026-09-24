/**
 * QAHM Assistant Runtime
 *
 * Scene execution engine for manifest-based assistant plugins.
 * Handles step execution, variable system, transform pipeline,
 * limited markdown, and expression parser.
 *
 * Design spec: docs/specs/assistant-manifest.md
 *
 * @since 1.0.0
 */

var qahm = qahm || {};

(function() {
    'use strict';

    /**
     * AssistantRuntime class
     *
     * Public surface for step handlers (Issue #1449) — the runtime instance
     * passed to a registered step handler exposes exactly these seven members
     * as the stable adapter-facing API:
     *
     *   resolveTranslation( raw )      i18n lookup ("t:..." keys)
     *   expandTemplate( raw )          {$var} / {$sys.*} expansion
     *   vars                           user variables (READ ONLY from adapters)
     *   ui.getContainer()              conversation container element
     *   ui.showMessage( html )         chat bubble (safe-HTML input)
     *   renderLimitedMarkdown( raw )   escape-first limited markdown -> safe HTML
     *   escapeHtml( raw )              plain HTML escape
     *
     * Everything else on the instance is private and may change without
     * notice — adapters and external step packs must not depend on it.
     *
     * @param {Object} manifest      Full manifest.json object
     * @param {Object} translations   Translations object (nested)
     * @param {Object} systemVars     System variables { tracking_id, locale }
     * @param {Object} uiModule       UI module instance (qahm.AssistantUI)
     */
    function AssistantRuntime( manifest, translations, systemVars, uiModule ) {
        this.manifest = manifest;
        this.translations = translations;
        this.systemVars = systemVars;
        this.ui = uiModule;

        // Initialize user variables from manifest.vars
        this.vars = {};
        if ( manifest.vars ) {
            var varKeys = Object.keys( manifest.vars );
            for ( var i = 0; i < varKeys.length; i++ ) {
                var k = varKeys[i];
                var val = manifest.vars[k];
                // Deep copy arrays/objects
                if ( Array.isArray( val ) ) {
                    this.vars[k] = [];
                } else if ( val && typeof val === 'object' ) {
                    this.vars[k] = JSON.parse( JSON.stringify( val ) );
                } else {
                    this.vars[k] = val;
                }
            }
        }

        this.running = false;

        // Issue #1534: 「開始に成功したか」＝最初のステップを実行し終えたか。launcher の
        // .catch が launch_failed（開始できなかった）／step_failed（会話中に止まった）の
        // 文言を選ぶために読む。エラーの受け皿自体は launcher の 1 本のまま（#1456 W-6 の設計維持）。
        this._conversationStarted = false;

        // Issue #1535: 実行ログ（append-only）。会話エクスポートのメタ（期間・入力）の唯一の源。
        // 保存時点の vars から採らない理由＝vars は form 再入力・goto の戻りで上書きされ、
        // 1会話で期間を2回変えると「最後の値」しか残らず記録を取り違えるため（設計レビュー 🟡-3）。
        // 読み手は qahm.AssistantExporter のみ（公開7面には含めない・adapters は依存しないこと）。
        this.runLog = [];

        // T22brand-3: pre-process queries[] (for_each 静的展開 + validation)
        // T22brand-3-dyn (2026-05-16): _dynamic_months / sources_template を先に展開
        this.expandDynamicParams();
        this.preprocessManifest();
    }

    // ─── Dynamic params expander (T22brand-3-dyn 2026-05-16) ────────────────

    /**
     * params._dynamic_months 宣言を展開して params.months / params.month_current を生成する。
     * manifest 内では preg_match 禁止 + シンボル参照のため日付加算は不可能なので、
     * runtime 側で今日の日付を基に月配列を生成して params に注入する。
     *
     * 宣言例:
     *   "params": {
     *     "_dynamic_months": { "from": "2026-02", "to": "previous_month" }
     *   }
     *
     * to の値:
     *   - "previous_month" : 今月の1つ前まで（月途中データを避ける）
     *   - "current_month"  : 今月を含む
     *   - "YYYY-MM"        : 固定指定
     *
     * 生成される値:
     *   - params.months         : [{ label:"2月", start:"2026-02-01T00:00:00", end:"2026-03-01T00:00:00" }, ...]
     *   - params.month_current  : [<末尾月の1件>]  (単月モード用)
     */
    AssistantRuntime.prototype.expandDynamicParams = function() {
        var params = this.manifest && this.manifest.params;
        if ( ! params || typeof params !== 'object' ) return;

        var spec = params._dynamic_months;
        if ( ! spec || typeof spec !== 'object' ) return;
        if ( typeof spec.from !== 'string' || spec.from.length < 7 ) {
            throw new Error( 'Manifest error: params._dynamic_months.from must be "YYYY-MM" string' );
        }

        var fromParts = spec.from.split( '-' );
        var fromYear  = parseInt( fromParts[0], 10 );
        var fromMonth = parseInt( fromParts[1], 10 );
        if ( isNaN( fromYear ) || isNaN( fromMonth ) || fromMonth < 1 || fromMonth > 12 ) {
            throw new Error( 'Manifest error: params._dynamic_months.from is invalid: ' + spec.from );
        }

        var to = spec.to || 'previous_month';
        var now = new Date();
        var toYear, toMonth;
        if ( to === 'previous_month' ) {
            toYear  = now.getFullYear();
            toMonth = now.getMonth();           // getMonth() は 0-indexed なので、currentMonth - 1
            if ( toMonth === 0 ) { toYear--; toMonth = 12; }
        } else if ( to === 'current_month' ) {
            toYear  = now.getFullYear();
            toMonth = now.getMonth() + 1;
        } else if ( typeof to === 'string' && to.indexOf( '-' ) > 0 ) {
            var toParts = to.split( '-' );
            toYear  = parseInt( toParts[0], 10 );
            toMonth = parseInt( toParts[1], 10 );
            if ( isNaN( toYear ) || isNaN( toMonth ) || toMonth < 1 || toMonth > 12 ) {
                throw new Error( 'Manifest error: params._dynamic_months.to is invalid: ' + to );
            }
        } else {
            throw new Error( 'Manifest error: params._dynamic_months.to is invalid: ' + to );
        }

        var months = [];
        var y = fromYear, m = fromMonth;
        var safety = 120;
        while ( safety-- > 0 ) {
            var ny = y, nm = m + 1;
            if ( nm > 12 ) { ny++; nm = 1; }
            months.push( {
                label: m + '月',
                start: y + '-' + this._pad2( m ) + '-01T00:00:00',
                end:   ny + '-' + this._pad2( nm ) + '-01T00:00:00'
            } );
            if ( y > toYear || ( y === toYear && m >= toMonth ) ) break;
            y = ny; m = nm;
        }

        params.months = months;
        if ( months.length > 0 ) {
            params.month_current = [ months[ months.length - 1 ] ];
            // gsc_keywords_all のような単一 query で使う「期間全体」の time を vars に注入
            // ($period_start / $period_end は resolveQueryVars が this.vars から解決する)
            this.vars.period_start         = months[0].start;
            this.vars.period_end           = months[ months.length - 1 ].end;
            this.vars.current_month_label  = months[ months.length - 1 ].label;
            this.vars.current_month_start  = months[ months.length - 1 ].start;
            this.vars.current_month_end    = months[ months.length - 1 ].end;
        } else {
            params.month_current = [];
        }
    };

    AssistantRuntime.prototype._pad2 = function( n ) {
        return n < 10 ? '0' + n : '' + n;
    };

    /**
     * combine.sources_template を展開して sources 配列を生成する。
     *
     * 宣言例:
     *   { "combine": "month_label",
     *     "sources_template": {
     *       "labels_from": "$months",                              // params.months から label を抽出
     *       "prefixes": ["sessions_total", "ad_sessions", "sega_store"]
     *     } }
     *
     * または直接 labels を指定:
     *   "sources_template": { "labels": ["2月","3月","4月"], "prefixes": [...] }
     *
     * 出力: ["sessions_total__2月","ad_sessions__2月","sega_store__2月","sessions_total__3月",...]
     * 月毎に prefix を並べる (label が外側ループ)
     */
    AssistantRuntime.prototype.expandSourcesTemplate = function( dsName, tmpl ) {
        if ( ! tmpl || typeof tmpl !== 'object' ) {
            throw new Error( 'Manifest error: data_source "' + dsName + '" sources_template must be an object' );
        }
        var labels;
        if ( Array.isArray( tmpl.labels ) ) {
            labels = tmpl.labels;
        } else if ( typeof tmpl.labels_from === 'string' && tmpl.labels_from.indexOf( '$' ) === 0 ) {
            var paramName = tmpl.labels_from.substring( 1 );
            var paramVal = null;
            if ( this.manifest.params && this.manifest.params[ paramName ] !== undefined ) {
                paramVal = this.manifest.params[ paramName ];
            } else if ( this.manifest.vars && this.manifest.vars[ paramName ] !== undefined ) {
                paramVal = this.manifest.vars[ paramName ];
            }
            if ( ! Array.isArray( paramVal ) ) {
                throw new Error( 'Manifest error: data_source "' + dsName + '" sources_template.labels_from "' + tmpl.labels_from + '" did not resolve to an array' );
            }
            labels = [];
            for ( var i = 0; i < paramVal.length; i++ ) {
                var item = paramVal[i];
                if ( typeof item === 'string' ) {
                    labels.push( item );
                } else if ( item && typeof item === 'object' && typeof item.label === 'string' ) {
                    labels.push( item.label );
                } else {
                    throw new Error( 'Manifest error: data_source "' + dsName + '" sources_template.labels_from item must be string or have .label' );
                }
            }
        } else {
            throw new Error( 'Manifest error: data_source "' + dsName + '" sources_template needs labels or labels_from' );
        }
        if ( ! Array.isArray( tmpl.prefixes ) || tmpl.prefixes.length === 0 ) {
            throw new Error( 'Manifest error: data_source "' + dsName + '" sources_template.prefixes must be a non-empty array' );
        }
        var result = [];
        for ( var li = 0; li < labels.length; li++ ) {
            for ( var pi = 0; pi < tmpl.prefixes.length; pi++ ) {
                result.push( tmpl.prefixes[ pi ] + '__' + labels[ li ] );
            }
        }
        return result;
    };

    // ─── Manifest pre-processor (T22brand-3) ────────────────

    /**
     * Pre-process manifest at load time:
     * - data_sources[*].queries[*].for_each を静的展開して flat な queries[] にする
     * - 展開後の label に `__<value>` サフィックスを付与
     * - バリデーション (query/queries 排他、長さ上限 50、label 重複、ループネスト)
     *
     * 展開後の manifest は runtime からは「単純な queries[] 配列」として見える。
     */
    AssistantRuntime.prototype.preprocessManifest = function() {
        var dsMap = this.manifest && this.manifest.data_sources;
        if ( ! dsMap || typeof dsMap !== 'object' ) return;

        var QUERIES_MAX = 50;
        var dsNames = Object.keys( dsMap );

        for ( var d = 0; d < dsNames.length; d++ ) {
            var dsName = dsNames[d];
            var ds = dsMap[dsName];
            if ( ! ds || typeof ds !== 'object' ) continue;

            // 排他: query (単数) と queries (複数) が同居していたらエラー
            if ( ds.query !== undefined && ds.queries !== undefined ) {
                throw new Error( 'Manifest error: data_source "' + dsName + '" has both query and queries (must be exclusive)' );
            }
            if ( ds.queries === undefined ) {
                // queries[] 不在で combine が書かれていたら、サイレントに無視されるので明示的にエラー (combine は queries[] 専用)
                if ( Array.isArray( ds.transform ) ) {
                    for ( var ti = 0; ti < ds.transform.length; ti++ ) {
                        if ( ds.transform[ti] && ds.transform[ti].combine !== undefined ) {
                            throw new Error( 'Manifest error: data_source "' + dsName + '" uses combine without queries[] (combine requires queries[])' );
                        }
                    }
                }
                continue;
            }

            if ( ! Array.isArray( ds.queries ) ) {
                throw new Error( 'Manifest error: data_source "' + dsName + '" queries must be an array' );
            }

            // queries は native_qal 専用
            if ( ds.type !== 'native_qal' ) {
                throw new Error( 'Manifest error: data_source "' + dsName + '" queries requires type:"native_qal"' );
            }

            // 各要素を for_each 展開して flat 化
            var flat = [];
            var seenLabels = {};
            for ( var i = 0; i < ds.queries.length; i++ ) {
                var elem = ds.queries[i];
                if ( ! elem || typeof elem !== 'object' ) {
                    throw new Error( 'Manifest error: data_source "' + dsName + '" queries[' + i + '] is not an object' );
                }
                if ( typeof elem.label !== 'string' || elem.label.length === 0 ) {
                    throw new Error( 'Manifest error: data_source "' + dsName + '" queries[' + i + '] is missing label' );
                }
                if ( ! elem.qal || typeof elem.qal !== 'object' ) {
                    throw new Error( 'Manifest error: data_source "' + dsName + '" queries[' + i + '] is missing qal' );
                }

                var expanded;
                if ( elem.for_each !== undefined ) {
                    expanded = this.expandForEach( dsName, i, elem );
                } else {
                    expanded = [ { label: elem.label, qal: this.deepClone( elem.qal ) } ];
                }

                for ( var e = 0; e < expanded.length; e++ ) {
                    var ex = expanded[e];
                    // ループのネスト禁止: 展開後の qal に for_each が残っていないか確認
                    if ( this.containsForEach( ex.qal ) ) {
                        throw new Error( 'Manifest error: data_source "' + dsName + '" queries contains nested for_each (forbidden by spec)' );
                    }
                    if ( seenLabels[ ex.label ] ) {
                        throw new Error( 'Manifest error: data_source "' + dsName + '" duplicate label "' + ex.label + '" after for_each expansion' );
                    }
                    seenLabels[ ex.label ] = true;
                    flat.push( ex );
                }
            }

            if ( flat.length > QUERIES_MAX ) {
                throw new Error( 'Manifest error: data_source "' + dsName + '" queries length ' + flat.length + ' exceeds limit ' + QUERIES_MAX );
            }
            if ( flat.length === 0 ) {
                throw new Error( 'Manifest error: data_source "' + dsName + '" queries is empty after expansion' );
            }

            // queries[] を使う data_source は transform[0] が combine 必須 (仕様 §7.9)
            if ( ! Array.isArray( ds.transform ) || ds.transform.length === 0 || ds.transform[0].combine === undefined ) {
                throw new Error( 'Manifest error: data_source "' + dsName + '" must place "combine" as the first transform when using queries[]' );
            }

            // T22brand-3-dyn (2026-05-16): combine.sources_template を sources に展開
            for ( var ts = 0; ts < ds.transform.length; ts++ ) {
                var op0 = ds.transform[ts];
                if ( op0 && op0.combine !== undefined && op0.sources_template !== undefined ) {
                    if ( op0.sources !== undefined ) {
                        throw new Error( 'Manifest error: data_source "' + dsName + '" combine has both sources and sources_template (must be exclusive)' );
                    }
                    op0.sources = this.expandSourcesTemplate( dsName, op0.sources_template );
                    delete op0.sources_template;
                }
            }

            // combine.sources の参照先未定義チェック
            for ( var t = 0; t < ds.transform.length; t++ ) {
                var op = ds.transform[t];
                if ( op && op.combine !== undefined ) {
                    if ( ! Array.isArray( op.sources ) ) {
                        throw new Error( 'Manifest error: data_source "' + dsName + '" combine.sources must be an array' );
                    }
                    for ( var s = 0; s < op.sources.length; s++ ) {
                        if ( ! seenLabels[ op.sources[s] ] ) {
                            throw new Error( 'Manifest error: data_source "' + dsName + '" combine references undefined source "' + op.sources[s] + '"' );
                        }
                    }
                }
            }

            ds.queries = flat;
        }
    };

    /**
     * Expand one queries[] element with for_each into N elements
     */
    AssistantRuntime.prototype.expandForEach = function( dsName, idx, elem ) {
        var fe = elem.for_each;
        if ( ! fe || typeof fe !== 'object' ) {
            throw new Error( 'Manifest error: data_source "' + dsName + '" queries[' + idx + '] for_each is invalid' );
        }
        if ( typeof fe.var !== 'string' || fe.var.length === 0 ) {
            throw new Error( 'Manifest error: data_source "' + dsName + '" queries[' + idx + '] for_each.var is missing' );
        }
        var arr = this.resolveForEachIn( fe['in'] );
        if ( ! Array.isArray( arr ) ) {
            throw new Error( 'Manifest error: data_source "' + dsName + '" queries[' + idx + '] for_each.in must resolve to an array' );
        }

        var result = [];
        for ( var i = 0; i < arr.length; i++ ) {
            var value = arr[i];
            var clonedQal = this.deepClone( elem.qal );
            this.substituteForEachVar( clonedQal, fe.var, value );
            var suffix = this.labelSuffixFor( value );
            result.push( {
                label: elem.label + '__' + suffix,
                qal: clonedQal
            } );
        }
        return result;
    };

    /**
     * Resolve for_each.in:
     * - literal array → そのまま
     * - "$<var>" → this.vars[var] が非空配列ならそれを優先（runtime で更新された値）、
     *   そうでなければ manifest.params[var] → manifest.vars[var] にフォールバック
     * - "$sys.<key>" → systemVars[key]
     *
     * 非空配列ガード（Array.isArray && length > 0）は、constructor が manifest.vars の
     * 配列宣言を `this.vars[k] = []` で初期化することへの対応。この空配列で manifest
     * 側の defaults を遮断すると、manifest.vars 配列に対する for_each が空展開に
     * なってしまうため、空配列はフォールバック対象とする。
     */
    AssistantRuntime.prototype.resolveForEachIn = function( spec ) {
        if ( Array.isArray( spec ) ) return spec;
        if ( typeof spec === 'string' ) {
            if ( spec.indexOf( '$sys.' ) === 0 ) {
                return this.systemVars[ spec.substring( 5 ) ];
            }
            if ( spec.indexOf( '$' ) === 0 ) {
                var name = spec.substring( 1 );
                if ( Array.isArray( this.vars[ name ] ) && this.vars[ name ].length > 0 ) {
                    return this.vars[ name ];
                }
                if ( this.manifest.params && this.manifest.params[ name ] !== undefined ) {
                    return this.manifest.params[ name ];
                }
                if ( this.manifest.vars && this.manifest.vars[ name ] !== undefined ) {
                    return this.manifest.vars[ name ];
                }
            }
        }
        return null;
    };

    /**
     * Substitute $<var> references in qal (deep recursive walk, no regex)
     *
     * - 文字列値が完全に "$<var>" → value で置換 (型保持)
     * - 文字列値が "$<var>." 始まり → "$<var>.prop1.prop2" のドットパスで value から辿る
     * - 文字列値に部分一致で "$<var>" が含まれる場合 → toString して置換 (テンプレート展開)
     */
    AssistantRuntime.prototype.substituteForEachVar = function( node, varName, value ) {
        if ( node === null || node === undefined ) return;
        if ( Array.isArray( node ) ) {
            for ( var i = 0; i < node.length; i++ ) {
                if ( typeof node[i] === 'string' ) {
                    node[i] = this.applySubstitution( node[i], varName, value );
                } else {
                    this.substituteForEachVar( node[i], varName, value );
                }
            }
            return;
        }
        if ( typeof node === 'object' ) {
            var keys = Object.keys( node );
            for ( var k = 0; k < keys.length; k++ ) {
                var key = keys[k];
                if ( typeof node[key] === 'string' ) {
                    node[key] = this.applySubstitution( node[key], varName, value );
                } else {
                    this.substituteForEachVar( node[key], varName, value );
                }
            }
        }
    };

    /**
     * Apply a single string substitution. No regex (preg_match 禁止 — CLAUDE.md)
     */
    AssistantRuntime.prototype.applySubstitution = function( str, varName, value ) {
        var token = '$' + varName;
        // 完全一致: 型を保つ
        if ( str === token ) {
            return value;
        }
        // ドットパス: "$<var>.prop1.prop2"
        if ( str.indexOf( token + '.' ) === 0 ) {
            var rest = str.substring( token.length + 1 );
            // 末尾以外でドットパス＋テンプレが混ざるケースは扱わない (シーケンシャル原則)
            // "$<var>.prop" 形式は完全一致のみサポートする
            if ( rest.indexOf( '$' ) === -1 ) {
                var parts = rest.split( '.' );
                var current = value;
                for ( var p = 0; p < parts.length; p++ ) {
                    if ( current === null || current === undefined || typeof current !== 'object' ) {
                        return '';
                    }
                    current = current[ parts[p] ];
                }
                return current;
            }
        }
        // テンプレート部分一致: indexOf ベースの単純置換 (replaceAll 相当)
        if ( str.indexOf( token ) === -1 ) {
            return str;
        }
        var out = '';
        var pos = 0;
        while ( pos < str.length ) {
            var found = str.indexOf( token, pos );
            if ( found === -1 ) {
                out += str.substring( pos );
                break;
            }
            // 境界判定: token の直後が英数字 / `_` ならプレフィックス誤マッチ (varName="m" で "$month" を "$m" として誤置換するのを防ぐ)。
            // preg_match 禁止 (CLAUDE.md) のため文字比較で実装。
            var nextCharPos = found + token.length;
            var nextChar = nextCharPos < str.length ? str.charAt( nextCharPos ) : '';
            var isIdentChar = nextChar !== '' && (
                ( nextChar >= 'a' && nextChar <= 'z' ) ||
                ( nextChar >= 'A' && nextChar <= 'Z' ) ||
                ( nextChar >= '0' && nextChar <= '9' ) ||
                nextChar === '_'
            );
            if ( isIdentChar ) {
                // 誤マッチ: $ までを literal として残し、次の文字から検索継続
                out += str.substring( pos, found + 1 );
                pos = found + 1;
                continue;
            }
            out += str.substring( pos, found );
            out += String( value );
            pos = found + token.length;
        }
        return out;
    };

    /**
     * label suffix from for_each value
     *
     * object 要素の場合は `label` フィールド最優先 (作者が明示的に指定したサフィックス)、
     * 次に id / key / name / month を順に試す。何も該当しなければ JSON 化。
     */
    AssistantRuntime.prototype.labelSuffixFor = function( value ) {
        if ( value === null || value === undefined ) return 'null';
        if ( typeof value === 'object' ) {
            if ( typeof value.label === 'string' || typeof value.label === 'number' ) return String( value.label );
            if ( typeof value.id === 'string' || typeof value.id === 'number' ) return String( value.id );
            if ( typeof value.key === 'string' || typeof value.key === 'number' ) return String( value.key );
            if ( typeof value.name === 'string' ) return value.name;
            if ( typeof value.month === 'string' ) return value.month;
            return JSON.stringify( value );
        }
        return String( value );
    };

    /**
     * Detect leftover for_each in expanded qal (forbidden — nested loops)
     */
    AssistantRuntime.prototype.containsForEach = function( node ) {
        if ( node === null || node === undefined ) return false;
        if ( Array.isArray( node ) ) {
            for ( var i = 0; i < node.length; i++ ) {
                if ( this.containsForEach( node[i] ) ) return true;
            }
            return false;
        }
        if ( typeof node === 'object' ) {
            if ( node.for_each !== undefined ) return true;
            var keys = Object.keys( node );
            for ( var k = 0; k < keys.length; k++ ) {
                if ( this.containsForEach( node[ keys[k] ] ) ) return true;
            }
        }
        return false;
    };

    /**
     * Deep clone helper (JSON-safe nodes only — manifest values are JSON-serializable)
     */
    AssistantRuntime.prototype.deepClone = function( value ) {
        return JSON.parse( JSON.stringify( value ) );
    };

    // ─── Scene execution ───────────────────────────────

    /**
     * Run a named scene
     */
    AssistantRuntime.prototype.runScene = async function( sceneName ) {
        var scenes = this.manifest.scenes;
        if ( ! scenes || ! scenes[sceneName] ) {
            console.error( 'Scene not found:', sceneName );
            // Issue #1456 W-6(b): 無言停止の根絶。start 不在・goto typo の両方でユーザー可視のバブルを出す
            // （従来は console.error のみで会話が黙って止まっていた）。console.error はデバッグ用に維持。
            await this.showErrorBubble( AssistantRuntime.ERROR_MESSAGES.scene_missing );
            return;
        }

        this.running = true;
        var steps = scenes[sceneName];

        for ( var i = 0; i < steps.length; i++ ) {
            if ( ! this.running ) break;
            var result = await this.executeStep( steps[i] );
            // Issue #1534: 最初のステップが完了した＝「開始」は成功した。以降の throw は
            // launcher の catch が step_failed（会話中の失敗）の文言を選ぶための目印。
            // 冪等な true 代入のみ＝会話挙動は不変。読み手は launcher だけ（adapters は依存しないこと）。
            this._conversationStarted = true;
            // If step returns a goto, switch scene
            if ( result && result.goto ) {
                await this.runScene( result.goto );
                return;
            }
        }
    };

    /**
     * Execute a single step.
     *
     * Dispatch goes through qahm.assistantSteps (Issue #1449). The registry
     * resolves the step's dispatch key in canonical order — the exact order
     * of the legacy if-chain — so first-match priority for steps carrying
     * multiple keys is preserved. Property detection (`step[key] !==
     * undefined`), the sync/async mix (await swallows the plain returns of
     * callout / divider / html / if / goto / set) and the return-value
     * contract (`{ goto }` switches scene) are all unchanged. Unknown steps
     * warn and return null, as before.
     */
    AssistantRuntime.prototype.executeStep = async function( step ) {
        var entry = qahm.assistantSteps.resolve( step );
        if ( entry ) {
            return await entry.handler( this, step );
        }
        console.warn( 'Unknown step type:', step );
        return null;
    };

    /**
     * エラーバブルを1経路に統一する（Issue #1456 W-6）。fetch / config_read / シーン不在 /
     * launcher の runScene 例外がすべて同じ `<p>Error: …</p>` バブルを通る。msg は escapeHtml で
     * エスケープ（サーバ由来文言も安全）。返り値は showMessage の Promise（呼び出し側で await 可）。
     */
    AssistantRuntime.prototype.showErrorBubble = function( msg ) {
        // 自己防御（Issue #1456 W-6・専属⚪-h）：UI 層自体が壊れて showMessage が throw/reject しても、
        // このエラーハンドラが「新たな unhandled rejection」を生まないよう内部で握る（W-6 のテーマ＝
        // 無音故障・未処理 rejection の撲滅に、エラー経路自身が反しないため）。best-effort＝失敗しても
        // console.error に落として resolve する（表示は諦めるが、少なくとも無音の連鎖にはしない）。
        try {
            return Promise.resolve( this.ui.showMessage( '<p>Error: ' + this.escapeHtml( msg ) + '</p>' ) )
                .catch( function( e ) { if ( typeof console !== 'undefined' && console.error ) { console.error( 'showErrorBubble failed:', e ); } } );
        } catch ( e ) {
            if ( typeof console !== 'undefined' && console.error ) { console.error( 'showErrorBubble failed:', e ); }
            return Promise.resolve();
        }
    };

    /**
     * 実行ログへ 1 エントリ追記する（Issue #1535・会話エクスポートのメタ源）。
     * 記録は会話の副作用であってはならない＝どんな失敗も握って会話を続ける（表示・挙動不変）。
     * payload は呼び出し側で JSON 安全な形にして渡す（ここで deep copy して後続 mutate から遮断）。
     */
    AssistantRuntime.prototype._recordRun = function( kind, payload ) {
        try {
            // 暴走防止の上限（通常会話の fetch/form は多くて数十件）。超過は記録だけ止め、会話は続く。
            var MAX_RUN_LOG = 500;
            if ( this.runLog.length >= MAX_RUN_LOG ) {
                this._warnOnce( 'run_log', 'overflow', 'run log reached ' + MAX_RUN_LOG + ' entries; further entries are not recorded' );
                return;
            }
            this.runLog.push( {
                kind: kind,
                at: new Date().toISOString(),
                data: JSON.parse( JSON.stringify( payload === undefined ? null : payload ) )
            } );
        } catch ( e ) {
            if ( typeof console !== 'undefined' && console.warn ) {
                console.warn( 'run log record failed (conversation unaffected):', e );
            }
        }
    };

    /**
     * 同じ警告を (種別, キー) ごとに1回だけ出す（Issue #1456 W-6(d)・ループでのログスパム防止）。
     * 「静かな劣化」（未定義変数・翻訳キー欠落）を観測可能にするためだけに使う＝画面表示は変えない。
     */
    AssistantRuntime.prototype._warnOnce = function( kind, key, message ) {
        if ( ! this._warnedKeys ) this._warnedKeys = {};
        var dedupeKey = kind + ':' + key;
        if ( this._warnedKeys[ dedupeKey ] ) return;
        this._warnedKeys[ dedupeKey ] = true;
        if ( typeof console !== 'undefined' && console.warn ) {
            console.warn( '[assistant] ' + message );
        }
    };

    // ─── Step handlers ─────────────────────────────────

    /**
     * Handle message step
     */
    AssistantRuntime.prototype.handleMessage = async function( step ) {
        var text = step.message;
        text = this.resolveTranslation( text );
        text = this.expandTemplate( text );
        text = this.renderLimitedMarkdown( text );
        await this.ui.showMessage( text );
        return null;
    };

    // The html step handler lives in qahm-assistant-blocks.js
    // (moved there in Issue #1449 PR-D2 — the blocks adapter owns the
    // full-width block types callout / divider / html).

    /**
     * Resolve a "set" object against a data row (Issue #1386).
     * A value of "$row.<field>" reads that field from the given row; any other
     * value goes through resolveValue ($var / $sys / literal). Shared by dynamic
     * choices (handleChoices) and table row_action (handleTable).
     *
     * @param {Object} setDef  The set definition (key -> value).
     * @param {Object} row     The data row in scope.
     * @returns {Object}  Resolved key -> value map.
     */
    AssistantRuntime.prototype.resolveSetFromRow = function( setDef, row ) {
        var out = {};
        if ( ! setDef || typeof setDef !== 'object' ) return out;
        var keys = Object.keys( setDef );
        for ( var i = 0; i < keys.length; i++ ) {
            var k = keys[i];
            var v = setDef[k];
            if ( typeof v === 'string' && v.indexOf( '$row.' ) === 0 ) {
                var field = v.substring( 5 );
                out[k] = ( row && row[field] !== undefined ) ? row[field] : '';
            } else {
                out[k] = this.resolveValue( v );
            }
        }
        return out;
    };

    /**
     * Handle choices step (static array or dynamic object)
     */
    AssistantRuntime.prototype.handleChoices = async function( step ) {
        var self = this;
        var choicesDef = step.choices;
        var buttons = [];

        if ( Array.isArray( choicesDef ) ) {
            // Static choices
            for ( var i = 0; i < choicesDef.length; i++ ) {
                buttons.push( this.buildButton( choicesDef[i] ) );
            }
        } else if ( choicesDef && typeof choicesDef === 'object' ) {
            // Dynamic choices from data
            var data = this.resolveValue( choicesDef.from_data );
            if ( Array.isArray( data ) ) {
                var maxItems = choicesDef.maxItems || 20;
                var seen = {};
                for ( var j = 0; j < data.length && buttons.length < maxItems; j++ ) {
                    var row = data[j];
                    var label = row[ choicesDef.label_field ];
                    if ( label === undefined || label === null || label === '' ) continue;
                    // Deduplicate
                    if ( seen[label] ) continue;
                    seen[label] = true;

                    var btn = {
                        label: String( label ),
                        goto: choicesDef.goto || '',
                        clear: choicesDef.clear || false
                    };
                    // Resolve $row.* in set (shared with table row_action — Issue #1386)
                    if ( choicesDef.set ) {
                        btn.set = this.resolveSetFromRow( choicesDef.set, row );
                    }
                    buttons.push( btn );
                }
            }
            // Extra buttons
            if ( choicesDef.extra && Array.isArray( choicesDef.extra ) ) {
                for ( var e = 0; e < choicesDef.extra.length; e++ ) {
                    buttons.push( this.buildButton( choicesDef.extra[e] ) );
                }
            }
        }

        if ( buttons.length === 0 ) return null;

        var chosen = await this.ui.showChoices( buttons );

        // Apply set
        if ( chosen.set ) {
            var chosenSetKeys = Object.keys( chosen.set );
            for ( var c = 0; c < chosenSetKeys.length; c++ ) {
                self.vars[ chosenSetKeys[c] ] = chosen.set[ chosenSetKeys[c] ];
            }
        }

        // Handle clear + goto
        if ( chosen.clear ) {
            this.ui.clearConversation();
        }
        if ( chosen.goto ) {
            return { goto: chosen.goto };
        }
        return null;
    };

    /**
     * Handle form step
     */
    AssistantRuntime.prototype.handleForm = async function( step ) {
        var self = this;
        var formDef = step.form;

        // Resolve translations in form definition
        var resolved = this.resolveFormTranslations( formDef );

        var result = await this.ui.showForm( resolved );

        // Cancel: go to specified scene without updating vars
        if ( result && result.cancelled ) {
            if ( result.goto ) {
                return { goto: result.goto };
            }
            return null;
        }

        // Submit: merge field values into vars
        if ( result && result.values ) {
            // date_range フィールドは "YYYY-MM-DD/YYYY-MM-DD" を <key>_start / <key>_end にも展開する — #1341。
            // manifest は time.start = "$<key>_start" / time.end = "$<key>_end" で参照する。
            var drTypeByKey = {};
            if ( resolved.fields && Array.isArray( resolved.fields ) ) {
                for ( var f = 0; f < resolved.fields.length; f++ ) {
                    drTypeByKey[ resolved.fields[f].key ] = resolved.fields[f].type;
                }
            }
            // Issue #1535: ユーザーが実際に送信した入力（期間・フォーム値）を append-only 記録。
            this._recordRun( 'form', { values: result.values } );

            var keys = Object.keys( result.values );
            for ( var i = 0; i < keys.length; i++ ) {
                self.vars[ keys[i] ] = result.values[ keys[i] ];
                if ( drTypeByKey[ keys[i] ] === 'date_range' ) {
                    var drVal = result.values[ keys[i] ];
                    if ( typeof drVal === 'string' && drVal.indexOf( '/' ) !== -1 ) {
                        var drRange = drVal.split( '/' );
                        self.vars[ keys[i] + '_start' ] = drRange[0];
                        self.vars[ keys[i] + '_end' ] = drRange[1];
                    }
                }
            }

            // Show submitted form values as a user bubble — Issue #1217
            // Allows users to review what they selected/entered after submit.
            // Use the same scroll pattern as choices: autoScroll: false + scrollIntoView({block:'start'}) + pause
            // so the user bubble is placed at the viewport top with the scroll-margin offset (Issue #1216),
            // matching the "User message: scroll to top of viewport" design (qahm-assistant-ui.js L7-9).
            if ( resolved.fields && Array.isArray( resolved.fields ) && qahm.conversationUI && qahm.conversationUI._displayText ) {
                var summary = self.buildFormSubmitSummary( resolved.fields, result.values );
                if ( summary ) {
                    var messageDiv = await qahm.conversationUI._displayText(
                        summary,
                        self.ui.container,
                        true,  // isUser
                        {
                            enableTypewriter: false,
                            autoScroll: false,
                            onMessageRendered: function() {}
                        }
                    );
                    if ( messageDiv && typeof messageDiv.scrollIntoView === 'function' ) {
                        messageDiv.scrollIntoView( { block: 'start', behavior: 'smooth' } );
                    }
                    if ( self.ui && typeof self.ui.pause === 'function' ) {
                        await self.ui.pause( 600 );
                    }
                }
            }
        }

        return null;
    };

    /**
     * Build a user-bubble summary of submitted form values — Issue #1217 / #1224.
     * Each field is rendered as "<rendered-label>: displayValue".
     *
     * Issue #1224 (B-1): the outer <strong> wrap from PR #1217 was removed and label is
     * now passed through renderLimitedMarkdown. Without this, manifest-side `**bold**`
     * inside field.label produced "double <strong> + literal **" (escapeHtml left `**`
     * as plain text while the outer wrap forced the whole label bold). Aligning summary
     * with the message step's markdown rules (## h3 / ### h4 / **bold** / links) lets
     * the manifest author control fine-grained emphasis.
     *
     * Returns an HTML string (consumed by _displayText with innerHTML).
     */
    AssistantRuntime.prototype.buildFormSubmitSummary = function( fields, values ) {
        if ( ! Array.isArray( fields ) ) return '';
        var lines = [];
        for ( var i = 0; i < fields.length; i++ ) {
            var field = fields[i];
            var labelText = field.label || field.key || '';
            var value = values[ field.key ];
            var displayValue = this.formatFieldValueForSummary( field, value );
            // Label: apply limited markdown (escapeHtml is included inside renderLimitedMarkdown)
            var renderedLabel = this.renderLimitedMarkdown( labelText );
            var escapedValue = this.escapeHtml( displayValue );
            // Preserve newlines for text/textarea so multi-line input remains readable
            if ( field.type === 'textarea' || field.type === 'text' ) {
                escapedValue = escapedValue.replace( /\n/g, '<br>' );
            }
            lines.push( renderedLabel + ': ' + escapedValue );
        }
        return lines.join( '<br>' );
    };

    /**
     * Format a single field value for the submit-summary bubble — Issue #1217.
     * Returns plain text (caller is responsible for escapeHtml).
     */
    AssistantRuntime.prototype.formatFieldValueForSummary = function( field, value ) {
        // Empty check: null / undefined / empty string / empty array
        if ( value === undefined || value === null || value === '' ) {
            return '(未入力)';
        }
        if ( Array.isArray( value ) && value.length === 0 ) {
            return '(未入力)';
        }

        var type = field.type || 'text';
        var options = field.options || [];

        switch ( type ) {
            case 'date_range':
                // "YYYY-MM-DD/YYYY-MM-DD" を "開始 〜 終了" で表示 — #1341
                if ( typeof value === 'string' && value.indexOf( '/' ) !== -1 ) {
                    var drsParts = value.split( '/' );
                    return drsParts[0] + ' 〜 ' + drsParts[1];
                }
                return String( value );

            case 'radio':
            case 'select':
                // Lookup label by value
                for ( var i = 0; i < options.length; i++ ) {
                    if ( String( options[i].value ) === String( value ) ) {
                        return String( options[i].label || options[i].value );
                    }
                }
                return String( value );

            case 'checkbox':
                if ( Array.isArray( value ) ) {
                    // Multiple checkbox: join selected labels with comma
                    var labels = [];
                    for ( var j = 0; j < value.length; j++ ) {
                        var found = null;
                        for ( var k = 0; k < options.length; k++ ) {
                            if ( String( options[k].value ) === String( value[j] ) ) {
                                found = options[k].label || options[k].value;
                                break;
                            }
                        }
                        labels.push( String( found !== null ? found : value[j] ) );
                    }
                    return labels.join( ', ' );
                }
                // Single checkbox (boolean)
                return value ? '✓' : '−';

            case 'text':
            case 'textarea':
            default:
                return String( value );
        }
    };

    /**
     * Resolve translations in form definition
     */
    AssistantRuntime.prototype.resolveFormTranslations = function( formDef ) {
        var resolved = JSON.parse( JSON.stringify( formDef ) );

        if ( resolved.submit ) {
            resolved.submit = this.resolveTranslation( resolved.submit );
        }
        if ( resolved.cancel && resolved.cancel.label ) {
            resolved.cancel.label = this.resolveTranslation( resolved.cancel.label );
        }

        if ( resolved.fields && Array.isArray( resolved.fields ) ) {
            for ( var i = 0; i < resolved.fields.length; i++ ) {
                var field = resolved.fields[i];
                if ( field.label ) {
                    field.label = this.resolveTranslation( field.label );
                }
                if ( field.placeholder ) {
                    field.placeholder = this.resolveTranslation( field.placeholder );
                }
                if ( field.options && Array.isArray( field.options ) ) {
                    for ( var j = 0; j < field.options.length; j++ ) {
                        if ( field.options[j].label ) {
                            field.options[j].label = this.resolveTranslation( field.options[j].label );
                        }
                    }
                }
            }
        }

        return resolved;
    };

    /**
     * Build a button object from a static choice definition
     */
    AssistantRuntime.prototype.buildButton = function( def ) {
        var btn = {
            label: this.expandTemplate( this.resolveTranslation( def.label || '' ) ),
            goto: def.goto || '',
            clear: def.clear || false
        };
        if ( def.set ) {
            btn.set = {};
            var keys = Object.keys( def.set );
            for ( var i = 0; i < keys.length; i++ ) {
                btn.set[ keys[i] ] = this.resolveValue( def.set[ keys[i] ] );
            }
        }
        return btn;
    };

    /**
     * Handle fetch step
     */
    AssistantRuntime.prototype.handleFetch = async function( step ) {
        var dsName = step.fetch;
        var ds = this.manifest.data_sources ? this.manifest.data_sources[dsName] : null;

        if ( ! ds ) {
            console.error( 'Data source not found:', dsName );
            return null;
        }

        // T22brand-3: queries[] 経路 (multi-QAL orchestration)
        if ( Array.isArray( ds.queries ) ) {
            return await this.handleFetchQueries( step, ds );
        }

        this.ui.showLoading();

        try {
            // Build resolved query
            var query = JSON.parse( JSON.stringify( ds.query ) );
            query = this.resolveQueryVars( query );

            // AJAX call to RuntimeHandler
            // type defaults to 'qal' (legacy manifest 簡易クエリ).
            // 'native_qal' は QAL ネイティブ JSON を素通しで Executor に渡す経路 (T22-1)。
            var dsType = ds.type || 'qal';
            // Issue #1535: 解決済みクエリ（time.start/end 込み）を append-only 記録。
            // fetchData 側でなく呼び出し元で記録する＝fetchData をシム差し替えする流儀
            // （デモパック等）でも記録が生きる。結果データは記録しない（サイズ・不要）。
            this._recordRun( 'fetch', { ds_type: dsType, query: query } );
            var response = await this.fetchData( query, dsType );

            await this.ui.hideLoading();

            if ( ! response || ! response.success ) {
                var errMsg = ( response && response.data && response.data.message ) ? response.data.message : AssistantRuntime.ERROR_MESSAGES.fetch_failed;
                console.error( 'Fetch error:', errMsg );
                if ( step.on_error ) {
                    return { goto: step.on_error };
                }
                await this.showErrorBubble( errMsg );
                return null;
            }

            var data = response.data.data || [];

            // Apply transform pipeline
            if ( ds.transform && Array.isArray( ds.transform ) ) {
                data = this.applyTransforms( data, ds.transform );
            }

            // Store into variable
            if ( ds.into ) {
                this.vars[ ds.into ] = data;
            }
        } catch ( err ) {
            await this.ui.hideLoading();
            console.error( 'Fetch exception:', err );
            if ( step.on_error ) {
                return { goto: step.on_error };
            }
            await this.showErrorBubble( AssistantRuntime.ERROR_MESSAGES.fetch_exception );
        }

        return null;
    };

    /**
     * T22brand-3: handle fetch step for queries[] (multi-QAL orchestration)
     *
     * - N 本の QAL を逐次発行し、$results[label] dict に格納
     * - 各発行ごとに showLoading('読み込み中 i/N') で進捗更新
     * - 失敗時は fetch step 全体を失敗扱い (既存 on_error 遷移)
     * - transform[] の最初の combine が dict→配列の変換を担う
     */
    AssistantRuntime.prototype.handleFetchQueries = async function( step, ds ) {
        var queries = ds.queries;
        var total = queries.length;
        var results = {};
        var loadingMsg = ( ds.loading_text || '読み込み中' );

        try {
            for ( var i = 0; i < total; i++ ) {
                this.ui.showLoading( loadingMsg + ' ' + ( i + 1 ) + '/' + total );

                var elem = queries[i];
                var qal = this.resolveQueryVars( this.deepClone( elem.qal ) );
                // Issue #1535: queries[] 経路も 1 本ずつ記録（label 付き）。
                this._recordRun( 'fetch', { ds_type: 'native_qal', query: qal, label: elem.label } );
                var response = await this.fetchData( qal, 'native_qal' );

                if ( ! response || ! response.success ) {
                    await this.ui.hideLoading();
                    var errMsg = ( response && response.data && response.data.message ) ? response.data.message : 'Data fetch failed (queries[' + i + '] label=' + elem.label + ').';
                    console.error( 'Fetch error:', errMsg );
                    if ( step.on_error ) {
                        return { goto: step.on_error };
                    }
                    // Issue #1577（A-9）: エラーバブル統一（#1456 W-6）の取り残し＝ showErrorBubble へ
                    await this.showErrorBubble( errMsg );
                    return null;
                }

                results[ elem.label ] = response.data.data || [];
            }

            await this.ui.hideLoading();

            // Apply transform pipeline.
            // 仕様 §7.9: queries[] を使う data_source は transform[0] が combine 必須
            // (バリデーションは preprocessManifest で実施済み)。
            var firstOp = ds.transform[0];
            var remaining = ds.transform.slice( 1 );
            var data = this.transformCombine( results, firstOp );
            data = this.applyTransforms( data, remaining );

            if ( ds.into ) {
                this.vars[ ds.into ] = data;
            }
        } catch ( err ) {
            await this.ui.hideLoading();
            console.error( 'Fetch exception (queries):', err );
            if ( step.on_error ) {
                return { goto: step.on_error };
            }
            // Issue #1577（A-9）: 文言も経路も handleFetch と同じ（ERROR_MESSAGES.fetch_exception）
            await this.showErrorBubble( AssistantRuntime.ERROR_MESSAGES.fetch_exception );
        }

        return null;
    };

    /**
     * Make AJAX call to fetch data
     *
     * @param {Object} query  Query payload (legacy manifest 簡易クエリ or QAL ネイティブ JSON)
     * @param {string} type   'qal' (default) or 'native_qal'
     */
    AssistantRuntime.prototype.fetchData = function( query, type ) {
        var self = this;
        return new Promise( function( resolve ) {
            jQuery.ajax({
                type: 'POST',
                url: qahm.ajax_url,
                dataType: 'json',
                data: {
                    'action': 'qahm_ajax_fetch_assistant_data',
                    'nonce': qahm.nonce_api,
                    'query': JSON.stringify( query ),
                    'tracking_id': self.systemVars.tracking_id || 'all',
                    'type': type || 'qal'
                }
            }).done( function( data ) {
                resolve( data );
            }).fail( function( xhr, status, error ) {
                console.error( 'AJAX fetch failed:', status, error );
                resolve( null );
            });
        });
    };

    /**
     * Handle table step
     */
    AssistantRuntime.prototype.handleTable = async function( step ) {
        var tableName = step.table;
        var tableDef = this.manifest.tables ? this.manifest.tables[tableName] : null;

        if ( ! tableDef ) {
            console.error( 'Table definition not found:', tableName );
            return null;
        }

        var data = this.resolveValue( tableDef.source );
        if ( ! Array.isArray( data ) ) {
            data = [];
        }

        var rowAction = step.row_action;

        // Display-only table (no row_action): render and continue (unchanged behavior).
        if ( ! rowAction ) {
            qahm.AssistantTable.renderTable( tableDef, data, this.ui.getContainer(), this, this.translations );
            return null;
        }

        // Issue #1386: selectable table. Render with onRowClick and wait for the user to click a row,
        // then echo the selection, apply set ($row.*), clear, and goto — mirroring choices semantics.
        var self = this;
        var clickedRowEl = null;
        var clicked = await new Promise( function( resolve ) {
            var resolved = false;
            qahm.AssistantTable.renderTable( tableDef, data, self.ui.getContainer(), self, self.translations, {
                onRowClick: function( rowData, rowIndex, rowEl ) {
                    if ( resolved ) return; // first click wins
                    resolved = true;
                    clickedRowEl = rowEl || null;
                    resolve( rowData );
                }
            } );
        } );

        if ( ! clicked || typeof clicked !== 'object' ) {
            return null;
        }

        // Selection done: remove the "clickable" affordance from every row (cursor / ▶ cursor /
        // hover-lane all go away — the table is no longer interactive), then mark the chosen row
        // as selected so it stays clearly highlighted. The selected highlight is hover-stable
        // (CSS pins it over qaTable's default gray hover), so re-hovering it doesn't flicker.
        if ( clickedRowEl ) {
            var tableEl = ( typeof clickedRowEl.closest === 'function' ) ? clickedRowEl.closest( 'table' ) : null;
            if ( tableEl ) {
                var clickableRows = tableEl.querySelectorAll( '.qa-row-clickable' );
                for ( var ri = 0; ri < clickableRows.length; ri++ ) {
                    clickableRows[ri].classList.remove( 'qa-row-clickable' );
                    clickableRows[ri].removeAttribute( 'tabindex' );
                    clickableRows[ri].removeAttribute( 'role' );
                }
            }
            clickedRowEl.classList.add( 'qa-row-selected' );
        }

        // Echo the selected row's label_field into the conversation history (like a chosen button).
        // The value is raw row data (e.g. a GSC keyword / page title — externally influenceable),
        // and echoSelection renders it via _displayText's innerHTML path, so escape it here to
        // prevent HTML/script injection into the admin conversation (Issue #1386 review).
        var labelVal = clicked[ rowAction.label_field ];
        if ( ! rowAction.clear && labelVal !== undefined && labelVal !== null && labelVal !== '' ) {
            await this.ui.echoSelection( this.escapeHtml( String( labelVal ) ) );
        }

        // Apply set ($row.* resolved against the clicked row).
        if ( rowAction.set ) {
            var resolvedSet = this.resolveSetFromRow( rowAction.set, clicked );
            var setKeys = Object.keys( resolvedSet );
            for ( var i = 0; i < setKeys.length; i++ ) {
                self.vars[ setKeys[i] ] = resolvedSet[ setKeys[i] ];
            }
        }

        if ( rowAction.clear ) {
            this.ui.clearConversation();
        }
        if ( rowAction.goto ) {
            return { goto: rowAction.goto };
        }
        return null;
    };

    /**
     * Handle chart step (rendering lives in qahm-assistant-chart.js; Issue #1280 Phase 4)
     */
    AssistantRuntime.prototype.handleChart = async function( step ) {
        var chartName = step.chart;
        var chartDef = this.manifest.charts ? this.manifest.charts[chartName] : null;

        if ( ! chartDef ) {
            console.error( 'Chart definition not found:', chartName );
            return null;
        }

        var data = this.resolveValue( chartDef.source );
        if ( ! Array.isArray( data ) ) {
            data = [];
        }

        qahm.AssistantChart.renderChart( chartDef, data, this.ui.getContainer(), this, this.translations );

        return null;
    };

    /**
     * Handle scorecard step (Issue #1409): render a summary-card group.
     * Named reference into manifest.scorecards (same shape as chart). The adapter
     * builds the DOM with textContent only — no data source array is resolved.
     */
    AssistantRuntime.prototype.handleScorecard = async function( step ) {
        var name = step.scorecard;
        var def = this.manifest.scorecards ? this.manifest.scorecards[name] : null;

        if ( ! def ) {
            console.error( 'Scorecard definition not found:', name );
            return null;
        }

        qahm.AssistantScorecard.renderScorecard( def, this.ui.getContainer(), this, this.translations );

        return null;
    };

    // callout / divider / html handlers live in qahm-assistant-blocks.js
    // (the adapter that owns those block types registers them itself —
    // Issue #1449 PR-D2).

    /**
     * 単一条件（`{ var, is, value }`）を評価する。
     *
     * Issue #1308: 比較の実装は matchOperator に一本化（transform.filter / tally の when と
     * 同一規則）。従来ここは①`value` を一切解決しない（`"$other"` がリテラル比較になる）
     * ②eq/neq が厳密等価（`===`）＝フォーム値は必ず文字列なので `{"is":"eq","value":4}` が
     * 永遠に不成立、しかも警告なし、という2つの silent fail を持っていた。
     *
     * @param  {Object} cond  単一条件
     * @param  {string} where 警告・例外文の主語（`all` の要素は添字つきになる）
     * @return {boolean} 条件が成立したか
     * @throws {Error} 条件が object でない／未知の `is`
     */
    AssistantRuntime.prototype.evalIfCondition = function( cond, where ) {
        if ( ! cond || typeof cond !== 'object' || Array.isArray( cond ) ) {
            throw new Error( where + ' must be a condition object (e.g. { "var": "x", "is": "eq", "value": 1 })' );
        }
        // `var` の欠落を fail-loud にする。放置すると this.vars[undefined] が undefined になり
        // `empty` が **成立** する＝エラーも警告も出さずに then が発火する（`all` の要素なら
        // その要素だけ黙って真になる）。未知の `is` は throw するのに `var` 欠落は「成立」へ
        // 倒れる、という一番気づきにくい非対称を塞ぐ。schema は required:["var","is"] +
        // minLength:1 で既に弾くが、ZIP+PHP 直配布経路は validator を通らない。
        if ( typeof cond['var'] !== 'string' || '' === cond['var'] ) {
            throw new Error( where + ' requires a non-empty "var" (got ' + JSON.stringify( cond['var'] ) + ')' );
        }
        var varName = cond['var'];
        var val = this.vars[varName];
        var is = cond.is;

        if ( is === 'empty' ) {
            return this.isEmpty( val );
        }
        if ( is === 'not_empty' ) {
            return ! this.isEmpty( val );
        }
        if ( 'in' === is && ! Array.isArray( cond.value ) &&
             ! ( typeof cond.value === 'string' && '$' === cond.value.charAt( 0 ) ) ) {
            // `in` のオペランドは配列（か配列に解決される "$var"）でなければならない。
            // 素の文字列を書くと resolveForEachIn が null を返し、console 警告 1 本だけ残して
            // **静かに常に不成立**になる＝ロードマップが消しに来た「静かに間違える」形。
            // 宣言面の検査は両 validator の E_REF_IF_IN_OPERAND（transform.filter の `in` が
            // schema で受けているのと同じ制約。if では `is` 依存の条件付きになるため、
            // justinrainbow が if/then を評価しない〔#1460 W-8・実測〕以上コード側で持つ）。
            throw new Error( where + ': in requires an array value (or a "$var" that resolves to one), got ' + JSON.stringify( cond.value ) );
        }
        if ( AssistantRuntime.IF_OPERATORS.indexOf( is ) === -1 ) {
            // fail-loud（#1510 と同じ思想）＝schema が弾く形でも、ZIP+PHP 直配布経路は
            // validator を通らないため runtime 側にも防護を置く。従来は未知の is が
            // どの case にも当たらず matched=false のまま素通り＝分岐が黙って死んでいた。
            throw new Error( 'unknown if operator "' + is + '" for var "' + varName + '" (supported: ' + AssistantRuntime.IF_OPERATORS.join( '/' ) + ')' );
        }
        return this.matchOperator( val, is, cond.value, 'if', String( varName ), where + ' var "' + varName + '"' );
    };

    /**
     * `if` の分岐（`then` / `else`）の**構造だけ**を検査する。Issue #1543 / B-6。
     *
     * 適用（`applyIfBranch`）から切り離してあるのは、**取られなかった側の分岐も検査する**ため。
     * 取られた側だけ検査すると「壊れた `else` を持つ manifest は、条件が成立している間は無害で、
     * ある日ユーザーの入力が変わった瞬間に初めて壊れる」＝fail-loud が実行時データに左右される。
     * `all` を短絡させない理由（下記 handleIf）とまったく同じ話。
     *
     * @param  {Object} branch 分岐（`{ set?, goto? }`）
     * @param  {string} label  例外文の主語（'then' / 'else'）
     * @throws {Error} 分岐が object でない／`set` も `goto` も無い／`set` が空
     */
    AssistantRuntime.prototype.checkIfBranch = function( branch, label ) {
        if ( ! branch || typeof branch !== 'object' || Array.isArray( branch ) ) {
            throw new Error( 'if "' + label + '" must be an object with "set" and/or "goto"' );
        }
        var hasSet = ( branch.set !== undefined );
        var hasGoto = ( branch.goto !== undefined );
        if ( ! hasSet && ! hasGoto ) {
            // 空の分岐は「書いたのに何も起きない」＝黙って死ぬ形。schema も minProperties で
            // 弾くが、ZIP+PHP 直配布経路は validator を通らないので runtime にも置く。
            throw new Error( 'if "' + label + '" has neither "set" nor "goto" (it would do nothing)' );
        }
        if ( hasSet ) {
            if ( ! branch.set || typeof branch.set !== 'object' || Array.isArray( branch.set ) ) {
                throw new Error( 'if "' + label + '".set must be an object of variable assignments' );
            }
            // `set: {}` も「書いたのに何も起きない」＝上と同じ穴（schema の
            // set.minProperties:1 が弾く形を runtime が見逃さないようにする）。
            if ( 0 === Object.keys( branch.set ).length ) {
                throw new Error( 'if "' + label + '".set is empty (it would assign nothing)' );
            }
        }
    };

    /**
     * `if` の分岐（`then` / `else`）を適用する。Issue #1543 / B-6。
     *
     * `set` → `goto` の順（同じ分岐で変数を置いてから飛べる）。`set` の解決は
     * `handleSet` と同一実装（`applyVarAssignments`）＝新しい解決規則を持ち込まない。
     * 構造はここへ来る前に `checkIfBranch` が検査済み。
     *
     * @param  {Object} branch 分岐（`{ set?, goto? }`）
     * @return {Object|null} `{ goto }` またはヌル
     */
    AssistantRuntime.prototype.applyIfBranch = function( branch ) {
        if ( branch.set !== undefined ) {
            this.applyVarAssignments( branch.set );
        }
        if ( branch.goto !== undefined ) {
            return { goto: branch.goto };
        }
        return null;
    };

    /**
     * Handle if/then/else step
     *
     * Issue #1543 / B-6: 器の拡張（`then.set` / `else` / `all`＝AND）。比較の中身は
     * #1308 で `matchOperator` に一本化済みで、ここでは触らない。
     *
     * `all` の評価は**短絡しない**。短絡すると「未知の演算子・壊れた要素で throw するか」が
     * 手前の要素の値（＝実行時のデータ）に左右され、fail-loud が確率的になる。要素数は
     * たかだか数個なので全件評価して構造・語彙の誤りを必ず表に出す
     * （#1531 の 🟡-2＝tally の `when` 構造検査を行ループの外へ出したのと同じ思想）。
     */
    AssistantRuntime.prototype.handleIf = function( step ) {
        var cond = step['if'];
        var hasThen = ( step.then !== undefined );
        var hasElse = ( step['else'] !== undefined );

        // 分岐が1つも無い if は「条件を書いたのに何も起きない」＝黙って死ぬ形。
        // 条件の評価より先に見る（データに関わらず必ず落ちる）。
        if ( ! hasThen && ! hasElse ) {
            throw new Error( 'if step requires "then" or "else"' );
        }
        // ★分岐の構造は「取られる側」だけでなく**両方**を、条件の評価より前に検査する
        //   （壊れた else が、条件が成立している間だけ無害に見える状態を作らない）。
        if ( hasThen ) { this.checkIfBranch( step.then, 'then' ); }
        if ( hasElse ) { this.checkIfBranch( step['else'], 'else' ); }

        var matched;
        if ( cond && typeof cond === 'object' && ! Array.isArray( cond ) && cond.all !== undefined ) {
            if ( cond['var'] !== undefined || cond.is !== undefined ) {
                throw new Error( 'if must be either a single condition ({var,is}) or {all:[...]}, not both' );
            }
            if ( ! Array.isArray( cond.all ) || 0 === cond.all.length ) {
                throw new Error( 'if.all must be a non-empty array of conditions' );
            }
            matched = true;
            for ( var i = 0; i < cond.all.length; i++ ) {
                var sub = cond.all[i];
                if ( sub && typeof sub === 'object' && ! Array.isArray( sub ) && sub.all !== undefined ) {
                    // 入れ子は意図的に非対応（許すと条件式エンジンへの入口になる・B-6 の設計）
                    throw new Error( 'if.all[' + i + '] must not nest another "all" (nesting is intentionally unsupported — use a preceding if with then.set instead)' );
                }
                if ( ! this.evalIfCondition( sub, 'if.all[' + i + ']' ) ) {
                    matched = false;
                }
            }
        } else {
            matched = this.evalIfCondition( cond, 'if' );
        }

        if ( matched ) {
            return hasThen ? this.applyIfBranch( step.then ) : null;
        }
        return hasElse ? this.applyIfBranch( step['else'] ) : null;
    };

    /**
     * Handle goto step
     */
    AssistantRuntime.prototype.handleGoto = function( step ) {
        return { goto: step.goto };
    };

    /**
     * Handle set step
     */
    AssistantRuntime.prototype.handleSet = function( step ) {
        var setObj = step.set;
        if ( setObj && typeof setObj === 'object' ) {
            this.applyVarAssignments( setObj );
        }
        return null;
    };

    /**
     * 変数代入（`set`）の適用。`set` step と `if` の `then` / `else` が共有する
     * （Issue #1543 / B-6＝2 経路で解決規則が分かれないよう 1 実装に寄せる）。
     *
     * @param {Object} setObj 変数名 → 値（`resolveValue` を通す）
     */
    AssistantRuntime.prototype.applyVarAssignments = function( setObj ) {
        var keys = Object.keys( setObj );
        for ( var i = 0; i < keys.length; i++ ) {
            this.vars[ keys[i] ] = this.resolveValue( setObj[ keys[i] ] );
        }
    };

    /**
     * Handle config_read step
     *
     * Reads config data from server and stores into variables.
     * Meta fields are flattened: {into}_count, {into}_next_id, {into}_is_max
     */
    AssistantRuntime.prototype.handleConfigRead = async function( step ) {
        var def = step.config_read;
        var category = def.category;
        var into = def.into;
        var self = this;

        this.ui.showLoading();

        try {
            var response = await new Promise( function( resolve ) {
                jQuery.ajax({
                    type: 'POST',
                    url: qahm.ajax_url,
                    dataType: 'json',
                    data: {
                        'action': 'qahm_ajax_read_config',
                        'nonce': qahm.nonce_api,
                        'category': category,
                        'store': self.resolveValue( def.store || '' ),
                        'tracking_id': self.systemVars.tracking_id || 'all',
                        'plugin_id': self.manifest.id || '',
                        'key': self.resolveValue( def.key || '' )
                    }
                }).done( function( data ) {
                    resolve( data );
                }).fail( function( xhr, status, error ) {
                    console.error( 'config_read AJAX failed:', status, error );
                    resolve( null );
                });
            });

            await this.ui.hideLoading();

            if ( ! response || ! response.success ) {
                if ( def.on_error ) {
                    return { goto: def.on_error };
                }
                // Issue #1456 W-6(c): on_error 未指定でも UI 通知ゼロにしない（従来は silent return で
                // fetch のエラーバブルと非対称だった）。fetch と同じ `<p>Error: …</p>` バブルに統一。
                console.error( 'config_read failed:', category );
                await this.showErrorBubble( AssistantRuntime.ERROR_MESSAGES.config_read_failed );
                return null;
            }

            var data = response.data;

            // Store main data (items only)
            // Preserve null: when items is null, into var becomes null so `is: empty` returns true (Issue #1214).
            if ( into ) {
                this.vars[ into ] = ( data.items !== null && data.items !== undefined ) ? data.items : null;

                // Flatten meta fields — suffixes are category-specific
                // goals: _count, _next_id, _is_max
                if ( data.count !== undefined ) {
                    this.vars[ into + '_count' ] = data.count;
                }
                if ( data.next_available_id !== undefined ) {
                    this.vars[ into + '_next_id' ] = data.next_available_id;
                }
                if ( data.is_max_reached !== undefined ) {
                    this.vars[ into + '_is_max' ] = data.is_max_reached;
                }
            }
        } catch ( err ) {
            await this.ui.hideLoading();
            console.error( 'config_read exception:', err );
            if ( def.on_error ) {
                return { goto: def.on_error };
            }
            // Issue #1456 W-6(c): 例外時も on_error 未指定なら silent にせずバブルを出す。
            await this.showErrorBubble( AssistantRuntime.ERROR_MESSAGES.config_read_failed );
        }

        return null;
    };

    /**
     * Handle config_write step
     *
     * Writes config data to server.
     * IMPORTANT: Unlike handleFetch, this stores into variable BEFORE on_error goto,
     * so the error reason is available in the error scene for conditional branching.
     */
    AssistantRuntime.prototype.handleConfigWrite = async function( step ) {
        var def = step.config_write;
        var category = def.category;
        var into = def.into;
        var self = this;

        // Resolve key
        var resolvedKey = this.resolveValue( def.key || '' );

        // Resolve value using resolveQueryVars (deep copy first — value is an object)
        var resolvedValue = JSON.parse( JSON.stringify( def.value || {} ) );
        resolvedValue = this.resolveQueryVars( resolvedValue );

        // Issue #1228 (Stage A): forward rotate option to server for auto-eviction.
        // When manifest declares config_write.rotate.{max_entries,strategy}, server
        // evicts oldest keys instead of returning limit_key_count. Omit the field
        // entirely (rather than send "null") when undeclared, so server-side parsing
        // does not have to special-case empty strings.
        var ajaxData = {
            'action': 'qahm_ajax_write_config',
            'nonce': qahm.nonce_api,
            'category': category,
            'store': self.resolveValue( def.store || '' ),
            'tracking_id': self.systemVars.tracking_id || 'all',
            'plugin_id': self.manifest.id || '',
            'key': resolvedKey,
            'value': JSON.stringify( resolvedValue )
        };
        if ( def.rotate && typeof def.rotate === 'object' && ! Array.isArray( def.rotate ) ) {
            ajaxData.rotate = JSON.stringify( def.rotate );
        }

        this.ui.showLoading();

        try {
            var response = await new Promise( function( resolve ) {
                jQuery.ajax({
                    type: 'POST',
                    url: qahm.ajax_url,
                    dataType: 'json',
                    data: ajaxData
                }).done( function( data ) {
                    resolve( data );
                }).fail( function( xhr, status, error ) {
                    console.error( 'config_write AJAX failed:', status, error );
                    resolve( null );
                });
            });

            await this.ui.hideLoading();

            if ( ! response || ! response.success ) {
                // Store error reason into variable BEFORE goto (unlike handleFetch)
                if ( into ) {
                    var reason = ( response && response.data && response.data.reason )
                        ? response.data.reason
                        : 'server_error';
                    this.vars[ into ] = reason;
                }
                if ( def.on_error ) {
                    return { goto: def.on_error };
                }
                return null;
            }

            // Success — store actual status ('done' or 'in_progress')
            if ( into ) {
                this.vars[ into ] = ( response.data && response.data.status ) ? response.data.status : 'done';
            }
        } catch ( err ) {
            await this.ui.hideLoading();
            console.error( 'config_write exception:', err );
            if ( into ) {
                this.vars[ into ] = 'server_error';
            }
            if ( def.on_error ) {
                return { goto: def.on_error };
            }
        }

        return null;
    };

    // ─── Variable system ───────────────────────────────

    /**
     * Resolve a JSON value that may be a variable reference
     */
    AssistantRuntime.prototype.resolveValue = function( val ) {
        if ( typeof val !== 'string' ) return val;

        // Issue #1510: 未定義の $var / $sys.* を可視化（expandTemplate L1539 と対称化）。
        // タイポが '' のまま QAL クエリへ無言で飛ぶのを防ぐ。返り値は従来どおり '' ＝画面不変。
        if ( val.indexOf( '$sys.' ) === 0 ) {
            var sysKey = val.substring( 5 );
            if ( this.systemVars[sysKey] === undefined ) {
                this._warnOnce( 'var', 'sys.' + sysKey, 'undefined variable $sys.' + sysKey );
                return '';
            }
            return this.systemVars[sysKey];
        }
        if ( val.indexOf( '$' ) === 0 ) {
            var varName = val.substring( 1 );
            if ( this.vars[varName] === undefined ) {
                this._warnOnce( 'var', varName, 'undefined variable $' + varName );
                return '';
            }
            return this.vars[varName];
        }
        return val;
    };

    /**
     * Expand template strings: {$var}, {$var|format}, {$var|format:arg}
     *
     * Issue #1575 (B-5): 書式指定子に引数を許す（`round:2` / `before:" - "`）。
     * 引数は **整数リテラル** か **ダブルクォート文字列** のどちらかだけ＝裸の文字列
     * （`before:@`）は受けない。空白・`}`・`:` が混ざると文法が壊れるため、書き間違いは
     * 「展開されない」ではなく validator の E_REF_TEMPLATE_SYNTAX で配布前に止める。
     *
     * ★この正規表現は src/core/assistant-schema/validator/ref-checks.js の TEMPLATE_RE と
     *   同一でなければならない（片方だけ新形を知っていると、新形が「未宣言変数」に化けて
     *   誤検知する／逆に検査をすり抜ける）。両方を必ず同時に直すこと。
     */
    AssistantRuntime.prototype.expandTemplate = function( text ) {
        if ( typeof text !== 'string' ) return text;

        var self = this;
        return text.replace( /\{\$([a-zA-Z0-9_.]+)(?:\|([a-zA-Z_]+)(?::(-?\d+|"(?:[^"\\]|\\.)*"))?)?\}/g, function( match, varPath, format, rawArg ) {
            var val;
            if ( varPath.indexOf( 'sys.' ) === 0 ) {
                val = self.systemVars[ varPath.substring(4) ];
            } else {
                val = self.vars[varPath];
            }
            // Issue #1456 W-6(d): 未定義変数（キー自体が無い）を可視化。null は「設定済みだが空」なので
            // 対象外。表示は従来どおり '' に落とす（画面不変）＝warn を足すだけ。
            if ( val === undefined ) {
                self._warnOnce( 'var', varPath, 'undefined variable {$' + varPath + '}' );
            }
            if ( val === undefined || val === null ) val = '';
            if ( format ) {
                val = self.formatValue( val, format, self._parseFormatArg( rawArg ) );
            }
            return String( val );
        });
    };

    /**
     * Decode the raw argument captured by the template regex.
     * Issue #1575: `undefined`（引数なし）／整数リテラル／ダブルクォート文字列の3形だけ。
     * 引用符の中は `\"` と `\\` のみをエスケープとして解く（他の `\x` は素のまま残す＝
     * 区切り文字にバックスラッシュを含めたいケースを壊さない）。
     */
    AssistantRuntime.prototype._parseFormatArg = function( rawArg ) {
        // 文字列以外は「引数なし」として扱う。正規表現の捕捉グループを将来いじったときに
        // 数値（マッチ位置）が流れ込んで TypeError で会話が止まるのを防ぐ（変異テストで発見）。
        if ( typeof rawArg !== 'string' || rawArg === '' ) return undefined;
        if ( rawArg.charAt( 0 ) === '"' ) {
            return rawArg.slice( 1, -1 ).replace( /\\(["\\])/g, '$1' );
        }
        return parseInt( rawArg, 10 );
    };

    /**
     * Resolve t: prefixed translation key
     */
    AssistantRuntime.prototype.resolveTranslation = function( text ) {
        if ( typeof text !== 'string' ) return text;
        if ( text.indexOf( 't:' ) !== 0 ) return text;

        var keyPath = text.substring( 2 );
        var parts = keyPath.split( '.' );
        var current = this.translations;

        for ( var i = 0; i < parts.length; i++ ) {
            if ( current && typeof current === 'object' && current[ parts[i] ] !== undefined ) {
                current = current[ parts[i] ];
            } else {
                // Issue #1456 W-6(d): 翻訳キー欠落の可視化（表示は raw text のまま＝画面不変）。
                this._warnOnce( 'i18n', keyPath, 'missing translation key t:' + keyPath );
                return text; // Key not found
            }
        }

        if ( typeof current === 'string' ) return current;
        // t: キーは在るが文字列に解決しなかった（中間ノードで止まった等）。表示は raw text のまま。
        this._warnOnce( 'i18n', keyPath, 'translation key t:' + keyPath + ' did not resolve to a string' );
        return text;
    };

    /**
     * Resolve variables in a QAL query object (deep)
     */
    AssistantRuntime.prototype.resolveQueryVars = function( obj ) {
        if ( typeof obj === 'string' ) {
            return this.resolveValue( obj );
        }
        if ( Array.isArray( obj ) ) {
            for ( var i = 0; i < obj.length; i++ ) {
                obj[i] = this.resolveQueryVars( obj[i] );
            }
            return obj;
        }
        if ( obj && typeof obj === 'object' ) {
            var keys = Object.keys( obj );
            for ( var k = 0; k < keys.length; k++ ) {
                obj[ keys[k] ] = this.resolveQueryVars( obj[ keys[k] ] );
            }
            return obj;
        }
        return obj;
    };

    /**
     * Format a value using a named format
     *
     * Issue #1575 (B-5): `round` / `before` / `after` を追加（引数つき）。既存4種の挙動は不変。
     * ★case の集合は vocab-ledger.json の format_specifiers と CI（vocab-ledger-check 検査⑦）で
     *   機械突合される＝case を足したら台帳にも足すこと（逆も同じ）。
     */
    AssistantRuntime.prototype.formatValue = function( value, format, arg ) {
        var num;
        switch ( format ) {
            case 'integer':
                num = parseInt( value, 10 );
                return isNaN( num ) ? value : num.toLocaleString();
            case 'float':
                num = parseFloat( value );
                return isNaN( num ) ? value : num.toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
            case 'percentage':
                num = parseFloat( value );
                return isNaN( num ) ? value : num.toFixed( 1 ) + '%';
            case 'duration':
                return this.formatDuration( value );
            case 'round':
                return this.formatRound( value, arg );
            case 'before':
            case 'after':
                return this.formatCut( value, format, arg );
            default:
                // Issue #1575 (B-5): 未知の指定子（`integar` 等のタイポ）は従来 **黙って**素の値を
                // 返していた＝声を出さない失敗。表示は従来どおり（画面不変・会話は止めない）で、
                // 警告だけ足す。配布を止めるのは validator の E_REF_TEMPLATE_SYNTAX の役目。
                this._warnOnce( 'format', String( format ), 'unknown format specifier "' + format + '" (value shown unformatted)' );
                return value;
        }
    };

    /**
     * Format seconds to HH:MM:SS
     */
    AssistantRuntime.prototype.formatDuration = function( seconds ) {
        var s = parseInt( seconds, 10 );
        if ( isNaN( s ) || s < 0 ) return String( seconds );
        var h = Math.floor( s / 3600 );
        var m = Math.floor( ( s % 3600 ) / 60 );
        var sec = s % 60;
        return String( h ).padStart( 2, '0' ) + ':' + String( m ).padStart( 2, '0' ) + ':' + String( sec ).padStart( 2, '0' );
    };

    /**
     * Round to a fixed number of decimals (Issue #1575 / B-5).
     * 引数は小数桁（省略時 0・0〜6）。桁区切りは付けない（付けたいときは `integer`）。
     * 数値に読めない値は **入力をそのまま返す**（既存 `integer` / `float` と同じ fail-soft）。
     */
    AssistantRuntime.prototype.formatRound = function( value, digits ) {
        var d = ( digits === undefined ) ? 0 : digits;
        if ( typeof d !== 'number' || isNaN( d ) || d < 0 || d > 6 ) {
            this._warnOnce( 'format', 'round:' + d, 'round expects 0-6 decimals (got "' + d + '") — using 0' );
            d = 0;
        }
        var num = parseFloat( value );
        return isNaN( num ) ? value : num.toFixed( d );
    };

    /**
     * Cut a string at the first occurrence of a separator (Issue #1575 / B-5).
     * `before` = 区切りの手前 ／ `after` = 区切りの後ろ。
     *
     * ★守りたいのは「表示が消えないこと」。したがって縮退の条件は **「結果が空になったか」**
     *   （区切りの有無ではない）。実需要である「タイトルから ' - サイト名' を落とす」では、
     *   区切りを含まないタイトルは異常ではなく通常のケースで、空にするとタイトルが消えてしまう。
     *   同じことは **区切りが先頭にある**（`before` で " - サイト名"）／**末尾にある**（`after`）
     *   ときにも起きる＝slice が空文字を返す。ここを「区切りが無いとき」だけで守ると、
     *   同じ事故が別の入り口から入ってくる（PR #1576 レビュー 🟡-1）。
     *   縮退したことは `_warnOnce` で可視化する（会話は止めない）。
     */
    AssistantRuntime.prototype.formatCut = function( value, mode, separator ) {
        if ( typeof separator !== 'string' || separator === '' ) {
            this._warnOnce( 'format', mode, mode + ' needs a quoted separator, e.g. {$var|' + mode + ':" - "}' );
            return value;
        }
        var s = String( value );
        var idx = s.indexOf( separator );
        if ( idx < 0 ) return value;
        var cut = ( 'before' === mode ) ? s.slice( 0, idx ) : s.slice( idx + separator.length );
        if ( '' === cut ) {
            this._warnOnce( 'format', mode + ':empty', mode + ' would have produced an empty string (the separator is at the ' +
                ( 'before' === mode ? 'start' : 'end' ) + ' of the value) — the original value is shown instead' );
            return value;
        }
        return cut;
    };

    /**
     * Check if a value is empty
     */
    AssistantRuntime.prototype.isEmpty = function( val ) {
        if ( val === null || val === undefined || val === '' ) return true;
        if ( Array.isArray( val ) && val.length === 0 ) return true;
        // Empty plain object {} is also empty (Issue #1214). 0 / false / 'abc' remain non-empty.
        if ( typeof val === 'object' && Object.keys( val ).length === 0 ) return true;
        return false;
    };

    // ─── Limited markdown ──────────────────────────────

    // Colouring vocabulary for inline body tokens (:::callout / ::stat) — Issue #1416.
    // Must stay identical to ALLOWED_LEVELS in qahm-assistant-blocks.js (block callout):
    // the block step and the inline token are two entrances to one visual entity.
    var INLINE_TOKEN_LEVELS = { good: 1, mid: 1, bad: 1, info: 1 };

    /**
     * Map an author-supplied level to a CSS class suffix through the fixed
     * whitelist only (never build a class name from raw author data).
     *
     * @param {string} level     Author-supplied level value (already escaped).
     * @param {string} fallback  Class to use when level is absent / out of vocabulary.
     * @return {string}
     */
    AssistantRuntime.prototype.tokenLevelClass = function( level, fallback ) {
        return ( level && INLINE_TOKEN_LEVELS[ level ] ) ? 'is-' + level : fallback;
    };

    /**
     * Render limited markdown: ## h3, ### h4, **bold**, [text](url), \n (literal and real LF),
     * :::callout fenced block, ::stat inline chip (Issue #1416)
     */
    AssistantRuntime.prototype.renderLimitedMarkdown = function( text ) {
        if ( typeof text !== 'string' ) return text;

        var self = this;

        // HTML escape first
        text = this.escapeHtml( text );

        // Headings: ### h4 must come before ## h3 (longer prefix first) — Issue #1218
        text = text.replace( /^### (.+)$/gm, '<h4>$1</h4>' );
        text = text.replace( /^## (.+)$/gm, '<h3>$1</h3>' );

        // **bold**
        text = text.replace( /\*\*(.+?)\*\*/g, '<strong>$1</strong>' );

        // [text](url) — allow only http/https/mailto schemes (Issue #1299: block javascript:/data:/vbscript: など)
        text = text.replace( /\[([^\]]+)\]\(([^)]+)\)/g, function( match, linkText, url ) {
            // ブラウザが href 解釈時に除去する制御文字を先に除去（java\tscript: バイパス対策・Issue #1299 B-1）
            var cleanUrl = url.replace( /[\t\n\r\f\v\0]/g, '' );
            // '::stat{' が URL に紛れると後段の ::stat 写像が href 属性の中で発火し、固定マークアップの
            // 引用符がアンカーを壊す（#1417 レビュー 🟡-1）。機能等価な %7B（'{'）へエンコードして無害化する。
            cleanUrl = cleanUrl.replace( /::stat\{/g, '::stat%7B' );
            var scheme = cleanUrl.match( /^\s*([a-z][a-z0-9+.\-]*)\s*:/i );
            if ( scheme ) {
                var s = scheme[1].toLowerCase();
                if ( s !== 'http' && s !== 'https' && s !== 'mailto' ) {
                    return linkText; // Block disallowed scheme
                }
            }
            return '<a href="' + cleanUrl + '" target="_blank" rel="noopener noreferrer">' + linkText + '</a>';
        });

        // :::callout fenced block → same .qa-callout markup as the block step — Issue #1416.
        // Placed after the per-line transforms (so a heading on the first body line has
        // already matched ^## while the line structure was intact) and before <br>
        // conversion (the fence lines themselves must never become <br>).
        // Consumes one LF before the opening fence and one after the closing fence,
        // so the emitted <div> has no adjacent <br>. Body is already escaped; heading /
        // bold / link inside the body are already rendered; body LFs become <br> below.
        text = text.replace(
            /(^|\n):::callout(?:\{([^}\n]*)\})?\n([\s\S]*?)\n:::(\n|$)/g,
            function( match, lead, attrs, body, trail ) {
                var levelMatch = ( attrs || '' ).match( /level=(\w+)/ );
                var cls = self.tokenLevelClass( levelMatch ? levelMatch[1] : '', 'is-note' );
                return '<div class="qa-callout ' + cls + '"><div class="qa-callout__body"><div class="qa-callout__text">' + body + '</div></div></div>';
            }
        );

        // Literal \n → <br> (legacy compatibility)
        text = text.replace( /\\n/g, '<br>' );
        // Real LF (JSON parsed newline) → <br> — Issue #1218
        text = text.replace( /\n/g, '<br>' );

        // ::stat inline chip — Issue #1416. Runs last (after <br> conversion) so the chip
        // never spans a line break. Attribute values are already escaped (escape-first);
        // quoted values therefore arrive as &quot;…&quot;. Whitelisted keys only
        // (label / value / unit / level) — unknown keys are ignored, level maps through
        // the fixed whitelist, no class is ever built from raw author data.
        text = text.replace( /::stat\{([^}]*)\}/g, function( match, attrs ) {
            // A real '<' inside the braces can only be markup we injected ourselves
            // (e.g. <br> from a multi-line attribute block) — leave the token literal.
            if ( attrs.indexOf( '<' ) !== -1 ) return match;

            var vals = {};
            var attrRe = /([a-zA-Z_]+)=(?:&quot;(.*?)&quot;|(\S+))/g;
            var am;
            while ( ( am = attrRe.exec( attrs ) ) !== null ) {
                vals[ am[1] ] = ( am[2] !== undefined ) ? am[2] : am[3];
            }
            if ( vals.value === undefined || vals.value === '' ) return match; // value is required

            var cls = self.tokenLevelClass( vals.level, '' );
            var html = '<span class="qa-stat' + ( cls ? ' ' + cls : '' ) + '">';
            if ( vals.label ) {
                html += '<span class="qa-stat__label">' + vals.label + '</span>';
            }
            html += '<span class="qa-stat__value">' + vals.value;
            if ( vals.unit ) {
                html += '<span class="qa-stat__unit">' + vals.unit + '</span>';
            }
            html += '</span></span>';
            return html;
        });

        return text;
    };

    /**
     * Escape HTML
     */
    AssistantRuntime.prototype.escapeHtml = function( str ) {
        if ( typeof str !== 'string' ) return str;
        return str
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' )
            .replace( /'/g, '&#039;' );
    };

    // ─── Transform pipeline ────────────────────────────

    /**
     * Apply transform pipeline to data array
     *
     * Issue #1510: 白名簿（実装済み語彙）に無いステップは throw する（fail-loud）。
     * 従来は else-if 連鎖から漏れたステップを警告ゼロで素通りしていた＝タイポや
     * 版ズレが「ズレても緑」になる親玉。呼び出し元は handleFetch / handleFetchQueries
     * の try/catch 内＝既存の統一エラーバブル（on_error goto 優先）に乗る。
     * combine は正規の位置（queries[] の transform[0]）では handleFetchQueries が
     * 先に処理するためここへは来ない＝到達した combine は常に位置違反として区別して報告する。
     */
    AssistantRuntime.prototype.applyTransforms = function( data, transforms ) {
        for ( var i = 0; i < transforms.length; i++ ) {
            var t = transforms[i];
            if ( t.sort !== undefined ) {
                data = this.transformSort( data, t.sort );
            } else if ( t.limit !== undefined ) {
                data = data.slice( 0, t.limit );
            } else if ( t.filter !== undefined ) {
                data = this.transformFilter( data, t.filter );
            } else if ( t.group_by !== undefined ) {
                data = this.transformGroupBy( data, t );
            } else if ( t.calc !== undefined ) {
                data = this.transformCalc( data, t );
            } else if ( t.lookup !== undefined ) {
                data = this.transformLookup( data, t );
            } else if ( t.set_var !== undefined ) {
                this.transformSetVar( data, t );
            } else if ( t.extract_host !== undefined ) {
                data = this.transformExtractHost( data, t );
            } else if ( t.tally !== undefined ) {
                data = this.transformTally( data, t.tally );
            } else if ( t.combine !== undefined ) {
                throw new Error( 'transform "combine" is only allowed as the first transform of a queries[] data source (transform[' + i + '])' );
            } else {
                throw new Error( 'unknown transform step at transform[' + i + ']: ' + Object.keys( t ).join( ',' ) );
            }
        }
        return data;
    };

    /**
     * Issue #1602: 並べ替えの数値判定を「型」ではなく「値が数値として読めるか」で行う。
     *
     * サーバー側 QAL の並べ替え（class-qahm-qal-executor.php の usort コールバック）は
     * `is_numeric( $va ) && is_numeric( $vb )` → `(float)` 比較。この関数は**その規則の JS 版**で、
     * 同じ語彙（sort）が層をまたいで同じ結果になるようにするためのもの。両方を同時に直すこと。
     *
     * PHP の is_numeric に合わせる（＝ JS の Number() より狭い）:
     *   - 前後の空白は許す。ただし **PHP が数値文字列で許す空白だけ**（半角スペース・\t\n\r\v\f）。
     *     String.prototype.trim は NBSP・全角スペース・BOM といった Unicode の空白まで落とすが、
     *     PHP の is_numeric は落とさない＝trim を使うと、そこだけ JS が数値・PHP が文字列になり、
     *     この関数の目的（層をまたいで同じ結果になること）が崩れる（PR #1604 レビュー 🟡-1・実測5件）。
     *   - 16進表記（"0x1A"）・"Infinity"・"NaN" は数値としない（Number() は通してしまう）
     *   - 空文字・空白のみは数値としない（Number('') は 0 になるため明示的に弾く）
     *   - 真偽値・配列・オブジェクトは数値としない（PHP の is_numeric と同じ）
     *
     * ★基準は PHP 8.0 以降。**末尾**の空白を数値文字列として許すのは 8.0 からで、本プラグインの
     *   サポート下限である PHP 7.4（qahm.php の Requires PHP）では `is_numeric("1 ")` は false ＝
     *   末尾に空白がある値だけ、7.4 のホストでは JS のほうが広いままになる（PR #1604 レビュー 🟡-2・
     *   7.4 実機では未測定）。先頭の空白は 7.4 でも許されるため差は出ない。
     *
     * @param {*} v 判定する値.
     * @return {boolean} 数値として読めるなら true.
     */
    var NUMERIC_RE = /^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/;
    // ★`/g` 付き＝`.replace()` 専用。`.test()` に使うと `lastIndex` が持ち越されて
    //   同じ値でも呼ぶたびに結果が変わる（NUMERIC_RE に `/g` が無いのと同じ理由）。
    var PHP_NUMERIC_WS_RE = /^[ \t\n\r\v\f]+|[ \t\n\r\v\f]+$/g;
    function isNumericValue( v ) {
        if ( typeof v === 'number' ) return isFinite( v );
        if ( typeof v !== 'string' ) return false;
        return NUMERIC_RE.test( v.replace( PHP_NUMERIC_WS_RE, '' ) );
    }

    /**
     * Sort transform
     *
     * Issue #1602: 従来は `typeof va === 'number'` で判定していたため、直前の group_by が
     * キーを String() で書き戻した列（transformGroupBy 参照）を並べ替えると、値は数値の
     * ままなのに**型が文字列になったという理由だけで**辞書順（1, 10, 11, 2, 20 …）になっていた。
     * エラーも警告も出ないので「上位◯件」が静かに嘘になる。判定を値ベースへ変更する。
     * 数値に読めない値どうしは従来どおり localeCompare（文字列列の並びは不変）。
     */
    AssistantRuntime.prototype.transformSort = function( data, sortDef ) {
        var keys = Object.keys( sortDef );
        if ( keys.length === 0 ) return data;

        var field = keys[0];
        var dir = sortDef[field];
        var asc = ( dir === 'asc' ) ? 1 : -1;

        return data.slice().sort( function( a, b ) {
            var va = a[field];
            var vb = b[field];
            if ( va === vb ) return 0;
            if ( va === null || va === undefined ) return 1;
            if ( vb === null || vb === undefined ) return -1;
            if ( isNumericValue( va ) && isNumericValue( vb ) ) {
                var na = Number( va );
                var nb = Number( vb );
                if ( na === nb ) return 0;
                return ( na < nb ? -1 : 1 ) * asc;
            }
            return String( va ).localeCompare( String( vb ) ) * asc;
        });
    };

    /**
     * Filter transform
     *
     * Issue #1510: 未知の演算子キーは throw する（fail-loud）。従来は known 演算子の
     * if 連鎖に当たらないキーを無言で素通り＝「絞ったつもりで絞れていない」が緑のまま
     * 通っていた（ZIP+PHP 直配布経路は validator を通らないため runtime 側にも防護が要る）。
     */
    AssistantRuntime.FILTER_OPERATORS = [ 'eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in', 'contains' ];

    /**
     * step の if がサポートする条件（step.schema.json の is enum と一致させる）。
     *
     * Issue #1543 / B-6 で `in` を追加した。`all`（AND）だけでは「いずれかに該当」が
     * 原理的に書けないが、`in` は**入れ子を許さない OR** ＝「条件式エンジンを育てない」
     * 方針を守ったまま OR を受けられる唯一の形。
     * `contains` は足さない＝`if` が見るのは `vars` のスカラー値で、部分一致は
     * `filter`（データ列）の領分。
     * ズレ検知＝test/vocab-check.js が schema の enum と機械突合し、
     * test/vocab-ledger-check.js が語彙裁定台帳（#1307）とも突合する。
     */
    AssistantRuntime.IF_OPERATORS = [ 'empty', 'not_empty', 'eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'in' ];

    /**
     * 比較演算子の統一規則（Issue #1308 / ロードマップ C-2）
     *
     * 条件評価の実装はここ1本。transform.filter / tally の when / step の if が
     * すべてこの関数を通る。従来は演算子ごとに4流儀へ分かれていた：
     *   ①filter の eq/neq＝resolveValue ＋ 厳密等価（=== ）
     *   ②filter の gt/gte/lt/lte＝解決なしの生値比較（"$var" を書くと NaN 比較で
     *     除外が一度も発動せず、絞ったつもりの未絞りデータが表示される）
     *   ③filter の in＝resolveForEachIn ＋ 厳密同一性（indexOf）
     *   ④step の if＝value を一切解決せず eq/neq は厳密等価（フォーム値は常に文字列
     *     なので数値リテラルとの比較が永遠に false ＝ Issue #1308 の silent fail）
     * ＝演算子を替えただけで意味が変わる状態。#1510 の tally when で確定した規則を
     * 唯一の規則へ昇格し、全経路をここへ寄せる。
     *
     * 規則:
     * - オペランドは必ず解決を通す（in の "$var" は resolveForEachIn・他は resolveValue）
     * - 値・オペランドの null / undefined は '' として扱う（欠損の同一視）
     * - eq / neq / in / contains ＝ 文字列形（String()）で比較
     * - gt / gte / lt / lte ＝ 両辺 Number() の数値比較。欠損・NaN は不成立＋_warnOnce
     * - 解決できなかった "$var" オペランドは全演算子で不成立＋_warnOnce（fail-closed）
     * - 未知の演算子は throw（fail-loud・#1510 と同じ白名簿）
     *
     * @param  {*}      rawVal   行値または変数値（生）
     * @param  {string} op       演算子（FILTER_OPERATORS のいずれか）
     * @param  {*}      rawCond  オペランド（未解決）
     * @param  {string} scope    _warnOnce の種別（'filter' / 'tally' / 'if'）
     * @param  {string} warnKey  _warnOnce の重複抑制キー（呼び出し元が場所で一意にする）
     * @param  {string} where    警告・例外文の主語（'filter field "sec"' 等）
     * @return {boolean} 条件が成立したか
     */
    AssistantRuntime.prototype.matchOperator = function( rawVal, op, rawCond, scope, warnKey, where ) {
        var val = ( rawVal === null || rawVal === undefined ) ? '' : rawVal;
        var valStr = String( val );
        // 「オペランドが $var 参照だったか」を覚えておく。resolveValue は未定義変数にも
        // 空変数にも '' を返すため、これを見ないと『解決できなかった $var』と『リテラルの
        // 欠損指定（neq: null 等）』を区別できない。前者は作者の意図が不明＝fail-closed に
        // 落として警告し、後者は「欠損の行を選ぶ/除く」という正当なイディオムとして通す。
        var condIsVarRef = ( typeof rawCond === 'string' && rawCond.charAt( 0 ) === '$' );

        if ( op === 'in' ) {
            // "$var" の解決は resolveForEachIn（vars 非空優先→params→manifest.vars）。
            // resolveValue だと constructor が manifest.vars の配列宣言を [] 初期化する
            // 仕様に遮断され、spec どおりの書き方が無言で 0 件になる（#1530 専属レビュー🔴-1）
            var list = rawCond;
            if ( typeof list === 'string' ) {
                list = this.resolveForEachIn( list );
            }
            if ( ! Array.isArray( list ) ) {
                this._warnOnce( scope, warnKey + '.in', where + ': in operand did not resolve to an array' );
                return false;
            }
            for ( var li = 0; li < list.length; li++ ) {
                var item = this.resolveValue( list[li] );
                var itemStr = ( item === null || item === undefined ) ? '' : String( item );
                if ( itemStr === valStr ) return true;
            }
            return false;
        }

        if ( op === 'eq' || op === 'neq' || op === 'contains' ) {
            var rawOperand = this.resolveValue( rawCond );
            var operand = ( rawOperand === null || rawOperand === undefined ) ? '' : String( rawOperand );
            // 解決できなかった $var（タイポ・未入力のフォーム値）で比較を続けると、
            // contains は '' の部分一致で全行通過・neq は全行通過＝「絞ったつもりで
            // 絞れていない」状態を無言で作る。本 Issue が消しに来た症状そのものなので、
            // 全演算子で不成立＋警告に落とす（リテラルの欠損指定はここを通らない）。
            if ( condIsVarRef && operand === '' ) {
                this._warnOnce( scope, warnKey + '.' + op + '.operand', where + ': ' + op + ' operand "' + rawCond + '" is empty (unresolved or blank variable)' );
                return false;
            }
            if ( op === 'eq' ) return valStr === operand;
            if ( op === 'neq' ) return valStr !== operand;
            return valStr.indexOf( operand ) !== -1;
        }

        if ( op === 'gt' || op === 'gte' || op === 'lt' || op === 'lte' ) {
            // 行値の欠損は不成立。'' を Number() に通すと 0 になり「欠損が 0 として閾値を
            // 跨ぐ」ため、数値化の前に弾く。null / undefined / '' は正常な欠損なので無言。
            // それ以外で String() が空になる値（空配列など）は書き方の誤りなので警告する。
            if ( valStr === '' ) {
                if ( rawVal !== null && rawVal !== undefined && rawVal !== '' ) {
                    this._warnOnce( scope, warnKey + '.' + op + '.value', where + ': ' + op + ' compared a value that is not a number (' + ( typeof rawVal ) + ')' );
                }
                return false;
            }
            var target = this.resolveValue( rawCond );
            if ( target === null || target === undefined || target === '' ) {
                this._warnOnce( scope, warnKey + '.' + op + '.operand', where + ': ' + op + ' operand is empty (unresolved $var?)' );
                return false;
            }
            var numVal = Number( val );
            var numTarget = Number( target );
            if ( isNaN( numVal ) || isNaN( numTarget ) ) {
                this._warnOnce( scope, warnKey + '.' + op, where + ': non-numeric ' + op + ' comparison (value=' + valStr + ')' );
                return false;
            }
            if ( op === 'gt' ) return numVal > numTarget;
            if ( op === 'gte' ) return numVal >= numTarget;
            if ( op === 'lt' ) return numVal < numTarget;
            return numVal <= numTarget;
        }

        // scope ごとに語彙が違うので案内も分ける（if に無い in/contains を
        // 「supported」と書かないため）。IF_OPERATORS 検査が先に走るので現状は到達しないが、
        // 語彙が増えたときに嘘の案内を出さないようにしておく。
        var supported = ( scope === 'if' ) ? AssistantRuntime.IF_OPERATORS : AssistantRuntime.FILTER_OPERATORS;
        throw new Error( 'unknown ' + scope + ' operator "' + op + '" in ' + where + ' (supported: ' + supported.join( '/' ) + ')' );
    };

    /**
     * 条件定義（`{ <field>: { <op>: <operand> } }`）の構造を検査する。
     * transform.filter と tally の when が共有する（Issue #1308）。
     *
     * schema（`filterCondition` の `type:object` / `minProperties:1`）が弾く形だが、
     * ZIP+PHP 直配布経路は JS validator を通らないため runtime 側にも防護を置く
     * （#1510 の fail-loud と同じ理由）。放置すると:
     * - `{ "tag": "news" }`（略記）→ `unknown ... operator "0"` という読めない例外
     * - `{ "tag": null }` → 素の TypeError（Cannot convert undefined or null to object）
     * - `{ "tag": {} }` → 条件ループが空回りして**全行一致**（黙って絞れない）
     *
     * @param  {*}      def   条件定義（filterDef / whenDef）
     * @param  {string} label 例外文の主語（'filter' / 'tally "x" when'）
     * @return {Array<{field: string, ops: Array<string>}>} 検査済みのフィールド×演算子
     * @throws {Error} 構造が不正なとき
     */
    AssistantRuntime.prototype.checkConditionDef = function( def, label ) {
        if ( def === null || typeof def !== 'object' || Array.isArray( def ) ) {
            throw new Error( label + ' must be an object of fields (e.g. { "tag": { "eq": "news" } }), got ' + ( def === null ? 'null' : ( Array.isArray( def ) ? 'array' : typeof def ) ) );
        }
        var fields = Object.keys( def );
        // フィールドが0個の条件（`{}`）も全行一致・全行通過になる＝下のフィールドごとの
        // 空条件チェックと同じ穴なので同じように塞ぐ。schema は filter / when の両方に
        // minProperties:1 を持つが、ZIP+PHP 直配布経路は validator を通らない
        // （＝この runtime ガードを置いた理由そのもの）。
        if ( fields.length === 0 ) {
            throw new Error( label + ' has no field (an empty condition would match every row)' );
        }
        var out = [];
        for ( var f = 0; f < fields.length; f++ ) {
            var cond = def[ fields[f] ];
            if ( cond === null || typeof cond !== 'object' || Array.isArray( cond ) ) {
                throw new Error( label + ' field "' + fields[f] + '" must be an object of operators (e.g. { "eq": "news" }), got ' + ( cond === null ? 'null' : ( Array.isArray( cond ) ? 'array' : typeof cond ) ) );
            }
            var ops = Object.keys( cond );
            if ( ops.length === 0 ) {
                throw new Error( label + ' field "' + fields[f] + '" has no operator (an empty condition would match every row)' );
            }
            for ( var o = 0; o < ops.length; o++ ) {
                if ( AssistantRuntime.FILTER_OPERATORS.indexOf( ops[o] ) === -1 ) {
                    throw new Error( 'unknown ' + label + ' operator "' + ops[o] + '" on field "' + fields[f] + '" (supported: ' + AssistantRuntime.FILTER_OPERATORS.join( '/' ) + ')' );
                }
            }
            out.push( { field: fields[f], ops: ops } );
        }
        return out;
    };

    /**
     * Issue #1607: 条件（filter / tally の when）が見ている列が **どの行にも無い** なら1回だけ声を出す。
     *
     * 放置すると結果が黙って 0 件になり、それは「該当なし」という**業務としてありうる答え**なので
     * 間違いだと気づけない。踏み方は①列名の打ち間違い ②`group_by` が落とした列を後段で見る、の2つ。
     * 門番（validate）は QAL の列名を見ない設計なので、配布前にも実行時にも誰も言わない状態だった。
     *
     * **判定は `checkConditionDef` を共有する消費者すべてに当てる**（filter と tally の when）。
     * 片方だけに置くと、同じガードを通る2つの消費者のうち片方だけが声を出す＝この Issue が
     * 消しに来た非対称を隣に作り直すことになる（PR #1608 レビュー 🟡-2）。
     *
     * 判定の線引き:
     * - **行が 0 件なら黙る**＝列の有無を判断できないうえ、上流で 0 件になったのが原因＝
     *   ここで鳴らすと本当の原因から目を逸らす。
     * - **1行でもキーが在れば黙る**＝材料によっては行ごとにキーが揃わない（`combine` 等）。
     *   厳しくすると正常なデータで鳴り続け、**警告そのものが無視されるようになる**。
     * - **`undefined` は「無い」と数える**（PR #1608 レビュー 🟡-1）。`transformGroupBy` は
     *   `out[ keep[k] ] = group.first[ keep[k] ]` と書くため、**`keep` に打ち間違いがあると
     *   値が `undefined` の own property が作られる**＝`hasOwnProperty` だけで見ると「在る」に
     *   なってしまい、この検査が閉じに来た症状（黙って 0 件）がそのまま残る。
     *   ただし **`hasOwnProperty` は外さない**＝外すと `toString` のような
     *   プロトタイプ由来の名前で鳴らなくなる。
     * - `null` / 空文字しか無い列は「**在るが空**」＝別の問題（値の側）なので鳴らさない。
     *   式エンジン（`parsePrimary` の `val === undefined`）とは**方向を揃え、閾値は意図的に緩い**
     *   （あちらは1行でも欠ければ鳴る／こちらは全行に無いときだけ）。
     *
     * 重複抑制キーの粒度は**メッセージの粒度と揃える**（PR #1608 第2R ⚪-1）。`tally` は
     * バケツごとに別の結果が壊れるので、`matchOperator` の先例（`bucketName + '.' + field`）と
     * 同じく `keyPrefix` にバケツ名を渡す＝**同じ列名を見ている 2 つのバケツは 2 件とも鳴る**。
     * 揃えないと「壊れているのは最初のバケツだけ」と読めてしまう。
     *
     * @param {Array}  data      対象データ.
     * @param {Array}  pairs     checkConditionDef の戻り値（フィールド×演算子）.
     * @param {string} kind      _warnOnce の種別（'filter' / 'tally'）.
     * @param {string} noun      文言の主語（'filter field' / 'tally "x" when field'）.
     * @param {string} keyPrefix 重複抑制キーの接頭辞（filter は ''／tally は '<bucket>.'）.
     * @return {void}
     */
    AssistantRuntime.prototype.warnMissingConditionFields = function( data, pairs, kind, noun, keyPrefix ) {
        if ( ! data || data.length === 0 || ! pairs ) {
            return;
        }
        var prefix = keyPrefix || '';
        for ( var p = 0; p < pairs.length; p++ ) {
            var f = pairs[p].field;
            var present = false;
            for ( var r = 0; r < data.length; r++ ) {
                if ( data[r] && Object.prototype.hasOwnProperty.call( data[r], f ) && data[r][f] !== undefined ) {
                    present = true;
                    break;
                }
            }
            if ( ! present ) {
                this._warnOnce( kind, prefix + 'unknown-field:' + f,
                    noun + ' "' + f + '" is not present in any row (result is empty)' );
            }
        }
    };

    AssistantRuntime.prototype.transformFilter = function( data, filterDef ) {
        var self = this;
        // 構造・語彙の検査は行ループの外（data が空でも未知演算子で throw する＝#1510 の
        // fail-loud のカバレッジを保つ）。検査済みのフィールド×演算子を使い回す。
        var pairs = this.checkConditionDef( filterDef, 'filter' );

        // Issue #1607: 絞り込む列が**どの行にも無い**なら声を出す（結果は従来どおり 0 件）。
        this.warnMissingConditionFields( data, pairs, 'filter', 'filter field' );

        return data.filter( function( row ) {
            for ( var i = 0; i < pairs.length; i++ ) {
                var field = pairs[i].field;
                var cond = filterDef[field];
                var ops = pairs[i].ops;
                // Issue #1308: 演算子ごとの独自比較を撤去し matchOperator へ一本化
                // （tally の when・step の if と同一規則）。同一フィールドに複数演算子が
                // 並ぶときの AND 結合と、1つでも不成立なら除外という骨格は不変。
                for ( var o = 0; o < ops.length; o++ ) {
                    if ( ! self.matchOperator( row[field], ops[o], cond[ ops[o] ], 'filter', field, 'filter field "' + field + '"' ) ) {
                        return false;
                    }
                }
            }
            return true;
        });
    };

    /**
     * Group by transform
     */
    AssistantRuntime.prototype.transformGroupBy = function( data, t ) {
        var groupField = t.group_by;
        var agg = t.agg || {};
        var keep = t.keep || [];
        // Object.create(null)＝キー値 "__proto__" の行で groups[key].rows が壊れる既存クラッシュの併修
        var groups = Object.create( null );

        for ( var i = 0; i < data.length; i++ ) {
            var row = data[i];
            // Issue #1510: グループキーは「null/undefined のみ '' 扱い・他は String()」。
            // 旧実装 String( v || '' ) は 0 / false も '' に潰し null と同一グループに
            // 合流させていた（キー正規化の是正）。tally の distinct キーと共通規則。
            var rawKey = row[groupField];
            var key = ( rawKey === null || rawKey === undefined ) ? '' : String( rawKey );
            if ( ! groups[key] ) {
                groups[key] = { rows: [], first: row };
            }
            groups[key].rows.push( row );
        }

        var result = [];
        var groupKeys = Object.keys( groups );
        for ( var g = 0; g < groupKeys.length; g++ ) {
            var gk = groupKeys[g];
            var group = groups[gk];
            var out = {};
            out[groupField] = gk;

            // Keep fields from first row
            for ( var k = 0; k < keep.length; k++ ) {
                out[ keep[k] ] = group.first[ keep[k] ];
            }

            // Aggregations
            var aggKeys = Object.keys( agg );
            for ( var a = 0; a < aggKeys.length; a++ ) {
                var aggField = aggKeys[a];
                var aggFunc = agg[aggField];
                out[aggField] = this.aggregate( group.rows, aggField, aggFunc );
            }

            result.push( out );
        }

        return result;
    };

    /**
     * Aggregate function
     */
    AssistantRuntime.prototype.aggregate = function( rows, field, func ) {
        // Issue #1510: count_distinct は Number() 経路を通さない（文字列 ID の distinct が
        // NaN に潰れ「何を数えても1」になるため）。同一性＝文字列形（String(v) が同じなら
        // 同一）・null/undefined/'' は数えない。group_by キー正規化と共通規則。
        if ( func === 'count_distinct' ) {
            // Object.create(null)＝"__proto__" 等の値がプロトタイプに食われて重複カウントされるのを防ぐ
            var seen = Object.create( null );
            var distinct = 0;
            for ( var d = 0; d < rows.length; d++ ) {
                var dv = rows[d][field];
                if ( dv === null || dv === undefined || dv === '' ) continue;
                var dk = String( dv );
                if ( ! Object.prototype.hasOwnProperty.call( seen, dk ) ) {
                    seen[dk] = true;
                    distinct++;
                }
            }
            return distinct;
        }

        // first は「先頭行の値をそのまま」（文字列も可＝first(keyword) が実在で多用）＝数値化の対象外。
        // 従来は rows[0][field] を返す前に vals（数値化後）が空だと 0 に化けていた＝
        // 「文字列だけの列」や「先頭が空」で first が壊れる穴。先頭行の有無だけで決める。
        if ( func === 'first' ) {
            if ( rows.length === 0 ) {
                this._warnOnce( 'agg', 'first:' + field + ':empty', 'first(' + field + ') has no rows (shown as empty)' );
                return null;
            }
            var fv = rows[0][field];
            return ( fv === undefined ) ? null : fv;
        }

        // Issue #1577（A-3）: 数値に読めない値は従来 NaN のまま計算に混ぜていた（sum/avg が NaN に
        // なり、下流の isNaN 判定で 0 に化ける）。ここでは **除外して1回だけ声を出す**。
        // 除外は「値が無い（null/undefined/''）」と同じ扱い＝集計の母集団から落とす。
        var vals = [];
        var skipped = 0;
        for ( var i = 0; i < rows.length; i++ ) {
            var v = rows[i][field];
            if ( v === null || v === undefined || v === '' ) continue;
            var n = Number( v );
            if ( isNaN( n ) ) { skipped++; continue; }
            vals.push( n );
        }
        if ( skipped > 0 ) {
            this._warnOnce( 'agg', func + ':' + field, func + '(' + field + ') skipped ' + skipped + ' non-numeric value(s)' );
        }

        // Issue #1577（A-3）: 空集合は従来 **一律 0**＝「合計が 0」と「対象が無い」が区別できない
        // （sum は数学的に 0 でよいが、avg/min/max の 0 は誤答）。count/sum 以外は null（表示は空）にする。
        // ★sum(空)＝0・count(空)＝0 は「足すものが無ければ 0」「数えるものが無ければ 0」で正しい値＝従来どおり。
        if ( vals.length === 0 ) {
            if ( func === 'count' || func === 'sum' ) return 0;
            this._warnOnce( 'agg', func + ':' + field + ':empty', func + '(' + field + ') has no numeric values (shown as empty)' );
            return null;
        }

        switch ( func ) {
            case 'sum':
                var sum = 0;
                for ( var s = 0; s < vals.length; s++ ) sum += vals[s];
                return sum;
            case 'avg':
                var total = 0;
                for ( var a = 0; a < vals.length; a++ ) total += vals[a];
                return total / vals.length;
            case 'count':
                return vals.length;
            case 'min':
                return Math.min.apply( null, vals );
            case 'max':
                return Math.max.apply( null, vals );
            // 'first' は上で早期 return（数値化を通さない）
            default:
                // Issue #1510（専属レビュー🟡-2）: 未知の agg 関数も fail-loud。従来の
                // `return 0` はタイポ（count_distict 等）が実在する数字に見える same-family の穴
                throw new Error( 'unknown aggregate function "' + func + '" (supported: sum/avg/count/count_distinct/min/max/first)' );
        }
    };

    /**
     * Calc transform — row-level or global
     */
    AssistantRuntime.prototype.transformCalc = function( data, t ) {
        var fieldName = t.calc;
        var expr = t.expr;
        var scope = t.scope || 'row';

        if ( scope === 'global' ) {
            var globalVal = this.evaluateGlobalExpression( expr, data );
            this.vars[fieldName] = globalVal;
            return data;
        }

        // Row-level
        for ( var i = 0; i < data.length; i++ ) {
            data[i][fieldName] = this.evaluateExpression( expr, data[i] );
        }
        return data;
    };

    /**
     * Lookup transform
     */
    AssistantRuntime.prototype.transformLookup = function( data, t ) {
        var lookupName = t.lookup;
        var keyField = t.key;
        var intoField = t.into;

        var dict = this.manifest.lookups ? this.manifest.lookups[lookupName] : null;
        if ( ! dict ) {
            console.warn( 'Lookup not found:', lookupName );
            return data;
        }

        for ( var i = 0; i < data.length; i++ ) {
            var keyVal = data[i][keyField];
            data[i][intoField] = ( keyVal !== undefined && dict[keyVal] !== undefined ) ? dict[keyVal] : null;
        }
        return data;
    };

    /**
     * set_var transform
     */
    AssistantRuntime.prototype.transformSetVar = function( data, t ) {
        var varName = t.set_var;
        var expr = t.expr;
        this.vars[varName] = this.evaluateGlobalExpression( expr, data );
    };

    /**
     * extract_host transform — URL の host 部分を新フィールドに書き出す
     *
     * "https://store.playstation.com/path?q=1" → "store.playstation.com"
     * "https://example.com?x=1"               → "example.com"
     * "https://example.com#section"           → "example.com"
     * scheme `://` の直後から最初の `/` `?` `#` のいずれかまでを切り出す。
     */
    AssistantRuntime.prototype.transformExtractHost = function( data, t ) {
        var spec = t.extract_host;
        var from = spec.from;
        var into = spec.into;
        for ( var i = 0; i < data.length; i++ ) {
            data[i][into] = this.extractHostFromUrl( data[i][from] );
        }
        return data;
    };

    AssistantRuntime.prototype.extractHostFromUrl = function( url ) {
        if ( typeof url !== 'string' ) return null;
        var schemeIdx = url.indexOf( '://' );
        if ( schemeIdx === -1 ) return null;
        var rest = url.substring( schemeIdx + 3 );
        var endIdx = rest.length;
        var slashIdx = rest.indexOf( '/' );
        var queryIdx = rest.indexOf( '?' );
        var hashIdx  = rest.indexOf( '#' );
        if ( slashIdx !== -1 ) endIdx = Math.min( endIdx, slashIdx );
        if ( queryIdx !== -1 ) endIdx = Math.min( endIdx, queryIdx );
        if ( hashIdx  !== -1 ) endIdx = Math.min( endIdx, hashIdx );
        return endIdx === rest.length ? rest : rest.substring( 0, endIdx );
    };

    /**
     * tally transform — データを壊さず1パスで複数バケツを同時集計（Issue #1510）
     *
     * { "tally": { "buckets": [
     *     { "into": "submit_users", "count_distinct": "user_id", "when": { "is_submit": { "eq": 1 } } },
     *     { "into": "total_rows",   "count": true }
     * ] } }
     *
     * - 戻り値は入力 data そのまま（後続 transform は元データを見る）
     * - 各バケツの結果は this.vars[into] に入る（set_var と同じ置き場・{$into} で参照）
     * - count_distinct の同一性＝文字列形（String(v)）・null/undefined/'' は数えない
     *   （aggregate の count_distinct と同一規則）
     * - when の演算子は filter と同じ8種だが、解決・型規則は統一（tallyMatch 参照）
     */
    AssistantRuntime.prototype.transformTally = function( data, tallyDef ) {
        var buckets = ( tallyDef && tallyDef.buckets ) ? tallyDef.buckets : [];
        var states = [];
        var b, bucket;

        for ( b = 0; b < buckets.length; b++ ) {
            bucket = buckets[b];
            if ( ! bucket.into ) {
                throw new Error( 'tally bucket[' + b + '] is missing "into"' );
            }
            if ( ( bucket.count_distinct === undefined ) === ( bucket.count !== true ) ) {
                throw new Error( 'tally bucket "' + bucket.into + '" must have exactly one of count_distinct / count:true' );
            }
            // when の構造・語彙検査は行ループの前（filter と同じ＝data が 0 件でも未知演算子や
            // 空条件で throw する）。行ごとに検査すると①空データで fail-loud が効かない
            // ②1000行×バケツ数だけ無駄に検査が走る、の2つを同時に踏む。
            var whenPairs = null;
            if ( bucket.when !== undefined ) {
                whenPairs = this.checkConditionDef( bucket.when, 'tally "' + bucket.into + '" when' );
                // Issue #1607: filter と同じ検査をここにも置く。`checkConditionDef` を共有する
                // 消費者のうち片方だけが声を出すと、この Issue が消しに来た非対称を隣に作り直す形に
                // なる（PR #1608 レビュー 🟡-2）。壊れ方も filter と同じ＝バケツが黙って 0 で終わる。
                this.warnMissingConditionFields( data, whenPairs, 'tally', 'tally "' + bucket.into + '" when field', bucket.into + '.' );
            }
            // seen は Object.create(null)＝"__proto__" 等の値の重複カウントを防ぐ（aggregate と同じ）
            states.push( { seen: Object.create( null ), n: 0, whenPairs: whenPairs } );
        }

        for ( var i = 0; i < data.length; i++ ) {
            var row = data[i];
            for ( b = 0; b < buckets.length; b++ ) {
                bucket = buckets[b];
                if ( states[b].whenPairs !== null && ! this.tallyMatch( row, bucket.when, bucket.into, states[b].whenPairs ) ) {
                    continue;
                }
                if ( bucket.count_distinct !== undefined ) {
                    var dv = row[ bucket.count_distinct ];
                    if ( dv === null || dv === undefined || dv === '' ) continue;
                    var dk = String( dv );
                    if ( ! Object.prototype.hasOwnProperty.call( states[b].seen, dk ) ) {
                        states[b].seen[dk] = true;
                        states[b].n++;
                    }
                } else {
                    states[b].n++;
                }
            }
        }

        for ( b = 0; b < buckets.length; b++ ) {
            this.vars[ buckets[b].into ] = states[b].n;
        }
        return data;
    };

    /**
     * tally の when 条件評価（Issue #1510 裁定2 → Issue #1308 で matchOperator へ一本化）
     *
     * #1510 ではここに統一規則の実装を置いたが、#1308 で transform.filter と step の if も
     * 同じ規則へ揃えたため、実装は matchOperator が唯一の正本になった。ここはフィールド×
     * 演算子の AND 結合だけを担う（規則の説明は matchOperator を参照）。
     *
     * @param {Array} pairs transformTally が行ループの前に検査済みのフィールド×演算子。
     *                      省略時はここで検査する（単体で呼ばれた場合の保険）。
     */
    AssistantRuntime.prototype.tallyMatch = function( row, whenDef, bucketName, pairs ) {
        // 構造・語彙の検査は filter と同じ共有ガード（Issue #1308）。#1510 では when 側に
        // 構造検査が無く、`when: { f: [] }` が黙って全行一致＝バケツが全行を数えていた。
        if ( ! pairs ) {
            pairs = this.checkConditionDef( whenDef, 'tally "' + bucketName + '" when' );
        }
        for ( var i = 0; i < pairs.length; i++ ) {
            var field = pairs[i].field;
            var cond = whenDef[field];
            var ops = pairs[i].ops;
            for ( var o = 0; o < ops.length; o++ ) {
                if ( ! this.matchOperator( row[field], ops[o], cond[ ops[o] ], 'tally', bucketName + '.' + field, 'tally "' + bucketName + '" field "' + field + '"' ) ) {
                    return false;
                }
            }
        }
        return true;
    };

    /**
     * combine transform — zip multi-source $results dict
     *
     * 仕様 (T22brand-3 §7.9 / ユーザー判断 2026-05-14 で多行対応に拡張):
     * - 各 source は 1 行返す前提を維持
     * - source の label が "<base>__<key>" 形式なら、`__` 以降を key 値として抽出し、
     *   同じ key を持つ source 同士を 1 行に zip
     * - `__` を含まない label の source は「全グループに共通」として全行にマージ
     * - 出力行の順序は sources 配列で key 値が初出になった順 (決定性)
     * - 単月モード互換: 全 source の key が同一なら 1 行返る (既存動作と一致)
     * - 同名列が複数 source にある場合は sources 配列で後ろの source の値が優先
     */
    AssistantRuntime.prototype.transformCombine = function( results, t ) {
        var keyField = t.combine;
        var sources = t.sources || [];

        var keyOrder = [];          // 出現順の key 値リスト
        var groups = {};            // key → merged row
        var common = {};            // key 抽出できない source の合算 (全行にマージ)
        var hasCommon = false;

        for ( var i = 0; i < sources.length; i++ ) {
            var label = sources[i];
            var arr = results[label];
            if ( ! Array.isArray( arr ) || arr.length === 0 ) continue;
            var row = arr[0];
            if ( ! row || typeof row !== 'object' ) continue;

            var keyVal = this.extractCombineKey( label );

            if ( keyVal === null ) {
                // label に `__` 接尾辞が無い: 共通列として全行にマージ
                var ckeys = Object.keys( row );
                for ( var c = 0; c < ckeys.length; c++ ) {
                    common[ ckeys[c] ] = row[ ckeys[c] ];
                }
                hasCommon = true;
                continue;
            }

            if ( ! groups[keyVal] ) {
                groups[keyVal] = {};
                keyOrder.push( keyVal );
            }
            var rkeys = Object.keys( row );
            for ( var r = 0; r < rkeys.length; r++ ) {
                groups[keyVal][ rkeys[r] ] = row[ rkeys[r] ];
            }
        }

        // 1 グループも作れなかった (全 source が common 扱いだった) 場合は 1 行返す
        if ( keyOrder.length === 0 ) {
            var out = {};
            if ( hasCommon ) {
                var commonKeys = Object.keys( common );
                for ( var cc = 0; cc < commonKeys.length; cc++ ) {
                    out[ commonKeys[cc] ] = common[ commonKeys[cc] ];
                }
            }
            if ( keyField && out[keyField] === undefined ) out[keyField] = '';
            return [ out ];
        }

        // グループごとに行を作る (common を全行にマージ、key 列を inject)
        var result = [];
        for ( var g = 0; g < keyOrder.length; g++ ) {
            var k = keyOrder[g];
            var merged = {};
            // common を先に置く (後勝ちなので source 値が上書きする)
            if ( hasCommon ) {
                var cks = Object.keys( common );
                for ( var cm = 0; cm < cks.length; cm++ ) {
                    merged[ cks[cm] ] = common[ cks[cm] ];
                }
            }
            var gks = Object.keys( groups[k] );
            for ( var gm = 0; gm < gks.length; gm++ ) {
                merged[ gks[gm] ] = groups[k][ gks[gm] ];
            }
            if ( keyField ) merged[keyField] = k;
            result.push( merged );
        }
        return result;
    };

    /**
     * label から combine key を抽出: "<base>__<key>" → "<key>", 区切り無しなら null
     * 区切りは固定文字列 "__" (二重アンダースコア)。preg_match 不使用。
     */
    AssistantRuntime.prototype.extractCombineKey = function( label ) {
        if ( typeof label !== 'string' ) return null;
        var idx = label.lastIndexOf( '__' );
        if ( idx === -1 ) return null;
        var suffix = label.substring( idx + 2 );
        if ( suffix.length === 0 ) return null;
        return suffix;
    };

    // ─── Expression parser ─────────────────────────────
    //
    // Issue #1577（エンジン改善 第3弾①）: 式エンジンの fail-loud 化。
    // 従来は失敗（未知文字・括弧不整合・ゼロ除算・数値でない値・想定外トークン・例外）を
    // **すべて黙って 0 に落としていた**＝計算式のタイポが実在する数字として画面に出る＝
    // 「声を出さない失敗」の親玉。#1510 で未知ステップ／未知演算子／未知集計関数は fail-loud に
    // したが、式の**中身**はまだ 0 に化けていた。
    //
    // 方針＝失敗は **null を返して _warnOnce で1回だけ声を出す**。表示側は既存の流儀（テンプレは
    // ''、セルは空）に落ちる＝**会話は止めない**（fail-loud だが fail-closed ではない。表示の不備で
    // 会話が落ちるほうが害が大きい）。**書き間違いを配布前に止めるのは validator の役目**
    // （E_REF_EXPR_SYNTAX）＝#1575 の E_REF_TEMPLATE_SYNTAX と同じ二段構え。
    //
    // 実装形＝内部は throw で失敗を伝播させ、evaluateExpression / evaluateGlobalExpression の
    // 入口1箇所で catch → warn → null にする（途中の関数が個別に 0 を返さない＝穴が再発しない形）。

    /** 式エンジン内部の失敗（入口で catch して null＋warn にする）. */
    function ExprError( message ) {
        this.name = 'ExprError';
        this.message = message;
    }
    ExprError.prototype = Object.create( Error.prototype );

    /**
     * Evaluate a row-level expression (safe, no eval)
     * Supports: field references, $var references, +, -, *, /, parentheses, unary minus
     * @returns {number|null} null = 失敗（警告済み）
     */
    AssistantRuntime.prototype.evaluateExpression = function( expr, row ) {
        try {
            // 前後の空白（タブ・改行を含む）は評価対象外＝evaluateGlobalExpression／validator と揃える。
            // tokenize が読み飛ばすのは半角スペースだけなので、JSON を手で整形して紛れた改行で
            // 行評価だけが落ちる形（PR #1579 レビュー 🟡-2）を塞ぐ。
            var tokens = this.tokenize( String( expr ).trim() );
            var pos = { i: 0 };
            var result = this.parseAddSub( tokens, pos, row );
            if ( pos.i < tokens.length ) {
                // 例＝閉じ括弧の余り・演算子の連続で読み残しが出た形。従来は黙って読み飛ばしていた
                throw new ExprError( 'unexpected token "' + tokens[pos.i].value + '"' );
            }
            if ( result === null || result === undefined || typeof result !== 'number' || isNaN( result ) || ! isFinite( result ) ) {
                throw new ExprError( 'result is not a finite number' );
            }
            return result;
        } catch ( e ) {
            this._warnOnce( 'expr', String( expr ), 'expression "' + expr + '" failed: ' + ( e && e.message ? e.message : e ) + ' (shown as empty)' );
            return null;
        }
    };

    /**
     * Evaluate a global expression（set_var / calc scope:"global"）.
     *   sum(field) / avg(field) / count(field) / count_distinct(field) / min(field) / max(field) / first(field)
     *   または $var だけで組んだ式（$a / $b * 100）
     *
     * Issue #1577（B-4）: 従来は「純集計形でなければ **先頭行の値で** 式を評価」していた＝
     * `sum(a)/sum(b)` が定数 0・`a/b*100` が先頭行値になる「VALID のまま静かな誤答」。
     * いまは3形を区別する:
     *   (1) 純集計形            → aggregate（従来どおり）
     *   (2) $var だけの式       → データの有無に関わらず vars から評価（R4 判断＝(a)・空データでも正しい率が出る）
     *   (3) それ以外（行フィールドを含む・集計を式に混ぜる）→ 先頭行に依存する形＝warn＋null
     *       （validator の E_REF_EXPR_SYNTAX が配布前に止める。ここに来るのは JS 検証をスキップしたときだけ）
     * @returns {number|string|null}
     */
    AssistantRuntime.prototype.evaluateGlobalExpression = function( expr, data ) {
        var src = String( expr ).trim();
        var match = /^(sum|avg|count|count_distinct|min|max|first)\(([a-zA-Z_][a-zA-Z0-9_]*)\)$/.exec( src );
        if ( match ) {
            return this.aggregate( data, match[2], match[1] );
        }
        if ( this.isVarOnlyExpression( src ) ) {
            // R4 (a): 元データが 0 件でも $var は解決できる＝空データで 0 を返していた従来の穴を塞ぐ
            return this.evaluateExpression( src, null );
        }
        this._warnOnce( 'expr', 'global:' + src,
            'global expression "' + src + '" depends on row fields (it would silently use the first row) — ' +
            'use an aggregate like sum(field), or $var references only (shown as empty)' );
        return null;
    };

    /**
     * 「$var だけで組んだ式」か（行フィールド＝裸の識別子や関数呼び出しを含まない）.
     * tokenize と同じ字句規則で判定する＝式エンジンの外に別の文法を作らない。
     */
    AssistantRuntime.prototype.isVarOnlyExpression = function( expr ) {
        var tokens;
        try { tokens = this.tokenize( expr ); } catch ( e ) { return false; }
        if ( tokens.length === 0 ) return false;
        var hasVar = false;
        for ( var i = 0; i < tokens.length; i++ ) {
            if ( tokens[i].type === 'id' ) return false;   // 行フィールド参照＝先頭行依存
            if ( tokens[i].type === 'var' ) hasVar = true;
        }
        return hasVar;
    };

    /**
     * Tokenizer for expressions
     */
    AssistantRuntime.prototype.tokenize = function( expr ) {
        var tokens = [];
        var i = 0;
        while ( i < expr.length ) {
            var ch = expr[i];
            if ( ch === ' ' ) { i++; continue; }
            if ( ch === '+' || ch === '-' || ch === '*' || ch === '/' || ch === '(' || ch === ')' ) {
                tokens.push( { type: 'op', value: ch } );
                i++;
            } else if ( /[0-9.]/.test( ch ) ) {
                var num = '';
                while ( i < expr.length && /[0-9.]/.test( expr[i] ) ) {
                    num += expr[i];
                    i++;
                }
                // Issue #1577: parseFloat は "1.2.3" を 1.2 として **黙って** 読む。数値リテラルの形を
                // 先に検査する（整数 or 小数点1つ）。
                if ( ! /^(\d+\.?\d*|\.\d+)$/.test( num ) ) {
                    throw new ExprError( 'malformed number "' + num + '"' );
                }
                tokens.push( { type: 'num', value: parseFloat( num ) } );
            } else if ( /[a-zA-Z_]/.test( ch ) ) {
                var id = '';
                while ( i < expr.length && /[a-zA-Z0-9_]/.test( expr[i] ) ) {
                    id += expr[i];
                    i++;
                }
                tokens.push( { type: 'id', value: id } );
            } else if ( ch === '$' ) {
                // $var reference — resolve from this.vars (or this.systemVars for $sys.*) in parsePrimary.
                // The '$' is stripped here; the variable name is stored without the prefix to match
                // the convention used by resolveValue and this.vars / this.systemVars keys.
                // See docs/specs/assistant/data-sources.md §4.7 / rules.md rule 17.
                i++;
                var varRef = '';
                while ( i < expr.length && /[a-zA-Z0-9_.]/.test( expr[i] ) ) {
                    varRef += expr[i];
                    i++;
                }
                // Issue #1577: 名前の無い "$" は従来 vars[''] を引いて黙って 0 になっていた
                if ( varRef === '' ) throw new ExprError( '"$" must be followed by a variable name' );
                tokens.push( { type: 'var', value: varRef } );
            } else {
                // Issue #1577: 未知の文字は従来 **黙ってスキップ** していた（`pv % 100` が `pv 100`
                // として読まれ、実在する数字が出る）。式の外の文字は文法違反として声を出す。
                throw new ExprError( 'unexpected character "' + ch + '" at ' + i );
            }
        }
        return tokens;
    };

    /**
     * Parse addition/subtraction
     */
    AssistantRuntime.prototype.parseAddSub = function( tokens, pos, row ) {
        var left = this.parseMulDiv( tokens, pos, row );
        while ( pos.i < tokens.length && ( tokens[pos.i].value === '+' || tokens[pos.i].value === '-' ) ) {
            var op = tokens[pos.i].value;
            pos.i++;
            var right = this.parseMulDiv( tokens, pos, row );
            if ( op === '+' ) left += right;
            else left -= right;
        }
        return left;
    };

    /**
     * Parse multiplication/division
     */
    AssistantRuntime.prototype.parseMulDiv = function( tokens, pos, row ) {
        var left = this.parsePrimary( tokens, pos, row );
        while ( pos.i < tokens.length && ( tokens[pos.i].value === '*' || tokens[pos.i].value === '/' ) ) {
            var op = tokens[pos.i].value;
            pos.i++;
            var right = this.parsePrimary( tokens, pos, row );
            if ( op === '*' ) left *= right;
            // Issue #1577: ゼロ除算は従来 **黙って 0**（率の計算で分母 0 が「0%」に化ける）。声を出す
            else if ( right === 0 ) throw new ExprError( 'division by zero' );
            else left = left / right;
        }
        return left;
    };

    /**
     * Parse primary: number, identifier (field ref), $var, parenthesized expression, unary minus.
     * Issue #1577: 数値に読めない値・未定義の $var・想定外トークン・閉じ括弧の欠落は従来すべて
     * 黙って 0 だった。ここでは throw で伝播させ、入口（evaluateExpression）で warn＋null にする。
     */
    AssistantRuntime.prototype.parsePrimary = function( tokens, pos, row ) {
        if ( pos.i >= tokens.length ) throw new ExprError( 'unexpected end of expression' );

        var token = tokens[pos.i];

        if ( token.type === 'num' ) {
            pos.i++;
            if ( isNaN( token.value ) ) throw new ExprError( 'malformed number' );   // "1.2.3" 等
            return token.value;
        }
        if ( token.type === 'id' ) {
            pos.i++;
            if ( ! row ) throw new ExprError( 'field "' + token.value + '" referenced outside a row' );
            var val = row[token.value];
            if ( val === undefined ) throw new ExprError( 'unknown field "' + token.value + '"' );
            // null / '' は「値が無い」＝計算不能（従来は 0 として計算していた）
            if ( val === null || val === '' ) throw new ExprError( 'field "' + token.value + '" is empty' );
            var num = Number( val );
            if ( isNaN( num ) ) throw new ExprError( 'field "' + token.value + '" is not a number (' + String( val ).slice( 0, 30 ) + ')' );
            return num;
        }
        if ( token.type === 'var' ) {
            pos.i++;
            var raw;
            if ( token.value.indexOf( 'sys.' ) === 0 ) {
                raw = this.systemVars[ token.value.substring( 4 ) ];
            } else {
                raw = this.vars[ token.value ];
            }
            if ( raw === undefined ) throw new ExprError( 'undefined variable $' + token.value );
            if ( raw === null || raw === '' ) throw new ExprError( 'variable $' + token.value + ' is empty' );
            var vnum = Number( raw );
            if ( isNaN( vnum ) ) throw new ExprError( 'variable $' + token.value + ' is not a number (' + String( raw ).slice( 0, 30 ) + ')' );
            return vnum;
        }
        if ( token.type === 'op' && token.value === '(' ) {
            pos.i++;
            var result = this.parseAddSub( tokens, pos, row );
            if ( pos.i < tokens.length && tokens[pos.i].value === ')' ) {
                pos.i++;
                return result;
            }
            throw new ExprError( 'missing closing parenthesis' );
        }
        // Unary minus
        if ( token.type === 'op' && token.value === '-' ) {
            pos.i++;
            return -this.parsePrimary( tokens, pos, row );
        }

        throw new ExprError( 'unexpected token "' + token.value + '"' );
    };

    // ─── Step registry: built-in bindings (Issue #1449) ─────────────────
    // Bound once at load time. Registration order is irrelevant — resolve
    // order is fixed by the registry's canonical table (the legacy if-chain
    // order), never by when a file happened to load.
    // The runtime binds the 12 conversation/control steps it implements;
    // callout / divider / html are bound by qahm-assistant-blocks.js
    // (the adapter that owns them — PR-D2).
    qahm.assistantSteps.registerBuiltin( 'message',      function( rt, step ) { return rt.handleMessage( step ); } );
    qahm.assistantSteps.registerBuiltin( 'choices',      function( rt, step ) { return rt.handleChoices( step ); } );
    qahm.assistantSteps.registerBuiltin( 'form',         function( rt, step ) { return rt.handleForm( step ); } );
    qahm.assistantSteps.registerBuiltin( 'fetch',        function( rt, step ) { return rt.handleFetch( step ); } );
    qahm.assistantSteps.registerBuiltin( 'table',        function( rt, step ) { return rt.handleTable( step ); } );
    qahm.assistantSteps.registerBuiltin( 'chart',        function( rt, step ) { return rt.handleChart( step ); } );
    qahm.assistantSteps.registerBuiltin( 'scorecard',    function( rt, step ) { return rt.handleScorecard( step ); } );
    qahm.assistantSteps.registerBuiltin( 'if',           function( rt, step ) { return rt.handleIf( step ); } );
    qahm.assistantSteps.registerBuiltin( 'goto',         function( rt, step ) { return rt.handleGoto( step ); } );
    qahm.assistantSteps.registerBuiltin( 'set',          function( rt, step ) { return rt.handleSet( step ); } );
    qahm.assistantSteps.registerBuiltin( 'config_read',  function( rt, step ) { return rt.handleConfigRead( step ); } );
    qahm.assistantSteps.registerBuiltin( 'config_write', function( rt, step ) { return rt.handleConfigWrite( step ); } );

    // Loud failure on binding gaps (#1449 PR-D2, reviewer follow-up): the
    // runtime is the LAST assistant script to load, so every built-in step
    // must be bound by now. A gap would otherwise only surface as a quiet
    // "Unknown step type" warn at dispatch time (the legacy chain failed
    // loudly with a TypeError) — surface it at load time instead.
    // typeof guard: a version-skewed (stale-cached) registry without
    // missingBuiltins must skip this check, not take the whole runtime down.
    if ( typeof qahm.assistantSteps.missingBuiltins === 'function' ) {
        var qahmUnboundSteps = qahm.assistantSteps.missingBuiltins();
        if ( qahmUnboundSteps.length > 0 ) {
            console.error( 'QAHM assistant: built-in step handlers not bound (script load order broken?):', qahmUnboundSteps );
        }
    }

    // エラーバブル文言の集約（Issue #1456 W-6）。現状 'Data fetch failed.'/'Failed to fetch data.' の
    // 英語ハードコードと同格の暫定＝本格 i18n は将来だが、1箇所に集約しておけば移行が1箇所で済む。
    // fetch 系（英語）は既存出力の byte 保存のため文言を変えない。launch/scene/config 系（日本語・
    // 管理画面 UI 言語に合わせる）が新規。すべて showErrorBubble で `<p>Error: …</p>` の同一バブルに統一する。
    AssistantRuntime.ERROR_MESSAGES = {
        launch_failed: 'アシスタントを開始できませんでした。',
        // #1534: 会話が「途中で」止まった場合の文言。開始前の失敗（launch_failed）と切り分ける
        // （切り分けの目印＝runtime._conversationStarted・受け皿は launcher の catch 1 本のまま）。
        step_failed: 'アシスタントの処理を続けられませんでした。',
        scene_missing: 'アシスタントの画面を表示できませんでした。',
        config_read_failed: '設定の読み込みに失敗しました。',
        fetch_failed: 'Data fetch failed.',       // 既存 handleFetch フォールバック（byte 保存）
        fetch_exception: 'Failed to fetch data.'  // 既存 handleFetch catch 文言（byte 保存）
    };

    // Export
    qahm.AssistantRuntime = AssistantRuntime;

})();
