<?php

namespace App\Services\GeoFlow;

use App\Models\Article;
use Illuminate\Support\Str;

class ArticleQualityAssessmentService
{
    /**
     * @return array{
     *   score:int,
     *   grade:string,
     *   status:string,
     *   detected_language:string,
     *   issues:list<array{key:string,severity:string,message_key:string}>,
     *   items:list<array{key:string,score:int,max:int,status:string,detail:string,metrics:array<string,mixed>,reasons:list<string>}>
     * }
     */
    public function assess(Article $article, array $generationTrace = []): array
    {
        $title = trim((string) $article->title);
        $content = trim((string) $article->content);
        $plain = $this->plainText($content);
        $keywords = $this->keywords((string) ($article->keywords ?: $article->original_keyword));
        $knowledge = is_array($generationTrace['knowledge'] ?? null) ? $generationTrace['knowledge'] : [];
        $traceImages = collect($generationTrace['images'] ?? [])->filter(fn ($image) => is_array($image))->values();
        $imageCount = $this->articleImageCount($article, $traceImages->count());
        $detectedLanguage = $this->detectLanguage($title."\n".$plain);
        $expectedLanguage = $this->expectedLanguage($generationTrace);

        $items = [
            $this->checkLanguage($title, $plain, $detectedLanguage, $expectedLanguage),
            $this->checkKnowledgeReference($knowledge),
            $this->checkKeywords($title, $plain, $keywords),
            $this->checkFactBasis($plain, $knowledge),
            $this->checkStructure($content, $plain),
            $this->checkFaq($content, $plain),
            $this->checkImages($imageCount, $traceImages->count(), (string) data_get($generationTrace, 'task.image_tag_filter', '')),
            $this->checkUniqueness($plain),
        ];

        $score = (int) collect($items)->sum('score');
        $issues = collect($items)
            ->filter(fn (array $item): bool => in_array($item['status'], ['warning', 'failed'], true))
            ->map(fn (array $item): array => [
                'key' => (string) $item['key'],
                'severity' => $item['status'] === 'failed' ? 'high' : 'medium',
                'message_key' => 'admin.articles.quality.suggestions.'.(string) $item['key'],
                'detail' => (string) ($item['detail'] ?? ''),
                'reasons' => is_array($item['reasons'] ?? null) ? $item['reasons'] : [],
            ])
            ->values()
            ->all();

        return [
            'score' => max(0, min(100, $score)),
            'grade' => $this->grade($score),
            'status' => $score >= 80 ? 'good' : ($score >= 60 ? 'warning' : 'poor'),
            'detected_language' => $detectedLanguage,
            'issues' => $issues,
            'items' => $items,
        ];
    }

    private function checkLanguage(string $title, string $plain, string $detectedLanguage, string $expectedLanguage): array
    {
        $sample = $title."\n".$plain;
        $han = preg_match_all('/\p{Han}/u', $sample);
        $latinWords = preg_match_all('/\b[A-Za-z][A-Za-z\'-]{2,}\b/u', $sample);
        $mixed = $han > 20 && $latinWords > 40;
        $score = $mixed ? 7 : 15;
        $status = $score >= 12 ? 'passed' : 'warning';
        $reasons = $mixed ? ['mixed_language'] : [];
        if ($expectedLanguage !== 'unknown') {
            if ($detectedLanguage === 'unknown') {
                $score = min($score, 8);
                $status = 'warning';
                $reasons[] = 'unknown_language';
            } elseif ($this->primaryLanguage($detectedLanguage) !== $this->primaryLanguage($expectedLanguage)) {
                $score = 0;
                $status = 'failed';
                $reasons[] = 'language_mismatch';
            }
        }

        $detail = $expectedLanguage !== 'unknown'
            ? $detectedLanguage.' / expected '.$expectedLanguage
            : $detectedLanguage;

        return $this->item('language', $score, 15, $status, $detail, [
            'detected_language' => $detectedLanguage,
            'expected_language' => $expectedLanguage,
            'han_chars' => $han,
            'latin_words' => $latinWords,
        ], $reasons);
    }

