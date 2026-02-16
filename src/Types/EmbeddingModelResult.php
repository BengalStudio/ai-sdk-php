<?php

declare(strict_types=1);

namespace BengalStudio\AI\Types;

/**
 * Result of an embedding model call.
 */
class EmbeddingModelResult
{
    /**
     * @param array<array<float>> $embeddings The generated embeddings.
     * @param EmbeddingModelUsage|null $usage Token usage information.
     * @param array|null $warnings Any warnings from the model.
     * @param array|null $response Response metadata (headers, body).
     */
    public function __construct(
        public readonly array $embeddings,
        public readonly ?EmbeddingModelUsage $usage = null,
        public readonly ?array $warnings = null,
        public readonly ?array $response = null,
    ) {}
}
