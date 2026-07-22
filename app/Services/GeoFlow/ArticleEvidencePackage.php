<?php

namespace App\Services\GeoFlow;

use InvalidArgumentException;

class ArticleEvidencePackage
{
    private const SOURCE_TYPES = ['knowledge_chunk', 'knowledge_full', 'entity', 'case'];

    private const SOURCE_STATES = ['available', 'unverified', 'restricted'];

    private const PUBLICATION_SCOPES = ['internal_reference', 'restricted', 'unknown'];

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    public function make(
        string $sourceType,
        int $sourceId,
        string $label,
        string $content,
        ?int $chunkIndex = null,
        string $sourceState = 'available',
        string $publicationScope = 'internal_reference',
        array $metadata = [],
        ?string $revisionContent = null
    ): array {
        $sourceType = trim($sourceType);
        $content = trim($content);
        if (! in_array($sourceType, self::SOURCE_TYPES, true) || $sourceId <= 0 || $content === '') {
            throw new InvalidArgumentException('证据来源参数无效');
        }
        if (! in_array($sourceState, self::SOURCE_STATES, true)) {
            throw new InvalidArgumentException('证据状态无效');
        }
        if (! in_array($publicationScope, self::PUBLICATION_SCOPES, true)) {
            throw new InvalidArgumentException('证据发布范围无效');
        }
        if ($sourceType === 'knowledge_chunk' && ($chunkIndex === null || $chunkIndex < 0)) {
            throw new InvalidArgumentException('知识片段索引无效');
        }
        if ($sourceType === 'case') {
            // The current Case schema has no publication approval or anonymization fields.
            $sourceState = 'unverified';
            $publicationScope = 'unknown';
        }

        $contentHash = hash('sha256', $content);
        $sourceRevisionHash = hash('sha256', $revisionContent === null ? $content : trim($revisionContent));

        return [
            'id' => $this->buildId($sourceType, $sourceId, $chunkIndex, $sourceRevisionHash),
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'chunk_index' => $sourceType === 'knowledge_chunk' ? $chunkIndex : null,
            'label' => trim($label),
            'content' => $content,
            'source_state' => $sourceState,
            'publication_scope' => $publicationScope,
            'content_sha256' => $contentHash,
            'source_revision_sha256' => $sourceRevisionHash,
            'metadata' => $metadata,
        ];
    }