    private function checkKnowledgeReference(array $knowledge): array
    {
        $contextLength = (int) ($knowledge['context_length'] ?? 0);
        $chunks = collect($knowledge['chunks'] ?? [])->filter(fn ($chunk) => is_array($chunk));
        $knowledgeBases = collect($knowledge['knowledge_bases'] ?? [])->filter(fn ($item) => is_array($item));
        $summary = is_array($knowledge['evidence_summary'] ?? null) ? $knowledge['evidence_summary'] : [];
        $averageEvidence = (int) ($summary['average_evidence_score'] ?? 0);
        $retrievalSources = collect($summary['retrieval_sources'] ?? [])
            ->map(fn ($source) => trim((string) $source))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $score = 0;
        $score += $contextLength >= 1200 ? 5 : ($contextLength >= 300 ? 4 : ($contextLength > 0 ? 2 : 0));
        $score += $chunks->count() >= 3 ? 5 : ($chunks->count() > 0 ? 4 : 0);
        $score += $knowledgeBases->count() > 0 ? 2 : 0;
        $score += $averageEvidence >= 70 ? 3 : ($averageEvidence >= 40 ? 2 : ($averageEvidence > 0 ? 1 : 0));
        if (array_intersect($retrievalSources, ['pgvector', 'real_embedding_hybrid']) !== []) {
            $score += 1;
        }
        $score = min(15, $score);

        $reasons = [];
        if ($contextLength === 0) {
            $reasons[] = 'no_rag_context';
        }
        if ($chunks->isEmpty()) {
            $reasons[] = 'no_chunks';
        }
        if ($averageEvidence > 0 && $averageEvidence < 40) {
            $reasons[] = 'weak_evidence_score';
        }
        if ($knowledgeBases->isEmpty() && $contextLength > 0) {
            $reasons[] = 'missing_knowledge_base_trace';
        }

        return $this->item(
            'knowledge',
            $score,
            15,
            $score >= 12 ? 'passed' : ($score > 0 ? 'warning' : 'failed'),
            $chunks->count().' chunks / '.$contextLength.' chars / evidence '.$averageEvidence,
            [
                'context_length' => $contextLength,
                'chunk_count' => $chunks->count(),
                'knowledge_base_count' => $knowledgeBases->count(),
                'average_evidence_score' => $averageEvidence,
                'retrieval_sources' => $retrievalSources,
            ],
            $reasons
        );
    }

    /**
     * @param  list<string>  $keywords
     */
    private function checkKeywords(string $title, string $plain, array $keywords): array
    {
        if ($keywords === []) {
            return $this->item('keywords', 6, 15, 'warning', '0/0');
        }

        $haystack = mb_strtolower($title."\n".$plain, 'UTF-8');
        $titleHaystack = mb_strtolower($title, 'UTF-8');
        $plainHaystack = mb_strtolower($plain, 'UTF-8');
        $matchedKeywords = collect($keywords)
            ->filter(fn (string $keyword): bool => $keyword !== '' && Str::contains($haystack, mb_strtolower($keyword, 'UTF-8')))
            ->values();
        $titleMatches = $matchedKeywords
            ->filter(fn (string $keyword): bool => Str::contains($titleHaystack, mb_strtolower($keyword, 'UTF-8')))
            ->count();
        $bodyMatches = $matchedKeywords
            ->filter(fn (string $keyword): bool => Str::contains($plainHaystack, mb_strtolower($keyword, 'UTF-8')))
            ->count();
        $matched = $matchedKeywords->count();
        $ratio = $matched / max(1, count($keywords));
        $score = $ratio >= 0.8 ? 15 : ($ratio >= 0.4 ? 10 : ($matched > 0 ? 6 : 0));
        $reasons = [];
        if ($matched === 0) {
            $reasons[] = 'no_keyword_match';
        } elseif ($ratio < 0.4) {
            $reasons[] = 'low_keyword_coverage';
        }
        if ($matched > 0 && $titleMatches === 0) {
            $reasons[] = 'keyword_missing_from_title';
        }

        return $this->item('keywords', $score, 15, $score >= 12 ? 'passed' : ($score > 0 ? 'warning' : 'failed'), $matched.'/'.count($keywords), [
            'matched_keywords' => $matched,
            'total_keywords' => count($keywords),
            'title_matches' => $titleMatches,
            'body_matches' => $bodyMatches,
            'coverage_percent' => (int) round($ratio * 100),
        ], $reasons);
    }

    private function checkFactBasis(string $plain, array $knowledge): array
    {
        $entities = collect($knowledge['entities'] ?? [])->filter(fn ($entity) => is_array($entity))->count();
        $cases = collect($knowledge['cases'] ?? [])->filter(fn ($case) => is_array($case))->count();
        $chunks = collect($knowledge['chunks'] ?? [])->filter(fn ($chunk) => is_array($chunk))->count();
        $knowledgeBases = collect($knowledge['knowledge_bases'] ?? [])->filter(fn ($item) => is_array($item))->count();
        $hasNumbers = preg_match('/\d+(?:[.,]\d+)?\s*(?:%|年|月|天|小时|kg|mm|cm|m|pcs|units|次|倍)?/u', $plain) === 1;
        $evidenceCount = $entities + $cases + $chunks + $knowledgeBases + ($hasNumbers ? 1 : 0);
        $score = 0;
        $score += $chunks >= 2 ? 5 : ($chunks === 1 ? 3 : 0);
        $score += $knowledgeBases > 0 ? 2 : 0;
        $score += $entities > 0 ? 3 : 0;
        $score += $cases > 0 ? 3 : 0;
        $score += $hasNumbers ? 2 : 0;
        $score = min(15, $score);
        $reasons = [];
        if ($chunks === 0 && $knowledgeBases === 0) {
            $reasons[] = 'no_source_evidence';
        }
        if ($entities === 0) {
            $reasons[] = 'no_entity_reference';
        }
        if ($cases === 0) {
            $reasons[] = 'no_case_reference';
        }
        if (! $hasNumbers) {
            $reasons[] = 'no_measurable_detail';
        }

        return $this->item('facts', $score, 15, $score >= 12 ? 'passed' : ($score > 0 ? 'warning' : 'failed'), (string) $evidenceCount, [
            'chunk_count' => $chunks,
            'knowledge_base_count' => $knowledgeBases,
            'entity_count' => $entities,
            'case_count' => $cases,
            'has_numbers' => $hasNumbers,
            'evidence_count' => $evidenceCount,
        ], $reasons);
    }

