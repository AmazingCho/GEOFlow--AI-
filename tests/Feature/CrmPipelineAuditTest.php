<?php

namespace Tests\Feature;

use App\Models\CollectionRecord;
use App\Models\CrmCustomer;
use App\Models\CrmFollowUp;
use App\Models\CrmInquiry;
use App\Models\CrmOpportunity;
use App\Models\CrmQuote;
use App\Models\CrmTask;
use App\Services\GeoFlow\CrmPipelineConsistencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CrmPipelineAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_contract_supports_opportunity_and_task_links(): void
    {
        $customer = CrmCustomer::query()->create([
            'company_name' => 'Activity Contract Buyer',
            'contact_person' => 'Buyer',
            'status' => 'active',
        ]);
        $opportunity = CrmOpportunity::query()->create([
            'customer_id' => $customer->id,
            'name' => 'Activity Contract Project',
            'stage' => 'qualified',
        ]);
        $task = CrmTask::query()->create([
            'customer_id' => $customer->id,
            'opportunity_id' => $opportunity->id,
            'title' => 'Confirm requirements',
            'status' => 'open',
        ]);

        $activity = CrmFollowUp::query()->create([
            'customer_id' => $customer->id,
            'opportunity_id' => $opportunity->id,
            'task_id' => $task->id,
            'content' => 'Requirements confirmed.',
        ]);

        $this->assertSame($opportunity->id, $activity->opportunity->id);
        $this->assertSame($task->id, $activity->task->id);
        $this->assertSame($activity->id, $task->activities->first()->id);
    }

    public function test_pipeline_audit_reports_broken_sales_chain_without_mutating_data(): void
    {
        $collection = CollectionRecord::query()->create([
            'name' => 'Audit Collection',
            'slug' => 'audit-collection',
            'status' => 'active',
        ]);
        $otherCollection = CollectionRecord::query()->create([
            'name' => 'Other Collection',
            'slug' => 'other-collection',
            'status' => 'active',
        ]);
        $customer = CrmCustomer::query()->create([
            'collection_id' => $collection->id,
            'company_name' => 'Audit Buyer',
            'contact_person' => 'Buyer',
            'status' => 'active',
        ]);
        $otherCustomer = CrmCustomer::query()->create([
            'collection_id' => $otherCollection->id,
            'company_name' => 'Other Buyer',
            'contact_person' => 'Other Buyer',
            'status' => 'active',
        ]);
        $inquiry = CrmInquiry::query()->create([
            'collection_id' => $collection->id,
            'customer_id' => $customer->id,
            'subject' => 'Audit inquiry',
            'status' => 'qualified',
            'priority' => 'normal',
        ]);
        $duplicateInquiry = CrmInquiry::query()->create([
            'collection_id' => $collection->id,
            'customer_id' => $customer->id,
            'subject' => 'Duplicate inquiry',
            'status' => 'qualified',
            'priority' => 'normal',
        ]);
        $mismatchInquiry = CrmInquiry::query()->create([
            'collection_id' => $collection->id,
            'customer_id' => $customer->id,
            'subject' => 'Mismatch inquiry',
            'status' => 'qualified',
            'priority' => 'normal',
        ]);
        $linkedOpportunity = CrmOpportunity::query()->create([
            'collection_id' => $collection->id,
            'customer_id' => $customer->id,
            'source_inquiry_id' => $inquiry->id,
            'name' => 'Linked opportunity',
            'stage' => 'qualified',
        ]);
        CrmOpportunity::query()->create([
            'collection_id' => $collection->id,
            'customer_id' => $customer->id,
            'source_inquiry_id' => $duplicateInquiry->id,
            'name' => 'Duplicate opportunity A',
            'stage' => 'qualified',
        ]);
        CrmOpportunity::query()->create([
            'collection_id' => $collection->id,
            'customer_id' => $customer->id,
            'source_inquiry_id' => $duplicateInquiry->id,
            'name' => 'Duplicate opportunity B',
            'stage' => 'qualified',
        ]);
        CrmOpportunity::query()->create([
            'collection_id' => $collection->id,
            'customer_id' => $customer->id,
            'name' => 'Direct opportunity',
            'stage' => 'qualified',
        ]);
        CrmOpportunity::query()->create([
            'collection_id' => $otherCollection->id,
            'customer_id' => $otherCustomer->id,
            'source_inquiry_id' => $mismatchInquiry->id,
            'name' => 'Mismatched opportunity',
            'stage' => 'qualified',
        ]);
        CrmTask::query()->create([
            'customer_id' => $customer->id,
            'inquiry_id' => $inquiry->id,
            'title' => 'Unlinked inquiry task',
            'status' => 'open',
        ]);
        CrmQuote::query()->create([
            'collection_id' => $collection->id,
            'customer_id' => $customer->id,
            'inquiry_id' => $inquiry->id,
            'quote_no' => 'Q-AUDIT-1',
            'title' => 'Unlinked inquiry document',
            'currency' => 'USD',
            'status' => 'draft',
        ]);
        $archivedOpportunity = CrmOpportunity::query()->create([
            'collection_id' => $collection->id,
            'customer_id' => $customer->id,
            'name' => 'Archived opportunity',
            'stage' => 'qualified',
        ]);
        $archivedOpportunity->delete();
        CrmTask::query()->create([
            'customer_id' => $customer->id,
            'opportunity_id' => $archivedOpportunity->id,
            'title' => 'Open task on archived opportunity',
            'status' => 'open',
        ]);

        $before = [
            'opportunities' => CrmOpportunity::withTrashed()->count(),
            'tasks' => CrmTask::withTrashed()->count(),
            'documents' => CrmQuote::withTrashed()->count(),
        ];

        $report = app(CrmPipelineConsistencyService::class)->audit();

        $this->assertCount(1, $report['orphan_opportunities']);
        $this->assertCount(1, $report['duplicate_inquiry_opportunities']);
        $this->assertCount(1, $report['unlinked_tasks']);
        $this->assertCount(1, $report['unlinked_documents']);
        $this->assertNotEmpty($report['relationship_mismatches']);
        $this->assertCount(1, $report['archived_opportunity_open_tasks']);
        $this->assertSame($linkedOpportunity->id, $report['unlinked_tasks'][0]['suggested_opportunity_id']);

        Artisan::call('crm:pipeline-audit', ['--json' => true]);
        $commandReport = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($report['summary'], $commandReport['summary']);

        $this->assertSame($before, [
            'opportunities' => CrmOpportunity::withTrashed()->count(),
            'tasks' => CrmTask::withTrashed()->count(),
            'documents' => CrmQuote::withTrashed()->count(),
        ]);
    }
}
