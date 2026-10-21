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

## 既存不具合の修正メモ

- **同一受講生の同時予約（B-A-04）**: 面談予約の処理開始時に受講生の行をロックし、そのうえで残面談回数を確認します。以前はロック後の再確認も、ロック前の最初の読み取りで確定したスナップショット（MySQL の REPEATABLE READ）を参照していたため、同じ受講生が別の時間枠へ同時に予約すると、残り回数を超えて両方成立していました。回帰テスト: `tests/Feature/Http/Meeting/MeetingQuotaConcurrencyTest.php`
- **60 分間対応できないコーチへの割当（B-A-05）**: 面談は毎時 00 分開始・60 分単位のため、コーチの対応可能時間は 00 分単位でのみ登録・更新できるようにしました。予約確定時は開始時刻だけでなく 60 分間すべてが枠に収まるコーチだけを割り当てます。以前は開始時刻が枠内かだけを見ていたため、同じ時刻に別のコーチの枠がある場合、9:30〜10:30 の枠しかないコーチにも 10:00〜11:00 の面談が割り当てられることがありました。修正前に登録された 00 分以外の枠は、次の 00 分から予約枠として表示します。この枠を編集するときは 00 分に直して保存してください（削除はそのままできます）。あわせて、時間枠の追加・編集で入力エラーになったときは、送信したモーダルを開いた状態でエラーを表示し、他のモーダルには入力値やエラーを出さないようにしました。回帰テスト: `tests/Feature/Http/Meeting/MeetingCoachAssignmentTest.php` ほか

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

`.env.example` をコピーすると通常のローカル機能が動作します（メールは Mailpit に配信されます）。外部サービスとの実連携には、以下の設定が必要です。

- `PUSHER_*` — チャットのリアルタイム配信に使用します。有効にする場合は Pusher のキーを取得して設定し、`BROADCAST_DRIVER=pusher` に変更してください。未設定（既定の `BROADCAST_DRIVER=log`）でもメッセージの送受信自体は動作し、相手画面へのリアルタイム反映のみ行われません

### Google Calendar 連携（S-A-01）

コーチ本人の Google Calendar と連携し、先約を面談の候補から除外して、予約・取消を予定へ反映します。未設定では連携できませんが、通常の面談予約は引き続き利用できます。連携済みでも Google の通信に失敗した場合は警告を返し、LMS の予約・取消を維持します。

| 環境変数 | 設定内容 |
|---|---|
| `GOOGLE_CALENDAR_CLIENT_ID` | Google Cloud で作成した Web アプリケーション用 OAuth クライアント ID |
| `GOOGLE_CALENDAR_CLIENT_SECRET` | 同クライアントのシークレット（Git や画面・ログに公開しない） |
| `GOOGLE_CALENDAR_REDIRECT_URI` | Google に登録する callback URL。既定のローカル環境では `http://localhost:8000/settings/google-calendar/callback` |

1. Google Cloud で Google Calendar API を有効にし、OAuth 同意画面と Web アプリケーション用クライアントを用意します。外部向けのテスト公開では、連携する Google アカウントをテストユーザーに登録してください。
2. 承認済みリダイレクト URI と `GOOGLE_CALENDAR_REDIRECT_URI` を、ポート・末尾の `/` を含めて完全一致させます。要求する scope は `https://www.googleapis.com/auth/calendar.events` と `https://www.googleapis.com/auth/calendar.freebusy` です。
3. 手元の `.env` に上記3項目を設定し、`sail artisan config:clear` を実行します。コーチでログイン後、`/settings/google-calendar/connect` から Google の同意画面へ進み、両方の権限を許可してください。接続先は本人の primary カレンダーです。
4. 先約と重なる枠の除外、予約後の予定作成、取消後の予定削除、連携解除後も既存予定が残ることを確認します。2026-10-21 にローカル Docker 環境で実 Google アカウントを使い、上記の同期と、通信失敗時も警告を表示して LMS の予約・取消が継続することを確認しました。

表示・障害時の検証には、通常のユーザーデータを投入した local/testing 環境で `sail artisan db:seed --class=GoogleCalendarVerificationSeeder` を使用できます。固定コーチのダミー接続と未接続の状態を用意するだけで、実際の Google 接続は成立しません。既存の実トークンは上書きしません。実同期の確認には、別途本人の OAuth 同意が必要です。

OAuth トークンを DB へ暗号化して保存する対応は今回の対象外です。本番運用時は、保存するトークンの暗号化を推奨します。

新しい環境変数やセットアップ手順を追加した場合は、`.env.example` と本 README に追記し、チームの誰でも環境を再現できる状態を保ってください。
