<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Enrollment;
use App\Services\GoogleCalendarService;
use App\Services\MeetingAvailabilityService;
use Carbon\Carbon;

/**
 * 予約画面の空き枠と同期警告を取得するユースケース。
 *
 * 受講生の面談予約画面に、指定日 1 日分の空き枠を返すユースケース。
 *
 * 空き枠は MeetingAvailabilityService で集計し、Google Calendar との同期に失敗した場合は警告文を添える。
 */
final class FetchAvailabilityAction
{
    public function __construct(
        private readonly MeetingAvailabilityService $availabilityService,
        private readonly GoogleCalendarService $calendar,
    ) {}

    /** @return array{date: string, warning: ?string, slots: array} */
    public function __invoke(Enrollment $enrollment, string $date): array
    {
        $date = Carbon::parse($date);
        $slots = $this->availabilityService->slotsForCertification(
            $enrollment->loadMissing('certification')->certification,
            $date,
        );

        return [
            'date' => $date->toDateString(),
            'warning' => $this->calendar->warning(),
            'slots' => $slots->map(fn (array $slot) => [
                'slot_start' => $slot['slot_start']->toIso8601String(),
                'slot_end' => $slot['slot_end']->toIso8601String(),
                'available_coach_count' => $slot['available_coach_count'],
            ])->all(),
        ];
    }
}
