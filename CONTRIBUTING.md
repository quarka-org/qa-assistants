# Contributing to QA Assistants

QA Assistants へのフィードバックをご検討いただきありがとうございます。

## ⚠ Pull Request は現在受け付けていません

このリポジトリは **PR を受け付けていません**。

- 開発は内部リポジトリで進めており、本リポジトリにはリリース版のみが反映されます
- PR をいただいてもマージはせず、自動 close されます（`.github/workflows/auto-close-pr.yml` で実装）
- ご親切に対し申し訳ありませんが、運用上の都合とご理解ください

## ✅ Issue は歓迎します

以下のフィードバックは [Issue](https://github.com/quarka-org/qa-assistants/issues/new/choose) でお知らせください:

- **バグ報告** — `bug_report` テンプレートを使用
- **機能要望** — `feature_request` テンプレートを使用
- **ドキュメントの誤り** — どのファイルのどの箇所か分かるように
- **マニフェスト仕様の質問・改善案**

---

## このリポジトリで触らない範囲

| パス | 理由 |
|---|---|
| `src/qa-heatmap-analytics/` | QA Assistants 本体ソース。配布物として置かれており、内部の qa-platform リポジトリで管理されています |
| `docs/specs/` | マニフェスト仕様書。qa-platform から同期されるため、ここで直接編集しても次回の同期で上書きされます |
| `LICENSE` | GPLv2-or-later（WordPress プラグイン慣習）|

これらに対する改善提案は **Issue でお願いします**。Issue を確認次第、内部リポジトリで対応を検討します。

---

## アシスタントプラグイン作成についての質問

`assistant-plugins/qa-assistant-starter/` と `docs/specs/` が用意されています。Claude Code を使うと、`CLAUDE.md` を自動で読み込んで作成支援してくれます。

- インストール手順: [docs/getting-started.md](docs/getting-started.md)
- 作成手順: [docs/how-to-build.md](docs/how-to-build.md)
- WordPress への配置: [docs/how-to-deploy.md](docs/how-to-deploy.md)
