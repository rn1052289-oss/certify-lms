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

`.env.example` をコピーすると、外部サービス連携を除く基本機能がローカルで動作します（メールは Mailpit に配信されます）。

- `PUSHER_*` — チャットのリアルタイム配信に使用します。有効にする場合は Pusher のキーを取得して設定し、`BROADCAST_DRIVER=pusher` に変更してください。未設定（既定の `BROADCAST_DRIVER=log`）でもメッセージの送受信自体は動作し、相手画面へのリアルタイム反映のみ行われません

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

設定キャッシュを使用している場合は、変更後に `sail artisan config:clear` を実行してください。画面から新しい質問を送ると実際の Gemini API を呼び出します。
