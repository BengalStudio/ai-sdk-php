<?php

declare(strict_types=1);

namespace BengalStudio\AI\Registry;

use BengalStudio\AI\Contracts\EmbeddingModel;
use BengalStudio\AI\Contracts\LanguageModel;
use BengalStudio\AI\Contracts\Provider;
use BengalStudio\AI\Exceptions\NoSuchProviderException;

/**
 * Registry for AI providers.
 *
 * Mirrors Vercel AI SDK's createProviderRegistry.
 *
 * Usage:
 *   $registry = new ProviderRegistry([
 *       'openai' => $openaiProvider,
 *       'anthropic' => $anthropicProvider,
 *   ]);
 *
 *   $model = $registry->languageModel('openai:gpt-4o');
 */
class ProviderRegistry
{
    /** @var array<string, Provider> */
    private array $providers = [];

    /**
     * @param array<string, Provider> $providers
     * @param string $separator Separator between provider and model ID (default ':')
     */
    public function __construct(
        array $providers = [],
        private readonly string $separator = ':',
    ) {
        foreach ($providers as $id => $provider) {
            $this->register($id, $provider);
        }
    }

    /**
     * Register a provider.
     */
    public function register(string $id, Provider $provider): self
    {
        $this->providers[$id] = $provider;
        return $this;
    }

    /**
     * Get a language model by composite ID (e.g., 'openai:gpt-4o').
     *
     * @throws NoSuchProviderException
     */
    public function languageModel(string $id): LanguageModel
    {
        [$providerId, $modelId] = $this->parseId($id);

        if (!isset($this->providers[$providerId])) {
            throw new NoSuchProviderException($providerId);
        }

        return $this->providers[$providerId]->languageModel($modelId);
    }

    /**
     * Get an embedding model by composite ID (e.g., 'openai:text-embedding-3-small').
     *
     * @throws NoSuchProviderException
     */
    public function embeddingModel(string $id): EmbeddingModel
    {
        [$providerId, $modelId] = $this->parseId($id);

        if (!isset($this->providers[$providerId])) {
            throw new NoSuchProviderException($providerId);
        }

        return $this->providers[$providerId]->embeddingModel($modelId);
    }

    /**
     * Parse a composite model ID into [provider, modelId].
     *
     * @return array{string, string}
     */
    private function parseId(string $id): array
    {
        $pos = strpos($id, $this->separator);

        if ($pos === false) {
            throw new \InvalidArgumentException(
                "Invalid model ID '{$id}'. Expected format: 'provider{$this->separator}model-id'"
            );
        }

        return [
            substr($id, 0, $pos),
            substr($id, $pos + strlen($this->separator)),
        ];
    }
}
