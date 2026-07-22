<?php

namespace App\Services\GeoFlow;

use InvalidArgumentException;
use JsonException;

class ArticleDeepOutputValidator
{
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

    private const SUPPORT_TYPES = ['evidence', 'general_explanation'];

    private const EVIDENCE_SUFFICIENCY = ['sufficient', 'limited', 'insufficient'];

    private const ANSWER_MODES = ['direct', 'conditional', 'evidence_limited', 'stop'];

    private const VERIFICATION_CATEGORIES = [
        'specification',
        'compatibility',
        'process',
        'integration',
        'safety',
        'commercial',
        'case_evidence',
        'other',
    ];

    private const BLOCKING_ISSUE_CODES = [
        'privacy_violation',
        'unsafe_operational_guidance',
        'dangerous_instruction',
        'fabricated_evidence',
        'unsupported_high_risk_claim',
        'prompt_injection_leak',
    ];

    /** @return array<string,mixed> */
    public function validatePlan(array|string $output, ?array $allowedEvidenceIds = null): array
    {
        $plan = $this->decodeObject($output, '策划结果');
        $allowedEvidenceLookup = array_fill_keys(
            $this->stringList($allowedEvidenceIds ?? [], 500, 240),
            true
        );
        $violations = $this->collectPlanViolations($plan, $allowedEvidenceLookup);
        if ($violations !== []) {
            throw new ArticlePlanValidationException($violations);
        }
        $evidenceSufficiency = strtolower(trim((string) ($plan['evidence_sufficiency'] ?? '')));
        $answerMode = strtolower(trim((string) ($plan['answer_mode'] ?? '')));
        $supportedSections = array_map(fn (array $section): array => [
            'purpose' => mb_substr(trim((string) $section['purpose']), 0, 1200, 'UTF-8'),
            'support_type' => trim((string) $section['support_type']),
            'evidence_refs' => $this->stringList($section['evidence_refs'], 50, 240),
        ], array_values($plan['supported_sections']));
        $evidenceMapping = array_map(fn (array $mapping): array => [
            'claim_scope' => mb_substr(trim((string) $mapping['claim_scope']), 0, 1000, 'UTF-8'),
            'evidence_refs' => $this->stringList($mapping['evidence_refs'], 50, 240),
        ], array_values($plan['evidence_mapping']));
        $verificationItems = array_map(static fn (array $item): array => [
            'question' => mb_substr(trim((string) $item['question']), 0, 1000, 'UTF-8'),
            'category' => strtolower(trim((string) $item['category'])),
            'required_for_draft' => $item['required_for_draft'],
        ], array_values($plan['verification_items']));

        return [
            'reader_question' => mb_substr(trim((string) $plan['reader_question']), 0, 2000, 'UTF-8'),
            'answer_mode' => $answerMode,
            'evidence_sufficiency' => $evidenceSufficiency,
            'supported_sections' => $supportedSections,
            'evidence_mapping' => $evidenceMapping,
            'optional_modules' => $this->stringList($plan['optional_modules'], 20, 500),
            'unsupported_claims_to_avoid' => $this->stringList($plan['unsupported_claims_to_avoid'] ?? [], 50, 1000),
            'verification_items' => $verificationItems,
        ];
    }

