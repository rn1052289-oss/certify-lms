<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Part;

use App\Models\Certification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ContentTestHelpers;
use Tests\TestCase;

/**
 * B-B-01: 担当外のコーチが教材の更新・削除・公開・並べ替えを直接送っても拒否され、教材が変わらないこと。
 */
class UnassignedCoachWriteTest extends TestCase
{
    use ContentTestHelpers, RefreshDatabase;

    public function test_unassigned_coach_cannot_update_delete_publish_or_reorder_content(): void
    {
        $cert = Certification::factory()->published()->create();
        $assigned = User::factory()->coach()->create();
        $this->assignCoach($assigned, $cert);
        $other = User::factory()->coach()->create();
        [$part, $chapter, $section] = $this->makePartChain($cert, 'draft');
        $fields = ['title' => '担当外からの更新', 'description' => '説明', 'body' => '本文'];

        $requests = [
            ['patch', route('admin.parts.update', $part), $fields],
            ['patch', route('admin.chapters.update', $chapter), $fields],
            ['patch', route('admin.sections.update', $section), $fields],
            ['post', route('admin.parts.publish', $part), []],
            ['post', route('admin.chapters.publish', $chapter), []],
            ['post', route('admin.sections.publish', $section), []],
            ['patch', route('admin.certifications.parts.reorder', $cert), ['ids' => [$part->id]]],
            ['patch', route('admin.parts.chapters.reorder', $part), ['ids' => [$chapter->id]]],
            ['patch', route('admin.chapters.sections.reorder', $chapter), ['ids' => [$section->id]]],
            ['delete', route('admin.sections.destroy', $section), []],
            ['delete', route('admin.chapters.destroy', $chapter), []],
            ['delete', route('admin.parts.destroy', $part), []],
        ];

        foreach ($requests as [$method, $url, $data]) {
            $this->actingAs($other)->{$method}($url, $data)->assertForbidden();
        }

        foreach ([$part, $chapter, $section] as $model) {
            $fresh = $model->fresh();
            $this->assertNotNull($fresh);
            $this->assertSame($model->title, $fresh->title);
            $this->assertSame('draft', $fresh->status->value);
        }
    }
}
