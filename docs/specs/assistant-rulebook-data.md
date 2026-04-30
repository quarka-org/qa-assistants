# アシスタントプラグイン生成ルールブック — データ編

QAL マテリアル（利用可能なデータ）、data_sources、transform パイプライン、tables の仕様と動作例。

> **基本編**（対話ルール・変数・翻訳・シーン）→ `assistant-rulebook-basic.md`
> **設定API編**（config_read / config_write）→ `assistant-rulebook-config-api.md`

---

## 利用可能なデータ（QAL マテリアル）

### allpv（ページビューデータ）— 22フィールド

| フィールド | 型 | 説明 |
|-----------|-----|------|
| `pv_id` | string | ページビューID |
| `reader_id` | string | 訪問者ID |
| `page_id` | string | ページID |
| `url` | string | ページURL |
| `title` | string | ページタイトル |
| `source_domain` | string | 参照元ドメイン |
| `referrer` | string | 参照元URL |
| `utm_source` | string | UTMソース |
| `utm_medium` | string | UTMメディア |
| `utm_campaign` | string | UTMキャンペーン |
| `ua` | string | ユーザーエージェント |
| `device_type` | string | デバイス種別（mobile/desktop/tablet） |
| `os` | string | OS |
| `browser` | string | ブラウザ |
| `language` | string | 言語 |
| `country_code` | string | 国コード |
| `access_time` | timestamp | アクセス日時 |
| `pv` | integer | PV数 |
| `speed_msec` | integer | 読み込み速度(ms) |
| `browse_sec` | integer | 滞在時間(秒) |
| `is_last` | boolean | セッション最終ページか |
| `is_newuser` | boolean | 新規ユーザーか |

### gsc（Google Search Console）— 12フィールド

| フィールド | 型 | 説明 |
|-----------|-----|------|
| `page_id` | string | ページID |
| `title` | string | ページタイトル |
| `url` | string | ページURL |
| `search_type` | string | 検索種別（Web/Image/Video） |
| `keyword` | string | 検索キーワード |
| `clicks_sum` | integer | クリック数 |
| `impressions_sum` | integer | 表示回数 |
| `ctr` | decimal | クリック率（0〜1の比率値。**直接使わず calc で計算すること** — 後述） |
| `position_wavg` | decimal | 平均掲載順位 |
| `first_position` | integer | 初回順位 |
| `latest_position` | integer | 最新順位 |
| `position_history` | json | 順位履歴 |

### goal_x（コンバージョンデータ）— 31フィールド

`goal_1`, `goal_2`, `goal_3`... の形式で指定。主要フィールド:

| フィールド | 型 | 説明 |
|-----------|-----|------|
| `session_index` | integer | セッション索引 |
| `pv_index` | integer | PV索引 |
| `pv_id` | string | ページビューID |
| `reader_id` | string | 訪問者ID |
| `UAos` | string | OS |
| `UAbrowser` | string | ブラウザ |
| `language` | string | 言語 |
| `is_reject` | boolean | リジェクトか |
| `page_id` | string | ページID |
| `url` | string | URL |
| `title` | string | タイトル |
| `access_time` | timestamp | 日時 |
| `device_id` | string | デバイスID |
| `version_id` | string | バージョンID |
| `source_id` | string | ソースID |
| `utm_source` | string | UTMソース |
| `source_domain` | string | 参照元ドメイン |
| `medium_id` | string | メディアID |
| `utm_medium` | string | UTMメディア |
| `campaign_id` | string | キャンペーンID |
| `utm_campaign` | string | UTMキャンペーン |
| `session_no` | integer | セッション番号 |
| `pv` | integer | PV数 |
| `speed_msec` | integer | 読み込み速度(ms) |
| `browse_sec` | integer | 滞在時間(秒) |
| `is_last` | boolean | セッション最終ページか |
| `is_newuser` | boolean | 新規ユーザーか |
| `is_raw_p` | boolean | 生データフラグ(p) |
| `is_raw_c` | boolean | 生データフラグ(c) |
| `is_raw_e` | boolean | 生データフラグ(e) |
| `version_no` | integer | バージョン番号 |

---

## data_sources

```json
"data_sources": {
  "source_name": {
    "type": "qal",
    "query": {
      "material": "allpv",
      "columns": ["page_id", "title", "url", "pv", "browse_sec"],
      "filter": {
        "tracking_id": { "eq": "$sys.tracking_id" },
        "date": { "between": ["$date_from", "$date_to"] }
      }
    },
    "into": "variable_name",
    "transform": [ ... ]
  }
}
```

