var qahm = qahm || {};

qahm.assistantTalkNo = 0;
qahm.assistantProcessNo = 0;

createdAssistant = {};
document.addEventListener("DOMContentLoaded", function() {
    qahm.createAssistant();
});

// キャラクターのクリックイベントを無効化するフラグ
qahm.isCharaClickDisabled = false;

qahm.createAssistant = function(assistantSlug = '', connectStep = 'start') {
    switch (connectStep) {
        case 'start':
            qahm.createAssistant(assistantSlug, 'getAllAssistant');
            break;

        case 'getAllAssistant':
            jQuery.ajax({
                type: 'POST',
                url: qahm.ajax_url,
                dataType: 'json',
                data: {
                    'action': 'qahm_ajax_get_assistant',
                    'nonce': qahm.nonce_api,
                }
            })
            .done(function(data) {
                if (data.success) {
                    qahm.allAssistants = data.data;
                    qahm.createAssistant(assistantSlug, 'renderAssistantSelector');
                }
            })
            .fail(function(jqXHR, textStatus, errorThrown) {
                console.log('Error: ' + errorThrown);
            })
            .always(function() {
                // This function will always be called
            });
            break;

        case 'renderAssistantSelector':
            if (qahm.checkAssistantsPage()) {
                qahm.renderAssistantSelector();
            } else {
                qahm.createAssistant(assistantSlug, 'renderAssistantConversation');
            }
            break;

        case 'renderAssistantConversation':
            qahm.renderAssistantConversation(assistantSlug);
            if ( qahm.allAssistants[assistantSlug] && qahm.allAssistants[assistantSlug].manifest_url ) {
                qahm.launchManifestRuntime(assistantSlug);
            } else {
                qahm.createAssistant(assistantSlug, 'ajaxConnectAssistant');
            }
            break;

        case 'ajaxConnectAssistant':
            qahm.ajaxConnectAssistant(assistantSlug, qahm.assistantTalkNo, 'start');
            qahm.assistantTalkNo++;
            break;
    }
}

qahm.checkAssistantsPage = function( ) {
    const element = document.getElementById('this_page_is_assistantpage');
    if (element) {
        return true;
    } else {
        return false;
    }
}

/**
 * アシスタント選択画面をレンダリングする
 *
 * 利用可能なアシスタントプラグインのカードを表示し、ユーザーが選択できる
 * ようにする。アシスタントがインストールされていない場合は、空の状態画面を
 * 表示する。カードはSortable.jsでドラッグ&ドロップ可能で、順序はLocalStorageに
 * 保存される。
 *
 * @returns {void}
 *
 * @example
 * qahm.renderAssistantSelector();
 */
