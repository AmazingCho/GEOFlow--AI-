<?php

namespace Tests\Feature;

use App\Jobs\ProcessGeoFlowTaskJob;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Task;
use App\Models\TaskRun;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\GeoFlow\TaskMonitoringQueryService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class JobQueueErrorSanitizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_enqueue_sanitizes_payload_before_task_run_is_persisted(): void
    {
        Queue::fake();
        $task = Task::query()->create(['name' => 'Enqueue sanitizer task']);

        $runId = app(JobQueueService::class)->enqueueTaskJob((int) $task->id, 'generate_article', [
            'safe_mode' => 'standard',
            'rawEvidence' => 'CANARY-QUEUED-EVIDENCE',
            'apiKey' => 'CANARY-QUEUED-KEY',
            'error' => ['message' => 'CANARY-QUEUED-ERROR'],
        ]);

        $meta = TaskRun::query()->findOrFail((int) $runId)->meta;
        $this->assertSame('standard', data_get($meta, 'payload.safe_mode'));
        $this->assertStringNotContainsString('CANARY-', json_encode($meta, JSON_THROW_ON_ERROR));
    }

    public function test_enqueue_sanitizes_job_type_and_preserves_documented_safe_payload_fields(): void
    {
        Queue::fake();
        $task = Task::query()->create(['name' => 'Enqueue contract task']);

        $runId = app(JobQueueService::class)->enqueueTaskJob(
            (int) $task->id,
            'CANARY raw evidence Authorization Bearer sk-SECRET',
            [
                'source' => 'api_manual_start',
                'safe_mode' => 'standard',
                'trigger' => 'manual',
                'request_id' => 'request_123',
                'client_reference' => 'client_456',
            ]
        );

        $meta = TaskRun::query()->findOrFail((int) $runId)->meta;
        $encoded = json_encode($meta, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('CANARY', $encoded);
        $this->assertStringNotContainsString('SECRET', $encoded);
        $this->assertSame('generate_article', data_get($meta, 'job_type'));
        $this->assertSame('manual', data_get($meta, 'payload.trigger'));
        $this->assertSame('request_123', data_get($meta, 'payload.request_id'));
        $this->assertSame('client_456', data_get($meta, 'payload.client_reference'));
    }

    public function test_failed_job_persists_only_a_safe_error_fingerprint(): void
    {
        $task = Task::query()->create(['name' => 'Failure sanitizer task']);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'running',
            'meta' => [
                'attempt_count' => 0,
                'max_attempts' => 1,
                'notes' => 'CANARY-HISTORICAL-META',
            ],
        ]);
        $privateMessage = 'SQL failed for CANARY-PRIVATE-EVIDENCE with api_key=SUPER-SECRET';

        app(JobQueueService::class)->failJob($run->id, $task->id, $privateMessage, 15);

        $run->refresh();
        $task->refresh();
        $encoded = json_encode($run->meta, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('CANARY-', (string) $run->error_message);
        $this->assertStringNotContainsString('SUPER-SECRET', (string) $run->error_message);
        $this->assertStringNotContainsString('CANARY-', $encoded);
        $this->assertStringNotContainsString('SUPER-SECRET', (string) $task->last_error_message);
        $this->assertStringContainsString(substr(hash('sha256', $privateMessage), 0, 12), (string) $run->error_message);
    }

    public function test_claiming_a_legacy_run_drops_unknown_meta_before_persisting_worker_state(): void
    {
        $task = Task::query()->create([
            'name' => 'Legacy claim sanitizer task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'pending',
            'started_at' => now()->subMinute(),
            'meta' => [
                'job_type' => 'generate_article',
                'attempt_count' => 0,
                'max_attempts' => 1,
                'available_at' => now()->subMinute()->toDateTimeString(),
                'notes' => 'CANARY-LEGACY-CLAIM-META',
            ],
        ]);

        $claimed = app(JobQueueService::class)->claimPendingJobById((int) $run->id, 'worker-safe-1');

        $this->assertNotNull($claimed);
        $this->assertStringNotContainsString(
            'CANARY-',
            json_encode($run->fresh()->meta, JSON_THROW_ON_ERROR)
        );
        $this->assertSame('worker-safe-1', data_get($run->fresh()->meta, 'worker_id'));
        $this->assertStringNotContainsString(
            'CANARY-',
            json_encode($claimed, JSON_THROW_ON_ERROR)
        );
    }

    public function test_claiming_a_legacy_run_with_an_invalid_available_at_does_not_wedge_the_task(): void
    {
        $task = Task::query()->create([
            'name' => 'Invalid available at task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'pending',
            'started_at' => now()->subMinute(),
            'meta' => [
                'job_type' => 'generate_article',
                'attempt_count' => 0,
                'max_attempts' => 1,
                'available_at' => 'definitely-not-a-date',
            ],
        ]);

        $claimed = app(JobQueueService::class)->claimPendingJobById((int) $run->id, 'worker-safe-2');

        $this->assertNotNull($claimed);
        $this->assertSame('running', $run->fresh()->status);
    }

    public function test_claiming_preserves_a_future_legacy_iso_available_at(): void
    {
        Queue::fake();
        $task = Task::query()->create([
            'name' => 'Future legacy schedule task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $future = now()->addHour();
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'pending',
            'started_at' => now(),
            'meta' => [
                'job_type' => 'generate_article',
                'available_at' => $future->toIso8601String(),
            ],
        ]);

        $claimed = app(JobQueueService::class)->claimPendingJobById((int) $run->id, 'worker-safe-future');

        $this->assertNull($claimed);
        $this->assertSame('pending', $run->fresh()->status);
        $this->assertTrue(now()->lt((string) data_get($run->fresh()->meta, 'available_at')));
    }

    public function test_claiming_rejects_an_invalid_worker_id_instead_of_bypassing_meta_validation(): void
    {
        $task = Task::query()->create([
            'name' => 'Worker id sanitizer task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'pending',
            'started_at' => now()->subMinute(),
            'meta' => ['job_type' => 'generate_article'],
        ]);

        $claimed = app(JobQueueService::class)->claimPendingJobById(
            (int) $run->id,
            'CANARY private evidence /tmp/secret'
        );

        $this->assertNull($claimed);
        $this->assertSame('pending', $run->fresh()->status);
        $this->assertArrayNotHasKey('worker_id', $run->fresh()->meta);
    }

    public function test_stale_worker_cannot_complete_after_recovery_rotates_execution_ownership(): void
    {
        Queue::fake();
        $service = app(JobQueueService::class);
        $task = Task::query()->create([
            'name' => 'Ownership fencing task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $dispatchToken = '11111111-1111-4111-8111-111111111111';
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'pending',
            'started_at' => now()->subMinute(),
            'meta' => [
                'job_type' => 'generate_article',
                'dispatch_token' => $dispatchToken,
                'available_at' => now()->subMinute()->toDateTimeString(),
            ],
        ]);

        $oldClaim = $service->claimPendingJobById((int) $run->id, 'worker-old', $dispatchToken);
        $this->assertIsArray($oldClaim);
        $oldClaimToken = (string) data_get($oldClaim, 'claim_token');
        $this->assertNotSame('', $oldClaimToken);
        TaskRun::query()->whereKey($run->id)->update(['started_at' => now()->subHours(2)]);

        $this->assertSame(1, $service->recoverStaleJobs(60));
        $recoveredMeta = $run->fresh()->meta;
        $newDispatchToken = (string) data_get($recoveredMeta, 'dispatch_token');
        $this->assertNotSame($dispatchToken, $newDispatchToken);
        $this->assertArrayNotHasKey('claim_token', $recoveredMeta);
        $this->assertArrayNotHasKey('worker_id', $recoveredMeta);

        $newClaim = $service->claimPendingJobById((int) $run->id, 'worker-new', $newDispatchToken);
        $this->assertIsArray($newClaim);
        $newClaimToken = (string) data_get($newClaim, 'claim_token');

        $this->assertFalse($service->completeJob(
            (int) $run->id,
            (int) $task->id,
            null,
            20,
            ['action' => 'generate_draft'],
            $oldClaimToken
        ));
        $this->assertSame('running', $run->fresh()->status);
        $this->assertTrue($service->completeJob(
            (int) $run->id,
            (int) $task->id,
            null,
            20,
            ['action' => 'generate_draft'],
            $newClaimToken
        ));
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_future_delivery_waits_for_due_recovery_without_recursive_redispatch(): void
    {
        Queue::fake();
        $service = app(JobQueueService::class);
        $task = Task::query()->create([
            'name' => 'Future replacement task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $dispatchToken = '22222222-2222-4222-8222-222222222222';
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'pending',
            'started_at' => now(),
            'meta' => [
                'job_type' => 'generate_article',
                'dispatch_token' => $dispatchToken,
                'available_at' => now()->addHour()->toIso8601String(),
            ],
        ]);

        $claimed = $service->claimPendingJobById((int) $run->id, 'worker-early', $dispatchToken);

        $this->assertNull($claimed);
        $replacementToken = (string) data_get($run->fresh()->meta, 'dispatch_token');
        $this->assertNotSame($dispatchToken, $replacementToken);
        $this->assertSame('awaiting', data_get($run->fresh()->meta, 'dispatch_state'));
        Queue::assertNothingPushed();

        $meta = $run->fresh()->meta;
        $meta['available_at'] = now()->subMinute()->toDateTimeString();
        TaskRun::query()->whereKey($run->id)->update([
            'created_at' => now()->subHours(2),
            'meta' => $meta,
        ]);

        $this->assertSame(1, $service->recoverStaleJobs(60));
        Queue::assertPushed(ProcessGeoFlowTaskJob::class, fn (ProcessGeoFlowTaskJob $job): bool => $job->taskRunId === (int) $run->id);
    }

    public function test_failed_callback_compensates_a_dispatched_job_that_never_claimed(): void
    {
        Queue::fake();
        $task = Task::query()->create([
            'name' => 'Pre-claim failure task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $dispatchToken = '66666666-6666-4666-8666-666666666666';
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'pending',
            'meta' => [
                'attempt_count' => 0,
                'max_attempts' => 1,
                'dispatch_token' => $dispatchToken,
                'dispatch_state' => 'dispatched',
                'available_at' => now()->subMinute()->toDateTimeString(),
            ],
        ]);

        (new ProcessGeoFlowTaskJob((int) $run->id, $dispatchToken))
            ->failed(new RuntimeException('worker boot failed'));

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame(1, data_get($run->meta, 'attempt_count'));
        $this->assertSame('failed', data_get($run->meta, 'dispatch_state'));
    }

    public function test_failed_callback_without_an_exception_still_releases_the_job_state(): void
    {
        Queue::fake();
        $task = Task::query()->create([
            'name' => 'Null callback failure task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $dispatchToken = '67676767-6767-4767-8767-676767676767';
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'pending',
            'meta' => [
                'attempt_count' => 0,
                'max_attempts' => 1,
                'dispatch_token' => $dispatchToken,
                'dispatch_state' => 'dispatched',
                'available_at' => now()->subMinute()->toDateTimeString(),
            ],
        ]);

        (new ProcessGeoFlowTaskJob((int) $run->id, $dispatchToken))->failed();

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame(1, data_get($run->meta, 'attempt_count'));
        $this->assertSame('failed', data_get($run->meta, 'dispatch_state'));
        $this->assertMatchesRegularExpression('/错误指纹：[a-f0-9]{12}/', (string) $run->error_message);
    }

    public function test_duplicate_delivery_failure_cannot_take_over_another_workers_running_claim(): void
    {
        Queue::fake();
        $service = app(JobQueueService::class);
        $task = Task::query()->create([
            'name' => 'Duplicate delivery ownership task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $dispatchToken = '12121212-1212-4212-8212-121212121212';
        $ownerToken = '13131313-1313-4313-8313-131313131313';
        $duplicateToken = '14141414-1414-4414-8414-141414141414';
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'pending',
            'started_at' => now()->subMinute(),
            'meta' => [
                'attempt_count' => 0,
                'max_attempts' => 2,
                'dispatch_token' => $dispatchToken,
                'dispatch_state' => 'dispatched',
                'available_at' => now()->subMinute()->toDateTimeString(),
            ],
        ]);

        $claimed = $service->claimPendingJobById(
            (int) $run->id,
            'worker-owner',
            $dispatchToken,
            $ownerToken
        );
        $this->assertIsArray($claimed);
        $this->assertSame($ownerToken, data_get($claimed, 'claim_token'));

        (new ProcessGeoFlowTaskJob((int) $run->id, $dispatchToken, $duplicateToken))
            ->failed(new RuntimeException('duplicate delivery failed'));

        $run->refresh();
        $this->assertSame('running', $run->status);
        $this->assertSame(0, data_get($run->meta, 'attempt_count'));
        $this->assertSame($ownerToken, data_get($run->meta, 'claim_token'));
        $this->assertTrue($service->completeJob(
            (int) $run->id,
            (int) $task->id,
            null,
            10,
            [],
            $ownerToken
        ));
    }

    public function test_redelivered_same_message_uses_a_distinct_attempt_claim_token(): void
    {
        Queue::fake();
        $service = app(JobQueueService::class);
        $task = Task::query()->create([
            'name' => 'Redelivered message ownership task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $dispatchToken = '17171717-1717-4717-8717-171717171717';
        $messageToken = '18181818-1818-4818-8818-181818181818';
        $firstDeliveryClaim = $service->claimTokenForDelivery($messageToken, 1);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'pending',
            'started_at' => now()->subMinute(),
            'meta' => [
                'attempt_count' => 0,
                'max_attempts' => 2,
                'dispatch_token' => $dispatchToken,
                'dispatch_state' => 'dispatched',
                'available_at' => now()->subMinute()->toDateTimeString(),
            ],
        ]);

        $claimed = $service->claimPendingJobById(
            (int) $run->id,
            'worker-first-delivery',
            $dispatchToken,
            $firstDeliveryClaim
        );
        $this->assertIsArray($claimed);

        $redelivered = new ProcessGeoFlowTaskJob((int) $run->id, $dispatchToken, $messageToken);
        $fakeQueueJob = new FakeJob;
        $fakeQueueJob->attempts = 2;
        $redelivered->setJob($fakeQueueJob);
        $redelivered->failed(new RuntimeException('same serialized message redelivered'));

        $run->refresh();
        $this->assertSame('running', $run->status);
        $this->assertSame(0, data_get($run->meta, 'attempt_count'));
        $this->assertSame($firstDeliveryClaim, data_get($run->meta, 'claim_token'));
        $this->assertTrue($service->completeJob(
            (int) $run->id,
            (int) $task->id,
            null,
            10,
            [],
            $firstDeliveryClaim
        ));
    }

    public function test_recovery_handles_stale_pending_dispatched_runs_and_consumes_an_attempt(): void
    {
        Queue::fake();
        $task = Task::query()->create([
            'name' => 'Lost carrier recovery task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'pending',
            'started_at' => now()->subHours(2),
            'meta' => [
                'attempt_count' => 0,
                'max_attempts' => 2,
                'dispatch_token' => '77777777-7777-4777-8777-777777777777',
                'dispatch_state' => 'dispatched',
                'dispatched_at' => now()->subHours(2)->toDateTimeString(),
                'available_at' => now()->subHours(2)->toDateTimeString(),
            ],
        ]);

        $this->assertSame(1, app(JobQueueService::class)->recoverStaleJobs(60));

        $run->refresh();
        $this->assertSame('pending', $run->status);
        $this->assertSame(1, data_get($run->meta, 'attempt_count'));
        $this->assertSame('dispatched', data_get($run->meta, 'dispatch_state'));
        Queue::assertPushed(ProcessGeoFlowTaskJob::class, fn (ProcessGeoFlowTaskJob $job): bool => $job->taskRunId === (int) $run->id);
    }

    public function test_future_dispatched_run_is_not_stale_until_timeout_after_available_at(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-21 12:00:00'));

        try {
            $task = Task::query()->create([
                'name' => 'Future dispatched timeout task',
                'status' => 'active',
                'schedule_enabled' => 1,
            ]);
            $availableAt = now()->copy();
            $run = TaskRun::query()->create([
                'task_id' => $task->id,
                'status' => 'pending',
                'started_at' => now()->subHours(2),
                'meta' => [
                    'attempt_count' => 0,
                    'max_attempts' => 2,
                    'dispatch_token' => '15151515-1515-4515-8515-151515151515',
                    'dispatch_state' => 'dispatched',
                    'dispatched_at' => now()->subHours(2)->toDateTimeString(),
                    'available_at' => $availableAt->toDateTimeString(),
                ],
            ]);
            TaskRun::query()->whereKey($run->id)->update(['created_at' => now()->subHours(2)]);

            $this->assertSame(0, app(JobQueueService::class)->recoverStaleJobs(60));
            $this->assertSame('pending', $run->fresh()->status);
            $this->assertSame(0, data_get($run->fresh()->meta, 'attempt_count'));
            Queue::assertNothingPushed();

            Carbon::setTestNow($availableAt->copy()->addSeconds(60));
            $this->assertSame(0, app(JobQueueService::class)->recoverStaleJobs(60));
            $this->assertSame(0, data_get($run->fresh()->meta, 'attempt_count'));
            Queue::assertNothingPushed();

            Carbon::setTestNow($availableAt->copy()->addSeconds(61));
            $this->assertSame(1, app(JobQueueService::class)->recoverStaleJobs(60));
            $this->assertSame(1, data_get($run->fresh()->meta, 'attempt_count'));
            Queue::assertPushed(ProcessGeoFlowTaskJob::class);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_stale_recovery_obeys_the_attempt_limit(): void
    {
        Queue::fake();
        $task = Task::query()->create([
            'name' => 'Bounded stale recovery task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'running',
            'started_at' => now()->subHours(2),
            'meta' => [
                'attempt_count' => 0,
                'max_attempts' => 1,
                'dispatch_token' => '88888888-8888-4888-8888-888888888888',
                'claim_token' => '88888888-8888-4888-8888-888888888888',
                'dispatch_state' => 'claimed',
            ],
        ]);

        $this->assertSame(0, app(JobQueueService::class)->recoverStaleJobs(60));

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame(1, data_get($run->meta, 'attempt_count'));
        $this->assertSame('failed', data_get($run->meta, 'dispatch_state'));
        Queue::assertNothingPushed();
    }

    public function test_legacy_dispatch_without_token_uses_per_delivery_execution_ownership(): void
    {
        Queue::fake();
        $task = Task::query()->create([
            'name' => 'Legacy failed callback task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'pending',
            'meta' => [
                'attempt_count' => 0,
                'max_attempts' => 1,
                'available_at' => now()->subMinute()->toDateTimeString(),
            ],
        ]);

        $executionToken = '16161616-1616-4616-8616-161616161616';
        $service = app(JobQueueService::class);
        $claimToken = $service->claimTokenForDelivery($executionToken, 1);
        $claimed = $service->claimPendingJobById(
            (int) $run->id,
            'legacy-worker',
            null,
            $claimToken
        );
        $this->assertIsArray($claimed);
        $this->assertSame($claimToken, data_get($run->fresh()->meta, 'claim_token'));
        $this->assertArrayNotHasKey('legacy_claim', $run->fresh()->meta);

        (new ProcessGeoFlowTaskJob((int) $run->id, null, $executionToken))
            ->failed(new RuntimeException('legacy worker stopped'));

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame(1, data_get($run->meta, 'attempt_count'));
    }

    public function test_stale_run_with_atomically_linked_article_is_completed_without_reexecution(): void
    {
        Queue::fake();
        $service = app(JobQueueService::class);
        $task = Task::query()->create([
            'name' => 'Committed article recovery task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $token = '99999999-9999-4999-8999-999999999999';
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'running',
            'started_at' => now()->subHours(2),
            'meta' => [
                'attempt_count' => 0,
                'max_attempts' => 3,
                'dispatch_token' => $token,
                'claim_token' => $token,
                'dispatch_state' => 'claimed',
            ],
        ]);
        $author = Author::query()->create(['name' => 'Recovery Author']);
        $category = Category::query()->create(['name' => 'Recovery', 'slug' => 'recovery']);
        $article = Article::query()->create([
            'title' => 'Recovered committed draft',
            'slug' => 'recovered-committed-draft',
            'content' => 'Committed content.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        $this->assertTrue($service->associateArticleWithExecution(
            (int) $run->id,
            (int) $task->id,
            (int) $article->id,
            $token
        ));
        $this->assertSame(0, $service->recoverStaleJobs(60));

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame($article->id, $run->article_id);
        $this->assertSame('completed', data_get($run->meta, 'dispatch_state'));
        Queue::assertNothingPushed();
    }

    public function test_failure_after_article_link_finalizes_the_committed_run_instead_of_retrying(): void
    {
        Queue::fake();
        [$task, $run, $article, $token] = $this->committedRunFixture('Post-commit failure');
        $service = app(JobQueueService::class);

        $this->assertTrue($service->associateArticleWithExecution(
            (int) $run->id,
            (int) $task->id,
            (int) $article->id,
            $token
        ));
        $this->assertTrue($service->failJob(
            (int) $run->id,
            (int) $task->id,
            'exception after business commit',
            25,
            60,
            $token
        ));

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame('completed', data_get($run->meta, 'dispatch_state'));
        $this->assertSame(0, data_get($run->meta, 'attempt_count'));
        Queue::assertNothingPushed();
    }

    public function test_article_link_rolls_back_with_the_surrounding_business_transaction(): void
    {
        [$task, $run, $article, $token] = $this->committedRunFixture('Rollback boundary');
        $article->forceDelete();

        try {
            DB::transaction(function () use ($task, $run, $token): void {
                $article = Article::query()->create([
                    'title' => 'Transactional draft',
                    'slug' => 'transactional-draft',
                    'content' => 'Transactional content.',
                    'category_id' => Category::query()->value('id'),
                    'author_id' => Author::query()->value('id'),
                    'task_id' => $task->id,
                    'status' => 'draft',
                    'review_status' => 'pending',
                ]);
                $this->assertTrue(app(JobQueueService::class)->associateArticleWithExecution(
                    (int) $run->id,
                    (int) $task->id,
                    (int) $article->id,
                    $token
                ));

                throw new RuntimeException('force outer rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('force outer rollback', $exception->getMessage());
        }

        $this->assertNull($run->fresh()->article_id);
        $this->assertFalse(Article::query()->where('slug', 'transactional-draft')->exists());
    }

    public function test_article_association_rejects_an_article_owned_by_another_task(): void
    {
        [$task, $run, $article, $token] = $this->committedRunFixture('Association scope');
        $otherTask = Task::query()->create(['name' => 'Other task']);
        $article->forceFill(['task_id' => $otherTask->id])->save();

        $this->assertFalse(app(JobQueueService::class)->associateArticleWithExecution(
            (int) $run->id,
            (int) $task->id,
            (int) $article->id,
            $token
        ));
        $this->assertNull($run->fresh()->article_id);
    }

    public function test_completion_preserves_and_cannot_replace_the_atomically_linked_article(): void
    {
        [$task, $run, $article, $token] = $this->committedRunFixture('Completion link');
        $service = app(JobQueueService::class);
        $this->assertTrue($service->associateArticleWithExecution($run->id, $task->id, $article->id, $token));

        $this->assertTrue($service->completeJob($run->id, $task->id, null, 10, [], $token));
        $this->assertSame($article->id, $run->fresh()->article_id);

        [$secondTask, $secondRun, $secondArticle, $secondToken] = $this->committedRunFixture('Completion mismatch');
        $otherArticle = $secondArticle->replicate(['slug']);
        $otherArticle->slug = 'completion-mismatch-other';
        $otherArticle->save();
        $this->assertTrue($service->associateArticleWithExecution(
            $secondRun->id,
            $secondTask->id,
            $secondArticle->id,
            $secondToken
        ));

        $this->assertFalse($service->completeJob(
            $secondRun->id,
            $secondTask->id,
            $otherArticle->id,
            10,
            [],
            $secondToken
        ));
        $this->assertSame($secondArticle->id, $secondRun->fresh()->article_id);
        $this->assertSame('running', $secondRun->fresh()->status);
    }

    public function test_legacy_running_run_without_started_at_uses_created_at_for_stale_recovery(): void
    {
        Queue::fake();
        $task = Task::query()->create([
            'name' => 'Legacy null start task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'running',
            'started_at' => null,
            'meta' => ['attempt_count' => 0, 'max_attempts' => 1],
        ]);
        TaskRun::query()->whereKey($run->id)->update(['created_at' => now()->subHours(2)]);

        $this->assertSame(0, app(JobQueueService::class)->recoverStaleJobs(60));
        $this->assertSame('failed', $run->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_recovery_dispatch_failure_is_not_counted_as_recovered(): void
    {
        $this->mock(Dispatcher::class)
            ->shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('CANARY recovery dispatch failed'));
        $task = Task::query()->create([
            'name' => 'Recovery dispatch failure task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'pending',
            'started_at' => now()->subMinute(),
            'meta' => [
                'attempt_count' => 0,
                'max_attempts' => 3,
                'dispatch_state' => 'awaiting',
                'available_at' => now()->subMinute()->toDateTimeString(),
            ],
        ]);

        $this->assertSame(0, app(JobQueueService::class)->recoverStaleJobs(60));
        $this->assertSame('failed', $run->fresh()->status);
    }

    public function test_dispatch_failure_never_leaves_a_pending_run_without_a_queue_job(): void
    {
        $this->mock(Dispatcher::class)
            ->shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('CANARY Redis dispatch failure'));
        $task = Task::query()->create([
            'name' => 'Dispatch compensation task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);

        try {
            app(JobQueueService::class)->enqueueTaskJob((int) $task->id);
            $this->fail('Dispatch failure must be surfaced to the caller.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('CANARY', $exception->getMessage());
        }

        $run = TaskRun::query()->where('task_id', $task->id)->latest('id')->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertFalse(TaskRun::query()->whereKey($run->id)->whereIn('status', ['pending', 'running'])->exists());
    }

    public function test_retry_dispatch_failure_is_compensated_instead_of_stranding_pending_run(): void
    {
        $this->mock(Dispatcher::class)
            ->shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('CANARY Redis retry failure'));
        $task = Task::query()->create(['name' => 'Retry dispatch compensation task']);
        $claimToken = '44444444-4444-4444-8444-444444444444';
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'running',
            'meta' => [
                'job_type' => 'generate_article',
                'attempt_count' => 0,
                'max_attempts' => 3,
                'dispatch_token' => $claimToken,
                'claim_token' => $claimToken,
            ],
        ]);

        try {
            app(JobQueueService::class)->failJob(
                (int) $run->id,
                (int) $task->id,
                'provider failed',
                15,
                60,
                $claimToken
            );
            $this->fail('Retry dispatch failure must be surfaced to the worker.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('CANARY', $exception->getMessage());
        }

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertFalse(TaskRun::query()->whereKey($run->id)->whereIn('status', ['pending', 'running'])->exists());
    }

    public function test_recovery_leaves_future_awaiting_run_alone_then_dispatches_it_when_due(): void
    {
        Queue::fake();
        $task = Task::query()->create([
            'name' => 'Orphan outbox recovery task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $future = now()->addHour()->startOfSecond();
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'pending',
            'created_at' => now()->subHours(2),
            'started_at' => now()->subHours(2),
            'meta' => [
                'job_type' => 'generate_article',
                'dispatch_token' => '55555555-5555-4555-8555-555555555555',
                'dispatch_state' => 'awaiting',
                'available_at' => $future->toIso8601String(),
            ],
        ]);
        TaskRun::query()->whereKey($run->id)->update(['created_at' => now()->subHours(2)]);

        $this->assertSame(0, app(JobQueueService::class)->recoverStaleJobs(60));

        $meta = $run->fresh()->meta;
        $this->assertSame('awaiting', data_get($meta, 'dispatch_state'));
        $this->assertSame($future->timestamp, Carbon::parse((string) data_get($meta, 'available_at'))->timestamp);
        Queue::assertNothingPushed();

        $meta['available_at'] = now()->subMinute()->toDateTimeString();
        TaskRun::query()->whereKey($run->id)->update(['meta' => $meta]);
        $this->assertSame(1, app(JobQueueService::class)->recoverStaleJobs(60));

        $meta = $run->fresh()->meta;
        $this->assertSame('dispatched', data_get($meta, 'dispatch_state'));
        Queue::assertPushed(ProcessGeoFlowTaskJob::class, fn (ProcessGeoFlowTaskJob $job): bool => $job->taskRunId === (int) $run->id
                && $job->dispatchToken === data_get($run->fresh()->meta, 'dispatch_token')
        );
    }

    public function test_successful_completion_preserves_queue_audit_metadata(): void
    {
        $service = app(JobQueueService::class);
        $task = Task::query()->create(['name' => 'Completion metadata task']);
        $claimToken = '33333333-3333-4333-8333-333333333333';
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'running',
            'meta' => [
                'job_type' => 'generate_article',
                'payload' => ['source' => 'api_manual_start'],
                'attempt_count' => 1,
                'max_attempts' => 3,
                'available_at' => now()->subMinute()->toDateTimeString(),
                'worker_id' => 'worker-audit',
                'dispatch_token' => $claimToken,
                'claim_token' => $claimToken,
            ],
        ]);

        $this->assertTrue($service->completeJob(
            (int) $run->id,
            (int) $task->id,
            null,
            20,
            ['action' => 'generate_draft'],
            $claimToken
        ));

        $meta = $run->fresh()->meta;
        $this->assertSame('generate_article', data_get($meta, 'job_type'));
        $this->assertSame('api_manual_start', data_get($meta, 'payload.source'));
        $this->assertSame(1, data_get($meta, 'attempt_count'));
        $this->assertSame(3, data_get($meta, 'max_attempts'));
        $this->assertSame('worker-audit', data_get($meta, 'worker_id'));
        $this->assertSame($claimToken, data_get($meta, 'claim_token'));
        $this->assertSame('generate_draft', data_get($meta, 'action'));
    }

    public function test_terminal_transition_locks_task_before_task_run(): void
    {
        $service = app(JobQueueService::class);
        $task = Task::query()->create(['name' => 'Lock order task']);
        $claimToken = '19191919-1919-4919-8919-191919191919';
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'running',
            'meta' => [
                'attempt_count' => 0,
                'max_attempts' => 1,
                'claim_token' => $claimToken,
                'dispatch_state' => 'claimed',
            ],
        ]);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower((string) $query->sql);
        });

        $this->assertTrue($service->completeJob(
            (int) $run->id,
            (int) $task->id,
            null,
            10,
            [],
            $claimToken
        ));

        $taskLockIndex = collect($queries)->search(
            static fn (string $sql): bool => str_contains($sql, 'from "tasks"')
        );
        $runLockIndex = collect($queries)->search(
            static fn (string $sql): bool => str_contains($sql, 'from "task_runs"')
        );
        $this->assertIsInt($taskLockIndex, 'The transition must explicitly lock the task row.');
        $this->assertIsInt($runLockIndex, 'The transition must explicitly lock the task run row.');
        $this->assertLessThan($runLockIndex, $taskLockIndex);
    }

    public function test_cancellation_and_recovery_transitions_sanitize_legacy_meta(): void
    {
        Queue::fake();
        $pausedTask = Task::query()->create([
            'name' => 'Paused cancellation task',
            'status' => 'paused',
            'schedule_enabled' => 0,
        ]);
        $pendingRun = TaskRun::query()->create([
            'task_id' => $pausedTask->id,
            'status' => 'pending',
            'started_at' => now()->subMinute(),
            'meta' => ['job_type' => 'generate_article', 'raw_evidence' => 'CANARY-INACTIVE-CLAIM'],
        ]);

        $this->assertNull(app(JobQueueService::class)->claimPendingJobById((int) $pendingRun->id, 'worker-safe'));
        $this->assertStringNotContainsString('CANARY-', json_encode($pendingRun->fresh()->meta, JSON_THROW_ON_ERROR));
        $this->assertSame('cancelled', data_get($pendingRun->fresh()->meta, 'dispatch_state'));

        $staleRun = TaskRun::query()->create([
            'task_id' => $pausedTask->id,
            'status' => 'running',
            'started_at' => now()->subHours(2),
            'meta' => ['job_type' => 'generate_article', 'raw_evidence' => 'CANARY-STALE-RECOVERY'],
        ]);
        app(JobQueueService::class)->recoverStaleJobs(60);
        $this->assertStringNotContainsString('CANARY-', json_encode($staleRun->fresh()->meta, JSON_THROW_ON_ERROR));

        $activeTask = Task::query()->create([
            'name' => 'Bulk pause sanitizer task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $bulkRun = TaskRun::query()->create([
            'task_id' => $activeTask->id,
            'status' => 'pending',
            'started_at' => now()->subMinute(),
            'meta' => ['job_type' => 'generate_article', 'raw_evidence' => 'CANARY-BULK-PAUSE'],
        ]);
        app(TaskLifecycleService::class)->stopTask((int) $activeTask->id);
        $this->assertStringNotContainsString('CANARY-', json_encode($bulkRun->fresh()->meta, JSON_THROW_ON_ERROR));
        $this->assertSame('cancelled', data_get($bulkRun->fresh()->meta, 'dispatch_state'));
    }

    public function test_complete_job_sanitizes_nonstandard_generation_trace_at_the_write_sink(): void
    {
        $task = Task::query()->create(['name' => 'Completion sanitizer task']);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'running',
            'meta' => [],
        ]);

        app(JobQueueService::class)->completeJob($run->id, $task->id, null, 20, [
            'generation_trace' => [
                'knowledge' => [
                    'chunks' => [[
                        'knowledge_base_id' => 3,
                        'chunk_index' => 1,
                        'chunkPreview' => 'CANARY-CAMEL-PREVIEW',
                        'score_components' => ['case_title' => 'CANARY-NESTED-CASE'],
                    ]],
                ],
                'evidencePackage' => [['text' => 'CANARY-NONSTANDARD-EVIDENCE']],
                'claimLedger' => [['body' => 'CANARY-TOP-LEVEL-CLAIM']],
                'claim_provenance' => [
                    'coverage_status' => 'complete',
                    'claim_ledger' => [[
                        'paragraph_sha256' => str_repeat('c', 64),
                        'evidence_refs' => ['KB:3:CHUNK:1:0123456789abcdef'],
                        'paragraph' => 'CANARY-CLAIM-PARAGRAPH',
                    ]],
                ],
            ],
        ]);

        $meta = $run->fresh()->meta;
        $encoded = json_encode($meta, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('CANARY-', $encoded);
        $this->assertSame([[
            'paragraph_sha256' => str_repeat('c', 64),
            'evidence_refs' => ['KB:3:CHUNK:1:0123456789abcdef'],
        ]], data_get($meta, 'generation_trace.claim_provenance.claim_ledger'));
    }

    public function test_complete_job_drops_non_finite_nested_scores_without_corrupting_meta(): void
    {
        $task = Task::query()->create(['name' => 'Non finite score task']);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'running',
            'meta' => [],
        ]);

        app(JobQueueService::class)->completeJob($run->id, $task->id, null, 20, [
            'generation_trace' => [
                'knowledge' => [
                    'chunks' => [[
                        'knowledge_base_id' => 3,
                        'chunk_index' => 1,
                        'score_components' => ['vector' => '1e309', 'metadata' => 0.5],
                    ]],
                ],
            ],
        ]);

        $meta = $run->fresh()->meta;
        $this->assertIsArray($meta);
        $this->assertArrayNotHasKey('vector', data_get($meta, 'generation_trace.knowledge.chunks.0.score_components'));
        $this->assertSame(0.5, data_get($meta, 'generation_trace.knowledge.chunks.0.score_components.metadata'));
    }

    public function test_cancel_and_monitoring_paths_redact_historical_errors(): void
    {
        $task = Task::query()->create([
            'name' => 'Historical error sanitizer task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'last_error_message' => 'CANARY-HISTORICAL-TASK-ERROR api_key=SECRET',
        ]);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'running',
            'error_message' => 'CANARY-HISTORICAL-RUN-ERROR api_key=SECRET',
            'meta' => [
                'attempt_count' => 0,
                'max_attempts' => 1,
                'raw_evidence' => 'CANARY-HISTORICAL-CANCEL-META',
            ],
        ]);

        $snapshot = app(TaskMonitoringQueryService::class)->buildTaskSnapshot();
        $this->assertStringNotContainsString('CANARY-', json_encode($snapshot, JSON_THROW_ON_ERROR));

        app(JobQueueService::class)->cancelJob(
            (int) $run->id,
            (int) $task->id,
            'CANARY-CANCEL-REASON api_key=SECRET'
        );

        $persisted = json_encode([
            'run_error' => $run->fresh()->error_message,
            'run_meta' => $run->fresh()->meta,
            'task_error' => $task->fresh()->last_error_message,
        ], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('CANARY-', $persisted);
    }

    /** @return array{Task,TaskRun,Article,string} */
    private function committedRunFixture(string $suffix): array
    {
        $task = Task::query()->create([
            'name' => $suffix.' task',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $token = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'running',
            'started_at' => now(),
            'meta' => [
                'attempt_count' => 0,
                'max_attempts' => 3,
                'dispatch_token' => $token,
                'claim_token' => $token,
                'dispatch_state' => 'claimed',
            ],
        ]);
        $author = Author::query()->create(['name' => $suffix.' Author']);
        $category = Category::query()->create([
            'name' => $suffix.' Category',
            'slug' => str($suffix)->slug()->append('-category')->toString(),
        ]);
        $article = Article::query()->create([
            'title' => $suffix.' draft',
            'slug' => str($suffix)->slug()->append('-draft')->toString(),
            'content' => 'Committed content.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        return [$task, $run, $article, $token];
    }
}
