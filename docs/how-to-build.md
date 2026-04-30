# How to Build — Claude Code でアシスタントプラグインを作る

QA Assistants は **JSON マニフェストだけでアシスタントが作れる** 仕組みを持っています。PHP コードは書きません。Claude Code を使うと、対話形式でほぼ自動で生成できます。

## 必要なもの

- [Claude Code](https://docs.claude.com/ja/docs/claude-code)（CLI）
- このリポジトリの clone

```bash
git clone https://github.com/quarka-org/qa-assistants.git
cd qa-assistants
```

## 流れ

```
1. claude を起動（CLAUDE.md が自動で読み込まれる）
   ↓
2. 「○○なアシスタントを作って」と指示
   ↓
3. Claude Code が構成を提案 → 確認
   ↓
4. 4 ファイルを生成（templates/ をコピーして編集）
   ↓
5. 自分の WordPress プラグインディレクトリに配置 → 有効化 → 動作確認
```

## ステップ 1: Claude Code を起動

```bash
cd qa-assistants
claude
```

リポジトリのルートで起動すると、`CLAUDE.md` が自動でコンテキストに読み込まれます。これにより Claude Code が:

- マニフェスト仕様（`docs/specs/`）の場所を知っている
- 出力すべきファイル構成を知っている
- 守るべきルール（snake_case、tracking_id+date 必須、等）を理解している

状態でアシスタント作成を支援してくれます。

## ステップ 2: 作りたいものを伝える

例:

> SNS（X / Facebook / Instagram）からの流入を分析して、各 SNS から来たページをドリルダウンで見られるアシスタントを作って

または:

> 4 つの質問に答えると診断結果を出すアシスタント。質問は…

技術用語（QAL、マテリアル、transform 等）は知らなくて大丈夫です。Claude Code が翻訳します。

## ステップ 3: 構成の確認

Claude Code が以下のように提案してきます:

```
📊 こんなアシスタントを作ります:

【できること】
・SNS別の PV・滞在時間を一覧表示
・各SNSをクリックすると、どのページに来ているかドリルダウン

【会話の流れ】
[開始] 期間を選ぶ
  ↓
[一覧] SNS 別アクセスをテーブル表示
  ↓ SNS を選ぶ
[詳細] そのSNSから来たページ一覧

この構成で生成しますか？
```

「OK」「お願い」と返すと生成に進みます。修正したい点があればその場で伝えてください。

## ステップ 4: 4 ファイルが生成される

Claude Code が以下を生成（`templates/qa-assistant-starter/` をコピーして編集）:

```
qa-assistant-{name}/
├── qa-assistant-{name}.php   # WP プラグインヘッダー
├── manifest.json              # 動作定義（本体）
├── icon.png                   # アイコン（暫定で starter のものを流用）
└── lang/
    ├── en.json                # 英語翻訳
    └── ja.json                # 日本語翻訳
```

中身を確認して、文言調整があれば追加で指示してください。

## ステップ 5: 配置 → 有効化 → 動作確認

詳細は [docs/how-to-deploy.md](how-to-deploy.md) を参照。要点:

1. 生成された `qa-assistant-{name}/` ディレクトリを WordPress の `wp-content/plugins/` 配下にコピー
2. WordPress 管理画面 → プラグイン → 「QA Assistant {Name}」を**有効化**
3. **AI アシスタント** メニュー → 一覧に新しいアシスタントが表示されることを確認
4. クリックして対話を試す

## 仕様の詳細を知りたい場合

| ファイル | 内容 |
|---|---|
| [`docs/specs/assistant-manifest.md`](specs/assistant-manifest.md) | 完全な仕様書（マニフェストランタイムの全機能） |
| [`docs/specs/assistant-rulebook.md`](specs/assistant-rulebook.md) | 統合ルールブック（GPT/AI 用ナレッジ向けに編集された版） |
| [`docs/specs/assistant-rulebook-basic.md`](specs/assistant-rulebook-basic.md) | 対話ルール / 変数 / シーンの基本 |
| [`docs/specs/assistant-rulebook-data.md`](specs/assistant-rulebook-data.md) | QAL マテリアル（取得できるデータ）と data_sources |
| [`docs/specs/assistant-rulebook-config-api.md`](specs/assistant-rulebook-config-api.md) | 設定 API（ゴール作成等） |

## 実装パターンの参考

`docs/specs/assistant-rulebook-data.md` の末尾に、実用的な完全動作例があります:

- 基本パターン: ページ分析アシスタント（期間選択 → fetch → table → ドリルダウン）
- 応用例: SNS トラフィック分析（lookup + 動的 choices）

## トラブルシューティング

| 症状 | 確認すること |
|---|---|
| アシスタント一覧に表示されない | プラグインが有効化されているか / `qa-assistant-` プレフィックスのディレクトリ名か |
| 起動するとエラー | `manifest.json` が JSON として valid か（`python -m json.tool manifest.json`）|
| 翻訳が表示されず `t:msg.welcome` のまま出る | 翻訳キーが `lang/ja.json` に存在するか |
| データが返ってこない | `tracking_id` と `date` フィルタが query に含まれているか |
| 動的 choices が空 | `from_data` が指す変数にデータが入っているか / `transform` が適切か |

詳細なデバッグは [`docs/specs/assistant-manifest.md`](specs/assistant-manifest.md) のセクション 11「実行フロー」を参照。
