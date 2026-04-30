# アシスタントプラグイン マニフェスト仕様書

Version: 1.1.0.0
Date: 2026-02-27

---

## 1. 概要

アシスタントプラグインは、JSON マニフェストだけで動作する宣言的なシステムである。
コードは一切書かない。会話フロー、データ取得、加工、表示の全てをマニフェストで定義する。

### 設計原則

- **宣言的** — マニフェストは「何をするか」を記述し、「どうやるか」は Runtime が担う
- **QAL 中心** — データ取得は QAL（Query Abstraction Language）経由のみ
- **フロントエンド駆動** — 会話状態・変数は全てブラウザ側で管理。サーバーはステートレス
- **AI 生成可能** — AI がマニフェストを自動生成できる構造
- **命名規約** — 全て snake_case で統一

### プラットフォーム要件

マニフェストベースのアシスタントプラグインは QA ZERO / QA Assistants の両環境で動作する。
QAL は現在 QA ZERO でのみ読み込まれるが、QA Assistants でも読み込むよう loader を変更する。

### レガシーとの共存

旧プラグイン（main.php + config.json）はレガシーコードパスで動き続ける。
Manager が `manifest.json` の有無で自動判定し、新旧を切り替える。

#### ファイル分離方針

レガシーコードは新コードと混在させない。専用ファイルに隔離する：

```
class-qahm-assistant-manager.php        ← ルーティングのみ（manifest.json の有無で振り分け）
class-qahm-assistant-runtime-handler.php ← 新: マニフェスト Runtime のサーバー側
class-qahm-assistant-legacy.php          ← 旧: レガシープラグイン実行ロジック全て
```

Manager の振り分けロジック:

```
プラグイン検出
  ├── manifest.json あり → RuntimeHandler + フロントエンド Runtime で実行
  │   フロントエンドには manifest_url を渡す
  │
  └── manifest.json なし（main.php あり）
      → class-qahm-assistant-legacy.php を遅延読み込み
      → QAHM_Assistant_Legacy::execute() に委譲（静的メソッド）
```

Legacy ファイルはレガシープラグインが存在する時のみ `require` される。
全プラグインがマニフェスト方式に移行した場合、Legacy コードはメモリに一切載らない。
旧プラグインを廃止する際は `class-qahm-assistant-legacy.php` を削除するだけで完了する。

#### フロントエンドの判定

フロントエンド（assistant-ai.js）はファイル存在チェックができないため、
PHP がページ描画時にプラグイン情報に `manifest_url` を含める:

```javascript
// manifest_url があれば新方式、なければ旧方式
if ( assistantData.manifest_url ) {
    launchManifestRuntime( assistantSlug );
} else {
    ajaxConnectAssistant( assistantSlug, talkNo, 'start' );
}
```

---

## 2. ファイル構成

```
qa-assistant-{name}/
├── qa-assistant-{name}.php   # WP プラグインヘッダー（コメントのみ、コードなし）
├── manifest.json              # 全ての定義（これが本体）
├── icon.png                   # アイコン画像
└── lang/
    ├── en.json                # 英語翻訳（フォールバック）
    └── ja.json                # 日本語翻訳
```

### qa-assistant-{name}.php

WordPress がプラグインとして認識するために必要。ヘッダーコメントのみ：

```php
<?php
/**
 * Plugin Name: QA Assistant Example
 * Description: ページパフォーマンスを分析するアシスタント
 * Version: 1.0.0.0
 * Author: Quarka
 * Text Domain: qa-assistant-example
 */
defined( 'ABSPATH' ) || exit;
```

---

## 3. manifest.json 構造

```json
{
  "id": "qa-assistant-example",
  "version": "1.0.0.0",
  "icon": "icon.png",
  "name": "t:meta.name",
  "description": "t:meta.description",

  "vars": { ... },
  "lookups": { ... },
  "data_sources": { ... },
  "tables": { ... },
  "charts": { ... },
  "scenes": { ... }
}
```

### トップレベルフィールド

| フィールド | 必須 | 説明 |
|-----------|:---:|------|
| `id` | Yes | プラグイン識別子（ディレクトリ名と一致） |
| `version` | Yes | 4桁バージョン（例: `1.0.0.0`） |
| `icon` | Yes | アイコン画像ファイル名 |
| `name` | Yes | 表示名（`t:` で翻訳参照可） |
| `description` | Yes | 説明文（`t:` で翻訳参照可） |
| `vars` | Yes | ユーザー変数の初期値 |
| `lookups` | No | 値の変換辞書 |
| `data_sources` | Yes | データ取得定義 |
| `tables` | No | テーブル定義 |
| `charts` | No | グラフ定義（将来実装） |
| `scenes` | Yes | 会話フロー定義 |

---

## 4. 変数システム

### 4.1 スコープ

| プレフィックス | スコープ | 例 | 有効範囲 |
|-------------|---------|-----|---------|
| `$` | ユーザー変数 | `$date_from`, `$page_data` | どこでも |
| `$sys.` | システム変数 | `$sys.tracking_id` | どこでも |
| `$row.` | 行コンテキスト | `$row.platform` | 動的 choices の `set` 内のみ |

### 4.2 ユーザー変数

`vars` セクションで初期値を定義。シーンの `set` で上書き可能：

```json
"vars": {
  "date_from": "",
  "date_to": "",
  "page_data": [],
  "selected": "",
  "total_pv": 0
}
```

### 4.3 システム変数

サーバーが自動注入する。マニフェストでは定義しない：

