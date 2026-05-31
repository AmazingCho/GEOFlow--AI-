<?php

namespace Tests\Feature;

use App\Models\CaseRecord;
use App\Models\EntityRecord;
use App\Models\UrlImportJob;
use App\Services\GeoFlow\EntityExtractionService;
use App\Services\GeoFlow\TagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntityExtractionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_extracts_entity_and_case_candidates_from_url_import_analysis(): void
    {
        app(TagService::class)->firstOrCreateTag('行业', '制造业');
        $job = UrlImportJob::query()->create([
            'url' => 'https://example.test/case',
            'normalized_url' => 'https://example.test/case',
            'source_domain' => 'example.test',
            'page_title' => '制造业智能客服案例',
            'status' => 'completed',
            'current_step' => 'preview',
            'progress_percent' => 100,
            'options_json' => '{}',
            'result_json' => '',
            'error_message' => '',
            'created_by' => 'tester',
        ]);

        $analysis = [
            'summary' => '制造业智能客服帮助售后团队提升响应效率。',
            'library_name' => '制造业智能客服案例',
            'keywords' => ['制造业', '智能客服'],
            'cleaned' => [
                'entities' => ['制造业客户A', '智能客服'],
                'facts' => ['响应时间下降 40%。'],
                'core_business' => [
                    'industry' => '制造业',
                    'products_services' => ['智能客服'],
                    'target_audience' => ['售后团队'],
                    'commercial_scenarios' => ['售后响应'],
                    'value_proposition' => '提升响应效率',
                ],
            ],
        ];

        $result = app(EntityExtractionService::class)->extractFromUrlImport($analysis, ['title' => '制造业智能客服案例'], $job);

        $this->assertSame('制造业客户A', $result['entities'][0]['name']);
        $this->assertSame('URL采集案例', $result['cases'][0]['case_type']);
        $this->assertSame('响应时间下降 40%。', $result['cases'][0]['metrics']);
    }

    public function test_it_persists_candidates_without_duplicate_entities(): void
    {
        $existing = EntityRecord::query()->create([
            'name' => '制造业客户A',
            'entity_type' => '客户',
            'description' => '已有实体',
            'attributes_json' => '{}',
        ]);

        $summary = app(EntityExtractionService::class)->persistCandidates([
            'entities' => [[
                'name' => '制造业客户A',
                'entity_type' => '客户',
                'description' => '新的描述',
                'attributes_json' => '{}',
                'source_url' => 'https://example.test/case',
                'recommended_tag_ids' => [],
            ]],
            'cases' => [[
                'entity_name' => '制造业客户A',
                'title' => '制造业智能客服案例 资料案例',
                'case_type' => 'URL采集案例',
                'summary' => '智能客服帮助售后团队提升响应效率。',
                'challenge' => '售后团队',
                'solution' => '智能客服',
                'result' => '响应时间下降 40%。',
                'metrics' => '响应时间下降 40%。',
                'source_url' => 'https://example.test/case',
                'recommended_tag_ids' => [],
            ]],
        ]);

        $this->assertSame(['entities' => 0, 'cases' => 1], $summary);
        $this->assertSame(1, EntityRecord::query()->where('name', '制造业客户A')->count());
        $caseRecord = CaseRecord::query()->where('title', '制造业智能客服案例 资料案例')->firstOrFail();
        $this->assertSame((int) $existing->id, (int) $caseRecord->entity_id);
    }
}
