<?php

declare(strict_types=1);

namespace BengalStudio\AI\Types;

/**
 * Represents a message in a conversation.
 *
 * Mirrors AI SDK's prompt message types.
 */
class Message
{
    public function __construct(
        public readonly string $role,
        public readonly string|array $content,
        public readonly ?array $providerOptions = null,
    ) {}

    /**
     * Create a system message.
     */
    public static function system(string $content): self
    {
        return new self(role: 'system', content: $content);
    }

    /**
     * Create a user message.
     */
    public static function user(string|array $content): self
    {
        return new self(role: 'user', content: $content);
    }

    /**
     * Create an assistant message.
     */
    public static function assistant(string|array $content): self
    {
        return new self(role: 'assistant', content: $content);
    }

    /**
     * Create a tool result message.
     */
    public static function tool(array $content): self
    {
        return new self(role: 'tool', content: $content);
    }

    /**
     * Convert to array representation.
     */
    public function toArray(): array
    {
        $data = [
            'role' => $this->role,
            'content' => $this->content,
        ];

        if ($this->providerOptions !== null) {
            $data['providerOptions'] = $this->providerOptions;
        }

        return $data;
    }
}
