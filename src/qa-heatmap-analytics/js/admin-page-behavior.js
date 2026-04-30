// デバイスリンククリック
jQuery( document ).off( 'click', '.qa-table-heatmap-container span' );
jQuery( document ).on(
	'click',
	'.qa-table-heatmap-container span',
	function(){
		let url           = new URL(window.location.href);
		let params        = url.searchParams;
		let trackingId    = params.get('tracking_id');
		let startMoment   = moment(reportRangeStart);
		let endMoment     = moment(reportRangeEnd);
		let startDate     = startMoment.format('YYYY-MM-DD HH:mm:ss');
		let endDate       = endMoment.format('YYYY-MM-DD HH:mm:ss');
		let pageId        = jQuery( this ).data( 'page_id' );
		let deviceName    = jQuery( this ).data( 'device_name' );
		let isLandingPage = jQuery( this ).data( 'is_landing_page' );
		let media         = jQuery( this ).data( 'media' );

		qahm.createCap( startDate, endDate, pageId, deviceName, isLandingPage, media, trackingId );
	}
);