| 変数 | 説明 |
|------|------|
| `$sys.tracking_id` | サイト識別子（QAL クエリに必須） |
| `$sys.locale` | 言語コード（`ja`, `en`） |

### 4.4 メッセージ内の変数展開

メッセージ文字列では `{$var}` 記法で展開する：

```json
"message": "{$date_from} から {$date_to} のデータです。合計 {$total_pv|integer} PV。"
```

| 記法 | 説明 | 例 |
|------|------|-----|
| `{$var}` | そのまま展開 | `{$date_from}` → `2026-01-01` |
| `{$var\|integer}` | 整数（カンマ区切り） | `{$total_pv\|integer}` → `1,234` |
| `{$var\|float}` | 小数 | `{$rate\|float}` → `3.14` |
| `{$var\|percentage}` | パーセント | `{$bounce\|percentage}` → `25.0%` |
| `{$var\|duration}` | 時間 | `{$time\|duration}` → `00:03:45` |

### 4.5 JSON 値での変数参照

JSON 値として変数を参照する場合は `$` プレフィックスを付ける（`{}` 不要）：

```json
"filter": { "tracking_id": { "eq": "$sys.tracking_id" } }
"set": { "selected": "$row.platform" }
```

格納先の指定（`into` 等）は変数名のみ（`$` 不要）：

```json
"into": "page_data"
```

`$` が付いていれば「読み取り」、付いていなければ「書き込み先」と区別できる。

---

## 5. 翻訳システム

### 5.1 翻訳参照

`t:` プレフィックスで翻訳キーを参照する：

```json
"name": "t:meta.name"
"message": "t:msg.welcome"
"label": "t:btn.restart"
```

### 5.2 翻訳ファイル

`lang/{locale}.json` にネスト構造で定義。フォールバック順: `{locale}.json` → `en.json`：

```json
{
  "meta": {
    "name": "ページ分析アシスタント",
    "description": "サイトのページパフォーマンスを分析します"
  },
  "msg": {
    "welcome": "期間を選んでください。",
    "analyzing": "データを分析しています...",
    "no_data": "データが見つかりませんでした。"
  },
  "btn": {
    "restart": "最初に戻る"
  },
  "tbl": {
    "pages": "ページ一覧"
  },
  "col": {
    "title": "ページタイトル",
    "pv": "PV"
  }
}
```

### 5.3 翻訳内の変数展開

翻訳テキスト内でも `{$var}` が使える：

```json
"analyzing": "{$date_from} から {$date_to} のデータを分析しています..."
```

---

## 6. data_sources

データ取得と加工を定義する。

### 6.1 構造

```json
"data_sources": {
  "pages": {
    "type": "qal",
    "query": {
      "material": "allpv",
      "columns": ["page_id", "title", "url", "pv", "sessions"],
      "filter": {
        "tracking_id": { "eq": "$sys.tracking_id" },
        "date": { "between": ["$date_from", "$date_to"] }
      }
    },
    "into": "page_data",
    "transform": [
      { "group_by": "page_id", "agg": { "pv": "sum", "sessions": "sum" }, "keep": ["title", "url"] },
      { "sort": { "pv": "desc" } },
      { "limit": 20 }
    ]
  }
}
```

| フィールド | 必須 | 説明 |
|-----------|:---:|------|
| `type` | Yes | `"qal"` 固定（現時点） |
| `query` | Yes | QAL クエリ定義 |
| `into` | Yes | 結果を格納する変数名（`$` 不要） |
| `transform` | No | 加工パイプライン（配列、順序実行） |

### 6.2 QAL クエリ

```json
"query": {
  "material": "allpv",
  "columns": ["page_id", "title", "url", "pv"],
  "filter": {
    "tracking_id": { "eq": "$sys.tracking_id" },
    "date": { "between": ["$date_from", "$date_to"] }
  }
}
```

| フィールド | 必須 | 説明 |
|-----------|:---:|------|
| `material` | Yes | QAL マテリアル名（`allpv`, `gsc`, `goal_x`） |
| `columns` | No | 取得カラム（省略時は全カラム） |
| `filter` | No | フィルタ条件 |

#### QAL マテリアル

| マテリアル | フィールド数 | 主なフィールド |
|-----------|:----------:|--------------|
| `allpv` | 22 | page_id, title, url, pv, sessions, bounce_rate, avg_time, ... |
| `gsc` | 12 | query, page, clicks, impressions, position, ... |
| `goal_x` | 31 | page_id, url, title, reader_id, source_domain, pv, browse_sec, ... |

#### フィルタ演算子

| 演算子 | 説明 | 例 |
|--------|------|-----|
| `eq` | 等しい | `{ "eq": "value" }` |
| `neq` | 等しくない | `{ "neq": null }` |
| `gt` / `gte` | より大きい / 以上 | `{ "gt": 100 }` |
| `lt` / `lte` | より小さい / 以下 | `{ "lt": 50 }` |
| `in` | いずれか | `{ "in": ["a", "b"] }` |
| `contains` | 部分一致 | `{ "contains": "google" }` |
| `prefix` | 前方一致 | `{ "prefix": "https://example.com" }` |
| `between` | 範囲 | `{ "between": ["2026-01-01", "2026-02-09"] }` |

---

## 7. Transform パイプライン

`data_sources` 内の `transform` に配列で定義。上から順に実行される。

### 7.1 操作一覧

#### v1（初回実装）

| 操作 | 説明 |
|------|------|
| `sort` | ソート |
| `limit` | 行数制限 |
| `filter` | 条件フィルタ |
| `group_by` | グループ化 + 集約 |
| `calc` | 計算フィールド追加 |
| `lookup` | 辞書による値変換 |
| `set_var` | 結果を変数にセット |

