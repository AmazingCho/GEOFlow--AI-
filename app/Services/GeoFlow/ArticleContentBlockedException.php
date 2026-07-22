<?php

namespace App\Services\GeoFlow;

use App\Support\GeoFlow\ArticleGenerationStage;
use RuntimeException;

final class ArticleContentBlockedException extends RuntimeException
{
    /**
     * @param  list<array<string,mixed>>  $attempts
     * @param  list<array<string,mixed>>  $stages
     */
    public function __construct(
        public readonly string $reasonCode,
        public readonly ArticleGenerationStage $stage,
        public readonly string $protocolVersion,
        public readonly array $attempts,
        public readonly array $stages,
        string $message = '文章内容被事实或安全门禁阻止，未保存草稿'
    ) {
        ini_set('zend.exception_ignore_args', '1');
        parent::__construct($message);
    }

    public function __toString(): string
    {
        return self::class.': '.$this->getMessage();
    }
}
