<?php

namespace App\Services\GeoFlow;

use InvalidArgumentException;

final class ArticlePlanValidationException extends InvalidArgumentException
{
    /** @param list<array{code:string,path:string,expected:string}> $violations */
    public function __construct(public readonly array $violations)
    {
        $summary = collect($violations)
            ->map(static fn (array $violation): string => $violation['path'].' '.$violation['expected'])
            ->implode('; ');

        parent::__construct('策划结果协议校验失败: '.$summary);
    }
}
