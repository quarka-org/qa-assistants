<?php

class QAHM_Assistant_Social_Media extends QAHM_Assistant {
	// session keep variables
    public $session_check_days = 7;
	public $session_top_media = '';
	public $session_data = "";


	protected function handle_state_start() {
		return 'm0';
	}

	protected function handle_state_m0() {
		$this->show_message(0);
		return 'cmd0';
	}

	protected function handle_state_cmd0() {
		$this->show_command('cmd0');
		return null;
	}

	protected function handle_state_m10() {
		$this->show_message(10);
		$this->defer_state('data0');
		return null;
	}

	protected function handle_state_data0() {
		$this->session_check_days = $this->session_free;
		$table_ary = $this->get_social_media_traffic_table($this->session_free);
		if ( $table_ary ) {
			$this->show_data($table_ary);
			$this->add_dynamic_commands($table_ary);
			return 'm21';
		} else {
			return 'm20';
		}
	}

	protected function handle_state_m20() {
		$this->show_message(20);
		return 'cmd0';
	}

	protected function handle_state_m21() {
		$this->show_message(21);
		return 'm30';
	}

	protected function handle_state_m30() {
		$this->show_message(30);
		return 'cmd1';
	}

	protected function handle_state_cmd1() {
		$this->show_command('cmd1');
		return null;
	}

	protected function handle_state_m40() {
		$this->show_message(40);
		$this->defer_state('data1');
		return null;
	}

	protected function handle_state_data1() {
		$text_exit = 'Exit';
		if ( ( ! $this->exists_command( 'cmd1', $text_exit ) ) && ( ! $this->exists_command( 'cmd1', '終了する' ) ) ) {
			$action = new stdClass();
			$action->next = "m100";
			$command_ary = ["text" => $text_exit, "action" => $action];
			$this->add_command('cmd1', $command_ary);
		}

		$table_ary = $this->get_landing_page_from_social_media_traffic_table($this->session_check_days,$this->session_free);
		if ( $table_ary ) {
			$this->show_data($table_ary);
		}
		return 'm30';
	}

	protected function handle_state_m100() {
		$this->show_message(100);
		return 'end';
	}

	protected function handle_state_end() {
		$this->show_command('end');
		return null;
	}

	private function add_dynamic_commands($table_ary) {
		$added_media = array();

		usort($table_ary['body'], function($a, $b) {
			return $b[2] <=> $a[2];
		});

		foreach ($table_ary['body'] as $body_ary) {
			if (in_array($body_ary[0], $added_media)) {
				continue;
			}

			$action = new stdClass();
			$action->next = "m40";
			$action->free = $body_ary[0];

			$command_ary = ["text" => $body_ary[0], "action" => $action];
			$this->add_command('cmd1', $command_ary);

			$added_media[] = $body_ary[0];
		}
	}

	public function get_social_media_traffic_table($days) {
        global $qahm_db;
		$dateterm = $this->get_dateterm_using_days($days);
		$lp_data_ary = $qahm_db->summary_days_landingpages( $dateterm, $this->tracking_id, ['source_domain', 'utm_medium'] );
		$social_ary  = $this->get_social_ary();
		if ( ! $lp_data_ary || empty( $social_ary ) ) {
			return false;
		}

		$header_ary = array(
			array( 'key' => 'referrer', 'label' => esc_html__( 'Source', 'qa-heatmap-analytics' ), 'width' => 40 ),
			array( 'key' => 'media', 'label' => esc_html__( 'Medium', 'qa-heatmap-analytics' ), 'width' => 15  ),
			array( 'key' => 'session', 'label' => esc_html__( 'Sessions', 'qa-heatmap-analytics' ), 'width' => 15, 'type' => 'integer' ),
			array( 'key' => 'page_session', 'label' => esc_html__( 'Pages / Session', 'qa-heatmap-analytics' ), 'width' => 15, 'type' => 'float' ),
			array( 'key' => 'avg_session_time', 'label' => esc_html__( 'Avg. Time on Site', 'qa-heatmap-analytics' ), 'width' => 15, 'type' => 'duration' )
		);

		$option_ary = array(
			'perPage'     => 100,
			'pagination'  => true,
			'exportable'  => true,
			'sortable'    => true,
			'filtering'   => true,
			'maxHeight'   => 300,
			'stickyHeader' => true,
			'initialSort' => array(
				'column'    => 'session',
				'direction' => 'desc'
			),
		);

		$body_ary = array();
		$top_media = null;  // セッション数が最大のメディアを保存する変数
		$max_session_count = 0;  // セッション数の最大値を追跡
		
		foreach ($lp_data_ary as $data) {
			if ( ! isset($data['source_domain']) ) {
				continue;
			}

			$media = $this->get_social_media_by_referrer($social_ary, $data['source_domain']);
			if ( ! $media ) {
				continue;
			}
		
			// session_countは1以上になることを想定しているので0除算対策はしない
			$body_ary[] = array(
				$media,
				$data['utm_medium'],
				$data['session_count'],
				round($data['pv_count'] / $data['session_count']),
				round($data['session_time'] / $data['session_count']),
			);
		
			// 現在のメディアのセッション数がこれまでの最大値より大きい場合
			if ($data['session_count'] > $max_session_count) {
				$max_session_count = $data['session_count'];
				$top_media = $media;  // セッション数が最大のメディアを保存
			}
		}

		if (empty($body_ary)) {
			return false;

		} else {
			
			// 最終的にセッション数が最大のメディアを保存
			$this->session_top_media = $top_media;

			$table_ary = array( 'title' => esc_html__( 'Traffic from Social Media', 'qa-heatmap-analytics' ), 'header' => $header_ary, 'option' => $option_ary, 'body' => $body_ary );
			return $table_ary;
		}

	}

