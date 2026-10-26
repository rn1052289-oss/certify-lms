<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('announcement_recipients', function (Blueprint $table) {
            $table->foreignUlid('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('notification_id')->unique();
            $table->primary(['announcement_id', 'user_id']);
        });
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('notification_id');
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20);
            $table->foreignUlid('announcement_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('reminder_delivery_id')->nullable()->constrained('meeting_reminder_deliveries')->cascadeOnDelete();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->unique(['notification_id', 'user_id', 'channel'], 'notification_recipient_channel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('announcement_recipients');
        Schema::dropIfExists('jobs');
    }
};
