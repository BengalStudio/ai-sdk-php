<?php

declare(strict_types=1);

namespace BengalStudio\AI\Core;

use BengalStudio\AI\Types\EmbeddingModelUsage;

/**
 * Result of an embed call.
 *
 * Mirrors Vercel AI SDK's EmbedResult.
 */
class EmbedResult
{
    public function __construct(
        /** The embedding vector for the input value. */
        public readonly array $embedding,
        /** The original input value that was embedded. */
        public readonly mixed $value,
        /** Token usage for the embedding. */
        public readonly EmbeddingModelUsage $usage,
        /** Optional raw response metadata. */
        public readonly ?array $response = null,
    ) {}

    /**
     * Get the embedding as a flat array of floats.
     *
     * @return float[]
     */
    public function getEmbedding(): array
    {
        return $this->embedding;
    }

    /**
     * Get the embedding dimension count.
     */
    public function getDimensions(): int
    {
        return count($this->embedding);
    }

    public function toArray(): array
    {
        return [
            'embedding' => $this->embedding,
            'usage' => [
                'tokens' => $this->usage->tokens,
            ],
        ];
    }
}
