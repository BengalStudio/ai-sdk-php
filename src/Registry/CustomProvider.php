<?php

declare(strict_types=1);

namespace BengalStudio\AI\Registry;

use BengalStudio\AI\Contracts\EmbeddingModel;
use BengalStudio\AI\Contracts\LanguageModel;
use BengalStudio\AI\Contracts\Provider;
use BengalStudio\AI\Exceptions\NoSuchModelException;

/**
 * Create a custom provider with specific models.
 *
 * Mirrors Vercel AI SDK's customProvider.
 *
 * Usage:
 *   $provider = new CustomProvider(
 *       languageModels: ['my-model' => $myLanguageModel],
 *       embeddingModels: ['my-embedding' => $myEmbeddingModel],
 *       fallbackProvider: $openaiProvider,
 *   );
 */
class CustomProvider implements Provider
{
    /**
     * @param array<string, LanguageModel> $languageModels
     * @param array<string, EmbeddingModel> $embeddingModels
     * @param Provider|null $fallbackProvider
     */
    public function __construct(
        private readonly array $languageModels = [],
        private readonly array $embeddingModels = [],
        private readonly ?Provider $fallbackProvider = null,
    ) {}

    public function languageModel(string $modelId): LanguageModel
    {
        if (isset($this->languageModels[$modelId])) {
            return $this->languageModels[$modelId];
        }

        if ($this->fallbackProvider !== null) {
            return $this->fallbackProvider->languageModel($modelId);
        }

        throw new NoSuchModelException($modelId, 'languageModel');
    }

    public function embeddingModel(string $modelId): EmbeddingModel
    {
        if (isset($this->embeddingModels[$modelId])) {
            return $this->embeddingModels[$modelId];
        }

        if ($this->fallbackProvider !== null) {
            return $this->fallbackProvider->embeddingModel($modelId);
        }

        throw new NoSuchModelException($modelId, 'embeddingModel');
    }
}
