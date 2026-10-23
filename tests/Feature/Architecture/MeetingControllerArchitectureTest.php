<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Http\Controllers\MeetingController;
use App\UseCases\Meeting\CancelAction;
use App\UseCases\Meeting\FetchAvailabilityAction;
use App\UseCases\Meeting\IndexAction;
use App\UseCases\Meeting\IndexAsCoachAction;
use App\UseCases\Meeting\ShowAction;
use App\UseCases\Meeting\StoreAction;
use App\UseCases\Meeting\UpsertMemoAction;
use Tests\TestCase;

/** 面談の取得・状態変更は各 Action へ委譲し、フォーム表示は除外する。 */
class MeetingControllerArchitectureTest extends TestCase
{
    public function test_each_meeting_operation_delegates_to_its_own_action_without_queries(): void
    {
        $actions = [
            'index' => IndexAction::class,
            'indexAsCoach' => IndexAsCoachAction::class,
            'show' => ShowAction::class,
            'store' => StoreAction::class,
            'cancel' => CancelAction::class,
            'upsertMemo' => UpsertMemoAction::class,
            'fetchAvailability' => FetchAvailabilityAction::class,
        ];
        $lines = file((new \ReflectionClass(MeetingController::class))->getFileName());

        foreach ($actions as $name => $action) {
            $method = new \ReflectionMethod(MeetingController::class, $name);
            $parameters = array_map(fn ($parameter) => $parameter->getType()?->getName(), $method->getParameters());
            $this->assertContains($action, $parameters, "{$name} は専用 Action に委譲してください。");
            $source = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
            $this->assertStringContainsString('$action(', $source, "{$name} は専用 Action を呼び出してください。");
            $this->assertDoesNotMatchRegularExpression('/\b(?:Meeting|MeetingMemo|User|DB|Carbon)::|->(?:query|where|loadMissing|remaining)\s*\(/', $source, "{$name} の業務処理・取得処理は Action に置いてください。");
        }
    }
}
