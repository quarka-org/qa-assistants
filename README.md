# QA Assistants

> 「人の判断を前に進める」ための WordPress プラグイン

QA Assistants は、サイト運営者や制作者が **「次に何を考え、何を判断すればよいか」** をデータと AI で支援する WordPress プラグイン型アシスタントです。分析ツールではなく、**判断支援ツール**です。

このリポジトリは **QA Assistants 本体の公式配布** です。最新版の zip は [GitHub Releases](https://github.com/quarka-org/qa-assistants/releases/latest) から入手できます。

---

## クイックスタート

1. [最新版の zip をダウンロード](https://github.com/quarka-org/qa-assistants/releases/latest/download/qa-heatmap-analytics.zip)
2. WordPress 管理画面 → プラグイン → 新規追加 → アップロード → 配布した zip を選択
3. 有効化
4. 詳細手順は [docs/getting-started.md](docs/getting-started.md)

> WordPress.org の「プラグイン > 新規追加」検索からも入手できますが、**マニフェストランタイム機能を含む先行版**が必要な場合は、本リポジトリの最新 Release を利用してください。

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

> マニフェストの仕様書は、本リポジトリでは公開していません。仕様に関するご質問は [Issue](https://github.com/quarka-org/qa-assistants/issues) でお知らせください。

---

## ドキュメント

| ファイル | 内容 |
|---|---|
| [docs/getting-started.md](docs/getting-started.md) | インストール → 有効化 → 動作確認 |

---

## このリポジトリで触らない範囲

- **`src/qa-heatmap-analytics/`** — QA Assistants 本体ソース（配布物）。改善要望は [Issue](https://github.com/quarka-org/qa-assistants/issues) でお願いします

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
