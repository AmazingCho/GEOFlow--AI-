<?php

namespace App\Services\GeoFlow;

use App\Support\GeoFlow\ArticleGenerationStage;
use RuntimeException;

final class ArticleModelSelectionException extends RuntimeException
{
    /** @param list<array<string,mixed>> $attempts */
    public function __construct(
        public readonly ArticleGenerationStage $stage,
        public readonly array $attempts,
        string $message = '模型服务异常'
    ) {
        ini_set('zend.exception_ignore_args', '1');
        parent::__construct($message);
    }

    public function __toString(): string
    {
        return self::class.': '.$this->getMessage();
    }
}
