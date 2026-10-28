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

Google Calendar・Gemini・Stripeのモックテストは、次のグループでまとめて実行・除外できます（T-A-04）。

```bash
sail artisan test --group=external-api
sail artisan test --exclude-group=external-api
```

テスト用のAPIキーは各テスト内のダミー値を使い、実サービスへ接続しません。Google・GeminiはLaravel HTTPのfake、利用側はカレンダー操作のスタブ、Stripe CheckoutはSDKのHTTPクライアント差し替え、Webhookは`Tests\Support\ExternalApi\StripeSignature`で作った署名を使います。

`Tests\TestCase`は未fakeのLaravel HTTP通信と未mockのStripe SDK通信を止め、アプリが通信例外を捕捉してもテスト終了時に失敗させます。URL・ヘッダー・本文を通信漏れのエラーへ含めません。Geminiは既存仕様どおり自動再試行せず、一時障害後に呼出側が明示的に再送するケースを検証します。並行処理テストも含むため、同じ`testing` DBを使うテスト群を同時起動しないでください。

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

コーチ本人の Google Calendar と連携し、先約を面談の候補から除外して、予約・取消を予定へ反映します。未設定では連携できませんが、通常の面談予約は引き続き利用できます。連携済みでも Google の通信に失敗した場合は警告を返し、LMS の予約・取消を維持します。 空き時間確認の通信待ちは、トークン更新を含めて 1 リクエスト内の全コーチで 5 秒を共有し、期限後は警告付きで通常の予約処理へ進みます。

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

### AI 相談（S-A-02）

学習中の受講生本人のみ利用できます。Gemini の API キーを取得し、利用する環境の `.env` に以下を設定してください。キーはコミットしないでください。

- `AI_CHAT_ENABLED` — 既定は `false`（無効）。`true` にすると AI 相談の画面・ナビ・小窓を利用できます
- `GEMINI_API_KEY` — Gemini の API キー。未設定の場合は送信できない旨を案内します
- `GEMINI_MODEL` — 既定は `gemini-3.5-flash-lite`。対象プロジェクトで利用できるモデルを指定してください
- `AI_CHAT_AUTO_TITLE` — 既定は `true`。初回の正常な AI 回答が完了したときに1回だけ自動命名します。本人が手動編集したタイトルは上書きしません
- `AI_CHAT_SYSTEM_PROMPT` — 任意。未設定なら `config/ai-chat.php` の日本語の案内文を使います

送信は受講生1人につき1日20回までです。AI 応答に失敗した送信も回数へ含め、会話を削除しても利用回数は戻りません。本文は2000文字、タイトルは100文字までです。

#### 提供済み Blade を変更した箇所・理由・根拠

次の5ファイルだけを変更しています。上から4件はPM回答で許可された表示変更で、最後の1件はレビューで見つかった入力消失を防ぐため、変更内容を提示して本人の許可を得たものです。画面構成・デザインは維持しています。

| 対象ファイル（`resources/views/` 以下） | 変更内容 | なぜ変更したか・根拠 |
|---|---|---|
| `ai-chat/_partials/message-bubble.blade.php` | 回答欄の応答時間・トークン数の表示を削除 | Ticket Docはこれらを「内部記録として残し、受講生には表示しない」としています。PMからも非表示の回答がありました。提供済みBladeが表示していた部分だけを削除し、回答本文・成功/エラー表示は残します。 |
| `ai-chat/show.blade.php` | 全画面のモデル名表示を削除 | 同じく運用情報を受講生へ表示しない要件と、全画面のモデル名を非表示にするPM回答に合わせます。タイトル編集・履歴・質問入力の構成は変えません。 |
| `components/ai-chat/floating-widget.blade.php` | 小窓のモデル名表示を削除 | 小窓にも同じ非表示要件が適用されます。全画面だけ直すと小窓にはモデル名が残るため、この表示も削除します。小窓の開閉・質問入力・全画面への導線は残します。 |
| `layouts/_partials/sidebar-student.blade.php` | AI相談ナビを `config('ai-chat.enabled', false)` の条件内へ移動 | 機能OFFではUI・画面・ルートとも利用できない要件と、OFF時にナビを非表示にするPM回答が根拠です。画面のリンクを隠すだけで認可を代替せず、`EnsureAiChatEnabled` によるルートの制御も維持します。通常のチャットのリンクは残します。 |
| `ai-chat/_partials/new-conversation-modal.blade.php` | 最初の質問欄に `:value="old('message')"` を1行追加 | APIキー未設定・日次上限による受付拒否で、入力した質問文が消えるレビュー指摘への対応です。Controllerが `withInput` で戻しても、既存の `components/form/textarea.blade.php` は受け取った `$value` を表示するだけで、自動では以前の入力を読みません。ここで明示的に渡す必要があります。 |

