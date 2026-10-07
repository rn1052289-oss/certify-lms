<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enrollment 単位の個人学習目標。
 *
 * 1 Enrollment に複数の目標を持てる。
 * 達成状態は achieved_at の有無で表し、目標自体は物理削除する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_goals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->string('title', 100);
            $table->text('description')->nullable();
            $table->date('target_date')->nullable();
            $table->timestamp('achieved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_goals');
    }
};
