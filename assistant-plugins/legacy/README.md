# legacy/

旧 Brains 系のアシスタントプラグイン置き場（**マニフェスト未対応**）。

## ⚠ 重要

**このディレクトリのコードはメンテナンスしません。**

- 旧形式（`main.php` + `commands.json` + `config.json`）で実装されたプラグイン
- マニフェストランタイム（`manifest.json` 方式、Issue #854 で導入）への置き換えに伴い、開発の主流からは外れた
- バグ修正や機能追加は、**マニフェスト方式で再実装する**方針
- 履歴的参考としてここに保持する

## 含まれるプラグイン

| プラグイン | 概要 |
|---|---|
| `qa-assistant-growth/` | 伸びているページ分析（Brains 系）|
| `qa-assistant-seo-expert/` | SEO 対策アドバイス（Brains 系）|
| `qa-assistant-site-analyst/` | サイト概要報告（Brains 系）|
| `qa-assistant-social-media/` | SNS 分析（Brains 系）|

## ab-test を含めない理由

旧 `quarka-org/qa-assistant` リポジトリには `qa-assistant-ab-test/` も存在したが、**README + PHP プラグインヘッダーのみのスタブ状態で実装が無かった**ため、本ディレクトリには含めない。

過去の議論や実装着手の痕跡は、archive 済みの旧リポジトリ [quarka-org/qa-assistant](https://github.com/quarka-org/qa-assistant) で参照可能（read-only）。

## 履歴の参照

git の履歴は本リポジトリには引き継いでいない。
旧コミット履歴・ブランチ・PR 等は archive 済みの [quarka-org/qa-assistant](https://github.com/quarka-org/qa-assistant) を参照。

## マニフェスト方式への移行

旧 Brains 系の機能をマニフェスト方式で再実装する場合の参考順序:

1. 既存コード（`main.php` / `commands.json`）を読み、ユースケースを把握
2. マニフェスト仕様（`docs/specs/assistant-manifest.md`）に当てはめる
3. `assistant-plugins/qa-assistant-starter/` をコピーして実装開始

**直接これをコピーしてもマニフェストランタイムでは動作しない**ので注意。
