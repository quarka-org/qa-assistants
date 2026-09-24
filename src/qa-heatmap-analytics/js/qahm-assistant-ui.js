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
     * HTML 特殊文字をエスケープして innerHTML 挿入を安全化する（Issue #1393）。
     * showChoices の選択 echo は _displayText の生 innerHTML 経路を通るため、
     * echo に渡す値はここで無害化する（ボタンの textContent は生のままで安全）。
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
                                escapeHtml( btn.label ),
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
     * Echo a selection (e.g. a clicked table row's label) into the conversation
     * history as a user message, mirroring the choice-button echo in showChoices.
     * Issue #1386 (table row_action).
     *
     * SECURITY CONTRACT (Issue #1393): `label` is passed RAW into _displayText's
     * innerHTML sink — the CALLER must escape it before calling (e.g. via
     * runtime.escapeHtml). External data (GSC keywords / page titles) left
     * unescaped here would be stored XSS. Cf. showChoices(), which escapes its
     * own echo argument (responsibility is asymmetric by design; a future
     * consolidation could unify it — see temp/sanitize-consolidation memo).
     *
     * @param {string} label  Pre-escaped text to display as the user's selection.
     * @returns {Promise}  Resolves after the echo is rendered (and a short pause).
     */
    AssistantUI.prototype.echoSelection = function( label ) {
        var self = this;
        return new Promise( function( resolve ) {
            if ( ! label ) { resolve(); return; }
            qahm.conversationUI._displayText(
                label,
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
                resolve();
            }).catch( function() {
                resolve();
            });
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

            // date_range フィールド等が document に付けたリスナーを後始末する（#1341）。
            // フォームを閉じる（submit / cancel）際に wrapper.remove() の前で呼ぶ。
            function cleanupFields() {
                var dr = form.querySelectorAll( '.qahm-conversation-form-daterange' );
                for ( var c = 0; c < dr.length; c++ ) {
                    if ( typeof dr[c]._qahmDateRangeDestroy === 'function' ) {
                        dr[c]._qahmDateRangeDestroy();
                    }
                }
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
                    cleanupFields();
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
                cleanupFields();
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

            case 'textarea':
                var textarea = document.createElement( 'textarea' );
                textarea.className = 'qahm-conversation-form-textarea';
                textarea.id = fieldId;
                textarea.setAttribute( 'data-key', fieldDef.key );
                if ( fieldDef.placeholder ) textarea.placeholder = fieldDef.placeholder;
                if ( fieldDef.required ) textarea.required = true;
                if ( fieldDef['default'] !== undefined ) textarea.value = fieldDef['default'];
                fieldWrapper.appendChild( textarea );
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

            case 'date_range':
                // 期間カレンダー（Cally アダプタ qahm.DateRange の popover）— #1341。
                // 表示専用の trigger input ＋ 値回収用の hidden input（id=fieldId）。
                // hidden.value は 'YYYY-MM-DD/YYYY-MM-DD'（collectFormValues が #qahm-form-<key> の .value を読む）。
                var drMount = document.createElement( 'div' );
                drMount.className = 'qahm-conversation-form-daterange';
                var drTrigger = document.createElement( 'input' );
                drTrigger.type = 'text';
                drTrigger.readOnly = true;
                drTrigger.className = 'qahm-conversation-form-input qahm-conversation-form-daterange-trigger';
                if ( fieldDef.placeholder ) drTrigger.placeholder = fieldDef.placeholder;
                // fieldId（= #qahm-form-<key>）は値回収用の hidden input に付くため、ラベルの for は
                // クリックでカレンダーを開ける trigger 側へ向け直す（UX / a11y）。
                var drTriggerId = fieldId + '-trigger';
                drTrigger.id = drTriggerId;
                var drLabel = fieldWrapper.querySelector( 'label.qahm-conversation-form-label' );
                if ( drLabel ) { drLabel.setAttribute( 'for', drTriggerId ); }
                var drHidden = document.createElement( 'input' );
                drHidden.type = 'hidden';
                drHidden.id = fieldId;
                drHidden.setAttribute( 'data-key', fieldDef.key );
                var drStart, drEnd;
                // max=today（未来日不可）。相対トークン解決でも max として渡すため先に算出する。
                var drToday = ( window.qahm && qahm.dateUtils && typeof qahm.dateUtils.getToday === 'function' )
                    ? qahm.dateUtils.getToday( 'YYYY-MM-DD' ) : undefined;
                // min=計測データ開始日（#1421）。他ページ（admin-page-dataviewer.js）と同じ既定＝
                // 「データがある一番古い日付」より前は選択不可にする。qahm.pvterm_start_date は
                // assistant ページでも localize 済み（Dataviewer 継承）。値が無ければ従来どおり min 無し。
                var drMin = ( window.qahm && typeof qahm.pvterm_start_date === 'string' && qahm.pvterm_start_date )
                    ? qahm.pvterm_start_date : undefined;
                var drDefault = fieldDef['default'];
                if ( typeof drDefault === 'string' && drDefault ) {
                    if ( drDefault.indexOf( '/' ) !== -1 ) {
                        // 絶対日付レンジ 'YYYY-MM-DD/YYYY-MM-DD'。min（データ開始日）より前は相対トークン経路と
                        // 同様にクランプする（#1421・クランプしないと「カレンダーに表示できない月」が選択値に
                        // 残り、表示と選択可能範囲が食い違う）。'YYYY-MM-DD' は辞書順比較で日付比較になる。
                        var drParts = drDefault.split( '/' );
                        drStart = drParts[0];
                        drEnd = drParts[1];
                        if ( drMin && drEnd < drMin ) {
                            // 全期間がデータ開始日より前＝成立しない範囲 → 無選択フォールバック（安全側・
                            // 未知トークン時と同格）。
                            drStart = undefined;
                            drEnd = undefined;
                        } else {
                            if ( drMin && drStart < drMin ) {
                                drStart = drMin;
                            }
                            drHidden.value = drStart + '/' + drEnd;
                        }
                    } else if ( window.qahm && qahm.DateRange && typeof qahm.DateRange.resolveRelative === 'function' ) {
                        // 相対トークン（last_30_days 等＝カレンダーの6プリセット）→ プリセットと同一計算で
                        // 解決し、初期値（＝そのプリセットをクリックした状態）に設定する（#1358）。
                        // hidden には解決済みの絶対値を入れるため、未操作で submit しても runtime split が効く。
                        var drRel = qahm.DateRange.resolveRelative( drDefault, { max: drToday, min: drMin } );
                        if ( drRel ) {
                            drStart = drRel.start;
                            drEnd = drRel.end;
                            drHidden.value = drRel.start + '/' + drRel.end;
                        } else if ( window.console && typeof console.warn === 'function' ) {
                            console.warn( '[qahm] date_range default: 未知の相対トークン "' + drDefault + '"（無選択にフォールバック）' );
                        }
                        // drRel === null（未知トークン or クランプ無効）→ drStart/drEnd 未設定＝無選択（従来どおり・安全）。
                    }
                }
                drMount.appendChild( drTrigger );
                drMount.appendChild( drHidden );
                fieldWrapper.appendChild( drMount );
                if ( window.qahm && qahm.DateRange && typeof qahm.DateRange.init === 'function' ) {
                    // qahm.dateUtils はアシスタント画面で利用可（dataviewer 経由）＝
                    // init 側が defaultPresets（過去7/30日・今週・先週・今月・先月の6種）を自動生成する。
                    var drHandle = qahm.DateRange.init( drMount, {
                        trigger: drTrigger,
                        start: drStart,
                        end: drEnd,
                        min: drMin,
                        max: drToday,
                        jqEvent: false,
                        // アシスタント会話 UI は overflow:hidden のスクロール枠（.qahm-assistant-dialogue-box 等）の
                        // 中にフォームを描画するため、popover を body 直下に fixed 配置してクリップを回避する（#1353）。
                        portal: true,
                        onChange: function( payload ) {
                            drHidden.value = payload.startStr + '/' + payload.endStr;
                        },
                    } );
                    // popover が document に付ける mousedown/keydown リスナーを後始末できるよう、
                    // destroy ハンドルを mount 要素に保持する（showForm が submit/cancel 時に呼ぶ）。
                    if ( drHandle && typeof drHandle.destroy === 'function' ) {
                        drMount._qahmDateRangeDestroy = drHandle.destroy;
                    }
                }
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

    AssistantUI.prototype.showLoading = function( text ) {
        // #1503: 3 ドット bubble（起動時 indicator #1226 と同一マークアップ）を即時表示する。
        // 消灯は hideLoading が MIN_SHOW_MS（＝アニメ1周期）を待ち合わせる（Promise）＝
        // 波が必ず1周完走し、次のメッセージはドット退場後に流れる（今井判断 2026-07-17）。
        var label = ( typeof text === 'string' && text.length > 0 ) ? text : '';
        var safeLabel = label
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' );
        var html = '<span class="qahm-typing-dot"></span><span class="qahm-typing-dot"></span><span class="qahm-typing-dot"></span>';
        if ( label && label !== '読み込み中' && label !== 'Loading' ) {
            html += '<span class="qahm-typing-label">' + safeLabel + '</span>';
        }
        if ( this.loadingEl ) {
            this.loadingEl.innerHTML = html;
            return;
        }
        this.loadingEl = document.createElement( 'div' );
        this.loadingEl.className = 'qahm-conversation-message qahm-conversation-typing';
        this.loadingEl.innerHTML = html;
        this._loadingShownAt = Date.now();
        this.container.appendChild( this.loadingEl );
    };

    AssistantUI.prototype.hideLoading = function() {
        // MIN_SHOW_MS（＝bounce アニメ1周期 1.0s）を満たしてから外し、外し終わってから resolve する。
        // 呼び出し側（runtime）は await 済み＝ドット表示中に次のメッセージが流れない。
        var MIN_SHOW_MS = 1000;
        var self = this;
        return new Promise( function( resolve ) {
            if ( ! self.loadingEl ) {
                resolve();
                return;
            }
            var removeAndResolve = function() {
                if ( self.loadingEl && self.loadingEl.parentNode ) {
                    self.loadingEl.parentNode.removeChild( self.loadingEl );
                }
                self.loadingEl = null;
                resolve();
            };
            var elapsed = Date.now() - ( self._loadingShownAt || 0 );
            if ( elapsed >= MIN_SHOW_MS ) {
                removeAndResolve();
            } else {
                setTimeout( removeAndResolve, MIN_SHOW_MS - elapsed );
            }
        } );
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
