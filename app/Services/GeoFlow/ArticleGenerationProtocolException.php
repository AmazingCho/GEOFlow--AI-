<?php

namespace App\Services\GeoFlow;

use App\Support\GeoFlow\ArticleGenerationStage;
use RuntimeException;

final class ArticleGenerationProtocolException extends RuntimeException
{
    /**
     * @param  list<array{code:string,path:string,expected:string}>  $violations
     * @param  list<array<string,mixed>>  $attempts
     * @param  list<array<string,mixed>>  $stages
     */
    public function __construct(
        public readonly ArticleGenerationStage $stage,
        public readonly string $protocolVersion,
        public readonly array $violations,
        public readonly array $attempts,
        public readonly array $stages = []
    ) {
        parent::__construct('深度生成协议校验失败，未生成文章');
    }
}
