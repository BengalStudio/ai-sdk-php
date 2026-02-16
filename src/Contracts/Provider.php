<?php

declare(strict_types=1);

namespace BengalStudio\AI\Contracts;

/**
 * Provider for language and embedding models.
 *
 * Mirrors Vercel AI SDK's ProviderV3 interface.
 */
interface Provider
{
    /**
     * Returns the language model with the given ID.
     *
     * @throws \BengalStudio\AI\Exceptions\NoSuchModelException
     */
    public function languageModel(string $modelId): LanguageModel;

    /**
     * Returns the embedding model with the given ID.
     *
     * @throws \BengalStudio\AI\Exceptions\NoSuchModelException
     */
    public function embeddingModel(string $modelId): EmbeddingModel;
}
