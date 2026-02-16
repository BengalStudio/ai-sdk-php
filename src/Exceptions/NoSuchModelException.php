<?php

declare(strict_types=1);

namespace BengalStudio\AI\Exceptions;

/**
 * Thrown when a model is not found.
 */
class NoSuchModelException extends AIException
{
    public function __construct(
        public readonly string $modelId,
        public readonly string $modelType = 'languageModel',
    ) {
        parent::__construct("No such {$modelType}: {$modelId}");
    }
}
