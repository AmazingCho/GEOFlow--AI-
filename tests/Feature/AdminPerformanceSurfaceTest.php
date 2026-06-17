<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Category;
use App\Models\ImageLibrary;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPerformanceSurfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_create_page_does_not_render_all_material_tags_initially(): void
    {
        $admin = $this->admin();
        Category::query()->create([
            'name' => 'Performance Category',
            'slug' => 'performance-category',
        ]);
        ImageLibrary::query()->create([
            'name' => 'Performance Images',
            'description' => '',
            'image_count' => 0,
            'used_task_count' => 0,
        ]);
        for ($index = 1; $index <= 120; $index++) {
            Tag::query()->create([
                'type' => 'material',
                'group_name' => 'Topic',
                'name' => 'Performance Tag '.$index,
                'slug' => 'performance-tag-'.$index,
                'color' => '',
            ]);
        }

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.tasks.create'));

        $response->assertOk()
            ->assertSee(route('admin.material-tags.search'), false);
        $this->assertLessThan(20, substr_count((string) $response->getContent(), 'data-tag-option'));
        $this->assertStringNotContainsString('Performance Tag 120', (string) $response->getContent());
    }

    public function test_material_tag_remote_search_is_limited_and_reports_has_more(): void
    {
        $admin = $this->admin('performance_tag_search_admin');
        for ($index = 1; $index <= 35; $index++) {
            Tag::query()->create([
                'type' => 'material',
                'group_name' => 'Topic',
                'name' => 'Searchable Performance '.$index,
                'slug' => 'searchable-performance-'.$index,
                'color' => '',
            ]);
        }

        $response = $this->actingAs($admin, 'admin')
            ->getJson(route('admin.material-tags.search', [
                'q' => 'Searchable Performance',
                'limit' => 10,
            ]));

        $response->assertOk()
            ->assertJsonPath('pagination.has_more', true);

        $payload = $response->json();
        $this->assertCount(10, $payload['items'] ?? []);
        $this->assertSame('Topic:Searchable Performance 1', (string) ($payload['items'][0]['label'] ?? ''));
    }

    private function admin(string $username = 'performance_admin'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => 'Performance Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }
}