    private function checkStructure(string $content, string $plain): array
    {
        $wordCount = $this->wordCount($plain);
        $headingCount = preg_match_all('/^\s{0,3}#{2,4}\s+\S+/mu', $content);
        $paragraphCount = collect(preg_split('/\R{2,}/u', $plain) ?: [])->map(fn ($p) => trim($p))->filter()->count();
        $hasConclusion = preg_match('/(?:Conclusion|Summary|Key Takeaways|结论|总结|要点)/iu', $content) === 1;
        $score = 0;
        $score += $wordCount >= 500 ? 6 : ($wordCount >= 250 ? 4 : 1);
        $score += $headingCount >= 3 ? 5 : ($headingCount >= 1 ? 3 : 0);
        $score += $paragraphCount >= 4 ? 4 : ($paragraphCount >= 2 ? 2 : 0);
        if ($hasConclusion && $score < 15) {
            $score += 1;
        }
        $reasons = [];
        if ($wordCount < 250) {
            $reasons[] = 'short_article';
        }
        if ($headingCount < 3) {
            $reasons[] = 'few_headings';
        }
        if ($paragraphCount < 4) {
            $reasons[] = 'few_paragraphs';
        }
        if (! $hasConclusion) {
            $reasons[] = 'missing_conclusion';
        }

        return $this->item('structure', min(15, $score), 15, $score >= 12 ? 'passed' : ($score >= 7 ? 'warning' : 'failed'), (string) $wordCount, [
            'word_count' => $wordCount,
            'heading_count' => $headingCount,
            'paragraph_count' => $paragraphCount,
            'has_conclusion' => $hasConclusion,
        ], $reasons);
    }

    private function checkFaq(string $content, string $plain): array
    {
        $hasFaqHeading = preg_match('/(?:FAQ|FAQs|Q&A|常见问题|问答|常见问答)/iu', $content) === 1;
        $questionMarks = preg_match_all('/[?？]/u', $plain);
        $score = $hasFaqHeading && $questionMarks >= 2 ? 10 : ($hasFaqHeading || $questionMarks >= 2 ? 6 : 0);
        $reasons = [];
        if (! $hasFaqHeading) {
            $reasons[] = 'missing_faq_heading';
        }
        if ($questionMarks < 2) {
            $reasons[] = 'few_questions';
        }

        return $this->item('faq', $score, 10, $score >= 8 ? 'passed' : ($score > 0 ? 'warning' : 'failed'), (string) $questionMarks, [
            'has_faq_heading' => $hasFaqHeading,
            'question_count' => $questionMarks,
        ], $reasons);
    }

    private function checkImages(int $articleImageCount, int $traceImageCount, string $imageTagFilter): array
    {
        $count = max($articleImageCount, $traceImageCount);
        $score = $count > 0 ? 10 : 0;
        $reasons = [];
        if ($score > 0 && $imageTagFilter === '') {
            $score = 8;
            $reasons[] = 'no_image_tag_filter';
        }
        if ($count === 0) {
            $reasons[] = 'no_images';
        }

        return $this->item('images', $score, 10, $score >= 8 ? 'passed' : 'failed', (string) $count, [
            'article_images' => $articleImageCount,
            'trace_images' => $traceImageCount,
            'image_tag_filter' => $imageTagFilter,
        ], $reasons);
    }