### query

| フィールド | 必須 | 説明 |
|-----------|:---:|------|
| `material` | Yes | `"allpv"`, `"gsc"`, `"goal_1"` 等 |
| `columns` | No | 取得カラム（省略で全カラム） |
| `filter` | No | フィルタ条件 |

### フィルタ演算子

| 演算子 | 例 |
|--------|-----|
| `eq` | `{ "eq": "value" }` |
| `neq` | `{ "neq": null }` |
| `gt` / `gte` | `{ "gt": 100 }` |
| `lt` / `lte` | `{ "lt": 50 }` |
| `in` | `{ "in": ["a", "b"] }` |
| `contains` | `{ "contains": "google" }` |
| `prefix` | `{ "prefix": "https://example.com" }` |
| `between` | `{ "between": ["2026-01-01", "2026-02-10"] }` |

### 必須フィルタ

**全てのクエリに以下を含めること:**

```json
"filter": {
  "tracking_id": { "eq": "$sys.tracking_id" },
  "date": { "between": ["$date_from", "$date_to"] }
}
```

---

## Transform パイプライン

`data_sources` の `transform` に配列で定義。上から順に実行:

### sort

```json
{ "sort": { "pv": "desc" } }
```

### limit

```json
{ "limit": 20 }
```

### filter

```json
{ "filter": { "pv": { "gt": 100 } } }
{ "filter": { "platform": { "neq": null } } }
```

### group_by

```json
{
  "group_by": "page_id",
  "agg": { "pv": "sum", "browse_sec": "avg" },
  "keep": ["title", "url"]
}
```

集約関数: `sum`, `avg`, `count`, `min`, `max`, `first`

> **⚠ 比率フィールド（CTR 等）は group_by の agg に入れてはいけない。**
> `avg` で集計すると不正確な値になる。代わりに group_by 後に `calc` で計算する:
>
> ```json
> { "group_by": "keyword", "agg": { "clicks_sum": "sum", "impressions_sum": "sum" } },
> { "calc": "ctr", "expr": "clicks_sum / impressions_sum * 100" }
> ```
>
> `* 100` は percentage 型テーブル列で正しく表示するために必要（percentage 型は値をそのまま `%` 付きで表示する）。

### calc

```json
{ "calc": "pages_per_session", "expr": "pv / sessions" }
{ "calc": "total_pv", "expr": "sum(pv)", "scope": "global" }
```

四則演算 `+`, `-`, `*`, `/` と括弧 `()` が使える。
`scope: "global"` で全行集約（`sum()`, `avg()`, `count()`, `min()`, `max()`）。

### lookup

```json
{ "lookup": "social_platforms", "key": "source_domain", "into": "platform" }
```

`lookups` セクションの辞書名を参照。マッチしない値は `null` になる。

### set_var

```json
{ "set_var": "total_pv", "expr": "sum(pv)" }
{ "set_var": "top_page", "expr": "first(title)" }
```

---

## tables

```json
"tables": {
  "table_name": {
    "title": "t:tbl.pages",
    "source": "$page_data",
    "columns": [
      { "key": "title",      "label": "t:col.title",    "type": "string" },
      { "key": "url",        "label": "t:col.url",      "type": "link" },
      { "key": "pv",         "label": "t:col.pv",       "type": "integer" },
      { "key": "browse_sec", "label": "t:col.avg_time", "type": "duration" }
    ],
    "initial_sort": { "column": "pv", "direction": "desc" }
  }
}
```

### 列定義プロパティ

| プロパティ | 必須 | 説明 |
|-----------|:---:|------|
| `key` | Yes | データフィールド名 |
| `label` | Yes | 表示ラベル（`t:` 翻訳対応） |
| `type` | Yes | 表示型（下記参照） |
| `type_options` | No | 型固有のオプション |
| `width` | No | 列幅 |

### 列の型

| type | 表示 | type_options |
|------|------|-------------|
| `string` | テキスト | — |
| `integer` | 整数（カンマ区切り） | — |
| `float` | 小数 | `precision`（デフォルト: 2） |
| `percentage` | パーセント | `precision`（デフォルト: 2） |
| `currency` | 通貨 | `currency`（デフォルト: `'¥'`） |
| `date` | 日付（YYYY/MM/DD） | — |
| `datetime` | 日時 | — |
| `duration` | 時間（HH:MM:SS） | — |
| `link` | クリック可能リンク | `text`, `new_tab`（デフォルト: true） |
| `boolean` | Yes/No | `true_label`, `false_label` |
| `filesize` | ファイルサイズ | — |

