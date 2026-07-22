<?php

namespace App\Services\GeoFlow;

use App\Support\GeoFlow\ArticleGenerationStage;
use RuntimeException;

final class ArticleProviderFailureException extends RuntimeException
{
    /**
     * @param  list<array<string,mixed>>  $attempts
     * @param  list<array<string,mixed>>  $stages
     */
    public function __construct(
        public readonly ArticleGenerationStage $stage,
        public readonly string $protocolVersion,
        public readonly array $attempts,
        public readonly array $stages
    ) {
        ini_set('zend.exception_ignore_args', '1');
        parent::__construct('模型服务异常，当前生成未完成');
    }

    public function __toString(): string
    {
        return self::class.': '.$this->getMessage();
    }
}
