<?php

namespace App\Services\GeoFlow;

use RuntimeException;
use Throwable;

class ArticleModelCallException extends RuntimeException
{
    /** @param array<string,mixed> $callMetadata */
    public function __construct(string $message, public readonly array $callMetadata = [], ?Throwable $previous = null)
    {
        ini_set('zend.exception_ignore_args', '1');
        parent::__construct($message, 0, $previous);
    }

    public function __toString(): string
    {
        return static::class.': '.$this->getMessage();
    }
}
