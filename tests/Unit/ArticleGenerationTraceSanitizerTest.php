<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ArticleGenerationTraceSanitizer;
use Tests\TestCase;

class ArticleGenerationTraceSanitizerTest extends TestCase
{
    public function test_legacy_knowledge_trace_is_reduced_to_persistence_safe_metadata(): void
    {
        $trace = [
            'deep_protocol_version' => 'deep-v2.3.1-sparse-evidence-1',
            'task' => ['id' => 8, 'name' => 'Safe task name'],
            'prompt' => [
                'id' => 3,
                'name' => 'Master',
                'type' => 'content',
                'preset_key' => 'article.master.trust_based',
                'preset_version' => '2.3.1',
            ],
            'deep_review' => ['passed' => false, 'score' => 76, 'issue_codes' => ['needs_review']],
            'knowledge' => [
                'strategy' => 'legacy',
                'context' => 'CANARY-FULL-CONTEXT',
                'knowledge_bases' => [['id' => 11, 'name' => 'CANARY-KB-NAME']],
                'chunks' => [[
                    'knowledge_base_id' => 11,
                    'chunk_index' => 2,
                    'knowledge_base_name' => 'CANARY-KB-NAME',
                    'preview' => 'CANARY-CHUNK-PREVIEW',
                    'score_components' => [
                        'vector' => 0.5,
                        'chunkPreview' => 'CANARY-CAMEL-PREVIEW',
                        'entity_name' => 'CANARY-NESTED-ENTITY',
                    ],
                ]],
                'entities' => [['id' => 5, 'name' => 'CANARY-ENTITY-NAME']],
                'cases' => [['id' => 6, 'title' => 'CANARY-CASE-TITLE', 'entity_name' => 'CANARY-ENTITY-NAME']],
                'evidence_package' => [['content' => 'CANARY-EVIDENCE-CONTENT']],
            ],
        ];

        $sanitized = app(ArticleGenerationTraceSanitizer::class)->sanitizeGenerationTrace($trace);
        $encoded = json_encode($sanitized, JSON_THROW_ON_ERROR);

        $this->assertSame('Safe task name', data_get($sanitized, 'task.name'));
        $this->assertSame('deep-v2.3.1-sparse-evidence-1', data_get($sanitized, 'deep_protocol_version'));
        $this->assertSame('article.master.trust_based', data_get($sanitized, 'prompt.preset_key'));
        $this->assertSame('2.3.1', data_get($sanitized, 'prompt.preset_version'));
        $this->assertSame(11, data_get($sanitized, 'knowledge.knowledge_bases.0.id'));
        $this->assertSame(5, data_get($sanitized, 'knowledge.entities.0.id'));
        $this->assertSame(0.5, data_get($sanitized, 'knowledge.chunks.0.score_components.vector'));
        $this->assertSame(76, data_get($sanitized, 'deep_review.score'));
        $this->assertStringNotContainsString('CANARY-', $encoded);
    }

    public function test_claim_ledger_keeps_only_hashes_and_machine_safe_references(): void
    {
        $ledger = app(ArticleGenerationTraceSanitizer::class)->sanitizeClaimLedger([[
            'paragraph_sha256' => str_repeat('a', 64),
            'evidence_refs' => ['KB:12:CHUNK:3:0123456789abcdef', 'PRIVATE-SOURCE'],
            'content' => 'CANARY-CLAIM-CONTENT',
        ]]);

        $this->assertSame([[
            'paragraph_sha256' => str_repeat('a', 64),
            'evidence_refs' => ['KB:12:CHUNK:3:0123456789abcdef'],
        ]], $ledger);
    }

    public function test_generation_trace_rebuilds_claim_provenance_from_safe_fields_only(): void
    {
        $sanitized = app(ArticleGenerationTraceSanitizer::class)->sanitizeGenerationTrace([
            'claimLedger' => [['body' => 'CANARY-TOP-LEVEL-CLAIM']],
            'claim_provenance' => [
                'coverage_status' => 'complete',
                'evidence_sufficiency' => 'limited',
                'claim_ledger' => [[
                    'paragraph_sha256' => str_repeat('b', 64),
                    'evidence_refs' => ['KB:12:CHUNK:3:0123456789abcdef', 'PRIVATE-SOURCE'],
                    'paragraph' => 'CANARY-CLAIM-PARAGRAPH',
                    'evidence' => ['body' => 'CANARY-NESTED-EVIDENCE'],
                ]],
            ],
        ]);

        $this->assertSame('complete', data_get($sanitized, 'claim_provenance.coverage_status'));
        $this->assertSame('limited', data_get($sanitized, 'claim_provenance.evidence_sufficiency'));
        $this->assertSame([[
            'paragraph_sha256' => str_repeat('b', 64),
            'evidence_refs' => ['KB:12:CHUNK:3:0123456789abcdef'],
        ]], data_get($sanitized, 'claim_provenance.claim_ledger'));
        $this->assertArrayNotHasKey('claimLedger', $sanitized);
        $this->assertStringNotContainsString('CANARY-', json_encode($sanitized, JSON_THROW_ON_ERROR));
    }

