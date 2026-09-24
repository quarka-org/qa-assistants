/**
 * QAHM Assistant Step Registry (Issue #1449)
 *
 * Single dispatch table for assistant step types. Replaces the legacy
 * property-existence if-chain in qahm-assistant-runtime.js executeStep()
 * with a canonical-order first-match resolve, and gives adapters (and,
 * later, external step packs) one documented way to add a step type.
 *
 * Ordering contract (the heart of behavior preservation):
 *   - The legacy if-chain checked the 15 built-in keys in a fixed order.
 *     That order is frozen here as CANONICAL_ORDER. Reserved (built-in)
 *     keys always resolve at their canonical position NO MATTER when or
 *     from which file they were registered — script load order (adapters
 *     load before the runtime) must never change dispatch priority.
 *   - Non-reserved keys resolve after all canonical keys, in registration
 *     order.
 *
 * Registration API:
 *   - qahm.assistantSteps.register( { key, handler } )
 *       For custom (non built-in) step types. Reserved keys are refused
 *       (console.warn, safe side). Duplicate keys: first registration wins.
 *   - qahm.assistantSteps.registerBuiltin( key, handler )
 *       For first-party files only (runtime / blocks adapter) to bind the
 *       reserved keys. Not part of the stable adapter API.
 *
 * Handler contract (uniform):
 *   handler( runtime, step ) — runtime is the AssistantRuntime instance,
 *   step is the raw step object. Sync handlers simply return their value;
 *   the runtime awaits every handler uniformly (await on a non-Promise
 *   resolves immediately), which preserves the legacy sync/async mix.
 *   Return value contract is unchanged: `{ goto: 'scene' }` switches the
 *   scene, anything falsy continues.
 *
 * @since 1.0.0
 */

var qahm = qahm || {};

(function() {
    'use strict';

    // The exact key order of the legacy executeStep if-chain (and of the
    // step.schema.json oneOf branches). Do not reorder: multi-key steps are
    // invalid per schema (additionalProperties:false), but fail-open manifests
    // can still reach the runtime, and for those the first-match priority is
    // preserved bug-for-bug.
    var CANONICAL_ORDER = [
        'message',
        'choices',
        'form',
        'fetch',
        'table',
        'chart',
        'scorecard',
        'callout',
        'divider',
        'html',
        'if',
        'goto',
        'set',
        'config_read',
        'config_write'
    ];

    // Null-prototype stores: bookkeeping lookups (RESERVED / handlers) must be
    // exact own-property checks, never fooled by Object.prototype members
    // ('constructor', 'toString', '__proto__', ...).
    var RESERVED = Object.create( null );
    for ( var i = 0; i < CANONICAL_ORDER.length; i++ ) {
        RESERVED[ CANONICAL_ORDER[i] ] = 1;
    }

    var handlers = Object.create( null ); // key -> handler( runtime, step )
    var externalOrder = [];               // non-reserved keys, in registration order

    qahm.assistantSteps = {

        /**
         * Register a custom step type (non built-in).
         *
         * @param {Object}   def          { key, handler }
         * @param {string}   def.key      Dispatch property name on the step object.
         * @param {Function} def.handler  handler( runtime, step ).
         * @returns {boolean} true if registered.
         */
        register: function( def ) {
            if ( ! def || typeof def.key !== 'string' || def.key === '' || typeof def.handler !== 'function' ) {
                console.warn( 'assistantSteps.register: invalid definition:', def );
                return false;
            }
            if ( RESERVED[ def.key ] ) {
                console.warn( 'assistantSteps.register: "' + def.key + '" is a reserved built-in step key and cannot be overridden.' );
                return false;
            }
            if ( def.key in Object.prototype ) {
                // '__proto__', 'constructor', 'toString', ... exist on every plain
                // object, so `step[key] !== undefined` could never distinguish
                // "step carries this key" — such a handler would swallow every
                // otherwise-unknown step. Refuse outright.
                console.warn( 'assistantSteps.register: "' + def.key + '" collides with Object.prototype and cannot be used as a step key.' );
                return false;
            }
            if ( handlers[ def.key ] ) {
                console.warn( 'assistantSteps.register: "' + def.key + '" is already registered (first registration wins).' );
                return false;
            }
            handlers[ def.key ] = def.handler;
            externalOrder.push( def.key );
            return true;
        },

        /**
         * Bind a built-in (reserved) step key. First-party use only
         * (qahm-assistant-runtime.js / adapters that own a built-in step);
         * NOT part of the stable adapter API. Re-binding warns and keeps
         * the first handler.
         *
         * @param {string}   key      A key from CANONICAL_ORDER.
         * @param {Function} handler  handler( runtime, step ).
         * @returns {boolean} true if bound.
         */
        registerBuiltin: function( key, handler ) {
            if ( ! RESERVED[ key ] || typeof handler !== 'function' ) {
                console.warn( 'assistantSteps.registerBuiltin: invalid built-in binding:', key );
                return false;
            }
            if ( handlers[ key ] ) {
                console.warn( 'assistantSteps.registerBuiltin: "' + key + '" is already bound (first binding wins).' );
                return false;
            }
            handlers[ key ] = handler;
            return true;
        },

        /**
         * Resolve a step object to its handler entry.
         * Canonical (built-in) keys first, in CANONICAL_ORDER; then external
         * keys in registration order. Property detection is the same
         * `step[key] !== undefined` the legacy chain used (a null/primitive
         * step behaves exactly as before, including TypeError on null).
         *
         * @param {Object} step  Raw step object from the manifest scene.
         * @returns {?{key: string, handler: Function}}
         */
        resolve: function( step ) {
            var k, j;
            for ( j = 0; j < CANONICAL_ORDER.length; j++ ) {
                k = CANONICAL_ORDER[j];
                if ( step[ k ] !== undefined && handlers[ k ] ) {
                    return { key: k, handler: handlers[ k ] };
                }
            }
            for ( j = 0; j < externalOrder.length; j++ ) {
                k = externalOrder[j];
                if ( step[ k ] !== undefined && handlers[ k ] ) {
                    return { key: k, handler: handlers[ k ] };
                }
            }
            return null;
        },

        /**
         * Reserved (built-in) keys that have no handler bound yet.
         * The runtime calls this after its own bindings (it is the last
         * assistant script to load) and reports gaps loudly — a missing
         * binding would otherwise only show up as a quiet "Unknown step
         * type" warn at dispatch time (#1449 PR-D2).
         *
         * @returns {string[]}
         */
        missingBuiltins: function() {
            var out = [];
            for ( var j = 0; j < CANONICAL_ORDER.length; j++ ) {
                if ( ! handlers[ CANONICAL_ORDER[j] ] ) {
                    out.push( CANONICAL_ORDER[j] );
                }
            }
            return out;
        },

        /**
         * Effective resolve order (bound canonical keys, then external keys).
         * Used by the equivalence tests to compare against the legacy chain.
         *
         * @returns {string[]}
         */
        keys: function() {
            var out = [];
            for ( var j = 0; j < CANONICAL_ORDER.length; j++ ) {
                if ( handlers[ CANONICAL_ORDER[j] ] ) {
                    out.push( CANONICAL_ORDER[j] );
                }
            }
            return out.concat( externalOrder.slice() );
        }
    };

})();
