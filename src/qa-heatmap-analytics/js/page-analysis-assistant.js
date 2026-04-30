/**
 * QAHM Page Analysis Assistant
 * 
 * Handles page analysis assistant UI interactions.
 * 
 * @since 1.0.0
 */

(function($) {
	'use strict';
	
	var qahm = window.qahm || {};
	window.qahm = qahm;
	
	if (typeof qahmPageAnalysisAssistantData === 'undefined') {
		console.error('qahmPageAnalysisAssistantData is not defined');
		return;
	}
	
	qahm.pageAnalysisAssistant = {
		ajaxUrl: qahmPageAnalysisAssistantData.ajaxUrl,
		nonce: qahmPageAnalysisAssistantData.nonce,
		isConversationStarted: false,  // 会話開始フラグ
		
		/**
		 * 初期化
		 */
		init: function() {
			this.button = $('#qahm-page-analysis-assistant-button');
			this.window = $('#qahm-page-analysis-assistant-window');
			this.closeBtn = $('.qahm-chatbot-close');
		
			this.button.prop('disabled', false);
			this.button.attr('title', 'このページのデータを確認');
		
			this.button.on('click', this.toggleWindow.bind(this));
			this.closeBtn.on('click', this.closeWindow.bind(this));
		},
		
		/**
		 * 会話ウィンドウの開閉
		 */
		toggleWindow: function(e) {
			e.preventDefault();
			
			if (this.window.hasClass('hidden')) {
				this.openWindow();
			} else {
				this.closeWindow();
			}
		},
		
		/**
		 * 会話ウィンドウを開く
		 */
		openWindow: function() {
			this.window.removeClass('hidden');
			this.button.addClass('active');
		
			$('#qahm-chatbot-dialogue').empty();
		
			this.isConversationStarted = false;
			this.startConversation();
		},
		
		/**
		 * 会話ウィンドウを閉じる
		 */
		closeWindow: function() {
			this.window.addClass('hidden');
			this.button.removeClass('active');
			this.isConversationStarted = false;
		},
		
		/**
		 * 会話を開始
		 */
		startConversation: function() {
			var self = this;
			var dialogueId = 'qahm-chatbot-dialogue';
		
			if (this.isConversationStarted) {
				return;
			}
		
			if (!qahm.conversationUI || !qahm.conversationUI.renderExecute) {
				console.error('qahm.conversationUI is not available');
				$('#' + dialogueId).html('<p>会話UIの読み込みに失敗しました。ページを再読み込みしてください。</p>');
				return;
			}
		
			this.isConversationStarted = true;
	
			$.ajax({
				url: this.ajaxUrl,
				type: 'POST',
				data: {
					action: 'qahm_page_analysis_assistant',
					action_type: 'init',
					nonce: this.nonce,
					current_url: window.location.href
				},
				success: function(response) {
					if (response.success && response.data.execute) {
						qahm.conversationUI.renderExecute(
							dialogueId,
							response.data.execute,
							{
								onCommandClick: function(action) {
									self.handleCommand(action);
								}
							}
						);
					} else {
						$('#' + dialogueId).html('<p>エラーが発生しました。</p>');
						self.isConversationStarted = false;
					}
				},
				error: function(xhr, status, error) {
					console.error('AJAX error:', {
						status: status,
						error: error,
						response: xhr.responseText,
						statusCode: xhr.status
					});
				
					var errorMessage = '<p class="error">通信エラーが発生しました。</p>';
				
					if (xhr.status === 0) {
						errorMessage += '<p>ネットワーク接続を確認してください。</p>';
					} else if (xhr.status === 403) {
						errorMessage += '<p>アクセス権限がありません。</p>';
					} else if (xhr.status >= 500) {
						errorMessage += '<p>サーバーエラーが発生しました。しばらく待ってから再試行してください。</p>';
					} else {
						errorMessage += '<p>ページを再読み込みしてください。</p>';
					}
				
					errorMessage += '<button class="qahm-conversation-command-button" onclick="location.reload()">ページを再読み込み</button>';
				
					$('#' + dialogueId).html(errorMessage);
					self.isConversationStarted = false;
				}
			});
		},
		
		/**
		 * コマンドボタンのクリック処理
		 */
		handleCommand: async function(action) {
			var self = this;
			var dialogueId = 'qahm-chatbot-dialogue';

			if (action.next) {
				if (action.userMessage) {
					var dialogueBox = document.getElementById(dialogueId);
					
					await qahm.conversationUI._displayText(
						action.userMessage,
						dialogueBox,
						true,  // isUser = true (ユーザーメッセージとして表示)
						{
							enableTypewriter: false,  // 瞬時表示
							onMessageRendered: function(element, type) {
							}
						}
					);
					
					await new Promise(resolve => setTimeout(resolve, 300));
				}
			
				$('.qahm-conversation-command-button').prop('disabled', true);
		
				$.ajax({
					url: this.ajaxUrl,
					type: 'POST',
					data: {
						action: 'qahm_page_analysis_assistant',
						action_type: action.next,
						free: JSON.stringify(action.free || {}),
						nonce: this.nonce,
						current_url: window.location.href
					},
					success: function(response) {
						if (response.success && response.data.execute) {
							qahm.conversationUI.renderExecute(
								dialogueId,
								response.data.execute,
								{
									onCommandClick: function(action) {
										self.handleCommand(action);
									}
								}
							);
						} else {
							$('#' + dialogueId).append('<p class="error">エラーが発生しました。</p>');
						}
					},
					error: function(xhr, status, error) {
						console.error('AJAX error:', {
							status: status,
							error: error,
							response: xhr.responseText,
							statusCode: xhr.status
						});
					
						var errorMessage = '<p class="error">通信エラーが発生しました。</p>';
					
						if (xhr.status === 0) {
							errorMessage += '<p>ネットワーク接続を確認してください。</p>';
						} else if (xhr.status >= 500) {
							errorMessage += '<p>サーバーエラーが発生しました。</p>';
						}
					
						$('#' + dialogueId).append(errorMessage);
					},
					complete: function() {
						$('.qahm-conversation-command-button').prop('disabled', false);
					}
				});
			}
		}
	};
	
	$(document).ready(function() {
		qahm.pageAnalysisAssistant.init();
	});
	
})(jQuery);