    /** @param list<array<string,mixed>> $items @return list<string> */
    public function ids(array $items): array
    {
        return collect($items)
            ->filter(static fn (mixed $item): bool => is_array($item)
                && ($item['source_type'] ?? null) !== 'case'
                && ($item['source_state'] ?? null) === 'available'
                && ($item['publication_scope'] ?? null) === 'internal_reference')
            ->map(static fn (mixed $item): string => is_array($item) ? trim((string) ($item['id'] ?? '')) : '')
            ->filter(fn (string $id): bool => $this->isMachineSafeId($id))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Validate the frozen package before any paid model call.
     *
     * @param  list<array<string,mixed>>  $items
     * @return list<string>
     */
    public function assertGenerationReady(array $items): array
    {
        if ($items === []) {
            throw new InvalidArgumentException('结构化证据包为空或无效');
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('结构化证据包为空或无效');
            }
            if (! is_string($item['source_type'] ?? null)
                || ! is_int($item['source_id'] ?? null)
                || ! is_string($item['label'] ?? null)
                || ! is_string($item['content'] ?? null)
                || ! is_string($item['content_sha256'] ?? null)
                || ! is_string($item['source_revision_sha256'] ?? null)
                || ! is_string($item['id'] ?? null)
                || ! is_string($item['source_state'] ?? null)
                || ! is_string($item['publication_scope'] ?? null)) {
                throw new InvalidArgumentException('结构化证据包为空或无效');
            }
            $sourceType = trim((string) ($item['source_type'] ?? ''));
            $sourceId = $item['source_id'];
            $chunkIndex = $item['chunk_index'] ?? null;
            $content = trim((string) ($item['content'] ?? ''));
            $contentHash = strtolower(trim((string) ($item['content_sha256'] ?? '')));
            $revisionHash = strtolower(trim((string) ($item['source_revision_sha256'] ?? '')));
            $id = trim((string) ($item['id'] ?? ''));
            $state = trim((string) ($item['source_state'] ?? ''));
            $scope = trim((string) ($item['publication_scope'] ?? ''));

            if (! in_array($sourceType, self::SOURCE_TYPES, true)
                || $sourceId === false
                || $sourceId <= 0
                || $content === ''
                || ! in_array($state, self::SOURCE_STATES, true)
                || ! in_array($scope, self::PUBLICATION_SCOPES, true)
                || preg_match('/\A[a-f0-9]{64}\z/', $contentHash) !== 1
                || preg_match('/\A[a-f0-9]{64}\z/', $revisionHash) !== 1
                || ! hash_equals(hash('sha256', $content), $contentHash)
                || ! $this->isMachineSafeId($id)
                || ! hash_equals($this->buildId($sourceType, (int) $sourceId, is_int($chunkIndex) ? $chunkIndex : null, $revisionHash), $id)
                || ($sourceType === 'knowledge_chunk' && (! is_int($chunkIndex) || $chunkIndex < 0))
                || ($sourceType !== 'knowledge_chunk' && $chunkIndex !== null)
                || ($sourceType === 'case' && ($state !== 'unverified' || $scope !== 'unknown'))) {
                throw new InvalidArgumentException('结构化证据包为空或无效');
            }
        }

        $allowedIds = $this->ids($items);
        if ($allowedIds === []) {
            throw new InvalidArgumentException('结构化证据包没有可用于生成的证据');
        }

        return $allowedIds;
    }

    /**
     * Validate the exact evidence context that will cross the model boundary.
     *
     * @param  list<array<string,mixed>>  $items
     * @return list<string>
     */
    public function assertGenerationContextSafe(string $context, array $items): array
    {
        $allowedIds = $this->assertGenerationReady($items);
        if (trim($context) === '' || $this->containsProtectedEvidenceContent($context, $items)) {
            throw new InvalidArgumentException('生成证据上下文为空或包含受限内容');
        }

        $contextIds = $this->machineIdsInText($context);
        if ($contextIds === []
            || array_diff($contextIds, $allowedIds) !== []
            || array_diff($allowedIds, $contextIds) !== []) {
            throw new InvalidArgumentException('生成证据上下文与可引用证据不一致');
        }

        $comparableContext = $this->normalizeComparableEvidenceText($context);
        foreach ($items as $item) {
            if (! is_array($item) || ! in_array((string) ($item['id'] ?? ''), $allowedIds, true)) {
                continue;
            }

            $content = $this->normalizeComparableEvidenceText((string) ($item['content'] ?? ''));
            if ($content === '' || ! str_contains($comparableContext, $content)) {
                throw new InvalidArgumentException('生成证据上下文缺少可引用证据正文');
            }
        }

        $expectedContext = $this->generationContext($items);
        if (! hash_equals($expectedContext, $this->normalizeGenerationContext($context))) {
            throw new InvalidArgumentException('生成证据上下文包含无归属内容');
        }

        return $allowedIds;
    }

    /**
     * Build the only context representation that may cross the Deep model boundary.
     *
     * @param  list<array<string,mixed>>  $items
     */
    public function generationContext(array $items): string
    {
        $allowedLookup = array_fill_keys($this->assertGenerationReady($items), true);

        return collect($items)
            ->filter(static fn (mixed $item): bool => is_array($item)
                && isset($allowedLookup[(string) ($item['id'] ?? '')]))
            ->map(static fn (array $item): string => 'Evidence ID: '.(string) $item['id']."\n".trim((string) $item['content']))
            ->implode("\n\n");
    }