応答時間・トークン数・モデル名を画面から隠しても、`GeminiClient` が返した値を `SendMessageAction` が `AiChatMessage` と運用ログへ記録する処理は残しています。表示を削除することと、DBの値を消すことは別です。日次回数やエラー履歴も変更しません。

質問文の復元は、受付に失敗した後も本人が入力内容を確認して再送できるようにするためです。流れは「フォームの `message` → Controllerの `withInput` → 本人のセッションにある直前の入力 → `old('message')` → textareaの `$value`」です。`old` は直前の入力を取り出すLaravelの関数で、質問をAIへ送る処理でも、会話履歴をDBへ保存する処理でもありません。表示は既存部品の `{{ $value }}` によるエスケープを使います。

受付前に拒否された503/429では、会話の新規作成と質問受付を一緒に取り消し、空会話を残さず、日次回数も増やしません。実際にGeminiへ送った後の失敗は別で、質問・エラーの履歴と使用回数を残します。全画面は入力を戻して案内し、小窓は初回の会話作成と質問を1回のHTTP処理で受け付けます。

確認では、表示を隠しても回答とDBの運用値が残ること、機能ON/OFFでAIナビだけが切り替わること、503/429でも質問入力が戻って空会話が作られないこと、復旧後に同じ質問を再送できることを回帰テストで確かめます。

教材の小窓では「新しい相談」を押した後も、PM確定仕様に従って同じ教材の最新会話を再利用します。送信後に保存済み履歴を取得して表示を揃え、画面だけ空のまま過去の会話をAIへ渡す状態を防ぎます。履歴取得に失敗した場合も、今回の質問・回答は画面に残します。別会話を作る仕様やBladeは変更していません。

履歴JSONの会話情報は、表示に必要な `id`・`title`・`enrollment_id`・`section_id` に限定しています。公開停止後に編集した教材本文や受講登録の関連データは返しません。本人の過去の相談履歴は引き続き表示できます。

教材を削除した場合は会話の `section_id` だけを NULL にし、会話・メッセージ・日次利用回数を残します。既存のAI相談テーブルにも反映するため、外部キーだけを変更する追加migrationを用意しています。更新時は `sail artisan migrate` を実行してください。

既存の会話でも、別タブでAIが応答中・入力不正・日次上限・APIキー未設定で送信を受け付けられない場合（409・422・429・503）は、小窓・全画面とも質問を入力欄へ戻します。409では応答完了後の送信を案内します。全画面で送信中に書いた別の下書きは上書きせず、通信後に保存された502の質問・エラー履歴は従来どおり保持します。

設定キャッシュを使用している場合は、変更後に `sail artisan config:clear` を実行してください。画面から新しい質問を送ると実際の Gemini API を呼び出します。

### Stripe（追加面談購入）

- `STRIPE_SECRET` — Stripe の検証用シークレットキーを設定します。未設定の場合は購入画面への送信を受け付けません
- `STRIPE_WEBHOOK_SECRET` — 決済通知の署名検証に使用します。ローカルでは下記の `stripe listen` が表示する値を設定します。Dashboard に登録した通知先の値とは別です

Stripe のサンドボックスと、本プロジェクト専用の Stripe CLI 設定を使用してください。

```bash
stripe login --project-name=certify-lms-verification
stripe listen --project-name=certify-lms-verification \
    --events checkout.session.completed,checkout.session.async_payment_succeeded,checkout.session.async_payment_failed,checkout.session.expired \
    --forward-to http://localhost:8000/webhooks/stripe
```

転送先のポートはアプリの起動先に合わせます。`APP_URL` もブラウザーから開ける URL にそろえてください。設定後は `sail artisan config:clear` を実行し、起動中のアプリを再起動します。キーや署名検証用の値は Git・画面撮影・共有ログへ含めないでください。

学習中の受講生は公開中の面談パックを購入できます。金額・回数は購入開始時に保存し、署名検証済みの正常完了通知を受け取った時に回数を加算します。成功画面の表示・再読込では加算せず、同じ通知を受け取っても二重加算しません。購入開始後に卒業・退会しても正常完了分は購入時の回数を加算し、利用できるかは既存の状態・認可に従います。

