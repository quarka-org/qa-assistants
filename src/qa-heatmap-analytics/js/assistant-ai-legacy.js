/**
 * Assistant AI — Legacy AJAX Communication & Execute Processing
 *
 * FROZEN: このファイルは凍結済み。機能追加・変更は原則行わないこと。
 * 例外: スクロール挙動の統一（#985）— マニフェスト版との一貫性確保のため変更。
 * レガシー（main.php方式）アシスタントのAJAX通信・execute処理を担当。
 * 新規アシスタントはマニフェスト方式を使用すること。
 *
 * Depends on assistant-ai.js (qahm.* globals)
 */

// loadingElement is declared in assistant-ai.js

qahm.ajaxConnectAssistant = function(assistantSlug, assistantTalkNo, state = 'start', free = null, retryCount = 0) {
    let url = new URL(window.location.href);
    let params = url.searchParams;
    let tracking_id = params.get('tracking_id');

    let aiflag = false;
    if (state.substring(0, 2) === 'ai') {
        aiflag = true;
        const commandBox = document.querySelector('.qahm-conversation-command-box');
        if (commandBox) {
            commandBox.innerHTML = loadingElement;
        }
    }
    jQuery.ajax(
        {
            type: 'POST',
            url: qahm.ajax_url,
            dataType : 'json',
            data: {
                'action' : 'qahm_ajax_connect_assistant',
                'assistant_slug' : assistantSlug,
                'state' : state,
                'free' : free,
                'nonce': qahm.nonce_api,
                'tracking_id': tracking_id
            }
        }
    ).done(
        function( data ){
            if (data.success) {
                if (data.data.debug_logs && data.data.debug_logs.length > 0) {
                    data.data.debug_logs.forEach(function(log) {
                        const logMethod = log.level === 'error' ? 'error' :
                                        log.level === 'warning' ? 'warn' : 'info';

                        console.group(`🤖 [${log.class}] ${log.level.toUpperCase()} - ${log.state}`);
                        console[logMethod](`📝 ${log.message}`);
                        console.log(`⏰ ${new Date(log.timestamp * 1000).toLocaleString()}`);

                        if (log.context && Object.keys(log.context).length > 0) {
                            console.log('📊 Context:', log.context);
                        }

                        if (log.trace && log.trace.length > 0) {
                            console.log('🔍 Call Stack:', log.trace);
                        }

                        console.groupEnd();
                    });
                }

                qahm.executeAssistant(assistantSlug, assistantTalkNo, data.data);
            } else {
                console.error("Assistant接続エラー (success=false):", data);
                if (data.data) {
                    console.error("エラー詳細:", data.data);
                    if (data.data.expected_class) {
                        console.error("期待クラス名:", data.data.expected_class);
                    }
                    if (data.data.plugin_slug) {
                        console.error("プラグインスラッグ:", data.data.plugin_slug);
                    }
                    if (data.data.error_type) {
                        console.error("エラータイプ:", data.data.error_type);
                    }
                }
            }
            if (aiflag) {
                const commandBox = document.querySelector('.qahm-conversation-command-box');
                if (commandBox) {
                    commandBox.innerHTML = data.success ? '' : 'Something went wrong. Retrying...';
                }
            }
        }
    ).fail(
        function( xhr, status, error ){
            console.error("Assistant接続エラー:", status, error);
            if (xhr.responseJSON && xhr.responseJSON.data) {
                console.error("エラー詳細:", xhr.responseJSON.data);
                console.error("期待クラス名:", xhr.responseJSON.data.expected_class);
                console.error("プラグインスラッグ:", xhr.responseJSON.data.plugin_slug);
            }
            if (aiflag) {
                const commandBox = document.querySelector('.qahm-conversation-command-box');
                if (commandBox) {
                    commandBox.innerHTML = 'Something went wrong. Retrying...';
                }
            }
            if (retryCount < 3) {
                qahm.ajaxConnectAssistant(assistantSlug, assistantTalkNo, state, null, retryCount + 1);
            } else {
                qahm.connectAssistantFailed();
                console.log('Failed to connect to assistant after 3 attempts.');
            }
        }
    ).always(
        function(){
            qahm.isCharaClickDisabled = false;
        }
    );
}

qahm.executeAssistant = function( assistantSlug = 'official_robot', assistantTalkNo, executeJson ) {
    processExecute(assistantSlug, assistantTalkNo, executeJson.execute);
}

