<?php

class QAHM_Assistant_Seo_Expert extends QAHM_Assistant {

    public $session_report_num_days = '';

	protected function handle_state_start() {
		return 'm0';
	}

	protected function handle_state_m0() {
		if ( QAHM_TYPE === QAHM_TYPE_WP ) {
			$this->show_message(1); // ベータ版用
			return 'cmd1'; // ベータ版用
		} else {
			$this->show_message(0);
			return 'cmd0';
		}
	}

	protected function handle_state_cmd0() {
		$this->show_command('cmd0');
		$this->exit_flag = true;
		return null;
	}

	protected function handle_state_m1000() {
		$this->show_message(1000);
		return 'cmd1100';
	}

	protected function handle_state_cmd1100() {
		$this->show_command('cmd1100');
		$this->exit_flag = true;
		return null;
	}

	protected function handle_state_m1100() {
		$this->show_message(1100);
		return 'data1100';
	}

	protected function handle_state_data1100() {
		$this->session_report_num_days = $this->session_free;
		$table_ary = $this->get_all_ch_table( $this->session_report_num_days );
		if ( $table_ary && isset($table_ary['body'][0][3]) && $table_ary['body'][0][3] > 0 ) {
			$total_sessions = $table_ary['body'][0][3];
			$organic_sessions = $table_ary['body'][2][3];
			$organic_sessions_rate = round( $organic_sessions / $total_sessions * 100, 1 );
			$this->session_organic_rate = $organic_sessions_rate;

			$this->show_data($table_ary);
			return 'm1101';
		} else {
			return 'say_no_data_and_end';
		}
	}

	protected function handle_state_m1101() {
		$this->show_message(1101);
		return 'm1200';
	}

	protected function handle_state_m1200() {
		$this->show_message(1200);
		return 'goto_data1200';
	}

	protected function handle_state_goto_data1200() {
		$this->show_command_nextstr( 'data1200' );
		$this->exit_flag = true;
		return null;
	}

	protected function handle_state_data1200() {
		$table_ary = $this->get_medium_organic_lp_table( $this->session_report_num_days );
		if ( $table_ary ) {
			$this->show_data($table_ary);
			return 'm1201';
		} else {
			return 'say_no_data_and_end';
		}
	}

	protected function handle_state_m1201() {
		$this->show_message(1201);
		return 'm1210';
	}

	protected function handle_state_m1210() {
		$this->show_message(1210);
		return 'goto_data1210';
	}

	protected function handle_state_goto_data1210() {
		$this->show_command_nextstr( 'data1210' );
		$this->exit_flag = true;
		return null;
	}

	protected function handle_state_data1210() {
		$table_ary = $this->get_medium_organic_highly_bounced_lp_table( $this->session_report_num_days );
		if ( $table_ary ) {
			$this->show_data($table_ary);
			return 'm1211';
		} else {
			return 'em91211';
		}
	}

	protected function handle_state_m1211() {
		$this->show_message(1211);
		return 'goto_m1999';
	}

	protected function handle_state_em91211() {
		$this->show_message(91211);
		return 'goto_m1999';
	}

	protected function handle_state_goto_m1999() {
		$this->show_command_nextstr( 'm1999' );
		$this->exit_flag = true;
		return null;
	}

	protected function handle_state_m1999() {
		$this->show_message(1999);
		return 'end';
	}

	protected function handle_state_m2000() {
		$gsc_dir =  'view/' . $this->tracking_id . '/gsc';
		if ( $this->check_dir_has_file( $gsc_dir ) ) {
			$this->show_message(2000);
			return 'goto_data2000';						
		} else {
			return 'em92000';
		}					
	}

	protected function handle_state_goto_data2000() {
		$this->show_command_nextstr( 'data2000' );
		$this->exit_flag = true;
		return null;
	}

	protected function handle_state_data2000() {
		$table_ary = $this->get_gsc_upward_trend_table( 30 );
		if ( $table_ary ) {
			$this->show_data($table_ary);
			return 'm2100';
		} else {
			return 'say_no_data_and_end';
		}
	}

	protected function handle_state_m2100() {
		$this->show_message(2100);
		return 'goto_m2110';
	}

