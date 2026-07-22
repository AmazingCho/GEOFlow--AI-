<?php

namespace App\Services\GeoFlow;

use App\Support\GeoFlow\CaseTypes;
use App\Support\GeoFlow\EntityTypes;

final class ArticleGenerationTraceSanitizer
{
    private const PIPELINE_STEPS = [
        'select_sources',
        'retrieve_context',
        'resolve_skill',
        'compose_prompt',
        'generate_article',
        'attach_images',
        'prepare_draft',
        'deep_plan',
        'deep_plan_repair',
        'deep_draft',
        'deep_draft_repair',
        'deep_review',
        'deep_revision',
        'deep_revision_repair',
        'deep_final_review',
        'grounding_gate',
    ];

    private const REVIEW_METRICS = [
        'factual_support',
        'clarity',
        'buyer_decision_value',
        'structure_naturalness',
        'uncertainty_and_negative_fit',
        'privacy_and_safety',
        'style_fitness',
        'non_template_naturalness',
    ];

    private const SENSITIVE_KEYS = [
        'content',
        'context',
        'body',
        'claim_ledger',
        'claim_provenance',
        'claim_paragraph',
        'evidence',
        'evidence_content',
        'evidence_package',
        'raw_context',
        'raw_evidence',
        'knowledge_base_name',
        'knowledge_context',
        'label',
        'paragraph',
        'preview',
        'provider_error',
        'query',
        'api_key',
        'raw_prompt',
        'raw_query',
        'source_label',
        'source_name',
        'source_title',
    ];