    public function test_safe_insufficient_evidence_message_survives_error_sanitization(): void
    {
        $message = '深度生成证据不足，已在策划阶段停止。待补资料类型：应用或工艺条件、产品参数';

        $this->assertSame(
            $message,
            app(ArticleGenerationTraceSanitizer::class)->sanitizeErrorMessage($message)
        );
        $this->assertStringNotContainsString(
            'private customer detail',
            app(ArticleGenerationTraceSanitizer::class)->sanitizeErrorMessage(
                $message.' private customer detail'
            )
        );
    }

    public function test_complete_provenance_is_downgraded_when_no_valid_evidence_reference_survives(): void
    {
        $sanitized = app(ArticleGenerationTraceSanitizer::class)->sanitizeGenerationTrace([
            'claim_provenance' => [
                'coverage_status' => 'complete',
                'claim_ledger' => [[
                    'paragraph_sha256' => str_repeat('a', 64),
                    'evidence_refs' => ['PRIVATE-SOURCE', 'CANARY-EVIDENCE'],
                ]],
            ],
        ]);

        $this->assertSame('partial', data_get($sanitized, 'claim_provenance.coverage_status'));
        $this->assertSame([], data_get($sanitized, 'claim_provenance.claim_ledger'));
        $this->assertStringNotContainsString('CANARY-', json_encode($sanitized, JSON_THROW_ON_ERROR));
    }

    public function test_knowledge_trace_whitelisted_fields_are_type_checked_not_only_name_checked(): void
    {
        $sanitized = app(ArticleGenerationTraceSanitizer::class)->sanitizeKnowledgeTrace([
            'query_sha256' => 'CANARY-NOT-A-HASH',
            'collection_id' => '12',
            'cross_collection_mode' => false,
            'strategy' => 'hybrid_vector_lexical',
            'retrieval_engine' => 'CANARY private prose',
            'context_length' => '420',
            'entity_filter_ids' => ['5', 'CANARY-ID'],
            'evidence_summary' => [
                'chunk_count' => '2',
                'average_evidence_score' => '72.5',
                'retrieval_sources' => ['fallback_embedding_hybrid', 'CANARY private source'],
            ],
            'chunks' => [[
                'knowledge_base_id' => '11',
                'chunk_index' => '2',
                'knowledge_type' => 'product_manual',
                'knowledge_role' => 'CANARY private role',
                'score' => '0.75',
                'evidence_score' => '72',
                'retrieval_source' => 'fallback_embedding_hybrid',
                'evidence_id' => 'KB:11:CHUNK:2:0123456789abcdef',
                'content_sha256' => str_repeat('d', 64),
                'source_state' => 'available',
                'publication_scope' => 'internal_reference',
            ]],
            'entities' => [[
                'id' => '5',
                'type' => '产品型号',
                'role' => 'CANARY injected role',
                'linkable' => 1,
            ]],
        ]);

        $this->assertArrayNotHasKey('query_sha256', $sanitized);
        $this->assertArrayNotHasKey('retrieval_engine', $sanitized);
        $this->assertSame(12, $sanitized['collection_id']);
        $this->assertSame([5], $sanitized['entity_filter_ids']);
        $this->assertSame(['fallback_embedding_hybrid'], data_get($sanitized, 'evidence_summary.retrieval_sources'));
        $this->assertSame(11, data_get($sanitized, 'chunks.0.knowledge_base_id'));
        $this->assertArrayNotHasKey('knowledge_role', $sanitized['chunks'][0]);
        $this->assertSame('产品型号', data_get($sanitized, 'entities.0.type'));
        $this->assertNotSame('CANARY injected role', data_get($sanitized, 'entities.0.role'));
        $this->assertStringNotContainsString('CANARY', json_encode($sanitized, JSON_THROW_ON_ERROR));
    }

