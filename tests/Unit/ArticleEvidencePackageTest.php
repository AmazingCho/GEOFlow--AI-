<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ArticleEvidenceMarkerException;
use App\Services\GeoFlow\ArticleEvidencePackage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ArticleEvidencePackageTest extends TestCase
{
    public function test_evidence_ids_are_source_specific_and_change_with_the_source_revision(): void
    {
        $service = app(ArticleEvidencePackage::class);

        $sources = [
            ['knowledge_chunk', 12, 3, 'KB:12:CHUNK:3:'],
            ['knowledge_full', 12, null, 'KB:12:FULL:'],
            ['entity', 7, null, 'ENTITY:7:'],
            ['case', 9, null, 'CASE:9:'],
        ];

        foreach ($sources as [$sourceType, $sourceId, $chunkIndex, $prefix]) {
            $first = $service->make(
                sourceType: $sourceType,
                sourceId: $sourceId,
                label: 'Private source label',
                content: 'Revision one',
                chunkIndex: $chunkIndex
            );
            $second = $service->make(
                sourceType: $sourceType,
                sourceId: $sourceId,
                label: 'Private source label',
                content: 'Revision two',
                chunkIndex: $chunkIndex
            );

            $this->assertStringStartsWith($prefix, $first['id']);
            $this->assertNotSame($first['id'], $second['id']);
        }
    }

    public function test_audit_metadata_never_contains_evidence_content_or_labels(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make(
            sourceType: 'case',
            sourceId: 19,
            label: 'CANARY-CUSTOMER-NAME',
            content: 'CANARY-PRIVATE-EVIDENCE-TEXT',
            sourceState: 'unverified',
            publicationScope: 'unknown'
        );

        $auditJson = json_encode($service->audit([$item]), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('CANARY-CUSTOMER-NAME', $auditJson);
        $this->assertStringNotContainsString('CANARY-PRIVATE-EVIDENCE-TEXT', $auditJson);
        $this->assertStringContainsString($item['id'], $auditJson);
        $this->assertStringContainsString(hash('sha256', 'CANARY-PRIVATE-EVIDENCE-TEXT'), $auditJson);
    }

    public function test_case_evidence_is_always_conservative_and_never_allowlisted(): void
    {
        $service = app(ArticleEvidencePackage::class);

        $case = $service->make(
            sourceType: 'case',
            sourceId: 21,
            label: 'Customer result',
            content: 'The customer reported a measurable result.',
            sourceState: 'available',
            publicationScope: 'internal_reference'
        );

        $this->assertSame('unverified', $case['source_state']);
        $this->assertSame('unknown', $case['publication_scope']);
        $this->assertSame([], $service->ids([$case]));
    }

    #[DataProvider('invalidPackageProvider')]
    public function test_generation_package_requires_strict_canonical_field_types(array $mutations): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make('knowledge_chunk', 12, 'Manual', 'Verified content.', 3);
        foreach ($mutations as $field => $value) {
            $item[$field] = $value;
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('结构化证据包');

        $service->assertGenerationReady([$item]);
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function invalidPackageProvider(): array
    {
        return [
            'fractional chunk index' => [['chunk_index' => '1.5']],
            'scientific chunk index' => [['chunk_index' => '1e2']],
            'negative chunk index' => [['chunk_index' => -1]],
            'string source id' => [['source_id' => '12']],
            'non string label' => [['label' => ['Manual']]],
            'non string content' => [['content' => ['Verified content.']]],
        ];
    }

    public function test_non_chunk_source_cannot_carry_a_chunk_index(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make('entity', 7, 'M2', 'M2 product record.');
        $item['chunk_index'] = 0;

        $this->expectException(InvalidArgumentException::class);

        $service->assertGenerationReady([$item]);
    }

    public function test_source_revision_can_change_id_when_rendered_excerpt_is_unchanged(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $first = $service->make(
            'entity',
            7,
            'Entity',
            'Same bounded excerpt',
            revisionContent: 'Same bounded excerpt plus source tail A'
        );
        $second = $service->make(
            'entity',
            7,
            'Entity',
            'Same bounded excerpt',
            revisionContent: 'Same bounded excerpt plus source tail B'
        );

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame($first['content_sha256'], $second['content_sha256']);
        $this->assertNotSame($first['source_revision_sha256'], $second['source_revision_sha256']);
    }

    public function test_valid_markers_are_stripped_and_recorded_as_hash_only_claim_ledger(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make('knowledge_chunk', 12, 'SJ4060 manual', 'The rated travel is 300 mm.', 3);
        $article = "## Verified specification\n\nThe rated travel is 300 mm.\n<!-- evidence:{$item['id']} -->";

        $result = $service->validateAndStripMarkers($article, [$item], ['SJ4060']);

        $this->assertSame('complete', $result['coverage_status']);
        $this->assertStringNotContainsString('<!-- evidence:', $result['content']);
        $this->assertCount(1, $result['claim_ledger']);
        $this->assertSame([$item['id']], $result['claim_ledger'][0]['evidence_refs']);
        $this->assertArrayHasKey('paragraph_sha256', $result['claim_ledger'][0]);
        $this->assertArrayNotHasKey('paragraph', $result['claim_ledger'][0]);
    }

    public function test_short_model_names_and_common_capability_verbs_require_markers(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $entity = $service->make('entity', 7, 'M2', 'M2 uses titanium seals and provides dual-channel control.');

        $result = $service->validateAndStripMarkers(
            "## M2 uses titanium seals\n\nThe selected unit provides dual-channel control.",
            [$entity]
        );

        $this->assertSame('partial', $result['coverage_status']);
        $this->assertSame(2, $result['unmarked_claim_count']);
    }

    public function test_low_information_verbs_and_model_prefixes_do_not_create_false_claims(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $entity = $service->make('entity', 7, 'M2', 'M2 product record.');
        $article = implode("\n\n", [
            'The buyer has questions.',
            'This guide includes a checklist.',
            'The example uses a generic workflow.',
            'The table provides a summary.',
            'M20 uses a different naming convention.',
        ]);

        $result = $service->validateAndStripMarkers($article, [$entity]);

        $this->assertSame('not_applicable', $result['coverage_status']);
        $this->assertSame(0, $result['unmarked_claim_count']);
    }

    public function test_marker_is_local_to_the_immediately_preceding_paragraph_not_its_heading(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $entity = $service->make('entity', 7, 'M2', 'M2 uses titanium seals.');

        $result = $service->validateAndStripMarkers(
            "## M2 performance\nM2 uses titanium seals.\n<!-- evidence:{$entity['id']} -->",
            [$entity]
        );

        $this->assertSame('partial', $result['coverage_status']);
        $this->assertSame(1, $result['unmarked_claim_count']);
        $this->assertCount(1, $result['claim_ledger']);
        $this->assertSame([$entity['id']], $result['claim_ledger'][0]['evidence_refs']);
    }

    public function test_isolated_marker_without_a_preceding_claim_unit_is_rejected(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make('knowledge_chunk', 12, 'Manual', 'Verified content.', 3);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('格式无效');

        $service->validateAndStripMarkers("<!-- evidence:{$item['id']} -->\n\nGeneral introduction.", [$item]);
    }

    public function test_restricted_or_unverified_sources_cannot_authorize_a_published_claim(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $safeItem = $service->make('knowledge_chunk', 12, 'Approved manual', 'Approved selection guidance.', 3);

        foreach ([
            $service->make('knowledge_full', 18, 'Inactive manual', 'Private specification 41 mm.', sourceState: 'restricted', publicationScope: 'restricted'),
            $service->make('case', 19, 'Customer case', 'Private customer outcome 22%.', sourceState: 'unverified', publicationScope: 'unknown'),
        ] as $item) {
            try {
                $service->validateAndStripMarkers("A specific claim is 41 mm.\n<!-- evidence:{$item['id']} -->", [$safeItem, $item]);
                $this->fail('Ineligible evidence must not authorize a claim marker.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('未知或格式无效', $exception->getMessage());
                $this->assertStringNotContainsString((string) $item['id'], $exception->getMessage());
            }
        }
    }

    #[DataProvider('protectedEvidenceContentProvider')]
    public function test_restricted_or_unverified_evidence_content_cannot_reappear_in_output(array $protectedItem): void
    {
        $service = app(ArticleEvidencePackage::class);
        $safeItem = $service->make('knowledge_chunk', 12, 'Approved manual', 'Approved selection guidance.', 3);

        try {
            $service->validateAndStripMarkers(
                (string) $protectedItem['content'],
                [$safeItem, $protectedItem]
            );
            $this->fail('Protected evidence content must not be allowed into generated output.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('受限证据', $exception->getMessage());
            $this->assertStringNotContainsString((string) $protectedItem['content'], $exception->getMessage());
        }
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function protectedEvidenceContentProvider(): array
    {
        $service = new ArticleEvidencePackage;

        return [
            'restricted knowledge' => [[
                ...$service->make(
                    'knowledge_full',
                    18,
                    'Inactive manual',
                    'RESTRICTED_KNOWLEDGE_CANARY_DO_NOT_PERSIST',
                    sourceState: 'restricted',
                    publicationScope: 'restricted'
                ),
            ]],
            'unverified case' => [[
                ...$service->make(
                    'case',
                    19,
                    'Private customer case',
                    'CASE_CANARY_DO_NOT_PERSIST_ALPHA'
                ),
            ]],
        ];
    }

    public function test_marker_validation_requires_a_generation_ready_package(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('结构化证据包');

        app(ArticleEvidencePackage::class)->validateAndStripMarkers('General introduction.', []);
    }

    public function test_protected_evidence_comparison_ignores_markdown_and_zero_width_obfuscation(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $safeItem = $service->make('knowledge_chunk', 12, 'Approved manual', 'Approved selection guidance.', 3);
        $restrictedItem = $service->make(
            'knowledge_full',
            18,
            'Inactive private record',
            'Private customer result increased by 12 percent.',
            sourceState: 'restricted',
            publicationScope: 'restricted'
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('受限证据');

        $service->validateAndStripMarkers(
            "Private customer **result** increased by 12\u{200B} percent.",
            [$safeItem, $restrictedItem]
        );
    }

    #[DataProvider('protectedEvidenceRenderingVariantProvider')]
    public function test_protected_evidence_comparison_rejects_rendering_and_unicode_variants(
        string $protectedContent,
        string $draft
    ): void {
        $service = app(ArticleEvidencePackage::class);
        $safeItem = $service->make('knowledge_chunk', 12, 'Approved manual', 'Approved selection guidance.', 3);
        $restrictedItem = $service->make(
            'knowledge_full',
            18,
            'Inactive private record',
            $protectedContent,
            sourceState: 'restricted',
            publicationScope: 'restricted'
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('受限证据');

        $service->validateAndStripMarkers(
            $draft."\n<!-- evidence:{$safeItem['id']} -->",
            [$safeItem, $restrictedItem]
        );
    }

    /** @return array<string,array{string,string}> */
    public static function protectedEvidenceRenderingVariantProvider(): array
    {
        return [
            'Markdown link destination' => [
                'Private customer result increased by 12 percent.',
                'Private customer [result](https://example.invalid) increased by 12 percent.',
            ],
            'Unicode compatibility width' => [
                'Private customer result increased by 12 percent.',
                'Ｐｒｉｖａｔｅ ｃｕｓｔｏｍｅｒ ｒｅｓｕｌｔ ｉｎｃｒｅａｓｅｄ ｂｙ １２ ｐｅｒｃｅｎｔ．',
            ],
            'short protected metric' => [
                'Client X: 42%.',
                'Client X: 42%.',
            ],
            'field label removed' => [
                '摘要：Client X confidential contact is abc@example.com.',
                'Client X confidential contact is abc@example.com.',
            ],
        ];
    }

    public function test_unknown_marker_is_rejected_without_echoing_the_reference_or_article(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make('knowledge_chunk', 12, 'Manual', 'Known evidence', 3);
        $unknown = 'KB:999:CHUNK:1:deadbeefdeadbeef';

        try {
            $service->validateAndStripMarkers("Private claim 85%.\n<!-- evidence:{$unknown} -->", [$item]);
            $this->fail('Unknown evidence reference should have been rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringNotContainsString($unknown, $exception->getMessage());
            $this->assertStringNotContainsString('Private claim', $exception->getMessage());
        }
    }

    public function test_unmarked_specific_claim_makes_coverage_partial(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make('knowledge_chunk', 12, 'Manual', 'The measured reduction is 18%.', 3);

        $result = $service->validateAndStripMarkers(
            'Testing recorded an 18% reduction in defects.',
            [$item]
        );

        $this->assertSame('partial', $result['coverage_status']);
        $this->assertSame(1, $result['unmarked_claim_count']);
        $this->assertSame([], $result['claim_ledger']);
    }

    public function test_marker_stripping_preserves_markdown_paragraph_spacing(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make('knowledge_chunk', 12, 'Manual', 'Verified 300 mm travel.', 3);
        $article = "First paragraph is 300 mm.\n<!-- evidence:{$item['id']} -->\n\nSecond paragraph remains separate.";

        $result = $service->validateAndStripMarkers($article, [$item]);

        $this->assertStringContainsString("300 mm.\n\nSecond paragraph", $result['content']);
    }

    public function test_marker_may_follow_its_claim_after_one_standard_markdown_blank_line(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make('knowledge_chunk', 12, 'Manual', 'Verified 300 mm travel.', 3);

        $result = $service->validateAndStripMarkers(
            "The rated travel is 300 mm.\n\n<!-- evidence:{$item['id']} -->",
            [$item]
        );

        $this->assertSame('complete', $result['coverage_status']);
        $this->assertSame([$item['id']], $result['claim_ledger'][0]['evidence_refs']);
    }

    public function test_marker_cannot_cross_more_than_one_blank_line_to_authorize_an_earlier_claim(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make('knowledge_chunk', 12, 'Manual', 'Verified 300 mm travel.', 3);

        $this->expectException(ArticleEvidenceMarkerException::class);
        $this->expectExceptionMessage('格式无效');

        $service->validateAndStripMarkers(
            "The rated travel is 300 mm.\n\n\n<!-- evidence:{$item['id']} -->",
            [$item]
        );
    }

    public function test_fullwidth_marker_punctuation_is_normalized_only_when_the_reference_resolves_exactly(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make('knowledge_chunk', 12, 'Manual', 'Verified 300 mm travel.', 3);

        $result = $service->validateAndStripMarkers(
            "The rated travel is 300 mm.\n<!-- evidence：{$item['id']} -->",
            [$item]
        );

        $this->assertSame('The rated travel is 300 mm.', $result['content']);
        $this->assertSame([$item['id']], $result['claim_ledger'][0]['evidence_refs']);
        $this->assertSame('complete', $result['coverage_status']);
        $this->assertSame(1, $result['marker_normalization_count']);
    }

    public function test_missing_marker_colon_is_normalized_locally_when_the_reference_resolves_exactly(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make('knowledge_chunk', 12, 'Manual', 'Verified evidence.', 3);

        $result = $service->validateAndStripMarkers(
            "Specific claim.\n<!-- evidence {$item['id']} -->",
            [$item]
        );

        $this->assertSame('Specific claim.', $result['content']);
        $this->assertSame([$item['id']], $result['claim_ledger'][0]['evidence_refs']);
        $this->assertSame(1, $result['marker_normalization_count']);
    }

    public function test_marker_with_known_id_and_extra_token_is_rejected_instead_of_normalized(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make('knowledge_chunk', 12, 'Manual', 'Verified evidence.', 3);

        foreach ([
            "<!-- evidence {$item['id']} GARBAGE -->",
            "<!-- evidence:{$item['id']}X -->",
            '<!-- evidence:'.strtolower($item['id']).' -->',
            "<!-- evidence:{$item['id']},KB:12:CHUNK:3:deadbeef -->",
        ] as $marker) {
            try {
                $service->validateAndStripMarkers('Specific claim. '.$marker, [$item]);
                $this->fail('Marker with an inexact evidence reference should be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('格式无效', $exception->getMessage());
                $this->assertStringNotContainsString('GARBAGE', $exception->getMessage());
            }
        }
    }

    public function test_evidence_prefixed_marker_variants_are_rejected(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make('knowledge_chunk', 12, 'Manual', 'Verified evidence.', 3);

        foreach ([
            '<!-- evidence_label:PRIVATE-SOURCE -->',
            '<!-- evidences:PRIVATE-SOURCE -->',
            "<!--\u{200B} evidence:PRIVATE-SOURCE -->",
            "<!-- evi\u{200B}dence:PRIVATE-SOURCE -->",
            "<!-- evi\u{2061}dence:PRIVATE-SOURCE -->",
            "<!-- evi\u{180E}dence:PRIVATE-SOURCE -->",
            "<!-- evi\u{00AD}dence:PRIVATE-SOURCE -->",
        ] as $marker) {
            try {
                $service->validateAndStripMarkers('Specific claim. '.$marker, [$item]);
                $this->fail('Evidence-prefixed marker variant should be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('格式无效', $exception->getMessage());
                $this->assertStringNotContainsString('PRIVATE-SOURCE', $exception->getMessage());
            }
        }
    }

    public function test_zero_width_character_inside_a_valid_evidence_id_is_rejected_before_ledger_creation(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make('knowledge_chunk', 12, 'Manual', 'Verified evidence.', 3);
        $hiddenReference = str_replace('CHUNK', "CHU\u{200B}NK", $item['id']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('格式无效');

        $service->validateAndStripMarkers(
            "Specific claim. <!-- evidence:{$hiddenReference} -->",
            [$item]
        );
    }

    public function test_inline_marker_stripping_preserves_word_spacing(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make('knowledge_chunk', 12, 'Manual', 'Verified evidence.', 3);

        $result = $service->validateAndStripMarkers(
            "Alpha <!-- evidence:{$item['id']} --> continues.",
            [$item]
        );

        $this->assertSame('Alpha continues.', $result['content']);
    }

    public function test_unmarked_compact_chinese_unit_and_selected_model_name_are_specific_claims(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make('knowledge_chunk', 7, 'SJ4060 product manual', 'SJ4060 行程为 300mm。', 0);

        $unitResult = $service->validateAndStripMarkers('该设备行程300mm。', [$item]);
        $nameResult = $service->validateAndStripMarkers('SJ4060适合该工艺。', [$item]);

        $this->assertSame('partial', $unitResult['coverage_status']);
        $this->assertSame('partial', $nameResult['coverage_status']);
    }

    public function test_unmarked_common_industrial_units_require_review(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make('knowledge_chunk', 7, 'Process data', 'Verified process data.', 0);

        foreach (['0.5 mL', '25 µm', '80 psi', '120 N'] as $measurement) {
            $result = $service->validateAndStripMarkers("The measured value is {$measurement}.", [$item]);

            $this->assertSame('partial', $result['coverage_status'], $measurement);
        }
    }

    public function test_unmarked_spelled_out_and_chinese_units_require_review(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make('knowledge_chunk', 7, 'Process data', 'Verified process data.', 0);

        foreach (['300 millimeters', '40 percent', '300毫米', '40摄氏度'] as $measurement) {
            $result = $service->validateAndStripMarkers("The measured value is {$measurement}.", [$item]);

            $this->assertSame('partial', $result['coverage_status'], $measurement);
        }
    }

    public function test_unmarked_capability_claim_keeps_coverage_partial_even_when_another_paragraph_is_marked(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make('knowledge_chunk', 12, 'Manual', 'Verified selection context.', 3);
        $article = "Verified selection context.\n<!-- evidence:{$item['id']} -->\n\nRobota processes abrasive resin.";

        $result = $service->validateAndStripMarkers($article, [$item]);

        $this->assertSame('partial', $result['coverage_status']);
        $this->assertSame(1, $result['unmarked_claim_count']);
        $this->assertCount(1, $result['claim_ledger']);
    }

    public function test_unmarked_modal_capability_claim_cannot_be_hidden_by_a_marked_paragraph(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make('knowledge_chunk', 12, 'Manual', 'Verified selection context.', 3);
        $article = "Verified selection context.\n<!-- evidence:{$item['id']} -->\n\n"
            .'The machine can mix two-part resin.';

        $result = $service->validateAndStripMarkers($article, [$item]);

        $this->assertSame('partial', $result['coverage_status']);
        $this->assertSame(1, $result['unmarked_claim_count']);
        $this->assertCount(1, $result['claim_ledger']);
    }

    #[DataProvider('structuredMarkerBoundaryProvider')]
    public function test_marker_cannot_authorize_an_earlier_structural_claim_unit(string $article): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make('knowledge_chunk', 12, 'Manual', 'Verified selection guidance.', 3);
        $article = str_replace('{ID}', $item['id'], $article);

        $result = $service->validateAndStripMarkers($article, [$item]);

        $this->assertSame('partial', $result['coverage_status']);
        $this->assertSame(1, $result['unmarked_claim_count']);
        $this->assertCount(1, $result['claim_ledger']);
    }

    /** @return array<string,array{string}> */
    public static function structuredMarkerBoundaryProvider(): array
    {
        return [
            'markdown list items' => [
                "- The machine uses a dual-pump architecture.\n- General selection guidance. <!-- evidence:{ID} -->",
            ],
            'markdown table rows' => [
                "| Item | Statement |\n| --- | --- |\n| A | The machine uses a dual-pump architecture. |\n| B | General selection guidance. | <!-- evidence:{ID} -->",
            ],
            'separate blockquote paragraphs' => [
                "> The machine uses a dual-pump architecture.\n>\n> General selection guidance. <!-- evidence:{ID} -->",
            ],
            'html paragraphs' => [
                "<p>The machine uses a dual-pump architecture.</p>\n<p>General selection guidance.</p><!-- evidence:{ID} -->",
            ],
            'nested markdown list items' => [
                "> - The machine uses a dual-pump architecture.\n> - General selection guidance. <!-- evidence:{ID} -->",
            ],
            'blockquote markdown table rows' => [
                "> | Item | Statement |\n> | --- | --- |\n> | A | The machine uses a dual-pump architecture. |\n> | B | General selection guidance. | <!-- evidence:{ID} -->",
            ],
            'compressed html paragraphs' => [
                '<p>The machine uses a dual-pump architecture.</p><p>General selection guidance.</p><!-- evidence:{ID} -->',
            ],
            'compressed html list items' => [
                '<ul><li>The machine uses a dual-pump architecture.</li><li>General selection guidance.</li></ul><!-- evidence:{ID} -->',
            ],
        ];
    }

    #[DataProvider('ordinaryProductSubjectCapabilityProvider')]
    public function test_unmarked_capability_claim_with_an_ordinary_product_subject_requires_review(string $claim): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make('knowledge_chunk', 12, 'Manual', 'Verified selection context.', 3);

        $result = $service->validateAndStripMarkers($claim, [$item]);

        $this->assertSame('partial', $result['coverage_status'], $claim);
        $this->assertSame(1, $result['unmarked_claim_count'], $claim);
    }

    /** @return array<string,array{string}> */
    public static function ordinaryProductSubjectCapabilityProvider(): array
    {
        return [
            'machine uses' => ['The machine uses a dual-pump architecture.'],
            'system includes' => ['The system includes automatic calibration.'],
            'device has' => ['The device has a closed-loop controller.'],
            'equipment features' => ['The equipment features automatic pressure control.'],
            'unit operates' => ['The unit operates with two independent channels.'],
            'Chinese machine uses' => ['这台机器采用双泵架构。'],
            'Chinese system includes' => ['该系统包含自动校准功能。'],
            'indefinite machine uses' => ['A machine uses a dual-pump architecture.'],
            'each system includes' => ['Each system includes automatic calibration.'],
            'modified device has' => ['A modern device has a closed-loop controller.'],
            'plural machines feature' => ['These machines feature automatic pressure control.'],
            'compound system operates' => ['A dispensing system operates with two independent channels.'],
            'modal machine capability' => ['The machine can mix two-part resin.'],
            'formatted modal capability' => ['The machine can **mix** two-part resin.'],
            'modal plural capability' => ['These systems can process abrasive material.'],
            'Chinese demonstrative equipment' => ['这款设备采用双泵架构。'],
            'Chinese modal capability' => ['该设备可以混合双组份树脂。'],
            'Chinese possessive system' => ['我们的系统包含自动校准功能。'],
            'Chinese indefinite machine' => ['一台机器配备闭环控制器。'],
        ];
    }

    public function test_lowercase_model_capability_synonym_is_still_a_specific_claim(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make('knowledge_chunk', 12, 'Manual', 'Verified selection context.', 3);

        $result = $service->validateAndStripMarkers('our mx-200 accommodates abrasive resin.', [$item]);

        $this->assertSame('partial', $result['coverage_status']);
        $this->assertSame(1, $result['unmarked_claim_count']);
    }

    public function test_specific_claim_in_a_heading_requires_provenance_even_when_body_has_no_claim(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make('knowledge_chunk', 12, 'MX-200 manual', 'The verified travel is 300 mm.', 3);

        $result = $service->validateAndStripMarkers(
            '## MX-200 supports 300 mm travel',
            [$item]
        );

        $this->assertSame('partial', $result['coverage_status']);
        $this->assertSame(1, $result['unmarked_claim_count']);
    }

    public function test_unclosed_evidence_comment_is_rejected_before_content_can_be_persisted(): void
    {
        $service = app(ArticleEvidencePackage::class);
        $item = $service->make('knowledge_chunk', 12, 'Manual', 'Verified evidence.', 3);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('格式无效');

        $service->validateAndStripMarkers(
            "Specific claim. <!-- evidence:{$item['id']}",
            [$item]
        );
    }
}
