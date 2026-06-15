<?php

namespace App\Services\GeoFlow;

use App\Models\Admin;
use App\Models\CrmCustomer;
use App\Models\CrmFollowUp;
use App\Models\CrmInquiry;
use App\Models\CrmOpportunity;
use App\Models\CrmTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CrmActivityService
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'followup_type' => ['nullable', 'string', 'max:80'],
            'content' => ['required', 'string', 'max:10000'],
            'owner' => ['nullable', 'string', 'max:120'],
            'create_task' => ['nullable', 'boolean'],
            'task_title' => ['nullable', 'required_if:create_task,1', 'string', 'max:240'],
            'task_due_at' => ['nullable', 'date'],
            'task_priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{activity:CrmFollowUp,task:?CrmTask}
     */
    public function record(
        CrmCustomer $customer,
        ?CrmInquiry $inquiry,
        ?CrmOpportunity $opportunity,
        array $payload,
        ?Admin $admin = null,
    ): array {
        $this->assertChain($customer, $inquiry, $opportunity);

        return DB::transaction(function () use ($customer, $inquiry, $opportunity, $payload, $admin): array {
            $task = null;
            if ((bool) ($payload['create_task'] ?? false)) {
                $task = CrmTask::query()->create([
                    'customer_id' => (int) $customer->id,
                    'inquiry_id' => (int) ($inquiry?->id ?? 0) ?: null,
                    'opportunity_id' => (int) ($opportunity?->id ?? 0) ?: null,
                    'assigned_admin_id' => $admin?->id,
                    'created_by_admin_id' => $admin?->id,
                    'title' => trim((string) $payload['task_title']),
                    'priority' => (string) ($payload['task_priority'] ?? 'normal'),
                    'status' => 'open',
                    'due_at' => $payload['task_due_at'] ?? null,
                ]);
            }

            $activity = CrmFollowUp::query()->create([
                'customer_id' => (int) $customer->id,
                'inquiry_id' => (int) ($inquiry?->id ?? 0) ?: null,
                'opportunity_id' => (int) ($opportunity?->id ?? 0) ?: null,
                'task_id' => $task?->id,
                'followup_type' => trim((string) ($payload['followup_type'] ?? '')),
                'content' => trim((string) $payload['content']),
                'owner' => trim((string) ($payload['owner'] ?? $admin?->display_name ?? $admin?->username ?? '')),
                'status' => 'done',
            ]);

            return ['activity' => $activity, 'task' => $task];
        });
    }

    public function completeTask(CrmTask $task, ?string $resultContent, ?string $followupType, ?Admin $admin = null): ?CrmFollowUp
    {
        return DB::transaction(function () use ($task, $resultContent, $followupType, $admin): ?CrmFollowUp {
            $task->update(['status' => 'done', 'completed_at' => now()]);
            $content = trim((string) $resultContent);
            if ($content === '') {
                return null;
            }

            return CrmFollowUp::query()->create([
                'customer_id' => (int) ($task->customer_id ?? 0) ?: null,
                'inquiry_id' => (int) ($task->inquiry_id ?? 0) ?: null,
                'opportunity_id' => (int) ($task->opportunity_id ?? 0) ?: null,
                'task_id' => (int) $task->id,
                'followup_type' => trim((string) $followupType) ?: '待办结果',
                'content' => $content,
                'owner' => trim((string) ($admin?->display_name ?? $admin?->username ?? '')),
                'status' => 'done',
            ]);
        });
    }

    private function assertChain(CrmCustomer $customer, ?CrmInquiry $inquiry, ?CrmOpportunity $opportunity): void
    {
        if ($inquiry && (int) $inquiry->customer_id !== (int) $customer->id) {
            throw ValidationException::withMessages(['inquiry_id' => '活动询盘不属于当前客户。']);
        }
        if ($opportunity && (int) $opportunity->customer_id !== (int) $customer->id) {
            throw ValidationException::withMessages(['opportunity_id' => '活动商机不属于当前客户。']);
        }
        if ($inquiry && $opportunity && (int) $opportunity->source_inquiry_id !== (int) $inquiry->id) {
            throw ValidationException::withMessages(['opportunity_id' => '活动询盘与商机来源不一致。']);
        }
    }
}
