<?php

namespace App\Services\GeoFlow;

use App\Models\Admin;
use App\Models\CrmInquiry;
use App\Models\CrmOpportunity;
use App\Models\CrmQuote;
use App\Models\CrmTask;
use App\Support\AdminActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OpportunityConversionService
{
    /**
     * @param array<string, mixed> $attributes
     * @return array{opportunity:CrmOpportunity,created:bool,linked_tasks:int,linked_documents:int}
     */
    public function convert(CrmInquiry $inquiry, array $attributes = [], ?Admin $admin = null): array
    {
        return DB::transaction(function () use ($inquiry, $attributes, $admin): array {
            $lockedInquiry = CrmInquiry::query()->lockForUpdate()->findOrFail($inquiry->id);
            $existing = CrmOpportunity::query()
                ->where('source_inquiry_id', $lockedInquiry->id)
                ->oldest('id')
                ->first();

            if ($existing) {
                return [
                    'opportunity' => $existing,
                    'created' => false,
                    'linked_tasks' => 0,
                    'linked_documents' => 0,
                ];
            }
            if ((int) ($lockedInquiry->customer_id ?? 0) <= 0) {
                throw ValidationException::withMessages([
                    'customer_id' => '转为商机前，请先为询盘关联客户。',
                ]);
            }

            $payload = array_merge([
                'name' => (string) $lockedInquiry->subject,
                'stage' => 'qualified',
                'amount' => 0,
                'currency' => 'USD',
                'probability' => 20,
            ], $attributes, [
                'collection_id' => (int) ($lockedInquiry->collection_id ?? 0) ?: null,
                'customer_id' => (int) $lockedInquiry->customer_id,
                'source_inquiry_id' => (int) $lockedInquiry->id,
            ]);

            $opportunity = CrmOpportunity::query()->create($payload);
            $linkedTasks = CrmTask::query()
                ->where('inquiry_id', $lockedInquiry->id)
                ->whereNull('opportunity_id')
                ->where('status', '<>', 'done')
                ->update(['opportunity_id' => $opportunity->id]);
            $linkedDocuments = CrmQuote::query()
                ->where('inquiry_id', $lockedInquiry->id)
                ->whereNull('opportunity_id')
                ->update(['opportunity_id' => $opportunity->id]);

            if (! in_array((string) $lockedInquiry->status, ['quoted', 'won', 'lost', 'closed'], true)) {
                $lockedInquiry->update(['status' => 'converted']);
            }

            if ($admin) {
                AdminActivityLogger::log($admin, 'crm:opportunity:convert', [
                    'request_method' => 'POST',
                    'page' => 'crm-opportunity-conversion',
                    'target_type' => 'opportunity',
                    'target_id' => (int) $opportunity->id,
                    'details' => [
                        'inquiry_id' => (int) $lockedInquiry->id,
                        'linked_tasks' => (int) $linkedTasks,
                        'linked_documents' => (int) $linkedDocuments,
                    ],
                ]);
            }

            return [
                'opportunity' => $opportunity,
                'created' => true,
                'linked_tasks' => (int) $linkedTasks,
                'linked_documents' => (int) $linkedDocuments,
            ];
        });
    }
}
