<?php

declare(strict_types=1);

namespace BengalStudio\AI\Core;

use BengalStudio\AI\Contracts\EmbeddingModel;
use BengalStudio\AI\Types\EmbeddingModelCallOptions;
use BengalStudio\AI\Types\EmbeddingModelResult;
use BengalStudio\AI\Types\EmbeddingModelUsage;
use BengalStudio\AI\Util\Retry;

/**
 * Embed a single value using an embedding model.
 *
 * Mirrors Vercel AI SDK's embed function.
 */
class Embed
{
    private EmbeddingModel $model;
    private mixed $value;
    private int $maxRetries = 2;
    private ?array $headers = null;
    private ?array $providerOptions = null;

    public function __construct(EmbeddingModel $model, mixed $value)
    {
        $this->model = $model;
        $this->value = $value;
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
     * Execute the embedding.
     */
    public function execute(): EmbedResult
    {
        $options = new EmbeddingModelCallOptions(
            values: [$this->value],
            headers: $this->headers,
            providerOptions: $this->providerOptions,
        );

        $result = Retry::execute(
            fn() => $this->model->doEmbed($options),
            maxRetries: $this->maxRetries,
        );

        $embedding = $result->embeddings[0] ?? [];

        return new EmbedResult(
            embedding: $embedding,
            value: $this->value,
            usage: $result->usage,
            response: $result->response,
        );
    }
}