    public function test_task_run_meta_rejects_sensitive_key_variants_and_structured_errors(): void
    {
        $sanitized = app(ArticleGenerationTraceSanitizer::class)->sanitizeTaskRunMeta([
            'job_type' => 'generate_article',
            'rawEvidence' => 'CANARY-RAW-EVIDENCE',
            'rawContext' => 'CANARY-RAW-CONTEXT',
            'sourceLabel' => 'CANARY-SOURCE-LABEL',
            'claimParagraph' => 'CANARY-CLAIM-PARAGRAPH',
            'apiKey' => 'CANARY-API-KEY',
            'providerError' => 'CANARY-PROVIDER-ERROR',
            'error' => ['message' => 'CANARY-NESTED-ERROR'],
            'notes' => ['CANARY-ARBITRARY-NOTES', 'sk-CANARY-SECRET'],
            0 => 'CANARY-NUMERIC-INDEX',
            'generation_trace' => [
                'claimProvenance' => [
                    'coverage_status' => 'complete',
                    'claimLedger' => [[
                        'paragraph_sha256' => str_repeat('e', 64),
                        'evidence_refs' => ['KB:12:CHUNK:3:0123456789abcdef'],
                        'body' => 'CANARY-CAMEL-CLAIM',
                    ]],
                ],
            ],
        ]);

        $this->assertSame('generate_article', $sanitized['job_type']);
        $this->assertSame('complete', data_get($sanitized, 'generation_trace.claim_provenance.coverage_status'));
        $this->assertSame([[
            'paragraph_sha256' => str_repeat('e', 64),
            'evidence_refs' => ['KB:12:CHUNK:3:0123456789abcdef'],
        ]], data_get($sanitized, 'generation_trace.claim_provenance.claim_ledger'));
        $this->assertStringNotContainsString('CANARY-', json_encode($sanitized, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('notes', $sanitized);
        $this->assertArrayNotHasKey(0, $sanitized);
    }

    public function test_generation_trace_drops_unknown_and_unicode_obfuscated_fields(): void
    {
        $sanitized = app(ArticleGenerationTraceSanitizer::class)->sanitizeGenerationTrace([
            'version' => 1,
            'debug_blob' => 'CANARY-RAW-CONTEXT',
            "r\u{0430}w_evidence" => 'CANARY-UNICODE-EVIDENCE',
            'provider_failure' => 'Authorization: Bearer sk-CANARY-KEY',
            'model_attempts' => [[
                'model_id' => 5,
                'status' => 'failed',
                'reason' => 'provider echoed CANARY-PROMPT and sk-CANARY-KEY',
                'provider_response' => 'CANARY-PROVIDER-RESPONSE',
            ]],
            'deep_review' => [
                'passed' => false,
                'score' => 71,
                'issue_codes' => ['unsupported_claim', 'CANARY private explanation'],
                'metrics' => [
                    'factual_support' => 2,
                    'CANARY private metric' => 5,
                ],
                'provider_comment' => 'CANARY-REVIEW-COMMENT',
            ],
            'knowledge' => [
                'strategy' => 'hybrid_vector_lexical',
                'tag_filters' => ['CANARY-CUSTOMER-TAG'],
                'context_package' => ['used_tags' => ['CANARY-CONTEXT-TAG']],
            ],
            'images' => [[
                'id' => 7,
                'library_id' => 3,
                'original_name' => 'safe-image.png',
                'file_path' => '/private/CANARY-image.png',
                'debug' => 'CANARY-IMAGE-DEBUG',
            ]],
        ]);

        $encoded = json_encode($sanitized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertSame(1, $sanitized['version']);
        $this->assertSame('failed', data_get($sanitized, 'model_attempts.0.status'));
        $this->assertMatchesRegularExpression(
            '/\A任务执行失败（错误指纹：[a-f0-9]{12}）\z/u',
            (string) data_get($sanitized, 'model_attempts.0.reason')
        );
        $this->assertSame(['unsupported_claim'], data_get($sanitized, 'deep_review.issue_codes'));
        $this->assertSame(['factual_support' => 2], data_get($sanitized, 'deep_review.metrics'));
        $this->assertArrayNotHasKey('tag_filters', $sanitized['knowledge']);
        $this->assertArrayNotHasKey('context_package', $sanitized['knowledge']);
        $this->assertSame('safe-image.png', data_get($sanitized, 'images.0.original_name'));
        $this->assertArrayNotHasKey('file_path', $sanitized['images'][0]);
        $this->assertStringNotContainsString('CANARY-', $encoded);
        $this->assertArrayNotHasKey('debug_blob', $sanitized);
        $this->assertArrayNotHasKey("r\u{0430}w_evidence", $sanitized);
    }

    public function test_generation_trace_pipeline_uses_an_exact_recursive_schema(): void
    {
        $sanitized = app(ArticleGenerationTraceSanitizer::class)->sanitizeGenerationTrace([
            'pipeline' => [[
                'name' => 'deep_final_review',
                'status' => 'completed',
                'meta' => [
                    'score' => 82,
                    'passed' => true,
                    'marker_normalization_count' => 1,
                    'issue_codes' => ['needs_review', 'CANARY private issue'],
                    'metrics' => [
                        'clarity' => 4,
                        'CANARY private metric' => 5,
                    ],
                    'provider_comment' => 'CANARY-PIPELINE-COMMENT',
                    0 => 'CANARY-NUMERIC-INDEX',
                ],
                'debug' => 'CANARY-STEP-DEBUG',
            ], [
                'name' => 'CANARY-UNKNOWN-STEP',
                'status' => 'completed',
            ]],
        ]);

        $this->assertSame([[
            'name' => 'deep_final_review',
            'status' => 'completed',
            'meta' => [
                'passed' => true,
                'score' => 82,
                'marker_normalization_count' => 1,
                'issue_codes' => ['needs_review'],
                'metrics' => ['clarity' => 4],
            ],
        ]], $sanitized['pipeline']);
        $this->assertStringNotContainsString('CANARY-', json_encode($sanitized, JSON_THROW_ON_ERROR));
    }

    public function test_malformed_mixed_values_are_dropped_without_php_conversion_errors(): void
    {
        $sanitized = app(ArticleGenerationTraceSanitizer::class)->sanitizeKnowledgeTrace([
            'query_sha256' => ['not', 'scalar'],
            'strategy' => ['not', 'scalar'],
            'collection_id' => '1e3',
            'tag_filters' => [['not', 'scalar']],
            'entity_filter_ids' => ['1.5', '2e3'],
            'chunks' => [[
                'knowledge_base_id' => 4,
                'chunk_index' => 0,
                'score_components' => ['vector' => '1e309', 'metadata' => 0.5],
            ]],
            'entities' => [['id' => 1, 'type' => ['not', 'scalar'], 'evidence_id' => ['not', 'scalar']]],
            'cases' => [['id' => 2, 'type' => ['not', 'scalar'], 'content_sha256' => ['not', 'scalar']]],
        ]);

        $this->assertArrayNotHasKey('query_sha256', $sanitized);
        $this->assertArrayNotHasKey('strategy', $sanitized);
        $this->assertArrayNotHasKey('collection_id', $sanitized);
        $this->assertSame([], $sanitized['entity_filter_ids'] ?? []);
        $this->assertSame([], $sanitized['tag_filters'] ?? []);
        $this->assertArrayNotHasKey('vector', data_get($sanitized, 'chunks.0.score_components'));
        $this->assertSame(0.5, data_get($sanitized, 'chunks.0.score_components.metadata'));
        $this->assertArrayNotHasKey('evidence_id', $sanitized['entities'][0]);
        $this->assertArrayNotHasKey('content_sha256', $sanitized['cases'][0]);
    }

    public function test_oversized_integer_strings_are_rejected_instead_of_collapsing_to_php_int_max(): void
    {
        $sanitized = app(ArticleGenerationTraceSanitizer::class)->sanitizeTaskRunMeta([
            'task_id' => str_repeat('9', 80),
            'attempt_count' => str_repeat('8', 80),
            'max_attempts' => (string) PHP_INT_MAX,
        ]);

        $this->assertArrayNotHasKey('task_id', $sanitized);
        $this->assertArrayNotHasKey('attempt_count', $sanitized);
        $this->assertSame(PHP_INT_MAX, $sanitized['max_attempts']);
    }

    public function test_provider_error_fingerprint_remains_stable_when_persisted(): void
    {
        $providerMessage = 'AI 生成失败：上游模型服务返回异常（错误标识：0123456789ab）';

        $sanitized = app(ArticleGenerationTraceSanitizer::class)->sanitizeErrorMessage($providerMessage);

        $this->assertSame('任务执行失败（错误指纹：0123456789ab）', $sanitized);
    }
}