#### v2（将来実装）

| 操作 | 説明 |
|------|------|
| `join` | 別データソースとの結合 |
| `rename` | フィールド名変更 |
| `pick` | フィールド選択 |

### 7.2 sort

```json
{ "sort": { "pv": "desc" } }
{ "sort": { "title": "asc" } }
```

### 7.3 limit

```json
{ "limit": 20 }
```

### 7.4 filter

```json
{ "filter": { "pv": { "gt": 100 } } }
{ "filter": { "platform": { "eq": "$selected" } } }
{ "filter": { "engine": { "neq": null } } }
```

フィルタ演算子は QAL と同じ（セクション 6.2 参照）。

### 7.5 group_by

```json
{
  "group_by": "page_id",
  "agg": {
    "pv": "sum",
    "sessions": "sum",
    "bounce_rate": "avg"
  },
  "keep": ["title", "url"]
}
```

| フィールド | 必須 | 説明 |
|-----------|:---:|------|
| `group_by` | Yes | グループ化キー |
| `agg` | Yes | 集約関数（`sum`, `avg`, `count`, `min`, `max`, `first`） |
| `keep` | No | グループ内の最初の値を保持するフィールド |

> **⚠ 比率フィールド（CTR 等）は `agg` に入れてはいけない。** `avg` で集計すると不正確な値になる。代わりに group_by 後に `calc` で計算する：
> ```json
> { "group_by": "keyword", "agg": { "clicks_sum": "sum", "impressions_sum": "sum" } },
> { "calc": "ctr", "expr": "clicks_sum / impressions_sum * 100" }
> ```

> **⚠ QAL は日付×キー単位でデータを返す。** ドリルダウン等の詳細クエリにも必ず `group_by` を入れること。省略すると同じ URL が日付分だけ重複する。

### 7.6 calc

行ごとの計算フィールドを追加：

```json
{ "calc": "pages_per_session", "expr": "pv / sessions" }
{ "calc": "change_rate", "expr": "(pv - prev_pv) / prev_pv" }
```

グローバル集約（全行を対象）：

```json
{ "calc": "total_pv", "expr": "sum(pv)", "scope": "global" }
```

#### 式で使える演算子・関数

- 四則演算: `+`, `-`, `*`, `/`
- 括弧: `( )`
- フィールド参照: フィールド名をそのまま記述
- グローバル関数（`scope: "global"` 時）: `sum()`, `avg()`, `count()`, `min()`, `max()`

`eval()` / `Function()` は使用しない。安全な再帰下降パーサーで評価する。

### 7.7 lookup

lookups セクションの辞書を使って値を変換：

```json
{ "lookup": "social_platforms", "key": "source_domain", "into": "platform" }
```

| フィールド | 必須 | 説明 |
|-----------|:---:|------|
| `lookup` | Yes | lookups セクションの辞書名 |
| `key` | Yes | 変換元のフィールド名 |
| `into` | Yes | 変換結果を格納するフィールド名 |

辞書にマッチしない値は `null` になる。`filter` と組み合わせて除外可能：

```json
{ "lookup": "social_platforms", "key": "source_domain", "into": "platform" },
{ "filter": { "platform": { "neq": null } } }
```

### 7.8 set_var

transform 結果を変数にセット：

```json
{ "set_var": "total_pv", "expr": "sum(pv)" }
{ "set_var": "top_page", "expr": "first(title)" }
```

---

## 8. lookups

値の変換辞書。トップレベルに定義し、transform の `lookup` 操作から名前で参照する。
複数の data_sources から共有できる。

```json
"lookups": {
  "social_platforms": {
    "twitter.com":    "X",
    "x.com":          "X",
    "t.co":           "X",
    "facebook.com":   "Facebook",
    "l.facebook.com": "Facebook",
    "youtube.com":    "YouTube",
    "m.youtube.com":  "YouTube",
    "instagram.com":  "Instagram",
    "linkedin.com":   "LinkedIn"
  },
  "search_engines": {
    "google.com":    "Google",
    "google.co.jp":  "Google",
    "bing.com":      "Bing",
    "yahoo.co.jp":   "Yahoo"
  }
}
```

---

## 9. tables

テーブル表示の定義。qaTable アダプタ経由で描画される。

```json
"tables": {
  "page_summary": {
    "title": "t:tbl.pages",
    "source": "$page_data",
    "columns": [
      { "key": "title",             "label": "t:col.title",    "type": "string" },
      { "key": "url",               "label": "t:col.url",      "type": "link" },
      { "key": "pv",                "label": "t:col.pv",       "type": "integer" },
      { "key": "sessions",          "label": "t:col.sessions",  "type": "integer" },
      { "key": "pages_per_session", "label": "t:col.pps",      "type": "float" },
      { "key": "bounce_rate",       "label": "t:col.bounce",   "type": "percentage" },
      { "key": "avg_time",          "label": "t:col.avg_time", "type": "duration" }
    ],
    "initial_sort": { "column": "pv", "direction": "desc" }
  }
}
```

### テーブルフィールド

| フィールド | 必須 | 説明 |
|-----------|:---:|------|
| `title` | No | テーブルタイトル |
| `source` | Yes | データ変数を参照（`$` 付き） |
| `columns` | Yes | 列定義の配列 |
| `initial_sort` | No | 初期ソート |
| `options` | No | qaTable オプション上書き |

### 列定義