	protected function handle_state_goto_m2110() {
		$this->show_command_nextstr( 'm2110' );
		$this->exit_flag = true;
		return null;
	}

	protected function handle_state_m2110() {
		$this->show_message(2110);
		return 'goto_m2120';
	}

	protected function handle_state_goto_m2120() {
		$this->show_command_nextstr( 'm2120' );
		$this->exit_flag = true;
		return null;
	}

	protected function handle_state_m2120() {
		$this->show_message(2120);
		return 'goto_m2999';
	}

	protected function handle_state_goto_m2999() {
		$this->show_command_nextstr( 'm2999' );
		$this->exit_flag = true;
		return null;
	}

	protected function handle_state_m2999() {
		$this->show_message(2999);
		return 'end';
	}

	protected function handle_state_em92000() {
		$this->show_message(92000);
		return 'end';
	}


	protected function handle_state_m3000() {
		$gsc_dir =  'view/' . $this->tracking_id . '/gsc';
		if ( $this->check_dir_has_file( $gsc_dir ) ) {
			return 'cmd3000';
		} else {
			return 'em93000';
		}
	}

	protected function handle_state_cmd3000() {
		$this->show_command('cmd3000');
		$this->exit_flag = true;
		return null;
	}

	protected function handle_state_m3100() {
		$this->show_message(3100);
		return 'mloading';
	}

	protected function handle_state_mloading() {
		$this->show_message(8000);
		return 'goto_data3100';
	}

	protected function handle_state_goto_data3100() {
		$this->show_command_nextstr( 'data3100' );
		$this->exit_flag = true;
		return null;
	}

	protected function handle_state_data3100() {
		$table_ary = $this->get_rewrite_title_candidates_table( 30 );
		if ( $table_ary ) {
			$this->show_data($table_ary);
			$sort_tableary = $table_ary['body'];
			usort($sort_tableary, function($a, $b) {
				return $b[4] <=> $a[4];
			});
			$this->session_free = 'タイトル：' . $sort_tableary[0][1] . ' 検索キーワード：' . $sort_tableary[0][0];
			return 'a2';
		} else {
			return 'em93100';
		}
	}

	protected function handle_state_a2() {
		$instructions = 'あなたはSEOのプロです。検索キーワードに対してCTR（クリック率）が低い記事タイトルを改善するためのリライトアドバイスをお願いします。以下の要件を満たしてください。

理由を明確にする：なぜそのタイトルのCTRが低い可能性があるのか、考察してください。
改善ポイントを具体的に指摘する：例えば、「曖昧な表現」「検索意図とのズレ」「魅力の欠如」など。
代替案を提案する：SEOとユーザーの興味を引く観点から、より効果的なタイトル案を複数提示してください。
心理トリガーを考慮する：「限定感」「数字」「疑問形」「メリット強調」などを活用して、クリックしたくなる要素を組み込んでください。';
		$this->session_top_title = $this->session_free;
		$this->session_rewrite_advice = $this->run_ai( $instructions, $this->session_top_title );
		return 'm3101';
	}

	protected function handle_state_m3101() {
		$this->show_message(3101);
		return 'end';
	}

	protected function handle_state_em93000() {
		$this->show_message(93000);
		return 'end';
	}

	protected function handle_state_em93100() {
		$this->show_message(93100);
		return 'end';
	}


	protected function handle_state_say_no_data_and_end() {
		$this->show_message(99910);
		return 'end';
	}

	protected function handle_state_end() {
		$this->show_command('end_menu');
		$this->exit_flag = true;
		return null;
	}

	protected function handle_state_say_bye() {
		$this->show_message(99999);
		$this->exit_flag = true;
		return null;
	}

	protected function handle_state_cmd1() {
		$this->show_command('cmd1');
		$this->exit_flag = true;
		return null;
	}

	protected function handle_state_say_see_you_soon() {
		$this->show_message(2);
		$this->exit_flag = true;
		return null;
	}


