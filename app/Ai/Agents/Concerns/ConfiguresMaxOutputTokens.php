<?php

namespace App\Ai\Agents\Concerns;

use Laravel\Ai\Enums\Lab;

trait ConfiguresMaxOutputTokens
{
    /** @return array<string, int> */
    public function providerOptions(Lab|string $provider): array
    {
        if ($this->maxTokens === null || $this->maxTokens <= 0) {
            return [];
        }

        $providerKey = $provider instanceof Lab ? $provider->value : $provider;

        return match ($providerKey) {
            'gemini' => ['maxOutputTokens' => $this->maxTokens],
            'openai' => ['max_output_tokens' => $this->maxTokens],
            default => ['max_tokens' => $this->maxTokens],
        };
    }
}
