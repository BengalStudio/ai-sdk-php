<?php

declare(strict_types=1);

namespace BengalStudio\AI\Types;

/**
 * Response metadata from a language model call.
 */
class LanguageModelResponseMetadata
{
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?\DateTimeInterface $timestamp = null,
        public readonly ?string $modelId = null,
        public readonly ?array $headers = null,
        public readonly mixed $body = null,
    ) {}

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'timestamp' => $this->timestamp?->format('c'),
            'modelId' => $this->modelId,
        ], fn($v) => $v !== null);
    }
}
