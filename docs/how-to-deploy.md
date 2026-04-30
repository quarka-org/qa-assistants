# How to Deploy — 作ったアシスタントを WordPress に配置する

Claude Code で生成したアシスタントプラグインを、自分の WordPress に配置して動かす手順です。

## 前提

- QA Assistants 本体がインストール済み・有効化済み（[docs/getting-started.md](getting-started.md) 参照）
- 作成済みのアシスタントプラグインディレクトリ（例: `qa-assistant-myassist/`）

## 配置先

```
wp-content/plugins/qa-assistant-{name}/
├── qa-assistant-{name}.php
├── manifest.json
├── icon.png
└── lang/
    ├── ja.json
    └── en.json
```

## 配置方法

### 方法 1: 直接コピー（FTP / SCP / 共有フォルダ）

```bash
# FTP/SCP の例（自分の環境に応じて）
scp -r qa-assistant-myassist user@your-host:/path/to/wp-content/plugins/
```

### 方法 2: zip にしてアップロード

1. ローカルでディレクトリを zip 圧縮
   ```bash
   zip -r qa-assistant-myassist.zip qa-assistant-myassist/
   ```
2. WordPress 管理画面 → **プラグイン** → **新規プラグインを追加** → **プラグインのアップロード**
3. zip を選択 → **今すぐインストール** → **プラグインを有効化**

### 方法 3: WP-CLI（サーバー上で）

```bash
cd /path/to/wp-content/plugins/
# ディレクトリを配置した状態で
wp plugin activate qa-assistant-myassist
```

## ファイル所有者・権限

WordPress の運用ユーザーに合わせます。一般的には:

| 環境 | 所有者 | 権限 |
|---|---|---|
| Apache (mod_php / php-fpm) | `www-data:www-data` 等 | 644（ファイル）/ 755（ディレクトリ） |
| nginx + php-fpm | `nginx:nginx` 等 | 同上 |
| Xserver / 共有ホスティング | アカウント名（例: `kwmg:members`） | 同上 |

権限が合っていないと WordPress がプラグインを読めないので、配置後に確認してください。

## 動作確認

1. WordPress 管理画面 → **プラグイン** → 「QA Assistant {Name}」が表示されることを確認
2. **有効化**
3. 左メニュー → **AI アシスタント**
4. 一覧（カード形式）に新しいアシスタントが表示されることを確認
5. アシスタントカードをクリック → 対話シーンが起動することを確認

## トラブルシューティング

### アシスタント一覧に表示されない

- ディレクトリ名が `qa-assistant-` で始まっているか
- `manifest.json` がディレクトリ直下にあるか
- プラグインが**有効化**されているか
- ファイル所有者が WordPress 実行ユーザーで読めるか

### 「Manifest not found」「Invalid manifest JSON」エラー

- `manifest.json` の場所と内容を確認
- JSON の構文が正しいか（`python -m json.tool manifest.json`）
- `.php` ヘッダーファイル名がディレクトリ名と一致しているか

### 翻訳が表示されない（`t:msg.xxx` の形のまま）

- `lang/ja.json` と `lang/en.json` が存在するか
- 翻訳キーがマニフェストの `t:` 参照と一致しているか
- JSON の文字化け（BOM 付き等）がないか確認

### 期間選択後にデータが空

- WordPress に**計測データが蓄積されているか**（**訪問レポート** で確認）
- `tracking_id` フィルタが正しく `$sys.tracking_id` を参照しているか
- `date` フィルタの期間に該当するデータがあるか

詳細は [`docs/specs/assistant-manifest.md`](specs/assistant-manifest.md) の「セキュリティ対策」「QAL クエリ変換」セクションを参照。

## アンインストール

1. WordPress 管理画面 → **プラグイン** → 「QA Assistant {Name}」 → **無効化** → **削除**
2. または `wp-content/plugins/qa-assistant-{name}/` ディレクトリを直接削除

QA Assistants 本体（`qa-heatmap-analytics`）は影響を受けません。
