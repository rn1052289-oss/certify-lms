<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasDuplicates = DB::table('meetings')
            ->select('coach_id', 'scheduled_at')
            ->whereIn('status', ['reserved', 'completed'])
            ->groupBy('coach_id', 'scheduled_at')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicates) {
            throw new RuntimeException('有効な面談予約が重複しています。予約内容を確認してから再実行してください。');
        }

        Schema::table('meetings', function (Blueprint $table) {
            // キャンセル履歴はNULLにし、同じ枠への再予約を妨げない。
            $table->unsignedTinyInteger('active_slot')->nullable()
                ->virtualAs("CASE WHEN status IN ('reserved', 'completed') THEN 1 ELSE NULL END");
            $table->unique(['scheduled_at', 'coach_id', 'active_slot'], 'meetings_active_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropUnique('meetings_active_slot_unique');
            $table->dropColumn('active_slot');
        });
    }
};