qahm.renderAssistantSelector = function() {
    const hasAssistants = Object.keys(qahm.allAssistants).length > 0;

    let html = `
	<div class="qahm-assistant-selector-inner">`;

    if (!hasAssistants) {
        const icon = `
        <svg viewBox="14 10 126 60" width="120" height="60" aria-hidden="true"
        fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round">
            <title>AI Assistant (Not Installed)</title>

            <!-- 吹き出し本体 -->
            <rect x="29" y="17" width="30" height="26" rx="7" ry="7"/>

            <!-- ちょん -->
            <path d="M55 43 L57 47 L51 43 Z"/>

            <!-- バツ印 -->
            <path d="M41 27 l7 7 M48 27 l-7 7"/>

            <!-- 丸い顔（右） -->
            <circle cx="92" cy="40" r="24"/>
            <circle cx="84" cy="36" r="2.4" fill="currentColor" stroke="none"/>
            <circle cx="100" cy="36" r="2.4" fill="currentColor" stroke="none"/>
            <path d="M82 47c6 4 14 4 20 0"/>
        </svg>`;

        // Differs between ZERO and QA - Start ----------
        if (qahm.type !== qahm.type_zero) {
            html += `
            <div class="qahm-assistant-selector-empty-state">
                <div class="qahm-assistant-selector-empty-icon">${icon}</div>
                <h3>${qahml10n['no_assistant_installed_title']}</h3>
                <p>${qahml10n['assistant_installation_required']}</p>
                <a href="${qahml10n['download_assistant_url']}" class="qahm-assistant-selector-empty-download-button" target="_blank">
                    ${qahml10n['download_first_assistant']}
                </a>
            </div>
            `;
        }
        // Differs between ZERO and QA - End ----------
    } else {
        html += `
		<div class="qahm-assistant-selector-title">
			${qahml10n['select_agent']}
		</div>
		<div class="qahm-assistant-selector-cards">`;

        Object.keys(qahm.allAssistants).forEach((key) => {
		const assistant = qahm.allAssistants[key];
		html += `
			<div class="qahm-assistant-selector-card" data-assistant-slug="${assistant.slug}">
				<img class="qahm-assistant-selector-image" src="${assistant.images.default}" alt="${assistant.description}">
				<div class="qahm-assistant-selector-info">
					<div class="qahm-assistant-selector-name" data-assistant-slug="${assistant.slug}">${assistant.name}</div>
					<div class="qahm-assistant-selector-description">${assistant.description}</div>
					<div class="qahm-assistant-selector-version">v${assistant.version}</div>
				</div>
			</div>`;
        });

        html += `
	</div>
	<div class="qahm-assistant-selector-hint">※ ${qahml10n['drag_to_reorder'] || 'You can rearrange assistants by dragging them.'}</div>`;

        // Differs between ZERO and QA - Start ----------
        if (qahm.type !== qahm.type_zero) {
            html += `
	<a href="${qahml10n['download_assistant_url']}"
	   class="qahm-assistant-selector-download-button"
	   target="_blank">
		${qahml10n['download_more_assistants']}
	</a>`;
        }
        // Differs between ZERO and QA - End ----------
    }

    html += `
	</div>`;

    document.getElementById('qahm-assistant-selector').innerHTML = html;

	if (hasAssistants) {
		const container = document.querySelector('.qahm-assistant-selector-cards');
		const storageKey = 'assistant-selector-sort-order';

		function restoreOrder() {
			const savedOrder = JSON.parse(localStorage.getItem(storageKey) || '[]');
			const currentEls = Array.from(container.children);
			const currentIds = currentEls.map(el => el.getAttribute('data-assistant-slug'));

			const fragment = document.createDocumentFragment();

			savedOrder.forEach(slug => {
				if (currentIds.includes(slug)) {
				const el = container.querySelector(`[data-assistant-slug="${slug}"]`);
				if (el) fragment.appendChild(el);
				}
			});

			currentEls.forEach(el => {
				const id = el.getAttribute('data-assistant-slug');
				if (!savedOrder.includes(id)) {
				fragment.appendChild(el);
				}
			});

			container.appendChild(fragment);
		}

		function saveOrder() {
			const order = [...container.children].map(el => el.getAttribute('data-assistant-slug'));
			localStorage.setItem(storageKey, JSON.stringify(order));
		}

		new Sortable(container, {
			animation: 200,
			ghostClass: 'qahm-assistant-selector-drag-ghost',
			chosenClass: 'qahm-assistant-selector-drag-chosen',
			onEnd: saveOrder
		});

		restoreOrder();
	}

    // セルがクリックされたときの処理をセット
    let cards = document.querySelectorAll('.qahm-assistant-selector-card');
    cards.forEach(card => {
        card.addEventListener('click', function handler(event) {
            if (qahm.isCharaClickDisabled) {
                return;
            }

            qahm.isCharaClickDisabled = true;

            if (event.currentTarget.contains(event.target)) {
                let header = document.querySelector('.qa-zero-header__title');
                if ( header ) {
                    header.style.cursor = 'pointer';
                    header.addEventListener('click', function() {
                        location.reload();
                        exit;
                    });
                }

                let newAssistantSlug = card.dataset.assistantSlug;
                if (newAssistantSlug) {
                    qahm.createAssistant(newAssistantSlug, 'renderAssistantConversation');
                }
                document.getElementById('qahm-assistant-selector').classList.add('qa-zero-hide');
            }
        }, {once: false});

        card.addEventListener('mouseover', function() {
            card.classList.add('focused');
        });

        card.addEventListener('mouseout', function() {
            card.classList.remove('focused');
        });
    });
}

