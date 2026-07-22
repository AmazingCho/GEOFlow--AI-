<?php

namespace App\Services\GeoFlow;

use RuntimeException;

final class ArticlePublicationBlockedException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message
    ) {
        parent::__construct($message);
    }
}