	public function get_social_media_by_referrer($social_ary, $source_domain) {
		// $social_aryが配列かどうか確認
		if (!is_array($social_ary) || empty($social_ary)) {
			// 配列でないか、空の場合には処理を中断
			return false;
		}
		
		// $source_domainがソーシャルメディア配列に部分一致するか確認
		foreach ($social_ary as $media => $domains) {
			foreach ($domains as $domain) {
				// 完全一致を確認
				if ($source_domain === $domain) {
					return $media; // 一致した場合、キーであるメディア名を返す
				}
			}
		}
	
		// ソーシャルメディアのドメインに一致しなければfalse
		return false;
	}

// Function from functions array
    public function get_landing_page_from_social_media_traffic_table($days, $social_media) {
        global $qahm_db;
		$dateterm = $this->get_dateterm_using_days($days);
		$lp_data_ary = $qahm_db->summary_days_landingpages($dateterm,$this->tracking_id, ['url','source_domain']);
		$social_ary  = $this->get_social_ary();
		if ( ! $lp_data_ary || empty( $social_ary ) ) {
			return false;
		}
		
		// 指定したメディアの要素だけを残す
		$social_ary = array_filter($social_ary, function($key) use ($social_media) {
			return $key === $social_media;
		}, ARRAY_FILTER_USE_KEY);

		$exist_search = false;
		// 検索結果ページが存在する場合はtrue
		$twitter_name = __('X (formerly Twitter)', 'qa-heatmap-analytics');
		if ( $social_media === 'X' || $social_media === 'X（旧Twitter）' || $social_media === $twitter_name || $social_media === 'Twitter' || $social_media === 'YouTube' || $social_media === 'Facebook' ) {
			$exist_search = true;
		}

		$header_ary = array();
		$header_ary[] = array( 'key' => 'landing_page', 'label' => esc_html__( 'Landing Pages', 'qa-heatmap-analytics' ), 'width' => 40, 'type'  => 'link' );
		$width = 20;
		if ( $exist_search ) {
			$width = 15;
			$header_ary[] = array( 'key' => 'sns_search', 'label' => esc_html__( 'Social Media Search Result', 'qa-heatmap-analytics' ), 'width' => $width, 'type' => 'link', 'typeOptions' => array( 'text' => '<i class="dashicons dashicons-share-alt2"></i>', 'newTab' => true ) );
		}
		$header_ary[] = array( 'key'   => 'session', 'label' => esc_html__( 'Sessions', 'qa-heatmap-analytics' ), 'width' => $width, 'type'  => 'integer' );
		$header_ary[] = array( 'key'   => 'page_session', 'label' => esc_html__( 'Pages / Session', 'qa-heatmap-analytics' ), 'width' => $width, 'type'  => 'float' );
		$header_ary[] = array( 'key'   => 'avg_session_time', 'label' => esc_html__( 'Avg. Time on Site', 'qa-heatmap-analytics' ), 'width' => $width, 'type'  => 'duration' );

		$body_ary = array();
		
		foreach ($lp_data_ary as $data) {
			if ( ! isset($data['source_domain']) ) {
				continue;
			}
		
			$media = $this->get_social_media_by_referrer($social_ary, $data['source_domain']);
			if ( ! $media ) {
				continue;
			}
		
			$search_url = urlencode($data['url']);

			switch ( $social_media ) {
				case 'X':
				case 'X（旧Twitter）':
				case 'Twitter':
				case $twitter_name:
					$search_url = 'https://x.com/search?q=' . $search_url . '&f=live';
					break;
			
				case 'YouTube':
					$search_url = 'https://www.youtube.com/results?search_query=' . $search_url;
					break;
		
				case 'Facebook':
					$search_url = 'https://www.facebook.com/search/top?q=' . $search_url;
					break;
					
				default:
					break;
			}
			
			if ( $exist_search ) {
				$body_ary[] = array(
					$data['url'],
					$search_url,
					$data['session_count'],
					round($data['pv_count'] / $data['session_count']),
					round($data['session_time'] / $data['session_count']),
				);
			} else {
				$body_ary[] = array(
					$data['url'],
					$data['session_count'],
					round($data['pv_count'] / $data['session_count']),
					round($data['session_time'] / $data['session_count']),
				);
			}
		}

		$option_ary = [
			'perPage'     => 100,
			'pagination'  => true,
			'exportable'  => true,
			'sortable'    => true,
			'filtering'   => true,
			'maxHeight'   => 300,
			'stickyHeader' => true,
			'initialSort' => array(
				'column'    => 'session',
				'direction' => 'desc'
			),
		];

		if (empty($body_ary)) {
			return false;

		} else {
			$table_title = sprintf(
				esc_html__( 'Landing Pages with Traffic from %s', 'qa-heatmap-analytics' ),
				$social_media
			);
			$table_ary = array( 'title' => $table_title, 'header' => $header_ary, 'option' => $option_ary, 'body' => $body_ary );
			return $table_ary;
		}
    }

    // Function from functions array
    public function analytics_social_media_goal() {
         $data = array();
         return $data;
    }

}
