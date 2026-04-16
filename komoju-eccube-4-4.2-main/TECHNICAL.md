# Komoju EC-CUBE 4.2/4.3 プラグイン技術ドキュメント

## 概要
Komoju EC-CUBE 4.2/4.3 プラグインは、EC-CUBE 4.2および4.3に対応したKomoju決済連携プラグインです。Komoju APIを利用し、注文・支払い・Webhook通知・管理画面での設定・ログ管理などを提供します。

## ディレクトリ構成
- Controller/
  - WebhookController.php: Webhook受信処理
  - Admin/: 管理画面用コントローラ（設定・ログ・注文管理）
- Doctrine/: Doctrineイベントサブスクライバ
- Entity/: Komoju関連エンティティ（設定・注文・ログ・支払い）
- Form/: EC-CUBEフォーム拡張（設定・支払い）
- Repository/: Komoju関連リポジトリ（DB操作）
- Resource/
  - config/: サービス定義（services.yaml）
  - komoju_lib/: Komoju APIラッパー・HTTPクライアント・Webhook検証
  - locale/: 多言語メッセージ
  - template/: Twigテンプレート（管理画面・購入画面・メール）
- Service/: ビジネスロジック（設定・決済・ログ・メール・Webhook）
- Method/: 決済方式定義

## 主なファイルと役割
- KomojuClient.php: Komoju APIクライアント
- KomojuService.php: 決済処理の中心サービス
- WebhookService.php: Webhook通知の検証・処理
- KomojuConfig.php: プラグイン設定エンティティ
- KomojuOrder.php: Komoju注文エンティティ
- KomojuPay.php: 支払い情報エンティティ
- KomojuLog.php: ログ管理エンティティ
- KomojuConfigType.php: 設定フォームType
- KomojuPayment.php: 複数決済方式の定義

## 実装仕様
- Komoju APIとの連携は `Resource/komoju_lib/` 配下の `KomojuApi.php` および `HttpClient.php` で実装。
- Webhook通知は `WebhookController.php` で受信し、署名検証は `WebhookSignature.php` で行う。
- 管理画面の設定・ログ・注文管理は `Controller/Admin/` 配下で提供。
- DB操作は Doctrine ORMを利用し、Entity/Repositoryで管理。
- サービス定義は `Resource/config/services.yaml` で行い、DIコンテナで管理。

## 拡張ポイント
- 決済方式追加: `Method/KomojuPayment.php` を拡張
- 管理画面拡張: `Controller/Admin/` 配下にコントローラ・Twigテンプレート追加
- Webhookイベント拡張: `Resource/komoju_lib/WebhookEvent.php` を拡張

## 設定方法
1. 管理画面 > プラグイン設定より、Komoju APIキー・エンドポイント等を入力
2. 必要に応じてWebhook URLをKomoju管理画面に登録
3. 支払い方法の有効化・無効化は管理画面で操作

## 注意事項
- EC-CUBE本体のバージョンに依存するため、4.2および4.3以外は動作保証外
- Komoju APIキー・Webhook署名は厳重に管理
- プラグインアップデート時はバックアップ推奨

## ライセンス
- LICENSE.txt参照