	public function get_goals_ary() {
		global $qahm_data_api;
		$goals_json = $qahm_data_api->get_goals_json($this->tracking_id);
		$goals_ary = json_decode( $goals_json, true );

		$pidary = array();
		$allgoals = 0;
		for ($gid = 1; $gid <= count($goals_ary); $gid++) {
			$pidary = array_merge($pidary, $goals_ary[$gid]['pageid_ary']);
			$allgoals += (int) $goals_ary[$gid]['gnum_scale'];
		}

		// 配列の最初の要素として全体のデータを挿入
		array_unshift($goals_ary, array(
			'gtitle' => esc_html__( 'All Goals', 'qa-heatmap-analytics' ),
			'pageid_ary' => $pidary,
			'gnum_scale' => $allgoals
		));

		return $goals_ary;
	}

	public function get_all_ch_table($days) {
		global $qahm_data_api;

		$dateterm = $this->get_dateterm_using_days($days);
		$data_ary = $qahm_data_api->get_ch_data( $dateterm, $this->tracking_id );

		$title = esc_html__( 'Channel Report', 'qa-heatmap-analytics' );

		$header_ary = array(
			array('key' => 'channel', 'label' => esc_html__( 'Channel', 'qa-heatmap-analytics' ), 'width' => 22, 'type' => 'string'),
			array('key' => 'users', 'label' => esc_html__( 'Users', 'qa-heatmap-analytics' ), 'width' => 13, 'type' => 'number'),
			array('key' => 'new_users', 'label' => esc_html__( 'New Users', 'qa-heatmap-analytics' ), 'width' => 13, 'type' => 'number'),
			array('key' => 'session_count', 'label' => esc_html__( 'Sessions', 'qa-heatmap-analytics' ), 'width' => 13, 'type' => 'number'),
			array('key' => 'bounce_rate', 'label' => esc_html__( 'Bounce Rate', 'qa-heatmap-analytics' ), 'width' => 13, 'type' => 'percentage'),
			array('key' => 'pages_session', 'label' => esc_html__( 'Pages / Session', 'qa-heatmap-analytics' ), 'width' => 13, 'type' => 'float'),
			array('key' => 'avg_time_on_site', 'label' => esc_html__( 'Avg. Time on Site', 'qa-heatmap-analytics' ), 'width' => 13, 'type' => 'duration')
		);

		$body_ary = array();
		foreach( $data_ary as $data ) {
			$body_ary[] = array( $data[0], $data[1], $data[2], $data[3], $data[4], $data[5], $data[6] );
		}

		$option_ary = array(
			'perPage'     => 100,
			'pagination'  => true,
			'exportable'  => true,
			'sortable'    => true,
			'filtering'   => true,
			'maxHeight'   => 300,
			'stickyHeader' => true,
			'initialSort' => array(
				'column'    => 'new_users',
				'direction' => 'desc'
			),
		);

		$table_ary = array(
			'title' => $title,
			'header' => $header_ary,
			'option' => $option_ary,
			'body' => $body_ary
		);
		return $table_ary;

	}