> **⚠ `percentage` 型は値をそのまま `%` 付きで表示する。** 値は 0〜100 の範囲で格納すること。
> 0.146 → ✗ `0.15%`、14.6 → ✓ `14.60%`

全行 null の列は自動非表示。

### テーブルオプション

`options` で以下のデフォルトを上書き可能:

```json
{
  "per_page": 100,
  "sortable": true,
  "filtering": true,
  "exportable": true,
  "max_height": 300,
  "sticky_header": true
}
```

---

## 完全な動作例: ページ分析アシスタント

### manifest.json

```json
{
  "id": "qa-assistant-sample",
  "version": "1.0.0.0",
  "icon": "icon.png",
  "name": "t:meta.name",
  "description": "t:meta.description",

  "vars": {
    "date_from": "",
    "date_to": "",
    "page_data": [],
    "selected_url": "",
    "selected_title": "",
    "total_pv": 0,
    "page_count": 0
  },

  "data_sources": {
    "pages": {
      "type": "qal",
      "query": {
        "material": "allpv",
        "columns": ["page_id", "title", "url", "pv", "browse_sec"],
        "filter": {
          "tracking_id": { "eq": "$sys.tracking_id" },
          "date": { "between": ["$date_from", "$date_to"] }
        }
      },
      "into": "page_data",
      "transform": [
        {
          "group_by": "page_id",
          "agg": { "pv": "sum", "browse_sec": "avg" },
          "keep": ["title", "url"]
        },
        { "sort": { "pv": "desc" } },
        { "limit": 20 },
        { "set_var": "total_pv", "expr": "sum(pv)" },
        { "set_var": "page_count", "expr": "count(pv)" }
      ]
    }
  },

  "tables": {
    "page_summary": {
      "title": "t:tbl.pages",
      "source": "$page_data",
      "columns": [
        { "key": "title",      "label": "t:col.title",    "type": "string" },
        { "key": "url",        "label": "t:col.url",      "type": "link" },
        { "key": "pv",         "label": "t:col.pv",       "type": "integer" },
        { "key": "browse_sec", "label": "t:col.avg_time", "type": "duration" }
      ],
      "initial_sort": { "column": "pv", "direction": "desc" }
    }
  },

  "scenes": {
    "start": [
      { "message": "t:msg.welcome" },
      { "message": "t:msg.select_period" },
      { "choices": [
          { "label": "t:btn.1week",   "goto": "analyze",
            "set": { "date_from": "2026-02-03", "date_to": "2026-02-10" } },
          { "label": "t:btn.1month",  "goto": "analyze",
            "set": { "date_from": "2026-01-11", "date_to": "2026-02-10" } },
          { "label": "t:btn.3months", "goto": "analyze",
            "set": { "date_from": "2025-11-12", "date_to": "2026-02-10" } }
        ]
      }
    ],

    "analyze": [
      { "message": "t:msg.analyzing" },
      { "fetch": "pages", "on_error": "error" },
      { "if": { "var": "page_data", "is": "empty" }, "then": { "goto": "no_data" } },
      { "message": "t:msg.result_summary" },
      { "table": "page_summary" },
      { "message": "t:msg.select_page" },
      { "choices": {
          "from_data": "$page_data",
          "label_field": "title",
          "maxItems": 10,
          "goto": "detail",
          "set": { "selected_url": "$row.url", "selected_title": "$row.title" },
          "extra": [
            { "label": "t:btn.restart", "goto": "start", "clear": true }
          ]
        }
      }
    ],

    "detail": [
      { "message": "t:msg.detail_title" },
      { "message": "t:msg.detail_url" },
      { "choices": [
          { "label": "t:btn.back", "goto": "analyze" },
          { "label": "t:btn.restart", "goto": "start", "clear": true }
        ]
      }
    ],

    "no_data": [
      { "message": "t:msg.no_data" },
      { "choices": [
          { "label": "t:btn.restart", "goto": "start", "clear": true }
        ]
      }
    ],

    "error": [
      { "message": "t:msg.error" },
      { "choices": [
          { "label": "t:btn.restart", "goto": "start", "clear": true }
        ]
      }
    ]
  }
}
```

### lang/ja.json