/**
 * アシスタント会話画面をレンダリングする
 *
 * 指定されたアシスタントの会話UIを生成し、キャラクター画像、会話ウィンドウ、
 * アシスタント切り替えセレクトボックスを表示する。既存の会話コンテナがある
 * 場合は削除してから新しいものを作成する。
 *
 * @param {string} [assistantSlug='official_robot'] - アシスタントのスラッグ
 * @returns {void}
 *
 * @example
 * qahm.renderAssistantConversation('site-analyst');
 */
qahm.renderAssistantConversation = function( assistantSlug = 'official_robot' ) {

    // IDを指定して既存のnewDev要素を取得
    let element = document.getElementById(qahm.nowAssistantContainerId);
    if (element) {
        element.parentNode.removeChild(element);
    }

    qahm.assistantTalkNo = 0;
    qahm.assistantProcessNo = 0;
    qahm.assistantTable = undefined;

    let newDiv = document.createElement("div");
    let mainCharacterImage = '';
    let mainCharacterName = '';
    let mainCharacterVersion = '';
    let mainCharacterTooltip = '';
    let assistantSelectBoxHtml = '<select id="qahm-assistant-change"><option selected>-- ' + qahml10n['switch_agent'] + ' --</option>';
    for (let assistant in qahm.allAssistants) {
        if (qahm.allAssistants.hasOwnProperty(assistant)) {
            if ( assistantSlug === assistant ) {
                mainCharacterImage = qahm.allAssistants[assistant].images.default;
                mainCharacterName = qahm.allAssistants[assistant].name;
                mainCharacterVersion = qahm.allAssistants[assistant].version;
                if (typeof qahm.allAssistants[assistant].description !== 'undefined') {
                    mainCharacterTooltip = 'class="assistant-tooltip" tabindex="0" title="' + qahm.allAssistants[assistant].description + '"';
                }
            }
        }
        assistantSelectBoxHtml += '<option value="' + qahm.allAssistants[assistant].slug + '">' + qahm.allAssistants[assistant].name + '</option>';
    }
    assistantSelectBoxHtml += '</select>';
    qahm.nowAssistantContainerId = `${assistantSlug}-container`;
    newDiv.innerHTML = `
<div id="${assistantSlug}-container">
<div class="qahm-assistant-container">
<div class="qa-zero-character-box">
<div class="qa-zero-main-character-box">
<div class="qa-zero-main-character-image"><img src="${mainCharacterImage}" alt="${mainCharacterName}" ${mainCharacterTooltip}></div>
</div>
</div>
<div class="qahm-assistant-talk-box">
<div class="qahm-assistant-talk-box-header">
    <div class="qahm-assistant-talk-box-title">
${mainCharacterName}
    </div>
    <div class="qahm-assistant-change-box">
${assistantSelectBoxHtml}
    </div>
</div>
<div id="qahm-assistant-talk-${qahm.assistantTalkNo}" class="qahm-assistant-dialogue-box">
</div>
</div>
</div>
</div>
`;

    // 直接新Divを対象要素に追加
    let container = document.getElementById('this_page_is_assistantpage');
    if (container) {
        container.appendChild(newDiv);
    }

    const selectBox = document.getElementById('qahm-assistant-change');
    selectBox.addEventListener('change', function(event) {
        let newAssistantSlug = event.target.value;
        if (newAssistantSlug) {
            qahm.createAssistant(newAssistantSlug, 'renderAssistantConversation');
        }
    }, {once: false});
}

qahm.getSelectorPath = function (element) {
    let path = [];
    while (element && element.parentNode) {
        let selector = element.nodeName.toLowerCase();
        if (element.id) {
            selector += '#' + element.id;
        } else if (element.className && typeof element.className === 'string') {
            selector += '.' + element.className.split(' ').join('.');
        }
        path.unshift(selector);
        element = element.parentNode;
    }
    return path.join(' > ');
}
