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
        'filesize':   'number'
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

    qahm.AssistantTable = {

        /**
         * Render a table from manifest definition
         *
         * @param {Object}       tableDef     Manifest table definition
         * @param {Array}        data          Array of row objects
         * @param {HTMLElement}  container     DOM container to append to
         * @param {Object}       runtime       AssistantRuntime instance (for translations)
         * @param {Object}       translations  Translations object
         */
        renderTable: function( tableDef, data, container, runtime, translations ) {
            if ( ! data || data.length === 0 ) return;

            // Filter out columns that are all empty
            var visibleColumns = this.filterEmptyColumns( tableDef.columns, data );

            if ( visibleColumns.length === 0 ) return;

            // Build qaTable header
            var header = [];
            for ( var i = 0; i < visibleColumns.length; i++ ) {
                var col = visibleColumns[i];
                var label = col.label || '';
                if ( runtime && typeof runtime.resolveTranslation === 'function' ) {
                    label = runtime.resolveTranslation( label );
                }

                var qaType = TYPE_MAP[ col.type ] || 'string';

                var headerCol = {
                    label: label,
                    key: col.key,
                    type: qaType
                };

                if ( col.width ) {
                    headerCol.width = col.width;
                }

                // Type-specific formatting
                if ( col.type === 'integer' ) {
                    headerCol.formatter = function( val ) {
                        var n = parseInt( val, 10 );
                        return isNaN( n ) ? val : n.toLocaleString();
                    };
                } else if ( col.type === 'float' ) {
                    var precision = ( col.type_options && col.type_options.precision !== undefined ) ? col.type_options.precision : 2;
                    headerCol.formatter = (function( p ) {
                        return function( val ) {
                            var n = parseFloat( val );
                            return isNaN( n ) ? val : n.toLocaleString( undefined, { minimumFractionDigits: p, maximumFractionDigits: p } );
                        };
                    })( precision );
                } else if ( col.type === 'percentage' ) {
                    var pPrecision = ( col.type_options && col.type_options.precision !== undefined ) ? col.type_options.precision : 2;
                    headerCol.formatter = (function( p ) {
                        return function( val ) {
                            var n = parseFloat( val );
                            return isNaN( n ) ? val : n.toFixed( p ) + '%';
                        };
                    })( pPrecision );
                } else if ( col.type === 'duration' ) {
                    headerCol.formatter = function( val ) {
                        var s = parseInt( val, 10 );
                        if ( isNaN( s ) || s < 0 ) return String( val );
                        var h = Math.floor( s / 3600 );
                        var m = Math.floor( ( s % 3600 ) / 60 );
                        var sec = s % 60;
                        return String( h ).padStart( 2, '0' ) + ':' + String( m ).padStart( 2, '0' ) + ':' + String( sec ).padStart( 2, '0' );
                    };
                } else if ( col.type === 'link' ) {
                    var newTab = ( col.type_options && col.type_options.new_tab !== undefined ) ? col.type_options.new_tab : true;
                    headerCol.formatter = (function( nt ) {
                        return function( val ) {
                            if ( ! val ) return '';
                            var target = nt ? ' target="_blank" rel="noopener noreferrer"' : '';
                            var display = val.length > 50 ? val.substring( 0, 50 ) + '...' : val;
                            return '<a href="' + val + '"' + target + '>' + display + '</a>';
                        };
                    })( newTab );
                } else if ( col.type === 'currency' ) {
                    var symbol = ( col.type_options && col.type_options.currency ) ? col.type_options.currency : '\u00a5';
                    headerCol.formatter = (function( sym ) {
                        return function( val ) {
                            var n = parseFloat( val );
                            return isNaN( n ) ? val : sym + n.toLocaleString();
                        };
                    })( symbol );
                }

                header.push( headerCol );
            }

            // Build qaTable body
            var body = [];
            for ( var r = 0; r < data.length; r++ ) {
                var row = data[r];
                var rowData = {};
                for ( var c = 0; c < visibleColumns.length; c++ ) {
                    var key = visibleColumns[c].key;
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
            requestAnimationFrame( function() {
                if ( typeof qaTable !== 'undefined' && typeof qaTable.createTable === 'function' ) {
                    var qaTableOptions = {
                        maxHeight: options.max_height || 300,
                        stickyHeader: options.sticky_header !== undefined ? options.sticky_header : true
                    };
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