`PaymentSeeder` は実際の Stripe 通信を行わず、完了・保留・失敗の購入履歴を用意します。実接続の確認には Stripe 側の設定とテスト決済が別途必要です。カード番号を LMS に保存しません。

2026-10-22 に専用の検証 DB と Stripe Sandbox を使用し、1 回パック（JPY 3,000）の Checkout・テスト決済・署名付き Webhook の実受信、完了画面と購入履歴の再読み込みを確認しました。残面談回数は 3 → 4 回となり、取消・カード拒否では増加しませんでした。拒否後の再決済では、実受信した同じ本文・署名をローカル中継から3回送信してすべて HTTP 200、付与履歴は1件・残回数は 4 → 5 回でした。Stripe Dashboard からの再送操作と、卒業・退会後に届く実決済は未確認です（卒業・退会後の反映は自動テストで確認）。


### 修了証 PDF（S-A-04）

「修了証を受け取る」で新規発行した修了証は、提供済みテンプレートから日本語 PDF を生成し、`private` ディスクの `certificates/` 配下へ保存します。mPDF と同梱の日本語フォントを使うため、追加の環境変数は不要です。`storage/app/private` と `storage/framework/cache/mpdf` をアプリの実行ユーザーが書き込める状態にしてください。

ダウンロード先は `GET /certificates/{certificate}/download` です。本人（卒業後を含む）・資格に現在割り当てられているコーチ・管理者だけが取得できます。公開ストレージへのリンクは作りません。

`EnrollmentSeeder`・`CertificateSeeder` も新規発行時に実 PDF を生成します。既発行レコードの PDF が欠けていても、Seeder やダウンロードで補完・再発行・差し替えはしません。

## 通知・メールのキュー運用（T-A-05）

チャット、Q&A返信、面談の予約・取消・リマインダー、お知らせ、招待・再招待、パスワード再設定は、database queueで処理します。通知内容・宛先・アプリ内／メールのチャネルは維持します。お知らせは受付時に「配信を受け付けました」と表示し、件数・最終失敗件数・配信日時はworkerの結果を記録します。投入時点では送信済みになりません。

### 起動と更新

`.env.example` の `QUEUE_CONNECTION=database` を自分の開発・実行環境にも設定してください。既存の実設定ファイルは自動変更しません。設定キャッシュを使っている場合は更新後にクリアします。

```bash
sail artisan migrate
sail artisan config:clear
sail up -d --force-recreate laravel.test
```

ローカルのSail環境では、`docker/supervisord.conf` をコンテナへ読み込み、HTTPサーバーと別プロセスのworkerを一緒に起動します。workerは既存の `notifications,default` の両キューを処理し、通知・メールなどのDBキュージョブを実行します。終了時はSupervisorが再起動するため、手動の `queue:work` を重ねて起動する必要はありません。初回の設定反映には上記のコンテナ再作成が必要です。停止時は処理中のジョブを終了できるよう、workerの待機上限を60秒、コンテナの停止猶予を75秒にしています。

コードや接続設定の更新後は `sail artisan queue:restart` でworkerを再読み込みしてください。workerは現在のジョブを終えて終了し、Supervisorが起動し直します。ローカルSail以外のサーバー運用では、別途プロセス管理ツールで `php artisan queue:work database --queue=notifications,default --sleep=1 --tries=3 --timeout=45` を監視・再起動してください。定期リマインダーの既存スケジュール自体は変更していません。

通知・招待メールは最大3回試行し、失敗後は30秒、120秒と待って再試行します。ジョブのtimeoutは45秒、database queueの `retry_after` は90秒です。timeoutをretry_afterより短く保ち、SMTPやHTTP transportにも45秒未満の接続・応答timeoutを設定してください。workerの強制終了だけでは、外部サーバーが受信したメールを取り消せません。

### 失敗と再投入

```bash
sail artisan queue:failed
sail artisan queue:retry <失敗ジョブのUUID>
```

ローカルSailでは再投入したジョブも常駐workerが処理します。常駐workerを用意していない環境でのみ、別途 `sail artisan queue:work database --queue=notifications,default --stop-when-empty --timeout=45` を実行してください。

再試行中の一時失敗は、お知らせの最終失敗件数に含めません。上限到達後は `failed_jobs` に残り、お知らせには失敗した受講生の件数・IDを記録します。再投入で成功すると当該失敗を解消し、既に成功したアプリ内通知やメールを再送・二重集計しません。リマインダーのコマンド出力は送信済件数ではなく投入受付件数です。配信結果は `meeting_reminder_deliveries` と `notification_deliveries` で確認します。

