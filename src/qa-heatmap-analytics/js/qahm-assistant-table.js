/**
 * QAHM Assistant Table Adapter
 *
 * Converts manifest table definitions to qaTable format.
 * Handles empty column filtering and type mapping.
 *
 * @since 1.0.0
 */

var qahm = qahm || {};

(function() {
    'use strict';

    /**
     * Manifest column type → qaTable column type mapping
     */
    var TYPE_MAP = {
        'string':     'string',
        'integer':    'number',
        'float':      'number',
        'percentage': 'number',
        'currency':   'number',
        'date':       'string',
        'datetime':   'string',
        'duration':   'string',
        'link':       'link',
        'boolean':    'string',
        'filesize':   'number',
        'scorebar':   'number',
        'badge':      'string',
        // #1424: html はサニタイズ済み描画（qa-table の case 'html'＝DOMPurify allowlist）。
        // ⚠️ formatter を張らないこと — セル描画は formatter > render > type の優先順のため、
        // formatter を付けると case 'html' のサニタイズ経路が発火しなくなる。
        'html':       'html'
    };

    /**
     * Default table options
     */
    var DEFAULT_OPTIONS = {
        per_page: 100,
        sortable: true,
        filtering: true,
        exportable: true,
        max_height: 300,
        sticky_header: true
    };

    /**
     * HTML 特殊文字をエスケープして innerHTML 挿入を安全化する（Issue #1299）。
     * PR-E2（#1453 / C3-6・専属🟡-3）: 実体は qa-table の static QATable.escapeHtml に一本化。
     * ここは存在ガード付きの委譲＝qa-table が居れば同じ芯を使い（重複解消）、qa-table 不在環境
     * （アダプタ単体持ち出し）では下の自前 escape に安全劣化する（可搬性を保つ）。
     */
    function escapeHtml( str ) {
        if ( typeof QATable !== 'undefined' && QATable && typeof QATable.escapeHtml === 'function' ) {
            return QATable.escapeHtml( str );
        }
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' )
            .replace( /'/g, '&#039;' );
    }

    /**
     * URL のスキームを許可リスト（http/https/mailto）で検証する（Issue #1299）。
     * 許可外スキーム（javascript:, data:, vbscript: 等）は '#' を返す。
     * スキーム無し（相対 / アンカー / プロトコル相対）の URL は許可する。
     * PR-E2: 実体は static QATable.sanitizeUrl（存在ガード付き委譲・不在時は自前へ安全劣化）。
     */
    function sanitizeUrl( value ) {
        if ( typeof QATable !== 'undefined' && QATable && typeof QATable.sanitizeUrl === 'function' ) {
            return QATable.sanitizeUrl( value );
        }
        var raw = String( value ).replace( /[\t\n\r\f\v\0]/g, '' ).trim();
        var m = raw.match( /^([a-z][a-z0-9+.\-]*):/i );
        if ( m ) {
            var scheme = m[1].toLowerCase();
            if ( scheme !== 'http' && scheme !== 'https' && scheme !== 'mailto' ) {
                return '#';
            }
        }
        return raw;
    }

    /**
     * Issue #1403: グラフィカル列の意味色レベル語彙（固定 enum）。
     * 生データから class 名を作らず、必ずこの集合のいずれかへ写像する（表記ゆれ / XSS 防止）。
     */
    var ALLOWED_LEVELS = { good: true, mid: true, bad: true, info: true };

    /**
     * スコアバーの達成率(%)から意味色レベルを求める。
     * thresholds = [{ lt:Number, level:'good|mid|bad' }]（昇順評価・最初に pct<lt を満たす level・無ければ good）。
     */
    function scoreLevel( pct, thresholds ) {
        if ( Array.isArray( thresholds ) ) {
            for ( var i = 0; i < thresholds.length; i++ ) {
                var t = thresholds[i];
                if ( t && typeof t.lt === 'number' && pct < t.lt && ALLOWED_LEVELS[ t.level ] ) {
                    return t.level;
                }
            }
        }
        return 'good';
    }

    /**
     * セル色分け（color_thresholds）から意味色レベルを求める。最初に条件を満たすルールの level。
     * op = lt|lte|gte|gt|between。level は固定 enum のみ採用。該当なし / 非数値は '' を返す（無着色）。
     */
    function cellLevel( value, rules ) {
        var n = parseFloat( value );
        if ( isNaN( n ) || ! Array.isArray( rules ) ) return '';
        for ( var i = 0; i < rules.length; i++ ) {
            var r = rules[i];
            if ( ! r || ! ALLOWED_LEVELS[ r.level ] || typeof r.value !== 'number' ) continue;
            var hit = false;
            switch ( r.op ) {
                case 'lt':      hit = n <  r.value; break;
                case 'lte':     hit = n <= r.value; break;
                case 'gte':     hit = n >= r.value; break;
                case 'gt':      hit = n >  r.value; break;
                case 'between': hit = ( typeof r.value2 === 'number' ) && n >= r.value && n <= r.value2; break;
            }
            if ( hit ) return r.level;
        }
        return '';
    }

    /**
     * アシスタント表の列 type 定義（Issue #1453 / W-5 PR-E2）。
     *
     * 旧実装は型ごとに headerCol.formatter クロージャを張っていた。これを qa-table の
     * インスタンス type レジストリ（new QATable(..., { types }) の options.types）へ移設する。
     * 各 format は _formatByType から `format.call(qaTableInstance, value, options)` で呼ばれ、
     * options = 列の typeOptions（= manifest の type_options）を実行時に読む（旧クロージャの
     * ビルド時キャプチャと同値）。escapeHtml/sanitizeUrl/scoreLevel/ALLOWED_LEVELS は本 IIFE の
     * クロージャを使う（qa-table 静的 API へ委譲済み）。
     *
     * sortAs / filterAs は旧 TYPE_MAP の native 型を完全再現し、ソート順・フィルタ演算子集合の
     * バイト等価を保つ（例 duration→'string'＝数値ソート化を防ぐ・scorebar/badge→native で
     * filterOptionsByType の取りこぼしを防ぐ）。
     *
     * ★スコープ規律: これはインスタンス上書き（表ごと）であってグローバル登録ではない。
     * QATable.registerType は使わない＝qa-table 単独利用の admin 画面 12箇所＋AIDE＋legacy の
     * 既定 type 挙動を一切汚さない（グローバル既定不変はテストで固定）。
     */
    var INSTANCE_TYPES = {
        integer: {
            sortAs: 'number', filterAs: 'number',
            format: function( value ) {
                var n = parseInt( value, 10 );
                return isNaN( n ) ? escapeHtml( value ) : n.toLocaleString();
            }
        },
        float: {
            sortAs: 'number', filterAs: 'number',
            format: function( value, options ) {
                var p = ( options && options.precision !== undefined ) ? options.precision : 2;
                var n = parseFloat( value );
                return isNaN( n ) ? escapeHtml( value ) : n.toLocaleString( undefined, { minimumFractionDigits: p, maximumFractionDigits: p } );
            }
        },
        percentage: {
            sortAs: 'number', filterAs: 'number',
            format: function( value, options ) {
                var p = ( options && options.precision !== undefined ) ? options.precision : 2;
                var n = parseFloat( value );
                return isNaN( n ) ? escapeHtml( value ) : n.toFixed( p ) + '%';
            }
        },
        currency: {
            sortAs: 'number', filterAs: 'number',
            format: function( value, options ) {
                var sym = ( options && options.currency ) ? options.currency : '¥';
                var n = parseFloat( value );
                return isNaN( n ) ? escapeHtml( value ) : sym + n.toLocaleString();
            }
        },
        duration: {
            sortAs: 'string', filterAs: 'string',
            format: function( value ) {
                var s = parseInt( value, 10 );
                if ( isNaN( s ) || s < 0 ) return escapeHtml( value );
                var h = Math.floor( s / 3600 );
                var m = Math.floor( ( s % 3600 ) / 60 );
                var sec = s % 60;
                return String( h ).padStart( 2, '0' ) + ':' + String( m ).padStart( 2, '0' ) + ':' + String( sec ).padStart( 2, '0' );
            }
        },
        link: {
            sortAs: 'link', filterAs: 'link',
            format: function( value, options ) {
                if ( ! value ) return '';
                var nt = ( options && options.new_tab !== undefined ) ? options.new_tab : true;
                var safeUrl = sanitizeUrl( value );
                var target = nt ? ' target="_blank" rel="noopener noreferrer"' : '';
                var raw = String( value );
                var display = raw.length > 50 ? raw.substring( 0, 50 ) + '...' : raw;
                return '<a href="' + escapeHtml( safeUrl ) + '"' + target + '>' + escapeHtml( display ) + '</a>';
            }
        },
        scorebar: {
            sortAs: 'number', filterAs: 'number',
            format: function( value, options ) {
                // Issue #1403: スコアバー（%横バー＋閾値で色＋%ラベル）。engine 無改修＝format が HTML を返す。
                var mx = ( options && typeof options.max === 'number' ) ? options.max : 100;
                var th = ( options && Array.isArray( options.thresholds ) ) ? options.thresholds : [ { lt: 50, level: 'bad' }, { lt: 80, level: 'mid' } ];
                // precision は toFixed の引数ゆえ 0..20 に clamp（異常値で RangeError を投げない防御）
                var pr = Math.min( 20, Math.max( 0, parseInt( ( options && options.precision ) || 0, 10 ) || 0 ) );
                var n = parseFloat( value );
                if ( isNaN( n ) ) return escapeHtml( value );
                var pct = ( mx > 0 ) ? ( n / mx ) * 100 : 0;
                if ( pct < 0 ) pct = 0;
                if ( pct > 100 ) pct = 100;
                var lvl = scoreLevel( pct, th );
                var w = pct.toFixed( 2 );
                var label = pct.toFixed( pr ) + '%';
                return '<div class="qa-scorebar">' +
                    '<span class="qa-scorebar__track"><i class="qa-scorebar__fill is-' + lvl + '" style="width:' + w + '%"></i></span>' +
                    '<span class="qa-scorebar__label is-' + lvl + '">' + label + '</span>' +
                    '</div>';
            }
        },
        badge: {
            sortAs: 'string', filterAs: 'string',
            format: function( value, options ) {
                // Issue #1403: 優先度バッジ（値→level 写像でピル表示）。map に無い値は素の escape 表示。
                var map = ( options && options.map && typeof options.map === 'object' ) ? options.map : {};
                var lvl = map[ String( value ) ];
                if ( ! ALLOWED_LEVELS[ lvl ] ) return escapeHtml( value );
                return '<span class="qa-badge is-' + lvl + '">' + escapeHtml( value ) + '</span>';
            }
        }
    };

    qahm.AssistantTable = {

        /**
         * Render a table from manifest definition
         *
         * @param {Object}       tableDef     Manifest table definition
         * @param {Array}        data          Array of row objects
         * @param {HTMLElement}  container     DOM container to append to
         * @param {Object}       runtime       AssistantRuntime instance (for translations)
         * @param {Object}       translations  Translations object
         * @param {Object}       [selectOptions]  Row-selection options (Issue #1386). { onRowClick: function( rowData, rowIndex ) }
         */
        renderTable: function( tableDef, data, container, runtime, translations, selectOptions ) {
            if ( ! data || data.length === 0 ) return;

            // Issue #1386: hidden columns carry data only (machine keys for row_action.set $row.<key>).
            // They are excluded from the header/render but their values are loaded onto each body row.
            var allColumns = tableDef.columns || [];
            var displayColumns = [];
            var hiddenColumns = [];
            for ( var hc = 0; hc < allColumns.length; hc++ ) {
                if ( allColumns[hc] && allColumns[hc].hidden === true ) {
                    hiddenColumns.push( allColumns[hc] );
                } else {
                    displayColumns.push( allColumns[hc] );
                }
            }

            // Filter out columns that are all empty (display columns only; hidden columns are always carried)
            var visibleColumns = this.filterEmptyColumns( displayColumns, data );

            if ( visibleColumns.length === 0 ) return;

            // Build qaTable header
            var header = [];
            for ( var i = 0; i < visibleColumns.length; i++ ) {
                var col = visibleColumns[i];
                var label = col.label || '';
                if ( runtime && typeof runtime.resolveTranslation === 'function' ) {
                    label = runtime.resolveTranslation( label );
                }

                // Issue #1453 PR-E2: 描画付き8型（INSTANCE_TYPES）は schema type を保ち、manifest の
                // type_options を typeOptions として渡す＝qa-table のインスタンス type レジストリが
                // 描画/ソート/フィルタを担う（formatter は張らない）。旧実装は型ごとに formatter
                // クロージャを張っていたが、その実体は INSTANCE_TYPES.<type>.format へ逐語移設済み。
                // 8型以外（string/date/datetime/boolean/filesize/html）は従来どおり native 型へ写像し、
                // qa-table 既定型に表示/ソート/フィルタを委譲する（挙動不変）。
                var headerCol = {
                    label: label,
                    key: col.key
                };

                // hasOwnProperty で引く＝col.type が 'toString' 等 Object.prototype 由来の名でも
                // 継承メンバを型と誤認しない（schema 検証で弾かれる値だが prototype-safe に固める・
                // qa-table 側 _resolveTypeEntry と同流儀）。
                if ( Object.prototype.hasOwnProperty.call( INSTANCE_TYPES, col.type ) ) {
                    headerCol.type = col.type;
                    if ( col.type_options ) {
                        headerCol.typeOptions = col.type_options;
                    }
                } else {
                    headerCol.type = Object.prototype.hasOwnProperty.call( TYPE_MAP, col.type ) ? TYPE_MAP[ col.type ] : 'string';
                }

                if ( col.width ) {
                    headerCol.width = col.width;
                }

                // Issue #1403: 値依存のセル背景色分け（color_thresholds）。opt-in cellClass フックへ橋渡し。
                // 数値列に併用可（type 非依存）。戻り値は固定 enum クラス（qa-cell-<level>）のみ。
                if ( col.color_thresholds && Array.isArray( col.color_thresholds ) ) {
                    headerCol.cellClass = (function( rules ) {
                        return function( val ) {
                            var lvl = cellLevel( val, rules );
                            return lvl ? 'qa-cell-' + lvl : '';
                        };
                    })( col.color_thresholds );
                }

                header.push( headerCol );
            }

            // Build qaTable body. Include hidden columns so row_action.set can read $row.<hidden key>.
            var bodyColumns = visibleColumns.concat( hiddenColumns );
            var body = [];
            for ( var r = 0; r < data.length; r++ ) {
                var row = data[r];
                var rowData = {};
                for ( var c = 0; c < bodyColumns.length; c++ ) {
                    var key = bodyColumns[c].key;
                    rowData[key] = row[key] !== undefined ? row[key] : '';
                }
                body.push( rowData );
            }

            // Build options
            var options = JSON.parse( JSON.stringify( DEFAULT_OPTIONS ) );
            if ( tableDef.options ) {
                var optKeys = Object.keys( tableDef.options );
                for ( var o = 0; o < optKeys.length; o++ ) {
                    options[ optKeys[o] ] = tableDef.options[ optKeys[o] ];
                }
            }

            // Initial sort
            if ( tableDef.initial_sort ) {
                options.initial_sort = tableDef.initial_sort;
            }

            // Create DOM structure
            if ( typeof qahm.assistantTable === 'undefined' ) {
                qahm.assistantTable = [];
            }

            var processNo = qahm.assistantProcessNo || 0;
            qahm.assistantProcessNo = processNo + 1;
            var tableKey = 'tb_assistant-manifest-' + processNo;

            var containerDiv = document.createElement( 'div' );
            containerDiv.className = 'qa-zero-data-container';
            var zeroDataDiv = document.createElement( 'div' );
            zeroDataDiv.className = 'qa-zero-data';
            containerDiv.appendChild( zeroDataDiv );

            // Title
            if ( tableDef.title ) {
                var titleText = tableDef.title;
                if ( runtime && typeof runtime.resolveTranslation === 'function' ) {
                    titleText = runtime.resolveTranslation( titleText );
                }
                if ( runtime && typeof runtime.expandTemplate === 'function' ) {
                    titleText = runtime.expandTemplate( titleText );
                }
                var titleEl = document.createElement( 'div' );
                titleEl.textContent = titleText;
                titleEl.id = tableKey + '-title';
                titleEl.className = 'qa-zero-data__title';
                zeroDataDiv.appendChild( titleEl );
            }

            var tableEl = document.createElement( 'div' );
            tableEl.id = tableKey;
            zeroDataDiv.appendChild( tableEl );
            container.appendChild( containerDiv );

            // Render table using qaTable
            // qaTable のデフォルトは sortable/filtering/exportable いずれも false なので、
            // assistant DEFAULT_OPTIONS (sortable: true 等) を qaTable オプションに明示的に渡す。
            // また manifest 側の snake_case (initial_sort) を camelCase (initialSort) に変換する。
            requestAnimationFrame( function() {
                if ( typeof qaTable !== 'undefined' && typeof qaTable.createTable === 'function' ) {
                    var qaTableOptions = {
                        maxHeight: options.max_height || 300,
                        stickyHeader: options.sticky_header !== undefined ? options.sticky_header : true,
                        sortable: options.sortable !== undefined ? options.sortable : true,
                        filtering: options.filtering !== undefined ? options.filtering : true,
                        exportable: options.exportable !== undefined ? options.exportable : true,
                        // Issue #1453 PR-E2: アシスタント表の描画付き型（integer/float/percentage/currency/
                        // duration/link/scorebar/badge）を qa-table のインスタンス type レジストリとして注入。
                        // インスタンススコープなので qa-table 単独利用のグローバル既定型は汚染しない。
                        types: INSTANCE_TYPES
                    };
                    if ( options.initial_sort && options.initial_sort.column ) {
                        qaTableOptions.initialSort = {
                            column: options.initial_sort.column,
                            direction: options.initial_sort.direction || 'asc'
                        };
                    }
                    // Issue #1386: row-click selection. Only wired when the caller passes onRowClick.
                    if ( selectOptions && typeof selectOptions.onRowClick === 'function' ) {
                        qaTableOptions.onRowClick = selectOptions.onRowClick;
                    }
                    qahm.assistantTable[tableKey] = qaTable.createTable( '#' + tableKey, header, qaTableOptions );
                    qahm.assistantTable[tableKey].updateData( body );
                } else {
                    console.error( 'qaTable is not available.' );
                }
            });
        },

        /**
         * Filter out columns where all rows have empty/null/undefined values
         *
         * @param {Array} columns  Column definitions
         * @param {Array} data     Row data
         * @returns {Array}  Filtered columns
         */
        filterEmptyColumns: function( columns, data ) {
            if ( ! columns || ! data || data.length === 0 ) return columns || [];

            var result = [];
            for ( var i = 0; i < columns.length; i++ ) {
                var col = columns[i];
                var hasValue = false;
                for ( var r = 0; r < data.length; r++ ) {
                    var val = data[r][ col.key ];
                    if ( val !== null && val !== undefined && val !== '' ) {
                        hasValue = true;
                        break;
                    }
                }
                if ( hasValue ) {
                    result.push( col );
                }
            }
            return result;
        }
    };

})();
