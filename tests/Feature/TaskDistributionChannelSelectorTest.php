<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\Task;
use App\Services\GeoFlow\TaskDistributionChannelSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskDistributionChannelSelectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_broadcast_strategy_keeps_all_selected_channels(): void
    {
        [$task, $article, $firstChannel, $secondChannel] = $this->seedTaskWithChannels(TaskDistributionChannelSelector::STRATEGY_BROADCAST);

        $article->load('task.distributionChannels');
        $selected = (new TaskDistributionChannelSelector)->selectChannelsForArticle(
            $article,
            $task->distributionChannels
        );

        $this->assertSame([(int) $firstChannel->id, (int) $secondChannel->id], $selected->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_round_robin_strategy_selects_one_channel_at_a_time(): void
    {
        [$task, $article, $firstChannel, $secondChannel] = $this->seedTaskWithChannels(TaskDistributionChannelSelector::STRATEGY_ROUND_ROBIN);
        $selector = new TaskDistributionChannelSelector;

        $article->load('task.distributionChannels');
        $firstSelection = $selector->selectChannelsForArticle($article, $task->distributionChannels);

        $task->refresh();
        $article->load('task.distributionChannels');
        $secondSelection = $selector->selectChannelsForArticle($article, $task->distributionChannels);

        $this->assertSame([(int) $firstChannel->id], $firstSelection->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame([(int) $secondChannel->id], $secondSelection->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(2, (int) $task->fresh()->distribution_cursor);
    }

    /**
     * @return array{Task, Article, DistributionChannel, DistributionChannel}
     */
    private function seedTaskWithChannels(string $strategy): array
    {
        $task = Task::query()->create([
            'name' => 'Distribution strategy task',
            'status' => 'active',
            'publish_scope' => 'local_and_distribution',
            'distribution_strategy' => $strategy,
            'distribution_cursor' => 0,
        ]);

        $firstChannel = DistributionChannel::query()->create([
            'name' => 'First channel',
            'domain' => 'first.example.com',
            'endpoint_url' => 'https://first.example.com/geoflow-agent/v1',
            'status' => 'active',
        ]);
        $secondChannel = DistributionChannel::query()->create([
            'name' => 'Second channel',
            'domain' => 'second.example.com',
            'endpoint_url' => 'https://second.example.com/geoflow-agent/v1',
            'status' => 'active',
        ]);

        $task->distributionChannels()->sync([
            (int) $firstChannel->id => [
                'sort_order' => 0,
                'trigger' => 'after_local_publish',
                'remote_status' => 'follow_local',
                'failure_policy' => 'ignore_distribution_failure',
                'max_attempts' => 3,
            ],
            (int) $secondChannel->id => [
                'sort_order' => 1,
                'trigger' => 'after_local_publish',
                'remote_status' => 'follow_local',
                'failure_policy' => 'ignore_distribution_failure',
                'max_attempts' => 3,
            ],
        ]);

        $author = Author::query()->create([
            'name' => 'Leo',
            'email' => 'leo@example.com',
        ]);
        $category = Category::query()->create([
            'name' => 'Test',
            'slug' => 'test',
        ]);
        $article = Article::query()->create([
            'title' => 'Test article',
            'slug' => 'test-article-'.strtolower($strategy),
            'content' => 'Article content',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'task_id' => (int) $task->id,
            'status' => 'published',
            'review_status' => 'approved',
        ]);

        return [$task, $article, $firstChannel, $secondChannel];
    }
}