    /**
     * Return persistence-safe metadata. Labels, content, previews, and retrieval queries are deliberately excluded.
     *
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    public function audit(array $items): array
    {
        return collect($items)
            ->filter(fn (mixed $item): bool => is_array($item) && $this->isMachineSafeId((string) ($item['id'] ?? '')))
            ->map(static fn (array $item): array => [
                'id' => (string) $item['id'],
                'source_type' => (string) ($item['source_type'] ?? ''),
                'source_id' => (int) ($item['source_id'] ?? 0),
                'chunk_index' => isset($item['chunk_index']) ? (int) $item['chunk_index'] : null,
                'source_state' => (string) ($item['source_state'] ?? ''),
                'publication_scope' => (string) ($item['publication_scope'] ?? ''),
                'content_sha256' => (string) ($item['content_sha256'] ?? ''),
                'source_revision_sha256' => (string) ($item['source_revision_sha256'] ?? $item['content_sha256'] ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @param  list<string>  $claimTerms
     * @return array{content:string,claim_ledger:list<array{paragraph_sha256:string,evidence_refs:list<string>}>,coverage_status:string,unmarked_claim_count:int,unmarked_claim_hashes:list<string>,marker_normalization_count:int}
     */
    public function validateAndStripMarkers(string $markdown, array $items, array $claimTerms = []): array
    {
        $knownIds = $this->assertGenerationReady($items);
        if ($this->containsProtectedEvidenceContent($markdown, $items)) {
            throw new InvalidArgumentException('文章包含受限证据内容，未保存草稿');
        }
        $knownLookup = array_fill_keys($knownIds, true);
        preg_match_all('/<!--.*?-->/su', $markdown, $commentMatches);
        foreach ($commentMatches[0] ?? [] as $comment) {
            $normalizedComment = $this->normalizeMarkerScan((string) $comment);
            if ($normalizedComment !== $comment && preg_match('/evidence/iu', $normalizedComment) === 1) {
                throw new ArticleEvidenceMarkerException('文章包含格式无效的证据引用');
            }
        }
        [$markerScan, $markerNormalizationCount] = $this->normalizeRecoverableMarkers($markdown, $knownLookup);
        preg_match_all('/<!--\s*evidence\s*:(.*?)-->/isu', $markerScan, $markerMatches);
        preg_match_all('/<!--\s*evidence.*?-->/isu', $markerScan, $allEvidenceMarkerMatches);
        if (count($allEvidenceMarkerMatches[0] ?? []) !== count($markerMatches[0] ?? [])) {
            throw new ArticleEvidenceMarkerException('文章包含格式无效的证据引用');
        }
        foreach ($markerMatches[1] ?? [] as $rawReferences) {
            $references = collect(explode(',', (string) $rawReferences))
                ->map(static fn (string $id): string => trim($id))
                ->filter()
                ->unique()
                ->values()
                ->all();
            if ($references === []) {
                throw new ArticleEvidenceMarkerException('文章包含格式无效的证据引用');
            }
            foreach ($references as $reference) {
                if (! $this->isMachineSafeId($reference) || ! isset($knownLookup[$reference])) {
                    throw new ArticleEvidenceMarkerException('文章包含未知或格式无效的证据引用');
                }
            }
        }

        $strippedMarkdown = $this->stripMarkers($markerScan);
        if ($this->containsEvidenceLikeMarker($strippedMarkdown)) {
            throw new ArticleEvidenceMarkerException('文章包含格式无效的证据引用');
        }

        $itemCollection = collect($items);
        $identifierTerms = $itemCollection
            ->pluck('label')
            ->flatMap(fn (mixed $label): array => $this->identifierTerms((string) $label));
        $specificTerms = collect($claimTerms)
            ->merge($itemCollection
                ->filter(static fn (mixed $item): bool => is_array($item) && in_array((string) ($item['source_type'] ?? ''), ['entity', 'case'], true))
                ->pluck('label'))
            ->merge($identifierTerms)
            ->map(static fn (mixed $term): string => trim((string) $term))
            ->filter(fn (string $term): bool => mb_strlen($term, 'UTF-8') >= 3 || $this->isCompactIdentifier($term))
            ->unique(static fn (string $term): string => mb_strtolower($term, 'UTF-8'))
            ->values()
            ->all();

        $ledger = [];
        $unmarkedClaimHashes = [];
        foreach ($this->claimUnits($markerScan) as $unit) {
            $paragraphHash = hash('sha256', $this->normalizeText($unit['text']));
            if ($unit['evidence_refs'] !== []) {
                $ledger[] = [
                    'paragraph_sha256' => $paragraphHash,
                    'evidence_refs' => $unit['evidence_refs'],
                ];

                continue;
            }

            if ($this->isSpecificClaim($unit['text'], $specificTerms)) {
                $unmarkedClaimHashes[] = $paragraphHash;
            }
        }

        $coverageStatus = 'not_applicable';
        if ($unmarkedClaimHashes !== []) {
            $coverageStatus = 'partial';
        } elseif ($ledger !== []) {
            $coverageStatus = 'complete';
        }

        return [
            'content' => trim($strippedMarkdown),
            'claim_ledger' => $ledger,
            'coverage_status' => $coverageStatus,
            'unmarked_claim_count' => count($unmarkedClaimHashes),
            'unmarked_claim_hashes' => array_values(array_unique($unmarkedClaimHashes)),
            'marker_normalization_count' => $markerNormalizationCount,
        ];
    }

