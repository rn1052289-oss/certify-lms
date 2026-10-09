<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enrollment 単位のコーチ・管理者向け内部メモ。
 *
 * 1 Enrollment に複数のメモを持てる。
 * メモ自体は物理削除する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->foreignUlid('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['enrollment_id', 'created_at']);
            $table->index('author_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_notes');
    }
};
