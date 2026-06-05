<?php

namespace Tests\Unit;

use App\Support\GeoFlow\MaterialAnalysisPromptRules;
use Tests\TestCase;

class MaterialAnalysisPromptRulesTest extends TestCase
{
    public function test_shared_rules_cover_language_tables_and_fact_grounding(): void
    {
        $language = MaterialAnalysisPromptRules::languageDirective([
            'code' => 'en',
            'name' => 'English',
        ]);
        $tableRules = MaterialAnalysisPromptRules::tableAccuracyRules();
        $factRules = MaterialAnalysisPromptRules::factGroundingRules();

        $this->assertStringContainsString('Do not output Chinese text unless the target output language is Chinese', $language);
        $this->assertStringContainsString('Preserve every meaningful row, column, header, unit, model number', $tableRules);
        $this->assertStringContainsString('Do not invent customers, certifications, rankings', $factRules);
    }
}
