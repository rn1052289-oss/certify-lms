# Certify LMS

マルチ資格対応の資格学習プラットフォームです。受講生は資格ごとの教材で学習し、演習問題・模擬試験で理解度を確かめながら、コーチの面談サポートを受けて資格取得を目指せます。

> プロジェクト構造・ドメインモデル・コードの読み進め方は [ONBOARDING.md](./ONBOARDING.md) を参照してください。

## 主な機能

| ロール | 機能 |
|---|---|
| 受講生（student） | 教材閲覧 / 演習問題・苦手分野ドリル / 模擬試験（分野別ヒートマップ・合格可能性スコア）/ 面談予約 / チャット / 学習時間・進捗・ストリーク管理 / 修了証の受領 |
| コーチ（coach） | 教材・演習問題・模試の管理 / 担当受講生の進捗フォロー / 面談対応・面談メモ / チャット |
| 管理者（admin） | ユーザー招待・管理 / 資格・資格分類マスタ管理 / 資格へのコーチ割当 / 面談回数の付与 / 全体ダッシュボード |

## 動作環境

- Docker Desktop / Docker Compose
- 開発環境は Laravel Sail で構築します（PHP コンテナ・MySQL・Mailpit・phpMyAdmin を起動）

## 環境構築手順

### 1. リポジトリの clone

```bash
git clone <このリポジトリの URL>
cd <リポジトリ名>
```

### 2. 環境変数ファイルの作成

```bash
cp .env.example .env
```

`.env.example` は Sail 向けに設定済みのため、コピーするだけでローカル開発を始められます（外部サービス連携のキーは後述）。

### 3. 依存パッケージのインストール（初回のみ）

`vendor/` がまだ無いため、初回のみ Docker 経由で Composer を実行します。

```bash
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs
```

### 4. Sail エイリアスの設定（推奨）

```bash
alias sail='./vendor/bin/sail'
```

以降のコマンドはこのエイリアス前提で記載します（未設定の場合は `./vendor/bin/sail` に読み替えてください）。

### 5. コンテナの起動

```bash
sail up -d
```

### 6. アプリケーションの初期化

```bash
sail artisan key:generate
sail artisan storage:link
sail artisan migrate:fresh --seed
```

`storage:link` は教材画像・プロフィール画像の配信に必要です。`migrate:fresh --seed` でテーブル作成とデモデータ投入が行われます（いつでも再実行してデータを初期状態に戻せます）。

### 7. フロントエンドのビルド

```bash
sail npm install
sail npm run build
```

Blade / CSS / JS を編集しながら開発する場合は、`build` の代わりに `sail npm run dev` を起動したままにしてください（Vite のホットリロードが効きます）。

### 8. 動作確認

http://localhost:8000 にアクセスし、下記の[ログインアカウント](#ログインアカウント)でログインできればセットアップ完了です。

## 開発環境 URL

| 用途 | URL |
|---|---|
| アプリケーション | http://localhost:8000 |
| phpMyAdmin（DB 確認） | http://localhost:8080 |
| Mailpit（メール確認） | http://localhost:8025 |

アプリケーションが送信するメール（招待メールなど）はすべて Mailpit に届きます。実際のメールは送信されません。

## ログインアカウント

`migrate:fresh --seed` 後、以下の固定アカウントが使えます（パスワードはすべて `password`）。

| ロール | メールアドレス | 備考 |
|---|---|---|
| 管理者 | admin@certify-lms.test | 全機能にアクセス可能 |
| コーチ | coach@certify-lms.test | IT 系資格の担当 |
| コーチ | coach2@certify-lms.test | ビジネス系資格の担当 |
| 受講生 | student@certify-lms.test | 受講中の資格・学習履歴・面談などのデモデータ付き |

このほか、ライフサイクル（招待中 / 受講中 / 卒業 / 退会）を網羅したデモユーザーが投入されます。

> 本サービスは**招待制**です。公開の会員登録画面はありません。新規ユーザーを作るには、管理者でログイン → ユーザー管理から招待 → Mailpit で招待メールの URL を開く → オンボーディング登録、という流れになります。

## テスト

```bash
sail artisan test                  # 全テスト実行
sail artisan test --filter=Xxx    # クラス名・メソッド名で絞り込み
```

## コード整形

Laravel Pint を使用しています。コミット前に実行してください。