| フィールド | 必須 | 説明 |
|-----------|:---:|------|
| `key` | Yes | データフィールド名 |
| `label` | Yes | 表示ラベル（`t:` で翻訳参照可） |
| `type` | Yes | 表示型 |
| `type_options` | No | 型固有のオプション |
| `width` | No | 列幅 |

### 列の型

| type | 表示 | type_options |
|------|------|-------------|
| `string` | テキスト | — |
| `integer` | 整数（カンマ区切り） | — |
| `float` | 小数 | `precision`（デフォルト: 2） |
| `percentage` | パーセント（値は 0〜100 で格納） | `precision`（デフォルト: 2） |
| `currency` | 通貨 | `currency`（デフォルト: `'¥'`） |
| `date` | 日付（YYYY/MM/DD） | — |
| `datetime` | 日時 | — |
| `duration` | 時間（HH:MM:SS） | — |
| `link` | クリック可能なリンク | `text`, `new_tab`（デフォルト: true） |
| `boolean` | Yes/No | `true_label`, `false_label` |
| `filesize` | ファイルサイズ | — |

### 空列の自動非表示

全行で値が `null` / `undefined` / `""` の列は自動的に非表示になる。

### デフォルトオプション

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

`options` で上書き可能。

---

## 10. scenes

会話フローを定義する。各シーンはステップの配列。

```json
"scenes": {
  "start": [
    { "message": "t:msg.welcome" },
    { "choices": [...] }
  ],
  "analyze": [
    { "message": "t:msg.analyzing" },
    { "fetch": "pages" },
    { "if": { "var": "page_data", "is": "empty" }, "then": { "goto": "no_data" } },
    { "table": "page_summary" },
    { "message": "t:msg.advice" },
    { "choices": [...] }
  ],
  "no_data": [
    { "message": "t:msg.no_data" },
    { "choices": [...] }
  ]
}
```

実行は `start` シーンから開始。

### 10.1 ステップ一覧

| ステップ | 説明 |
|---------|------|
| `message` | メッセージ表示（タイプライター効果） |
| `choices` | 選択肢ボタン表示 |
| `form` | テキスト入力・選択フォーム（セクション14参照） |
| `fetch` | データ取得 |
| `table` | テーブル表示 |
| `chart` | グラフ表示（将来実装） |
| `if` / `then` | 条件分岐 |
| `goto` | シーン遷移 |
| `set` | 変数セット |

### 10.2 message

```json
{ "message": "t:msg.welcome" }
{ "message": "t:msg.result" }
```

翻訳解決 → 変数展開 → 限定マークダウン処理の順で処理される。

#### 限定マークダウン

| 記法 | 結果 |
|------|------|
| `**text**` | **太字** |
| `[text](url)` | リンク |
| `\n` | 改行 |

HTML タグは全てエスケープ。`javascript:` スキームはブロック。

### 10.3 choices（静的）

```json
{ "choices": [
    { "label": "t:btn.14days", "goto": "analyze",
      "set": { "date_from": "2026-01-26", "date_to": "2026-02-09" } },
    { "label": "t:btn.restart", "goto": "start", "clear": true }
  ]
}
```

| プロパティ | 必須 | 説明 |
|-----------|:---:|------|
| `label` | Yes | ボタンテキスト（`t:` で翻訳参照可） |
| `goto` | Yes | 遷移先シーン名 |
| `set` | No | クリック時にセットする変数 |
| `clear` | No | `true` で会話エリアをクリアしてから遷移 |

### 10.4 choices（動的）

データから自動でボタンを生成：

```json
{ "choices": {
    "from_data": "$traffic_data",
    "label_field": "platform",
    "goto": "detail",
    "set": { "selected": "$row.platform" },
    "maxItems": 10,
    "extra": [
      { "label": "t:btn.restart", "goto": "start", "clear": true }
    ]
  }
}
```

| プロパティ | 必須 | 説明 |
|-----------|:---:|------|
| `from_data` | Yes | データ変数を参照 |
| `label_field` | Yes | ボタンラベルに使うフィールド名 |
| `goto` | Yes | 遷移先シーン名 |
| `set` | No | `$row.field` でその行の値を変数にセット |
| `maxItems` | No | 生成するボタンの上限数 |
| `extra` | No | 動的ボタンの後に追加する静的ボタン |

### 10.5 fetch

```json
{ "fetch": "pages" }
{ "fetch": "pages", "on_error": "error_scene" }
```

`data_sources` の定義名を指定。QAL クエリ実行 → transform 適用 → `into` 変数にセット。

`on_error` 省略時はデフォルトのエラーメッセージを表示。

### 10.6 table

```json
{ "table": "page_summary" }
```

`tables` の定義名を指定。データは `source` で参照する変数から取得。

### 10.7 if / then

```json
{ "if": { "var": "page_data", "is": "empty" }, "then": { "goto": "no_data" } }
```

| 条件（`is`） | 説明 |
|-------------|------|
| `empty` | 配列が空 or 値が空文字 / null / undefined |
| `not_empty` | 空でない |
| `eq` | 値が一致（`"value"` フィールドで指定） |
| `neq` | 値が不一致 |

```json
{ "if": { "var": "selected", "is": "eq", "value": "Google" }, "then": { "goto": "google_detail" } }
```

### 10.8 goto

```json
{ "goto": "analyze" }
```

指定シーンに即座に遷移。

### 10.9 set

シーン内で変数を直接セット：

```json
{ "set": { "selected": "", "page_data": [] } }
```

---

## 11. 実行フロー

### 11.1 起動

