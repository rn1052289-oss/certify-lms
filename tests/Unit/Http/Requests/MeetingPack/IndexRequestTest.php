<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\MeetingPack;

use App\Enums\MeetingPackStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IndexRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_passes_with_empty_filters(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->get(route('admin.meeting-packs.index'));

        $response->assertStatus(200);
    }

    public function test_validation_passes_with_boundary_values(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->get(route('admin.meeting-packs.index', [
                'keyword' => str_repeat('a', 100),
                'status' => MeetingPackStatus::Published->value,
                'page' => 1,
            ]));

        $response->assertStatus(200);
    }

    #[DataProvider('validStatuses')]
    public function test_validation_passes_with_valid_status(string $status): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->get(route('admin.meeting-packs.index', [
                'status' => $status,
            ]));

        $response->assertStatus(200);
    }

    #[DataProvider('invalidFilterPayloads')]
    public function test_validation_fails_with_invalid_filter(array $params, string $expectedErrorField): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->getJson(route('admin.meeting-packs.index', $params));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors($expectedErrorField);
    }

    public function test_coach_cannot_access(): void
    {
        $coach = User::factory()->coach()->create();

        $response = $this->actingAs($coach)
            ->get(route('admin.meeting-packs.index'));

        $response->assertStatus(403);
    }

    public function test_student_cannot_access(): void
    {
        $student = User::factory()->student()->create();

        $response = $this->actingAs($student)
            ->get(route('admin.meeting-packs.index'));

        $response->assertStatus(403);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validStatuses(): array
    {
        return [
            'draft' => [MeetingPackStatus::Draft->value],
            'published' => [MeetingPackStatus::Published->value],
            'archived' => [MeetingPackStatus::Archived->value],
        ];
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidFilterPayloads(): array
    {
        return [
            'keyword 101文字' => [
                ['keyword' => str_repeat('a', 101)],
                'keyword',
            ],
            'status 不正値' => [
                ['status' => 'unknown'],
                'status',
            ],
            'page 0' => [
                ['page' => 0],
                'page',
            ],
            'page 文字列' => [
                ['page' => 'abc'],
                'page',
            ],
        ];
    }
}
