<?php

declare(strict_types=1);

namespace BengalStudio\AI\Core;

use BengalStudio\AI\Types\FinishReason;
use BengalStudio\AI\Types\LanguageModelResponseMetadata;
use BengalStudio\AI\Types\LanguageModelUsage;

/**
 * Result of a generateObject call.
 *
 * Mirrors Vercel AI SDK's GenerateObjectResult.
 */
class GenerateObjectResult
{
    public function __construct(
        /** The generated object matching the provided JSON Schema. */
        public readonly array $object,
        /** The raw text response from the model. */
        public readonly string $rawText,
        public readonly FinishReason $finishReason,
        public readonly LanguageModelUsage $usage,
        public readonly array $warnings = [],
        public readonly ?LanguageModelResponseMetadata $response = null,
    ) {}

    /**
     * Get the generated object.
     */
    public function getObject(): array
    {
        return $this->object;
    }

    /**
     * Access a nested value using dot notation.
     *
     * @param string $key e.g., 'user.name' or 'items.0.title'
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->object;

        foreach (explode('.', $key) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return $default;
            }
        }

        return $value;
    }

    public function toArray(): array
    {
        return [
            'object' => $this->object,
            'finishReason' => $this->finishReason->value,
            'usage' => $this->usage->toArray(),
        ];
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->object, $flags);
    }
}
