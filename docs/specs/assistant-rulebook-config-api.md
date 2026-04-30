# アシスタントプラグイン生成ルールブック — 設定API編

データ分析だけでなく、設定画面の項目を読み書きするアシスタントも作成できる。
第一のユースケースは「目標設定アシスタント」（対話形式でゴール設定をガイドする）。

> **基本編**（対話ルール・変数・翻訳・シーン）→ `assistant-rulebook-basic.md`
> **データ編**（QAL マテリアル・data_sources・transform・tables）→ `assistant-rulebook-data.md`

---

## permissions 宣言

設定 API を使うには、manifest.json のトップレベルに `permissions` を宣言する:

```json
{
  "id": "qa-assistant-goal-setter",
  "permissions": {
    "config_read": ["goals", "siteinfo"],
    "config_write": ["goals"]
  }
}
```

**利用可能なカテゴリ:**
- `goals` — ゴール設定（読み書き可）
- `siteinfo` — サイトプロフィール（読み取りのみ）

---

## config_read ステップ

設定データを読み取り、変数に格納する:

```json
{ "config_read": { "category": "goals", "into": "goals_info", "on_error": "read_failed" } }
```

| プロパティ | 必須 | 説明 |
|-----------|:---:|------|
| `category` | Yes | 設定カテゴリ（`goals`, `siteinfo`） |
| `into` | Yes | 結果を格納する変数名 |
| `on_error` | No | 失敗時の遷移先シーン |

**フラット化:** 結果は以下の変数に展開される（goals カテゴリの場合）:
- `goals_info` — ゴールデータ本体
- `goals_info_count` — ゴール数（number: 0, 1, 2, ...）
- `goals_info_next_id` — 次の空き gid（number: 1〜10）
- `goals_info_is_max` — 上限到達フラグ（boolean: true/false）

---

## config_write ステップ

設定データを書き込む:

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
| `key` | No | 項目キー（gid）。省略時はサーバー自動採番。明示指定も可（編集時） |
| `value` | Yes | 書き込むデータ。変数参照（`$変数名`）が使える |
| `into` | No | 結果を格納する変数名 |
| `on_error` | No | 失敗時の遷移先シーン |

- `key` — ゴール ID。config_read で得た `$goals_info_next_id` を使う
- `into` — 成功時 `"done"`、失敗時はエラー理由（`"no_page_id"`, `"max_reached"` 等）
- `on_error` — AJAX 通信失敗時の遷移先シーン

---

## ゴール設定アシスタントの定番パターン

```
start → config_read で既存ゴールを読む
      → is_max チェック → 上限到達なら max_reached シーン
      → count == 0 チェック → 0件なら first_goal シーン
      → 通常: 追加 or 一覧表示を選択

input_goal → form でゴール情報を入力
           → confirm_save で確認

do_save → set で gtype 等のデフォルト値をセット
        → config_write で保存
        → 成功 → start に戻る

save_failed → save_result の値で分岐
            → no_page_id → URL が見つからないエラー
            → その他 → 汎用エラー + リトライ
```

---

## 完全な動作例: 目標設定アシスタント

### manifest.json

