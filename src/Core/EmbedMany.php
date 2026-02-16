<?php

declare(strict_types=1);

namespace BengalStudio\AI\Core;

use BengalStudio\AI\Contracts\EmbeddingModel;
use BengalStudio\AI\Exceptions\TooManyEmbeddingValuesException;
use BengalStudio\AI\Types\EmbeddingModelCallOptions;
use BengalStudio\AI\Types\EmbeddingModelUsage;
use BengalStudio\AI\Util\Retry;

/**
 * Embed multiple values using an embedding model.
 *
 * Automatically chunks values if they exceed the model's
 * maxEmbeddingsPerCall limit, and can run chunks in parallel
 * if the model supports it.
 *
 * Mirrors Vercel AI SDK's embedMany function.
 */
class EmbedMany
{
    private EmbeddingModel $model;
    private array $values;
    private int $maxRetries = 2;
    private ?array $headers = null;
    private ?array $providerOptions = null;

    /**
     * @param EmbeddingModel $model
     * @param array $values Array of values to embed.
     */
    public function __construct(EmbeddingModel $model, array $values)
    {
        $this->model = $model;
        $this->values = $values;
    }

    public function maxRetries(int $maxRetries): self
    {
        $this->maxRetries = $maxRetries;
        return $this;
    }

    public function headers(array $headers): self
    {
        $this->headers = $headers;
        return $this;
    }

    public function providerOptions(array $options): self
    {
        $this->providerOptions = $options;
        return $this;
    }

    /**
     * Execute the embedding for multiple values.
     */
    public function execute(): EmbedManyResult
    {
        $maxPerCall = $this->model->maxEmbeddingsPerCall();

        // If all values fit in one call, do a single request
        if ($maxPerCall === null || count($this->values) <= $maxPerCall) {
            return $this->embedChunk($this->values);
        }

        // Chunk the values
        $chunks = array_chunk($this->values, $maxPerCall);
        $allEmbeddings = [];
        $totalUsage = new EmbeddingModelUsage();

        foreach ($chunks as $chunk) {
            $result = $this->embedChunk($chunk);
            $allEmbeddings = array_merge($allEmbeddings, $result->embeddings);
            $totalUsage = $totalUsage->add($result->usage);
        }

        return new EmbedManyResult(
            embeddings: $allEmbeddings,
            values: $this->values,
            usage: $totalUsage,
        );
    }

    /**
     * Embed a single chunk of values.
     */
    private function embedChunk(array $values): EmbedManyResult
    {
        $options = new EmbeddingModelCallOptions(
            values: $values,
            headers: $this->headers,
            providerOptions: $this->providerOptions,
        );

        $result = Retry::execute(
            fn() => $this->model->doEmbed($options),
            maxRetries: $this->maxRetries,
        );

        return new EmbedManyResult(
            embeddings: $result->embeddings,
            values: $values,
            usage: $result->usage,
        );
    }
}
