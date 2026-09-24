/**
 * QAHM Assistant Scorecard Adapter (Issue #1409)
 *
 * Renders a manifest scorecard definition (a group of summary cards) into the
 * conversation container. Every card field is written with textContent only —
 * there is no innerHTML path here, so the XSS surface is zero even for values
 * that come from external data (#1299 / #1386 / #1393 discipline, but stronger:
 * a scorecard never needs HTML).
 *
 * Kept separate from qahm-assistant-runtime.js so the runtime hook stays minimal
 * (qa-labo actively rewrites the runtime; this file is core-owned) — same split
 * as qahm-assistant-chart.js.
 *
 * @since 1.0.0
 */

var qahm = qahm || {};

(function() {
    'use strict';

    // Value colouring is limited to this fixed set (mirrors the schema enum).
    // A card without a level renders neutral (no brand colour).
    var ALLOWED_LEVELS = { good: 1, mid: 1, bad: 1, info: 1 };

    qahm.AssistantScorecard = {

        /**
         * Render a scorecard from its manifest definition.
         *
         * @param {Object}      def          Manifest scorecard definition ({ title?, cards[] }).
         * @param {HTMLElement} container     DOM container to append to.
         * @param {Object}      runtime       AssistantRuntime instance (for translations / templates).
         * @param {Object}      translations  Translations object (unused directly; runtime resolves).
         */
        renderScorecard: function( def, container, runtime, translations ) {
            if ( ! def || ! Array.isArray( def.cards ) || def.cards.length === 0 ) return;

            // Resolve t: translation refs then expand {$var} templates. Both are
            // no-ops for plain strings, so it is safe to run on every field.
            var text = function( raw ) {
                if ( typeof raw !== 'string' ) return '';
                var out = raw;
                if ( runtime && typeof runtime.resolveTranslation === 'function' ) {
                    out = runtime.resolveTranslation( out );
                }
                if ( runtime && typeof runtime.expandTemplate === 'function' ) {
                    out = runtime.expandTemplate( out );
                }
                return out;
            };

            // Container DOM (mirrors qahm-assistant-chart.js so the CSS scope
            // `.qa-zero-data-container .qa-scorecard` applies).
            var containerDiv = document.createElement( 'div' );
            containerDiv.className = 'qa-zero-data-container';
            var zeroDataDiv = document.createElement( 'div' );
            zeroDataDiv.className = 'qa-zero-data';
            containerDiv.appendChild( zeroDataDiv );

            if ( def.title ) {
                var titleEl = document.createElement( 'div' );
                titleEl.className = 'qa-zero-data__title';
                titleEl.textContent = text( def.title );
                zeroDataDiv.appendChild( titleEl );
            }

            var grid = document.createElement( 'div' );
            grid.className = 'qa-scorecard';

            for ( var i = 0; i < def.cards.length; i++ ) {
                var card = def.cards[i];
                if ( ! card || typeof card !== 'object' ) continue;

                var cardEl = document.createElement( 'div' );
                cardEl.className = 'qa-scorecard__card';

                // Label
                var labelEl = document.createElement( 'span' );
                labelEl.className = 'qa-scorecard__label';
                labelEl.textContent = text( card.label );
                cardEl.appendChild( labelEl );

                // Value (+ optional unit). Non-numeric values render smaller (text variant).
                var valueStr = text( card.value );
                var valueEl = document.createElement( 'div' );
                var cls = 'qa-scorecard__value';
                if ( card.level && ALLOWED_LEVELS[ card.level ] ) {
                    cls += ' is-' + card.level;
                }
                if ( isNaN( parseFloat( valueStr ) ) ) {
                    cls += ' is-text';
                }
                valueEl.className = cls;
                valueEl.appendChild( document.createTextNode( valueStr ) );

                if ( card.unit ) {
                    var unitStr = text( card.unit );
                    if ( unitStr !== '' ) {
                        valueEl.appendChild( document.createTextNode( ' ' ) );
                        var unitEl = document.createElement( 'small' );
                        unitEl.textContent = unitStr;
                        valueEl.appendChild( unitEl );
                    }
                }
                cardEl.appendChild( valueEl );

                // Sub note (optional)
                if ( card.sub ) {
                    var subStr = text( card.sub );
                    if ( subStr !== '' ) {
                        var subEl = document.createElement( 'div' );
                        subEl.className = 'qa-scorecard__sub';
                        subEl.textContent = subStr;
                        cardEl.appendChild( subEl );
                    }
                }

                grid.appendChild( cardEl );
            }

            zeroDataDiv.appendChild( grid );
            container.appendChild( containerDiv );
        }
    };

})();
