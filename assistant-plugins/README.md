# assistant-plugins/

QA Assistants のアシスタントプラグインを集約するディレクトリ。

## このディレクトリの役割

- **ソース管理場所**（編集はここで行う）
- このディレクトリの内容は GitHub Actions の `sync-to-public.yml` で **`quarka-org/qa-assistants` に完全ミラー** される（公開用配布）
- 配布側（qa-assistants）は **編集しない**（ミラーで上書きされる）

## 構成

| エントリ | 役割 | 状態 |
|---|---|---|
| `qa-assistant-sample/` | マニフェストランタイム全機能の参考実装。**公式サンプル** | 安定 |
| `qa-assistant-starter/` | ユーザーがコピーして始める **スターターテンプレート**（最小構成）| 安定 |
| `legacy/` | 旧 Brains 系（マニフェスト未対応）| 凍結（メンテしない）|

## 新しいアシスタントプラグインを作るとき

1. `qa-assistant-starter/` をコピー
2. `qa-assistant-{name}/` に変更
3. `manifest.json` / `lang/*.json` / PHP ヘッダーを編集
4. WordPress プラグインディレクトリに配置 → 有効化 → 動作確認

詳細手順:
- [配布リポジトリの how-to-build.md](https://github.com/quarka-org/qa-assistants/blob/main/docs/how-to-build.md)
- マニフェスト仕様: [docs/specs/assistant-manifest.md](../docs/specs/assistant-manifest.md)
- ルールブック: [docs/specs/assistant-rulebook.md](../docs/specs/assistant-rulebook.md)

## 「開発中・試作」の置き場

開発中・実験中のアシスタントプラグインは、**当面ここには置かない**。完成して安定したら集約する方針。

開発中のものは、必要に応じて Issue ベースで `tasks/issue-XXX-feat-NAME/` 配下に置くか、別の場所に整理する（運用は別途検討）。

## 同期の仕組み

- このディレクトリ + `src/qa-heatmap-analytics/` + `docs/specs/` は、リリース時に `sync-to-public.yml` ワークフローで qa-assistants に同期される
- トリガー: `workflow_dispatch`（管理人が Actions タブから手動実行）
- 詳細: `.github/workflows/sync-to-public.yml`

## 関連 Issue

- #854 マニフェストランタイム導入
- #1124 公開リポジトリ qa-assistants 新設
- #1125 sync workflow 整備
- #1131 本ディレクトリの集約
