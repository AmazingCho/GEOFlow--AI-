<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Services\GeoFlow\TagRecommendationService;
use App\Services\GeoFlow\TagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagRecommendationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_recommends_existing_tags_from_material_text(): void
    {
        $manufacturing = app(TagService::class)->firstOrCreateTag('行业', '制造业');
        app(TagService::class)->firstOrCreateTag('行业', '医疗');

        $items = app(TagRecommendationService::class)->recommendForText('制造业智能客服售后案例', [], 5);

        $this->assertSame((int) $manufacturing->id, (int) $items[0]['id']);
        $this->assertSame('行业:制造业', $items[0]['label']);
    }

    public function test_it_excludes_already_selected_tags(): void
    {
        $manufacturing = app(TagService::class)->firstOrCreateTag('行业', '制造业');
        app(TagService::class)->firstOrCreateTag('场景', '售后');

        $items = app(TagRecommendationService::class)->recommendForText('制造业售后资料', [(int) $manufacturing->id], 5);
        $labels = collect($items)->pluck('label')->all();

        $this->assertNotContains('行业:制造业', $labels);
        $this->assertContains('场景:售后', $labels);
    }

    public function test_recommendation_endpoint_returns_json_items(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tag_recommend_admin',
            'password' => 'secret-123',
            'email' => 'tag-recommend@example.com',
            'display_name' => 'Tag Recommend Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        app(TagService::class)->firstOrCreateTag('行业', '制造业');

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.material-tags.recommendations', ['text' => '制造业知识库']))
            ->assertOk()
            ->assertJsonPath('items.0.label', '行业:制造业');
    }
}