    public function containsEvidenceLikeMarker(string $text): bool
    {
        $normalized = $this->normalizeMarkerScan($text);

        return preg_match('/<!--[^>]*evidence/isu', $normalized) === 1;
    }

    private function buildId(string $sourceType, int $sourceId, ?int $chunkIndex, string $contentHash): string
    {
        $shortHash = substr($contentHash, 0, 16);

        return match ($sourceType) {
            'knowledge_chunk' => "KB:{$sourceId}:CHUNK:{$chunkIndex}:{$shortHash}",
            'knowledge_full' => "KB:{$sourceId}:FULL:{$shortHash}",
            'entity' => "ENTITY:{$sourceId}:{$shortHash}",
            'case' => "CASE:{$sourceId}:{$shortHash}",
            default => throw new InvalidArgumentException('证据来源类型无效'),
        };
    }

    private function isMachineSafeId(string $id): bool
    {
        return preg_match('/\A(?:KB:\d+:(?:CHUNK:\d+|FULL):[a-f0-9]{16}|ENTITY:\d+:[a-f0-9]{16}|CASE:\d+:[a-f0-9]{16})\z/', $id) === 1;
    }

    private function stripMarkers(string $text): string
    {
        $stripped = preg_replace('/[ \t]*<!--\s*evidence\s*:.*?-->[ \t]*/isu', ' ', $text) ?? $text;
        $stripped = preg_replace('/^[ \t]+$/mu', '', $stripped) ?? $stripped;

        return preg_replace('/\n{3,}/u', "\n\n", $stripped) ?? $stripped;
    }

    private function normalizeMarkerScan(string $text): string
    {
        return preg_replace('/[\p{Cc}\p{Cf}]/u', '', $text) ?? $text;
    }

