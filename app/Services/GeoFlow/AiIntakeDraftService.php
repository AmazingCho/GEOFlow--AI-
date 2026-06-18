<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\AiIntakeAction;
use App\Models\AiIntakeDraft;
use App\Models\CollectionRecord;
use App\Models\CrmAfterSalesTicket;
use App\Models\CrmContentProposal;
use App\Models\CrmCustomer;
use App\Models\CrmFollowUp;
use App\Models\CrmInquiry;
use App\Models\CrmTask;
use App\Support\AdminActivityLogger;
use Illuminate\Support\Facades\DB;

class AiIntakeDraftService
{
    private const RISK_ORDER = [
        'low' => 1,
        'medium' => 2,
        'high' => 3,
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validatePayload(array $payload): array
    {
        $normalized = $this->normalizeDraftPayload($payload);
        $warnings = $this->governanceWarnings($normalized);
        $actions = $this->applyGovernanceToActions($normalized['actions'], $warnings);

        return [
            'valid' => true,
            'draft' => [
                'source' => $normalized['source'],
                'source_reference' => $normalized['source_reference'],
                'collection_id' => $normalized['collection_id'],
                'raw_input' => $normalized['raw_input'],
                'normalized_summary' => $normalized['normalized_summary'],
                'detected_language' => $normalized['detected_language'],
                'confidence' => $normalized['confidence'],
            ],
            'actions' => $actions,
            'warnings' => $warnings,
            'risk_summary' => $this->riskSummary($actions),
            'action_count' => count($actions),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createDraft(array $payload, int $adminId): AiIntakeDraft
    {
        $validated = $this->validatePayload($payload);
        $draftData = $validated['draft'];
        if (! is_array($draftData)) {
            throw new ApiException('validation_failed', '草稿数据无效', 422);
        }

        $draft = DB::transaction(function () use ($draftData, $validated, $adminId): AiIntakeDraft {
            $draft = AiIntakeDraft::query()->create([
                'source' => (string) $draftData['source'],
                'source_reference' => (string) $draftData['source_reference'],
                'collection_id' => $this->nullableInt($draftData['collection_id'] ?? null),
                'raw_input' => (string) $draftData['raw_input'],
                'normalized_summary' => (string) $draftData['normalized_summary'],
                'status' => AiIntakeDraft::STATUS_NEEDS_REVIEW,
                'confidence' => $draftData['confidence'],
                'detected_language' => (string) $draftData['detected_language'],
                'created_by_admin_id' => $adminId > 0 ? $adminId : null,
                'metadata_json' => [
                    'warnings' => $validated['warnings'],
                    'risk_summary' => $validated['risk_summary'],
                ],
            ]);

            foreach ((array) $validated['actions'] as $action) {
                if (! is_array($action)) {
                    continue;
                }

                AiIntakeAction::query()->create([
                    'draft_id' => (int) $draft->id,
                    'action_type' => (string) $action['action_type'],
                    'target_type' => (string) $action['target_type'],
                    'target_id' => $this->nullableInt($action['target_id'] ?? null),
                    'payload_json' => is_array($action['payload'] ?? null) ? $action['payload'] : [],
                    'relation_json' => is_array($action['relation'] ?? null) ? $action['relation'] : [],
                    'diff_json' => is_array($action['diff'] ?? null) ? $action['diff'] : [],
                    'confidence' => $action['confidence'],
                    'risk_level' => (string) $action['risk_level'],
                    'status' => AiIntakeAction::STATUS_PENDING,
                ]);
            }

            return $draft;
        });

        $this->log($adminId, 'assistant-intake:create', $draft, [
            'action_count' => count((array) $validated['actions']),
            'risk_summary' => $validated['risk_summary'],
        ]);

        return $draft->fresh(['actions', 'collection']) ?? $draft;
    }

    public function applyDraft(AiIntakeDraft $draft, Admin $admin): AiIntakeDraft
    {
        if ((string) $draft->status === AiIntakeDraft::STATUS_APPLIED) {
            throw new ApiException('draft_already_applied', '该草稿已应用', 409);
        }
        if ((string) $draft->status === AiIntakeDraft::STATUS_REJECTED) {
            throw new ApiException('draft_rejected', '已拒绝的草稿不能应用', 409);
        }

        DB::transaction(function () use ($draft, $admin): void {
            $draft->load('actions');
            foreach ($draft->actions as $action) {
                if ((string) $action->status !== AiIntakeAction::STATUS_PENDING) {
                    continue;
                }

                [$targetType, $targetId] = $this->applyAction($draft, $action, $admin);
                $action->update([
                    'status' => AiIntakeAction::STATUS_APPLIED,
                    'applied_target_type' => $targetType,
                    'applied_target_id' => $targetId,
                    'applied_at' => now(),
                    'error_message' => null,
                ]);
            }

            $draft->update([
                'status' => AiIntakeDraft::STATUS_APPLIED,
                'reviewed_by_admin_id' => (int) $admin->id,
                'applied_at' => now(),
            ]);
        });

        AdminActivityLogger::log($admin, 'assistant-intake:apply', [
            'request_method' => 'POST',
            'page' => 'assistant-intake-drafts',
            'target_type' => 'ai_intake_draft',
            'target_id' => (int) $draft->id,
            'details' => [
                'draft_id' => (int) $draft->id,
                'action_count' => $draft->actions()->count(),
            ],
        ]);

        return $draft->fresh(['actions', 'collection', 'reviewedBy']) ?? $draft;
    }

    public function rejectDraft(AiIntakeDraft $draft, Admin $admin, string $reason = ''): AiIntakeDraft
    {
        if ((string) $draft->status === AiIntakeDraft::STATUS_APPLIED) {
            throw new ApiException('draft_already_applied', '已应用的草稿不能拒绝', 409);
        }

        DB::transaction(function () use ($draft, $admin, $reason): void {
            $draft->actions()->where('status', AiIntakeAction::STATUS_PENDING)->update([
                'status' => AiIntakeAction::STATUS_REJECTED,
                'updated_at' => now(),
            ]);
            $draft->update([
                'status' => AiIntakeDraft::STATUS_REJECTED,
                'reviewed_by_admin_id' => (int) $admin->id,
                'rejected_reason' => trim($reason),
            ]);
        });

        AdminActivityLogger::log($admin, 'assistant-intake:reject', [
            'request_method' => 'POST',
            'page' => 'assistant-intake-drafts',
            'target_type' => 'ai_intake_draft',
            'target_id' => (int) $draft->id,
            'details' => ['reason' => trim($reason)],
        ]);

        return $draft->fresh(['actions', 'collection', 'reviewedBy']) ?? $draft;
    }

    /**
     * @return array<string, mixed>
     */
    public function draftSummary(AiIntakeDraft $draft): array
    {
        $draft->loadMissing(['actions', 'collection', 'createdBy', 'reviewedBy']);

        return [
            'id' => (int) $draft->id,
            'status' => (string) $draft->status,
            'source' => (string) $draft->source,
            'source_reference' => (string) $draft->source_reference,
            'collection_id' => $this->nullableInt($draft->collection_id),
            'collection_name' => (string) ($draft->collection?->name ?? ''),
            'raw_input' => (string) $draft->raw_input,
            'normalized_summary' => (string) ($draft->normalized_summary ?? ''),
            'confidence' => $draft->confidence !== null ? (float) $draft->confidence : null,
            'detected_language' => (string) ($draft->detected_language ?? ''),
            'warnings' => is_array($draft->metadata_json) ? ($draft->metadata_json['warnings'] ?? []) : [],
            'risk_summary' => is_array($draft->metadata_json) ? ($draft->metadata_json['risk_summary'] ?? []) : [],
            'created_by' => (string) ($draft->createdBy?->username ?? ''),
            'reviewed_by' => (string) ($draft->reviewedBy?->username ?? ''),
            'created_at' => $draft->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $draft->updated_at?->format('Y-m-d H:i:s'),
            'applied_at' => $draft->applied_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function actionSummaries(AiIntakeDraft $draft): array
    {
        $draft->loadMissing('actions');

        return $draft->actions
            ->map(fn (AiIntakeAction $action): array => $this->actionSummary($action))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function actionSummary(AiIntakeAction $action): array
    {
        return [
            'id' => (int) $action->id,
            'draft_id' => (int) $action->draft_id,
            'action_type' => (string) $action->action_type,
            'action_label' => $this->actionLabel((string) $action->action_type, (string) $action->target_type),
            'target_type' => (string) $action->target_type,
            'target_id' => $this->nullableInt($action->target_id),
            'payload' => is_array($action->payload_json) ? $action->payload_json : [],
            'relation' => is_array($action->relation_json) ? $action->relation_json : [],
            'diff' => is_array($action->diff_json) ? $action->diff_json : [],
            'confidence' => $action->confidence !== null ? (float) $action->confidence : null,
            'risk_level' => (string) $action->risk_level,
            'status' => (string) $action->status,
            'error_message' => (string) ($action->error_message ?? ''),
            'applied_target_type' => (string) ($action->applied_target_type ?? ''),
            'applied_target_id' => $this->nullableInt($action->applied_target_id),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeDraftPayload(array $payload): array
    {
        $rawInput = trim((string) ($payload['raw_input'] ?? ''));
        if ($rawInput === '') {
            throw new ApiException('validation_failed', 'raw_input 不能为空', 422, [
                'field_errors' => ['raw_input' => 'raw_input 不能为空'],
            ]);
        }

        $actions = $payload['actions'] ?? null;
        if (! is_array($actions) || $actions === []) {
            throw new ApiException('validation_failed', 'actions 至少需要一个动作', 422, [
                'field_errors' => ['actions' => 'actions 至少需要一个动作'],
            ]);
        }

        $collectionId = $this->nullableInt($payload['collection_id'] ?? null);
        if ($collectionId !== null && ! CollectionRecord::query()->whereKey($collectionId)->exists()) {
            throw new ApiException('collection_not_found', 'Collection 不存在', 404);
        }

        return [
            'source' => $this->limitString((string) ($payload['source'] ?? 'codex'), 80, 'codex'),
            'source_reference' => $this->limitString((string) ($payload['source_reference'] ?? ''), 255),
            'collection_id' => $collectionId,
            'raw_input' => mb_substr($rawInput, 0, 30000),
            'normalized_summary' => mb_substr(trim((string) ($payload['normalized_summary'] ?? $payload['summary'] ?? '')), 0, 30000),
            'detected_language' => $this->limitString((string) ($payload['detected_language'] ?? $payload['language'] ?? ''), 32),
            'confidence' => $this->normalizeConfidence($payload['confidence'] ?? null),
            'actions' => $this->normalizeActions($actions, $collectionId),
        ];
    }

    /**
     * @param  array<int, mixed>  $actions
     * @return list<array<string, mixed>>
     */
    private function normalizeActions(array $actions, ?int $draftCollectionId): array
    {
        $result = [];
        foreach ($actions as $index => $action) {
            if (! is_array($action)) {
                throw new ApiException('validation_failed', 'action 必须是对象', 422, [
                    'field_errors' => ['actions.'.$index => 'action 必须是对象'],
                ]);
            }

            $actionType = $this->limitString((string) ($action['action_type'] ?? ''), 40);
            $targetType = $this->limitString((string) ($action['target_type'] ?? ''), 80);
            if ($actionType === '' || $targetType === '') {
                throw new ApiException('validation_failed', 'action_type 和 target_type 不能为空', 422, [
                    'field_errors' => ['actions.'.$index => 'action_type 和 target_type 不能为空'],
                ]);
            }

            $payload = $action['payload'] ?? $action['payload_json'] ?? [];
            $relation = $action['relation'] ?? $action['relations'] ?? $action['relation_json'] ?? [];
            $diff = $action['diff'] ?? $action['diff_json'] ?? [];
            $payload = is_array($payload) ? $payload : [];
            $relation = is_array($relation) ? $relation : [];
            $diff = is_array($diff) ? $diff : [];
            if ($draftCollectionId !== null && ! isset($relation['collection_id'])) {
                $relation['collection_id'] = $draftCollectionId;
            }

            $result[] = [
                'action_type' => $actionType,
                'target_type' => $targetType,
                'target_id' => $this->nullableInt($action['target_id'] ?? null),
                'payload' => $payload,
                'relation' => $relation,
                'diff' => $diff,
                'confidence' => $this->normalizeConfidence($action['confidence'] ?? null),
                'risk_level' => $this->normalizeRisk((string) ($action['risk_level'] ?? 'medium')),
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return list<array<string, mixed>>
     */
    private function governanceWarnings(array $normalized): array
    {
        $warnings = [];
        if ($this->nullableInt($normalized['collection_id'] ?? null) === null) {
            $warnings[] = [
                'code' => 'collection_missing',
                'level' => 'medium',
                'message' => '草稿未指定 Collection，应用前请确认业务容器。',
            ];
        }

        $draftConfidence = $normalized['confidence'];
        if ($draftConfidence !== null && (float) $draftConfidence < 0.5) {
            $warnings[] = [
                'code' => 'low_confidence_draft',
                'level' => 'medium',
                'message' => '整体置信度较低，建议人工检查。',
            ];
        }

        foreach ((array) $normalized['actions'] as $index => $action) {
            if (! is_array($action)) {
                continue;
            }

            $confidence = $action['confidence'];
            if ($confidence !== null && (float) $confidence < 0.5) {
                $warnings[] = [
                    'code' => 'low_confidence_action',
                    'level' => 'medium',
                    'message' => '第 '.($index + 1).' 个动作置信度较低。',
                    'action_index' => $index,
                ];
            }

            if (($action['action_type'] ?? '') === 'create' && ($action['target_type'] ?? '') === 'customer') {
                $duplicate = $this->findDuplicateCustomer(is_array($action['payload'] ?? null) ? $action['payload'] : []);
                if ($duplicate) {
                    $warnings[] = [
                        'code' => 'possible_duplicate_customer',
                        'level' => 'medium',
                        'message' => '可能重复创建客户：'.$duplicate->company_name,
                        'action_index' => $index,
                        'target_id' => (int) $duplicate->id,
                    ];
                }
            }
        }

        return $warnings;
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     * @param  list<array<string, mixed>>  $warnings
     * @return list<array<string, mixed>>
     */
    private function applyGovernanceToActions(array $actions, array $warnings): array
    {
        foreach ($warnings as $warning) {
            if (! isset($warning['action_index'])) {
                continue;
            }
            $index = (int) $warning['action_index'];
            if (! isset($actions[$index])) {
                continue;
            }
            $actions[$index]['risk_level'] = $this->maxRisk((string) $actions[$index]['risk_level'], (string) ($warning['level'] ?? 'medium'));
        }

        return array_values($actions);
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     * @return array{low:int,medium:int,high:int}
     */
    private function riskSummary(array $actions): array
    {
        $summary = ['low' => 0, 'medium' => 0, 'high' => 0];
        foreach ($actions as $action) {
            $risk = $this->normalizeRisk((string) ($action['risk_level'] ?? 'medium'));
            $summary[$risk]++;
        }

        return $summary;
    }

    /**
     * @return array{0:string,1:int}
     */
    private function applyAction(AiIntakeDraft $draft, AiIntakeAction $action, Admin $admin): array
    {
        $payload = is_array($action->payload_json) ? $action->payload_json : [];
        $relation = is_array($action->relation_json) ? $action->relation_json : [];
        $targetType = (string) $action->target_type;
        $actionType = (string) $action->action_type;
        $collectionId = $this->nullableInt($payload['collection_id'] ?? $relation['collection_id'] ?? $draft->collection_id);

        if ($actionType === 'create' && $targetType === 'customer') {
            $companyName = trim((string) ($payload['company_name'] ?? ''));
            if ($companyName === '') {
                throw new ApiException('validation_failed', '创建客户需要 company_name', 422);
            }

            $customer = CrmCustomer::query()->create([
                'collection_id' => $collectionId,
                'company_name' => $companyName,
                'contact_person' => (string) ($payload['contact_person'] ?? ''),
                'customer_type' => (string) ($payload['customer_type'] ?? ''),
                'country' => (string) ($payload['country'] ?? ''),
                'address' => (string) ($payload['address'] ?? ''),
                'website' => (string) ($payload['website'] ?? ''),
                'industry' => (string) ($payload['industry'] ?? ''),
                'source_channel' => (string) ($payload['source_channel'] ?? $draft->source),
                'phone' => (string) ($payload['phone'] ?? ''),
                'email' => (string) ($payload['email'] ?? ''),
                'tax_number' => (string) ($payload['tax_number'] ?? ''),
                'contact_title' => (string) ($payload['contact_title'] ?? ''),
                'owner' => (string) ($payload['owner'] ?? $admin->username),
                'status' => (string) ($payload['status'] ?? 'active'),
                'notes' => (string) ($payload['notes'] ?? $draft->normalized_summary ?? ''),
                'created_by' => (int) $admin->id,
                'updated_by' => (int) $admin->id,
            ]);

            return [CrmCustomer::class, (int) $customer->id];
        }

        if ($actionType === 'create' && $targetType === 'inquiry') {
            $subject = trim((string) ($payload['subject'] ?? ''));
            if ($subject === '') {
                throw new ApiException('validation_failed', '创建询盘需要 subject', 422);
            }

            $inquiry = CrmInquiry::query()->create([
                'collection_id' => $collectionId,
                'customer_id' => $this->nullableInt($payload['customer_id'] ?? $relation['customer_id'] ?? null),
                'source_channel' => (string) ($payload['source_channel'] ?? $draft->source),
                'source_url' => (string) ($payload['source_url'] ?? ''),
                'subject' => $subject,
                'raw_message' => (string) ($payload['raw_message'] ?? $draft->raw_input),
                'detected_language' => (string) ($payload['detected_language'] ?? $draft->detected_language),
                'status' => (string) ($payload['status'] ?? 'new'),
                'priority' => (string) ($payload['priority'] ?? 'normal'),
                'assigned_to' => (string) ($payload['assigned_to'] ?? $admin->username),
                'customer_need_summary' => (string) ($payload['customer_need_summary'] ?? $draft->normalized_summary),
                'product_interest' => (string) ($payload['product_interest'] ?? ''),
                'suggested_reply_points' => (string) ($payload['suggested_reply_points'] ?? ''),
                'missing_information_questions' => (string) ($payload['missing_information_questions'] ?? ''),
                'urgency_level' => (string) ($payload['urgency_level'] ?? ''),
                'notes' => (string) ($payload['notes'] ?? ''),
            ]);

            return [CrmInquiry::class, (int) $inquiry->id];
        }

        if ($targetType === 'activity' || $actionType === 'note') {
            $content = trim((string) ($payload['content'] ?? $payload['note'] ?? ''));
            if ($content === '') {
                throw new ApiException('validation_failed', '创建活动记录需要 content', 422);
            }

            $followUp = CrmFollowUp::query()->create([
                'customer_id' => $this->nullableInt($payload['customer_id'] ?? $relation['customer_id'] ?? null),
                'inquiry_id' => $this->nullableInt($payload['inquiry_id'] ?? $relation['inquiry_id'] ?? null),
                'opportunity_id' => $this->nullableInt($payload['opportunity_id'] ?? $relation['opportunity_id'] ?? null),
                'task_id' => $this->nullableInt($payload['task_id'] ?? $relation['task_id'] ?? null),
                'followup_type' => (string) ($payload['followup_type'] ?? 'note'),
                'activity_type' => (string) ($payload['activity_type'] ?? 'note'),
                'content' => $content,
                'next_action' => (string) ($payload['next_action'] ?? ''),
                'next_followup_at' => (string) ($payload['next_followup_at'] ?? '') ?: null,
                'owner' => (string) ($payload['owner'] ?? $admin->username),
                'status' => (string) ($payload['status'] ?? 'open'),
            ]);

            return [CrmFollowUp::class, (int) $followUp->id];
        }

        if ($targetType === 'todo' || $targetType === 'task') {
            $title = trim((string) ($payload['title'] ?? ''));
            if ($title === '') {
                throw new ApiException('validation_failed', '创建待办需要 title', 422);
            }

            $task = CrmTask::query()->create([
                'customer_id' => $this->nullableInt($payload['customer_id'] ?? $relation['customer_id'] ?? null),
                'inquiry_id' => $this->nullableInt($payload['inquiry_id'] ?? $relation['inquiry_id'] ?? null),
                'opportunity_id' => $this->nullableInt($payload['opportunity_id'] ?? $relation['opportunity_id'] ?? null),
                'quote_id' => $this->nullableInt($payload['quote_id'] ?? $relation['quote_id'] ?? null),
                'order_id' => $this->nullableInt($payload['order_id'] ?? $relation['order_id'] ?? null),
                'ticket_id' => $this->nullableInt($payload['ticket_id'] ?? $relation['ticket_id'] ?? null),
                'assigned_admin_id' => $this->nullableInt($payload['assigned_admin_id'] ?? null),
                'created_by_admin_id' => (int) $admin->id,
                'title' => $title,
                'description' => (string) ($payload['description'] ?? ''),
                'priority' => (string) ($payload['priority'] ?? 'normal'),
                'status' => (string) ($payload['status'] ?? 'open'),
                'due_at' => (string) ($payload['due_at'] ?? '') ?: null,
            ]);

            return [CrmTask::class, (int) $task->id];
        }

        if ($actionType === 'create' && $targetType === 'ticket') {
            $title = trim((string) ($payload['title'] ?? ''));
            if ($title === '') {
                throw new ApiException('validation_failed', '创建售后工单需要 title', 422);
            }

            $ticket = CrmAfterSalesTicket::query()->create([
                'collection_id' => $collectionId,
                'customer_id' => $this->nullableInt($payload['customer_id'] ?? $relation['customer_id'] ?? null),
                'owner' => (string) ($payload['owner'] ?? $admin->username),
                'order_id' => $this->nullableInt($payload['order_id'] ?? $relation['order_id'] ?? null),
                'entity_id' => $this->nullableInt($payload['entity_id'] ?? $relation['entity_id'] ?? null),
                'title' => $title,
                'issue_description' => (string) ($payload['issue_description'] ?? $draft->raw_input),
                'issue_type' => (string) ($payload['issue_type'] ?? 'general'),
                'priority' => (string) ($payload['priority'] ?? 'normal'),
                'status' => (string) ($payload['status'] ?? 'open'),
                'reply_points' => (string) ($payload['reply_points'] ?? ''),
                'missing_information_questions' => (string) ($payload['missing_information_questions'] ?? ''),
                'resolution' => (string) ($payload['resolution'] ?? ''),
                'notes' => (string) ($payload['notes'] ?? ''),
            ]);

            return [CrmAfterSalesTicket::class, (int) $ticket->id];
        }

        if ($actionType === 'proposal' && in_array($targetType, ['knowledge_base', 'case'], true)) {
            $title = trim((string) ($payload['title'] ?? ''));
            $content = trim((string) ($payload['content'] ?? ''));
            if ($title === '' || $content === '') {
                throw new ApiException('validation_failed', '创建内容候选需要 title 和 content', 422);
            }

            $proposalType = (string) ($payload['proposal_type'] ?? ($targetType === 'case' ? 'case_draft' : 'faq_draft'));
            $proposal = CrmContentProposal::query()->create([
                'collection_id' => $collectionId,
                'source_type' => 'ai_intake_draft',
                'source_id' => (int) $draft->id,
                'proposal_type' => $proposalType,
                'title' => mb_substr($title, 0, 240),
                'content' => $content,
                'metadata_json' => json_encode([
                    'draft_id' => (int) $draft->id,
                    'action_id' => (int) $action->id,
                    'target_type' => $targetType,
                    'relation' => $relation,
                ], JSON_UNESCAPED_UNICODE),
                'status' => 'pending',
            ]);

            return [CrmContentProposal::class, (int) $proposal->id];
        }

        throw new ApiException('unsupported_intake_action', '暂不支持该 AI 录入动作：'.$actionType.' / '.$targetType, 422);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function findDuplicateCustomer(array $payload): ?CrmCustomer
    {
        $company = trim((string) ($payload['company_name'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        if ($company === '' && $email === '') {
            return null;
        }

        return CrmCustomer::query()
            ->where(function ($query) use ($company, $email): void {
                if ($company !== '') {
                    $query->orWhere('company_name', $company);
                }
                if ($email !== '') {
                    $query->orWhere('email', $email);
                }
            })
            ->orderByDesc('updated_at')
            ->first();
    }

    private function normalizeRisk(string $risk): string
    {
        $risk = strtolower(trim($risk));

        return array_key_exists($risk, self::RISK_ORDER) ? $risk : 'medium';
    }

    private function maxRisk(string $current, string $candidate): string
    {
        $current = $this->normalizeRisk($current);
        $candidate = $this->normalizeRisk($candidate);

        return self::RISK_ORDER[$candidate] > self::RISK_ORDER[$current] ? $candidate : $current;
    }

    private function normalizeConfidence(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }

        return max(0.0, min(1.0, (float) $value));
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function limitString(string $value, int $length, string $fallback = ''): string
    {
        $value = trim($value);
        if ($value === '') {
            $value = $fallback;
        }

        return mb_substr($value, 0, $length);
    }

    private function actionLabel(string $actionType, string $targetType): string
    {
        $map = [
            'create:customer' => '创建客户',
            'create:inquiry' => '创建询盘',
            'create:ticket' => '创建售后工单',
            'proposal:knowledge_base' => '创建知识库候选',
            'proposal:case' => '创建 Case 候选',
            'note:activity' => '记录活动',
            'create:todo' => '创建待办',
            'create:task' => '创建待办',
        ];

        return $map[$actionType.':'.$targetType] ?? ($actionType.' / '.$targetType);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function log(int $adminId, string $action, AiIntakeDraft $draft, array $details): void
    {
        $admin = Admin::query()->whereKey($adminId)->first();
        if (! $admin) {
            return;
        }

        AdminActivityLogger::log($admin, $action, [
            'request_method' => 'POST',
            'page' => 'assistant-intake-drafts',
            'target_type' => 'ai_intake_draft',
            'target_id' => (int) $draft->id,
            'details' => $details,
        ]);
    }
}
