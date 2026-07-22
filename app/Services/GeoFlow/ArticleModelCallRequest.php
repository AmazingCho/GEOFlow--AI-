<?php

namespace App\Services\GeoFlow;

use App\Support\GeoFlow\ArticleGenerationStage;

final readonly class ArticleModelCallRequest
{
    public function __construct(
        public ArticleGenerationStage $stage,
        public string $prompt,
        public bool $validateArticleCompleteness,
        public ?int $maxTokens = null,
    ) {}
}
