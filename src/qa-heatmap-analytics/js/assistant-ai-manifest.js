/**
 * Assistant AI — Manifest Runtime Launcher
 *
 * Fetches manifest + translations + system vars from server,
 * creates Runtime/UI instances, and starts the 'start' scene.
 *
 * Design spec: docs/specs/assistant-manifest.md
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

    // Show loading while fetching manifest
    container.innerHTML = '<span class="el_loading">Loading<span></span></span>';

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
            container.innerHTML = '';

            var manifest = data.data.manifest;
            var translations = data.data.translations;
            var systemVars = data.data.system_vars;

            var ui = new qahm.AssistantUI( container );
            var runtime = new qahm.AssistantRuntime( manifest, translations, systemVars, ui );

            runtime.runScene( 'start' );
        } else {
            container.innerHTML = 'Failed to load assistant manifest.';
            console.error( 'Manifest load failed:', data );
        }
    }).fail( function( xhr, status, error ) {
        container.innerHTML = 'Failed to load assistant manifest.';
        console.error( 'Manifest AJAX failed:', status, error );
    });
};
