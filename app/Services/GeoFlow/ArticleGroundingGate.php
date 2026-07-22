<?php

namespace App\Services\GeoFlow;

final class ArticleGroundingGate
{
    public const RULE_VERSION = 'article-v2.3.1-grounding-2';

    private const LIMITED_EXPANSION_FLOOR = 4000;

    private const LIMITED_EXPANSION_RATIO = 4;

    private const NUMBER_WITH_UNIT_PATTERN = '/(?<![\p{L}\p{N}_,.\-])(\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:[.,]\d+)?)\s*(%|mm|cm|m|µm|um|nm|l|ml|µl|ul|kg|g|mg|kw|w|v|a|n|kn|bar|psi|pa|kpa|mpa|rpm|hz|°c|°f|hours?|days?|minutes?|seconds?|pieces?|pcs?|units?|毫米|厘米|米|微米|纳米|升|毫升|微升|千克|公斤|克|毫克|千瓦|瓦|伏|安培?|牛|千牛|巴|帕|千帕|兆帕|赫兹|摄氏度|华氏度|小时|天|分钟|秒|件|台|套)(?![\p{L}\p{N}_])/iu';

    public function __construct(private readonly ArticleEvidencePackage $evidencePackage) {}

    /**
     * @param  list<array<string,mixed>>  $evidenceItems
     * @param  array<string,mixed>  $claimAnalysis
     * @return array{rule_version:string,outcome:string,content_sha256:string,issues:list<array<string,mixed>>}
     */
    public function evaluate(string $content, array $evidenceItems = [], array $claimAnalysis = []): array
    {
        $content = trim($content);
        $eligibleEvidence = $this->eligibleEvidence($evidenceItems);
        $evidenceText = implode("\n", array_column($eligibleEvidence, 'content'));
        $supportedNumericClaims = $this->numericClaims($evidenceText);
        $issues = [];

        foreach ($this->sentences($content) as $sentence) {
            foreach ($this->numericClaims($sentence) as $claim => $match) {
                if (! isset($supportedNumericClaims[$claim])) {
                    $issues[] = $this->issue(
                        'unsupported_numeric_unit',
                        'critical',
                        95,
                        $sentence
                    );
                }
            }

            if ($this->containsPrivateContact($sentence)) {
                $issues[] = $this->issue('privacy_contact_exposure', 'critical', 98, $sentence);
            }

            if ($this->containsUnsafeImperative($sentence)) {
                $issues[] = $this->issue('unsafe_operational_instruction', 'critical', 98, $sentence);
            }

            if ($this->containsAmbiguousSpecificClaim($sentence)
                && ! str_contains($this->comparable($evidenceText), $this->comparable($sentence))) {
                $issues[] = $this->issue('ambiguous_specific_claim', 'warning', 70, $sentence);
            }
        }

        if (($claimAnalysis['coverage_status'] ?? null) === 'partial') {
            $issues[] = $this->issue(
                'partial_claim_coverage',
                'warning',
                85,
                hash('sha256', json_encode($claimAnalysis['unmarked_claim_hashes'] ?? [], JSON_UNESCAPED_SLASHES))
            );
        }

        $evidenceSufficiency = (string) ($claimAnalysis['evidence_sufficiency'] ?? '');
        if ($evidenceSufficiency === 'limited') {
            $issues[] = $this->issue('limited_evidence_delivery', 'warning', 90, $evidenceSufficiency);
            $evidenceLength = mb_strlen(trim($evidenceText), 'UTF-8');
            $contentLength = mb_strlen($content, 'UTF-8');
            $maximumProportionateLength = max(
                self::LIMITED_EXPANSION_FLOOR,
                $evidenceLength * self::LIMITED_EXPANSION_RATIO
            );
            if ($contentLength > $maximumProportionateLength) {
                $issues[] = $this->issue(
                    'limited_evidence_overexpansion',
                    'critical',
                    92,
                    'limited:'.$contentLength.':'.$evidenceLength
                );
            }
        } elseif ($evidenceSufficiency === 'insufficient') {
            $issues[] = $this->issue('insufficient_evidence_delivery', 'critical', 95, $evidenceSufficiency);
        }

        $issues = collect($issues)
            ->unique(static fn (array $issue): string => $issue['code'].':'.$issue['excerpt_sha256'])
            ->values()
            ->all();
        $outcome = collect($issues)->contains(
            static fn (array $issue): bool => $issue['severity'] === 'critical' && (int) $issue['confidence'] >= 90
        ) ? 'blocked' : ($issues === [] ? 'pass' : 'pending_review');

        return [
            'rule_version' => self::RULE_VERSION,
            'outcome' => $outcome,
            'content_sha256' => hash('sha256', $content),
            'issues' => $issues,
        ];
    }

    /** @param list<array<string,mixed>> $items @return list<array{id:string,content:string}> */
    private function eligibleEvidence(array $items): array
    {
        $allowedLookup = array_fill_keys($this->evidencePackage->ids($items), true);

        return collect($items)
            ->filter(static fn (mixed $item): bool => is_array($item)
                && isset($allowedLookup[(string) ($item['id'] ?? '')]))
            ->map(static fn (array $item): array => [
                'id' => (string) $item['id'],
                'content' => trim((string) ($item['content'] ?? '')),
            ])
            ->values()
            ->all();
    }

    /** @return array<string,string> */
    private function numericClaims(string $text): array
    {
        preg_match_all(self::NUMBER_WITH_UNIT_PATTERN, $text, $matches, PREG_SET_ORDER);
        $claims = [];
        foreach ($matches as $match) {
            $number = $this->canonicalNumber((string) ($match[1] ?? ''));
            $unit = $this->canonicalUnit((string) ($match[2] ?? ''));
            if ($number !== null && $unit !== '') {
                $claims[$number.' '.$unit] = (string) ($match[0] ?? '');
            }
        }

        return $claims;
    }

