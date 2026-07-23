<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PromptSkillContractTest extends TestCase
{
    private const MASTER_NAME = 'GEO Master - Trust-Based Article Generation';

    /**
     * @var array<string, array{name:string,min:int,max:int}>
     */
    private const CANONICAL_SKILLS = [
        'comparison' => ['name' => 'GEO Skill - Comparison', 'min' => 130, 'max' => 240],
        'buying_guide' => ['name' => 'GEO Skill - Buying Guide', 'min' => 130, 'max' => 240],
        'application' => ['name' => 'GEO Skill - Application', 'min' => 150, 'max' => 270],
        'technical' => ['name' => 'GEO Skill - Technical', 'min' => 130, 'max' => 240],
        'troubleshooting' => ['name' => 'GEO Skill - Troubleshooting', 'min' => 190, 'max' => 320],
        'case_study' => ['name' => 'GEO Skill - Case Study', 'min' => 190, 'max' => 320],
        'definition' => ['name' => 'GEO Skill - Definition', 'min' => 120, 'max' => 220],
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
            'eligible evidence',
            'verified fact',
            'attributed statement',
            'inference',
            'unknown',
            'privacy',
            'unsupported',
            'relationship',
            'do not fabricate',
            'safety',
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
            'every product-, application-, or project-specific claim',
            'background knowledge',
            'cannot fill missing',
            'omit it or turn it into a verification question',
        ] as $boundary) {
            $this->assertStringContainsString($boundary, $master);
        }
    }

    public function test_failure_prone_skills_forbid_plausible_but_unverified_detail_completion(): void
    {
        $boundaries = [
            'comparison' => 'paired support',
            'buying_guide' => 'assumed default requirements',
            'application' => 'general industry knowledge',
            'case_study' => 'invent project metrics',
            'definition' => 'invent numeric examples',
        ];

        foreach ($boundaries as $intent => $boundary) {
            $this->assertStringContainsString(
                $boundary,
                mb_strtolower($this->skillContent($intent), 'UTF-8'),
                $intent
            );
        }
    }

    public function test_all_skills_use_a_lean_intent_specific_contract(): void
    {
        foreach ($this->skillsByName() as $name => $skill) {
            $content = (string) ($skill['content'] ?? '');

            foreach ([
                '[Decision goal]',
                '[Reasoning]',
                '[Intent-specific evidence boundary]',
                '[Optional output forms]',
            ] as $section) {
                $this->assertStringContainsString($section, $content, $name.' is missing '.$section);
            }

            $lower = mb_strtolower($content, 'UTF-8');
            foreach ([
                '[applies when]',
                '[do not use when]',
                '[failure checks]',
                'source priority',
                'claim inventory',
                'anti-hype rule',
                'runtime owns',
                'final quality check',
            ] as $repeatedBoilerplate) {
                $this->assertStringNotContainsString($repeatedBoilerplate, $lower, $name);
            }
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
        $this->assertGreaterThanOrEqual(420, $masterWords);
        $this->assertLessThanOrEqual(650, $masterWords);

        foreach (self::CANONICAL_SKILLS as $intent => $contract) {
            $content = (string) ($this->skillsByName()[$contract['name']]['content'] ?? '');
            $skillWords = $this->wordCount($content);

            $this->assertGreaterThanOrEqual($contract['min'], $skillWords, $intent.' is below its contract budget');
            $this->assertLessThanOrEqual($contract['max'], $skillWords, $intent.' exceeds its contract budget');
            $this->assertGreaterThanOrEqual(550, $masterWords + $skillWords, $intent.' combined prompt is too thin');
            $this->assertLessThanOrEqual(950, $masterWords + $skillWords, $intent.' combined prompt is too large');
        }
    }

    public function test_v24_prompts_treat_structure_as_content_driven_instead_of_a_heading_template(): void
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
        $this->assertStringContainsString('structure follows the question, evidence, and reader decision', $master);
        $this->assertStringContainsString('do not turn that reasoning into a visible template', $master);
        $this->assertStringContainsString('optional modules are optional', $master);
        $this->assertStringContainsString('do not add a conclusion', $master);
    }

    public function test_all_article_prompt_candidates_advance_to_the_expected_lean_contract_versions(): void
    {
        foreach ($this->presets() as $preset) {
            $key = (string) ($preset['preset_key'] ?? '');
            if ($key === 'article.master.trust_based' || str_starts_with($key, 'article.skill.')) {
                $expectedVersion = $key === 'article.skill.technical' ? '2.4.1' : '2.4.0';
                $this->assertSame($expectedVersion, $preset['preset_version'] ?? null, $key);
            }
            if (str_starts_with($key, 'article.style.')) {
                $this->assertSame('1.1.0', $preset['preset_version'] ?? null, $key);
            }
        }
    }

    public function test_technical_skill_preserves_architecture_uncertainty(): void
    {
        $technical = mb_strtolower($this->skillContent('technical'), 'UTF-8');

        foreach ([
            'actual architecture',
            'directly supported',
            'conditional design possibility',
            'do not infer',
            'path count',
            'isolation',
            'actuation timing',
            'shared cavity',
            'shutoff method',
            'recirculation',
            'mixing location',
            'illustrative numbers',
        ] as $boundary) {
            $this->assertStringContainsString($boundary, $technical);
        }
    }

    public function test_v24_prompts_preserve_grounding_corrections_without_template_pressure(): void
    {
        $master = mb_strtolower($this->masterContent(), 'UTF-8');
        $comparison = mb_strtolower($this->skillContent('comparison'), 'UTF-8');
        $application = mb_strtolower($this->skillContent('application'), 'UTF-8');
        $caseStudy = mb_strtolower($this->skillContent('case_study'), 'UTF-8');

        $this->assertStringContainsString('if eligible evidence runs out, stop', $master);
        $this->assertStringContainsString('shorter supported answer', $master);
        $this->assertStringContainsString('paired support', $comparison);
        $this->assertStringContainsString('unsupported side unknown', $comparison);
        $this->assertStringContainsString('verified operating fact', $application);
        $this->assertStringContainsString('suitability, capacity, environmental tolerance, or process constraints', $application);
        $this->assertStringContainsString('do not invent numeric examples', $application);
        $this->assertStringContainsString('verified, anonymized, and approved for publication', $caseStudy);
        $this->assertStringContainsString('omit unsupported identity, detail, metric, and outcome certainty', $caseStudy);

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
            $this->assertSame('1.1.0', $style['preset_version'] ?? null, $key);
            $this->assertSame('', $style['variables'] ?? null, $key);

            $content = mb_strtolower((string) ($style['content'] ?? ''), 'UTF-8');
            foreach (['[expression]', '[rhythm]', '[boundaries]'] as $section) {
                $this->assertStringContainsString($section, $content, $key);
            }
            $this->assertGreaterThanOrEqual(70, $this->wordCount($content), $key);
            $this->assertLessThanOrEqual(140, $this->wordCount($content), $key);
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
            'completed case',
            'implementation in progress',
            'inquiry or proposed application',
            'after-sales lesson',
            'publication permission',
            'anonymize',
            'attributed result',
            'verified positive outcome',
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
            'escalate',
            'observation is not permission to act',
        ] as $boundary) {
            $this->assertStringContainsString($boundary, $troubleshooting);
        }
    }

    #[DataProvider('intentBoundaryProvider')]
    public function test_each_skill_has_distinct_reasoning_markers(
        string $intent,
        string $primaryMarker,
        string $evidenceMarker
    ): void {
        $content = mb_strtolower($this->skillContent($intent), 'UTF-8');

        $this->assertStringContainsString($primaryMarker, $content);
        $this->assertStringContainsString($evidenceMarker, $content);
    }

    /**
     * @return array<string, array{string,string,string}>
     */
    public static function intentBoundaryProvider(): array
    {
        return [
            'comparison' => ['comparison', 'direct alternatives', 'paired support'],
            'buying guide' => ['buying_guide', 'selection criteria', 'verification question'],
            'application' => ['application', 'process need', 'verified operating fact'],
            'technical' => ['technical', 'mechanism', 'causal'],
            'troubleshooting' => ['troubleshooting', 'symptom', 'qualified technician'],
            'case study' => ['case_study', 'evidence state', 'publication permission'],
            'definition' => ['definition', 'concept boundary', 'terminology'],
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
