<?php

class QAHM_Assistant_Site_Analyst extends QAHM_Assistant {
	// session keep variables
    public $session_check_days = 7;


	protected function handle_state_start() {
		return $this->handle_state_m0();
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

	protected function handle_state_m10() {
		$this->session_check_days = 7;
		$this->show_message(10);
		return 'data0';
	}

	protected function handle_state_m11() {
		$this->session_check_days = 28;
		$this->show_message(10);
		return 'data0';
	}

	protected function handle_state_m12() {
		$this->session_check_days = 90;
		$this->show_message(10);
		return 'data0';
	}

	protected function handle_state_data0() {
		$data_ary = $this->get_days_access_using_days($this->session_check_days);

		$body_ary = [];
		foreach ($data_ary as $each_row_ary) {
			$body_ary[] = array_values($each_row_ary);
		}

		$table_title = sprintf(
			esc_html__('User Activity Summary for the Last %d Days', 'qa-heatmap-analytics'),
			$this->session_check_days
		);
		$table_ary = array(
			'title' => $table_title,
			'header' => array(
				array('key' => 'date', 'label' => esc_html__( 'Date', 'qa-heatmap-analytics' ), 'width' => 20, 'type' => 'date'),
				array('key' => 'day', 'label' => esc_html__( 'Day of Week', 'qa-heatmap-analytics' ), 'width' => 20, 'type' => 'string'),
				array('key' => 'user_count', 'label' => esc_html__( 'Users', 'qa-heatmap-analytics' ), 'width' => 20, 'type' => 'integer'),
				array('key' => 'session_count', 'label' => esc_html__( 'Sessions', 'qa-heatmap-analytics' ), 'width' => 20, 'type' => 'integer'),
				array('key' => 'pv_count', 'label' => esc_html__( 'Pageviews', 'qa-heatmap-analytics' ), 'width' => 20, 'type' => 'integer'),
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
					'column'    => 'date',
					'direction' => 'desc'
				),
			),
			'body' => $body_ary
		);
		$this->show_data($table_ary);
		return 'm20';
	}

	protected function handle_state_m20() {
		$this->show_message(20);
		return 'cmd1';
	}

	protected function handle_state_cmd1() {
		$this->show_command('cmd1');
		$this->exit_flag = true;
		return null;
	}

	protected function handle_state_m30() {
		$this->show_message(30);
		return 'data1';
	}

