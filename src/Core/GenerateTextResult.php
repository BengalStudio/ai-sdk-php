<?php

declare(strict_types=1);

namespace BengalStudio\AI\Core;

use BengalStudio\AI\Tool\ToolCall;
use BengalStudio\AI\Tool\ToolResult;
use BengalStudio\AI\Types\FinishReason;
use BengalStudio\AI\Types\LanguageModelResponseMetadata;
use BengalStudio\AI\Types\LanguageModelUsage;

/**
 * Result of a generateText call.
 *
 * Mirrors Vercel AI SDK's GenerateTextResult.
 */
class GenerateTextResult
{
    public readonly string $text;
    public readonly FinishReason $finishReason;
    /** @var ToolCall[] */
    public readonly array $toolCalls;
    /** @var ToolResult[] */
    public readonly array $toolResults;
    public readonly LanguageModelUsage $usage;
    public readonly ?LanguageModelResponseMetadata $response;
    public readonly array $warnings;

    /**
     * @param StepResult[] $steps
     */
    public function __construct(
        public readonly array $steps,
        public readonly LanguageModelUsage $totalUsage,
    ) {
        $lastStep = !empty($this->steps) ? $this->steps[array_key_last($this->steps)] : null;

        $this->text = $lastStep?->text ?? '';
        $this->finishReason = $lastStep?->finishReason ?? FinishReason::Unknown;
        $this->toolCalls = $lastStep?->toolCalls ?? [];
        $this->toolResults = $lastStep?->toolResults ?? [];
        $this->usage = $this->totalUsage;
        $this->response = $lastStep?->response;
        $this->warnings = $lastStep?->warnings ?? [];
    }

    /**
     * Get the text output from the last step.
     */
    public function getText(): string
    {
        return $this->text;
    }

    /**
     * Get all steps in the multi-step generation.
     *
     * @return StepResult[]
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    /**
     * Check if the result has any tool calls.
     */
    public function hasToolCalls(): bool
    {
        return !empty($this->toolCalls);
    }

    /**
     * Get the number of steps taken.
     */
    public function getStepCount(): int
    {
        return count($this->steps);
    }

    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'finishReason' => $this->finishReason->value,
            'toolCalls' => array_map(fn(ToolCall $tc) => $tc->toArray(), $this->toolCalls),
            'toolResults' => array_map(fn(ToolResult $tr) => $tr->toArray(), $this->toolResults),
            'usage' => $this->totalUsage->toArray(),
            'steps' => array_map(fn(StepResult $s) => $s->toArray(), $this->steps),
        ];
    }
}