```
1. ユーザーがアシスタントを選択
2. assistant-ai.js がプラグイン情報の manifest_url を確認
3. manifest_url あり → AJAX で manifest + translations + system vars を取得
4. Runtime インスタンスを生成
5. 'start' シーンから実行開始
```

manifest_url がない場合は旧方式（Legacy Handler 経由）で実行。

### 11.2 シーン実行

```
1. シーンのステップ配列を先頭から順に実行
2. message → UI に表示（タイプライター効果）
3. choices → ボタン表示、ユーザーのクリックを待つ
   → set で変数セット → goto で次のシーンへ
4. fetch → QAL クエリ送信 → transform 適用 → into 変数にセット
5. table → データ変数を参照してテーブル描画
6. if/then → 条件評価、true なら then を実行
7. goto → 指定シーンに遷移
```

### 11.3 データ取得

```
1. fetch ステップ → data_sources の定義を参照
2. query 内の変数を解決（$var → 実際の値）
3. AJAX で RuntimeHandler に送信
4. RuntimeHandler が QAL Executor に転送
5. QAL が key-value データを返却
6. フロントエンドで transform パイプラインを順に適用
7. 結果を into 変数にセット
```

---

## 12. サーバーサイド

### 12.1 RuntimeHandler（新方式）

マニフェストベースのプラグイン専用。

#### 責務

- マニフェスト + 翻訳 + システム変数の配信
- QAL クエリの簡略形式からネイティブ形式への変換
- QAL クエリの受付と実行
- データの加工は**一切しない**（Transform はフロントエンド側）

#### QAL クエリ変換

マニフェストの簡略クエリ:

```json
{
  "material": "allpv",
  "columns": ["page_id", "title"],
  "filter": { "tracking_id": { "eq": "$sys.tracking_id" } }
}
```

を、RuntimeHandler が QAL Executor のネイティブ形式に変換する:

```json
{
  "tracking_id": "...",
  "materials": [{ "name": "allpv" }],
  "time": { "start": "...", "end": "...", "tz": "Asia/Tokyo" },
  "make": { "view": { "from": ["allpv"], "keep": ["allpv.page_id", "allpv.title"] } },
  "result": { "use": "view" }
}
```

マニフェスト作者は簡略形式だけを知っていれば良い。変換ロジックは RuntimeHandler の内部実装。

#### AJAX エンドポイント

**マニフェスト取得:**

```
POST wp-admin/admin-ajax.php
action: qahm_get_assistant_manifest
slug: qa-assistant-example
nonce: (検証用)

Response: {
  manifest: { ... },
  translations: { ... },
  system_vars: {
    tracking_id: "all",
    locale: "ja"
  }
}
```

**データ取得:**

```
POST wp-admin/admin-ajax.php
action: qahm_fetch_assistant_data
type: qal
query: { material: "allpv", filter: { ... } }
nonce: (検証用)

Response: {
  success: true,
  data: [ { page_id: 1, title: "...", pv: 100 }, ... ]
}
```

### 12.2 Legacy Handler（旧方式）

旧プラグイン（main.php + config.json）専用。Manager から遅延読み込みされる。

```php
// Manager 内の振り分け
if ( file_exists( $plugin_dir . '/manifest.json' ) ) {
    // 新方式: manifest_url をフロントエンドに渡す
} else {
    // 旧方式: Legacy Handler を遅延読み込みして委譲
    require_once 'class-qahm-assistant-legacy.php';
    return QAHM_Assistant_Legacy::execute( $config, $state, $tracking_id );
}
```

Legacy Handler は静的メソッドで実装。インスタンスを作らない：

```php
class QAHM_Assistant_Legacy extends QAHM_Base {

    public static function execute( $config, $state, $tracking_id = 'all' ) {
        // $execute 配列、PHP セッション、QAHM_Assistant 基底クラス等
        // 旧プラグインの実行ロジックを全てここに隔離
    }
}
```

全プラグインがマニフェスト方式に移行した場合、このファイルは `require` されず
メモリに一切載らない。廃止時はファイル削除のみで完了。

---

## 13. 完全な例: SNS トラフィック分析

### manifest.json