	public function get_medium_organic_lp_table( $num_days ) {
		global $qahm_data_api;
		$table_ary = array();

		$gsc_from_and_to_date = $this->determine_normal_from_and_to_dates( $num_days );
		$from_date = $gsc_from_and_to_date[0];
		$to_date = $gsc_from_and_to_date[1];

		$dateterm = 'date = between ' . $from_date . ' and ' . $to_date;
		$filters = array( 'utm_medium' => 'organic' );
		$max_results = 10000;
		$sort_by = 3; // index#[3]＝セッション数上位から取ってくる
		$medium_organic_lp_ary = $qahm_data_api->get_filtered_lp_data( $this->tracking_id, $dateterm, $filters, $max_results, $sort_by );
		
		if ( ! empty( $medium_organic_lp_ary ) ) {
			$title = esc_html__( 'Landing Pages from Search Engines', 'qa-heatmap-analytics' );

			$header_ary = array(
				array('key' => 'page_title', 'label' => esc_html__( 'Page Title', 'qa-heatmap-analytics' ), 'width' => 23, 'type' => 'string'),
				array('key' => 'url', 'label' => esc_html__( 'URL', 'qa-heatmap-analytics' ), 'width' => 23, 'type' => 'link'),
				array('key' => 'session_count', 'label' => esc_html__( 'Sessions', 'qa-heatmap-analytics' ), 'width' => 9, 'type' => 'integer'),
				array('key' => 'new_session_rate', 'label' => esc_html__( 'New Session Rate', 'qa-heatmap-analytics' ), 'width' => 9, 'type' => 'integer'),
				array('key' => 'new_users', 'label' => esc_html__( 'New Users', 'qa-heatmap-analytics' ), 'width' => 9, 'type' => 'string'),
				array('key' => 'bounce_rate', 'label' => esc_html__( 'Bounce Rate', 'qa-heatmap-analytics' ), 'width' => 9, 'type' => 'percentage'),
				array('key' => 'pages_session', 'label' => esc_html__( 'Pages / Session', 'qa-heatmap-analytics' ), 'width' => 9, 'type' => 'float'),
				array('key' => 'avg_time_on_site', 'label' => esc_html__( 'Avg. Time on Site', 'qa-heatmap-analytics' ), 'width' => 9, 'type' => 'duration'),
			);

			$body_ary = array();
			$lp_ary_cnt = count( $medium_organic_lp_ary );
			for ( $iii = 0; $iii < $lp_ary_cnt; $iii++ ) {
				$lp_data = $medium_organic_lp_ary[$iii]; // [ [0] => ページID配列, [1] => ページタイトル, [2] => ページURL, [3] => セッション数, [4] => 新規セッション数, [5] => 新規ユーザー数, [6] => バウンス数, [7] => ページビュー数, [8] => セッション時間（秒）, [9] => wp_qa_id, [10] => ページID配列 ]				
				$new_sessions_rate = round( $lp_data[4] / $lp_data[3] * 100, 1 );
				$bounce_rate = round( $lp_data[6] / $lp_data[3] * 100, 1 );
				$pages_per_session = round( $lp_data[7] / $lp_data[3], 1 );
				$avg_time_on_site = $lp_data[8] / $lp_data[3];
				$avg_time_on_site = round( $avg_time_on_site, 0 );
				$body_ary[] = array(
					$lp_data[1],
					$lp_data[2],
					$lp_data[3],
					$new_sessions_rate,
					$lp_data[5],
					$bounce_rate,
					$pages_per_session,
					$avg_time_on_site
				);
			}
		
			$option_ary = array(
				'perPage'     => 100,
				'pagination'  => true,
				'exportable'  => true,
				'sortable'    => true,
				'filtering'   => true,
				'maxHeight'   => 300,
				'stickyHeader' => true,
				'initialSort' => array(
					'column'    => 'session_count',
					'direction' => 'desc'
				),
			);

			$table_ary = array(
				'title' => $title,
				'header' => $header_ary,
				'option' => $option_ary,
				'body' => $body_ary
			);
		}
				
		return $table_ary;
	}

