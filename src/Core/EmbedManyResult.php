<?php

declare(strict_types=1);

namespace BengalStudio\AI\Core;

use BengalStudio\AI\Types\EmbeddingModelUsage;

/**
 * Result of an embedMany call.
 *
 * Mirrors Vercel AI SDK's EmbedManyResult.
 */
class EmbedManyResult
{
    public function __construct(
        /** Array of embedding vectors, one per input value. */
        public readonly array $embeddings,
        /** The original input values that were embedded. */
        public readonly array $values,
        /** Token usage for the embedding. */
        public readonly EmbeddingModelUsage $usage,
    ) {}

    /**
     * Get all embeddings.
     *
     * @return array<array<float>>
     */
    public function getEmbeddings(): array
    {
        return $this->embeddings;
    }

    /**
     * Get a specific embedding by index.
     *
     * @return float[]|null
     */
    public function getEmbedding(int $index): ?array
    {
        return $this->embeddings[$index] ?? null;
    }

    /**
     * Get the number of embeddings.
     */
    public function count(): int
    {
        return count($this->embeddings);
    }

    /**
     * Calculate cosine similarity between two embeddings by index.
     */
    public function cosineSimilarity(int $indexA, int $indexB): float
    {
        $a = $this->embeddings[$indexA] ?? null;
        $b = $this->embeddings[$indexB] ?? null;

        if ($a === null || $b === null || count($a) !== count($b)) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < count($a); $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $normA = sqrt($normA);
        $normB = sqrt($normB);

        if ($normA === 0.0 || $normB === 0.0) {
            return 0.0;
        }

        return $dotProduct / ($normA * $normB);
    }

    public function toArray(): array
    {
        return [
            'embeddings' => $this->embeddings,
            'usage' => [
                'tokens' => $this->usage->tokens,
            ],
        ];
    }
}