```bash
sail bin pint --dirty    # 変更ファイルのみ整形
sail bin pint --test     # 整形漏れの確認（CI 相当のチェック）
```

## 使用技術

- PHP 8.5 / Laravel 10
- MySQL 8.4
- Laravel Fortify（認証）/ Laravel Sanctum（API 認証）
- Blade + Tailwind CSS + Vite（JavaScript は素の JS、フレームワーク不使用）
- PHPUnit / Laravel Pint
- league/commonmark（教材本文の Markdown レンダリング）
- Pusher（チャットのリアルタイム配信）
- Docker（Laravel Sail）

## 環境変数

`.env.example` をコピーするだけで、すべての機能がローカルで動作します（メールは Mailpit に配信されます）。

- `PUSHER_*` — チャットのリアルタイム配信に使用します。有効にする場合は Pusher のキーを取得して設定し、`BROADCAST_DRIVER=pusher` に変更してください。未設定（既定の `BROADCAST_DRIVER=log`）でもメッセージの送受信自体は動作し、相手画面へのリアルタイム反映のみ行われません

新しい環境変数やセットアップ手順を追加した場合は、`.env.example` と本 README に追記し、チームの誰でも環境を再現できる状態を保ってください。


## 追加面談購入（S-A-03 / Stripe Checkout）

学習中の受講生は、面談回数履歴またはダッシュボードから公開中の面談パックを選んで購入できます。
カード情報は Stripe の決済画面で入力し、LMS には保存しません。通貨は JPY、都度購入のみです。

### ローカル検証の設定

ローカルの `.env` に Stripe **テストモード**の `STRIPE_SECRET` と、Webhook エンドポイント専用の
`STRIPE_WEBHOOK_SECRET` を設定してください。キーをブラウザー・Git・スクリーンショットへ公開しないでください。
反映後に `sail artisan config:clear` を実行します。本番のキーは検証に使用しません。

1. `sail artisan migrate` で payments と付与履歴の制約を追加します。
2. `sail artisan db:seed --class=PaymentSeeder` で固定・デモ受講生の完了 / 保留 / 失敗履歴を用意します。
   先に UserSeeder / MeetingPackSeeder が投入済みである必要があります。Seeder は外部通信せず、再実行でも二重付与しません。
   完了分のみ purchased 履歴に加算し、保留・失敗分と `max_meetings` は変えません。
3. Stripe CLI のテストアカウントで `stripe listen --forward-to http://localhost/webhooks/stripe` を実行します。
   ポートを変えている環境では転送先を合わせます。CLI が表示する署名用シークレットをローカル設定に使用します。
4. 学習中 student で `/meeting-quota/checkout` を開き、公開中のパックから Stripe テスト決済へ進みます。
   Stripe の公式テストカードで決済します。CLI の汎用 `trigger` だけでは LMS の購入控えに対応しないため加算されません。
5. 完了通知後に完了画面・ダッシュボード・面談回数履歴で回数を確認します。同じイベントの再送でも加算は 1 回です。
   キャンセルして購入画面へ戻るだけでは加算されません。完了画面の GET も支払状態を更新しません。
6. 購入開始後のマスタ価格 / 回数変更でも、購入控えの金額・回数が維持されることを確認します。

Webhook は `/webhooks/stripe` のみ CSRF 検証を除外し、未認証で受けます。Stripe SDK で生本文の署名と時刻を検証し、
購入者・購入 ID・Session ID・金額・通貨を控えと照合します。支払確定と回数付与は同一 DB トランザクションで保存します。
購入後に卒業・退会していても正常な支払分は付与しますが、購入・面談利用の既存ステータス制御は維持します。
通信失敗時の保留控えは残し、遅れて届く正常な通知も照合できるようにしています。想定外イベントは付与せず終了します。
Stripe の返金操作を LMS の回数へ自動反映する処理は今回の対象外です。

### 自動テスト

```bash
sail artisan test tests/Feature/MeetingQuotaCheckoutTest.php tests/Feature/StripeWebhookTest.php tests/Feature/Seeders/PaymentSeederTest.php
sail artisan test tests/Feature/ConcurrentStripeWebhookTest.php
```

テストは SDK の HTTP 応答を差し替え、署名付きイベントもローカルで生成するため、実決済・外部通信は発生しません。
並行通知の専用テストは MySQL と pcntl が必要です。testing DB を使う別テスト群と同時に起動しないでください。
