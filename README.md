# QA Assistants

> 「人の判断を前に進める」ための WordPress プラグイン

QA Assistants は、サイト運営者や制作者が **「次に何を考え、何を判断すればよいか」** をデータと AI で支援する WordPress プラグイン型アシスタントです。分析ツールではなく、**判断支援ツール**です。

このリポジトリは:

1. **QA Assistants 本体の公式配布** — 最新版の zip は [GitHub Releases](https://github.com/quarka-org/qa-assistants/releases/latest) から
2. **アシスタントプラグイン作成キット** — Claude Code でマニフェスト方式のアシスタントプラグインを作成するためのテンプレートとドキュメント

---

## クイックスタート

### QA Assistants を使いたい人

1. [最新版の zip をダウンロード](https://github.com/quarka-org/qa-assistants/releases/latest/download/qa-heatmap-analytics.zip)
2. WordPress 管理画面 → プラグイン → 新規追加 → アップロード → 配布した zip を選択
3. 有効化
4. 詳細手順は [docs/getting-started.md](docs/getting-started.md)

> WordPress.org の「プラグイン > 新規追加」検索からも入手できますが、**マニフェストランタイム機能を含む先行版**が必要な場合は、本リポジトリの最新 Release を利用してください。

### アシスタントプラグインを作りたい人

QA Assistants は、**JSON マニフェストだけでアシスタントが作れる**ように設計されています。PHP コードは書きません。

1. このリポジトリを clone
   ```bash
   git clone https://github.com/quarka-org/qa-assistants.git
   ```
2. `claude` を起動（[Claude Code](https://docs.claude.com/ja/docs/claude-code) が必要）
   ```bash
   cd qa-assistants
   claude
   ```

> **Claude Code を持っていない場合:** [公式ドキュメント](https://docs.claude.com/ja/docs/claude-code) からインストールできます。Claude Pro / Max / Team プラン または Anthropic API キーで利用可能。Pro プラン（$20/月）でも軽〜中規模の作業には十分です。
3. 「○○なアシスタントを作って」と指示するだけ。
   Claude Code が `CLAUDE.md` を自動で読み込み、マニフェスト仕様に従った 4 ファイル（`manifest.json` + 翻訳 2 ファイル + PHP ヘッダー）を生成します。

詳細は [docs/how-to-build.md](docs/how-to-build.md)。

---

## マニフェスト方式とは

```
qa-assistant-{name}/
├── qa-assistant-{name}.php   # WP プラグインヘッダー（コードなし）
├── manifest.json              # 動作定義（本体）
├── icon.png                   # アイコン
└── lang/
    ├── ja.json                # 日本語翻訳
    └── en.json                # 英語翻訳
```

`manifest.json` に「会話フロー」「データ取得」「テーブル表示」を **JSON で宣言** すると、QA Assistants の Runtime が自動でアシスタントとして実行します。

```json
{
  "scenes": {
    "start": [
      { "message": "t:msg.welcome" },
      { "choices": [
        { "label": "t:btn.analyze", "goto": "analyze" }
      ]}
    ],
    "analyze": [
      { "fetch": "pages" },
      { "table": "page_summary" }
    ]
  }
}
```

完全な仕様は [docs/specs/assistant-manifest.md](docs/specs/assistant-manifest.md) を参照してください。

---

## ドキュメント

| ファイル | 内容 |
|---|---|
| [docs/getting-started.md](docs/getting-started.md) | インストール → 有効化 → 動作確認 |
| [docs/how-to-build.md](docs/how-to-build.md) | Claude Code でアシスタントを作る手順 |
| [docs/how-to-deploy.md](docs/how-to-deploy.md) | 作ったアシスタントを WordPress に配置する手順 |
| [docs/specs/assistant-manifest.md](docs/specs/assistant-manifest.md) | マニフェスト仕様書（完全版）|
| [docs/specs/assistant-rulebook.md](docs/specs/assistant-rulebook.md) | アシスタント生成ルールブック（統合版）|
| [docs/specs/assistant-rulebook-basic.md](docs/specs/assistant-rulebook-basic.md) | 基本編（対話ルール / 変数 / シーン）|
| [docs/specs/assistant-rulebook-data.md](docs/specs/assistant-rulebook-data.md) | データ編（QAL マテリアル / data_sources）|
| [docs/specs/assistant-rulebook-config-api.md](docs/specs/assistant-rulebook-config-api.md) | 設定 API 編（config_read / config_write）|

---

## このリポジトリで触らない範囲

- **`src/qa-heatmap-analytics/`** — QA Assistants 本体ソース（配布物）。改善要望は [Issue](https://github.com/quarka-org/qa-assistants/issues) でお願いします
- **`docs/specs/`** — qa-platform から同期される仕様書。直接編集はしないでください

詳細は [CONTRIBUTING.md](CONTRIBUTING.md) を参照。

---

## ⚠ Pull Request について

**このリポジトリでは Pull Request を受け付けていません。**

開発は内部で進めており、外部からの PR はマージしません。フィードバックは [Issue](https://github.com/quarka-org/qa-assistants/issues) でお願いします。

詳細は [CONTRIBUTING.md](CONTRIBUTING.md) を参照。

---

## ライセンス

[GPLv2-or-later](LICENSE)（WordPress プラグイン慣習に準拠）

## リンク

- 開発元: [Quarka](https://github.com/quarka-org)
- Issue: https://github.com/quarka-org/qa-assistants/issues
- 最新リリース: https://github.com/quarka-org/qa-assistants/releases/latest