	public function get_medium_organic_highly_bounced_lp_table( $num_days ) {
		global $qahm_data_api;
		$table_ary = array();

		$gsc_from_and_to_date = $this->determine_normal_from_and_to_dates( $num_days );		
		$from_date = $gsc_from_and_to_date[0];
		$to_date = $gsc_from_and_to_date[1];

		$dateterm = 'date = between ' . $from_date . ' and ' . $to_date;
		$filters = array( 'utm_medium' => 'organic' );
		$max_results = 10000;
		$sort_by = 3; // index#[3]＝セッション数上位から取ってくる
		$medium_organic_lp_ary = $qahm_data_api->get_filtered_lp_data( $this->tracking_id, $dateterm, $filters, $max_results, $sort_by );
		
		if ( ! empty( $medium_organic_lp_ary ) ) {
			$title = esc_html__( 'High Bounce Rate Landing Pages', 'qa-heatmap-analytics' );

			$header_ary = array(
				array('key' => 'page_title', 'label' => esc_html__( 'Page Title', 'qa-heatmap-analytics' ), 'width' => 23, 'type' => 'string'),
				array('key' => 'url', 'label' => esc_html__( 'URL', 'qa-heatmap-analytics' ), 'width' => 23, 'type' => 'link'),
				array('key' => 'session_count', 'label' => esc_html__( 'Sessions', 'qa-heatmap-analytics' ), 'width' => 9, 'type' => 'integer'),
				array('key' => 'new_session_rate', 'label' => esc_html__( 'New Session Rate', 'qa-heatmap-analytics' ), 'width' => 9, 'type' => 'percentage'),
				array('key' => 'new_users', 'label' => esc_html__( 'New Users', 'qa-heatmap-analytics' ), 'width' => 9, 'type' => 'integer'),
				array('key' => 'bounce_rate', 'label' => esc_html__( 'Bounce Rate', 'qa-heatmap-analytics' ), 'width' => 9, 'type' => 'percentage'),
				array('key' => 'pages_session', 'label' => esc_html__( 'Pages / Session', 'qa-heatmap-analytics' ), 'width' => 9, 'type' => 'float'),
				array('key' => 'avg_time_on_site', 'label' => esc_html__( 'Avg. Time on Site', 'qa-heatmap-analytics' ), 'width' => 9, 'type' => 'duration')
			);

			$body_ary = array();
			$lp_ary_cnt = count( $medium_organic_lp_ary );
			for ( $iii = 0; $iii < $lp_ary_cnt; $iii++ ) {
				$lp_data = $medium_organic_lp_ary[$iii]; // [ [0] => ページID配列, [1] => ページタイトル, [2] => ページURL, [3] => セッション数, [4] => 新規セッション数, [5] => 新規ユーザー数, [6] => バウンス数, [7] => ページビュー数, [8] => セッション時間（秒）, [9] => wp_qa_id, [10] => ページID配列 ]				
				//直帰率が90%以上&&ページ別訪問数が10以上のページのみを抽出
				if ( $lp_data[3] < 10 ) {
					continue;
				}
				$bounce_rate = round( $lp_data[6] / $lp_data[3] * 100, 1 );
				if ( $bounce_rate < 90 ) {
					continue;
				}
				$new_sessions_rate = round( $lp_data[4] / $lp_data[3] * 100, 1 );				
				$pages_per_session = round( $lp_data[7] / $lp_data[3], 1 );
				$avg_time_on_site = $lp_data[8] / $lp_data[3];
				$avg_time_on_site = round( $avg_time_on_site, 0 );
				$body_ary[] = array(
					$lp_data[1],
					$lp_data[2],
					$lp_data[3],
					$new_sessions_rate,
					$lp_data[5],
					$bounce_rate,
					$pages_per_session,
					$avg_time_on_site
				);
			}
		
			$option_ary = array(
				'perPage'     => 100,
				'pagination'  => true,
				'exportable'  => true,
				'sortable'    => true,
				'filtering'   => true,
				'maxHeight'   => 300,
				'stickyHeader' => true,
				'initialSort' => array(
					'column'    => 'bounce_rate',
					'direction' => 'desc'
				),
			);

			if ( ! empty( $body_ary ) ) {
				$table_ary = array(
					'title' => $title,
					'header' => $header_ary,
					'option' => $option_ary,
					'body' => $body_ary
				);
			}
		}
				
		return $table_ary;
	}

