<?php

declare(strict_types=1);

namespace BengalStudio\AI\Types;

/**
 * Token usage from an embedding model call.
 */
class EmbeddingModelUsage
{
    public function __construct(
        public readonly int $tokens = 0,
    ) {}

    /**
     * Add usage from another result.
     */
    public function add(self $other): self
    {
        return new self(
            tokens: $this->tokens + $other->tokens,
        );
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return ['tokens' => $this->tokens];
    }
}
