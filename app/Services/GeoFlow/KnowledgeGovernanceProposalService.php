<?php

namespace App\Services\GeoFlow;

use App\Models\Admin;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeGovernanceProposal;
use App\Support\AdminActivityLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class KnowledgeGovernanceProposalService
{
    /**
     * @param  array<string,mixed>  $issuePayload
     */
    public function create(string $proposalType, array $issuePayload, ?Admin $admin = null, string $note = ''): KnowledgeGovernanceProposal
    {
        if (! in_array($proposalType, [KnowledgeGovernanceProposal::TYPE_DUPLICATE_ARCHIVE, KnowledgeGovernanceProposal::TYPE_CONFLICT_REVIEW], true)) {
            throw new RuntimeException(__('admin.knowledge_governance_proposals.error.unsupported_type'));
        }

        $knowledgeBaseIds = $this->knowledgeBaseIdsFromIssue($proposalType, $issuePayload);
        if (count($knowledgeBaseIds) < 2) {
            throw new RuntimeException(__('admin.knowledge_governance_proposals.error.insufficient_sources'));
        }

        $knowledgeBases = KnowledgeBase::query()
            ->whereIn('id', $knowledgeBaseIds)
            ->orderByRaw('CASE id '.implode(' ', array_map(
                static fn (int $id, int $index): string => 'WHEN '.$id.' THEN '.$index,
                $knowledgeBaseIds,
                array_keys($knowledgeBaseIds)
            )).' END')
            ->get()
            ->keyBy('id');

        if ($knowledgeBases->count() < 2) {
            throw new RuntimeException(__('admin.knowledge_governance_proposals.error.insufficient_sources'));
        }

        $primaryId = (int) $knowledgeBaseIds[0];
        $relatedIds = array_values(array_filter($knowledgeBaseIds, static fn (int $id): bool => $id !== $primaryId));
        $primary = $knowledgeBases->get($primaryId) ?? $knowledgeBases->first();
        $collectionId = (int) ($primary?->collection_id ?? 0) ?: null;

        $proposal = KnowledgeGovernanceProposal::query()->create([
            'proposal_type' => $proposalType,
            'status' => KnowledgeGovernanceProposal::STATUS_PENDING,
            'collection_id' => $collectionId,
            'primary_knowledge_base_id' => $primaryId,
            'related_knowledge_base_ids' => $relatedIds,
            'detection_snapshot' => $issuePayload,
            'proposed_content' => $this->proposedActionText($proposalType, $knowledgeBases->all(), $primaryId, $relatedIds),
            'before_content_snapshot' => $this->snapshotFor($knowledgeBases->all()),
            'admin_note' => trim($note),
            'created_by_admin_id' => $admin ? (int) $admin->id : null,
        ]);

        $this->log($admin, 'knowledge-governance-proposal:create', $proposal, [
            'proposal_type' => $proposalType,
            'knowledge_base_ids' => $knowledgeBaseIds,
        ]);

        return $proposal;
    }

    public function reject(KnowledgeGovernanceProposal $proposal, ?Admin $admin = null, string $note = ''): KnowledgeGovernanceProposal
    {
        if ($proposal->status === KnowledgeGovernanceProposal::STATUS_APPLIED) {
            throw new RuntimeException(__('admin.knowledge_governance_proposals.error.applied_cannot_reject'));
        }

        $proposal->forceFill([
            'status' => KnowledgeGovernanceProposal::STATUS_REJECTED,
            'admin_note' => trim($note) !== '' ? trim($note) : $proposal->admin_note,
        ])->save();

        $this->log($admin, 'knowledge-governance-proposal:reject', $proposal);

        return $proposal->refresh();
    }

    public function apply(KnowledgeGovernanceProposal $proposal, ?Admin $admin = null, string $note = ''): KnowledgeGovernanceProposal
    {
        if ($proposal->status === KnowledgeGovernanceProposal::STATUS_REJECTED) {
            throw new RuntimeException(__('admin.knowledge_governance_proposals.error.rejected_cannot_apply'));
        }
        if ($proposal->status === KnowledgeGovernanceProposal::STATUS_APPLIED) {
            throw new RuntimeException(__('admin.knowledge_governance_proposals.error.already_applied'));
        }
        if ($proposal->proposal_type === KnowledgeGovernanceProposal::TYPE_DUPLICATE_MERGE) {
            throw new RuntimeException(__('admin.knowledge_governance_proposals.error.merge_disabled'));
        }

        if ($proposal->proposal_type === KnowledgeGovernanceProposal::TYPE_CONFLICT_REVIEW) {
            $proposal->forceFill([
                'status' => KnowledgeGovernanceProposal::STATUS_APPROVED,
                'admin_note' => trim($note) !== '' ? trim($note) : $proposal->admin_note,
                'applied_by_admin_id' => $admin ? (int) $admin->id : null,
                'applied_at' => now(),
            ])->save();
            $this->log($admin, 'knowledge-governance-proposal:review', $proposal);

            return $proposal->refresh();
        }

        if ($proposal->proposal_type !== KnowledgeGovernanceProposal::TYPE_DUPLICATE_ARCHIVE) {
            throw new RuntimeException(__('admin.knowledge_governance_proposals.error.unsupported_type'));
        }

        return DB::transaction(function () use ($proposal, $admin, $note): KnowledgeGovernanceProposal {
            /** @var KnowledgeGovernanceProposal $locked */
            $locked = KnowledgeGovernanceProposal::query()->whereKey((int) $proposal->id)->lockForUpdate()->firstOrFail();
            $relatedIds = $this->relatedIds($locked);
            if ($relatedIds === []) {
                throw new RuntimeException(__('admin.knowledge_governance_proposals.error.no_related_sources'));
            }

            KnowledgeBase::query()
                ->whereIn('id', $relatedIds)
                ->lockForUpdate()
                ->get()
                ->each(static function (KnowledgeBase $knowledgeBase): void {
                    $knowledgeBase->forceFill(['status' => 'inactive'])->save();
                });

            $locked->forceFill([
                'status' => KnowledgeGovernanceProposal::STATUS_APPLIED,
                'admin_note' => trim($note) !== '' ? trim($note) : $locked->admin_note,
                'applied_by_admin_id' => $admin ? (int) $admin->id : null,
                'applied_at' => now(),
            ])->save();

            $this->log($admin, 'knowledge-governance-proposal:apply-archive', $locked, [
                'archived_knowledge_base_ids' => $relatedIds,
            ]);

            return $locked->refresh();
        });
    }

    public function rollback(KnowledgeGovernanceProposal $proposal, ?Admin $admin = null, string $note = ''): KnowledgeGovernanceProposal
    {
        if ($proposal->status !== KnowledgeGovernanceProposal::STATUS_APPLIED) {
            throw new RuntimeException(__('admin.knowledge_governance_proposals.error.only_applied_can_rollback'));
        }
        if ($proposal->proposal_type !== KnowledgeGovernanceProposal::TYPE_DUPLICATE_ARCHIVE) {
            throw new RuntimeException(__('admin.knowledge_governance_proposals.error.rollback_not_supported'));
        }

        return DB::transaction(function () use ($proposal, $admin, $note): KnowledgeGovernanceProposal {
            /** @var KnowledgeGovernanceProposal $locked */
            $locked = KnowledgeGovernanceProposal::query()->whereKey((int) $proposal->id)->lockForUpdate()->firstOrFail();
            $snapshot = $this->decodeBeforeSnapshot($locked);
            $relatedIds = $this->relatedIds($locked);

            KnowledgeBase::query()
                ->whereIn('id', $relatedIds)
                ->lockForUpdate()
                ->get()
                ->each(function (KnowledgeBase $knowledgeBase) use ($snapshot): void {
                    $before = $snapshot[(int) $knowledgeBase->id] ?? [];
                    $knowledgeBase->forceFill([
                        'status' => (string) ($before['status'] ?? 'active'),
                    ])->save();
                });

            $locked->forceFill([
                'status' => KnowledgeGovernanceProposal::STATUS_ROLLED_BACK,
                'admin_note' => trim($note) !== '' ? trim($note) : $locked->admin_note,
                'rolled_back_by_admin_id' => $admin ? (int) $admin->id : null,
                'rolled_back_at' => now(),
            ])->save();

            $this->log($admin, 'knowledge-governance-proposal:rollback', $locked, [
                'restored_knowledge_base_ids' => $relatedIds,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string,mixed>  $issuePayload
     * @return list<int>
     */
    private function knowledgeBaseIdsFromIssue(string $proposalType, array $issuePayload): array
    {
        $items = $proposalType === KnowledgeGovernanceProposal::TYPE_CONFLICT_REVIEW
            ? [data_get($issuePayload, 'left', []), data_get($issuePayload, 'right', [])]
            : (array) data_get($issuePayload, 'items', []);

        return collect($items)
            ->map(static fn (mixed $item): int => (int) data_get($item, 'id', 0))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int,KnowledgeBase>  $knowledgeBases
     */
    private function proposedActionText(string $proposalType, array $knowledgeBases, int $primaryId, array $relatedIds): string
    {
        if ($proposalType === KnowledgeGovernanceProposal::TYPE_CONFLICT_REVIEW) {
            return __('admin.knowledge_governance_proposals.default_conflict_action');
        }

        $primaryName = isset($knowledgeBases[$primaryId]) ? (string) $knowledgeBases[$primaryId]->name : '#'.$primaryId;
        $relatedNames = collect($relatedIds)
            ->map(static fn (int $id): string => isset($knowledgeBases[$id]) ? (string) $knowledgeBases[$id]->name : '#'.$id)
            ->implode('、');

        return __('admin.knowledge_governance_proposals.default_archive_action', [
            'primary' => $primaryName,
            'related' => $relatedNames,
        ]);
    }

    /**
     * @param  array<int,KnowledgeBase>  $knowledgeBases
     */
    private function snapshotFor(array $knowledgeBases): string
    {
        return json_encode(collect($knowledgeBases)
            ->mapWithKeys(static fn (KnowledgeBase $knowledgeBase): array => [
                (int) $knowledgeBase->id => [
                    'id' => (int) $knowledgeBase->id,
                    'name' => (string) $knowledgeBase->name,
                    'status' => (string) ($knowledgeBase->status ?? 'active'),
                    'content' => (string) ($knowledgeBase->content ?? ''),
                    'updated_at' => $knowledgeBase->updated_at?->format('Y-m-d H:i:s'),
                ],
            ])
            ->all(), JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function decodeBeforeSnapshot(KnowledgeGovernanceProposal $proposal): array
    {
        $decoded = json_decode((string) ($proposal->before_content_snapshot ?? ''), true);
        if (! is_array($decoded)) {
            return [];
        }

        $snapshot = [];
        foreach ($decoded as $id => $value) {
            if (is_array($value)) {
                $snapshot[(int) $id] = $value;
            }
        }

        return $snapshot;
    }

    /**
     * @return list<int>
     */
    private function relatedIds(KnowledgeGovernanceProposal $proposal): array
    {
        return collect((array) ($proposal->related_knowledge_base_ids ?? []))
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $details
     */
    private function log(?Admin $admin, string $action, KnowledgeGovernanceProposal $proposal, array $details = []): void
    {
        if (! $admin) {
            return;
        }

        AdminActivityLogger::log($admin, $action, [
            'request_method' => 'POST',
            'page' => 'knowledge_governance',
            'target_type' => KnowledgeGovernanceProposal::class,
            'target_id' => (int) $proposal->id,
            'details' => array_merge([
                'proposal_type' => (string) $proposal->proposal_type,
                'status' => (string) $proposal->status,
                'primary_knowledge_base_id' => (int) ($proposal->primary_knowledge_base_id ?? 0),
                'related_knowledge_base_ids' => $this->relatedIds($proposal),
            ], $details),
        ]);
    }
}