```json
{
  "id": "qa-assistant-goal-setter",
  "name": "t:meta.name",
  "description": "t:meta.description",
  "version": "1.0.0.0",
  "icon": "icon.png",
  "permissions": {
    "config_read": ["goals"],
    "config_write": ["goals"]
  },
  "vars": {
    "goal_title": "",
    "goal_url": "",
    "goal_scale": "",
    "match_type": "pagematch_complete",
    "goal_type": "gtype_page"
  },
  "scenes": {
    "start": [
      { "message": "t:msg.welcome" },
      { "config_read": { "category": "goals", "into": "goals_info", "on_error": "read_failed" } },
      { "if": { "var": "goals_info_is_max", "is": "eq", "value": true },
        "then": { "goto": "max_reached" } },
      { "if": { "var": "goals_info_count", "is": "eq", "value": 0 },
        "then": { "goto": "first_goal" } },
      { "message": "t:msg.has_goals" },
      { "choices": [
        { "label": "t:btn.add_goal", "goto": "input_goal" },
        { "label": "t:btn.back", "goto": "start", "clear": true }
      ]}
    ],
    "first_goal": [
      { "message": "t:msg.first_goal" },
      { "choices": [
        { "label": "t:btn.add_goal", "goto": "input_goal" }
      ]}
    ],
    "input_goal": [
      { "form": {
        "fields": [
          { "key": "goal_title", "type": "text", "label": "t:form.goal_name", "required": true },
          { "key": "goal_url", "type": "url", "label": "t:form.goal_url", "required": true },
          { "key": "goal_scale", "type": "text", "label": "t:form.goal_target" },
          { "key": "match_type", "type": "radio", "label": "t:form.match_type",
            "options": [
              { "value": "pagematch_complete", "label": "t:form.exact_match" },
              { "value": "pagematch_prefix", "label": "t:form.prefix_match" }
            ]}
        ],
        "submit": "t:btn.confirm"
      }},
      { "goto": "confirm_save" }
    ],
    "confirm_save": [
      { "message": "t:msg.confirm" },
      { "choices": [
        { "label": "t:btn.save", "goto": "do_save" },
        { "label": "t:btn.cancel", "goto": "start" }
      ]}
    ],
    "do_save": [
      { "set": { "goal_type": "gtype_page" } },
      { "config_write": {
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
      }},
      { "message": "t:msg.saved" },
      { "goto": "start" }
    ],
    "save_failed": [
      { "if": { "var": "save_result", "is": "eq", "value": "no_page_id" },
        "then": { "goto": "no_page_error" } },
      { "message": "t:msg.generic_error" },
      { "choices": [{ "label": "t:btn.retry", "goto": "input_goal" }] }
    ],
    "no_page_error": [
      { "message": "t:msg.page_not_found" },
      { "choices": [{ "label": "t:btn.reenter", "goto": "input_goal" }] }
    ],
    "max_reached": [
      { "message": "t:msg.max_goals" },
      { "choices": [{ "label": "t:btn.back", "goto": "start", "clear": true }] }
    ],
    "read_failed": [
      { "message": "t:msg.read_error" },
      { "choices": [{ "label": "t:btn.retry", "goto": "start" }] }
    ]
  }
}
```

### ja.json

```json
{
  "meta": {
    "name": "目標設定アシスタント",
    "description": "対話形式でコンバージョン目標を設定します"
  },
  "msg": {
    "welcome": "目標設定をお手伝いします。現在の設定を確認しますね。",
    "has_goals": "すでに目標が {$goals_info_count} 件設定されています。新しい目標を追加しますか？",
    "first_goal": "まだ目標が設定されていません。最初の目標を作りましょう！",
    "confirm": "以下の内容で目標を保存します。\\n\\n**目標名:** {$goal_title}\\n**URL:** {$goal_url}\\n**月間目標:** {$goal_scale} 件",
    "saved": "目標を保存しました！",
    "generic_error": "保存中にエラーが発生しました。もう一度お試しください。",
    "page_not_found": "入力されたURLに該当するページが見つかりませんでした。URLを確認して再入力してください。",
    "max_goals": "目標数が上限に達しています。新しい目標を追加するには、設定画面で既存の目標を削除してください。",
    "read_error": "設定の読み込みに失敗しました。"
  },
  "btn": {
    "add_goal": "新しい目標を追加",
    "back": "戻る",
    "confirm": "確認",
    "save": "保存する",
    "cancel": "やめる",
    "retry": "もう一度試す",
    "reenter": "URLを入力し直す"
  },
  "form": {
    "goal_name": "目標名",
    "goal_url": "目標ページのURL",
    "goal_target": "月間目標件数（任意）",
    "match_type": "マッチ方式",
    "exact_match": "完全一致",
    "prefix_match": "前方一致"
  }
}
```

**ポイント:**
- `config_read` → `if` の組み合わせで上限・0件を分岐
- `config_write` の `key` に `$goals_info_next_id`（config_read のフラット化変数）を使用
- `save_result` の値で `save_failed` シーン内をさらに分岐
- `on_error` は AJAX 失敗時の遷移。`save_result` へのエラー理由格納は on_error の前に行われる
