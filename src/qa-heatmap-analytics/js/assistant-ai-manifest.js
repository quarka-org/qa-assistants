/**
 * Assistant AI — Manifest Runtime Launcher
 *
 * Fetches manifest + translations + system vars from server,
 * creates Runtime/UI instances, and starts the 'start' scene.
 *
 * Design spec: docs/specs/assistant/overview.md (and related)
 * Depends on assistant-ai.js (qahm.* globals)
 */

/**
 * Launch manifest-based assistant runtime
 *
 * @param {string} slug - Assistant plugin slug
 */
qahm.launchManifestRuntime = function( slug ) {
    var url = new URL( window.location.href );
    var params = url.searchParams;
    var trackingId = params.get( 'tracking_id' ) || 'all';

    var containerId = 'qahm-assistant-talk-' + qahm.assistantTalkNo;
    var container = document.getElementById( containerId );

    if ( ! container ) {
        console.error( 'Manifest runtime: container not found:', containerId );
        return;
    }

    // Show typing indicator while fetching manifest (Issue #1226 / #1503).
    // #1503: 即時表示＋最低表示 MIN_LOADING_MS（＝bounce アニメ1周期 1.0s）＝波が必ず1周完走してから
    // 会話が始まる。fetch / config loading（qahm-assistant-ui.js showLoading/hideLoading）と同方式。
    var MIN_LOADING_MS = 1000;
    container.innerHTML =
        '<div class="qahm-conversation-message qahm-conversation-typing">' +
        '<span class="qahm-typing-dot"></span>' +
        '<span class="qahm-typing-dot"></span>' +
        '<span class="qahm-typing-dot"></span>' +
        '</div>';
    // [#1265] 会話開始時にページ最上部へ戻す（一覧で下側のカードをクリックしても会話が先頭から始まるように）。scrollIntoView/smooth は逆に追従スクロールを生むため使わない。
    window.scrollTo( 0, 0 );
    var loadingShownAt = Date.now();

    var afterMinDuration = function( fn ) {
        var elapsed = Date.now() - loadingShownAt;
        var remaining = MIN_LOADING_MS - elapsed;
        if ( remaining > 0 ) {
            setTimeout( fn, remaining );
        } else {
            fn();
        }
    };

    jQuery.ajax({
        type: 'POST',
        url: qahm.ajax_url,
        dataType: 'json',
        data: {
            'action': 'qahm_ajax_get_assistant_manifest',
            'nonce': qahm.nonce_api,
            'slug': slug,
            'tracking_id': trackingId
        }
    }).done( function( data ) {
        if ( data.success && data.data ) {
            afterMinDuration( function() {
                container.innerHTML = '';

                var ui, runtime;
                try {
                    var manifest = data.data.manifest;
                    var translations = data.data.translations;
                    var systemVars = data.data.system_vars;

                    ui = new qahm.AssistantUI( container );
                    runtime = new qahm.AssistantRuntime( manifest, translations, systemVars, ui );
                } catch ( err ) {
                    // Issue #1456 W-6(a): manifest 前処理の throw を受けないと、クリア済みの container が
                    // 空白のまま残る（空白画面）。AJAX 失敗分岐（#1434 の textContent 流儀）と同じく
                    // インラインで表示する（inert・HTML 注入なし）。
                    console.error( 'Assistant launch failed:', err );
                    container.textContent = qahm.AssistantRuntime.ERROR_MESSAGES.launch_failed;
                    return;
                }

                // Issue #1535: 「この会話を保存」ボタンは launcher（＝manifest 形式の入口）だけが
                // 取り付ける＝legacy Brains 4種の会話には出ない（メタ無しファイルを作らせない）。
                // export_meta（spec_version / site_label）は system_vars と別キー＝runtime の
                // $sys.* 解決に載らない（エクスポート専用・exporter へ直接渡す）。
                // exporter 未ロード環境では静かにスキップ（会話は通常どおり）。
                if ( qahm.AssistantExporter && typeof qahm.AssistantExporter.attach === 'function' ) {
                    qahm.AssistantExporter.attach( runtime, container, slug, manifest, data.data.export_meta || {} );
                }

                // runScene は async。.catch が無いと最初のシーンで例外が出ても unhandled rejection として
                // 握り潰され、会話が黙って止まる（Issue #1456 W-6(a)）。エラーバブルで可視化する。
                // #1534: 会話全体がこの 1 本の chain で await されるため、従来はどの時点の throw も
                // launch_failed（開始できなかった）と表示されていた。最初のステップを実行し終えたか
                // （runtime._conversationStarted）で「開始前の失敗」と「会話中の失敗」の文言を切り分ける。
                // 受け皿はこの catch 1 本のまま（#1456 W-6 の設計維持）。
                runtime.runScene( 'start' ).catch( function( err ) {
                    console.error( 'Assistant scene failed:', err );
                    var msgKey = runtime._conversationStarted ? 'step_failed' : 'launch_failed';
                    runtime.showErrorBubble( qahm.AssistantRuntime.ERROR_MESSAGES[ msgKey ] );
                } );
            } );
        } else {
            afterMinDuration( function() {
                // Surface the server-provided message (e.g. E_CORE_TOO_OLD "please update")
                // instead of a hardcoded string. textContent keeps it inert (no HTML injection).
                var msg = ( data && data.data && data.data.message ) ? data.data.message : 'Failed to load assistant manifest.';
                container.textContent = msg;
                console.error( 'Manifest load failed:', data );
            } );
        }
    }).fail( function( xhr, status, error ) {
        afterMinDuration( function() {
            // 409 (E_CORE_TOO_OLD) / 400 (E_SCHEMA_VALIDATION) land here; prefer the server
            // message so the user sees an actionable reason instead of a generic error.
            var msg = 'Failed to load assistant manifest.';
            if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
                msg = xhr.responseJSON.data.message;
            }
            container.textContent = msg;
            console.error( 'Manifest AJAX failed:', status, error );
        } );
    });
};
