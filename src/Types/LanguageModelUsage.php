<?php

declare(strict_types=1);

namespace BengalStudio\AI\Types;

/**
 * Token usage information from a language model call.
 */
class LanguageModelUsage
{
    public function __construct(
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly ?int $totalTokens = null,
        public readonly ?int $reasoningTokens = null,
        public readonly ?int $cachedInputTokens = null,
    ) {}

    /**
     * Get the total token count.
     */
    public function total(): int
    {
        return $this->totalTokens ?? ($this->inputTokens + $this->outputTokens);
    }

    /**
     * Add usage from another result.
     */
    public function add(self $other): self
    {
        return new self(
            inputTokens: $this->inputTokens + $other->inputTokens,
            outputTokens: $this->outputTokens + $other->outputTokens,
            totalTokens: $this->total() + $other->total(),
            reasoningTokens: ($this->reasoningTokens ?? 0) + ($other->reasoningTokens ?? 0) ?: null,
            cachedInputTokens: ($this->cachedInputTokens ?? 0) + ($other->cachedInputTokens ?? 0) ?: null,
        );
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return array_filter([
            'inputTokens' => $this->inputTokens,
            'outputTokens' => $this->outputTokens,
            'totalTokens' => $this->total(),
            'reasoningTokens' => $this->reasoningTokens,
            'cachedInputTokens' => $this->cachedInputTokens,
        ], fn($v) => $v !== null);
    }
}