	public function get_gsc_upward_trend_table( $num_days ) {
		global $qahm_data_api;
		$table_ary = array();

		$gsc_from_and_to_date = $this->determine_gsc_from_and_to_dates( $num_days );		
		$from_date = $gsc_from_and_to_date[0];
		$to_date = $gsc_from_and_to_date[1];

		$gsc_data_ary = $qahm_data_api->get_gsc_lp_keywords_calc_data( $this->tracking_id, $from_date, $to_date );

		if ( ! empty( $gsc_data_ary ) ) {		
			$title = esc_html__( 'Google Search Console Report', 'qa-heatmap-analytics' );

			$header_ary = array(
				array('key' => 'search_keyword', 'label' => esc_html__( 'Search Query', 'qa-heatmap-analytics' ), 'width' => 23, 'type' => 'string'),
				array('key' => 'url', 'label' => esc_html__( 'Url', 'qa-heatmap-analytics' ), 'width' => 23, 'type' => 'link'),
				array('key' => 'current_rank', 'label' => esc_html__( 'Latest Position', 'qa-heatmap-analytics' ), 'width' => 9, 'type' => 'float'),
				array('key' => 'rank_trend', 'label' => esc_html__( 'Position Trend', 'qa-heatmap-analytics' ), 'width' => 9, 'type' => 'percentage', 'formatOptions' => array( 'precision' =>  1 ) ),
				array('key' => 'impressions', 'label' => esc_html__( 'Impressions', 'qa-heatmap-analytics' ), 'width' => 9, 'type' => 'integer'),
				array('key' => 'clicks', 'label' => esc_html__( 'Clicks', 'qa-heatmap-analytics' ), 'width' => 9, 'type' => 'integer'),
				array('key' => 'google_search', 'label' => esc_html__( 'Search on Google', 'qa-heatmap-analytics' ), 'width' => 18, 'type' => 'string', 'formatter' => 'function(value, row) {
						let qParam = encodeURIComponent(value);
						let link = `https://www.google.com/search?q=${qParam}`;
						return `<a href="${link}" target="_blank"><span class="dashicons dashicons-search"></span>${value}</a>`;
					}')
			);

			$body_ary = array();
			foreach( $gsc_data_ary as $lp_id => $lp_data ) {
				$lp_url = $lp_data['url'];
				$keywords_data = $lp_data['keyword'];
				foreach( $keywords_data as $keyword => $calc_data ) {
					// 順位が上がっているキーワードのみを抽出（トレンドがマイナスや0のものは除外）
					if ( $calc_data['trend'] <= 0 ) {
						continue;
					}
					// 相関係数が高いキーワードのみを抽出
					if ( $calc_data['soukankeisu'] <= 0.4 ) {
						continue;
					}
					// 表示回数が30未満のものは除外
					if ( $calc_data['imp'] < 30 ) {
						continue;
					}

					$body_ary[] = array(
						$keyword,
						$lp_url,
						$calc_data['lastpos'],
						$calc_data['trend'],
						$calc_data['imp'],
						$calc_data['clk'],
						$keyword,
					);
				}
			}

			$option_ary = array(
				'perPage'     => 100,
				'pagination'  => true,
				'exportable'  => true,
				'sortable'    => true,
				'filtering'   => true,
				'maxHeight'   => 300,
				'initialSort' => array(
					'column'    => 'rank_trend',
					'direction' => 'desc'
				),
			);

			if (  ! empty( $body_ary ) ) {
				$table_ary = array(
					'title' => $title,
					'header' => $header_ary,
					'option' => $option_ary,
					'body' => $body_ary
				);
			}
		}
		return $table_ary;
	}

