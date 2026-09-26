<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\MeetingPack;

use App\Models\MeetingPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UpdateRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_passes_with_minimum_boundary_values(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($admin)
            ->patch(route('admin.meeting-packs.update', $plan), [
                'name' => 'a',
                'description' => null,
                'meeting_count' => 1,
                'price' => 0,
                'stripe_price_id' => null,
                'sort_order' => 0,
            ]);

        $response->assertStatus(302);
        $response->assertSessionDoesntHaveErrors();
    }

    public function test_validation_passes_with_maximum_boundary_values(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($admin)
            ->patch(route('admin.meeting-packs.update', $plan), [
                'name' => str_repeat('a', 100),
                'description' => str_repeat('b', 2000),
                'meeting_count' => 100,
                'price' => 1000000,
                'stripe_price_id' => str_repeat('c', 255),
                'sort_order' => 4294967295,
            ]);

        $response->assertStatus(302);
        $response->assertSessionDoesntHaveErrors();
    }

    public function test_validation_passes_when_optional_fields_are_omitted(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($admin)
            ->patch(route('admin.meeting-packs.update', $plan), [
                'name' => '面談パック',
                'meeting_count' => 5,
                'price' => 15000,
            ]);

        $response->assertStatus(302);
        $response->assertSessionDoesntHaveErrors();
    }

    #[DataProvider('invalidPayloads')]
    public function test_validation_fails_with_invalid_payload(array $overrides, string $expectedErrorField): void
    {
        $admin = User::factory()->admin()->create();
        $plan = MeetingPack::factory()->draft()->create();

        $payload = array_merge([
            'name' => '面談パック',
            'description' => '説明です。',
            'meeting_count' => 5,
            'price' => 15000,
            'stripe_price_id' => 'price_test',
            'sort_order' => 10,
        ], $overrides);

        $response = $this->actingAs($admin)
            ->patchJson(
                route('admin.meeting-packs.update', $plan),
                $payload,
            );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors($expectedErrorField);
    }

    public function test_coach_cannot_update_meeting_pack(): void
    {
        $coach = User::factory()->coach()->create();
        $plan = MeetingPack::factory()->draft()->create();

        $response = $this->actingAs($coach)
            ->patchJson(route('admin.meeting-packs.update', $plan), [
                'name' => '更新後の面談パック',
                'meeting_count' => 5,
                'price' => 15000,
            ]);

        $response->assertStatus(403);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'name 未指定' => [
                ['name' => ''],
                'name',
            ],
            'name 101文字' => [
                ['name' => str_repeat('a', 101)],
                'name',
            ],
            'description 2001文字' => [
                ['description' => str_repeat('b', 2001)],
                'description',
            ],
            'meeting_count 0' => [
                ['meeting_count' => 0],
                'meeting_count',
            ],
            'meeting_count 101' => [
                ['meeting_count' => 101],
                'meeting_count',
            ],
            'meeting_count 整数以外' => [
                ['meeting_count' => 'abc'],
                'meeting_count',
            ],
            'price -1' => [
                ['price' => -1],
                'price',
            ],
            'price 1000001' => [
                ['price' => 1000001],
                'price',
            ],
            'price 整数以外' => [
                ['price' => 'abc'],
                'price',
            ],
            'stripe_price_id 256文字' => [
                ['stripe_price_id' => str_repeat('c', 256)],
                'stripe_price_id',
            ],
            'sort_order -1' => [
                ['sort_order' => -1],
                'sort_order',
            ],
            'sort_order 4294967296' => [
                ['sort_order' => 4294967296],
                'sort_order',
            ],
            'sort_order 整数以外' => [
                ['sort_order' => 'abc'],
                'sort_order',
            ],
        ];
    }
}