const processExecute = async (assistantSlug = 'official_robot', assistantTalkNo, executeObj) => {
    const containerId = 'qahm-assistant-talk-' + assistantTalkNo;
    const talkElem = document.getElementById(containerId);

    if (talkElem === null || typeof talkElem === 'undefined') {
        console.error(`Talk element ${containerId} not found`);
        return;
    }

    try {
        await qahm.conversationUI.renderExecute(containerId, executeObj, {
            autoScroll: false,
            onCommandClick: async function(action) {
                if (action.next) {
                    if (action.next === 'start') {
                        clearConversationHistory(talkElem);
                    } else if (action.userMessage) {
                        await renderUserMessageWithDelay(action.userMessage, talkElem);
                    }
                    const free = action.free || null;
                    qahm.ajaxConnectAssistant(assistantSlug, assistantTalkNo, action.next, free);
                }
                if (action.link) {
                    window.location.href = action.link;
                }
                if (action.close === 'window') {
                    window.close();
                }
            },

            onNext: function(nextState) {
                qahm.ajaxConnectAssistant(assistantSlug, assistantTalkNo, nextState, null);
            },

            translations: {
                endCommandLabel: qahml10n['end_command_label']
            },

            tableRenderer: function(data, container) {
                if (typeof qahm.assistantTable === 'undefined') {
                    qahm.assistantTable = [];
                }

                const processNo = qahm.assistantProcessNo++;
                const tableKey = 'tb_assistant-' + processNo;

                const fragment = document.createDocumentFragment();
                const containerDiv = document.createElement('div');
                containerDiv.className = 'qa-zero-data-container';
                const zeroDataDiv = document.createElement('div');
                zeroDataDiv.className = 'qa-zero-data';
                containerDiv.appendChild(zeroDataDiv);

                if (data.title) {
                    const newTitle = document.createElement('div');
                    newTitle.textContent = data.title;
                    newTitle.id = tableKey + '-title';
                    newTitle.className = 'qa-zero-data__title';
                    zeroDataDiv.appendChild(newTitle);
                }

                const newDiv = document.createElement('div');
                newDiv.id = tableKey;
                zeroDataDiv.appendChild(newDiv);
                fragment.appendChild(containerDiv);
                container.appendChild(fragment);

                requestAnimationFrame(() => {
                    qahm.assistantTable[tableKey] = qaTable.createTable('#' + tableKey, data.header, data.option);
                    qahm.assistantTable[tableKey].updateData(data.body);
                });
            },

            onError: function(error, context) {
                console.error('Assistant conversation error:', error, context);
                qahm.connectAssistantFailed();
            },

            onMessageRendered: function(element, type) {
            },

            onBeforeRender: function(executeArray) {
            },

            onAfterRender: function() {
            }
        });

    } catch (error) {
        console.error('Failed to render assistant conversation:', error);
        qahm.connectAssistantFailed();
    }
}

qahm.connectAssistantFailed = function( ) {
    const commandBox = document.querySelector('.qahm-conversation-command-box');
    commandBox.innerHTML = 'Sorry, we tried 3 times but failed. Please select a Assistant again and try running it once more.';
}

/**
 * 会話履歴をクリアし、状態をリセットする
 *
 * 会話ウィンドウの内容を削除し、トーク番号、プロセス番号、テーブル参照を
 * 初期化する。アシスタントを最初から開始する際に使用される。
 *
 * @param {HTMLElement} dialogueBox - 会話ウィンドウのDOM要素
 * @returns {void}
 *
 * @example
 * clearConversationHistory(talkElem);
 */
function clearConversationHistory(dialogueBox) {
    dialogueBox.innerHTML = '';
    qahm.assistantTalkNo = 0;
    qahm.assistantProcessNo = 0;
    qahm.assistantTable = undefined;
}

/**
 * ユーザーメッセージを表示し、指定時間待機する
 *
 * コマンドボタンクリックまたは将来のチャット入力で使用される汎用関数。
 * ユーザー発言は瞬時に表示され(タイプライター効果なし)、画面上端にスムーズスクロール
 * された後、0.6秒待機してからAI応答を取得する。(#985 でスクロール挙動を統一)
 *
 * @param {string} userMessage - 表示するユーザーメッセージ
 * @param {HTMLElement} dialogueBox - 会話ウィンドウのDOM要素
 * @returns {Promise<void>}
 * @throws {Error} dialogueBoxが見つからない場合
 *
 * @example
 * await renderUserMessageWithDelay('トップページ', talkElem);
 */
async function renderUserMessageWithDelay(userMessage, dialogueBox) {
    var messageDiv = await qahm.conversationUI._displayText(
        userMessage,
        dialogueBox,
        true,  // isUser = true (ユーザーメッセージとして表示)
        {
            enableTypewriter: false,  // 瞬時表示
            autoScroll: false,
            onMessageRendered: function(element, type) {
            }
        }
    );

    if ( messageDiv ) {
        messageDiv.scrollIntoView( { block: 'start', behavior: 'smooth' } );
    }

    await new Promise(resolve => setTimeout(resolve, 600));
}

// Temporarily commented out — heatmap integration for future implementation
/*
function convertPxToLink(text) {
    return text.replace(/(\d+)px/g, (match, p1) => {
        return `<span class="pxlink" data-scroll="${p1}" style="text-decoration: underline">${match}</span>`;
    });
}
*/

// Temporarily commented out — heatmap integration for future implementation
/*
function setupPxLinkEvents(targetElement) {
    const pxLinks = targetElement.querySelectorAll('.pxlink');
    pxLinks.forEach(link => {
        link.addEventListener('click', (event) => {
            let iframe = document.getElementById('heatmap-iframe');
            let iframeWindow = iframe.contentWindow;
            const position = event.target.getAttribute('data-scroll');
            let windowHeight = iframeWindow.innerHeight;
            let scrollToPosition = position - (windowHeight / 2);
            iframeWindow.scrollTo({
                top: scrollToPosition,
                behavior: 'smooth'
            });
        });
    });
}
*/
