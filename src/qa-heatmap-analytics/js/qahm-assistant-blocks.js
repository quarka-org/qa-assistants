/**
 * QAHM Assistant Layout Blocks Adapter (Issue #1414 / #1449)
 *
 * Owns the three layout-block step types and registers them with the step
 * registry (qahm.assistantSteps) at load time — this file is the reference
 * example of how a step type plugs into the runtime (Issue #1449 PR-D2):
 *   - callout : a notice / hint box (colored by level, icon via CSS ::before)
 *   - divider : a static horizontal rule
 *   - html    : free-form HTML rendered as a sanitized full-width block
 *
 * Security:
 *   - callout TITLE is written with textContent (no HTML).
 *   - callout BODY goes through runtime.renderLimitedMarkdown(), the same
 *     escape-first + allowlist renderer used by message steps (#1299). Its
 *     output is safe HTML, so assigning it to innerHTML does not add XSS surface.
 *   - divider carries no data.
 *   - html goes through qahm.sanitizeHtml() (shared DOMPurify helper, same
 *     allowlist as the table type:"html" column), degrading to a plain
 *     escape when the helper is absent (#1426).
 *   - level is mapped only through a fixed whitelist (never from raw author data).
 *
 * Both this file and qahm-assistant-runtime.js are core-owned (confirmed
 * 2026-07-06; an older comment here claimed qa-labo rewrites the runtime —
 * that was stale). The split is the adapter pattern, same as
 * qahm-assistant-chart.js / qahm-assistant-scorecard.js: the runtime keeps
 * the conversation core, adapters own their block types.
 *
 * Handlers only use the documented public runtime surface (see the
 * AssistantRuntime docblock): resolveTranslation / expandTemplate /
 * ui.getContainer / renderLimitedMarkdown / escapeHtml.
 *
 * @since 1.0.0
 */

var qahm = qahm || {};

(function() {
    'use strict';

    // Colouring is limited to this fixed set (mirrors the schema enum).
    // A callout without a level renders neutral (the "note" style).
    // Must stay identical to INLINE_TOKEN_LEVELS in qahm-assistant-runtime.js
    // (:::callout / ::stat body tokens — Issue #1416): the block step and the
    // inline token are two entrances to one visual entity.
    var ALLOWED_LEVELS = { good: 1, mid: 1, bad: 1, info: 1 };

    qahm.AssistantBlocks = {

        /**
         * Render a callout (notice / hint box).
         *
         * @param {Object}      def        { text, title?, level? }
         * @param {HTMLElement} container  DOM container to append to.
         * @param {Object}      runtime    AssistantRuntime instance (translations / templates / markdown).
         */
        renderCallout: function( def, container, runtime ) {
            if ( ! def || typeof def.text !== 'string' || def.text === '' ) return;

            var resolve = function( raw ) {
                if ( typeof raw !== 'string' ) return '';
                var out = raw;
                if ( runtime && typeof runtime.resolveTranslation === 'function' ) out = runtime.resolveTranslation( out );
                if ( runtime && typeof runtime.expandTemplate === 'function' ) out = runtime.expandTemplate( out );
                return out;
            };

            var containerDiv = document.createElement( 'div' );
            containerDiv.className = 'qa-zero-data-container';

            var box = document.createElement( 'div' );
            var cls = 'qa-callout';
            cls += ( def.level && ALLOWED_LEVELS[ def.level ] ) ? ' is-' + def.level : ' is-note';
            box.className = cls;

            var body = document.createElement( 'div' );
            body.className = 'qa-callout__body';

            if ( def.title ) {
                var titleEl = document.createElement( 'b' );
                titleEl.className = 'qa-callout__title';
                titleEl.textContent = resolve( def.title ); // title = plain text
                body.appendChild( titleEl );
            }

            var textEl = document.createElement( 'div' );
            textEl.className = 'qa-callout__text';
            var resolvedText = resolve( def.text );
            if ( runtime && typeof runtime.renderLimitedMarkdown === 'function' ) {
                // renderLimitedMarkdown escapes the whole string first, then restores
                // only an allowlist of tags — the result is safe HTML (#1299).
                textEl.innerHTML = runtime.renderLimitedMarkdown( resolvedText );
            } else {
                textEl.textContent = resolvedText; // defensive fallback = plain escape
            }
            body.appendChild( textEl );

            box.appendChild( body );
            containerDiv.appendChild( box );
            container.appendChild( containerDiv );
        },

        /**
         * Render a static horizontal divider.
         *
         * @param {HTMLElement} container  DOM container to append to.
         */
        renderDivider: function( container ) {
            var hr = document.createElement( 'hr' );
            hr.className = 'qa-divider';
            container.appendChild( hr );
        },

        /**
         * Render free-form HTML as a sanitized full-width block (Issue #1426 /
         * block rendering #1428; moved here from the runtime in #1449 PR-D2).
         *
         * Value pipeline is the same as message (translation → {$var} expansion)
         * but the result goes through qahm.sanitizeHtml() instead of the
         * limited-markdown renderer. {$var} expansion happens BEFORE sanitizing
         * on purpose: a variable may carry data-source values (visitor-influenced),
         * so the expanded whole is sanitized as one — anything injected via a
         * variable is neutralized too. Sanitization policy is the shared helper
         * (same allowlist as the table type:"html" column — "2 入口・1 実体").
         *
         * The html step is a block like table / callout / divider, so it is
         * appended straight to the conversation container inside a
         * .qa-zero-data-container wrapper (full width, no gray message bubble).
         * The wrapper keeps the QA component classes (qa-callout / qa-stat)
         * styled, since #1417 scopes them to both .qa-zero-data-container and
         * .qahm-conversation-message.
         *
         * @param {string}      def        The raw step.html string.
         * @param {HTMLElement} container  DOM container to append to.
         * @param {Object}      runtime    AssistantRuntime instance.
         */
        renderHtml: function( def, container, runtime ) {
            var raw = def;
            raw = runtime.resolveTranslation( raw );
            raw = runtime.expandTemplate( raw );
            var safe = ( window.qahm && typeof qahm.sanitizeHtml === 'function' )
                ? qahm.sanitizeHtml( raw )
                : runtime.escapeHtml( raw ); // ヘルパ不在時は escape に安全劣化（message と同じ escapeHtml）
            var wrap = document.createElement( 'div' );
            wrap.className = 'qa-zero-data-container';
            wrap.innerHTML = safe; // safe は sanitize 済み（DOMPurify allowlist or escape）
            container.appendChild( wrap );
        }
    };

    // ─── Step registry: layout-block bindings (Issue #1449 PR-D2) ───────
    // This adapter owns callout / divider / html, so it binds them itself.
    // Load order does NOT matter for dispatch priority: the registry's
    // canonical order table fixes the resolve order regardless of when a
    // reserved key gets bound (blocks loads before the runtime).
    // Return value: all three are display-only steps → null (continue scene).
    qahm.assistantSteps.registerBuiltin( 'callout', function( rt, step ) {
        qahm.AssistantBlocks.renderCallout( step.callout, rt.ui.getContainer(), rt );
        return null;
    } );
    qahm.assistantSteps.registerBuiltin( 'divider', function( rt, step ) {
        qahm.AssistantBlocks.renderDivider( rt.ui.getContainer() );
        return null;
    } );
    qahm.assistantSteps.registerBuiltin( 'html', function( rt, step ) {
        qahm.AssistantBlocks.renderHtml( step.html, rt.ui.getContainer(), rt );
        return null;
    } );

})();
