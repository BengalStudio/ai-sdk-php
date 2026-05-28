<?php

declare(strict_types=1);

namespace BengalStudio\AI\Prompt;

/**
 * Represents a UI Message from @ai-sdk/react.
 *
 * UIMessage is the frontend message format used by useChat() and other
 * AI SDK React hooks. It uses a parts-based structure rather than a
 * simple content string.
 *
 * Mirrors the TypeScript `UIMessage` type from the Vercel AI SDK.
 *
 * @see https://ai-sdk.dev/docs/ai-sdk-ui/chatbot
 */
class UIMessage
{
    /**
     * @param string $id        Unique message ID.
     * @param string $role      'user', 'assistant', or 'system'.
     * @param array  $parts     Array of message parts (text, tool-<name>, dynamic-tool, file, etc.).
     * @param array  $metadata  Optional metadata attached to the message.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $role,
        public readonly array $parts = [],
        public readonly array $metadata = [],
    ) {}

    /**
     * Create a UIMessage from an associative array (e.g. decoded JSON from the frontend).
     *
     * @param array $data {
     *   @type string $id
     *   @type string $role
     *   @type array  $parts   Array of part arrays, each with a 'type' key.
     *   @type array  $metadata
     * }
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            role: $data['role'] ?? 'user',
            parts: $data['parts'] ?? [],
            metadata: $data['metadata'] ?? [],
        );
    }

    /**
     * Get all parts of a specific type.
     *
     * @param string $type e.g. 'text', 'tool-<name>', 'dynamic-tool', 'file', 'reasoning', 'source-url'.
     * @return array
     */
    public function getPartsByType(string $type): array
    {
        return array_values(array_filter(
            $this->parts,
            fn(array $part) => ($part['type'] ?? '') === $type,
        ));
    }

    /**
     * Get concatenated text from all text parts.
     */
    public function getTextContent(): string
    {
        return implode('', array_map(
            fn(array $part) => $part['text'] ?? '',
            $this->getPartsByType('text'),
        ));
    }

    /**
     * Get all tool parts (AI SDK v5+ `tool-<name>` and `dynamic-tool`).
     *
     * @return array[] Each element has: type, toolCallId, state, input, and
     *                 output (state 'output-available') or errorText (state
     *                 'output-error') once the call resolves.
     */
    public function getToolInvocations(): array
    {
        return array_values(array_filter(
            $this->parts,
            static function (array $part): bool {
                $type = $part['type'] ?? '';
                return $type === 'dynamic-tool' || str_starts_with($type, 'tool-');
            },
        ));
    }
}
