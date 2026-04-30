<?php
if ( ! defined( 'ABSPATH' ) ) {
	// This file is loaded before WordPress bootstrap via SHORTINIT. Do not exit.
}
define( 'QAHM_CONFIG_USE_LSCMD_LISTFILE', false );
define( 'QAHM_CONFIG_TWO_SYSTEM_MODE', false );
define( 'QAHM_CONFIG_SYSTEM_MODE', 1 );
define( 'QAHM_CONFIG_CPROC_NUM_MAX', 2 );
define( 'QAHM_CONFIG_RCNK_MAX', 50000 );
define( 'QAHM_CONFIG_SOCIAL_REFERRER', 'Youtube:www.youtube.com,youtu.be;X:x.com,www.twitter.com,t.co;Facebook:www.facebook.com,fb.me;Instagram:www.instagram.com,instagr.am;TikTok:www.tiktok.com,vm.tiktok.com;Pinterest:www.pinterest.com,jp.pinterest.com;' );
define( 'QAHM_CONFIG_BEHAVIORAL_SEND_INTERVAL', 3000 );
define( 'QAHM_CONFIG_LIMIT_PV_MONTH', 10000 );
define( 'QAHM_CONFIG_DATA_RETENTION_DAYS', 120 );
// Google Search Console API 取得件数上限
define( 'QAHM_CONFIG_GSC_ROW_LIMIT_PER_REQUEST_KEYWORD', 5000 );
define( 'QAHM_CONFIG_GSC_ROW_LIMIT_PER_REQUEST_PAGE', 1000 );
define( 'QAHM_CONFIG_GSC_ROW_LIMIT_PER_REQUEST_QUERY', 5000 );