```json
{
  "id": "qa-assistant-social-media",
  "version": "1.0.0.0",
  "icon": "icon.png",
  "name": "t:meta.name",
  "description": "t:meta.description",

  "vars": {
    "date_from": "",
    "date_to": "",
    "traffic_data": [],
    "landing_data": [],
    "selected_platform": "",
    "top_platform": ""
  },

  "lookups": {
    "social_platforms": {
      "twitter.com":    "X",
      "x.com":          "X",
      "t.co":           "X",
      "facebook.com":   "Facebook",
      "l.facebook.com": "Facebook",
      "youtube.com":    "YouTube",
      "m.youtube.com":  "YouTube",
      "instagram.com":  "Instagram",
      "linkedin.com":   "LinkedIn",
      "pinterest.com":  "Pinterest"
    }
  },

  "data_sources": {
    "social_traffic": {
      "type": "qal",
      "query": {
        "material": "allpv",
        "columns": ["source_domain", "sessions", "pv", "avg_time"],
        "filter": {
          "tracking_id": { "eq": "$sys.tracking_id" },
          "date": { "between": ["$date_from", "$date_to"] }
        }
      },
      "into": "traffic_data",
      "transform": [
        { "lookup": "social_platforms", "key": "source_domain", "into": "platform" },
        { "filter": { "platform": { "neq": null } } },
        { "group_by": "platform", "agg": { "sessions": "sum", "pv": "sum", "avg_time": "avg" } },
        { "calc": "pages_per_session", "expr": "pv / sessions" },
        { "sort": { "sessions": "desc" } },
        { "set_var": "top_platform", "expr": "first(platform)" }
      ]
    },
    "social_landing": {
      "type": "qal",
      "query": {
        "material": "allpv",
        "columns": ["source_domain", "url", "title", "sessions", "pv", "avg_time"],
        "filter": {
          "tracking_id": { "eq": "$sys.tracking_id" },
          "date": { "between": ["$date_from", "$date_to"] }
        }
      },
      "into": "landing_data",
      "transform": [
        { "lookup": "social_platforms", "key": "source_domain", "into": "platform" },
        { "filter": { "platform": { "eq": "$selected_platform" } } },
        { "group_by": "url", "agg": { "sessions": "sum", "pv": "sum", "avg_time": "avg" }, "keep": ["title"] },
        { "calc": "pages_per_session", "expr": "pv / sessions" },
        { "sort": { "sessions": "desc" } }
      ]
    }
  },

  "tables": {
    "traffic_summary": {
      "title": "t:tbl.traffic",
      "source": "$traffic_data",
      "columns": [
        { "key": "platform",          "label": "t:col.platform",     "type": "string" },
        { "key": "sessions",          "label": "t:col.sessions",     "type": "integer" },
        { "key": "pv",                "label": "t:col.pv",           "type": "integer" },
        { "key": "pages_per_session", "label": "t:col.pps",          "type": "float" },
        { "key": "avg_time",          "label": "t:col.avg_time",     "type": "duration" }
      ],
      "initial_sort": { "column": "sessions", "direction": "desc" }
    },
    "landing_pages": {
      "title": "t:tbl.landing",
      "source": "$landing_data",
      "columns": [
        { "key": "title",             "label": "t:col.page",         "type": "string" },
        { "key": "url",               "label": "t:col.url",          "type": "link" },
        { "key": "sessions",          "label": "t:col.sessions",     "type": "integer" },
        { "key": "pages_per_session", "label": "t:col.pps",          "type": "float" },
        { "key": "avg_time",          "label": "t:col.avg_time",     "type": "duration" }
      ],
      "initial_sort": { "column": "sessions", "direction": "desc" }
    }
  },

  "scenes": {
    "start": [
      { "message": "t:msg.welcome" },
      { "choices": [
          { "label": "t:btn.14days",  "goto": "analyze",
            "set": { "date_from": "2026-01-26", "date_to": "2026-02-09" } },
          { "label": "t:btn.28days",  "goto": "analyze",
            "set": { "date_from": "2026-01-12", "date_to": "2026-02-09" } },
          { "label": "t:btn.90days",  "goto": "analyze",
            "set": { "date_from": "2025-11-11", "date_to": "2026-02-09" } }
        ]
      }
    ],
    "analyze": [
      { "message": "t:msg.analyzing" },
      { "fetch": "social_traffic" },
      { "if": { "var": "traffic_data", "is": "empty" }, "then": { "goto": "no_data" } },
      { "table": "traffic_summary" },
      { "message": "t:msg.top_platform" },
      { "message": "t:msg.select_platform" },
      { "choices": {
          "from_data": "$traffic_data",
          "label_field": "platform",
          "goto": "platform_detail",
          "set": { "selected_platform": "$row.platform" },
          "extra": [
            { "label": "t:btn.restart", "goto": "start", "clear": true }
          ]
        }
      }
    ],
    "platform_detail": [
      { "message": "t:msg.landing_title" },
      { "fetch": "social_landing" },
      { "if": { "var": "landing_data", "is": "empty" }, "then": { "goto": "analyze" } },
      { "table": "landing_pages" },
      { "message": "t:msg.landing_advice" },
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
    ]
  }
}
```

---

## 14. form ステップ

テキスト入力・選択を受け付けるフォームを表示する。Issue #897 で追加。

### 14.1 基本構造

```json
{
  "form": {
    "fields": [
      {
        "key": "search_text",
        "type": "text",
        "label": "t:form.keyword_label",
        "placeholder": "t:form.keyword_placeholder",
        "required": true
      },
      {
        "key": "target_url",
        "type": "url",
        "label": "t:form.url_label",
        "placeholder": "https://example.com/",
        "required": true
      },
      {
        "key": "match_type",
        "type": "radio",
        "label": "t:form.match_label",
        "options": [
          { "value": "prefix", "label": "t:form.prefix" },
          { "value": "contains", "label": "t:form.contains" },
          { "value": "eq", "label": "t:form.exact" }
        ],
        "default": "prefix"
      },
      {
        "key": "sort_field",
        "type": "select",
        "label": "t:form.sort_label",
        "options": [
          { "value": "pv", "label": "t:form.sort_pv" },
          { "value": "sessions", "label": "t:form.sort_sessions" }
        ],
        "default": "pv"
      }
    ],
    "submit": "t:btn.search",
    "cancel": {
      "label": "t:btn.back",
      "goto": "start"
    }
  }
}
```

### 14.2 field types（v1）

| type | HTML 要素 | 用途 | placeholder | required |
|------|-----------|------|:-----------:|:--------:|
| `text` | `<input type="text">` | 汎用テキスト入力 | YES | YES |
| `url` | `<input type="url">` | URL 入力（ブラウザの形式チェックあり） | YES | YES |
| `radio` | `<input type="radio">` | 少数の選択肢（2〜3個向き） | — | YES |
| `select` | `<select>` | 多数の選択肢（4個以上向き） | — | — |

`select` は常にいずれかの option が選択されるため `required` は不要。

### 14.3 field プロパティ

