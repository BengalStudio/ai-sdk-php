<?php

declare(strict_types=1);

namespace BengalStudio\AI\Core;

use BengalStudio\AI\Tool\ToolCall;
use BengalStudio\AI\Tool\ToolResult;
use BengalStudio\AI\Types\FinishReason;
use BengalStudio\AI\Types\LanguageModelResponseMetadata;
use BengalStudio\AI\Types\LanguageModelUsage;

/**
 * Result of a single step in the generateText process.
 */
class StepResult
{
    public function __construct(
        public readonly string $text,
        /** @var ToolCall[] */
        public readonly array $toolCalls,
        /** @var ToolResult[] */
        public readonly array $toolResults,
        public readonly FinishReason $finishReason,
        public readonly LanguageModelUsage $usage,
        public readonly array $warnings,
        public readonly ?LanguageModelResponseMetadata $response,
        public readonly array $content = [],
    ) {}

    public function hasToolCalls(): bool
    {
        return !empty($this->toolCalls);
    }

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'toolCalls' => array_map(fn(ToolCall $tc) => $tc->toArray(), $this->toolCalls),
            'toolResults' => array_map(fn(ToolResult $tr) => $tr->toArray(), $this->toolResults),
            'finishReason' => $this->finishReason->value,
            'usage' => [
                'promptTokens' => $this->usage->promptTokens,
                'completionTokens' => $this->usage->completionTokens,
            ],
        ];
    }
}
