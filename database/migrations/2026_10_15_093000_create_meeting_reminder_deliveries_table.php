<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_reminder_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('window', 20);
            $table->uuid('notification_id')->unique();
            $table->timestamp('database_sent_at')->nullable();
            $table->timestamp('mail_sent_at')->nullable();
            $table->timestamps();
            $table->unique(['meeting_id', 'window', 'user_id'], 'meeting_reminder_recipient_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_reminder_deliveries');
    }
};
