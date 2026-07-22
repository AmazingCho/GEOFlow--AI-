<?php

namespace Tests\Unit;

use App\Services\GeoFlow\PromptPresetCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PromptSkillContractTest extends TestCase
{
    private const MASTER_NAME = 'GEO Master - Trust-Based Article Generation';

    /**
     * @var array<string, array{name:string,min:int,max:int}>
     */
    private const CANONICAL_SKILLS = [
        'comparison' => ['name' => 'GEO Skill - Comparison', 'min' => 250, 'max' => 400],
        'buying_guide' => ['name' => 'GEO Skill - Buying Guide', 'min' => 250, 'max' => 400],
        'application' => ['name' => 'GEO Skill - Application', 'min' => 250, 'max' => 400],
        'technical' => ['name' => 'GEO Skill - Technical', 'min' => 250, 'max' => 400],
        'troubleshooting' => ['name' => 'GEO Skill - Troubleshooting', 'min' => 400, 'max' => 550],
        'case_study' => ['name' => 'GEO Skill - Case Study', 'min' => 400, 'max' => 550],
        'definition' => ['name' => 'GEO Skill - Definition', 'min' => 250, 'max' => 400],
    ];

    /** @var array<string,string> */
    private const CANONICAL_STYLES = [
        'article.style.technical_clarity' => 'Technical Clarity',
        'article.style.buyer_decision' => 'Buyer Decision',
        'article.style.editorial_flow' => 'Editorial Flow',
        'article.style.conversational_expert' => 'Conversational Expert',
    ];

    public function test_exactly_seven_canonical_article_skills_are_packaged(): void
    {
        $rawSkills = array_values(array_filter(
            $this->presets(),
            static fn (array $preset): bool => ($preset['type'] ?? null) === 'skill'
        ));
        $skills = $this->skillsByName();

        $this->assertCount(7, $rawSkills, 'Duplicate names must not hide extra packaged Skills.');
        $this->assertCount(7, $skills);
        $this->assertCount(7, array_unique(array_column($rawSkills, 'name')));
        $this->assertSame(
            array_values(array_column(self::CANONICAL_SKILLS, 'name')),
            array_keys($skills)
        );

        foreach ($skills as $skill) {
            $this->assertSame('', (string) ($skill['variables'] ?? ''));
            $this->assertIsArray($skill['legacy_names'] ?? null);
        }
    }

    public function test_each_canonical_skill_has_its_stable_intent_key(): void
    {
        foreach (self::CANONICAL_SKILLS as $intent => $contract) {
            $skill = $this->skillsByName()[$contract['name']] ?? null;

            $this->assertIsArray($skill, $contract['name'].' is missing.');
            $this->assertSame($intent, $skill['intent_key'] ?? null, $contract['name']);
        }
    }

    public function test_master_owns_the_shared_responsibility_contract(): void
    {
        $master = mb_strtolower($this->masterContent(), 'UTF-8');

        foreach ([
            'source priority',
            'verified facts',
            'inference',
            'unknown',
            'privacy',
            'unsupported claims',
            'anti-hype',
            'relationship evidence',
        ] as $requiredBoundary) {
            $this->assertStringContainsString($requiredBoundary, $master);
        }

        $this->assertStringNotContainsString('write entirely in english', $master);
        $this->assertStringNotContainsString('final article must be written entirely in english', $master);
    }

    public function test_master_uses_a_closed_world_rule_for_specific_factual_claims(): void
    {
        $master = mb_strtolower($this->masterContent(), 'UTF-8');

        foreach ([
            'closed-world evidence rule',
            'claim inventory',
            'product-, application-, or project-specific',
            'plausible background knowledge',
            'omit the claim',
        ] as $boundary) {
            $this->assertStringContainsString($boundary, $master);
        }
    }

    public function test_failure_prone_skills_forbid_plausible_but_unverified_detail_completion(): void
    {
        $boundaries = [
            'comparison' => 'a resource constraint does not by itself prove a performance outcome',
            'buying_guide' => 'do not complete a checklist with assumed default requirements',
            'application' => 'do not complete missing process details from general industry knowledge',
            'case_study' => 'model-generated project metrics',
            'definition' => 'do not add illustrative numeric values',
        ];

        foreach ($boundaries as $intent => $boundary) {
            $this->assertStringContainsString(
                $boundary,
                mb_strtolower($this->skillContent($intent), 'UTF-8'),
                $intent
            );
        }
    }

    public function test_all_skills_follow_the_shared_responsibility_contract(): void
    {
        foreach ($this->skillsByName() as $name => $skill) {
            $content = (string) ($skill['content'] ?? '');

            foreach ([
                '[Applies when]',
                '[Do not use when]',
                '[Reasoning approach]',
                '[Evidence requirements]',
                '[Optional modules]',
                '[Failure checks]',
            ] as $section) {
                $this->assertStringContainsString($section, $content, $name.' is missing '.$section);
            }

            $lower = mb_strtolower($content, 'UTF-8');
            $this->assertStringNotContainsString('output only the final article body', $lower, $name);
            $this->assertStringNotContainsString('write entirely in english', $lower, $name);
            $this->assertStringNotContainsString('keyword stuffing', $lower, $name);
        }
    }

    public function test_skills_do_not_contain_body_h1_templates(): void
    {
        $prompts = [self::MASTER_NAME => ['content' => $this->masterContent()]] + $this->skillsByName();

        foreach ($prompts as $name => $skill) {
            $content = (string) ($skill['content'] ?? '');

            $this->assertDoesNotMatchRegularExpression('/^\s*#(?!#)\s+/m', $content, $name);
            $this->assertStringNotContainsString('{{title}}', $content, $name);
        }
    }

    public function test_skills_do_not_use_reserved_unsupported_placeholders(): void
    {
        $prompts = [self::MASTER_NAME => ['content' => $this->masterContent()]] + $this->skillsByName();

        foreach ($prompts as $name => $skill) {
            $content = (string) ($skill['content'] ?? '');

            foreach (['language', 'audience', 'SkillPrompt'] as $placeholder) {
                $this->assertDoesNotMatchRegularExpression(
                    '/{{\s*(?:#if\s+)?'.preg_quote($placeholder, '/').'\s*}}/i',
                    $content,
                    $name
                );
            }
        }
    }

    public function test_v2_candidates_are_safe_for_industry_neutral_distribution(): void
    {
        $privateOrIndustrySpecificTerms = [
            '灌胶',
            '点胶',
            'doming',
            'dispensing',
            'potting',
            'epoxy',
            'polyurethane',
            'resin',
            'chiller',
            'cooling',
            'coating',
            'soldering',
            'curing',
            'robota',
            'sj4060',
            'industrial b2b',
            'automation equipment',
            'engineering procurement',
            'design-for-manufacturing',
            'dfm',
        ];
        $candidates = [self::MASTER_NAME => $this->masterContent()] + array_map(
            static fn (array $skill): string => (string) ($skill['content'] ?? ''),
            $this->skillsByName()
        );

        foreach ($candidates as $name => $content) {
            $searchable = mb_strtolower($name."\n".$content, 'UTF-8');
            foreach ($privateOrIndustrySpecificTerms as $term) {
                $this->assertStringNotContainsString(
                    mb_strtolower($term, 'UTF-8'),
                    $searchable,
                    $name.' contains '.$term
                );
            }
        }
    }

    public function test_master_and_skill_combination_stays_within_size_budget(): void
    {
        $masterWords = $this->wordCount($this->masterContent());
        $this->assertGreaterThanOrEqual(750, $masterWords);
        $this->assertLessThanOrEqual(900, $masterWords);

        foreach (self::CANONICAL_SKILLS as $intent => $contract) {
            $content = (string) ($this->skillsByName()[$contract['name']]['content'] ?? '');
            $skillWords = $this->wordCount($content);

            $this->assertGreaterThanOrEqual($contract['min'], $skillWords, $intent.' is below its contract budget');
            $this->assertLessThanOrEqual($contract['max'], $skillWords, $intent.' exceeds its contract budget');
            $this->assertGreaterThanOrEqual(1000, $masterWords + $skillWords, $intent.' combined prompt is too thin');
            $this->assertLessThanOrEqual(1450, $masterWords + $skillWords, $intent.' combined prompt is too large');
        }
    }

    public function test_v22_prompts_treat_structure_as_content_driven_instead_of_a_heading_template(): void
    {
        $prompts = [self::MASTER_NAME => $this->masterContent()] + array_map(
            static fn (array $skill): string => (string) ($skill['content'] ?? ''),
            $this->skillsByName()
        );

        foreach ($prompts as $name => $content) {
            $lower = mb_strtolower($content, 'UTF-8');

            $this->assertStringNotContainsString('[suggested structure]', $lower, $name);
            $this->assertStringNotContainsString('recommended article structure', $lower, $name);
            $this->assertStringNotContainsString('must include an faq', $lower, $name);
            $this->assertStringNotContainsString('must include a conclusion', $lower, $name);
        }

        $master = mb_strtolower($this->masterContent(), 'UTF-8');
        $this->assertStringContainsString('structure follows the title, evidence shape, and reader decision', $master);
        $this->assertStringContainsString('reasoning sequence is internal', $master);
        $this->assertStringContainsString('normally choose zero, one, or two optional modules', $master);
        $this->assertStringContainsString('a conclusion is not mandatory', $master);
    }

    public function test_only_targeted_prompts_advance_to_v23(): void
    {
        $versions = [
            'article.master.trust_based' => '2.3.1',
            'article.skill.comparison' => '2.3.0',
            'article.skill.application' => '2.3.1',
            'article.skill.case_study' => '2.3.0',
        ];

        foreach ($this->presets() as $preset) {
            $key = (string) ($preset['preset_key'] ?? '');
            if ($key !== 'article.master.trust_based' && ! str_starts_with($key, 'article.skill.')) {
                continue;
            }

            $this->assertSame(
                $versions[$key] ?? '2.2.0',
                $preset['preset_version'] ?? null,
                $key
            );
        }
    }

    public function test_v23_changes_only_the_four_approved_candidate_prompts(): void
    {
        $baseline = require base_path('tests/Fixtures/article-grounding/prompt-v22-baseline-hashes.php');
        $current = collect(app(PromptPresetCatalog::class)->candidate())
            ->whereIn('preset_key', array_keys($baseline))
            ->pluck('content_hash', 'preset_key')
            ->all();
        $changed = [];

        foreach ($baseline as $key => $hash) {
            $this->assertArrayHasKey($key, $current);
            if (! hash_equals($hash, (string) $current[$key])) {
                $changed[] = $key;
            }
        }

        $this->assertSame([
            'article.master.trust_based',
            'article.skill.comparison',
            'article.skill.application',
            'article.skill.case_study',
        ], $changed);
    }

    public function test_v23_targeted_prompts_encode_the_grounding_corrections_without_templates(): void
    {
        $master = mb_strtolower($this->masterContent(), 'UTF-8');
        $comparison = mb_strtolower($this->skillContent('comparison'), 'UTF-8');
        $application = mb_strtolower($this->skillContent('application'), 'UTF-8');
        $caseStudy = mb_strtolower($this->skillContent('case_study'), 'UTF-8');

        $this->assertStringContainsString('if a specific fact is absent from eligible evidence, keep it unknown', $master);
        $this->assertStringContainsString('evidence determines the useful length', $master);
        $this->assertStringContainsString('minimum word count', $master);
        $this->assertStringContainsString('paired support', $comparison);
        $this->assertStringContainsString('mark the unsupported side unknown', $comparison);
        $this->assertStringContainsString('verified operating facts from conditional selection guidance', $application);
        $this->assertStringContainsString('do not claim suitability, capacity, environmental tolerance, or process constraints', $application);
        $this->assertStringContainsString('do not invent numeric examples, ranges, tolerances, thresholds, setpoints, or acceptance values', $application);
        $this->assertStringContainsString('use qualitative wording or a verification question instead', $application);
        $this->assertStringContainsString('stop when the eligible evidence is exhausted', $application);
        $this->assertStringContainsString('do not complete a standard application checklist', $application);
        $this->assertStringContainsString('verified, safely anonymized, and publication-approved', $caseStudy);
        $this->assertStringContainsString('omit unsupported project detail, identity, metrics, and outcome certainty', $caseStudy);

        foreach ([$master, $comparison, $application, $caseStudy] as $content) {
            $this->assertStringNotContainsString('mandatory faq', $content);
            $this->assertStringNotContainsString('follow this heading sequence', $content);
        }
    }

    public function test_four_optional_style_presets_are_packaged_with_strict_responsibility_boundaries(): void
    {
        $styles = collect($this->presets())
            ->where('type', 'style')
            ->keyBy('preset_key');

        $this->assertSame(array_keys(self::CANONICAL_STYLES), $styles->keys()->all());

        foreach (self::CANONICAL_STYLES as $key => $name) {
            $style = $styles->get($key);
            $this->assertIsArray($style, $key);
            $this->assertSame($name, $style['name'] ?? null, $key);
            $this->assertSame('1.0.0', $style['preset_version'] ?? null, $key);
            $this->assertSame('', $style['variables'] ?? null, $key);

            $content = mb_strtolower((string) ($style['content'] ?? ''), 'UTF-8');
            foreach (['[voice]', '[rhythm]', '[paragraphs and transitions]', '[openings and endings]', '[word choice]', '[boundaries]'] as $section) {
                $this->assertStringContainsString($section, $content, $key);
            }
            foreach ([
                'do not add, remove, or strengthen factual claims',
                'do not prescribe headings or mandatory modules',
                'do not imitate a named author',
            ] as $boundary) {
                $this->assertStringContainsString($boundary, $content, $key);
            }
            foreach (['{{title}}', '[suggested structure]', 'must include an faq', 'must include a table'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $content, $key);
            }
        }
    }

    public function test_case_skill_contains_evidence_and_privacy_boundaries(): void
    {
        $case = mb_strtolower($this->skillContent('case_study'), 'UTF-8');

        foreach ([
            'completed case with verified results',
            'implementation case without final results',
            'inquiry or application scenario',
            'after-sales lesson',
            'publication permission',
            'anonymize',
            'customer statement',
            'internal sales assessment',
            'success story',
            'troubleshooting safety boundary',
            'qualified technician',
        ] as $boundary) {
            $this->assertStringContainsString($boundary, $case);
        }
    }

    public function test_troubleshooting_skill_contains_operator_safety_and_escalation_boundaries(): void
    {
        $troubleshooting = mb_strtolower($this->skillContent('troubleshooting'), 'UTF-8');

        foreach ([
            'safe operator checks',
            'qualified technician',
            'lockout',
            'depressurization',
            'safe temperature',
            'ppe',
            'stop troubleshooting',
            'escalate',
            'observation is not permission to perform an action',
            'equipment-specific procedure is not supplied',
        ] as $boundary) {
            $this->assertStringContainsString($boundary, $troubleshooting);
        }
    }

    #[DataProvider('intentBoundaryProvider')]
    public function test_each_skill_has_distinct_application_and_exclusion_rules(
        string $intent,
        string $appliesMarker,
        string $exclusionMarker
    ): void {
        $content = mb_strtolower($this->skillContent($intent), 'UTF-8');

        $this->assertStringContainsString($appliesMarker, $content);
        $this->assertStringContainsString($exclusionMarker, $content);
    }

    /**
     * @return array<string, array{string,string,string}>
     */
    public static function intentBoundaryProvider(): array
    {
        return [
            'comparison' => ['comparison', 'direct alternatives', 'how to choose'],
            'buying guide' => ['buying_guide', 'selection criteria', 'direct comparison is the central question'],
            'application' => ['application', 'process or application requirement', 'verified project result'],
            'technical' => ['technical', 'how or why something works', 'basic definition is the main question'],
            'troubleshooting' => ['troubleshooting', 'symptom, fault, or maintenance problem', 'safe diagnostic evidence is unavailable'],
            'case study' => ['case_study', 'retrievable case evidence', 'no case source is available'],
            'definition' => ['definition', 'orientation, terminology, or basic scope', 'mechanism is the main question'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function skillsByName(): array
    {
        $skills = [];

        foreach ($this->presets() as $preset) {
            if (($preset['type'] ?? null) !== 'skill') {
                continue;
            }

            $skills[(string) ($preset['name'] ?? '')] = $preset;
        }

        $ordered = [];
        foreach (self::CANONICAL_SKILLS as $contract) {
            if (isset($skills[$contract['name']])) {
                $ordered[$contract['name']] = $skills[$contract['name']];
            }
        }

        return $ordered + array_diff_key($skills, $ordered);
    }

    private function masterContent(): string
    {
        foreach ($this->presets() as $preset) {
            if (($preset['type'] ?? null) === 'content' && ($preset['name'] ?? null) === self::MASTER_NAME) {
                return (string) ($preset['content'] ?? '');
            }
        }

        $this->fail('Canonical V2 Master Prompt is not packaged.');
    }

    private function skillContent(string $intent): string
    {
        $contract = self::CANONICAL_SKILLS[$intent];

        return (string) ($this->skillsByName()[$contract['name']]['content'] ?? '');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function presets(): array
    {
        $presets = require database_path('seeders/data/prompt_presets_v2.php');

        return is_array($presets) ? array_values($presets) : [];
    }

    private function wordCount(string $content): int
    {
        preg_match_all("/[A-Za-z0-9]+(?:[-'’][A-Za-z0-9]+)*/u", strip_tags($content), $matches);

        return count($matches[0] ?? []);
    }
}