お知らせの初回投入に失敗した場合も、受付内容・対象者・通知IDは保存して受付を返し、例外を記録します。この段階ではジョブが存在しないため `queue:retry` は使えません。キュー接続を復旧した後、管理画面の詳細URLにあるお知らせIDを指定して、保存済みの受付から再開してください。新しいお知らせを作り直す必要はありません。

```bash
sail artisan notifications:retry-announcement <お知らせID>
```

再開コマンド自身の投入も失敗した場合は終了コード1を返します。復旧して同じIDで再実行してください。再開で対象者や通知IDは変わらず、成功済み・投入済みのチャネルは再投入しません。個別通知の最終失敗や、対象者へジョブを展開する処理の最終失敗は `queue:failed` のUUIDを使って `queue:retry` してください。展開処理が上限回数に達したときは、まだ投入できていないチャネルだけを失敗として記録します。既に投入済みで待機中のチャネルは最終失敗に含めません。

送信中は同じ通知・受信者・チャネルの成功記録だけをロックします。お知らせ全体のロックは送信のトランザクション終了後の集計時に取得するため、別の受講生へのSMTP送信を待たせません。

お知らせ受付のコミット後、個別ジョブの保存と `queued_at` の記録を同じDB接続の短いトランザクションで確定します。保存直後・記録直後の例外では両方を取り消し、未記録の待機ジョブを残しません。正常に投入されて待機中のチャネルは最終失敗や処理完了に含めません。通知を直接呼ぶ場合のコミット後投入も維持しています。

通知はチャネル別に成功を記録します。workerがSMTP成功応答を受けた直後、DBへ成功記録を残す前に異常終了した場合は、メールが届いたかをDBだけで確定できません。この場合はメールサーバーの配信ログを照合してから再投入してください。SMTPには受信とDB更新を一括コミットする仕組みがないため、この障害境界でのメールの厳密な一度だけの配信は保証しません。

招待メールはworker実行時にも期限・失効状態を確認し、再招待で失効した旧リンクや期限切れリンクを送りません。パスワード再設定も有効なトークンだけを送ります。トークン等を含むキュージョブは暗号化し、既存の署名付きURLの有効期限・宛先・本文を維持します。workerとWebプロセスは同じAPP_KEYを使用し、保留中ジョブがある状態でキーを変更しないでください。`jobs` / `failed_jobs` は運用者のみが閲覧できるようにし、payloadやメールのリンクをログ・証跡へ貼り付けないでください。

### ローカルでの動作確認

既存のデモデータを用意した専用検証DBで、次のSeederを実行します。実ユーザーや実SMTPを使わず、Mailpit等のローカル受信箱を使ってください。

```bash
sail artisan db:seed --class=NotificationQueueVerificationSeeder
```

`queue-reset@certify-lms.test` の架空アカウントを用意します。worker停止中に管理者お知らせ、架空コーチの招待・再招待、検証アカウントのパスワード再設定を受け付け、メール未送信・履歴0件・配信日時未確定を確認します。その後workerを起動して件数・受信箱を確認します。別の専用環境でSMTPを接続不能にして一時失敗、3回で最終失敗、復旧後の `queue:retry` を検証してください。実設定ファイルや既存プロジェクトのworkerは変更しません。

### 管理者ダッシュボードの集計キャッシュ（T-A-06）

管理者KPI（資格別内訳を含む）と資格別修了率は、既定のキャッシュ保存先に300秒保存します。
`ADMIN_DASHBOARD_CACHE_TTL` で秒数を変更できます（0以下では既存キャッシュの読取・保存を行いません）。
新規受講登録・合格・学習中止・再挑戦・受講解除・退会後に、外側のDBトランザクションのコミットを待って両集計を無効化します。
ロールバック時は既存キャッシュを保持し、トランザクション内の集計値は共有キャッシュへ保存しません。
資格名など状態遷移以外の変更はTTL失効後に反映します。集計対象・計算式・表示内容は従来どおりです。

画面検証には専用の空DB（local/testing）で `sail artisan db:seed --class=AdminDashboardCacheVerificationSeeder` を実行します。受講登録100件（受講中80・修了15・学習中止5）を作成します。既存ユーザーがいるDBへの実行は拒否します。

キャッシュの読取・書込に例外が出た場合はログへ記録してDB集計を返します。無効化は世代の書込と両キーの削除を個別に試み、キャッシュ例外を確定済みの業務処理へ返しません。保存先の障害で無効化できなかった既存値は、復旧後もTTL失効まで残る場合があります。
