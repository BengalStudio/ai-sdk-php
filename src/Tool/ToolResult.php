<?php

declare(strict_types=1);

namespace BengalStudio\AI\Tool;

/**
 * Represents a tool result after execution.
 */
class ToolResult
{
    public function __construct(
        public readonly string $toolCallId,
        public readonly string $toolName,
        public readonly mixed $input,
        public readonly mixed $output,
    ) {}

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'type' => 'tool-result',
            'toolCallId' => $this->toolCallId,
            'toolName' => $this->toolName,
            'input' => $this->input,
            'output' => $this->output,
        ];
    }

    /**
     * Convert to a tool result message part.
     */
    public function toMessagePart(): array
    {
        return [
            'type' => 'tool-result',
            'toolCallId' => $this->toolCallId,
            'result' => is_string($this->output) ? $this->output : json_encode($this->output),
        ];
    }
}
