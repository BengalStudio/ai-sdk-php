<?php

declare(strict_types=1);

namespace BengalStudio\AI\Exceptions;

/**
 * Thrown when too many embedding values are provided for a single call.
 */
class TooManyEmbeddingValuesException extends AIException
{
    public function __construct(
        public readonly string $provider,
        public readonly string $modelId,
        public readonly int $maxEmbeddingsPerCall,
        public readonly int $providedCount,
    ) {
        parent::__construct(
            "Too many values for a single embedding call to {$provider}/{$modelId}. " .
            "Maximum: {$maxEmbeddingsPerCall}, provided: {$providedCount}"
        );
    }
}