| プロパティ | 型 | 必須 | 対象 type | 説明 |
|---|---|:---:|---|---|
| `key` | string | YES | 全て | 値を格納する変数名。submit 後 `vars` に同名で格納される |
| `type` | string | YES | 全て | フィールドの種類（`text`, `url`, `radio`, `select`） |
| `label` | string | NO | 全て | ラベルテキスト（`t:` 翻訳対応） |
| `placeholder` | string | NO | text, url | プレースホルダー（`t:` 翻訳対応）。radio, select では無効 |
| `required` | boolean | NO | text, url, radio | 必須入力（デフォルト `false`）。select では無効 |
| `options` | array | YES* | radio, select | 選択肢の配列（後述）。*radio, select では必須 |
| `default` | string | NO | 全て | 初期値。text/url は入力欄に設定。radio/select は一致する option を選択状態にする |

### 14.4 options 配列

`radio` と `select` の選択肢を定義する:

```json
"options": [
  { "value": "prefix", "label": "t:form.prefix" },
  { "value": "contains", "label": "t:form.contains" },
  { "value": "eq", "label": "t:form.exact" }
]
```

| プロパティ | 型 | 必須 | 説明 |
|---|---|:---:|---|
| `value` | string | YES | 選択時に `vars` に格納される値 |
| `label` | string | YES | 表示テキスト（`t:` 翻訳対応） |

### 14.5 submit / cancel

**submit**（必須）:
- ボタンラベル文字列（`t:` 翻訳対応）
- クリック → HTML5 バリデーション（`reportValidity()`）→ 通過なら各フィールドの値を `vars` に格納 → 同一シーン内の次のステップへ進む

**cancel**（省略可）:
- `{ "label": "ラベル", "goto": "シーン名" }` で定義
- `label`: ボタンテキスト（`t:` 翻訳対応）
- `goto`: 遷移先シーン名
- `vars` は一切更新せず、指定シーンに遷移する
- 省略時はキャンセルボタンを表示しない

### 14.6 バリデーション

HTML5 標準のバリデーション（`reportValidity()`）を使用する。バリデーション不通過時は submit をブロックし、ブラウザのエラー表示が出る。

| type | バリデーション内容 |
|------|-------------------|
| `text` + `required` | 空文字を許可しない |
| `url`（required 有無問わず） | 入力があれば URL 形式チェック（ブラウザ標準） |
| `url` + `required` | 上記 + 空文字を許可しない |
| `radio` + `required` | いずれか1つの選択を強制 |
| `select` | 常にいずれかの option が選択済み（バリデーション不要） |

### 14.7 値の格納と後続ステップでの利用

submit 後、各フィールドの `key` と入力値のペアが `vars` にマージされる:

```
フォーム入力:
  target_url = "https://example.com/"
  match_type = "prefix"

    ↓ submit

vars に格納:
  vars.target_url = "https://example.com/"
  vars.match_type = "prefix"

    ↓ 後続の fetch ステップ

filter 内で変数展開:
  "url": { "prefix": "$target_url" }
  → "url": { "prefix": "https://example.com/" }
```

form → fetch → table の流れは同一シーン内で連続して定義する:

```json
"url_search": [
  { "message": "t:msg.enter_url" },
  { "form": { ... } },
  { "fetch": "filtered_pages" },
  { "message": "t:msg.results" },
  { "table": "page_results" },
  { "choices": [...] }
]
```

form の submit 後、fetch → table と自動的に進む。cancel 時はシーン遷移するため後続ステップは実行されない。

### 14.8 翻訳対応プロパティ

以下のプロパティで `t:` プレフィックスが使用可能:

| プロパティ | 例 |
|---|---|
| `fields[].label` | `"t:form.url_label"` |
| `fields[].placeholder` | `"t:form.url_placeholder"` |
| `fields[].options[].label` | `"t:form.prefix"` |
| `submit` | `"t:btn.search"` |
| `cancel.label` | `"t:btn.back"` |

### 14.9 注意事項

- 演算子キーの変数化（例: `{ "$match_type": "$target_url" }`）は未実装。演算子ごとに別の `data_sources` を定義し、`if` ステップで分岐する（セクション15参照）
- form の `key` は `vars` で定義した変数名と一致させること。`vars` に未定義の `key` でも動作するが、初期値が明示されないため非推奨
- 1つのシーンに複数の form ステップを配置可能だが、通常は1つで十分

---

## 15. 将来の拡張予定

| 機能 | 説明 | 優先度 |
|------|------|--------|
| `textarea` type | 複数行テキスト入力（AI チャット対応時） | 高 |
| `config_delete` ステップ | ゴール削除（config API Phase 2） | 中 |
| `handleIf` 比較演算子 | `gte`, `lte`, `gt`, `lt` の追加 | 中 |
| `chart` | グラフ表示 | 中 |
| `join` transform | 複数データソースの結合 | 中 |
| `rename` / `pick` transform | フィールド操作 | 低 |
| 動的 lookups | サーバーから辞書取得 | 低 |
| Enter キー送信 | form の `enter_submit` オプション | 低 |
| 動的演算子 | filter 演算子キーの変数化 | 低 |
| ドット記法変数参照 | `result.reason` のようなネスト参照 | 低 |

---

## 16. 設定 API（config_read / config_write）

設定画面の項目をアシスタントプラグインから読み書きするためのステップ。
QAL マテリアルとしては実装しない（QAL の `time` 必須バリデーションと互換性がないため）。

### 16.1 設計原則

config API もセクション1の5原則に従う。加えて:
- **config は QAL に載せない** — 設定データは時系列データではないため、QAL の `time` 必須フィールドと構造的に合わない
- **フラット変数展開** — config_read の結果はメインデータと メタ情報を別変数に展開する。handleIf でドット記法が使えない制約への対応