    /**
     * Canonicalize marker-only protocol variations without sending article prose
     * back to a model. The payload must be consumed completely and every ID must
     * resolve against the frozen allowlist.
     *
     * @param  array<string,bool>  $knownLookup
     * @return array{string,int}
     */
    private function normalizeRecoverableMarkers(string $text, array $knownLookup): array
    {
        $normalizationCount = 0;
        $normalized = preg_replace_callback(
            '/<!--.*?-->/su',
            function (array $matches) use ($knownLookup, &$normalizationCount): string {
                $comment = (string) ($matches[0] ?? '');
                if (preg_match('/evidence/iu', $comment) !== 1) {
                    return $comment;
                }
                if (preg_match('/\A<!--\s*evidence\s*(?::|：)?\s*(.*?)\s*-->\z/isu', $comment, $parts) !== 1) {
                    throw new ArticleEvidenceMarkerException('文章包含格式无效的证据引用');
                }

                $payload = mb_convert_kana((string) ($parts[1] ?? ''), 'asKV', 'UTF-8');
                $payload = str_replace(['，', '；', ';'], ',', $payload);
                $payload = preg_replace('/\s*:\s*/u', ':', $payload) ?? $payload;
                $references = array_map('trim', explode(',', trim($payload)));
                if ($references === [] || in_array('', $references, true)) {
                    throw new ArticleEvidenceMarkerException('文章包含格式无效的证据引用');
                }
                foreach ($references as $reference) {
                    if (! $this->isMachineSafeId($reference) || ! isset($knownLookup[$reference])) {
                        throw new ArticleEvidenceMarkerException('文章包含未知或格式无效的证据引用');
                    }
                }

                $canonical = '<!-- evidence:'.implode(',', array_values(array_unique($references))).' -->';
                if (! hash_equals($comment, $canonical)) {
                    $normalizationCount++;
                }

                return $canonical;
            },
            $text
        );

        return [$normalized ?? $text, $normalizationCount];
    }

    private function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Split Markdown into marker-local claim units. A marker can authorize only
     * the immediately preceding heading or body paragraph, never both.
     *
     * @return list<array{text:string,evidence_refs:list<string>}>
     */
    private function claimUnits(string $markdown): array
    {
        $units = [];
        $bodyLines = [];
        $bodyReferences = [];
        $lastUnitIndex = null;
        $blankLineCount = 0;
        $flushBody = function () use (&$units, &$bodyLines, &$bodyReferences, &$lastUnitIndex): void {
            $body = trim(implode("\n", $bodyLines));
            if ($body !== '') {
                $units[] = [
                    'text' => $body,
                    'evidence_refs' => array_values(array_unique($bodyReferences)),
                ];
                $lastUnitIndex = array_key_last($units);
            }
            $bodyLines = [];
            $bodyReferences = [];
        };

        foreach ($this->expandedClaimLines($markdown) as $line) {
            $lineReferences = $this->markerReferences($line);
            $plainLine = trim($this->stripMarkers($line));
            if ($plainLine === '') {
                if ($lineReferences !== []) {
                    if ($bodyLines !== []) {
                        $bodyReferences = array_merge($bodyReferences, $lineReferences);
                        $flushBody();
                    } elseif ($lastUnitIndex !== null && $blankLineCount <= 1) {
                        $units[$lastUnitIndex]['evidence_refs'] = array_values(array_unique(array_merge(
                            $units[$lastUnitIndex]['evidence_refs'],
                            $lineReferences
                        )));
                    } else {
                        throw new ArticleEvidenceMarkerException('文章包含格式无效的证据引用');
                    }
                    $blankLineCount = 0;
                } else {
                    $flushBody();
                    $blankLineCount++;
                    if ($blankLineCount > 1) {
                        $lastUnitIndex = null;
                    }
                }

                continue;
            }

            $blankLineCount = 0;

            if (preg_match('/^#{1,6}\s+(.+)$/u', $plainLine, $matches) === 1) {
                $flushBody();
                $heading = trim((string) ($matches[1] ?? ''));
                if ($heading !== '') {
                    $units[] = ['text' => $heading, 'evidence_refs' => $lineReferences];
                    $lastUnitIndex = array_key_last($units);
                }

                continue;
            }

            if (preg_match('/^>\s*$/u', $plainLine) === 1) {
                $flushBody();
                $lastUnitIndex = null;

                continue;
            }

            if ($this->startsStructuralClaimUnit($plainLine)) {
                $flushBody();
            }

            $bodyLines[] = $plainLine;
            if ($lineReferences !== []) {
                $bodyReferences = array_merge($bodyReferences, $lineReferences);
                $flushBody();
            }
        }
        $flushBody();

        return $units;
    }