    /** @param array<string,mixed> $trace @return array<string,mixed> */
    public function sanitizeGenerationTrace(array $trace): array
    {
        $safe = array_filter([
            'version' => $this->positiveInteger($trace['version'] ?? null),
            'deep_protocol_version' => $this->safeIdentifier($trace['deep_protocol_version'] ?? null),
            'generated_at' => $this->dateTime($trace['generated_at'] ?? null),
            'pipeline' => $this->sanitizePipeline($trace['pipeline'] ?? null),
            'task' => $this->sanitizeTraceTask($trace['task'] ?? null),
            'title' => $this->sanitizeTraceTitle($trace['title'] ?? null),
            'author' => $this->sanitizeNamedReference($trace['author'] ?? null),
            'category' => $this->sanitizeNamedReference($trace['category'] ?? null),
            'prompt' => $this->sanitizePromptReference($trace['prompt'] ?? null),
            'skill_prompt' => $this->sanitizePromptReference($trace['skill_prompt'] ?? null),
            'skill_routing' => $this->sanitizeSkillRouting($trace['skill_routing'] ?? null),
            'style_prompt' => $this->sanitizePromptReference($trace['style_prompt'] ?? null),
            'prompt_hashes' => $this->sanitizePromptHashes($trace['prompt_hashes'] ?? null),
            'language' => $this->sanitizeLanguage($trace['language'] ?? null),
            'model' => $this->sanitizeModelReference($trace['model'] ?? null),
            'model_attempts' => $this->sanitizeModelAttempts($trace['model_attempts'] ?? null),
            'deep_review' => $this->sanitizeDeepReview($trace['deep_review'] ?? null),
            'grounding_gate' => $this->sanitizeGroundingGate($trace['grounding_gate'] ?? null),
            'images' => $this->sanitizeImages($trace['images'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');

        if (is_array($trace['knowledge'] ?? null)) {
            $knowledge = $this->sanitizeKnowledgeTrace($trace['knowledge']);
            unset($knowledge['tag_filters'], $knowledge['context_package']);
            if ($knowledge !== []) {
                $safe['knowledge'] = $knowledge;
            }
        }
        $claimProvenance = $this->arrayValueByNormalizedKey($trace, 'claimprovenance');
        if ($claimProvenance !== null) {
            $coverageStatus = $this->machineCode($this->valueByNormalizedKey($claimProvenance, 'coveragestatus'));
            if (! in_array($coverageStatus, ['complete', 'partial', 'not_applicable'], true)) {
                $coverageStatus = null;
            }
            $rawLedger = $this->valueByNormalizedKey($claimProvenance, 'claimledger');
            $claimLedger = $this->sanitizeClaimLedger($rawLedger);
            $evidenceSufficiency = $this->machineCode(
                $this->valueByNormalizedKey($claimProvenance, 'evidencesufficiency')
            );
            if (! in_array($evidenceSufficiency, ['sufficient', 'limited', 'insufficient', 'not_applicable'], true)) {
                $evidenceSufficiency = null;
            }
            if ($coverageStatus === 'complete' && $claimLedger === []) {
                $coverageStatus = 'partial';
            }
            $safe['claim_provenance'] = array_filter([
                'coverage_status' => $coverageStatus,
                'evidence_sufficiency' => $evidenceSufficiency,
                'claim_ledger' => $claimLedger,
            ], static fn (mixed $value, string $key): bool => $value !== null && ($key === 'claim_ledger' || $value !== []), ARRAY_FILTER_USE_BOTH);
        }

        return $safe;
    }

    /** @return list<array<string,mixed>> */
    private function sanitizePipeline(mixed $pipeline): array
    {
        return collect(is_array($pipeline) ? $pipeline : [])
            ->filter(static fn (mixed $step): bool => is_array($step))
            ->map(function (array $step): ?array {
                $name = $this->machineCode($step['name'] ?? null);
                if ($name === null || ! in_array($name, self::PIPELINE_STEPS, true)) {
                    return null;
                }

                $status = $this->machineCode($step['status'] ?? null);
                if (! in_array($status, ['completed', 'pending', 'blocked', 'failed', 'skipped'], true)) {
                    $status = null;
                }

                return array_filter([
                    'name' => $name,
                    'status' => $status,
                    'meta' => $this->sanitizePipelineMeta($step['meta'] ?? null),
                ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
            })
            ->filter()
            ->take(20)
            ->values()
            ->all();
    }

    /** @return array<string,mixed> */
    private function sanitizePipelineMeta(mixed $meta): array
    {
        if (! is_array($meta)) {
            return [];
        }

        $safe = [];
        foreach (['passed', 'blocking', 'has_custom_prompt', 'has_skill_prompt', 'has_style_prompt', 'governance_review_required', 'grounding_review_required'] as $key) {
            $value = $this->booleanValue($meta[$key] ?? null);
            if ($value !== null) {
                $safe[$key] = $value;
            }
        }
        foreach (['score', 'confidence'] as $key) {
            $value = $this->boundedInteger($meta[$key] ?? null, 0, 100);
            if ($value !== null) {
                $safe[$key] = $value;
            }
        }
        foreach (['title_id', 'author_id', 'category_id', 'prompt_id', 'configured_skill_prompt_id', 'style_prompt_id', 'resolved_skill_prompt_id', 'model_id'] as $key) {
            $value = $this->positiveInteger($meta[$key] ?? null);
            if ($value !== null) {
                $safe[$key] = $value;
            }
        }
        foreach ([
            'context_length', 'chunks', 'entities', 'cases', 'prompt_length', 'content_length',
            'attempts', 'image_count', 'excerpt_length', 'section_count', 'open_question_count',
            'attempt_count', 'duration_ms', 'prompt_tokens', 'completion_tokens', 'output_length',
            'reasoning_tokens', 'unmarked_claim_count', 'marker_normalization_count', 'claim_count', 'issue_count',
        ] as $key) {
            $value = $this->nonNegativeInteger($meta[$key] ?? null);
            if ($value !== null) {
                $safe[$key] = $value;
            }
        }
        foreach ([
            'skill_selection_mode', 'strategy', 'mode', 'intent', 'status', 'reason',
            'target_language', 'review_status', 'generation_mode', 'claim_coverage_status',
            'outcome', 'grounding_outcome', 'evidence_sufficiency',
        ] as $key) {
            $value = $this->machineCode($meta[$key] ?? null);
            if ($value !== null) {
                $safe[$key] = $value;
            }
        }
        $ruleVersion = $this->safeIdentifier($meta['rule_version'] ?? null);
        if ($ruleVersion !== null) {
            $safe['rule_version'] = $ruleVersion;
        }
        foreach (['evidence_sha256', 'plan_sha256', 'output_sha256', 'review_sha256', 'error_sha256'] as $key) {
            $value = $this->sha256($meta[$key] ?? null);
            if ($value !== null) {
                $safe[$key] = $value;
            }
        }
        foreach (['deep_issue_codes', 'issue_codes', 'requested_issue_codes'] as $key) {
            $value = $this->machineCodeList($meta[$key] ?? []);
            if ($value !== []) {
                $safe[$key] = $value;
            }
        }

        $metrics = $this->sanitizeReviewMetrics($meta['metrics'] ?? null);
        if ($metrics !== []) {
            $safe['metrics'] = $metrics;
        }

        return $safe;
    }

    /** @return array<string,mixed> */
    private function sanitizeTraceTask(mixed $task): array
    {
        if (! is_array($task)) {
            return [];
        }

        return array_filter([
            'id' => $this->positiveInteger($task['id'] ?? null),
            'name' => $this->displayText($task['name'] ?? null, 240),
            'collection_id' => $this->positiveInteger($task['collection_id'] ?? null),
            'model_selection_mode' => $this->machineCode($task['model_selection_mode'] ?? null),
            'generation_mode' => $this->machineCode($task['generation_mode'] ?? null),
            'skill_selection_mode' => $this->machineCode($task['skill_selection_mode'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @return array<string,mixed> */
    private function sanitizeTraceTitle(mixed $title): array
    {
        if (! is_array($title)) {
            return [];
        }

        return array_filter([
            'id' => $this->positiveInteger($title['id'] ?? null),
            'text' => $this->displayText($title['text'] ?? null, 500),
            'keyword' => $this->displayText($title['keyword'] ?? null, 500),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @return array<string,mixed> */
    private function sanitizeNamedReference(mixed $reference): array
    {
        if (! is_array($reference)) {
            return [];
        }

        return array_filter([
            'id' => $this->positiveInteger($reference['id'] ?? null),
            'name' => $this->displayText($reference['name'] ?? null, 240),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @return array<string,mixed> */
    private function sanitizePromptReference(mixed $reference): array
    {
        $safe = $this->sanitizeNamedReference($reference);
        if (! is_array($reference)) {
            return $safe;
        }

        $type = $this->machineCode($reference['type'] ?? null);
        if ($type !== null) {
            $safe['type'] = $type;
        }
        $presetKey = $this->safeIdentifier($reference['preset_key'] ?? null);
        if ($presetKey !== null) {
            $safe['preset_key'] = $presetKey;
        }
        $presetVersion = $this->safeIdentifier($reference['preset_version'] ?? null);
        if ($presetVersion !== null) {
            $safe['preset_version'] = $presetVersion;
        }

        return $safe;
    }

    /** @return array<string,mixed> */
    private function sanitizeSkillRouting(mixed $routing): array
    {
        if (! is_array($routing)) {
            return [];
        }

        return array_filter([
            'mode' => $this->machineCode($routing['mode'] ?? null),
            'intent' => $this->machineCode($routing['intent'] ?? null),
            'confidence' => $this->boundedInteger($routing['confidence'] ?? null, 0, 100),
            'status' => $this->machineCode($routing['status'] ?? null),
            'reason' => $this->machineCode($routing['reason'] ?? null),
            'resolved_skill_prompt_id' => $this->positiveInteger($routing['resolved_skill_prompt_id'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @return array<string,string> */
    private function sanitizePromptHashes(mixed $hashes): array
    {
        if (! is_array($hashes)) {
            return [];
        }

        return array_filter([
            'master_sha256' => $this->sha256($hashes['master_sha256'] ?? null),
            'skill_sha256' => $this->sha256($hashes['skill_sha256'] ?? null),
            'style_sha256' => $this->sha256($hashes['style_sha256'] ?? null),
        ], static fn (?string $value): bool => $value !== null);
    }

    /** @return array{code?:string} */
    private function sanitizeLanguage(mixed $language): array
    {
        if (! is_array($language)) {
            return [];
        }

        $code = $this->machineCode($language['code'] ?? null);

        return $code !== null ? ['code' => $code] : [];
    }

    /** @return array<string,mixed> */
    private function sanitizeModelReference(mixed $model): array
    {
        if (! is_array($model)) {
            return [];
        }

        return array_filter([
            'id' => $this->positiveInteger($model['id'] ?? null),
            'name' => $this->displayText($model['name'] ?? null, 240),
            'model_id' => $this->safeIdentifier($model['model_id'] ?? null),
            'provider' => $this->safeIdentifier($model['provider'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @return array<string,mixed> */
    private function sanitizeDeepReview(mixed $review): array
    {
        if (! is_array($review)) {
            return [];
        }

        return array_filter([
            'passed' => $this->booleanValue($review['passed'] ?? null),
            'score' => $this->boundedInteger($review['score'] ?? null, 0, 100),
            'issue_codes' => $this->machineCodeList($review['issue_codes'] ?? []),
            'metrics' => $this->sanitizeReviewMetrics($review['metrics'] ?? null),
            'requires_manual_review' => $this->booleanValue($review['requires_manual_review'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /** @return array<string,mixed> */
    public function sanitizeGroundingGate(mixed $gate): array
    {
        if (! is_array($gate)) {
            return [];
        }

        $outcome = $this->machineCode($gate['outcome'] ?? null);
        if (! in_array($outcome, ['pass', 'pending_review', 'blocked'], true)) {
            $outcome = null;
        }

        $issues = collect(is_array($gate['issues'] ?? null) ? $gate['issues'] : [])
            ->filter(static fn (mixed $issue): bool => is_array($issue))
            ->map(function (array $issue): array {
                $severity = $this->machineCode($issue['severity'] ?? null);
                $confidence = $this->boundedInteger($issue['confidence'] ?? null, 0, 100);

                return array_filter([
                    'code' => $this->machineCode($issue['code'] ?? null),
                    'severity' => in_array($severity, ['warning', 'critical'], true) ? $severity : null,
                    'confidence' => $confidence,
                    'excerpt_sha256' => $this->sha256($issue['excerpt_sha256'] ?? null),
                    'evidence_refs' => $this->boundedStringList($issue['evidence_refs'] ?? [], 50, 240),
                ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
            })
            ->filter(static fn (array $issue): bool => isset($issue['code'], $issue['excerpt_sha256']))
            ->take(100)
            ->values()
            ->all();

        return array_filter([
            'rule_version' => $this->safeIdentifier($gate['rule_version'] ?? null),
            'outcome' => $outcome,
            'content_sha256' => $this->sha256($gate['content_sha256'] ?? null),
            'issues' => $issues,
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /** @return array<string,int> */
    private function sanitizeReviewMetrics(mixed $metrics): array
    {
        if (! is_array($metrics)) {
            return [];
        }

        $safe = [];
        foreach (self::REVIEW_METRICS as $key) {
            $value = $this->boundedInteger($metrics[$key] ?? null, 0, 5);
            if ($value !== null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    /** @return list<array<string,mixed>> */
    private function sanitizeImages(mixed $images): array
    {
        return collect(is_array($images) ? $images : [])
            ->filter(static fn (mixed $image): bool => is_array($image))
            ->map(function (array $image): array {
                return array_filter([
                    'id' => $this->positiveInteger($image['id'] ?? null),
                    'library_id' => $this->positiveInteger($image['library_id'] ?? null),
                    'original_name' => $this->safeFilename($image['original_name'] ?? null),
                ], static fn (mixed $value): bool => $value !== null && $value !== '');
            })
            ->filter()
            ->take(100)
            ->values()
            ->all();
    }

    /** @param array<string,mixed> $trace @return array<string,mixed> */
    public function sanitizeKnowledgeTrace(array $trace): array
    {
        $safe = [
            'query_sha256' => $this->sha256($trace['query_sha256'] ?? null),
            'collection_id' => $this->positiveInteger($trace['collection_id'] ?? null),
            'cross_collection_mode' => $this->booleanValue($trace['cross_collection_mode'] ?? null),
            'strategy' => $this->machineCode($trace['strategy'] ?? null),
            'retrieval_engine' => $this->machineCode($trace['retrieval_engine'] ?? null),
            'context_length' => $this->nonNegativeInteger($trace['context_length'] ?? null),
        ];
        foreach (['tag_filters', 'entity_filter_ids', 'case_filter_ids', 'knowledge_base_ids'] as $key) {
            if (is_array($trace[$key] ?? null)) {
                $safe[$key] = $key === 'tag_filters'
                    ? $this->boundedStringList($trace[$key], 100, 180)
                    : $this->positiveIntegerList($trace[$key]);
            }
        }

        if (is_array($trace['evidence_summary'] ?? null)) {
            $safe['evidence_summary'] = $this->sanitizeEvidenceSummary($trace['evidence_summary']);
        }
        $safe['knowledge_bases'] = $this->records($trace['knowledge_bases'] ?? [], [
            'id', 'knowledge_type', 'knowledge_role', 'importance', 'status',
        ]);
        $safe['chunks'] = $this->records($trace['chunks'] ?? [], [
            'knowledge_base_id', 'knowledge_type', 'knowledge_role', 'importance', 'status',
            'chunk_index', 'score', 'evidence_score', 'retrieval_source', 'match_reasons',
            'score_components', 'evidence_id', 'content_sha256', 'source_state', 'publication_scope',
        ]);
        $safe['entities'] = $this->entityRecords($trace['entities'] ?? []);
        $safe['cases'] = $this->caseRecords($trace['cases'] ?? []);
        $safe['evidence_audit'] = $this->records($trace['evidence_audit'] ?? [], [
            'id', 'source_type', 'source_id', 'chunk_index', 'source_state',
            'publication_scope', 'content_sha256', 'source_revision_sha256',
        ]);
        if (is_array($trace['context_package'] ?? null)) {
            $safe['context_package'] = $this->sanitizeContextPackage($trace['context_package']);
        }

        return array_filter($safe, static fn (mixed $value): bool => $value !== [] && $value !== null && $value !== '');
    }

    /** @param array<string,mixed> $package @return array<string,mixed> */
    public function sanitizeContextPackage(array $package): array
    {
        $safe = [
            'selected_collection_id' => $this->positiveInteger($package['selected_collection_id'] ?? null),
            'cross_collection_mode' => $this->booleanValue($package['cross_collection_mode'] ?? null) ?? false,
            'selected_entity_ids' => $this->positiveIntegerList($package['selected_entity_ids'] ?? []),
            'selected_case_ids' => $this->positiveIntegerList($package['selected_case_ids'] ?? []),
            'used_knowledge_base_ids' => $this->positiveIntegerList($package['used_knowledge_base_ids'] ?? []),
            'used_tags' => $this->boundedStringList($package['used_tags'] ?? [], 100, 180),
            'strategy' => $this->machineCode($package['strategy'] ?? null),
            'context_length' => $this->nonNegativeInteger($package['context_length'] ?? null) ?? 0,
        ];
        if (is_array($package['evidence_summary'] ?? null)) {
            $safe['evidence_summary'] = $this->sanitizeEvidenceSummary($package['evidence_summary']);
        }
        $safe['evidence_audit'] = $this->records($package['evidence_audit'] ?? [], [
            'id', 'source_type', 'source_id', 'chunk_index', 'source_state',
            'publication_scope', 'content_sha256', 'source_revision_sha256',
        ]);
        $safe['knowledge_bases'] = $this->records($package['knowledge_bases'] ?? [], [
            'id', 'knowledge_type', 'knowledge_role', 'importance', 'status',
        ]);
        $safe['chunks'] = $this->records($package['chunks'] ?? [], [
            'knowledge_base_id', 'knowledge_type', 'knowledge_role', 'importance', 'status',
            'chunk_index', 'score', 'evidence_score', 'retrieval_source', 'match_reasons',
            'score_components', 'evidence_id', 'content_sha256', 'source_state', 'publication_scope',
        ]);
        $safe['entities'] = $this->entityRecords($package['entities'] ?? []);
        $safe['cases'] = $this->caseRecords($package['cases'] ?? []);

        return array_filter($safe, static fn (mixed $value): bool => $value !== [] && $value !== null && $value !== '');
    }

    /** @param array<string,mixed> $meta @return array<string,mixed> */
    public function sanitizeTaskRunMeta(array $meta): array
    {
        $safe = [];

        foreach (['job_type', 'action', 'model_selection_mode', 'generation_mode', 'safe_mode', 'source', 'dispatch_state', 'generation_outcome', 'protocol_stage', 'failure_class', 'content_block_reason'] as $key) {
            $value = $this->machineCode($meta[$key] ?? null);
            if ($value !== null) {
                $safe[$key] = $value;
            }
        }
        if (in_array(($meta['terminal_reason'] ?? null), ['insufficient_evidence', 'protocol_failure', 'content_blocked', 'provider_failure'], true)) {
            $safe['terminal_reason'] = $meta['terminal_reason'];
        }
        $protocolVersion = $this->safeIdentifier($meta['protocol_version'] ?? null);
        if ($protocolVersion !== null) {
            $safe['protocol_version'] = $protocolVersion;
        }
        foreach (['task_id', 'title_id', 'author_id', 'category_id', 'used_model_id'] as $key) {
            $value = $this->positiveInteger($meta[$key] ?? null);
            if ($value !== null) {
                $safe[$key] = $value;
            }
        }
        foreach (['knowledge_length', 'image_count', 'publish_interval', 'attempt_count', 'max_attempts', 'protocol_violation_count', 'provider_attempt_count'] as $key) {
            $value = $this->nonNegativeInteger($meta[$key] ?? null);
            if ($value !== null) {
                $safe[$key] = $value;
            }
        }
        $legacyClaim = $this->booleanValue($meta['legacy_claim'] ?? null);
        if ($legacyClaim !== null) {
            $safe['legacy_claim'] = $legacyClaim;
        }
        $availableAt = $this->scheduledDateTime($meta['available_at'] ?? null);
        if ($availableAt !== null) {
            $safe['available_at'] = $availableAt;
        }
        $dispatchedAt = $this->scheduledDateTime($meta['dispatched_at'] ?? null);
        if ($dispatchedAt !== null) {
            $safe['dispatched_at'] = $dispatchedAt;
        }
        foreach (['dispatch_token', 'claim_token'] as $key) {
            $token = $this->executionToken($meta[$key] ?? null);
            if ($token !== null) {
                $safe[$key] = $token;
            }
        }
        if (is_scalar($meta['worker_id'] ?? null)) {
            $workerId = trim((string) $meta['worker_id']);
            if (preg_match('/\A[A-Za-z0-9_.:@-]{1,120}\z/', $workerId) === 1) {
                $safe['worker_id'] = $workerId;
            }
        }
        if (is_array($meta['payload'] ?? null)) {
            $safe['payload'] = $this->sanitizeTaskPayload($meta['payload']);
        }
        if (is_array($meta['model_attempts'] ?? null)) {
            $safe['model_attempts'] = $this->sanitizeModelAttempts($meta['model_attempts']);
        }
        if (is_array($meta['generation_trace'] ?? null)) {
            $safe['generation_trace'] = $this->sanitizeGenerationTrace($meta['generation_trace']);
        }
        if (array_key_exists('last_error', $meta)) {
            $lastError = $meta['last_error'];
            $safe['last_error'] = is_scalar($lastError) && trim((string) $lastError) !== ''
                ? $this->sanitizeErrorMessage((string) $lastError)
                : $this->sanitizeErrorMessage('structured_error:'.get_debug_type($lastError));
        }
        if (is_array($meta['missing_information_categories'] ?? null)) {
            $safe['missing_information_categories'] = collect($meta['missing_information_categories'])
                ->filter(static fn (mixed $code): bool => is_string($code)
                    && ArticleInsufficientEvidenceException::isSafeCategoryCode($code))
                ->unique()
                ->take(3)
                ->values()
                ->all();
        }
        if (is_array($meta['protocol_violation_codes'] ?? null)) {
            $safe['protocol_violation_codes'] = collect($meta['protocol_violation_codes'])
                ->filter(static fn (mixed $code): bool => is_string($code)
                    && preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+\z/', $code) === 1)
                ->unique()
                ->take(20)
                ->values()
                ->all();
        }
        if (is_array($meta['protocol_violation_paths'] ?? null)) {
            $safe['protocol_violation_paths'] = collect($meta['protocol_violation_paths'])
                ->filter(static fn (mixed $path): bool => is_string($path)
                    && preg_match('/\A\$(?:\.[a-z_]+|\[\d+\])*\z/', $path) === 1)
                ->unique()
                ->take(20)
                ->values()
                ->all();
        }

        return $safe;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function sanitizeTaskPayload(array $payload): array
    {
        $safe = [];
        foreach (['source', 'safe_mode', 'trigger', 'request_id', 'client_reference'] as $key) {
            $value = $this->machineCode($payload[$key] ?? null);
            if ($value !== null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    /** @return list<array<string,mixed>> */
    private function sanitizeModelAttempts(mixed $attempts): array
    {
        return collect(is_array($attempts) ? $attempts : [])
            ->filter(static fn (mixed $attempt): bool => is_array($attempt))
            ->map(function (array $attempt): array {
                $safe = array_filter([
                    'stage' => $this->machineCode($attempt['stage'] ?? null),
                    'model_id' => $this->positiveInteger($attempt['model_id'] ?? null),
                    'status' => $this->machineCode($attempt['status'] ?? null),
                    'duration_ms' => $this->nonNegativeInteger($attempt['duration_ms'] ?? null),
                    'finish_reason' => $this->machineCode($attempt['finish_reason'] ?? null),
                    'prompt_tokens' => $this->nonNegativeInteger($attempt['prompt_tokens'] ?? null),
                    'completion_tokens' => $this->nonNegativeInteger($attempt['completion_tokens'] ?? null),
                    'reasoning_tokens' => $this->nonNegativeInteger($attempt['reasoning_tokens'] ?? null),
                ], static fn (mixed $value): bool => $value !== null && $value !== '');

                if (array_key_exists('reason', $attempt) && $attempt['reason'] !== null && $attempt['reason'] !== '') {
                    $safe['reason'] = is_scalar($attempt['reason'])
                        ? $this->sanitizeErrorMessage((string) $attempt['reason'])
                        : $this->sanitizeErrorMessage('structured_error:'.get_debug_type($attempt['reason']));
                }

                return $safe;
            })
            ->take(20)
            ->values()
            ->all();
    }

    /** @return list<array{paragraph_sha256:string,evidence_refs:list<string>}> */
    public function sanitizeClaimLedger(mixed $ledger): array
    {
        if (! is_array($ledger)) {
            return [];
        }

        return collect($ledger)
            ->filter(static fn (mixed $entry): bool => is_array($entry))
            ->map(function (array $entry): ?array {
                $rawParagraphHash = $entry['paragraph_sha256'] ?? null;
                if (! is_scalar($rawParagraphHash)) {
                    return null;
                }
                $paragraphHash = strtolower(trim((string) $rawParagraphHash));
                if (preg_match('/\A[a-f0-9]{64}\z/', $paragraphHash) !== 1) {
                    return null;
                }

                $references = collect(is_array($entry['evidence_refs'] ?? null) ? $entry['evidence_refs'] : [])
                    ->filter(static fn (mixed $reference): bool => is_scalar($reference))
                    ->map(static fn (mixed $reference): string => trim((string) $reference))
                    ->filter(fn (string $reference): bool => $this->isEvidenceId($reference))
                    ->unique()
                    ->values()
                    ->all();

                if ($references === []) {
                    return null;
                }

                return ['paragraph_sha256' => $paragraphHash, 'evidence_refs' => $references];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function sanitizeSerializedMeta(mixed $meta): mixed
    {
        if (is_array($meta)) {
            return $this->sanitizeTaskRunMeta($meta);
        }
        if (! is_string($meta) || trim($meta) === '') {
            return $meta;
        }

        $decoded = json_decode($meta, true);
        if (! is_array($decoded)) {
            return '';
        }

        return json_encode($this->sanitizeTaskRunMeta($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public function sanitizeErrorMessage(string $message): string
    {
        if (ArticleInsufficientEvidenceException::isSafePublicMessage($message)) {
            return $message;
        }
        if (preg_match('/\A任务执行失败（错误指纹：[a-f0-9]{12}）\z/u', $message) === 1) {
            return $message;
        }
        if (preg_match('/错误标识：([a-f0-9]{12})/u', $message, $matches) === 1) {
            return '任务执行失败（错误指纹：'.$matches[1].'）';
        }
        $fingerprint = substr(hash('sha256', $message), 0, 12);

        return '任务执行失败（错误指纹：'.$fingerprint.'）';
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function removeSensitiveValues(array $value): array
    {
        $safe = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                continue;
            }
            if (is_string($key) && $this->isErrorKey($key)) {
                $safe[$key] = is_scalar($item) && trim((string) $item) !== ''
                    ? $this->sanitizeErrorMessage((string) $item)
                    : $this->sanitizeErrorMessage('structured_error:'.get_debug_type($item));

                continue;
            }
            $safe[$key] = is_array($item) ? $this->removeSensitiveValues($item) : $item;
        }

        return $safe;
    }

    /** @param array<string,mixed> $value @param list<string> $keys @return array<string,mixed> */
    private function only(array $value, array $keys): array
    {
        return array_intersect_key($value, array_fill_keys($keys, true));
    }

    /** @param mixed $records @param list<string> $keys @return list<array<string,mixed>> */
    private function records(mixed $records, array $keys): array
    {
        if (! is_array($records)) {
            return [];
        }

        return collect($records)
            ->filter(static fn (mixed $record): bool => is_array($record))
            ->map(function (array $record) use ($keys): array {
                $safe = $this->removeSensitiveValues($this->only($record, $keys));
                foreach (array_keys($safe) as $key) {
                    $value = $safe[$key];
                    if (in_array($key, ['id', 'knowledge_base_id', 'source_id', 'entity_id'], true)) {
                        $safe[$key] = $this->positiveInteger($value);
                    } elseif ($key === 'chunk_index') {
                        $safe[$key] = $this->nonNegativeInteger($value);
                    } elseif ($key === 'importance') {
                        $importance = $this->positiveInteger($value);
                        $safe[$key] = $importance !== null ? min(5, $importance) : null;
                    } elseif (in_array($key, ['score', 'evidence_score'], true)) {
                        $safe[$key] = $this->finiteNumber($value);
                    } elseif (in_array($key, ['knowledge_type', 'knowledge_role', 'status', 'retrieval_source', 'source_state', 'publication_scope', 'source_type'], true)) {
                        $safe[$key] = $this->machineCode($value);
                    } elseif (in_array($key, ['content_sha256', 'source_revision_sha256'], true)) {
                        $safe[$key] = $this->sha256($value);
                    } elseif ($key === 'evidence_id') {
                        $safe[$key] = $this->isEvidenceId($value) ? (string) $value : null;
                    }
                }
                if (is_array($safe['score_components'] ?? null)) {
                    $safe['score_components'] = collect($this->only($safe['score_components'], ['vector', 'lexical', 'metadata']))
                        ->map(fn (mixed $value): int|float|null => $this->finiteNumber($value))
                        ->filter(static fn (mixed $value): bool => $value !== null)
                        ->all();
                }
                if (is_array($safe['match_reasons'] ?? null)) {
                    $safe['match_reasons'] = $this->machineCodeList($safe['match_reasons']);
                }

                return array_filter($safe, static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
            })
            ->values()
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function entityRecords(mixed $records): array
    {
        return collect(is_array($records) ? $records : [])
            ->filter(static fn (mixed $record): bool => is_array($record))
            ->map(function (array $record): array {
                $type = is_scalar($record['type'] ?? null) ? trim((string) $record['type']) : '';
                $type = EntityTypes::isControlled($type) ? $type : EntityTypes::GENERAL;

                return array_filter([
                    'id' => $this->positiveInteger($record['id'] ?? null),
                    'type' => $type,
                    'role' => EntityTypes::roleDescription($type),
                    'linkable' => $this->booleanValue($record['linkable'] ?? null),
                    'evidence_id' => $this->isEvidenceId($record['evidence_id'] ?? null) ? (string) $record['evidence_id'] : null,
                    'content_sha256' => $this->sha256($record['content_sha256'] ?? null),
                    'source_state' => $this->machineCode($record['source_state'] ?? null),
                    'publication_scope' => $this->machineCode($record['publication_scope'] ?? null),
                ], static fn (mixed $value): bool => $value !== null && $value !== '');
            })
            ->values()
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function caseRecords(mixed $records): array
    {
        return collect(is_array($records) ? $records : [])
            ->filter(static fn (mixed $record): bool => is_array($record))
            ->map(function (array $record): array {
                $type = CaseTypes::normalize(is_scalar($record['type'] ?? null) ? (string) $record['type'] : '');

                return array_filter([
                    'id' => $this->positiveInteger($record['id'] ?? null),
                    'type' => $type,
                    'role' => CaseTypes::referenceRule($type),
                    'entity_id' => $this->positiveInteger($record['entity_id'] ?? null),
                    'evidence_id' => $this->isEvidenceId($record['evidence_id'] ?? null) ? (string) $record['evidence_id'] : null,
                    'content_sha256' => $this->sha256($record['content_sha256'] ?? null),
                    'source_state' => $this->machineCode($record['source_state'] ?? null),
                    'publication_scope' => $this->machineCode($record['publication_scope'] ?? null),
                ], static fn (mixed $value): bool => $value !== null && $value !== '');
            })
            ->values()
            ->all();
    }

    /** @param array<string,mixed> $summary @return array<string,mixed> */
    private function sanitizeEvidenceSummary(array $summary): array
    {
        return array_filter([
            'chunk_count' => $this->nonNegativeInteger($summary['chunk_count'] ?? null),
            'average_evidence_score' => $this->finiteNumber($summary['average_evidence_score'] ?? null),
            'retrieval_sources' => $this->machineCodeList($summary['retrieval_sources'] ?? []),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $key));
        $exact = array_map(
            static fn (string $item): string => strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $item)),
            self::SENSITIVE_KEYS
        );

        return in_array($normalized, $exact, true)
            || preg_match('/preview$/i', $key) === 1
            || preg_match('/(?:apikey|accesstoken|authtoken|authorization|credential|password|secret)/', $normalized) === 1
            || preg_match('/raw(?:evidence|context|prompt|query|response|content|text)/', $normalized) === 1
            || preg_match('/source(?:label|name|title)/', $normalized) === 1
            || preg_match('/claim(?:paragraph|body|text|content)/', $normalized) === 1
            || preg_match('/provider(?:error|request|response)/', $normalized) === 1
            || (str_contains($normalized, 'evidence') && preg_match('/(?:content|text|package|label|name|source)/', $normalized) === 1);
    }

    private function isErrorKey(string $key): bool
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $key));

        return in_array($normalized, ['error', 'lasterror', 'errormessage', 'lasterrormessage'], true);
    }

    private function isEvidenceId(mixed $value): bool
    {
        return is_scalar($value)
            && preg_match('/\A(?:KB:\d+:(?:CHUNK:\d+|FULL):[a-f0-9]{16}|ENTITY:\d+:[a-f0-9]{16}|CASE:\d+:[a-f0-9]{16})\z/', (string) $value) === 1;
    }

    private function positiveInteger(mixed $value): ?int
    {
        $integer = $this->strictInteger($value, false);

        return $integer !== null && $integer > 0 ? $integer : null;
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        $integer = $this->strictInteger($value, false);

        return $integer !== null && $integer >= 0 ? $integer : null;
    }

    private function boundedInteger(mixed $value, int $minimum, int $maximum): ?int
    {
        $integer = $this->strictInteger($value, true);

        return $integer !== null && $integer >= $minimum && $integer <= $maximum ? $integer : null;
    }

    private function strictInteger(mixed $value, bool $allowNegative): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (! is_string($value) || preg_match($allowNegative ? '/\A-?\d+\z/' : '/\A\d+\z/', $value) !== 1) {
            return null;
        }

        $negative = str_starts_with($value, '-');
        $digits = ltrim($negative ? substr($value, 1) : $value, '0');
        $digits = $digits === '' ? '0' : $digits;
        $limit = $negative ? ltrim((string) PHP_INT_MIN, '-') : (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            return null;
        }

        return (int) (($negative ? '-' : '').$digits);
    }

    private function finiteNumber(mixed $value): int|float|null
    {
        if (! is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        if (! is_finite($number)) {
            return null;
        }

        return floor($number) === $number ? (int) $number : $number;
    }

    private function booleanValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return (bool) $value;
        }

        return null;
    }

    private function sha256(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $hash = strtolower(trim((string) $value));

        return preg_match('/\A[a-f0-9]{64}\z/', $hash) === 1 ? $hash : null;
    }

    /** @return list<int> */
    private function positiveIntegerList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(fn (mixed $item): ?int => $this->positiveInteger($item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function boundedStringList(mixed $value, int $maxItems, int $maxLength): array
    {
        return collect(is_array($value) ? $value : [])
            ->filter(static fn (mixed $item): bool => is_scalar($item))
            ->map(static fn (mixed $item): string => mb_substr(trim((string) $item), 0, $maxLength, 'UTF-8'))
            ->filter()
            ->unique()
            ->take($maxItems)
            ->values()
            ->all();
    }

    private function machineCode(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $code = strtolower(trim((string) $value));

        return preg_match('/\A[a-z0-9_:-]{1,80}\z/', $code) === 1 ? $code : null;
    }

    private function executionToken(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $token = strtolower(trim((string) $value));

        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $token) === 1
            ? $token
            : null;
    }

    private function safeIdentifier(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $identifier = trim((string) $value);

        return preg_match('/\A[A-Za-z0-9._:@\/-]{1,160}\z/', $identifier) === 1 ? $identifier : null;
    }

    private function displayText(mixed $value, int $maximumLength): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '' || mb_check_encoding($text, 'UTF-8') === false) {
            return null;
        }
        $text = preg_replace('/\p{C}+/u', '', $text);
        if (! is_string($text) || $text === '') {
            return null;
        }

        return mb_substr($text, 0, $maximumLength, 'UTF-8');
    }

    private function safeFilename(mixed $value): ?string
    {
        $filename = $this->displayText($value, 255);
        if ($filename === null) {
            return null;
        }
        $parts = preg_split('/[\\\\\/]+/', $filename);
        $basename = is_array($parts) ? end($parts) : false;

        return is_string($basename) && $basename !== '' && ! in_array($basename, ['.', '..'], true)
            ? $basename
            : null;
    }

    private function dateTime(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $dateTime = trim((string) $value);
        if (preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $dateTime) !== 1) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $dateTime);

        return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d H:i:s') === $dateTime
            ? $dateTime
            : null;
    }

    private function scheduledDateTime(mixed $value): ?string
    {
        $dateTime = $this->dateTime($value);
        if ($dateTime !== null || ! is_scalar($value)) {
            return $dateTime;
        }

        $isoDateTime = trim((string) $value);
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/', $isoDateTime) !== 1) {
            return null;
        }

        try {
            $parsed = new \DateTimeImmutable($isoDateTime);
            $timezone = new \DateTimeZone((string) config('app.timezone', 'UTC'));

            return $parsed->setTimezone($timezone)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private function machineCodeList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(fn (mixed $item): ?string => $this->machineCode($item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string,mixed> $value */
    private function valueByNormalizedKey(array $value, string $expected): mixed
    {
        foreach ($value as $key => $item) {
            if (is_string($key) && strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $key)) === $expected) {
                return $item;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $value @return array<string,mixed>|null */
    private function arrayValueByNormalizedKey(array $value, string $expected): ?array
    {
        $item = $this->valueByNormalizedKey($value, $expected);

        return is_array($item) ? $item : null;
    }
}