### 16.2 permissions 宣言

マニフェストの トップレベルに `permissions` を追加:

```json
{
  "id": "qa-assistant-goal-setter",
  "version": "1.0.0.0",
  "permissions": {
    "config_read": ["goals", "siteinfo"],
    "config_write": ["goals"]
  }
}
```

Legacy プラグインは `config.json` に同じ構造で宣言する。

**利用可能なカテゴリ:**

| カテゴリ | 読み取り | 書き込み | 内容 |
|----------|:------:|:------:|------|
| `goals` | OK | OK | ゴール設定（最大 QAHM_CONFIG_GOALMAX 件） |
| `siteinfo` | OK | -- | サイトプロフィール |

### 16.3 config_read ステップ

```json
{ "config_read": { "category": "goals", "into": "goals_info", "on_error": "read_failed" } }
```

| プロパティ | 必須 | 説明 |
|-----------|:---:|------|
| `category` | Yes | 設定カテゴリ |
| `into` | Yes | 結果を格納する変数名 |
| `on_error` | No | 失敗時の遷移先シーン |

**フラット化仕様:** サーバーからの返却データをメイン変数とメタ変数に展開する。

`goals` カテゴリの場合:
```
{into}           → items（ゴールデータのオブジェクト）
{into}_count     → ゴール数（number）
{into}_next_id   → 次の空き gid（number）
{into}_is_max    → 上限到達フラグ（boolean）
```

`siteinfo` カテゴリの場合:
```
{into}           → items（サイトプロフィールデータ）
```

**注意:** `into` に指定した名前 + `_count`, `_next_id`, `_is_max` は予約される。form の key 等と衝突しないようにすること。

### 16.4 config_write ステップ

```json
{
  "config_write": {
    "category": "goals",
    "key": "$goals_info_next_id",
    "value": {
      "gtitle": "$goal_title",
      "gnum_scale": "$goal_scale",
      "gtype": "$goal_type",
      "g_goalpage": "$goal_url",
      "g_pagematch": "$match_type"
    },
    "into": "save_result",
    "on_error": "save_failed"
  }
}
```

| プロパティ | 必須 | 説明 |
|-----------|:---:|------|
| `category` | Yes | 設定カテゴリ（Phase 1: `goals` のみ） |
| `key` | No | 項目キー（gid）。省略時はサーバー自動採番 |
| `value` | Yes | 書き込むデータ。`resolveQueryVars()` で再帰展開 |
| `into` | No | 結果を格納する変数名 |
| `on_error` | No | 失敗時の遷移先シーン |

**value の変数展開:** `value` はオブジェクトなので `resolveValue()` では展開されない。`resolveQueryVars()` でディープコピー後に再帰展開する（`handleFetch` の query と同パターン）。

**into の格納値:**
- 成功時: `"done"`
- 失敗時: `"validation_error"` / `"no_page_id"` / `"max_reached"` / `"server_error"`

**重要:** `handleConfigWrite` は `into` 変数への格納を `on_error` の goto 返却より前に行う。これにより、エラーシーンで `save_result` の値を `if` で分岐できる（`handleFetch` とは異なるパターン）。

**AJAX データ送信:** `value` は `JSON.stringify` して送信する（`fetchData` の `query` と同パターン）。

**tracking_id 制約:** `tracking_id === 'all'` の場合はエラー返却。ゴール設定はサイト固有。

### 16.5 セキュリティモデル（4層）

| 層 | チェック | 実装場所 |
|----|---------|---------|
| 1. パーミッション宣言 | manifest.json / config.json の `permissions` | RuntimeHandler / Assistant base |
| 2. ホワイトリスト強制 | `READABLE_CATEGORIES` / `WRITABLE_CATEGORIES` ハードコード | RuntimeHandler |
| 3. WordPress capability | `current_user_can('manage_options')` | RuntimeHandler / Assistant base |
| 4. 監査ログ | `error_log()` で操作記録 | RuntimeHandler (write のみ) |

### 16.6 使用例（目標設定アシスタント）

セクション16末尾の会話フロー JSON は `assistant-rulebook.md` の「設定変更アシスタント」セクションを参照。

---

## 17. アシスタントメーカー

アシスタントプラグインの自動生成ツール。ChatGPT の Custom GPT として実装。

### 17.1 構成

| 項目 | 内容 |
|------|------|
| プラットフォーム | ChatGPT Custom GPT |
| ナレッジファイル | `docs/specs/assistant-rulebook.md` |
| 指示 | rulebook の Part 1（対話ルール）に従って会話し、Part 2（技術仕様）に従ってファイルを生成 |

### 17.2 仕組み

1. ユーザーが「どんなアシスタントを作りたいか」を自然言語で説明
2. GPT が rulebook に基づいて manifest.json + 翻訳ファイル + PHP ヘッダーを生成
3. 生成されたファイルを WordPress プラグインディレクトリに配置すれば動作

### 17.3 rulebook 更新ルール

マニフェスト仕様（本書）を変更した場合は、必ず `assistant-rulebook.md` にも反映すること。
rulebook は GPT のナレッジとして読み込まれるため、仕様と rulebook が乖離するとメーカーが不正なマニフェストを生成する。

**更新フロー:**
1. `assistant-manifest.md`（本書）で仕様を定義
2. `assistant-rulebook.md` に GPT 向けのルール・例を追記
3. ChatGPT GPT の構成画面でナレッジファイルを差し替え