    private function startsStructuralClaimUnit(string $line): bool
    {
        return preg_match('/^(?:>\s*)*(?:[-+*]|\d+[.)])\s+\S/u', $line) === 1
            || preg_match('/^(?:>\s*)*\|.*\|\s*$/u', $line) === 1
            || preg_match('/^<(?:p|li|tr|blockquote)\b[^>]*>.*<\/(?:p|li|tr|blockquote)>\s*$/iu', $line) === 1;
    }

    /** @return list<string> */
    private function expandedClaimLines(string $markdown): array
    {
        $expanded = preg_replace('/<\/?(?:ul|ol|table|thead|tbody|tfoot)\b[^>]*>/iu', '', trim($markdown)) ?? trim($markdown);
        $expanded = preg_replace('/(?=<(?:p|li|tr)\b)/iu', "\n", $expanded) ?? $expanded;
        $expanded = preg_replace('/(<\/(?:p|li|tr)>)/iu', "$1\n", $expanded) ?? $expanded;

        return preg_split('/\R/u', trim($expanded)) ?: [];
    }

    /** @return list<string> */
    private function markerReferences(string $text): array
    {
        preg_match_all('/<!--\s*evidence\s*:(.*?)-->/isu', $text, $matches);

        return collect($matches[1] ?? [])
            ->flatMap(static fn (mixed $raw): array => array_map('trim', explode(',', (string) $raw)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function identifierTerms(string $label): array
    {
        preg_match_all('/\b(?=[A-Za-z0-9_-]*[A-Za-z])(?=[A-Za-z0-9_-]*\d)[A-Za-z0-9_-]{2,}\b/u', $label, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    private function isCompactIdentifier(string $term): bool
    {
        return preg_match('/\A(?=[A-Za-z0-9_-]*[A-Za-z])(?=[A-Za-z0-9_-]*\d)[A-Za-z0-9_-]{2,}\z/u', $term) === 1;
    }

    /** @param list<string> $specificTerms */
    private function isSpecificClaim(string $text, array $specificTerms): bool
    {
        $text = $this->normalizeRenderedText($text);

        if (preg_match('/(?<![\p{N}])\d+(?:[.,]\d+)?\s*(?:%|mm|cm|m|µm|um|nm|l|ml|µl|ul|kg|g|mg|kw|w|v|a|n|kn|bar|psi|pa|kpa|mpa|rpm|hz|millimeters?|centimeters?|meters?|micrometers?|nanometers?|liters?|litres?|milliliters?|millilitres?|microliters?|microlitres?|kilograms?|grams?|milligrams?|kilowatts?|watts?|volts?|amps?|amperes?|newtons?|kilonewtons?|pascals?|kilopascals?|megapascals?|hertz|percent|degrees?\s+(?:celsius|fahrenheit)|hours?|days?|minutes?|seconds?|pieces?|pcs?|units?|毫米|厘米|米|微米|纳米|升|毫升|微升|千克|公斤|克|毫克|千瓦|瓦|伏|安培?|牛|千牛|巴|帕|千帕|兆帕|赫兹|摄氏度|华氏度|小时|天|分钟|秒|件|台|套|百分比)(?![\p{L}\p{N}_])/iu', $text) === 1) {
            return true;
        }
        if (preg_match('/\b(?:achieved|reduced|decreased|increased|improved|supports?|capable|can provide|can handle|dispenses?|process(?:es)?|handles?|accepts?|accommodates?|works?|is suitable|is compatible|guarantees?)\b|(?:提升|降低|减少|增加|支持|能够|可实现|保证|达到|适合|处理|可点胶|可灌胶|兼容)/iu', $text) === 1) {
            return true;
        }
        if (preg_match('/\b(?:(?:the|this|that|these|those|selected|our|a|an|each|every|any)\s+)?(?:[a-z][a-z0-9-]*\s+){0,3}(?:units?|models?|products?|machines?|systems?|equipment|devices?)\b.{0,80}\b(?:uses?|provides?|has|have|includes?|features?|contains?|operates?|(?:can|could|may)\s+[a-z][a-z-]*)\b|(?:该|本|这台|这款|这个|所选|我们的|一台|每台|每个|任一)(?:设备|机器|机型|系统|装置|产品|型号).{0,40}(?:采用|使用|支持|提供|包含|配备|运行|实现|适合|处理|兼容|(?:可以|能够|能)[\p{Han}]{1,12})/iu', $text) === 1) {
            return true;
        }

        $haystack = mb_strtolower($text, 'UTF-8');
        foreach ($specificTerms as $term) {
            if ($this->isCompactIdentifier($term)) {
                if (preg_match('/(?<![A-Za-z0-9_-])'.preg_quote($term, '/').'(?![A-Za-z0-9_-])/iu', $text) === 1) {
                    return true;
                }

                continue;
            }
            if (str_contains($haystack, mb_strtolower($term, 'UTF-8'))) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string,mixed>> $items */
    private function containsProtectedEvidenceContent(string $markdown, array $items): bool
    {
        $haystack = $this->normalizeComparableEvidenceText($this->stripMarkers($markdown));
        if ($haystack === '') {
            return false;
        }

        foreach ($items as $item) {
            if (! is_array($item)
                || (($item['source_type'] ?? null) !== 'case'
                    && ($item['source_state'] ?? null) === 'available'
                    && ($item['publication_scope'] ?? null) === 'internal_reference')) {
                continue;
            }

            $content = trim((string) ($item['content'] ?? ''));
            $fragments = array_merge(
                [$content],
                preg_split('/\R+|(?<=[.!?。！？;；])\s*/u', $content, -1, PREG_SPLIT_NO_EMPTY) ?: []
            );
            $fieldValueFragments = [];
            foreach ($fragments as $fragment) {
                if (preg_match('/\A[^:：\r\n]{1,24}[:：]\s*(.+)\z/su', trim((string) $fragment), $matches) === 1) {
                    $fieldValueFragments[] = (string) ($matches[1] ?? '');
                }
            }
            $fragments = array_merge($fragments, $fieldValueFragments);
            foreach ($fragments as $fragment) {
                $needle = $this->normalizeComparableEvidenceText((string) $fragment);
                if (mb_strlen($needle, 'UTF-8') >= 8 && str_contains($haystack, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeComparableEvidenceText(string $text): string
    {
        $text = mb_strtolower($this->normalizeRenderedText($text), 'UTF-8');

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $text);
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

    private function normalizeGenerationContext(string $context): string
    {
        $context = preg_replace('/\R/u', "\n", $context) ?? $context;

        return trim($context);
    }

    /** @return list<string> */
    private function machineIdsInText(string $text): array
    {
        $text = mb_convert_kana($this->normalizeMarkerScan($text), 'asKV', 'UTF-8');
        preg_match_all('/(?:KB:\d+:(?:CHUNK:\d+|FULL):[a-f0-9]{16}|ENTITY:\d+:[a-f0-9]{16}|CASE:\d+:[a-f0-9]{16})/i', $text, $matches);

        return collect($matches[0] ?? [])
            ->map(static fn (mixed $id): string => strtoupper(substr((string) $id, 0, (int) strrpos((string) $id, ':') + 1))
                .strtolower(substr((string) $id, (int) strrpos((string) $id, ':') + 1)))
            ->filter(fn (string $id): bool => $this->isMachineSafeId($id))
            ->unique()
            ->values()
            ->all();
    }
}
