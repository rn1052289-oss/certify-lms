<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MeetingStatus;
use App\Models\GoogleCredential;
use App\Models\Meeting;
use App\Models\MeetingGoogleEvent;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Google 障害は LMS の予約・取消を巻き戻さず、同じリクエストに警告を返す。
 *
 * コーチの Google Calendar と面談予約を同期する Service。
 *
 * - 空き時間の確認: 先約(busy)と重なる枠を予約候補から外す
 * - 予約・取消: Google Calendar に予定を作成 / 削除する
 *
 * Google との通信に失敗しても例外は投げず、LMS 側の予約・取消を優先する。失敗は warning() で画面の警告に使い、
 * ログには例外クラスだけを残す。busy の取得は 1 リクエスト内で通信待ち 5 秒を全コーチで共有し、結果をキャッシュする。
 */
class GoogleCalendarService
{
    public const WARNING = 'Google Calendarとの同期に失敗した';

    private bool $failed = false;

    private array $busyCache = [];

    private ?float $busyDeadline = null;

    public function __construct(private GoogleCalendarClient $client) {}

    /**
     * このリクエスト内で同期に失敗していれば警告文を返す。
     */
    public function warning(): ?string
    {
        return $this->failed ? self::WARNING : null;
    }

    /**
     * 連携済みコーチごとの予定(busy)を返す。未連携のコーチは含めず、取得に失敗したコーチは空配列にする。
     *
     * @param Collection<int, User> $coaches
     *
     * @return array<string, array<int, array{start: string, end: string}>>
     */
    public function busyForCoaches(Collection $coaches, CarbonInterface $start, CarbonInterface $end): array
    {
        $busy = [];
        foreach ($coaches as $coach) {
            $credential = $coach->googleCredential;
            if ($credential === null) {
                continue;
            }
            $cached = $this->busyCache[$credential->id] ?? null;
            if ($cached !== null && $cached['start']->lte($start) && $cached['end']->gte($end)) {
                $busy[$coach->id] = $cached['periods'];

                continue;
            }
            try {
                // 担当コーチ数や同一リクエスト内の再取得で、通信待ち時間を積み上げない。
                $this->busyDeadline ??= hrtime(true) / 1_000_000_000 + 5;
                $periods = $this->client->busy($credential, $start, $end, $this->busyDeadline);
                foreach ($periods as $period) {
                    // 壊れた外部データもフォールバックの対象にする。
                    if (! is_string($period['start'] ?? null) || ! is_string($period['end'] ?? null) || Carbon::parse($period['start'])->gte(Carbon::parse($period['end']))) {
                        throw new \RuntimeException('Invalid Google busy period.');
                    }
                }
                $busy[$coach->id] = $periods;
            } catch (Throwable $exception) {
                $busy[$coach->id] = [];
                $this->recordFailure('free_busy', $exception);
            }
            // 予約 POST の日単位検証と割当判定は同じ取得結果を使う（リクエスト内のみ）。
            $this->busyCache[$credential->id] = ['start' => $start->copy(), 'end' => $end->copy(), 'periods' => $busy[$coach->id]];
        }

        return $busy;
    }

    /**
     * 予定のいずれかが [start, end) と重なるかを返す。終了と開始が一致するだけなら重ならない。
     *
     * @param array<int, array{start: string, end: string}> $periods
     */
    public function overlaps(array $periods, CarbonInterface $start, CarbonInterface $end): bool
    {
        foreach ($periods as $period) {
            // [開始, 終了) の重なり。終了と開始が一致するだけなら予約できる。
            if (Carbon::parse($period['start'])->lt($end) && Carbon::parse($period['end'])->gt($start)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 面談の予定をコーチの Google Calendar に作成し、MeetingGoogleEvent に対応を記録する。
     *
     * 予約確定後に呼ぶ。外部通信中に取消された場合は作成した予定を片付ける。
     */
    public function reserve(Meeting $meeting): void
    {
        $credential = GoogleCredential::query()->where('user_id', $meeting->coach_id)->first();
        if ($credential === null || $meeting->fresh()?->status !== MeetingStatus::Reserved) {
            return;
        }
        try {
            $eventId = $this->client->createEvent($credential, [
                'id' => hash('sha256', $meeting->id),
                'summary' => 'Certify LMS 面談',
                'description' => $meeting->meeting_url_snapshot ?? '',
                'location' => $meeting->meeting_url_snapshot ?? '',
                'start' => ['dateTime' => $meeting->scheduled_at->toRfc3339String()],
                'end' => ['dateTime' => $meeting->scheduled_at->copy()->addHour()->toRfc3339String()],
            ]);
            $canceled = DB::transaction(function () use ($meeting, $credential, $eventId): bool {
                $locked = Meeting::query()->whereKey($meeting->id)->lockForUpdate()->firstOrFail();
                // 解除済みの接続を復活させず、その接続の予定も削除しない。
                if (! GoogleCredential::query()->whereKey($credential->id)->exists()) {
                    return false;
                }
                MeetingGoogleEvent::query()->updateOrCreate(['meeting_id' => $meeting->id], [
                    'google_credential_id' => $credential->id,
                    'calendar_id' => $credential->calendar_id,
                    'event_id' => $eventId,
                ]);

                return $locked->status === MeetingStatus::Canceled;
            });
            if ($canceled) {
                // 削除失敗時も対応表を残し、外部に作成済みであることを追跡できるようにする。
                $this->cancel($meeting);
            }
        } catch (Throwable $exception) {
            $this->recordFailure('reserve', $exception);
        }
    }

    /**
     * 面談の予定を Google Calendar から削除し、MeetingGoogleEvent を削除する。連携を解除済みなら何もしない。
     */
    public function cancel(Meeting $meeting): void
    {
        $event = MeetingGoogleEvent::query()->with('credential')->where('meeting_id', $meeting->id)->first();
        if ($event?->credential === null) {
            return;
        }
        try {
            $this->client->deleteEvent($event->credential, $event->calendar_id, $event->event_id);
            $event->delete();
        } catch (Throwable $exception) {
            $this->recordFailure('cancel', $exception);
        }
    }

    private function recordFailure(string $operation, Throwable $exception): void
    {
        $this->failed = true;
        // OAuth/HTTP 例外の本文にはトークン等が入り得るため、クラスだけを記録する。
        Log::warning('Google Calendar sync failed.', ['operation' => $operation, 'exception_type' => $exception::class]);
    }
}
