<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Enrollment;
use App\Services\GoogleCalendarService;
use App\Services\MeetingAvailabilityService;
use Carbon\Carbon;

/** 予約画面の空き枠と同期警告を取得するユースケース。 */
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