	public function get_rewrite_title_candidates_table( $num_days ) {
		global $qahm_data_api;
		$table_ary = array();
		
		$gsc_from_and_to_date = $this->determine_gsc_from_and_to_dates( $num_days );		
		$from_date = $gsc_from_and_to_date[0];
		$to_date = $gsc_from_and_to_date[1];

		// ランディングページのデータ
		$dateterm = 'date = between ' . $from_date . ' and ' . $to_date;
		$lp_data_ary = $qahm_data_api->get_lp_data( $dateterm, $this->tracking_id );
		$lp_pageid_ary = array();
		$lp_data_cnt = count( $lp_data_ary );
		for( $iii = 0; $iii < $lp_data_cnt; $iii++ ) {
			// 訪問数が10以上のみを抽出
			if ( $lp_data_ary[$iii][3] < 10 ) {
				continue;
			}
			$lp_pageid_ary = array_merge( $lp_pageid_ary, $lp_data_ary[$iii][10] );
		}
		
		// 「順位に対するクリック率」の一般平均
		$position_ctr_avg = array(
			1 => 27.6,
			2 => 15.8,
			3 => 11.0,
			4 => 8.0,
			5 => 7.2,
			6 => 5.1,
			7 => 4.0,
			8 => 3.2,
			9 => 2.8,
			10 => 2.5,
		);

		// GSCデータ
		$gsc_data_ary = $qahm_data_api->get_gsc_lp_keywords_calc_data( $this->tracking_id, $from_date, $to_date );
		
		if ( ( ! empty( $lp_pageid_ary ) ) && ( ! empty( $gsc_data_ary ) ) ) {		
			$matched_lps_gsc_data = array();
			$lp_pageid_ary = array_keys( array_flip( $lp_pageid_ary ) ); // 重複削除
			$lp_pageid_cnt = count( $lp_pageid_ary );
			for ( $iii = 0; $iii < $lp_pageid_cnt; $iii++ ) {
				if ( ! isset( $gsc_data_ary[ $lp_pageid_ary[$iii] ] ) ) {
					continue;
				}
				$lp_data = $gsc_data_ary[ $lp_pageid_ary[$iii] ];
				$lp_title = $lp_data['title'];
				$lp_url = $lp_data['url'];			
				$keywords_data = $lp_data['keyword'];
				foreach( $keywords_data as $keyword => $calc_data ) {
					// 10位以内のみを抽出
					if ( $calc_data['lastpos'] > 10 ) {
						continue;
					}
					// 一般平均と比較して、クリック率が平均に達しているものは除く
					if ( $calc_data['ctr'] >= $position_ctr_avg[ $calc_data['lastpos'] ] ) {
						continue;
					}
					$matched_lps_gsc_data[] = array(
						$keyword,
						$lp_title,
						$lp_url,
						$calc_data['lastpos'],
						$calc_data['imp'],
						$calc_data['clk'],
						round($calc_data['ctr']),
						$keyword,
					);
				}
			}

			if ( ! empty( $matched_lps_gsc_data ) ) {
				$title = esc_html__( 'Rewrite Opportunities from Google Search Console', 'qa-heatmap-analytics' );
				$header_ary = array(
					array('key' => 'search_keyword', 'label' => esc_html__( 'Search Query', 'qa-heatmap-analytics' ), 'width' => 20, 'type' => 'string'),
					array('key' => 'page_title', 'label' => esc_html__( 'Page Title', 'qa-heatmap-analytics' ), 'width' => 17, 'type' => 'string'),
					array('key' => 'url', 'label' => esc_html__( 'URL', 'qa-heatmap-analytics' ), 'width' => 17, 'type' => 'link'),
					array('key' => 'current_rank', 'label' => esc_html__( 'Latest Position', 'qa-heatmap-analytics' ), 'width' => 8, 'type' => 'float'),
					array('key' => 'impressions', 'label' => esc_html__( 'Impressions', 'qa-heatmap-analytics' ), 'width' => 8, 'type' => 'integer'),
					array('key' => 'clicks', 'label' => esc_html__( 'Clicks', 'qa-heatmap-analytics' ), 'width' => 8, 'type' => 'integer'),
					array('key' => 'click_rate', 'label' => esc_html__( 'CTR', 'qa-heatmap-analytics' ), 'width' => 8, 'type' => 'percentage'),
					array('key' => 'google_search', 'label' => esc_html__( 'Search on Google', 'qa-heatmap-analytics' ), 'width' => 14, 'type' => 'string', 'formatter' => 'function(value, row) {
								let qParam = encodeURIComponent(value);
								let link = `https://www.google.com/search?q=${qParam}`;
								return `<a href="${link}" target="_blank"><span class="dashicons dashicons-search"></span>${value}</a>`;
							}')
				);
				$body_ary = $matched_lps_gsc_data;
				$option_ary = array(
					'perPage'     => 100,
					'pagination'  => true,
					'exportable'  => true,
					'sortable'    => true,
					'filtering'   => true,
					'maxHeight'   => 300,
					'initialSort' => array(
						'column'    => 'current_rank',
						'direction' => 'desc'
					),
				);

				$table_ary = array(
					'title' => $title,
					'header' => $header_ary,
					'option' => $option_ary,
					'body' => $body_ary
				);
			} 
		}
		return $table_ary;
	}


	public function get_dummy_data_table() {
		$title = 'ダミーテーブル';
		$header_ary = array(
			array('title' => 'ダミー１', 'type' => 'string'),
			array('title' => 'ダミー２', 'type' => 'string'),
			array('title' => 'ダミー３', 'type' => 'number'),
			array('title' => 'ダミー４', 'type' => 'number'),
			array('title' => 'ダミー５', 'type' => 'number')
		);
		$body_ary = array(
			array('データ１', 'データ２', 100, 1.5, 30),
			array('データ３', 'データ４', 200, 2.5, 40),
			array('データ５', 'データ６', 300, 3.5, 50),
			array('データ７', 'データ８', 400, 4.5, 60),
			array('データ９', 'データ１０', 500, 5.5, 70)
		);
		$sort_ary = array('index' => 1, 'order' => 'dsc');
		
		$table_ary = array(
			'title' => $title,
			'header' => $header_ary,
			'sort' => $sort_ary,
			'body' => $body_ary
		);
		return $table_ary;
	}

}