    private function checkUniqueness(string $plain): array
    {
        $paragraphs = collect(preg_split('/\R{2,}/u', $plain) ?: [])
            ->map(fn ($paragraph) => preg_replace('/\s+/u', ' ', trim((string) $paragraph)) ?: '')
            ->filter(fn (string $paragraph): bool => mb_strlen($paragraph, 'UTF-8') >= 20)
            ->values();
        if ($paragraphs->count() < 3) {
            return $this->item('uniqueness', 3, 5, 'warning', '0', [
                'paragraph_count' => $paragraphs->count(),
                'duplicate_paragraphs' => 0,
                'duplicate_ratio' => 0,
            ], ['too_few_paragraphs_for_duplicate_check']);
        }

        $duplicates = $paragraphs->count() - $paragraphs->unique()->count();
        $ratio = $duplicates / max(1, $paragraphs->count());
        $score = $ratio <= 0.05 ? 5 : ($ratio <= 0.2 ? 3 : 0);
        $reasons = $duplicates > 0 ? ['duplicate_paragraphs'] : [];

        return $this->item('uniqueness', $score, 5, $score >= 5 ? 'passed' : ($score > 0 ? 'warning' : 'failed'), (string) round($ratio * 100), [
            'paragraph_count' => $paragraphs->count(),
            'duplicate_paragraphs' => $duplicates,
            'duplicate_ratio' => (int) round($ratio * 100),
        ], $reasons);
    }

    private function plainText(string $content): string
    {
        $content = preg_replace('/```.*?```/su', ' ', $content) ?? $content;
        $content = strip_tags($content);
        $content = preg_replace('/[#>*_`\[\]()-]+/u', ' ', $content) ?? $content;
        $content = preg_replace('/\s+/u', ' ', $content) ?? $content;

        return trim($content);
    }

    /**
     * @return list<string>
     */
    private function keywords(string $keywords): array
    {
        return collect(preg_split('/[,，;；、\n]+/u', $keywords) ?: [])
            ->map(fn ($keyword) => trim((string) $keyword))
            ->filter()
            ->unique(fn (string $keyword) => mb_strtolower($keyword, 'UTF-8'))
            ->values()
            ->all();
    }

    private function detectLanguage(string $text): string
    {
        $normalized = mb_strtolower(trim($text), 'UTF-8');
        $han = preg_match_all('/\p{Han}/u', $normalized);
        $latin = preg_match_all('/\b[A-Za-z][A-Za-z\'-]{2,}\b/u', $normalized);
        if ($han > $latin * 0.8 && $han > 10) {
            return 'zh';
        }
        if ($latin <= 20) {
            return 'unknown';
        }

        return 'en';
    }

    private function expectedLanguage(array $generationTrace): string
    {
        $code = (string) data_get($generationTrace, 'language.code', '');
        $code = $this->normalizeLanguageCode($code);
        if ($code !== '') {
            return $code;
        }

        $title = (string) data_get($generationTrace, 'title.text', '');
        $keyword = (string) data_get($generationTrace, 'title.keyword', '');
        $detected = $this->detectLanguage($title."\n".$keyword);

        return $detected === 'unknown' ? 'unknown' : $detected;
    }

    private function normalizeLanguageCode(string $code): string
    {
        $code = strtolower(str_replace('_', '-', trim($code)));
        if ($code === '') {
            return '';
        }
        $aliases = [
            'zh' => 'zh',
            'zh-cn' => 'zh',
            'zh-hans' => 'zh',
            'zh-tw' => 'zh',
            'zh-hant' => 'zh',
            'en-us' => 'en',
            'en-gb' => 'en',
        ];

        $normalized = $aliases[$code] ?? (str_contains($code, '-') ? (string) strtok($code, '-') : $code);

        return in_array($normalized, ['zh', 'en'], true) ? $normalized : '';
    }

    private function primaryLanguage(string $code): string
    {
        return $this->normalizeLanguageCode($code) ?: $code;
    }

    private function wordCount(string $plain): int
    {
        $latinWords = preg_match_all('/\b[A-Za-z0-9][A-Za-z0-9\'-]*\b/u', $plain);
        $han = preg_match_all('/\p{Han}/u', $plain);

        return max($latinWords, (int) ceil($han / 2));
    }

    private function articleImageCount(Article $article, int $fallback): int
    {
        if (isset($article->article_images_count)) {
            return (int) $article->article_images_count;
        }
        if ($article->relationLoaded('articleImages')) {
            return $article->articleImages->count();
        }

        return $fallback;
    }

    private function grade(int $score): string
    {
        return match (true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'E',
        };
    }

    /**
     * @param  array<string,mixed>  $metrics
     * @param  list<string>  $reasons
     * @return array{key:string,score:int,max:int,status:string,detail:string,metrics:array<string,mixed>,reasons:list<string>}
     */
    private function item(string $key, int $score, int $max, string $status, string $detail, array $metrics = [], array $reasons = []): array
    {
        return [
            'key' => $key,
            'score' => max(0, min($max, $score)),
            'max' => $max,
            'status' => $status,
            'detail' => $detail,
            'metrics' => $metrics,
            'reasons' => array_values(array_unique(array_filter($reasons, static fn (string $reason): bool => trim($reason) !== ''))),
        ];
    }
}
