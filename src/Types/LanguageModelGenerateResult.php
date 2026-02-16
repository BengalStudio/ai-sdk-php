<?php

declare(strict_types=1);

namespace BengalStudio\AI\Types;

/**
 * Result of a language model generate call.
 */
class LanguageModelGenerateResult
{
    /**
     * @param array $content The generated content parts.
     * @param string $finishReason Why generation stopped ('stop', 'length', 'tool-calls', 'content-filter', 'error', 'other').
     * @param LanguageModelUsage $usage Token usage information.
     * @param array|null $warnings Any warnings from the model.
     * @param LanguageModelResponseMetadata|null $response Response metadata.
     * @param array|null $providerMetadata Provider-specific metadata.
     * @param array|null $request Request metadata.
     */
    public function __construct(
        public readonly array $content,
        public readonly string $finishReason,
        public readonly LanguageModelUsage $usage,
        public readonly ?array $warnings = null,
        public readonly ?LanguageModelResponseMetadata $response = null,
        public readonly ?array $providerMetadata = null,
        public readonly ?array $request = null,
    ) {}

    /**
     * Extract the text content from the result.
     */
    public function getText(): string
    {
        $text = '';
        foreach ($this->content as $part) {
            if (isset($part['type']) && $part['type'] === 'text') {
                $text .= $part['text'] ?? '';
            }
        }
        return $text;
    }

    /**
     * Extract tool calls from the result.
     */
    public function getToolCalls(): array
    {
        return array_values(array_filter($this->content, function ($part) {
            return isset($part['type']) && $part['type'] === 'tool-call';
        }));
    }
}
