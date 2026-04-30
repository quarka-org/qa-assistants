<?php

class QAHM_Assistant_Growth extends QAHM_Assistant {
	// session keep variables
    public $session_check_days = 14;
    public $session_growth_page_summary = [];
    public $session_top_growth_pages = [];
    public $session_top_x = 10;
    public $session_average_end_pv_count = 0;

	protected function handle_state_start() {
		return 'm0';
	}

	protected function handle_state_m0() {
		$this->show_message(0);
		return 'cmd0';
	}

	protected function handle_state_cmd0() {
		$this->show_command('cmd0');
		$this->exit_flag = true;
		return null;
	}

	protected function handle_state_m1() {
		$this->session_check_days = 14;
		return 'm10';
	}

	protected function handle_state_m2() {
		$this->session_check_days = 28;
		return 'm10';
	}

	protected function handle_state_m3() {
		$this->session_check_days = 90;
		return 'm10';
	}

	protected function handle_state_m10() {
		$this->set_growth_page_array($this->session_check_days);
		$this->show_message(10);
		return 'm20';
	}

	protected function handle_state_m20() {
		$this->show_message(20);
		return 'data0';
	}

	protected function handle_state_data0() {
		$table_title = sprintf(
			esc_html__('Top Growing Landing Pages (Last %d Days)', 'qa-heatmap-analytics'),
			$this->session_check_days
		);
		$table_ary = array(
			'title' => $table_title,
			'header' => array(
				array('key' => 'title', 'label' => esc_attr__( 'Title', 'qa-heatmap-analytics'), 'width' => 26, 'type' => 'string'),
				array('key' => 'url', 'label' => 'URL', 'width' => 26, 'type' => 'link'),
				array('key' => 'first7day_pv', 'label' => esc_attr__( 'First 7-Day PV', 'qa-heatmap-analytics' ), 'width' => 16, 'type' => 'integer'),
				array('key' => 'thisweek7day_pv', 'label' => esc_attr__( 'Latest 7-Day PV', 'qa-heatmap-analytics' ), 'width' => 16, 'type' => 'integer'),
				array('key' => 'growth_rate', 'label' => esc_attr__( 'Growth Rate', 'qa-heatmap-analytics' ), 'width' => 16, 'type' => 'percentage')
			),
			'option' => array(
				'perPage'     => 100,
				'pagination'  => true,
				'exportable'  => true,
				'sortable'    => true,
				'filtering'   => true,
				'maxHeight'   => 300,
				'stickyHeader' => true,
				'initialSort' => array(
					'column'    => 'growth_rate',
					'direction' => 'desc'
				),
			),
			'body' => $this->session_growth_page_summary
		);
		$this->show_data($table_ary);
		return 'm30';
	}

	protected function handle_state_m30() {
		$this->show_message(30);
		return 'cmd1';
	}

	protected function handle_state_cmd1() {
		$this->show_command('cmd1');
		$this->exit_flag = true;
		return null;
	}

	protected function handle_state_m40() {
		$this->show_message(40);
		return 'data1';
	}

	protected function handle_state_data1() {
		$table_title = sprintf(
			esc_html__('Top %d Growing Landing Pages', 'qa-heatmap-analytics'),
			$this->session_top_x
		);
		$table_ary = array(
			'title' => $table_title,
			'header' => array(
				array('key' => 'title', 'label' => esc_attr__( 'Title', 'qa-heatmap-analytics'), 'width' => 23, 'type' => 'string'),
				array('key' => 'url', 'label' => 'URL', 'width' => 23, 'type' => 'link'),
				array('key' => 'media', 'label' => esc_attr__( 'Media', 'qa-heatmap-analytics'), 'width' => 18, 'type' => 'string'),
				array('key' => 'first7day_pv', 'label' => esc_attr__( 'First 7-Day PV', 'qa-heatmap-analytics'), 'width' => 12, 'type' => 'integer'),
				array('key' => 'thisweek7day_pv', 'label' => esc_attr__( 'Latest 7-Day PV', 'qa-heatmap-analytics'), 'width' => 12, 'type' => 'integer'),
				array('key' => 'growth_rate', 'label' => esc_attr__( 'Growth Rate', 'qa-heatmap-analytics'), 'width' => 12, 'type' => 'percentage')
			),
			'option' => array(
				'perPage'     => 100,
				'pagination'  => true,
				'exportable' => true,
				'sortable'   => true,
				'filtering'   => true,
				'maxHeight'   => 300,
				'stickyHeader' => true,
				'initialSort' => array(
					'column'    => 'growth_rate',
					'direction' => 'desc'
				),
			),
			'body' => $this->session_top_growth_pages
		);
		$this->show_data($table_ary);
		return 'm50';
	}

