<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tool;

/**
 * Represents a tool call from the language model.
 */
class ToolCall
{
    public function __construct(
        public readonly string $toolCallId,
        public readonly string $toolName,
        public readonly mixed $input,
        public readonly string $toolCallType = 'function',
    ) {}

    /**
     * Create from a content part array.
     */
    public static function fromContentPart(array $part): self
    {
        return new self(
            toolCallId: $part['toolCallId'] ?? '',
            toolName: $part['toolName'] ?? '',
            input: is_string($part['input'] ?? null) ? json_decode($part['input'], true) : ($part['input'] ?? []),
            toolCallType: $part['toolCallType'] ?? 'function',
        );
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'type' => 'tool-call',
            'toolCallType' => $this->toolCallType,
            'toolCallId' => $this->toolCallId,
            'toolName' => $this->toolName,
            'input' => $this->input,
        ];
    }
}
