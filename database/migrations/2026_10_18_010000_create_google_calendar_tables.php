<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_credentials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->unique()->constrained()->restrictOnDelete();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('expires_at');
            $table->string('calendar_id')->default('primary');
            $table->timestamp('connected_at');
            $table->timestamps();
        });

        Schema::create('meeting_google_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('meeting_id')->unique()->constrained()->restrictOnDelete();
            // 解除時に外部予定は消さず、別アカウントへの再連携にも引き継がない。
            $table->foreignUlid('google_credential_id')->nullable()->constrained()->nullOnDelete();
            $table->string('calendar_id');
            $table->string('event_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_google_events');
        Schema::dropIfExists('google_credentials');
    }
};