    private function canonicalNumber(string $number): ?string
    {
        $number = trim($number);
        if (preg_match('/\A\d{1,3}(?:,\d{3})+(?:\.\d+)?\z/', $number) === 1) {
            $number = str_replace(',', '', $number);
        } elseif (preg_match('/\A\d+,\d{1,2}\z/', $number) === 1) {
            $number = str_replace(',', '.', $number);
        } elseif (preg_match('/\A\d+(?:\.\d+)?\z/', $number) !== 1) {
            return null;
        }

        [$integer, $fraction] = array_pad(explode('.', $number, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');

        return $fraction === '' ? $integer : $integer.'.'.$fraction;
    }

    private function canonicalUnit(string $unit): string
    {
        $unit = mb_strtolower(trim($unit), 'UTF-8');

        return match ($unit) {
            'percent' => '%',
            'hours' => 'hour', 'days' => 'day', 'minutes' => 'minute', 'seconds' => 'second',
            'pieces', 'pcs' => 'piece', 'units' => 'unit',
            '毫米' => 'mm', '厘米' => 'cm', '米' => 'm', '微米' => 'um', '纳米' => 'nm',
            '升' => 'l', '毫升' => 'ml', '微升' => 'ul',
            '千克', '公斤' => 'kg', '克' => 'g', '毫克' => 'mg',
            '千瓦' => 'kw', '瓦' => 'w', '伏' => 'v', '安', '安培' => 'a',
            '牛' => 'n', '千牛' => 'kn', '巴' => 'bar', '帕' => 'pa', '千帕' => 'kpa', '兆帕' => 'mpa',
            '赫兹' => 'hz', '摄氏度' => '°c', '华氏度' => '°f',
            '小时' => 'hour', '天' => 'day', '分钟' => 'minute', '秒' => 'second',
            '件' => 'piece', '台', '套' => 'unit',
            'µm' => 'um', 'µl' => 'ul',
            default => rtrim($unit, 's'),
        };
    }

    /** @return list<string> */
    private function sentences(string $content): array
    {
        return collect(preg_split('/\R+|(?<=[.!?。！？;；])\s+/u', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(static fn (string $sentence): string => trim(strip_tags($sentence)))
            ->filter()
            ->values()
            ->all();
    }

    private function containsPrivateContact(string $sentence): bool
    {
        if (preg_match('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu', $sentence) === 1) {
            return true;
        }

        if (preg_match('/(?:phone|telephone|tel|mobile|whatsapp|电话|手机|联系电话)(?:\s+(?:number|no\.?|号码))?(?:\s*(?:is|为|是))?\s*[:：#=-]?\s*\+?\d{7,15}\b/iu', $sentence) === 1) {
            return true;
        }

        if (preg_match('/\+\d[\d\s().-]{6,}\d/u', $sentence) !== 1
            && preg_match('/\b\d{2,4}[\s().-]+\d{3,4}[\s().-]+\d{3,4}\b/u', $sentence) !== 1) {
            return false;
        }

        preg_match_all('/\d/u', $sentence, $digits);

        return count($digits[0] ?? []) >= 8;
    }

    private function containsUnsafeImperative(string $sentence): bool
    {
        $dangerous = '(?:disable|bypass|remove|override|short|disconnect|defeat|关闭|禁用|绕过|拆除|移除|短接)';
        $target = '(?:safety\s+(?:interlock|guard|protection|sensor)|guard|interlock|保护装置|安全联锁|安全门|安全传感器)';
        $clauses = preg_split('/[,，;；]|\b(?:and\s+then|then|but|however|while|when|before|after|and)\b|(?:然后|但是|不过|同时|并且|之后|之前|当时)/iu', $sentence, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($clauses as $clause) {
            if (preg_match('/'.$dangerous.'.{0,30}'.$target.'|'.$target.'.{0,30}'.$dangerous.'/iu', $clause) !== 1) {
                continue;
            }
            $negated = preg_match('/(?:do\s+not|don[’\']t|never|must\s+not|should\s+not|avoid)\s+(?:\S+\s+){0,3}'.$dangerous.'|(?:不要|不得|禁止|切勿|避免).{0,12}'.$dangerous.'/iu', $clause) === 1;
            if (! $negated) {
                return true;
            }
        }

        return false;
    }

    private function containsAmbiguousSpecificClaim(string $sentence): bool
    {
        return preg_match('/\b(?:the|this|our)\s+(?:system|machine|product|equipment)\s+(?:may|might|could)\s+(?:significantly\s+)?(?:improve|increase|reduce|decrease|enhance)\b|(?:该|本|这款)(?:系统|机器|设备|产品)(?:可能|或可)(?:显著)?(?:提升|提高|降低|减少|改善)/iu', $sentence) === 1;
    }

    /** @return array{code:string,severity:string,confidence:int,excerpt_sha256:string,evidence_refs:list<string>} */
    private function issue(string $code, string $severity, int $confidence, string $excerpt): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'confidence' => $confidence,
            'excerpt_sha256' => preg_match('/\A[a-f0-9]{64}\z/', $excerpt) === 1
                ? $excerpt
                : hash('sha256', trim($excerpt)),
            'evidence_refs' => [],
        ];
    }

    private function comparable(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_convert_kana($text, 'asKV', 'UTF-8');
        $text = mb_strtolower($text, 'UTF-8');

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $text);
    }
}
