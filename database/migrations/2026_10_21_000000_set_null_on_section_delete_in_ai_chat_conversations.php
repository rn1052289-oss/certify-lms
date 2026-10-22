<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_chat_conversations', function (Blueprint $table) {
            $table->dropForeign(['section_id']);
            // 教材を削除しても、本人の相談履歴は残す。
            $table->foreign('section_id')->references('id')->on('sections')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_chat_conversations', function (Blueprint $table) {
            $table->dropForeign(['section_id']);
            $table->foreign('section_id')->references('id')->on('sections')->restrictOnDelete();
        });
    }
};
