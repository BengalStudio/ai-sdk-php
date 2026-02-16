<?php

declare(strict_types=1);

namespace BengalStudio\AI\Contracts;

use BengalStudio\AI\Types\EmbeddingModelCallOptions;
use BengalStudio\AI\Types\EmbeddingModelResult;

/**
 * Specification for an embedding model.
 *
 * Mirrors Vercel AI SDK's EmbeddingModelV3 interface.
 */
interface EmbeddingModel
{
    /**
     * The specification version this model implements.
     */
    public function specificationVersion(): string;

    /**
     * Provider name for logging purposes.
     */
    public function provider(): string;

    /**
     * Provider-specific model ID.
     */
    public function modelId(): string;

    /**
     * Maximum number of embeddings per API call.
     * Return null for unlimited.
     */
    public function maxEmbeddingsPerCall(): ?int;

    /**
     * Whether the model supports parallel calls.
     */
    public function supportsParallelCalls(): bool;

    /**
     * Generate embeddings for the given values.
     *
     * @param EmbeddingModelCallOptions $options
     * @return EmbeddingModelResult
     */
    public function doEmbed(EmbeddingModelCallOptions $options): EmbeddingModelResult;
}
