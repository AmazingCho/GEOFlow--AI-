<?php

namespace App\Services\GeoFlow;

use App\Models\CrmFollowUp;
use App\Models\AdminActivityLog;
use App\Models\CrmOpportunity;
use App\Models\CrmQuote;
use App\Models\CrmTask;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CrmPipelineConsistencyService
{
    /**
     * Build a read-only report. This method must never mutate CRM records.
     *
     * @return array<string, mixed>
     */
    public function audit(): array
    {
        $orphanOpportunities = CrmOpportunity::query()
            ->whereNull('source_inquiry_id')
            ->orderBy('id')
            ->get(['id', 'customer_id', 'collection_id', 'name'])
            ->map(fn (CrmOpportunity $opportunity): array => $this->opportunityRow($opportunity))
            ->values()
            ->all();

        $duplicateInquiryOpportunities = CrmOpportunity::query()
            ->whereNotNull('source_inquiry_id')
            ->select('source_inquiry_id', DB::raw('COUNT(*) as opportunity_count'))
            ->groupBy('source_inquiry_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('source_inquiry_id')
            ->get()
            ->map(function ($row): array {
                $ids = CrmOpportunity::query()
                    ->where('source_inquiry_id', (int) $row->source_inquiry_id)
                    ->orderBy('id')
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();

                return [
                    'inquiry_id' => (int) $row->source_inquiry_id,
                    'opportunity_count' => (int) $row->opportunity_count,
                    'opportunity_ids' => $ids,
                ];
            })
            ->values()
            ->all();

        $unlinkedTasks = CrmTask::query()
            ->with('inquiry.opportunities')
            ->whereNotNull('inquiry_id')
            ->whereNull('opportunity_id')
            ->orderBy('id')
            ->get()
            ->map(function (CrmTask $task): array {
                $candidates = $task->inquiry?->opportunities ?? collect();

                return [
                    'task_id' => (int) $task->id,
                    'inquiry_id' => (int) $task->inquiry_id,
                    'customer_id' => (int) ($task->customer_id ?? 0),
                    'title' => (string) $task->title,
                    'suggested_opportunity_id' => $this->uniqueCandidateId($candidates),
                    'candidate_count' => $candidates->count(),
                ];
            })
            ->values()
            ->all();

        $unlinkedDocuments = CrmQuote::query()
            ->with('inquiry.opportunities')
            ->whereNotNull('inquiry_id')
            ->whereNull('opportunity_id')
            ->orderBy('id')
            ->get()
            ->map(function (CrmQuote $quote): array {
                $candidates = $quote->inquiry?->opportunities ?? collect();

                return [
                    'document_id' => (int) $quote->id,
                    'inquiry_id' => (int) $quote->inquiry_id,
                    'customer_id' => (int) $quote->customer_id,
                    'title' => (string) $quote->title,
                    'suggested_opportunity_id' => $this->uniqueCandidateId($candidates),
                    'candidate_count' => $candidates->count(),
                ];
            })
            ->values()
            ->all();

        $relationshipMismatches = $this->relationshipMismatches();

        $archivedOpportunityOpenTasks = CrmTask::query()
            ->join('crm_opportunities', 'crm_opportunities.id', '=', 'crm_tasks.opportunity_id')
            ->whereNotNull('crm_opportunities.deleted_at')
            ->where('crm_tasks.status', '<>', 'done')
            ->whereNull('crm_tasks.deleted_at')
            ->orderBy('crm_tasks.id')
            ->get([
                'crm_tasks.id as task_id',
                'crm_tasks.opportunity_id',
                'crm_tasks.title',
            ])
            ->map(static fn ($row): array => [
                'task_id' => (int) $row->task_id,
                'opportunity_id' => (int) $row->opportunity_id,
                'title' => (string) $row->title,
            ])
            ->values()
            ->all();

        $activityCandidates = CrmFollowUp::query()
            ->with('inquiry.opportunities')
            ->whereNotNull('inquiry_id')
            ->whereNull('opportunity_id')
            ->orderBy('id')
            ->get()
            ->map(function (CrmFollowUp $activity): array {
                $candidates = $activity->inquiry?->opportunities ?? collect();

                return [
                    'activity_id' => (int) $activity->id,
                    'inquiry_id' => (int) $activity->inquiry_id,
                    'suggested_opportunity_id' => $this->uniqueCandidateId($candidates),
                    'candidate_count' => $candidates->count(),
                ];
            })
            ->values()
            ->all();

        $report = [
            'orphan_opportunities' => $orphanOpportunities,
            'duplicate_inquiry_opportunities' => $duplicateInquiryOpportunities,
            'unlinked_tasks' => $unlinkedTasks,
            'unlinked_documents' => $unlinkedDocuments,
            'relationship_mismatches' => $relationshipMismatches,
            'archived_opportunity_open_tasks' => $archivedOpportunityOpenTasks,
            'activity_candidates' => $activityCandidates,
        ];

        $report['summary'] = collect($report)
            ->map(static fn (array $issues): int => count($issues))
            ->all();
        $report['summary']['total_issues'] = array_sum($report['summary']);

        return $report;
    }

    /**
     * Apply only unambiguous relationship repairs from the current audit report.
     *
     * @return array<string, mixed>
     */
    public function repairUniqueLinks(): array
    {
        $before = $this->audit();
        $applied = [
            'tasks_linked' => 0,
            'documents_linked' => 0,
            'activities_linked' => 0,
            'document_collections_fixed' => 0,
        ];
        $skipped = [
            'orphan_opportunities' => count($before['orphan_opportunities']),
            'duplicate_inquiry_opportunities' => count($before['duplicate_inquiry_opportunities']),
            'unlinked_tasks_without_unique_candidate' => 0,
            'unlinked_documents_without_unique_candidate' => 0,
            'activities_without_unique_candidate' => 0,
            'relationship_mismatches_not_auto_fixed' => 0,
        ];

        DB::transaction(function () use ($before, &$applied, &$skipped): void {
            foreach ($before['unlinked_tasks'] as $row) {
                $candidateId = (int) ($row['suggested_opportunity_id'] ?? 0);
                if ($candidateId <= 0) {
                    $skipped['unlinked_tasks_without_unique_candidate']++;
                    continue;
                }
                $updated = CrmTask::query()
                    ->whereKey((int) $row['task_id'])
                    ->whereNull('opportunity_id')
                    ->update(['opportunity_id' => $candidateId]);
                $applied['tasks_linked'] += (int) $updated;
            }

            foreach ($before['unlinked_documents'] as $row) {
                $candidateId = (int) ($row['suggested_opportunity_id'] ?? 0);
                if ($candidateId <= 0) {
                    $skipped['unlinked_documents_without_unique_candidate']++;
                    continue;
                }
                $opportunity = CrmOpportunity::query()->find($candidateId);
                $values = ['opportunity_id' => $candidateId];
                if ($opportunity && $opportunity->collection_id) {
                    $values['collection_id'] = (int) $opportunity->collection_id;
                }
                $updated = CrmQuote::query()
                    ->whereKey((int) $row['document_id'])
                    ->whereNull('opportunity_id')
                    ->update($values);
                $applied['documents_linked'] += (int) $updated;
            }

            foreach ($before['activity_candidates'] as $row) {
                $candidateId = (int) ($row['suggested_opportunity_id'] ?? 0);
                if ($candidateId <= 0) {
                    $skipped['activities_without_unique_candidate']++;
                    continue;
                }
                $updated = CrmFollowUp::query()
                    ->whereKey((int) $row['activity_id'])
                    ->whereNull('opportunity_id')
                    ->update(['opportunity_id' => $candidateId]);
                $applied['activities_linked'] += (int) $updated;
            }

            foreach ($before['relationship_mismatches'] as $row) {
                if (($row['record_type'] ?? '') === 'document'
                    && ($row['field'] ?? '') === 'collection_id'
                    && ($row['actual'] ?? null) === null
                    && (int) ($row['expected'] ?? 0) > 0) {
                    $updated = CrmQuote::query()
                        ->whereKey((int) $row['record_id'])
                        ->whereNull('collection_id')
                        ->update(['collection_id' => (int) $row['expected']]);
                    $applied['document_collections_fixed'] += (int) $updated;
                    continue;
                }
                $skipped['relationship_mismatches_not_auto_fixed']++;
            }

            if (Schema::hasTable('admin_activity_logs')) {
                AdminActivityLog::query()->create([
                    'admin_id' => null,
                    'admin_username' => 'system',
                    'admin_role' => 'system',
                    'action' => 'crm:pipeline-audit:apply',
                    'request_method' => 'CLI',
                    'page' => 'crm-pipeline-audit',
                    'target_type' => 'crm_pipeline',
                    'target_id' => null,
                    'ip_address' => '',
                    'details' => json_encode(['applied' => $applied, 'skipped' => $skipped], JSON_UNESCAPED_UNICODE),
                ]);
            }
        });

        return [
            'before' => $before,
            'repair' => ['applied' => $applied, 'skipped' => $skipped],
            'after' => $this->audit(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function relationshipMismatches(): array
    {
        $issues = [];

        CrmOpportunity::query()
            ->with('sourceInquiry')
            ->whereNotNull('source_inquiry_id')
            ->orderBy('id')
            ->each(function (CrmOpportunity $opportunity) use (&$issues): void {
                $inquiry = $opportunity->sourceInquiry;
                if (! $inquiry) {
                    return;
                }
                if ((int) $opportunity->customer_id !== (int) $inquiry->customer_id) {
                    $issues[] = $this->mismatch('opportunity', $opportunity->id, 'customer_id', $opportunity->customer_id, $inquiry->customer_id);
                }
                if ($this->differentNullableId($opportunity->collection_id, $inquiry->collection_id)) {
                    $issues[] = $this->mismatch('opportunity', $opportunity->id, 'collection_id', $opportunity->collection_id, $inquiry->collection_id);
                }
            });

        CrmTask::query()
            ->with(['inquiry', 'opportunity'])
            ->where(function ($query): void {
                $query->whereNotNull('inquiry_id')->orWhereNotNull('opportunity_id');
            })
            ->orderBy('id')
            ->each(function (CrmTask $task) use (&$issues): void {
                foreach ([$task->inquiry, $task->opportunity] as $parent) {
                    if ($parent && (int) ($task->customer_id ?? 0) !== (int) ($parent->customer_id ?? 0)) {
                        $issues[] = $this->mismatch('task', $task->id, 'customer_id', $task->customer_id, $parent->customer_id);
                    }
                }
                if ($task->inquiry && $task->opportunity && (int) $task->opportunity->source_inquiry_id !== (int) $task->inquiry_id) {
                    $issues[] = $this->mismatch('task', $task->id, 'inquiry_opportunity_link', $task->inquiry_id, $task->opportunity->source_inquiry_id);
                }
            });

        CrmQuote::query()
            ->with(['inquiry', 'opportunity'])
            ->where(function ($query): void {
                $query->whereNotNull('inquiry_id')->orWhereNotNull('opportunity_id');
            })
            ->orderBy('id')
            ->each(function (CrmQuote $quote) use (&$issues): void {
                foreach ([$quote->inquiry, $quote->opportunity] as $parent) {
                    if ($parent && (int) $quote->customer_id !== (int) ($parent->customer_id ?? 0)) {
                        $issues[] = $this->mismatch('document', $quote->id, 'customer_id', $quote->customer_id, $parent->customer_id);
                    }
                    if ($parent && $this->differentNullableId($quote->collection_id, $parent->collection_id)) {
                        $issues[] = $this->mismatch('document', $quote->id, 'collection_id', $quote->collection_id, $parent->collection_id);
                    }
                }
                if ($quote->inquiry && $quote->opportunity && (int) $quote->opportunity->source_inquiry_id !== (int) $quote->inquiry_id) {
                    $issues[] = $this->mismatch('document', $quote->id, 'inquiry_opportunity_link', $quote->inquiry_id, $quote->opportunity->source_inquiry_id);
                }
            });

        return $issues;
    }

    /** @param Collection<int, CrmOpportunity> $candidates */
    private function uniqueCandidateId(Collection $candidates): ?int
    {
        return $candidates->count() === 1 ? (int) $candidates->first()->id : null;
    }

    /** @return array<string, mixed> */
    private function opportunityRow(CrmOpportunity $opportunity): array
    {
        return [
            'opportunity_id' => (int) $opportunity->id,
            'customer_id' => (int) $opportunity->customer_id,
            'collection_id' => (int) ($opportunity->collection_id ?? 0) ?: null,
            'name' => (string) $opportunity->name,
        ];
    }

    /** @return array<string, mixed> */
    private function mismatch(string $type, int $id, string $field, mixed $actual, mixed $expected): array
    {
        return [
            'record_type' => $type,
            'record_id' => $id,
            'field' => $field,
            'actual' => $actual,
            'expected' => $expected,
        ];
    }

    private function differentNullableId(mixed $left, mixed $right): bool
    {
        return ((int) ($left ?? 0) ?: null) !== ((int) ($right ?? 0) ?: null);
    }
}