	protected function handle_state_m50() {
		$this->show_message(50);
		return 'cmd2';
	}

	protected function handle_state_cmd2() {
		$this->show_command('cmd2');
		$this->exit_flag = true;
		return null;
	}
    public function set_growth_page_array($days) {
        global $qahm_data_api;
        $this->session_growth_page_summary = [];
        $this->session_top_growth_pages = [];

        $dateterm = $this->get_dateterm_using_days($days);
        $gw_data_ary = $qahm_data_api->get_gw_data($dateterm, $this->tracking_id);

        // データを合算する
        $temp_data = [];
        foreach ($gw_data_ary as $data) {
            $pageid = $data[0];
            $title = $data[1];
            $url = $data[2];
            $start_pv_count = $data[4];
            $end_pv_count = $data[5];

            if (!isset($temp_data[$pageid])) {
                $temp_data[$pageid] = [
                    'title' => $title,
                    'url' => $url,
                    'start_pv_count' => 0,
                    'end_pv_count' => 0
                ];
            }

            $temp_data[$pageid]['start_pv_count'] += $start_pv_count;
            $temp_data[$pageid]['end_pv_count'] += $end_pv_count;
        }

        // end_pv_countの平均を計算
        $total_end_pv_count = 0;
        $total_items = count($temp_data);
        foreach ($temp_data as $data) {
            $total_end_pv_count += $data['end_pv_count'];
        }
        $average_end_pv_count = round($total_end_pv_count / $total_items);
        $this->session_average_end_pv_count = $average_end_pv_count;
        $growth_page_summary = [];
        $top_growth_pages = [];

        // 合算したデータから growth_rate を計算する
        foreach ($temp_data as $data) {
            if ($data['end_pv_count'] >= $average_end_pv_count) {
                $growth_rate = $data['start_pv_count'] != 0 ? round($data['end_pv_count'] / $data['start_pv_count'] * 100 - 100, 2) : 0;

                $growth_page_summary[] = [
                    $data['title'],
                    $data['url'],
                    $data['start_pv_count'],
                    $data['end_pv_count'],
                    $growth_rate
                ];
            }
        }
		
        // growth_rateがプラスのものをフィルタリング
        $positive_growth_data = array_filter($growth_page_summary, function ($data) {
            return $data[4] > 0;  // growth_rate
        });

        // growth_rateでソート
        usort($positive_growth_data, function ($a, $b) {
            return $b[4] <=> $a[4];  // growth_rate
        });

        // 上位10ページを取得
        $top_growth_data = array_slice($positive_growth_data, 0, 10);

        // 取得できたページ数をセット
        $this->session_top_x = count($top_growth_data);
        // 元の配列から medium を含めたデータを作成
        foreach ($top_growth_data as $data) {
            foreach ($gw_data_ary as $original_data) {
                if ($data[1] == $original_data[2]) { // url
                    // medium
                    if ( ! $original_data[3] ) {
                        $medium = 'direct';
                    } else {
                        $medium = $original_data[3];
                    }
                    if ( $original_data[4] !== 0 ) {
                        $growth_rate = round($original_data[5] / $original_data[4] * 100 - 100, 2);
                    } else {
                        $growth_rate = 0;
                    }
                    $top_growth_pages[] = [
                        $original_data[1],  // title
                        $original_data[2],  // url
                        $medium,  // medium
                        $original_data[4],  // start_pv_count
                        $original_data[5],  // end_pv_count
                        $growth_rate // growth_rate
                    ];
                }
            }
        }


        // 結果をセッション変数にセット
        $this->session_growth_page_summary = $growth_page_summary;
        $this->session_top_growth_pages = $top_growth_pages;
    }

}
