<div align="center">

# WP Helpers Multi
**WordPress 管理画面に “便利ツール箱” を追加するプラグイン**

[![WordPress](https://img.shields.io/badge/WordPress-6.x%2B-blue)](#)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4)](#)
[![License](https://img.shields.io/badge/License-GPLv2%2B-green)](#license)
[![Status](https://img.shields.io/badge/Status-Private%20Repo-lightgrey)](#)

</div>

---

## 概要
**WP Helpers Multi** は、WordPress運用で「ちょっと欲しい」を集めたユーティリティ集です。  
最初のバージョンでは、以下の機能を同梱しています。

- QRコード生成
- 電卓
- bcrypt ヘルパー
- パスワード生成
- タイマー
- リンク点検
- アクセスログ

> 目的：学習（プラグイン開発・更新配布）と、日々の運用効率を少しずつ上げること。

---

## 特徴（v0.1）
- ✅ 管理画面にツールを集約（“使いたい時にすぐ”）
- ✅ 小さく始めて拡張しやすい（ツール追加の土台）
- ✅ ZIP配布 & 自動更新の2パターン（学習用途に最適）

---

## 機能一覧（v0.1）
- ✅ **QRコード生成**：テキスト/URL からQRを作成
- ✅ **電卓**：軽い計算を即実行
- ✅ **bcrypt**：ハッシュ生成・検証（開発/確認用）
- ✅ **パスワード生成**：長さ・文字種を指定して生成
- ✅ **タイマー**：作業・待機に
- ✅ **リンク点検**：URLの到達確認（簡易チェック）
- ✅ **アクセスログ**：アクセス状況を確認（環境により表示内容は変わります）

---

## スクリーンショット
> あとで追加でOK（2枚でも一気に見栄えが上がります）

- `assets/screenshot-1.png`：ダッシュボード / ツール一覧
- `assets/screenshot-2.png`：QRコード生成画面

（例：配置イメージ）

    assets/
      screenshot-1.png
      screenshot-2.png

---

## 動作環境
- WordPress：6.x+（目安）
- PHP：8.0+（目安）

---

## インストール（2通り）

### 1) GitHub ZIP でインストール
1. GitHub の **Releases** から `wp-helpers-multi.zip` をダウンロード
2. WP管理画面 → **プラグイン** → **新規追加** → **プラグインのアップロード**
3. 有効化

### 2) サブドメインに ZIP を置いて自動更新（Self-hosted）
自前の更新サーバー（サブドメイン）にZIPを配置し、プラグイン側が更新情報を参照する方式です。

- 更新サーバー例：`https://updates.your-domain.example/wp-helpers-multi/`
- 置くもの例：
  - `wp-helpers-multi.zip`
  - `info.json`（バージョンや更新情報）

ディレクトリ例：

    https://updates.your-domain.example/wp-helpers-multi/
      ├─ wp-helpers-multi.zip
      └─ info.json

`info.json` の例（あなたの実装に合わせて調整）：

    {
      "name": "WP Helpers Multi",
      "slug": "wp-helpers-multi",
      "version": "0.1.0",
      "download_url": "https://updates.your-domain.example/wp-helpers-multi/wp-helpers-multi.zip",
      "requires": "6.0",
      "requires_php": "8.0",
      "last_updated": "2026-01-04",
      "sections": {
        "description": "Utility toolbox for WordPress admins.",
        "changelog": "- v0.1.0 Initial release"
      }
    }

> ※「自動更新」の実装方式（GitHub Releases参照 / 独自JSON参照 / Update URI など）により、必要なフィールドや設計が変わります。  
> README には “概要” だけ書いて、実装詳細は `docs/` に分けるのがおすすめです。

---

## 使い方
インストール後、WordPress 管理画面に **WP Helpers Multi** のメニューが追加されます。  
各ツールはその画面から実行できます。

---

## セキュリティ / 注意
- 本プラグインは運用を助けるためのツール集です。
- **bcrypt/パスワード生成** など、情報を扱う機能は取り扱いに注意してください。
- 脆弱性や気づいた点があれば Issue または非公開で連絡してください。

---

## ロードマップ（予定）
- [ ] UI整理（共通デザイン / ナビ改善）
- [ ] リンク点検の詳細化（リダイレクト / タイムアウト / レポート出力）
- [ ] ツール追加（必要になったら順次）

---

## ライセンス
GPL-2.0-or-later

---

## Author
- YOURNAME

---

## English (Short)
**WP Helpers Multi** adds a small toolbox of admin utilities to WordPress.  
Initial features: QR code generator, calculator, bcrypt helper, password generator, timer, link checker, and access log viewer.
