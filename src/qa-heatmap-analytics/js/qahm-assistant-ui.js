/**
 * QAHM Assistant UI Module
 *
 * DOM operations for manifest-based assistant plugins.
 * Reuses existing CSS classes and conversationUI._displayText for typewriter effect.
 *
 * Scroll behavior (Claude.ai style):
 * - User message: scroll to top of viewport
 * - AI response: no auto-scroll (user scrolls manually)
 * - Scroll room provided by CSS ::after on dialogue-box (no JS spacer needed)
 *
 * @since 1.0.0
 */

var qahm = qahm || {};

(function() {
    'use strict';

    /**
     * AssistantUI class
     *
     * @param {HTMLElement} container  The dialogue box element
     */
    function AssistantUI( container ) {
        this.container = container;
        this.loadingEl = null;
    }

    /**
     * Show a message with typewriter effect
     *
     * @param {string} html  HTML content to display
     * @returns {Promise<void>}
     */
    AssistantUI.prototype.showMessage = async function( html ) {
        await qahm.conversationUI._displayText(
            html,
            this.container,
            false,
            {
                enableTypewriter: true,
                typewriterSpeed: 15,
                autoScroll: false,
                onMessageRendered: function() {}
            }
        );
        await this.pause( 300 );
    };

    /**
     * Show choice buttons and wait for user selection
     *
     * @param {Array} buttons  Array of { label, goto, set, clear }
     * @returns {Promise<Object>}  The chosen button object
     */
    AssistantUI.prototype.showChoices = function( buttons ) {
        var self = this;
        return new Promise( function( resolve ) {
            var wrapper = document.createElement( 'div' );
            wrapper.className = 'qahm-conversation-command-box';

            for ( var i = 0; i < buttons.length; i++ ) {
                (function( btn ) {
                    var button = document.createElement( 'button' );
                    button.className = 'qahm-conversation-command-button';
                    button.textContent = btn.label;
                    button.addEventListener( 'click', function() {
                        wrapper.remove();

                        if ( btn.label && ! btn.clear ) {
                            qahm.conversationUI._displayText(
                                btn.label,
                                self.container,
                                true,
                                {
                                    enableTypewriter: false,
                                    autoScroll: false,
                                    onMessageRendered: function() {}
                                }
                            ).then( function( messageDiv ) {
                                if ( messageDiv ) {
                                    messageDiv.scrollIntoView( { block: 'start', behavior: 'smooth' } );
                                }
                                return self.pause( 600 );
                            }).then( function() {
                                resolve( btn );
                            }).catch( function() {
                                resolve( btn );
                            });
                        } else {
                            resolve( btn );
                        }
                    });
                    wrapper.appendChild( button );
                })( buttons[i] );
            }

            self.container.appendChild( wrapper );
        });
    };

    /**
     * Show form fields and wait for submit or cancel
     */
    AssistantUI.prototype.showForm = function( formDef ) {
        var self = this;
        return new Promise( function( resolve ) {
            var wrapper = document.createElement( 'div' );
            wrapper.className = 'qahm-conversation-form';

            var form = document.createElement( 'form' );
            form.className = 'qahm-conversation-form-inner';

            var fields = formDef.fields || [];
            for ( var i = 0; i < fields.length; i++ ) {
                var fieldEl = self.buildFormField( fields[i] );
                form.appendChild( fieldEl );
            }

            var btnWrapper = document.createElement( 'div' );
            btnWrapper.className = 'qahm-conversation-form-buttons';

            var submitBtn = document.createElement( 'button' );
            submitBtn.type = 'submit';
            submitBtn.className = 'qahm-conversation-command-button';
            submitBtn.textContent = formDef.submit || 'Submit';
            btnWrapper.appendChild( submitBtn );

            if ( formDef.cancel ) {
                var cancelBtn = document.createElement( 'button' );
                cancelBtn.type = 'button';
                cancelBtn.className = 'qahm-conversation-command-button qahm-conversation-form-cancel';
                cancelBtn.textContent = formDef.cancel.label || 'Cancel';
                cancelBtn.addEventListener( 'click', function() {
                    wrapper.remove();
                    resolve( { cancelled: true, goto: formDef.cancel.goto || '' } );
                });
                btnWrapper.appendChild( cancelBtn );
            }

            form.appendChild( btnWrapper );

            form.addEventListener( 'submit', function( e ) {
                e.preventDefault();
                if ( ! form.reportValidity() ) {
                    return;
                }
                var values = self.collectFormValues( fields, form );
                wrapper.remove();
                resolve( { cancelled: false, values: values } );
            });

            wrapper.appendChild( form );
            self.container.appendChild( wrapper );
        });
    };

    // ─── Form field helpers ──────────────────────────────────

    AssistantUI.prototype.buildFormField = function( fieldDef ) {
        var fieldWrapper = document.createElement( 'div' );
        fieldWrapper.className = 'qahm-conversation-form-field';
        var fieldId = 'qahm-form-' + fieldDef.key;

        if ( fieldDef.label ) {
            var label = document.createElement( 'label' );
            label.className = 'qahm-conversation-form-label';
            label.textContent = fieldDef.label;
            if ( fieldDef.type !== 'radio' ) {
                label.setAttribute( 'for', fieldId );
            }
            fieldWrapper.appendChild( label );
        }

        switch ( fieldDef.type ) {
            case 'text':
            case 'url':
                var input = document.createElement( 'input' );
                input.type = fieldDef.type;
                input.className = 'qahm-conversation-form-input';
                input.id = fieldId;
                input.setAttribute( 'data-key', fieldDef.key );
                if ( fieldDef.placeholder ) input.placeholder = fieldDef.placeholder;
                if ( fieldDef.required ) input.required = true;
                if ( fieldDef['default'] !== undefined ) input.value = fieldDef['default'];
                fieldWrapper.appendChild( input );
                break;

            case 'radio':
                var radioGroup = document.createElement( 'div' );
                radioGroup.className = 'qahm-conversation-form-radio-group';
                var opts = fieldDef.options || [];
                for ( var r = 0; r < opts.length; r++ ) {
                    var radioLabel = document.createElement( 'label' );
                    radioLabel.className = 'qahm-conversation-form-radio-item';
                    var radio = document.createElement( 'input' );
                    radio.type = 'radio';
                    radio.name = fieldId;
                    radio.value = opts[r].value;
                    radio.setAttribute( 'data-key', fieldDef.key );
                    if ( fieldDef.required ) radio.required = true;
                    if ( fieldDef['default'] === opts[r].value ) {
                        radio.checked = true;
                    }
                    radioLabel.appendChild( radio );
                    radioLabel.appendChild( document.createTextNode( ' ' + opts[r].label ) );
                    radioGroup.appendChild( radioLabel );
                }
                fieldWrapper.appendChild( radioGroup );
                break;

            case 'select':
                var select = document.createElement( 'select' );
                select.className = 'qahm-conversation-form-select';
                select.id = fieldId;
                select.setAttribute( 'data-key', fieldDef.key );
                if ( fieldDef.required ) select.required = true;
                var selOpts = fieldDef.options || [];
                for ( var s = 0; s < selOpts.length; s++ ) {
                    var option = document.createElement( 'option' );
                    option.value = selOpts[s].value;
                    option.textContent = selOpts[s].label;
                    if ( fieldDef['default'] === selOpts[s].value ) {
                        option.selected = true;
                    }
                    select.appendChild( option );
                }
                fieldWrapper.appendChild( select );
                break;
        }

        return fieldWrapper;
    };

    AssistantUI.prototype.collectFormValues = function( fields, form ) {
        var values = {};
        for ( var i = 0; i < fields.length; i++ ) {
            var key = fields[i].key;
            var type = fields[i].type;
            if ( type === 'radio' ) {
                var checked = form.querySelector( 'input[name="qahm-form-' + key + '"]:checked' );
                values[key] = checked ? checked.value : '';
            } else {
                var el = form.querySelector( '#qahm-form-' + key );
                values[key] = el ? el.value : '';
            }
        }
        return values;
    };

    // ─── Utility methods ─────────────────────────────────────

    AssistantUI.prototype.showLoading = function() {
        if ( this.loadingEl ) return;
        this.loadingEl = document.createElement( 'div' );
        this.loadingEl.className = 'qahm-conversation-message';
        this.loadingEl.innerHTML = '<span class="el_loading">Loading<span></span></span>';
        this.container.appendChild( this.loadingEl );
    };

    AssistantUI.prototype.hideLoading = function() {
        if ( this.loadingEl ) {
            this.loadingEl.remove();
            this.loadingEl = null;
        }
    };

    AssistantUI.prototype.clearConversation = function() {
        this.container.innerHTML = '';
    };

    AssistantUI.prototype.getContainer = function() {
        return this.container;
    };

    AssistantUI.prototype.scrollToBottom = function() {
        this.container.scrollTop = this.container.scrollHeight;
    };

    AssistantUI.prototype.pause = function( ms ) {
        return new Promise( function( resolve ) {
            setTimeout( resolve, ms );
        });
    };

    // Export
    qahm.AssistantUI = AssistantUI;

})();
