<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('enrollment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUlid('section_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('title', 100)->default('新しい相談');
            $table->boolean('title_manually_edited')->default(false);
            $table->timestamp('auto_title_attempted_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'last_message_at']);
            $table->index(['user_id', 'section_id']);
        });

        Schema::create('ai_chat_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('ai_chat_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->string('status');
            $table->longText('content');
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->string('error_detail')->nullable();
            $table->timestamps();
            $table->index(['ai_chat_conversation_id', 'created_at']);
        });

        // 会話を削除しても、その日の利用回数は減らさない。
        Schema::create('ai_chat_daily_usages', function (Blueprint $table) {
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->date('usage_date');
            $table->unsignedInteger('attempts')->default(0);
            $table->primary(['user_id', 'usage_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_daily_usages');
        Schema::dropIfExists('ai_chat_messages');
        Schema::dropIfExists('ai_chat_conversations');
    }
};
