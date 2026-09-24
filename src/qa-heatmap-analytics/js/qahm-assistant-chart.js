/**
 * QAHM Assistant Chart Adapter (Issue #1280 Phase 4)
 *
 * Converts manifest chart definitions to qahm.EChart specs and renders them
 * into the conversation container. All drawing goes through the shared
 * ECharts wrapper (lib/echarts/qahm-echarts.js) — no echarts calls here.
 *
 * Kept separate from qahm-assistant-runtime.js so the runtime hook stays
 * minimal (qa-labo actively rewrites the runtime; this file is core-owned).
 *
 * @since 1.0.0
 */

var qahm = qahm || {};

(function() {
    'use strict';

    /**
     * Manifest chart type → wrapper spec kind / extras
     */
    var KIND_MAP = {
        'bar':      { kind: 'bar' },
        'line':     { kind: 'line' },
        'pie':      { kind: 'pie' },
        'doughnut': { kind: 'pie', donut: true },
        'hbar':     { kind: 'hbar' }
    };

    var DEFAULT_HEIGHT = 260;

    qahm.AssistantChart = {

        /**
         * Render a chart from manifest definition
         *
         * @param {Object}       chartDef      Manifest chart definition
         * @param {Array}        data          Array of row objects
         * @param {HTMLElement}  container     DOM container to append to
         * @param {Object}       runtime       AssistantRuntime instance (for translations)
         * @param {Object}       translations  Translations object
         */
        renderChart: function( chartDef, data, container, runtime, translations ) {
            if ( ! data || data.length === 0 ) return;

            var mapped = KIND_MAP[ chartDef.type ];
            if ( ! mapped ) {
                console.error( 'Unknown chart type:', chartDef.type );
                return;
            }

            var resolve = function( text ) {
                if ( runtime && typeof runtime.resolveTranslation === 'function' ) {
                    text = runtime.resolveTranslation( text );
                }
                return text;
            };

            // Category labels from the x field
            var labels = [];
            for ( var r = 0; r < data.length; r++ ) {
                var v = data[r][ chartDef.x ];
                labels.push( v === undefined || v === null ? '' : String( v ) );
            }

            // pie / doughnut / hbar render a single value series (documented in chart.schema.json)
            var seriesDefs = chartDef.series;
            if ( 'pie' === mapped.kind || 'hbar' === mapped.kind ) {
                if ( seriesDefs.length > 1 ) {
                    console.warn( 'Chart type "' + chartDef.type + '" uses only the first series (got ' + seriesDefs.length + ').' );
                }
                seriesDefs = [ seriesDefs[0] ];
            }

            var series = [];
            for ( var s = 0; s < seriesDefs.length; s++ ) {
                var def = seriesDefs[s];
                var values = [];
                for ( var i = 0; i < data.length; i++ ) {
                    var num = Number( data[i][ def.field ] );
                    values.push( isNaN( num ) ? 0 : num );
                }
                var one = {
                    name: resolve( def.label || def.field ),
                    data: values
                };
                if ( def.type ) {
                    one.type = def.type;
                }
                if ( def.color ) {
                    one.color = def.color;
                }
                series.push( one );
            }

            var spec = {
                kind: mapped.kind,
                labels: labels,
                series: series,
                // single-series bar/line/hbar: the title says it all, keep the canvas clean
                legend: 'pie' === mapped.kind || series.length > 1
            };
            if ( mapped.donut ) {
                spec.donut = true;
            }
            if ( 'bar' === mapped.kind || 'line' === mapped.kind ) {
                spec.maxXTicks = 10;
            }

            // Container DOM (mirrors qahm-assistant-table.js)
            var options = chartDef.options || {};
            var containerDiv = document.createElement( 'div' );
            containerDiv.className = 'qa-zero-data-container';
            var zeroDataDiv = document.createElement( 'div' );
            zeroDataDiv.className = 'qa-zero-data';
            containerDiv.appendChild( zeroDataDiv );

            if ( chartDef.title ) {
                var titleText = resolve( chartDef.title );
                if ( runtime && typeof runtime.expandTemplate === 'function' ) {
                    titleText = runtime.expandTemplate( titleText );
                }
                var titleEl = document.createElement( 'div' );
                titleEl.textContent = titleText;
                titleEl.className = 'qa-zero-data__title';
                zeroDataDiv.appendChild( titleEl );
            }

            var chartEl = document.createElement( 'div' );
            chartEl.className = 'qahm-ec-chart';
            // ECharts needs a sized element; the conversation container has no fixed
            // height, so set it explicitly. hbar grows with the row count.
            var height = options.height;
            if ( ! height ) {
                height = 'hbar' === mapped.kind ? Math.max( 220, data.length * 26 + 30 ) : DEFAULT_HEIGHT;
            }
            chartEl.style.height = height + 'px';
            zeroDataDiv.appendChild( chartEl );
            container.appendChild( containerDiv );

            // Wait one frame so the element has layout before echarts.init measures it
            requestAnimationFrame( function() {
                if ( qahm.EChart && typeof qahm.EChart.create === 'function' ) {
                    qahm.EChart.create( chartEl, spec );
                } else {
                    console.error( 'qahm.EChart is not available.' );
                }
            } );
        }
    };

})();
