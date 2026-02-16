<?php

declare(strict_types=1);

namespace BengalStudio\AI\Types;

/**
 * Options for embedding model calls.
 */
class EmbeddingModelCallOptions
{
    /**
     * @param array<string> $values Values to embed.
     * @param array<string, string>|null $headers Additional HTTP headers.
     * @param array|null $providerOptions Provider-specific options.
     */
    public function __construct(
        public readonly array $values,
        public readonly ?array $headers = null,
        public readonly ?array $providerOptions = null,
    ) {}
}
