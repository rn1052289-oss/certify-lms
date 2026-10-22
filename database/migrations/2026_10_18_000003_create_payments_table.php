<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUlid('meeting_pack_id')->constrained('meeting_packs')->restrictOnDelete();
            $table->string('meeting_pack_name', 100);
            $table->unsignedInteger('amount');
            $table->unsignedSmallInteger('quantity');
            $table->char('currency', 3)->default('jpy');
            $table->string('status', 20)->default('pending');
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::table('meeting_quota_transactions', function (Blueprint $table) {
            $table->foreign('related_payment_id')->references('id')->on('payments')->restrictOnDelete();
            // NULL の既存履歴は複数保持し、購入 1 件につき付与履歴は 1 件に制限する。
            $table->unique('related_payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_quota_transactions', function (Blueprint $table) {
            $table->dropForeign(['related_payment_id']);
            $table->dropUnique(['related_payment_id']);
        });

        Schema::dropIfExists('payments');
    }
};