```json
{
  "meta": {
    "name": "サンプルアシスタント",
    "description": "サイトのページパフォーマンスを分析します"
  },
  "msg": {
    "welcome": "こんにちは！**サンプルアシスタント**です。\nサイトのページパフォーマンスを分析します。",
    "select_period": "分析する期間を選んでください。",
    "analyzing": "{$date_from} から {$date_to} のデータを取得しています...",
    "result_summary": "**{$page_count|integer}ページ**を分析しました。\n合計 **{$total_pv|integer} PV**",
    "select_page": "詳細を見たいページを選んでください。",
    "detail_title": "**{$selected_title}** の詳細です。",
    "detail_url": "URL: [{$selected_url}]({$selected_url})",
    "no_data": "指定された期間にデータが見つかりませんでした。\n別の期間を試してみてください。",
    "error": "データの取得中にエラーが発生しました。\nしばらく待ってから再度お試しください。"
  },
  "btn": {
    "1week": "直近1週間",
    "1month": "直近1ヶ月",
    "3months": "直近3ヶ月",
    "back": "一覧に戻る",
    "restart": "最初に戻る"
  },
  "tbl": {
    "pages": "ページ一覧"
  },
  "col": {
    "title": "ページタイトル",
    "url": "URL",
    "pv": "PV",
    "avg_time": "平均滞在時間"
  }
}
```

### lang/en.json

```json
{
  "meta": {
    "name": "Sample Assistant",
    "description": "Analyzes your site's page performance"
  },
  "msg": {
    "welcome": "Hello! I'm the **Sample Assistant**.\nI'll analyze your site's page performance.",
    "select_period": "Please select a period to analyze.",
    "analyzing": "Fetching data from {$date_from} to {$date_to}...",
    "result_summary": "Analyzed **{$page_count|integer} pages**.\nTotal **{$total_pv|integer} PV**",
    "select_page": "Select a page to see details.",
    "detail_title": "Details for **{$selected_title}**",
    "detail_url": "URL: [{$selected_url}]({$selected_url})",
    "no_data": "No data found for the selected period.\nPlease try a different period.",
    "error": "An error occurred while fetching data.\nPlease try again later."
  },
  "btn": {
    "1week": "Past week",
    "1month": "Past month",
    "3months": "Past 3 months",
    "back": "Back to list",
    "restart": "Start over"
  },
  "tbl": {
    "pages": "Page List"
  },
  "col": {
    "title": "Page Title",
    "url": "URL",
    "pv": "PV",
    "avg_time": "Avg. Time on Page"
  }
}
```

---

## 応用例: SNS トラフィック分析（lookup + 動的choices）

lookups と動的 choices を組み合わせた高度な例:

### manifest.json（抜粋）

```json
{
  "lookups": {
    "social_platforms": {
      "twitter.com": "X", "x.com": "X", "t.co": "X",
      "facebook.com": "Facebook", "l.facebook.com": "Facebook",
      "youtube.com": "YouTube", "m.youtube.com": "YouTube",
      "instagram.com": "Instagram",
      "linkedin.com": "LinkedIn"
    }
  },

  "data_sources": {
    "social_traffic": {
      "type": "qal",
      "query": {
        "material": "allpv",
        "columns": ["source_domain", "pv", "browse_sec"],
        "filter": {
          "tracking_id": { "eq": "$sys.tracking_id" },
          "date": { "between": ["$date_from", "$date_to"] }
        }
      },
      "into": "traffic_data",
      "transform": [
        { "lookup": "social_platforms", "key": "source_domain", "into": "platform" },
        { "filter": { "platform": { "neq": null } } },
        { "group_by": "platform", "agg": { "pv": "sum", "browse_sec": "avg" } },
        { "sort": { "pv": "desc" } },
        { "set_var": "top_platform", "expr": "first(platform)" }
      ]
    }
  },

  "scenes": {
    "analyze": [
      { "fetch": "social_traffic" },
      { "if": { "var": "traffic_data", "is": "empty" }, "then": { "goto": "no_data" } },
      { "table": "traffic_summary" },
      { "message": "t:msg.top_result" },
      { "choices": {
          "from_data": "$traffic_data",
          "label_field": "platform",
          "goto": "detail",
          "set": { "selected_platform": "$row.platform" },
          "extra": [
            { "label": "t:btn.restart", "goto": "start", "clear": true }
          ]
        }
      }
    ]
  }
}
```

**ポイント:**
- `lookup` で `source_domain` → プラットフォーム名に変換
- `filter` で変換できなかった行（SNS以外）を除外
- `group_by` でプラットフォーム別に集約
- 動的 `choices` でプラットフォームをボタン化
- `$row.platform` で選択されたプラットフォームを変数にセット
