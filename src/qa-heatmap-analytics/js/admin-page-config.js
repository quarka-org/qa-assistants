    let goalmax = qahm.goalmax;
    let stepmax = 2;
    window.addEventListener( 'load', loadFinished );
    function loadFinished() {
        //document readyが完了するまではsaveさせない
        let td_gtype_save = document.getElementsByClassName('td_gtype_save');
        for ( let iii = 0; iii < td_gtype_save.length; iii++ ) {
            td_gtype_save[iii].style.opacity = "100";
        }
        let el_loadings = document.getElementsByClassName('el_loading');
        for ( let iii = 0; iii < el_loadings.length; iii++ ) {
            el_loadings[iii].style.display = "none";
        }
//mkdummy
        let tab_items = document.getElementsByClassName('qa-zero-tab__item');
        for ( let iii = 0; iii < tab_items.length; iii++ ) {
            tab_items[iii].addEventListener( 'click',  function() {
                let targetId = this.getAttribute('data-tab');
                if ( ! targetId ) return;

                // Hide all tab contents
                let allContents = document.getElementsByClassName('qahm-config__tab-content');
                for ( let jjj = 0; jjj < allContents.length; jjj++ ) {
                    allContents[jjj].classList.remove('qahm-config__tab-content--active');
                }

                // Deactivate all tab items
                for ( let jjj = 0; jjj < tab_items.length; jjj++ ) {
                    tab_items[jjj].classList.remove('qa-zero-tab__item--active');
                }

                // Show target content and activate tab
                let targetContent = document.getElementById( targetId );
                if ( targetContent ) {
                    targetContent.classList.add('qahm-config__tab-content--active');
                }
                this.classList.add('qa-zero-tab__item--active');

                // Save to hash (without scrolling)
                history.replaceState( null, '', '#' + targetId );
            } );
        }
        // Iframe width slider: update iframe width and apply scale
        document.querySelectorAll( '.qahm-config__iframe-width-slider' ).forEach( function( slider ) {
            slider.addEventListener( 'input', function() {
                var gid = this.getAttribute( 'data-gid' );
                var iframeWidth = parseInt( this.value, 10 );
                var iframe = document.getElementById( 'g' + gid + '_event-iframe' );
                var label = this.parentElement.querySelector( '.qahm-config__iframe-width-value' );

                if ( iframe ) {
                    iframe.setAttribute( 'width', iframeWidth );
                    qahm.applyIframeScale( gid );
                }
                if ( label ) {
                    label.textContent = iframeWidth + 'px';
                }
            } );
        } );

        // Recalculate iframe scale on window resize (e.g. sidebar collapse)
        var resizeRaf;
        window.addEventListener( 'resize', function() {
            if ( resizeRaf ) return;
            resizeRaf = requestAnimationFrame( function() {
                document.querySelectorAll( '.qahm-config__iframe-width-slider' ).forEach( function( slider ) {
                    qahm.applyIframeScale( slider.getAttribute( 'data-gid' ) );
                } );
                resizeRaf = null;
            } );
        } );

//mkdummy
        let g_clickpage = qahm.g_clickpage;

        for ( let gid = 1; gid <= goalmax; gid++ ) {
            // jQuery( `#g${gid}_event-iframe-containar` ).hide();
            let typeradios = document.getElementsByName( `g${gid}_type` );
            let iframeX = document.getElementById(`g${gid}_event-iframe`);
			// 開発環境等セキュリティの高いページをiframeで参照させた場合、g_clickpage[gid]内でエラーが発生する可能性がある。そのためにsrcを空白にする暫定措置を施した
			//iframeX.src = `${g_clickpage[gid]}`;
			iframeX.src = '';
            iframeX.addEventListener('load',function(){
                for ( let jjj = 0; jjj < typeradios.length; jjj++ ) {
                    typeradios[jjj].addEventListener( 'click', showGoalTextboxes );
                    if (typeradios[jjj].value === 'gtype_click' && typeradios[jjj].checked ) {
                        qahm.showIframeSelector(`g${gid}_event-iframe-containar`);
                    }
                }
                let valobj = document.getElementById(`g${gid}_val`);
                if (valobj) {
	                calcSales(valobj);
			    }
            } );
        }
//mkdummy
        let hashtabid = window.location.hash;
        if ( hashtabid ) {
            hashtabid = hashtabid.replace('#', '');
            // Find the tab item with matching data-tab and click it
            for ( let iii = 0; iii < tab_items.length; iii++ ) {
                if ( tab_items[iii].getAttribute('data-tab') === hashtabid ) {
                    tab_items[iii].click();
                    break;
                }
            }
        }
//mkdummy

//QA ZERO start
        // let sm_table_all_cbx = document.getElementById("sm_table_all_cbx");
        // sm_table_all_cbx.addEventListener("change",function(){

        //     let sm_table_cbxs = document.getElementsByClassName("sm_table_cbx");
        //     for(let i=0;i<sm_table_cbxs.length;i++){
        //         sm_table_cbxs[i].checked = this.checked;
        //     }

        // }
        // );
//QA ZERO end

    }
    function calcSales( obj ) {
        let idname   = obj.id;
        let idsplit  = idname.split('_');
        let gnum     = idsplit[0].slice(1);

        let num = document.getElementById(`g${gnum}_num`).value;
        let val = document.getElementById(`g${gnum}_val`).value;

        if ( num === '' ) {
            num = 0
        } else {
            num = Number(num);
        }
        if ( val === '' ) {
            val = 0
        } else {
            val = Number(val);
        }

        let sales   = num * val;
        let calspan = document.getElementById(`g${gnum}_calcsales`);
        calspan.innerText = sales.toLocaleString();
        calspan.classList.add('highlight');

        // 500ミリ秒後にすぐ外す
        setTimeout(function() {
            calspan.classList.remove('highlight');
        }, 500);

    }
    function showGoalTextboxes(e) {
        let radioobj = e.target;
        let idname   = radioobj.id;
        let idsplit  = idname.split('_');
        let gnum     = idsplit[0].slice(1);

        switch (idsplit[2]) {
            case 'click':
                document.getElementById(`g${gnum}_page_goal`).style.display  = 'none';
                document.getElementById(`g${gnum}_click_goal`).style.display = 'block';
                document.getElementById(`g${gnum}_event_goal`).style.display = 'none';
                qahm.showIframeSelector(`g${gnum}_event-iframe-containar`);
                //required
                document.getElementById(`g${gnum}_goalpage`).required = false;
                document.getElementById(`g${gnum}_clickpage`).required = true;
                document.getElementById(`g${gnum}_clickselector`).required = true;
                document.getElementById(`g${gnum}_eventselector`).required = false;
                break;

            case 'event':
                document.getElementById(`g${gnum}_page_goal`).style.display  = 'none';
                document.getElementById(`g${gnum}_click_goal`).style.display = 'none';
                document.getElementById(`g${gnum}_event_goal`).style.display = 'block';
                jQuery( `#g${gnum}_event-iframe-containar` ).hide();
                //required
                document.getElementById(`g${gnum}_goalpage`).required = false;
                document.getElementById(`g${gnum}_clickpage`).required = false;
                document.getElementById(`g${gnum}_clickselector`).required = false;
                document.getElementById(`g${gnum}_eventselector`).required = true;
                break;

            default:
            case 'page':
                document.getElementById(`g${gnum}_page_goal`).style.display  = 'block';
                document.getElementById(`g${gnum}_click_goal`).style.display = 'none';
                document.getElementById(`g${gnum}_event_goal`).style.display = 'none';
                jQuery( `#g${gnum}_event-iframe-containar` ).hide();
                //required
                document.getElementById(`g${gnum}_goalpage`).required = true;
                document.getElementById(`g${gnum}_clickpage`).required = false;
                document.getElementById(`g${gnum}_clickselector`).required = false;
                document.getElementById(`g${gnum}_eventselector`).required = false;
                break;

        }
    }

    function siteinfoChanges(formobj) {
        // let submitobj = e.target;
        let siteinfo_form     = formobj;
        let idname    = formobj.id;
        let idsplit   = idname.split('_');
        let gnum      = idsplit[0].slice(1);
        let submitobj = document.getElementById(`g${gnum}_submit`);

        let target_customer      = siteinfo_form[`target_customer`].value;
        let sitetype  = siteinfo_form[`sitetype`].value;
        let membership  = siteinfo_form[`membership`].value;
        let payment       = siteinfo_form[`payment`].value;
        let month_later  = siteinfo_form[`month_later`].value;
        let session_goal = siteinfo_form[`session_goal`].value;
        let url = new URL(window.location.href);
        let params = url.searchParams;
        let tracking_id = params.get('tracking_id');
        
        jQuery.ajax(
            {
                type: 'POST',
                url: qahm.ajax_url,
                dataType : 'json',
                data: {
                    'action'  : 'qahm_ajax_save_siteinfo',
                    'target_customer':         target_customer,
                    'sitetype':      sitetype,
                    'membership':  membership,
                    'payment':  payment,
                    'month_later':  month_later,
                    'session_goal': session_goal,
                    'nonce':qahm.nonce_api,
                    'tracking_id':tracking_id
                }
            }
        ).done(
            function( data ){
				location.reload();
            }
        ).fail(
            function( jqXHR, textStatus, errorThrown ){
                qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
                alert( qahml10n['cnv_couldnt_saved'] );
            }
        ).always(
            function(){
            }
        );
    }

    function deleteGoalX( gid ) {
        let result = window.confirm( qahml10n['cnv_delete_confirm'] );
        let url = new URL(window.location.href);
        let params = url.searchParams;
        let tracking_id = params.get('tracking_id');
        if (result) {
            let start_time = new Date().getTime();
            jQuery.ajax(
                {
                    type: 'POST',
                    url: qahm.ajax_url,
                    dataType : 'json',
                    data: {
                        'action'  : 'qahm_ajax_delete_goal_x',
                        'gid':      gid,
                        'nonce':qahm.nonce_api,
                        'tracking_id':tracking_id
                    }
                }
            ).done(
                function( data ){
                    function deleteGoalXDone() {
                        AlertMessage.alert(
                            qahml10n['alert_message_success'],
                            qahm.sprintf( qahml10n['cnv_deleted'], gid ) + '<br>' + qahml10n['cnv_deleted2'],
                            'success',
                            function(){
                                qahm.hideLoadIcon();
                                location.reload();
                            }
                        );                        
                    }
                    // 最低読み込み時間経過後に処理実行
                    let now_time  = new Date().getTime();
                    let load_time = now_time - start_time;
                    let min_time  = 600;

                    if ( load_time < min_time ) {
                        // ロードアイコンを削除して新しいウインドウを開く
                        setTimeout( deleteGoalXDone, (min_time - load_time) );
                    } else {
                        deleteGoalXDone();
                    }
                    //location.reload();
                }
            ).fail(
                function( jqXHR, textStatus, errorThrown ){
                    qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
                    alert( qahml10n['cnv_couldnt_delete'] );
                }
            ).always(
                function(){
                }
            );
        } else {
            return false;
        }
    }


    function saveChanges(formobj) {
        // let submitobj = e.target;
        let gform     = formobj;
        let idname    = formobj.id;
        let idsplit   = idname.split('_');
        let gnum      = idsplit[0].slice(1);
        let submitobj = document.getElementById(`g${gnum}_submit`);

        let gtitle      = gform[`g${gnum}_title`].value;
        let gnum_scale  = gform[`g${gnum}_num`].value;
        let gnum_value  = gform[`g${gnum}_val`].value;
        let gtype       = gform[`g${gnum}_type`].value;
        let g_goalpage  = gform[`g${gnum}_goalpage`].value;
        let g_pagematch = gform[`g${gnum}_pagematch`].value;
        let g_clickpage = gform[`g${gnum}_clickpage`].value;
        let g_eventtype = gform[`g${gnum}_eventtype`].value;
        let g_clickselector = gform[`g${gnum}_clickselector`].value;
        let g_eventselector = gform[`g${gnum}_eventselector`].value;
		let url = new URL(window.location.href);
		let params = url.searchParams;
		let tracking_id = params.get('tracking_id');
        
        //required check
        if ( gtitle === '' ) {
            return
        }

        let uri         = new URL(window.location.href);
        let httpdomaina = uri.origin;
        let httpdomainb = httpdomaina + '/';
        if ( g_pagematch === 'pagematch_prefix' && gtype === 'gtype_page') {
            if ( g_goalpage === httpdomaina || g_goalpage === httpdomainb ) {
                alert( qahml10n['cnv_page_set_alert'] );
                return false;
            }
        }

		//IDが飛んでいないかチェック
		for ( let gid = 1; gid < gnum; gid++ ) {
	        let gidtitle = document.getElementById(`g${gid}_title`).value;
	        if ( gidtitle === '' ) {
                alert( qahml10n['cnv_goal_numbering_alert'] );
                return false;
			}
		}

        let backupvalue = submitobj.value;
        submitobj.disabled = true;
        submitobj.value = qahml10n['cnv_saving'];

        jQuery.ajax(
            {
                type: 'POST',
                url: qahm.ajax_url,
                dataType : 'json',
                data: {
                    'action'  : 'qahm_ajax_save_goal_x',
                    'gid':         gnum,
                    'gtitle':      encodeURI(gtitle),
                    'gnum_scale':  gnum_scale,
                    'gnum_value':  gnum_value,
                    'gtype':       encodeURI(gtype),
                    'g_goalpage':  g_goalpage,
                    'g_pagematch': g_pagematch,
                    'g_clickpage': g_clickpage,
                    'g_eventtype': g_eventtype,
                    'g_clickselector': g_clickselector,
                    'g_eventselector': g_eventselector,
                    'nonce':qahm.nonce_api,
                    'tracking_id': tracking_id
                }
            }
        ).done(
            function( data ){
                let saveStatus = data['status'];
                if ( saveStatus !== 'error' ) { 
                    let msg = '';          
                    switch ( saveStatus ) {
                        case 'in_progress':                            
                            if ( data['estimated_sec'] != null ) {
                                let estimateSec = data['estimated_sec'] + 20; // 20秒多めに見積もっておく
                                let minutes = Math.floor(estimateSec / 60);
                                let remainingSeconds = estimateSec % 60;
                                let estimatedTime = '';
                                if ( minutes > 0 ) {
                                    estimatedTime += minutes + qahml10n['x_minutes'];
                                }
                                if ( remainingSeconds > 0 ) {
                                    estimatedTime += remainingSeconds + qahml10n['x_seconds'];
                                }
                                msg = qahml10n['cnv_in_progress'] + '<br>' + qahml10n['cnv_estimated_time'] + estimatedTime;
                            } else {
                                msg = qahml10n['cnv_in_progress'] + '<br>' + qahml10n['cnv_estimated_time2'];
                            }
                            
                            AlertMessage.alert(
                                qahml10n['alert_message_success'],
                                qahm.sprintf( qahml10n['cnv_saved_1'], gnum ) + '<br>' + msg,
                                'success',
                                function(){}
                            );
                            break;

                        case 'done':
                            let goalCompFlg = data['goal_comp_flg'];
                            msg = qahm.sprintf( qahml10n['cnv_saved_1'], gnum );
                            if ( goalCompFlg ) {
                                msg += '<br>' + qahml10n['cnv_reaching_goal_notice'];
                            }
                            AlertMessage.alert(
                                qahml10n['alert_message_success'],
                                msg,
                                'success',
                                function(){}
                            );
                            break;

                        case 'no_pvterm':
                            AlertMessage.alert(
                                qahml10n['alert_message_success'],
                                qahml10n['no_pvterm'],
                                'success',
                                function(){}
                            );                           
                            break;
                    }
                    setTimeout(function(){submitobj.value = backupvalue; submitobj.disabled = true;}, 1000);

                } else {
                    let errorReason = data['reason'];
                    let msg = '';
                    switch ( errorReason ) {
                        case 'no_page_id':
                            msg = qahml10n['cnv_save_failed'] + '<br>' + qahml10n['nothing_page_id'] + '<br>' + qahml10n['nothing_page_id2'];
                            break;
                        case 'wrong_delimiter':
                            msg = qahml10n['wrong_regex_delimiter'];
                            break;

                    }
                    AlertMessage.alert(
                        qahml10n['alert_message_failed'],
                        msg,
                        'error',
                        function(){}
                    );
                    setTimeout(function(){submitobj.value = backupvalue; submitobj.disabled =false;}, 2000);
                }                
            }
        ).fail(
            function( jqXHR, textStatus, errorThrown ){
                qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
                alert( qahml10n['cnv_couldnt_saved'] );
                submitobj.value = backupvalue;
                submitobj.disabled = false;
            }
        ).always(
            function(){
            }
        );
    }

    function detailopen( stepid ) {
        for (let iii = 1; iii < stepmax + 1; iii++) {
            let detailid  = 'step' + iii.toString();
            let detaildiv = document.getElementById( detailid );
            if ( iii === stepid ) {
                detaildiv.open = true;
            } else {
                detaildiv.open = false;
            }
        }
    }




	/**
	 * 目標設定
     * クリック用のiframeを表示
	 */
	/**
	 * Apply scale transform to iframe based on slider value
	 */
	qahm.applyIframeScale = function( gid ) {
		var container = document.getElementById( 'g' + gid + '_event-iframe-containar' );
		if ( ! container ) return;
		var iframe = document.getElementById( 'g' + gid + '_event-iframe' );
		var wrapper = container.querySelector( '.qahm-config__iframe-scale-wrapper' );
		if ( ! iframe || ! wrapper ) return;

		var iframeWidth = parseInt( iframe.getAttribute( 'width' ), 10 ) || 1200;
		var iframeHeight = parseInt( iframe.getAttribute( 'height' ), 10 ) || 400;
		var containerWidth = container.offsetWidth;
		if ( containerWidth <= 0 ) return;

		var scale = ( iframeWidth > containerWidth ) ? containerWidth / iframeWidth : 1;
		wrapper.style.transform = 'scale(' + scale + ')';
		wrapper.style.width = iframeWidth + 'px';
		wrapper.style.height = iframeHeight + 'px';
		container.style.height = Math.round( iframeHeight * scale ) + 'px';
	};

	qahm.showIframeSelector = function( idname ){
        let idsplit  = idname.split('_');
        let gid      = idsplit[0].slice(1);
        jQuery(`#g${gid}_event-iframe-containar`).css('display', 'none');
		jQuery( `#g${gid}_click_pageload` ).prop( 'disabled', false ).text( qahml10n['cnv_load_page'] );
		jQuery( `#g${gid}_click_pageload` ).on( 'click', function(){
            qahm.loadIframePage( gid );
			jQuery( `#g${gid}_clickselector` ).val( '' );
			jQuery( `#g${gid}_event-iframe` ).on( 'load', function(){
				qahm.showIframeSelector( jQuery(this).attr('id') );
			});
		});
	};


    /**
     * 目標設定
     *  
     * Load Page が押されたら、iframe内にページ表示  
     */    
    qahm.loadIframePage = function( gid ) {
        
        jQuery( `#g${gid}_click_pageload` ).prop( 'disabled', false ).text( qahml10n['cnv_load_page'] );

        let url = jQuery( `#g${gid}_clickpage` ).val();
        let deviceId = 1;
        jQuery( `#g${gid}_click_pageload` ).prop( 'disabled', true ).text( qahml10n['cnv_loading'] );
        jQuery( `#g${gid}_clickselector` ).val( '' );
        
        //ZERO (1)base_html取得、(2)iframe表示 (3)iframe内のクリックされたセレクタ取得
        jQuery.ajax(
            {
                type: 'POST',
                url: qahm.ajax_url,
                dataType : 'json',
                data: {
                    'action' : 'qahm_ajax_get_base_html_by_url',
                    'nonce':qahm.nonce_api,
                    'pageurl': url,
                    'device_id': deviceId,
                    'add_basehref': '1',
                }
            }
        ).done(
            function( data ) {
                if (data) {
                    let baseHtml = data;
                    var iframe = jQuery(`#g${gid}_event-iframe`);                    
                    var iframeDocument = iframe.contents();                
                    try {
                        // iframe内のHTMLを設定する
                        //iframeDocument.find("html").html(baseHtml); //これだとうまくいかない
                        iframeDocument[0].documentElement.innerHTML = baseHtml;

                        // Resolve lazy-loaded images: data-src → src
                        iframeDocument.find( 'img[data-src]' ).each( function() {
                            var $img = jQuery( this );
                            $img.attr( 'src', $img.attr( 'data-src' ) );
                            $img.removeAttr( 'data-src' );
                        } );
                        // Also handle srcset lazy loading
                        iframeDocument.find( 'img[data-srcset]' ).each( function() {
                            var $img = jQuery( this );
                            $img.attr( 'srcset', $img.attr( 'data-srcset' ) );
                            $img.removeAttr( 'data-srcset' );
                        } );

                        jQuery(`#g${gid}_event-iframe-containar`).css('display', 'block');
                        qahm.applyIframeScale( gid );

                        // ここに、iframe内で実行するJavaScriptコードなどを追加
                        let frameContent = jQuery( 'body', jQuery( `#g${gid}_event-iframe` ).contents() );
                        frameContent.on( 'click', function(e){
                            // セレクタ設定
                            const names   = qahm.getSelectorFromElement( e.target );
                            const selName = names.join( '>' );
                            
                            // エスケープ処理を適用
                            const escapedSelName = qahm.escapeSelectorString(selName);
                            jQuery( `#g${gid}_clickselector` ).val( escapedSelName );
                            jQuery( `#g${gid}_clickselector` ).prop("readonly", true);

                            // 吹き出し表示
                            jQuery( `#g${gid}_event-iframe-tooltip-right` ).fadeIn( 300 ).css( 'display', 'inline' );
                            setTimeout( function(){ jQuery( `#g${gid}_event-iframe-tooltip-right` ).fadeOut( 300 ); }, 1500 );
                            return false;
                        });

                    } catch (error) {
                        console.error('An error occurred while setting HTML in iframe:', error.message);
                        // エラーをコンソールにログ出力するだけで、何も追加しない
                    }
                } else {
                    alert(url + '\n' + qahml10n['failed_iframe_load']);
                }
            }
            
        ).fail(
            function( jqXHR, textStatus, errorThrown ){
                qahm.log_ajax_error( jqXHR, textStatus, errorThrown );
                alert( '[' + textStatus + ']' + qahml10n['please_try_again'] );
            }
        ).always(
            function(){
                jQuery( `#g${gid}_click_pageload` ).prop( 'disabled', false ).text( qahml10n['cnv_load_page'] );
            }
        );
    }
    

	/**
	 * オブジェクトがELEMENT_NODEか判定
	 */
	qahm.isElementNode = function( obj ) {
		return obj && obj.nodeType && obj.nodeType === 1;
	}

	/**
	 * 同じ階層に同名要素が複数ある場合は識別のためインデックスを付与する
	 * 複数要素の先頭 ( index = 1 ) の場合、インデックスは省略可能
	 */
	qahm.getSiblingElemetsIndex = function( el, name ) {
		var index = 1;
		var sib   = el;

		while ( ( sib = sib.previousElementSibling ) ) {
			if ( sib.nodeName.toLowerCase() === name ) {
				++index;
			}
		}

		return index;
	};

	/**
	 * エレメントからセレクタを取得
	 * @returns {string} セレクタ名
	 */
	qahm.getSelectorFromElement = function( el ) {
		var names = [];
		if ( ! qahm.isElementNode( el ) ) {
			return names;
		}

		while ( el.nodeType === Node.ELEMENT_NODE ) {
			var name = el.nodeName.toLowerCase();
			if ( el.id ) {
				// id はページ内で一意となるため、これ以上の検索は不要
				// ↑ かと思ったがクリックマップを正しく構成するためには必要
				name += '#' + el.id;
				//names.unshift( name );
				//break;
			}

			var index = qahm.getSiblingElemetsIndex( el, name );
			if ( 1 < index ) {
				name += ':nth-of-type(' + index + ')';
			}

			names.unshift( name );
			el = el.parentNode;
		}

		return names;
	};

	/**
	 * セレクタの文字をエスケープ
	 * @param {string} str セレクタ文字列
	 * @returns {string} エスケープされたセレクタ文字列
	 */
	qahm.escapeSelectorString = function( str ){
		let strSplitAry = str.split('>');
		let find = false;
		for ( let strIdx = 0; strIdx < strSplitAry.length; strIdx++ ) {
			let deliStr = '.';
			let deliIdx = strSplitAry[strIdx].indexOf( deliStr );
			if ( deliIdx === -1 ) {
				deliStr = '#';
				deliIdx = strSplitAry[strIdx].indexOf( deliStr );
			}
			if ( deliIdx !== -1 ) {
				let fwdName  = strSplitAry[strIdx].substr( 0, deliIdx );
				let BackName = strSplitAry[strIdx].substr( deliIdx + 1 );
				strSplitAry[strIdx] = fwdName + deliStr + CSS.escape( BackName );
				find = true;
			}
		}

		if ( find ) {
			return strSplitAry.join('>');
		} else {
			return str;
		}
	};


    // プラグイン設定の保存処理用のコード
	jQuery(function() {
		jQuery(document).on('click', '#plugin-submit', function() {
			qahm.showLoadIcon();
			let advancedMode      = jQuery('#advanced_mode').is(':checked');
			let cbSupMode         = jQuery('#cb_sup_mode').is(':checked');
			
			jQuery.ajax({
				type: 'POST',
				url: qahm.ajax_url,
				dataType: 'json',
				data: {
					'action': 'qahm_ajax_save_plugin_config',
					'security': qahml10n['nonce_qahm_options'],
					'advanced_mode': advancedMode,
					'cb_sup_mode': cbSupMode,
				}
			}).done(function(data) {
				AlertMessage.alert(
					qahml10n['alert_message_success'],
					qahml10n['setting_option_saved'],
					'success',
					function() {
						location.reload();
					}
				);
			}).fail(function(jqXHR, textStatus, errorThrown) {
				qahm.log_ajax_error(jqXHR, textStatus, errorThrown);
				AlertMessage.alert(
					qahml10n['alert_message_failed'],
					qahml10n['setting_option_failed'], 
					'error',
					function() {}
				);
			}).always(function() {
				qahm.hideLoadIcon();
			});
		});
	});

	// 計測設定の保存処理（QA ZERO）
	jQuery(function() {
		// HTML定期取得 OFF 時に差分検知モードを disabled にする
		var $periodicCheckbox = jQuery('#measurement_get_base_html_periodic');
		var $detectionMode    = jQuery('#measurement_html_diff_detection_mode');
		$detectionMode.prop('disabled', !$periodicCheckbox.is(':checked'));
		$periodicCheckbox.on('change', function() {
			$detectionMode.prop('disabled', !this.checked);
		});

		jQuery(document).on('click', '#measurement-submit', function() {
			qahm.showLoadIcon();

			let ignoreParams          = jQuery('#measurement_ignore_params').val().split(/\n/).map(s => s.trim()).filter(s => s).join(',');
			let searchParams          = jQuery('#measurement_search_params').val().split(/\n/).map(s => s.trim()).filter(s => s).join(',');
			let ignoreIps             = jQuery('#measurement_ignore_ips').val();
			let urlCase               = jQuery('#measurement_url_case').is(':checked') ? 1 : 0;
			let siteId                = jQuery('#measurement_site_id').val();
			let getBaseHtmlPeriodic   = $periodicCheckbox.is(':checked') ? 1 : 0;
			let htmlDiffDetectionMode = $detectionMode.val();

			jQuery.ajax({
				type: 'POST',
				url: qahm.ajax_url,
				dataType: 'json',
				data: {
					'action': 'qahm_ajax_save_measurement_config',
					'security': qahml10n['nonce_qahm_options'],
					'site_id': siteId,
					'ignore_params': ignoreParams,
					'search_params': searchParams,
					'ignore_ips': ignoreIps,
					'url_case': urlCase,
					'get_base_html_periodic': getBaseHtmlPeriodic,
					'html_diff_detection_mode': htmlDiffDetectionMode,
				}
			}).done(function(data) {
				if ( data.success ) {
					AlertMessage.alert(
						qahml10n['alert_message_success'],
						qahml10n['measurement_saved'],
						'success',
						function() {
							location.reload();
						}
					);
				} else {
					let msg = qahml10n['measurement_save_failed'];
					if ( data.data === 'invalid_ip' ) {
						msg = qahml10n['measurement_invalid_ip'];
					}
					AlertMessage.alert(
						qahml10n['alert_message_failed'],
						msg,
						'error',
						function() {}
					);
				}
			}).fail(function(jqXHR, textStatus, errorThrown) {
				qahm.log_ajax_error(jqXHR, textStatus, errorThrown);
				AlertMessage.alert(
					qahml10n['alert_message_failed'],
					qahml10n['measurement_save_failed'],
					'error',
					function() {}
				);
			}).always(function() {
				qahm.hideLoadIcon();
			});
		});
	});
//QA ZERO end