    /** @return array<string,mixed> */
    public function validateReview(string $output): array
    {
        $review = $this->decodeObject($output, '审核结果');
        if (! is_bool($review['passed'] ?? null)) {
            throw new InvalidArgumentException('审核结果 passed 必须是布尔值');
        }
        if (! is_numeric($review['score'] ?? null)) {
            throw new InvalidArgumentException('审核结果 score 必须是数字');
        }

        $score = max(0, min(100, (int) round((float) $review['score'])));
        $issues = [];
        foreach (is_array($review['issues'] ?? null) ? array_values($review['issues']) : [] as $issue) {
            if (! is_array($issue)) {
                continue;
            }
            $code = $this->normalizeCode((string) ($issue['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $severity = strtolower(trim((string) ($issue['severity'] ?? 'medium')));
            $issues[] = [
                'code' => $code,
                'severity' => in_array($severity, ['low', 'medium', 'high', 'critical'], true) ? $severity : 'medium',
                'message' => mb_substr(trim((string) ($issue['message'] ?? '')), 0, 1500, 'UTF-8'),
            ];
        }

        $issueCodes = $this->stringList($review['issue_codes'] ?? [], 50, 120);
        $issueCodes = collect(array_merge($issueCodes, array_column($issues, 'code')))
            ->map(fn (string $code): string => $this->normalizeCode($code))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $revisionInstructions = [];
        foreach (is_array($review['revision_instructions'] ?? null) ? array_values($review['revision_instructions']) : [] as $instruction) {
            if (! is_array($instruction)) {
                continue;
            }
            $target = trim((string) ($instruction['target'] ?? ''));
            $text = trim((string) ($instruction['instruction'] ?? ''));
            if ($target !== '' && $text !== '') {
                $revisionInstructions[] = [
                    'target' => mb_substr($target, 0, 500, 'UTF-8'),
                    'instruction' => mb_substr($text, 0, 2000, 'UTF-8'),
                ];
            }
        }

        $metricKeys = is_array($review['metrics'] ?? null) ? array_keys($review['metrics']) : [];
        $expectedMetricKeys = self::REVIEW_METRICS;
        sort($metricKeys);
        sort($expectedMetricKeys);
        if ($metricKeys !== $expectedMetricKeys) {
            throw new InvalidArgumentException('审核结果 metrics 必须完整包含八项既定指标');
        }
        $metrics = [];
        foreach (self::REVIEW_METRICS as $key) {
            $value = $review['metrics'][$key] ?? null;
            if (! is_int($value) || $value < 1 || $value > 5) {
                throw new InvalidArgumentException('审核结果 metrics 必须是 1-5 的整数');
            }
            $metrics[$key] = $value;
        }

        return [
            'passed' => (bool) $review['passed']
                && $score >= 80
                && $issueCodes === []
                && $metrics['factual_support'] >= 4
                && $metrics['privacy_and_safety'] >= 4,
            'score' => $score,
            'issue_codes' => $issueCodes,
            'issues' => $issues,
            'revision_instructions' => $revisionInstructions,
            'metrics' => $metrics,
        ];
    }

    /** @param array<string,mixed> $review */
    public function hasBlockingIssues(array $review): bool
    {
        if (array_intersect(self::BLOCKING_ISSUE_CODES, $review['issue_codes'] ?? []) !== []) {
            return true;
        }

        return collect($review['issues'] ?? [])->contains(
            static fn (mixed $issue): bool => is_array($issue) && ($issue['severity'] ?? '') === 'critical'
        );
    }

    /** @return array<string,mixed> */
    private function decodeObject(array|string $output, string $label): array
    {
        if (is_array($output)) {
            if (array_is_list($output)) {
                throw new InvalidArgumentException($label.'必须是 JSON 对象');
            }

            return $output;
        }

        $json = trim($output);
        if (preg_match('/\A```(?:json)?\s*(.*?)\s*```\z/isu', $json, $matches) === 1) {
            $json = trim((string) ($matches[1] ?? ''));
        }

        if ($json === '') {
            throw new InvalidArgumentException($label.'为空');
        }

        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException($label.'不是有效 JSON', 0, $exception);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException($label.'必须是 JSON 对象');
        }

        return $decoded;
    }

    /** @return list<string> */
    private function stringList(mixed $value, int $maxItems, int $maxLength): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(static fn (mixed $item): string => mb_substr(trim((string) $item), 0, $maxLength, 'UTF-8'))
            ->filter()
            ->unique()
            ->take($maxItems)
            ->values()
            ->all();
    }

    private function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = preg_replace('/[^a-z0-9_]+/', '_', $code) ?? '';

        return trim($code, '_');
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,bool>  $allowedEvidenceLookup
     * @return list<array{code:string,path:string,expected:string}>
     */
    private function collectPlanViolations(array $plan, array $allowedEvidenceLookup): array
    {
        $violations = [];
        $add = static function (array &$items, string $code, string $path, string $expected): void {
            $items[] = compact('code', 'path', 'expected');
        };

        if (trim((string) ($plan['reader_question'] ?? '')) === '') {
            $add($violations, 'schema.required', '$.reader_question', 'non-empty string');
        }

        $sufficiency = strtolower(trim((string) ($plan['evidence_sufficiency'] ?? '')));
        if (! in_array($sufficiency, self::EVIDENCE_SUFFICIENCY, true)) {
            $add($violations, 'schema.invalid_enum', '$.evidence_sufficiency', 'sufficient|limited|insufficient');
        }
        $answerMode = strtolower(trim((string) ($plan['answer_mode'] ?? '')));
        if (! in_array($answerMode, self::ANSWER_MODES, true)) {
            $add($violations, 'schema.invalid_enum', '$.answer_mode', 'direct|conditional|evidence_limited|stop');
        }

        $sections = $plan['supported_sections'] ?? null;
        if (! is_array($sections)) {
            $add($violations, 'schema.invalid_type', '$.supported_sections', 'array');
            $sections = [];
        }
        foreach (array_values($sections) as $sectionIndex => $section) {
            $path = '$.supported_sections['.$sectionIndex.']';
            if (! is_array($section)) {
                $add($violations, 'schema.invalid_type', $path, 'object');

                continue;
            }
            if (trim((string) ($section['purpose'] ?? '')) === '') {
                $add($violations, 'schema.required', $path.'.purpose', 'non-empty string');
            }
            $supportType = trim((string) ($section['support_type'] ?? ''));
            if (! in_array($supportType, self::SUPPORT_TYPES, true)) {
                $add($violations, 'schema.invalid_enum', $path.'.support_type', 'evidence|general_explanation');
            }
            if ($supportType === 'general_explanation' && $this->containsSpecificClaim((string) ($section['purpose'] ?? ''))) {
                $add(
                    $violations,
                    'content.specific_claim_forbidden',
                    $path.'.purpose',
                    'general_explanation must not contain product-specific facts, measurements, or performance claims'
                );
            }
            if (! is_array($section['evidence_refs'] ?? null)) {
                $add($violations, 'schema.invalid_type', $path.'.evidence_refs', 'array');
            }
            $refs = is_array($section['evidence_refs'] ?? null) ? array_values($section['evidence_refs']) : [];
            if ($supportType === 'evidence' && $refs === []) {
                $add($violations, 'evidence.reference_required', $path.'.evidence_refs', 'at least one exact allowlisted evidence ID');
            }
            if ($supportType === 'general_explanation' && $refs !== []) {
                $add($violations, 'evidence.reference_forbidden', $path.'.evidence_refs', 'general_explanation requires an empty array');
            }
            foreach ($refs as $refIndex => $reference) {
                if (! is_string($reference) || ! isset($allowedEvidenceLookup[$reference])) {
                    $add($violations, 'evidence.unknown_reference', $path.'.evidence_refs['.$refIndex.']', 'exact allowlisted evidence ID');
                }
            }
        }

        $optionalModules = $plan['optional_modules'] ?? null;
        if (! is_array($optionalModules)) {
            $add($violations, 'schema.invalid_type', '$.optional_modules', 'array');
            $optionalModules = [];
        }
        foreach (array_values($optionalModules) as $moduleIndex => $module) {
            $path = '$.optional_modules['.$moduleIndex.']';
            if (! is_string($module) || trim($module) === '') {
                $add($violations, 'schema.invalid_type', $path, 'non-empty string');

                continue;
            }
            if ($this->containsSpecificClaim($module)) {
                $add(
                    $violations,
                    'content.specific_claim_forbidden',
                    $path,
                    'optional module names must not contain product-specific facts, measurements, or performance claims'
                );
            }
        }

        $unsupportedClaims = $plan['unsupported_claims_to_avoid'] ?? null;
        if (! is_array($unsupportedClaims)) {
            $add($violations, 'schema.invalid_type', '$.unsupported_claims_to_avoid', 'array');
        } else {
            foreach (array_values($unsupportedClaims) as $claimIndex => $claim) {
                if (! is_string($claim) || trim($claim) === '') {
                    $add($violations, 'schema.invalid_type', '$.unsupported_claims_to_avoid['.$claimIndex.']', 'non-empty string');
                }
            }
        }

        $mappings = $plan['evidence_mapping'] ?? null;
        if (! is_array($mappings)) {
            $add($violations, 'schema.invalid_type', '$.evidence_mapping', 'array');
            $mappings = [];
        }
        foreach (array_values($mappings) as $mappingIndex => $mapping) {
            $path = '$.evidence_mapping['.$mappingIndex.']';
            if (! is_array($mapping)) {
                $add($violations, 'schema.invalid_type', $path, 'object');

                continue;
            }
            if (trim((string) ($mapping['claim_scope'] ?? '')) === '') {
                $add($violations, 'schema.required', $path.'.claim_scope', 'non-empty string');
            }
            if (! is_array($mapping['evidence_refs'] ?? null)) {
                $add($violations, 'schema.invalid_type', $path.'.evidence_refs', 'array');
            }
            $refs = is_array($mapping['evidence_refs'] ?? null) ? array_values($mapping['evidence_refs']) : [];
            if ($refs === []) {
                $add($violations, 'evidence.reference_required', $path.'.evidence_refs', 'at least one exact allowlisted evidence ID');
            }
            foreach ($refs as $refIndex => $reference) {
                if (! is_string($reference) || ! isset($allowedEvidenceLookup[$reference])) {
                    $add($violations, 'evidence.unknown_reference', $path.'.evidence_refs['.$refIndex.']', 'exact allowlisted evidence ID');
                }
            }
        }

        $verificationItems = $plan['verification_items'] ?? null;
        if (! is_array($verificationItems)) {
            $add($violations, 'schema.invalid_type', '$.verification_items', 'array');
            $verificationItems = [];
        }
        foreach (array_values($verificationItems) as $itemIndex => $item) {
            $path = '$.verification_items['.$itemIndex.']';
            if (! is_array($item)) {
                $add($violations, 'schema.invalid_type', $path, 'object');

                continue;
            }
            if (trim((string) ($item['question'] ?? '')) === '') {
                $add($violations, 'schema.required', $path.'.question', 'non-empty string');
            }
            if (! in_array(strtolower(trim((string) ($item['category'] ?? ''))), self::VERIFICATION_CATEGORIES, true)) {
                $add($violations, 'schema.invalid_enum', $path.'.category', implode('|', self::VERIFICATION_CATEGORIES));
            }
            if (! is_bool($item['required_for_draft'] ?? null)) {
                $add($violations, 'schema.invalid_type', $path.'.required_for_draft', 'boolean');
            }
        }

        if ($sufficiency === 'sufficient' && ($sections === [] || $mappings === [] || $answerMode === 'stop')) {
            $add($violations, 'contract.inconsistent_state', '$', 'sufficient plan with supported sections, evidence mapping, and non-stop answer mode');
        }
        if ($sufficiency === 'limited' && ($sections === [] || $mappings === [] || ! in_array($answerMode, ['conditional', 'evidence_limited'], true))) {
            $add($violations, 'contract.inconsistent_state', '$', 'limited plan with supported content and conditional|evidence_limited answer mode');
        }
        if ($sufficiency === 'insufficient') {
            $hasRequiredItem = collect($verificationItems)->contains(
                static fn (mixed $item): bool => is_array($item) && ($item['required_for_draft'] ?? null) === true
            );
            if ($answerMode !== 'stop' || ! $hasRequiredItem) {
                $add($violations, 'contract.inconsistent_state', '$.verification_items', 'insufficient plan with answer_mode=stop and a draft-blocking verification item');
            }
        }

        return $violations;
    }

    private function containsSpecificClaim(string $text): bool
    {
        $text = $this->normalizeRenderedText($text);

        if (preg_match('/(?<![\p{N}])\d+(?:[.,]\d+)?\s*(?:%|mm|cm|m|µm|um|nm|l|ml|µl|ul|kg|g|mg|kw|w|v|a|n|kn|bar|psi|pa|kpa|mpa|rpm|hz|°c|°f|hours?|days?|minutes?|seconds?|pcs?|units?)(?![\p{L}\p{N}_])/iu', $text) === 1) {
            return true;
        }
        if (preg_match('/\b(?:guarantees?|achieved|reduced|decreased|increased|improved|customer\s+result)\b|(?:保证|达到|提升|降低|减少|增加|客户结果)/iu', $text) === 1) {
            return true;
        }

        $capabilityVerbs = '(?:is|are|has|have|uses?|provides?|supports?|delivers?|includes?|features?|requires?|achieves?|operates?|dispenses?|process(?:es)?|handles?|accepts?|accommodates?|works?|(?:can|could|may)\s+[a-z][a-z-]*)';
        if (preg_match('/\b(?:(?:the|this|that|these|those|selected|our|a|an|each|every|any)\s+)?(?:[a-z][a-z0-9-]*\s+){0,3}(?:units?|models?|products?|machines?|systems?|equipment|devices?)\b.{0,80}\b(?i:'.$capabilityVerbs.')\b/iu', $text) === 1) {
            return true;
        }
        if (preg_match('/\b[A-Z][A-Za-z0-9_-]{2,}(?:\s+[A-Z][A-Za-z0-9_-]{2,}){0,3}\s+(?i:'.$capabilityVerbs.')\b/u', $text) === 1) {
            return true;
        }
        if (preg_match('/(?<![A-Za-z0-9_-])(?:our\s+)?(?=[A-Za-z0-9_-]*[A-Za-z])(?=[A-Za-z0-9_-]*\d)[A-Za-z0-9_-]{2,}(?![A-Za-z0-9_-])\s+(?i:'.$capabilityVerbs.')\b/u', $text) === 1) {
            return true;
        }

        return preg_match('/(?<![A-Za-z0-9_-])(?=[A-Za-z0-9_-]*[A-Za-z])(?=[A-Za-z0-9_-]*\d)[A-Za-z0-9_-]{2,}(?![A-Za-z0-9_-])\s*(?:采用|使用|支持|提供|包含|实现|适合|处理|可点胶|可灌胶|兼容)/u', $text) === 1
            || preg_match('/\b[A-Z][A-Za-z0-9_-]{2,}\s*(?:采用|使用|支持|提供|包含|实现|适合|处理|可点胶|可灌胶|兼容)/u', $text) === 1
            || preg_match('/(?:该|本|这台|这款|这个|所选|我们的|一台|每台|每个|任一)(?:设备|机器|机型|系统|装置|产品|型号).{0,40}(?:采用|使用|支持|提供|包含|配备|运行|实现|适合|处理|兼容|(?:可以|能够|能)[\p{Han}]{1,12})/u', $text) === 1;
    }

    private function normalizeRenderedText(string $text): string
    {
        $text = preg_replace('/!\[([^\]]*)\]\([^\r\n)]*\)/u', '$1', $text) ?? $text;
        $text = preg_replace('/\[([^\]]+)\]\([^\r\n)]*\)/u', '$1', $text) ?? $text;
        $text = preg_replace('/!\[([^\]]*)\]\[[^\]]*\]/u', '$1', $text) ?? $text;
        $text = preg_replace('/\[([^\]]+)\]\[[^\]]*\]/u', '$1', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\p{Cc}\p{Cf}]/u', '', $text) ?? $text;
        $text = mb_convert_kana($text, 'asKV', 'UTF-8');
        $text = str_replace(['**', '__', '~~', '`', '*', '_'], '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