	protected function handle_state_data1() {
		$data_ary = $this->aggregate_pv_sessions_by_os_and_browser($this->session_check_days);

		$body_ary = [];
		foreach ($data_ary as $each_row_ary) {
			$body_ary[] = array_values($each_row_ary);
		}
		$table_title = esc_html__( 'User Activity by Date, OS, and Browser', 'qa-heatmap-analytics' );
		$table_ary = array(
			'title' => $table_title,
			'header' => array(
				array('key' => 'os', 'label' => 'OS', 'width' => 20, 'type' => 'string'),
				array('key' => 'browser', 'label' => esc_html__( 'Browser', 'qa-heatmap-analytics' ), 'width' => 20, 'type' => 'string'),
				array('key' => 'user', 'label' => esc_html__( 'Users', 'qa-heatmap-analytics' ), 'width' => 20, 'type' => 'integer'),
				array('key' => 'session', 'label' => esc_html__( 'Sessions', 'qa-heatmap-analytics' ), 'width' => 20, 'type' => 'integer'),
				array('key' => 'pv', 'label' => esc_html__( 'Pageviews', 'qa-heatmap-analytics' ), 'width' => 20, 'type' => 'integer')
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
					'column'    => 'user',
					'direction' => 'desc'
				),
			),
			'body' => $body_ary
		);
		$this->show_data($table_ary);
		return 'm40';
	}

	protected function handle_state_cmd40() {
		$this->show_command('cmd40');
		$this->exit_flag = true;
		return null;
	}

	protected function handle_state_m40() {
		$this->show_message(40);
		return 'cmd50';
	}

	protected function handle_state_cmd50() {
		$this->show_command('cmd50');
		$this->exit_flag = true;
		return null;
	}

	protected function handle_state_m50() {
		$this->show_message(50);
		return 'data3';
	}

	protected function handle_state_data3() {
		$data_ary = $this->aggregate_sessions_by_hour_and_weekday($this->session_check_days);
		$body_ary = [];
		foreach ($data_ary as $each_row_ary) {
			$body_ary[] = array_values($each_row_ary);
		}

		$table_title = esc_html__( 'Sessions by Hour and Day of Week', 'qa-heatmap-analytics' );
		$table_ary = array(
			'title' => $table_title,
			'header' => array(
				array('key' => 'time', 'label' => esc_html__( 'Hour', 'qa-heatmap-analytics' ), 'width' => 9, 'type' => 'integer'),
				array('key' => 'sun', 'label' => esc_html__( 'Sun', 'qa-heatmap-analytics' ), 'width' => 13, 'type' => 'integer'),
				array('key' => 'mon', 'label' => esc_html__( 'Mon', 'qa-heatmap-analytics' ), 'width' => 13, 'type' => 'integer'),
				array('key' => 'tue', 'label' => esc_html__( 'Tue', 'qa-heatmap-analytics' ), 'width' => 13, 'type' => 'integer'),
				array('key' => 'wed', 'label' => esc_html__( 'Wed', 'qa-heatmap-analytics' ), 'width' => 13, 'type' => 'integer'),
				array('key' => 'thu', 'label' => esc_html__( 'Thu', 'qa-heatmap-analytics' ), 'width' => 13, 'type' => 'integer'),
				array('key' => 'fri', 'label' => esc_html__( 'Fri', 'qa-heatmap-analytics' ), 'width' => 13, 'type' => 'integer'),
				array('key' => 'sat', 'label' => esc_html__( 'Sat', 'qa-heatmap-analytics' ), 'width' => 13, 'type' => 'integer')
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
					'column'    => 'time',
					'direction' => 'asc'
				),
			),
			'body' => $body_ary
		);

		$this->show_data($table_ary);
		return 'm51';
	}

	protected function handle_state_m51() {
		$this->show_message(51);
		return 'm60';
	}

	protected function handle_state_m60() {
		$this->show_message(60);
		return 'cmd3';
	}

	protected function handle_state_cmd3() {
		$this->show_command('cmd3');
		$this->exit_flag = true;
		return null;
	}

    public function get_days_access_using_days($days = 1) {
        global $qahm_data_api;

        $dateterm = $this->get_dateterm_using_days($days);
        $days_access = $qahm_data_api->select_data('summary_days_access', '*', $dateterm, false, '', $this->tracking_id);

        // 翻訳済み曜日ラベル（index 0〜6 = 日〜土）
        $weekday_labels = [
            __( 'Sun', 'qa-heatmap-analytics' ),
            __( 'Mon', 'qa-heatmap-analytics' ),
            __( 'Tue', 'qa-heatmap-analytics' ),
            __( 'Wed', 'qa-heatmap-analytics' ),
            __( 'Thu', 'qa-heatmap-analytics' ),
            __( 'Fri', 'qa-heatmap-analytics' ),
            __( 'Sat', 'qa-heatmap-analytics' ),
        ];

        foreach ($days_access as $key => $value) {
            if (!isset($value['date'])) {
                error_log('Date not set in array: ' . print_r($value, true));
                continue;
            }

            $date = new DateTime($value['date']);
            $day_index = (int) $date->format('w'); // 0 = Sunday, 6 = Saturday
            $day_label = $weekday_labels[$day_index];

            // 曜日を配列の2番目に挿入（dateの次）
            array_splice($days_access[$key], 1, 0, $day_label);
        }

        $filtered_days_access = array_map(function($item) {
            return [
                $item['date'],       // 日付
                $item[0],            // 曜日
                $item['user_count'],
                $item['session_count'],
                $item['pv_count']
            ];
        }, $days_access);

        return $filtered_days_access;
    }

    
    public function aggregate_pv_sessions_by_hour( $days ) {
        global $qahm_data_api;
        $endDate = strtotime('yesterday');
        $startDate = strtotime("-$days days", $endDate);

        $days_pv = array();

        // 日ごとにデータを取得し、$days_pvに追加する
        for ($date = $startDate; $date <= $endDate; $date = strtotime('+1 day', $date)) {
            $datestr = date('Y-m-d', $date);
            $dateterm = $this->get_dateterm_using_daystr( $datestr );
            $daily_pv = $qahm_data_api->select_data('view_pv', '*', $dateterm, false, '', $this->tracking_id);
            if (isset($daily_pv[0]) && is_array($daily_pv[0])) {
				$days_pv = array_merge($days_pv, $daily_pv[0]); // $daily_pv[0] is the data array
			}
        }

        $pv_counts = array_fill( 0, 24, 0 );
        $session_counts = array_fill( 0, 24, 0 );

        foreach ( $days_pv as $entry ) {
            // Convert UNIX timestamp to JST hour of the day.
            $hour = gmdate('G', $entry['access_time'] + 9 * 3600);

            // Increment PV count.
            $pv_counts[ $hour ]++;

            // Increment session count if pv is 1.
            if ( 1 === intval( $entry['pv'] ) ) {
                $session_counts[ $hour ]++;
            }
        }

        $result = array();
        for ($hour = 0; $hour < 24; $hour++) {
            $result[] = array($hour, $session_counts[$hour], $pv_counts[$hour]);
        }

        return $result;
    }
    public function aggregate_sessions_by_hour_and_weekday($days) {
        global $qahm_data_api;
        $endDate = strtotime('yesterday');
        $startDate = strtotime("-$days days", $endDate);

        $days_pv = array();

        // 日ごとにデータを取得し、$days_pvに追加する
        for ($date = $startDate; $date <= $endDate; $date = strtotime('+1 day', $date)) {
            $datestr = date('Y-m-d', $date);
            $dateterm = $this->get_dateterm_using_daystr($datestr);
            $daily_pv = $qahm_data_api->select_data('view_pv', '*', $dateterm, false, '', $this->tracking_id);
            if (isset($daily_pv[0]) && is_array($daily_pv[0])) {
				$days_pv = array_merge($days_pv, $daily_pv[0]); // $daily_pv[0] is the data array
			}
        }

        // 24時間 * 7 の配列を初期化
        $session_counts = array_fill(0, 24, array_fill(0, 7, 0));

        // 合算した$days_pvを元の処理に使用する
        foreach ($days_pv as $entry) {
            // Convert UNIX timestamp to JST hour and day of the week
            $access_time_jst = $entry['access_time'] + 9 * 3600;
            $hour = gmdate('G', $access_time_jst);
            $weekday = gmdate('w', $access_time_jst);

            // Increment session count if pv is 1.
            if (1 === intval($entry['pv'])) {
                $session_counts[$hour][$weekday]++;
            }
        }

        $result = array();
        for ($hour = 0; $hour < 24; $hour++) {
            $hour_data = array($hour);
            for ($weekday = 0; $weekday < 7; $weekday++) {
                $hour_data[] = $session_counts[$hour][$weekday];
            }
            $result[] = $hour_data;
        }

        return $result;
    }

    public function aggregate_pv_sessions_by_device_and_os($days) {
        global $qahm_data_api;
        $endDate = strtotime('yesterday');
        $startDate = strtotime("-$days days", $endDate);

        $days_pv = array();

        // 日ごとにデータを取得し、$days_pvに追加する
        for ($date = $startDate; $date <= $endDate; $date = strtotime('+1 day', $date)) {
            $datestr = date('Y-m-d', $date);
            $dateterm = $this->get_dateterm_using_daystr( $datestr );
            $daily_pv = $qahm_data_api->select_data('vr_view_pv', '*', $dateterm, false, '', $this->tracking_id);
            if (isset($daily_pv[0]) && is_array($daily_pv[0])) {
				$days_pv = array_merge($days_pv, $daily_pv[0]); // $daily_pv[0] is the data array
			}
        }

        $pv_counts = array();
        $session_counts = array();
        $user_counts = array();

        // ユニークユーザー数を計算
        $unique_users = array();
        foreach ($days_pv as $entry) {
            $device_id = $entry['device_id'];
            $uaos = $entry['UAos'];
            $reader_id = $entry['reader_id'];
            if (! $uaos ) {
                $uaos = '(not set)';
            }
            if (!isset($unique_users[$device_id])) {
                $unique_users[$device_id] = array();
            }
            if (!isset($unique_users[$device_id][$uaos])) {
                $unique_users[$device_id][$uaos] = array();
            }

            $unique_users[$device_id][$uaos][$reader_id] = true;

            if (!isset($pv_counts[$device_id])) {
                $pv_counts[$device_id] = array();
                $session_counts[$device_id] = array();
            }
            if (!isset($pv_counts[$device_id][$uaos])) {
                $pv_counts[$device_id][$uaos] = 0;
                $session_counts[$device_id][$uaos] = 0;
            }

            // Increment PV count.
            $pv_counts[$device_id][$uaos]++;

            // Increment session count if pv is 1.
            if (1 === intval($entry['pv'])) {
                $session_counts[$device_id][$uaos]++;
            }

        }

        // ユニークユーザー数をカウント
        foreach ($unique_users as $device_id => $os_users) {
            foreach ($os_users as $uaos => $users) {
                if (!isset($user_counts[$device_id])) {
                    $user_counts[$device_id] = array();
                }
                $user_counts[$device_id][$uaos] = count($users);
            }
            if ( ! $user_counts[$device_id][$uaos] ) {
                $user_counts[$device_id][$uaos] = 0;
            } elseif ( $user_counts[$device_id][$uaos] === 'null') {
                $user_counts[$device_id][$uaos] = 0;
            }
        }


        $result = array();
        foreach ($pv_counts as $device_id => $os_counts) {
            foreach ($os_counts as $uaos => $pv_count) {
                $result[] = array(
                    'device_id' => $device_id,
                    'UAos' => $uaos,
                    'users' => $user_counts[$device_id][$uaos],
                    'sessions' => $session_counts[$device_id][$uaos],
                    'pv' => $pv_count,
                );
            }
        }
// 変換後の配列を作成
// device_idを変換するマッピング
        $device_id_mapping = array(
            1 => 'dsk',
            2 => 'tab',
            3 => 'smp'
        );

		return $result;

		/*
		以下のコードを実行するとデータが表示されなくなるためコメントアウト
		2024/09/12 imai
        $transformed_data = array();

        foreach ($result as $entry) {
            $transformed_data[] = array(
                $device_id_mapping[$entry['device_id']],
                $entry['UAos'],
                $entry['users'],
                $entry['sessions'],
                $entry['pv']
            );
        }

        return $transformed_data;
		*/
    }
    public function aggregate_pv_sessions_by_os_and_browser($days) {
        global $qahm_data_api;
        $endDate = strtotime('yesterday');
        $startDate = strtotime("-$days days", $endDate);

        $days_pv = array();

        // 日ごとにデータを取得し、$days_pvに追加する
        for ($date = $startDate; $date <= $endDate; $date = strtotime('+1 day', $date)) {
            $datestr = date('Y-m-d', $date);
            $dateterm = $this->get_dateterm_using_daystr($datestr);
            $daily_pv = $qahm_data_api->select_data('vr_view_pv', '*', $dateterm, false, '', $this->tracking_id);
			if (isset($daily_pv[0]) && is_array($daily_pv[0])) {
				$days_pv = array_merge($days_pv, $daily_pv[0]); // $daily_pv[0] is the data array
			}
        }
        $pv_counts = array();
        $session_counts = array();
        $user_counts = array();

        // ユニークユーザー数を計算
        $unique_users = array();
        foreach ($days_pv as $entry) {
            $uaos = $entry['UAos'];
            $uabrowser = $entry['UAbrowser']; // 新しいブラウザフィールドを追加
            if (!$uaos) {
                $uaos = '(not set)';
            }
            $uabrowser = $this->convert_browser($uabrowser); // ブラウザ名を変換
            $reader_id = $entry['reader_id'];

            if (!isset($unique_users[$uaos])) {
                $unique_users[$uaos] = array();
            }
            if (!isset($unique_users[$uaos][$uabrowser])) {
                $unique_users[$uaos][$uabrowser] = array();
            }

            $unique_users[$uaos][$uabrowser][$reader_id] = true;

            if (!isset($pv_counts[$uaos])) {
                $pv_counts[$uaos] = array();
                $session_counts[$uaos] = array();
            }
            if (!isset($pv_counts[$uaos][$uabrowser])) {
                $pv_counts[$uaos][$uabrowser] = 0;
                $session_counts[$uaos][$uabrowser] = 0;
            }

            // Increment PV count.
            $pv_counts[$uaos][$uabrowser]++;

            // Increment session count if pv is 1.
            if (1 === intval($entry['pv'])) {
                $session_counts[$uaos][$uabrowser]++;
            }
        }

        // ユニークユーザー数をカウント
        foreach ($unique_users as $uaos => $browsers) {
            foreach ($browsers as $uabrowser => $users) {
                if (!isset($user_counts[$uaos])) {
                    $user_counts[$uaos] = array();
                }
                $user_counts[$uaos][$uabrowser] = count($users);
            }
        }

        $result = array();
        foreach ($pv_counts as $uaos => $browsers) {
            foreach ($browsers as $uabrowser => $pv_count) {
                $result[] = array(
                    'UAos' => $uaos,
                    'UAbrowser' => $uabrowser,
                    'users' => $user_counts[$uaos][$uabrowser],
                    'sessions' => $session_counts[$uaos][$uabrowser],
                    'pv' => $pv_count,
                );
            }
        }

		return $result;

		/*
		以下のコードを実行するとデータが表示されなくなるためコメントアウト
		2024/09/12 imai

        // 変換後の配列を作成
        $transformed_data = array();

        foreach ($result as $entry) {
            $transformed_data[] = array(
                $entry['UAos'],
                $entry['UAbrowser'], // 追加したフィールドを変換結果に含める
                $entry['users'],
                $entry['sessions'],
                $entry['pv']
            );
        }

        return $transformed_data;
		*/
    }

}

