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
    }

    // ─── Scene execution ───────────────────────────────

    /**
     * Run a named scene
     */
    AssistantRuntime.prototype.runScene = async function( sceneName ) {
        var scenes = this.manifest.scenes;
        if ( ! scenes || ! scenes[sceneName] ) {
            console.error( 'Scene not found:', sceneName );
            return;
        }

        this.running = true;
        var steps = scenes[sceneName];

        for ( var i = 0; i < steps.length; i++ ) {
            if ( ! this.running ) break;
            var result = await this.executeStep( steps[i] );
            // If step returns a goto, switch scene
            if ( result && result.goto ) {
                await this.runScene( result.goto );
                return;
            }
        }
    };

    /**
     * Execute a single step
     */
    AssistantRuntime.prototype.executeStep = async function( step ) {
        if ( step.message !== undefined ) {
            return await this.handleMessage( step );
        }
        if ( step.choices !== undefined ) {
            return await this.handleChoices( step );
        }
        if ( step.form !== undefined ) {
            return await this.handleForm( step );
        }
        if ( step.fetch !== undefined ) {
            return await this.handleFetch( step );
        }
        if ( step.table !== undefined ) {
            return await this.handleTable( step );
        }
        if ( step['if'] !== undefined ) {
            return this.handleIf( step );
        }
        if ( step['goto'] !== undefined ) {
            return this.handleGoto( step );
        }
        if ( step.set !== undefined ) {
            return this.handleSet( step );
        }
        if ( step.config_read !== undefined ) {
            return await this.handleConfigRead( step );
        }
        if ( step.config_write !== undefined ) {
            return await this.handleConfigWrite( step );
        }
        console.warn( 'Unknown step type:', step );
        return null;
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
                    // Resolve $row.* in set
                    if ( choicesDef.set ) {
                        btn.set = {};
                        var setKeys = Object.keys( choicesDef.set );
                        for ( var s = 0; s < setKeys.length; s++ ) {
                            var sk = setKeys[s];
                            var sv = choicesDef.set[sk];
                            if ( typeof sv === 'string' && sv.indexOf( '$row.' ) === 0 ) {
                                var field = sv.substring( 5 );
                                btn.set[sk] = row[field] !== undefined ? row[field] : '';
                            } else {
                                btn.set[sk] = this.resolveValue( sv );
                            }
                        }
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
            var keys = Object.keys( result.values );
            for ( var i = 0; i < keys.length; i++ ) {
                self.vars[ keys[i] ] = result.values[ keys[i] ];
            }
        }

        return null;
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

        this.ui.showLoading();

        try {
            // Build resolved query
            var query = JSON.parse( JSON.stringify( ds.query ) );
            query = this.resolveQueryVars( query );

            // AJAX call to RuntimeHandler
            var response = await this.fetchData( query );

            this.ui.hideLoading();

            if ( ! response || ! response.success ) {
                var errMsg = ( response && response.data && response.data.message ) ? response.data.message : 'Data fetch failed.';
                console.error( 'Fetch error:', errMsg );
                if ( step.on_error ) {
                    return { goto: step.on_error };
                }
                await this.ui.showMessage( '<p>Error: ' + this.escapeHtml( errMsg ) + '</p>' );
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
            this.ui.hideLoading();
            console.error( 'Fetch exception:', err );
            if ( step.on_error ) {
                return { goto: step.on_error };
            }
            await this.ui.showMessage( '<p>Error: Failed to fetch data.</p>' );
        }

        return null;
    };

    /**
     * Make AJAX call to fetch data
     */
    AssistantRuntime.prototype.fetchData = function( query ) {
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
                    'tracking_id': self.systemVars.tracking_id || 'all'
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

        qahm.AssistantTable.renderTable( tableDef, data, this.ui.getContainer(), this, this.translations );

        return null;
    };

    /**
     * Handle if/then step
     */
    AssistantRuntime.prototype.handleIf = function( step ) {
        var cond = step['if'];
        var varName = cond['var'] || cond.var;
        var val = this.vars[varName];
        var is = cond.is;
        var matched = false;

        switch ( is ) {
            case 'empty':
                matched = this.isEmpty( val );
                break;
            case 'not_empty':
                matched = ! this.isEmpty( val );
                break;
            case 'eq':
                matched = ( val === cond.value );
                break;
            case 'neq':
                matched = ( val !== cond.value );
                break;
        }

        if ( matched && step.then ) {
            if ( step.then.goto ) {
                return { goto: step.then.goto };
            }
        }
        return null;
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
            var keys = Object.keys( setObj );
            for ( var i = 0; i < keys.length; i++ ) {
                this.vars[ keys[i] ] = this.resolveValue( setObj[ keys[i] ] );
            }
        }
        return null;
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
                        'tracking_id': self.systemVars.tracking_id || 'all',
                        'plugin_id': self.manifest.id || ''
                    }
                }).done( function( data ) {
                    resolve( data );
                }).fail( function( xhr, status, error ) {
                    console.error( 'config_read AJAX failed:', status, error );
                    resolve( null );
                });
            });

            this.ui.hideLoading();

            if ( ! response || ! response.success ) {
                if ( def.on_error ) {
                    return { goto: def.on_error };
                }
                return null;
            }

            var data = response.data;

            // Store main data (items only)
            if ( into ) {
                this.vars[ into ] = data.items || {};

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
            this.ui.hideLoading();
            console.error( 'config_read exception:', err );
            if ( def.on_error ) {
                return { goto: def.on_error };
            }
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

        this.ui.showLoading();

        try {
            var response = await new Promise( function( resolve ) {
                jQuery.ajax({
                    type: 'POST',
                    url: qahm.ajax_url,
                    dataType: 'json',
                    data: {
                        'action': 'qahm_ajax_write_config',
                        'nonce': qahm.nonce_api,
                        'category': category,
                        'tracking_id': self.systemVars.tracking_id || 'all',
                        'plugin_id': self.manifest.id || '',
                        'key': resolvedKey,
                        'value': JSON.stringify( resolvedValue )
                    }
                }).done( function( data ) {
                    resolve( data );
                }).fail( function( xhr, status, error ) {
                    console.error( 'config_write AJAX failed:', status, error );
                    resolve( null );
                });
            });

            this.ui.hideLoading();

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
            this.ui.hideLoading();
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

        if ( val.indexOf( '$sys.' ) === 0 ) {
            var sysKey = val.substring( 5 );
            return this.systemVars[sysKey] !== undefined ? this.systemVars[sysKey] : '';
        }
        if ( val.indexOf( '$' ) === 0 ) {
            var varName = val.substring( 1 );
            return this.vars[varName] !== undefined ? this.vars[varName] : '';
        }
        return val;
    };

    /**
     * Expand template strings: {$var}, {$var|format}
     */
    AssistantRuntime.prototype.expandTemplate = function( text ) {
        if ( typeof text !== 'string' ) return text;

        var self = this;
        return text.replace( /\{\$([a-zA-Z0-9_.]+)(?:\|([a-zA-Z_]+))?\}/g, function( match, varPath, format ) {
            var val;
            if ( varPath.indexOf( 'sys.' ) === 0 ) {
                val = self.systemVars[ varPath.substring(4) ];
            } else {
                val = self.vars[varPath];
            }
            if ( val === undefined || val === null ) val = '';
            if ( format ) {
                val = self.formatValue( val, format );
            }
            return String( val );
        });
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
                return text; // Key not found
            }
        }

        return typeof current === 'string' ? current : text;
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
     */
    AssistantRuntime.prototype.formatValue = function( value, format ) {
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
            default:
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
     * Check if a value is empty
     */
    AssistantRuntime.prototype.isEmpty = function( val ) {
        if ( val === null || val === undefined || val === '' ) return true;
        if ( Array.isArray( val ) && val.length === 0 ) return true;
        return false;
    };

    // ─── Limited markdown ──────────────────────────────

    /**
     * Render limited markdown: **bold**, [text](url), \n
     */
    AssistantRuntime.prototype.renderLimitedMarkdown = function( text ) {
        if ( typeof text !== 'string' ) return text;

        // HTML escape first
        text = this.escapeHtml( text );

        // **bold**
        text = text.replace( /\*\*(.+?)\*\*/g, '<strong>$1</strong>' );

        // [text](url) — block javascript: scheme
        text = text.replace( /\[([^\]]+)\]\(([^)]+)\)/g, function( match, linkText, url ) {
            if ( /^\s*javascript\s*:/i.test( url ) ) {
                return linkText; // Block dangerous scheme
            }
            return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + linkText + '</a>';
        });

        // \n → <br>
        text = text.replace( /\\n/g, '<br>' );

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
            }
        }
        return data;
    };

    /**
     * Sort transform
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
            if ( typeof va === 'number' && typeof vb === 'number' ) {
                return ( va - vb ) * asc;
            }
            return String( va ).localeCompare( String( vb ) ) * asc;
        });
    };

    /**
     * Filter transform
     */
    AssistantRuntime.prototype.transformFilter = function( data, filterDef ) {
        var self = this;
        var fields = Object.keys( filterDef );

        return data.filter( function( row ) {
            for ( var i = 0; i < fields.length; i++ ) {
                var field = fields[i];
                var cond = filterDef[field];
                var val = row[field];

                if ( cond.eq !== undefined ) {
                    var target = self.resolveValue( cond.eq );
                    if ( val !== target ) return false;
                }
                if ( cond.neq !== undefined ) {
                    var targetNeq = self.resolveValue( cond.neq );
                    if ( val === targetNeq ) return false;
                }
                if ( cond.gt !== undefined ) {
                    if ( val === null || val === undefined || val <= cond.gt ) return false;
                }
                if ( cond.gte !== undefined ) {
                    if ( val === null || val === undefined || val < cond.gte ) return false;
                }
                if ( cond.lt !== undefined ) {
                    if ( val === null || val === undefined || val >= cond.lt ) return false;
                }
                if ( cond.lte !== undefined ) {
                    if ( val === null || val === undefined || val > cond.lte ) return false;
                }
                if ( cond['in'] !== undefined ) {
                    if ( ! Array.isArray( cond['in'] ) || cond['in'].indexOf( val ) === -1 ) return false;
                }
                if ( cond.contains !== undefined ) {
                    if ( typeof val !== 'string' || val.indexOf( cond.contains ) === -1 ) return false;
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
        var groups = {};

        for ( var i = 0; i < data.length; i++ ) {
            var row = data[i];
            var key = String( row[groupField] || '' );
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
        var vals = [];
        for ( var i = 0; i < rows.length; i++ ) {
            var v = rows[i][field];
            if ( v !== null && v !== undefined && v !== '' ) {
                vals.push( Number( v ) );
            }
        }
        if ( vals.length === 0 ) return 0;

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
            case 'first':
                return rows[0][field];
            default:
                return 0;
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

    // ─── Expression parser ─────────────────────────────

    /**
     * Evaluate a row-level expression (safe, no eval)
     * Supports: field references, +, -, *, /, parentheses
     */
    AssistantRuntime.prototype.evaluateExpression = function( expr, row ) {
        try {
            var tokens = this.tokenize( expr );
            var pos = { i: 0 };
            var result = this.parseAddSub( tokens, pos, row, null );
            return ( result === null || result === undefined || isNaN( result ) ) ? 0 : result;
        } catch ( e ) {
            return 0;
        }
    };

    /**
     * Evaluate a global expression: sum(field), avg(field), count(field), min(field), max(field), first(field)
     */
    AssistantRuntime.prototype.evaluateGlobalExpression = function( expr, data ) {
        var match = /^(sum|avg|count|min|max|first)\(([a-zA-Z_][a-zA-Z0-9_]*)\)$/.exec( expr.trim() );
        if ( match ) {
            return this.aggregate( data, match[2], match[1] );
        }
        // Fallback: try as simple expression on first row
        if ( data.length > 0 ) {
            return this.evaluateExpression( expr, data[0] );
        }
        return 0;
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
                tokens.push( { type: 'num', value: parseFloat( num ) } );
            } else if ( /[a-zA-Z_]/.test( ch ) ) {
                var id = '';
                while ( i < expr.length && /[a-zA-Z0-9_]/.test( expr[i] ) ) {
                    id += expr[i];
                    i++;
                }
                tokens.push( { type: 'id', value: id } );
            } else {
                i++; // Skip unknown
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
            else left = ( right !== 0 ) ? left / right : 0;
        }
        return left;
    };

    /**
     * Parse primary: number, identifier (field ref), parenthesized expression
     */
    AssistantRuntime.prototype.parsePrimary = function( tokens, pos, row ) {
        if ( pos.i >= tokens.length ) return 0;

        var token = tokens[pos.i];

        if ( token.type === 'num' ) {
            pos.i++;
            return token.value;
        }
        if ( token.type === 'id' ) {
            pos.i++;
            var val = row ? row[token.value] : 0;
            return ( val !== null && val !== undefined ) ? Number( val ) : 0;
        }
        if ( token.type === 'op' && token.value === '(' ) {
            pos.i++;
            var result = this.parseAddSub( tokens, pos, row );
            if ( pos.i < tokens.length && tokens[pos.i].value === ')' ) {
                pos.i++;
            }
            return result;
        }
        // Unary minus
        if ( token.type === 'op' && token.value === '-' ) {
            pos.i++;
            return -this.parsePrimary( tokens, pos, row );
        }

        pos.i++;
        return 0;
    };

    // Export
    qahm.AssistantRuntime = AssistantRuntime;

})();
